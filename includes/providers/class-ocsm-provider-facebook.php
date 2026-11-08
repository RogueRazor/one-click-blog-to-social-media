<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Provider_Facebook extends OCSM_Provider {

	const GRAPH_VERSION = 'v21.0';
	const AUTH_URL       = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
	const TOKEN_URL      = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/oauth/token';
	const GRAPH_BASE     = 'https://graph.facebook.com/' . self::GRAPH_VERSION;
	const SCOPE          = 'pages_show_list,pages_manage_posts,pages_read_engagement';

	const OPTION_SETTINGS = 'ocsm_facebook_settings';
	const OPTION_TARGETS  = 'ocsm_facebook_pages';

	public function id() {
		return 'facebook';
	}

	public function label() {
		return __( 'Facebook Pages', 'one-click-blog-to-social-media' );
	}

	public function icon() {
		return '📘';
	}

	public function settings() {
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'app_id'        => '',
				'app_secret'    => '',
				'access_token'  => '',
				'token_expires' => 0,
				'targets'       => array(),
				'enabled'       => 0,
			)
		);
	}

	public function update_settings( $fields ) {
		$settings = $this->settings();
		foreach ( $fields as $key => $value ) {
			$settings[ $key ] = $value;
		}
		update_option( self::OPTION_SETTINGS, $settings );
	}

	public function install_defaults() {
		if ( false === get_option( self::OPTION_SETTINGS ) ) {
			add_option( self::OPTION_SETTINGS, $this->settings() );
		}
	}

	public function is_configured() {
		$s = $this->settings();
		return ! empty( $s['app_id'] ) && ! empty( $s['app_secret'] );
	}

	public function is_connected() {
		$s = $this->settings();
		return ! empty( $s['access_token'] ) && ( empty( $s['token_expires'] ) || time() < (int) $s['token_expires'] );
	}

	public function redirect_uri() {
		return admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_oauth=facebook' );
	}

	public function get_connect_url() {
		$s = $this->settings();
		return add_query_arg(
			array(
				'client_id'     => $s['app_id'],
				'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'state'         => wp_create_nonce( 'ocsm_oauth_facebook' ),
			),
			self::AUTH_URL
		);
	}

	public function exchange_code( $code ) {
		$s = $this->settings();
		$response = wp_remote_get(
			add_query_arg(
				array(
					'client_id'     => $s['app_id'],
					'client_secret' => $s['app_secret'],
					'redirect_uri'  => $this->redirect_uri(),
					'code'          => $code,
				),
				self::TOKEN_URL
			),
			array( 'timeout' => 30 )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'ocsm_token_error', $err );
		}

		$long_response = wp_remote_get(
			add_query_arg(
				array(
					'grant_type'    => 'fb_exchange_token',
					'client_id'     => $s['app_id'],
					'client_secret' => $s['app_secret'],
					'fb_exchange_token' => $body['access_token'],
				),
				self::TOKEN_URL
			),
			array( 'timeout' => 30 )
		);
		if ( ! is_wp_error( $long_response ) ) {
			$long_body = json_decode( wp_remote_retrieve_body( $long_response ), true );
			if ( ! empty( $long_body['access_token'] ) ) {
				$body = $long_body;
			}
		}

		$this->update_settings( array(
			'access_token'  => $body['access_token'],
			'token_expires' => empty( $body['expires_in'] ) ? 0 : time() + (int) $body['expires_in'] - 60,
		) );
		return true;
	}

	public function disconnect() {
		$this->update_settings( array(
			'access_token'  => '',
			'token_expires' => 0,
			'targets'       => array(),
		) );
		delete_option( self::OPTION_TARGETS );
	}

	private function request( $method, $path, $body = null ) {
		$s = $this->settings();
		$url = self::GRAPH_BASE . '/' . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
		);
		if ( null !== $body ) {
			$body['access_token'] = $s['access_token'];
			$args['body'] = $body;
		} else {
			$url = add_query_arg( 'access_token', rawurlencode( $s['access_token'] ), $url );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'ocsm_api_error', $msg, array( 'status' => $code ) );
		}
		return $data;
	}

	public function get_targets() {
		$data = $this->request( 'GET', '/me/accounts?fields=name,access_token&limit=100' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$targets = array();
		if ( ! empty( $data['data'] ) ) {
			foreach ( $data['data'] as $page ) {
				if ( ! empty( $page['id'] ) ) {
					$targets[] = array(
						'id'    => $page['id'],
						'title' => isset( $page['name'] ) ? $page['name'] : $page['id'],
					);
				}
			}
		}
		return $targets;
	}

	public function selected_targets() {
		return (array) $this->settings()['targets'];
	}

	public function handle_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'facebook_save' === $action ) {
			check_admin_referer( 'ocsm_facebook_save' );
			$this->update_settings( array(
				'app_id'     => isset( $_POST['ocsm_facebook_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ocsm_facebook_app_id'] ) ) : '',
				'app_secret' => isset( $_POST['ocsm_facebook_app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['ocsm_facebook_app_secret'] ) ) : '',
				'enabled'    => isset( $_POST['ocsm_facebook_enabled'] ) ? 1 : 0,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=saved' ) );
			exit;
		}

		if ( 'facebook_refresh' === $action ) {
			check_admin_referer( 'ocsm_facebook_refresh' );
			$targets = $this->get_targets();
			if ( is_wp_error( $targets ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=targets_error&ocsm_err=' . rawurlencode( $targets->get_error_message() ) ) );
				exit;
			}
			update_option( self::OPTION_TARGETS, $targets );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=targets_refreshed' ) );
			exit;
		}

		if ( 'facebook_targets' === $action ) {
			check_admin_referer( 'ocsm_facebook_targets' );
			$selected = isset( $_POST['ocsm_facebook_targets'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ocsm_facebook_targets'] ) ) : array();
			$this->update_settings( array( 'targets' => array_values( array_filter( $selected ) ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=saved' ) );
			exit;
		}

		if ( 'facebook_disconnect' === $action ) {
			check_admin_referer( 'ocsm_facebook_disconnect' );
			$this->disconnect();
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=disconnected' ) );
			exit;
		}
	}

	public function post( $post_id, $payload ) {
		$targets = $this->selected_targets();
		if ( empty( $targets ) ) {
			$this->log( array( 'post_id' => $post_id, 'status' => 'skipped', 'message' => __( 'No Facebook Pages selected.', 'one-click-blog-to-social-media' ) ) );
			return;
		}

		$message = $payload['title'];
		if ( ! empty( $payload['excerpt'] ) ) {
			$message .= "\n\n" . $payload['excerpt'];
		}

		$success = array();
		$errors  = array();
		foreach ( $targets as $page_id ) {
			$body = array(
				'message' => $message,
				'link'    => $payload['url'],
			);
			$response = $this->request( 'POST', '/' . $page_id . '/feed', $body );
			if ( is_wp_error( $response ) ) {
				$errors[ $page_id ] = $response->get_error_message();
			} elseif ( ! empty( $response['id'] ) ) {
				$success[] = $page_id;
				update_post_meta( $post_id, '_ocsm_facebook_post_id_' . $page_id, $response['id'] );
			} else {
				$errors[ $page_id ] = __( 'Unknown API response.', 'one-click-blog-to-social-media' );
			}
		}

		if ( $errors ) {
			$this->log( array(
				'post_id' => $post_id,
				'status'  => 'error',
				'message' => __( 'Facebook API error: ', 'one-click-blog-to-social-media' ) . implode( ' | ', $errors ),
			) );
		}
		if ( $success ) {
			$this->log( array(
				'post_id' => $post_id,
				'status'  => 'success',
				'message' => sprintf( _n( 'Posted to %d page.', 'Posted to %d pages.', count( $success ), 'one-click-blog-to-social-media' ), count( $success ) ),
			) );
		}
	}

	public function render_settings() {
		$s         = $this->settings();
		$available = get_option( self::OPTION_TARGETS, array() );
		$connected = $this->is_connected();
		?>
		<form method="post">
			<?php wp_nonce_field( 'ocsm_facebook_save' ); ?>
			<input type="hidden" name="ocsm_action" value="facebook_save" />
			<h2><?php echo esc_html( $this->icon() . ' ' . $this->label() ); ?></h2>
			<p><?php esc_html_e( 'Redirect URI (add this in your Facebook app settings under Valid OAuth Redirect URIs):', 'one-click-blog-to-social-media' ); ?></p>
			<code><?php echo esc_html( $this->redirect_uri() ); ?></code>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ocsm_facebook_app_id"><?php esc_html_e( 'App ID', 'one-click-blog-to-social-media' ); ?></label></th>
					<td><input type="text" class="regular-text" id="ocsm_facebook_app_id" name="ocsm_facebook_app_id" value="<?php echo esc_attr( $s['app_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ocsm_facebook_app_secret"><?php esc_html_e( 'App Secret', 'one-click-blog-to-social-media' ); ?></label></th>
					<td><input type="password" class="regular-text" autocomplete="off" id="ocsm_facebook_app_secret" name="ocsm_facebook_app_secret" value="<?php echo esc_attr( $s['app_secret'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'one-click-blog-to-social-media' ); ?></th>
					<td>
						<?php if ( $connected ) : ?>
							<p style="color:#00a32a;font-weight:bold;"><?php esc_html_e( 'Connected to Facebook', 'one-click-blog-to-social-media' ); ?></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Not connected. Save your credentials first, then click Connect.', 'one-click-blog-to-social-media' ); ?></p>
						<?php endif; ?>
						<?php if ( $this->is_configured() ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $this->get_connect_url() ); ?>"><?php esc_html_e( 'Connect Facebook account', 'one-click-blog-to-social-media' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ocsm_facebook_enabled"><?php esc_html_e( 'Post to Facebook Pages', 'one-click-blog-to-social-media' ); ?></label></th>
					<td>
						<input type="checkbox" id="ocsm_facebook_enabled" name="ocsm_facebook_enabled" <?php checked( ! empty( $s['enabled'] ) ); ?> />
						<span class="description"><?php esc_html_e( 'Cross-post articles as link posts on your Pages.', 'one-click-blog-to-social-media' ); ?></span>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Facebook settings', 'one-click-blog-to-social-media' ) ); ?>
		</form>

		<h3><?php esc_html_e( 'Facebook Pages', 'one-click-blog-to-social-media' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'ocsm_facebook_refresh' ); ?>
			<input type="hidden" name="ocsm_action" value="facebook_refresh" />
			<?php submit_button( __( 'Refresh Pages', 'one-click-blog-to-social-media' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( ! empty( $available ) ) : ?>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'ocsm_facebook_targets' ); ?>
				<input type="hidden" name="ocsm_action" value="facebook_targets" />
				<?php foreach ( $available as $page ) : ?>
					<label style="display:block;">
						<input type="checkbox" name="ocsm_facebook_targets[]" value="<?php echo esc_attr( $page['id'] ); ?>" <?php checked( in_array( $page['id'], (array) $s['targets'], true ) ); ?> />
						<?php echo esc_html( $page['title'] ); ?>
					</label>
				<?php endforeach; ?>
				<?php submit_button( __( 'Save Pages', 'one-click-blog-to-social-media' ) ); ?>
			</form>
		<?php elseif ( $connected ) : ?>
			<p><?php esc_html_e( 'Click "Refresh Pages" to load your Facebook Pages.', 'one-click-blog-to-social-media' ); ?></p>
		<?php endif; ?>

		<?php if ( $connected ) : ?>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'ocsm_facebook_disconnect' ); ?>
				<input type="hidden" name="ocsm_action" value="facebook_disconnect" />
				<?php submit_button( __( 'Disconnect Facebook account', 'one-click-blog-to-social-media' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif;
	}
}
