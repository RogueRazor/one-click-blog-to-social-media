<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Provider_Instagram extends OCSM_Provider {

	const GRAPH_VERSION = 'v21.0';
	const AUTH_URL       = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
	const TOKEN_URL      = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/oauth/access_token';
	const GRAPH_BASE     = 'https://graph.facebook.com/' . self::GRAPH_VERSION;
	const SCOPE          = 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement';

	const OPTION_SETTINGS = 'ocsm_instagram_settings';
	const OPTION_TARGETS  = 'ocsm_instagram_accounts';

	public function id() {
		return 'instagram';
	}

	public function label() {
		return __( 'Instagram', 'one-click-blog-to-social-media' );
	}

	public function icon() {
		return '📸';
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
		return admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_oauth=instagram' );
	}

	public function get_connect_url() {
		if ( ! $this->is_configured() ) {
			return '';
		}
		$s = $this->settings();
		return add_query_arg(
			array(
				'client_id'     => $s['app_id'],
				'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'state'         => wp_create_nonce( 'ocsm_oauth_instagram' ),
			),
			self::AUTH_URL
		);
	}

	public function exchange_code( $code ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'ocsm_not_configured', __( 'Enter the Meta App ID and App Secret first.', 'one-click-blog-to-social-media' ) );
		}
		$s = $this->settings();
		$response = wp_remote_get(
			add_query_arg(
				array(
					'client_id'     => $s['app_id'],
					'client_secret' => $s['app_secret'],
					'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
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
					'grant_type'        => 'fb_exchange_token',
					'client_id'         => $s['app_id'],
					'client_secret'     => $s['app_secret'],
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
		$s   = $this->settings();
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
		$data = $this->request( 'GET', '/me/accounts?fields=name,instagram_business_account{id,username}&limit=100' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$targets = array();
		if ( ! empty( $data['data'] ) ) {
			foreach ( $data['data'] as $page ) {
				if ( ! empty( $page['instagram_business_account']['id'] ) ) {
					$ig = $page['instagram_business_account'];
					$targets[] = array(
						'id'    => $ig['id'],
						'title' => '@' . ( isset( $ig['username'] ) ? $ig['username'] : $ig['id'] ),
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

		if ( 'instagram_save' === $action ) {
			check_admin_referer( 'ocsm_instagram_save' );
			$this->update_settings( array(
				'app_id'     => isset( $_POST['ocsm_instagram_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ocsm_instagram_app_id'] ) ) : '',
				'app_secret' => isset( $_POST['ocsm_instagram_app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['ocsm_instagram_app_secret'] ) ) : '',
				'enabled'    => isset( $_POST['ocsm_instagram_enabled'] ) ? 1 : 0,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=saved' ) );
			exit;
		}

		if ( 'instagram_refresh' === $action ) {
			check_admin_referer( 'ocsm_instagram_refresh' );
			$targets = $this->get_targets();
			if ( is_wp_error( $targets ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=targets_error&ocsm_err=' . rawurlencode( $targets->get_error_message() ) ) );
				exit;
			}
			update_option( self::OPTION_TARGETS, $targets );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=targets_refreshed' ) );
			exit;
		}

		if ( 'instagram_targets' === $action ) {
			check_admin_referer( 'ocsm_instagram_targets' );
			$selected = isset( $_POST['ocsm_instagram_targets'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ocsm_instagram_targets'] ) ) : array();
			$this->update_settings( array( 'targets' => array_values( array_filter( $selected ) ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=saved' ) );
			exit;
		}

		if ( 'instagram_disconnect' === $action ) {
			check_admin_referer( 'ocsm_instagram_disconnect' );
			$this->disconnect();
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=disconnected' ) );
			exit;
		}
	}

	public function post( $post_id, $payload ) {
		$targets = $this->selected_targets();
		if ( empty( $targets ) ) {
			$this->log( array( 'post_id' => $post_id, 'status' => 'skipped', 'message' => __( 'No Instagram accounts selected.', 'one-click-blog-to-social-media' ) ) );
			return;
		}

		if ( empty( $payload['image_url'] ) ) {
			$this->log( array( 'post_id' => $post_id, 'status' => 'skipped', 'message' => __( 'Instagram requires an image; the article has no featured image and no fallback image is set.', 'one-click-blog-to-social-media' ) ) );
			return;
		}

		$caption = $payload['title'];
		if ( ! empty( $payload['excerpt'] ) ) {
			$caption .= "\n\n" . $payload['excerpt'];
		}
		$caption .= "\n\n" . $payload['url'];
		if ( ! empty( $payload['hashtags'] ) ) {
			$caption .= "\n\n" . $payload['hashtags'];
		}
		if ( mb_strlen( $caption ) > 2200 ) {
			$caption = mb_substr( $caption, 0, 2197 ) . '…';
		}

		$success = array();
		$errors  = array();
		foreach ( $targets as $ig_user_id ) {
			$container = $this->request( 'POST', '/' . $ig_user_id . '/media', array(
				'image_url' => $payload['image_url'],
				'caption'   => $caption,
			) );
			if ( is_wp_error( $container ) ) {
				$errors[ $ig_user_id ] = $container->get_error_message();
				continue;
			}
			if ( empty( $container['id'] ) ) {
				$errors[ $ig_user_id ] = __( 'No media container returned.', 'one-click-blog-to-social-media' );
				continue;
			}

			$publish = $this->request( 'POST', '/' . $ig_user_id . '/media_publish', array(
				'creation_id' => $container['id'],
			) );
			if ( is_wp_error( $publish ) ) {
				$errors[ $ig_user_id ] = $publish->get_error_message();
			} elseif ( ! empty( $publish['id'] ) ) {
				$success[] = $ig_user_id;
				update_post_meta( $post_id, '_ocsm_instagram_post_id_' . $ig_user_id, $publish['id'] );
			} else {
				$errors[ $ig_user_id ] = __( 'Unknown API response.', 'one-click-blog-to-social-media' );
			}
		}

		if ( $errors ) {
			$this->log( array(
				'post_id' => $post_id,
				'status'  => 'error',
				'message' => __( 'Instagram API error: ', 'one-click-blog-to-social-media' ) . implode( ' | ', $errors ),
			) );
		}
		if ( $success ) {
			$this->log( array(
				'post_id' => $post_id,
				'status'  => 'success',
				'message' => sprintf( _n( 'Posted to %d account.', 'Posted to %d accounts.', count( $success ), 'one-click-blog-to-social-media' ), count( $success ) ),
			) );
		}
	}

	public function render_settings() {
		$s         = $this->settings();
		$available = get_option( self::OPTION_TARGETS, array() );
		$connected = $this->is_connected();
		?>
		<form method="post">
			<?php wp_nonce_field( 'ocsm_instagram_save' ); ?>
			<input type="hidden" name="ocsm_action" value="instagram_save" />
			<h2><?php echo esc_html( $this->icon() . ' ' . $this->label() ); ?></h2>
			<p><?php esc_html_e( 'Instagram posts are published through a Meta app, to Instagram Business or Creator accounts linked to your Facebook Pages.', 'one-click-blog-to-social-media' ); ?></p>
			<p><?php esc_html_e( 'Redirect URI (add this in your Meta app settings under Valid OAuth Redirect URIs):', 'one-click-blog-to-social-media' ); ?></p>
			<code><?php echo esc_html( $this->redirect_uri() ); ?></code>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ocsm_instagram_app_id"><?php esc_html_e( 'App ID', 'one-click-blog-to-social-media' ); ?></label></th>
					<td><input type="text" class="regular-text" id="ocsm_instagram_app_id" name="ocsm_instagram_app_id" value="<?php echo esc_attr( $s['app_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ocsm_instagram_app_secret"><?php esc_html_e( 'App Secret', 'one-click-blog-to-social-media' ); ?></label></th>
					<td><input type="password" class="regular-text" autocomplete="off" id="ocsm_instagram_app_secret" name="ocsm_instagram_app_secret" value="<?php echo esc_attr( $s['app_secret'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'one-click-blog-to-social-media' ); ?></th>
					<td>
						<?php if ( $connected ) : ?>
							<p style="color:#00a32a;font-weight:bold;"><?php esc_html_e( 'Connected to Instagram', 'one-click-blog-to-social-media' ); ?></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Not connected. Save your credentials first, then click Connect.', 'one-click-blog-to-social-media' ); ?></p>
						<?php endif; ?>
						<?php if ( $this->is_configured() ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $this->get_connect_url() ); ?>"><?php esc_html_e( 'Connect Instagram account', 'one-click-blog-to-social-media' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ocsm_instagram_enabled"><?php esc_html_e( 'Post to Instagram', 'one-click-blog-to-social-media' ); ?></label></th>
					<td>
						<input type="checkbox" id="ocsm_instagram_enabled" name="ocsm_instagram_enabled" <?php checked( ! empty( $s['enabled'] ) ); ?> />
						<span class="description"><?php esc_html_e( 'Cross-post articles as image posts. Requires a featured or fallback image; Instagram links are not clickable, so the article URL is appended to the caption.', 'one-click-blog-to-social-media' ); ?></span>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Instagram settings', 'one-click-blog-to-social-media' ) ); ?>
		</form>

		<h3><?php esc_html_e( 'Instagram accounts', 'one-click-blog-to-social-media' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'ocsm_instagram_refresh' ); ?>
			<input type="hidden" name="ocsm_action" value="instagram_refresh" />
			<?php submit_button( __( 'Refresh accounts', 'one-click-blog-to-social-media' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( ! empty( $available ) ) : ?>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'ocsm_instagram_targets' ); ?>
				<input type="hidden" name="ocsm_action" value="instagram_targets" />
				<?php foreach ( $available as $account ) : ?>
					<label style="display:block;">
						<input type="checkbox" name="ocsm_instagram_targets[]" value="<?php echo esc_attr( $account['id'] ); ?>" <?php checked( in_array( $account['id'], (array) $s['targets'], true ) ); ?> />
						<?php echo esc_html( $account['title'] ); ?>
					</label>
				<?php endforeach; ?>
				<?php submit_button( __( 'Save accounts', 'one-click-blog-to-social-media' ) ); ?>
			</form>
		<?php elseif ( $connected ) : ?>
			<p><?php esc_html_e( 'Click "Refresh accounts" to load your linked Instagram Business accounts. Only Business/Creator accounts linked to a Facebook Page appear.', 'one-click-blog-to-social-media' ); ?></p>
		<?php endif; ?>

		<?php if ( $connected ) : ?>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'ocsm_instagram_disconnect' ); ?>
				<input type="hidden" name="ocsm_action" value="instagram_disconnect" />
				<?php submit_button( __( 'Disconnect Instagram account', 'one-click-blog-to-social-media' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif;
	}
}
