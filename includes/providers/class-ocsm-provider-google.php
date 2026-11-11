<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Provider_Google extends OCSM_Provider {

	const AUTH_URL      = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const SCOPE         = 'https://www.googleapis.com/auth/business.manage';
	const ACCOUNTS_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';
	const INFO_BASE     = 'https://mybusinessbusinessinformation.googleapis.com/v1';
	const POSTS_BASE    = 'https://mybusiness.googleapis.com/v4';

	const OPTION_SETTINGS = 'ocsm_google_settings';
	const OPTION_TARGETS  = 'ocsm_google_locations';

	public function id() {
		return 'google';
	}

	public function label() {
		return __( 'Google Business Profile', 'one-click-blog-to-social-media' );
	}

	public function icon() {
		return '🔗';
	}

	public function settings() {
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'client_id'      => '',
				'client_secret' => '',
				'access_token'   => '',
				'refresh_token'  => '',
				'token_expires'  => 0,
				'targets'        => array(),
				'cta_type'       => 'LEARN_MORE',
				'enabled'        => 1,
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
		return ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] );
	}

	public function is_connected() {
		$s = $this->settings();
		return ! empty( $s['refresh_token'] ) || ( ! empty( $s['access_token'] ) && time() < (int) $s['token_expires'] );
	}

	public function redirect_uri() {
		return admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_oauth=google' );
	}

	public function get_connect_url() {
		$s = $this->settings();
		return add_query_arg(
			array(
				'client_id'     => $s['client_id'],
				'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => wp_create_nonce( 'ocsm_oauth_google' ),
			),
			self::AUTH_URL
		);
	}

	public function exchange_code( $code ) {
		$s = $this->settings();
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'redirect_uri'  => $this->redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		return $this->parse_token_response( $response );
	}

	public function refresh_token() {
		$s = $this->settings();
		if ( empty( $s['refresh_token'] ) ) {
			return new WP_Error( 'ocsm_no_refresh_token', __( 'Not connected to Google. Reconnect on the settings page.', 'one-click-blog-to-social-media' ) );
		}
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'refresh_token' => $s['refresh_token'],
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
					'grant_type'    => 'refresh_token',
				),
			)
		);
		return $this->parse_token_response( $response, true );
	}

	private function parse_token_response( $response, $keep_refresh = false ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'ocsm_token_error', $err );
		}
		$fields = array(
			'access_token'  => $body['access_token'],
			'token_expires' => time() + (int) $body['expires_in'] - 60,
		);
		if ( ! empty( $body['refresh_token'] ) && ! $keep_refresh ) {
			$fields['refresh_token'] = $body['refresh_token'];
		}
		$this->update_settings( $fields );
		return true;
	}

	public function get_access_token() {
		$s = $this->settings();
		if ( empty( $s['access_token'] ) && empty( $s['refresh_token'] ) ) {
			return new WP_Error( 'ocsm_not_connected', __( 'Google account is not connected.', 'one-click-blog-to-social-media' ) );
		}
		if ( empty( $s['access_token'] ) || time() >= (int) $s['token_expires'] ) {
			$result = $this->refresh_token();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$s = $this->settings();
		}
		return $s['access_token'];
	}

	private function request( $method, $url, $body = null ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
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
		$data = $this->request( 'GET', self::ACCOUNTS_BASE . '/accounts' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$accounts = isset( $data['accounts'] ) ? $data['accounts'] : array();
		$targets  = array();
		foreach ( $accounts as $account ) {
			$name     = $account['name'];
			$response = $this->request( 'GET', self::INFO_BASE . '/' . $name . '/locations?readMask=name,title' );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			if ( ! empty( $response['locations'] ) ) {
				foreach ( $response['locations'] as $location ) {
					if ( empty( $location['name'] ) ) {
						continue;
					}
					if ( 0 !== strpos( $location['name'], 'accounts/' ) ) {
						$location['name'] = $name . '/' . ltrim( $location['name'], '/' );
					}
					$targets[] = $location;
				}
			}
		}
		return $targets;
	}

	public function selected_targets() {
		return (array) $this->settings()['targets'];
	}

	public function disconnect() {
		$this->update_settings( array(
			'access_token'  => '',
			'refresh_token' => '',
			'token_expires' => 0,
			'targets'       => array(),
		) );
		delete_option( self::OPTION_TARGETS );
	}

	public function handle_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'google_save' === $action ) {
			check_admin_referer( 'ocsm_google_save' );
			$this->update_settings( array(
				'client_id'     => isset( $_POST['ocsm_google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ocsm_google_client_id'] ) ) : '',
				'client_secret' => isset( $_POST['ocsm_google_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['ocsm_google_client_secret'] ) ) : '',
				'cta_type'      => isset( $_POST['ocsm_google_cta_type'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['ocsm_google_cta_type'] ) ) ) : 'LEARN_MORE',
				'enabled'       => isset( $_POST['ocsm_google_enabled'] ) ? 1 : 0,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=saved&tab=' . $this->id() ) );
			exit;
		}

		if ( 'google_refresh' === $action ) {
			check_admin_referer( 'ocsm_google_refresh' );
			$targets = $this->get_targets();
			if ( is_wp_error( $targets ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=targets_error&ocsm_err=' . rawurlencode( $targets->get_error_message() ) . '&tab=' . $this->id() ) );
				exit;
			}
			update_option( self::OPTION_TARGETS, $targets );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=targets_refreshed&tab=' . $this->id() ) );
			exit;
		}

		if ( 'google_targets' === $action ) {
			check_admin_referer( 'ocsm_google_targets' );
			$selected = isset( $_POST['ocsm_google_targets'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ocsm_google_targets'] ) ) : array();
			$this->update_settings( array( 'targets' => array_values( array_filter( $selected ) ) ) );
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=saved&tab=' . $this->id() ) );
			exit;
		}

		if ( 'google_disconnect' === $action ) {
			check_admin_referer( 'ocsm_google_disconnect' );
			$this->disconnect();
			wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_msg=disconnected&tab=' . $this->id() ) );
			exit;
		}
	}

	public function post( $post_id, $payload ) {
		$targets = $this->selected_targets();
		if ( empty( $targets ) ) {
			$this->log( array( 'post_id' => $post_id, 'status' => 'skipped', 'message' => __( 'No Google Business locations selected.', 'one-click-blog-to-social-media' ) ) );
			return;
		}

		$body = array(
			'topicType'     => 'STANDARD',
			'languageCode'  => $payload['language_code'],
			'summary'       => $payload['summary'],
			'callToAction'  => array(
				'actionType' => $this->settings()['cta_type'] ? $this->settings()['cta_type'] : 'LEARN_MORE',
				'url'        => $payload['url'],
			),
		);
		if ( ! empty( $payload['image_url'] ) ) {
			$body['media'] = array(
				array(
					'mediaFormat' => 'PHOTO',
					'sourceUrl'   => $payload['image_url'],
				),
			);
		}

		$success = array();
		$errors  = array();
		foreach ( $targets as $location_name ) {
			$response = $this->request( 'POST', self::POSTS_BASE . '/' . $location_name . '/localPosts', $body );
			if ( is_wp_error( $response ) ) {
				$errors[ $location_name ] = $response->get_error_message();
			} elseif ( ! empty( $response['name'] ) ) {
				$success[] = $location_name;
				update_post_meta( $post_id, '_ocsm_google_post_name', $response['name'] );
			} else {
				$errors[ $location_name ] = __( 'Unknown API response.', 'one-click-blog-to-social-media' );
			}
		}

		if ( $errors ) {
			$this->log( array(
				'post_id' => $post_id,
				'status'  => 'error',
				'message' => __( 'Google API error: ', 'one-click-blog-to-social-media' ) . implode( ' | ', $errors ),
			) );
		}
		if ( $success ) {
			$this->log( array(
				'post_id' => $post_id,
				'status'  => 'success',
				'message' => sprintf( _n( 'Posted to %d location.', 'Posted to %d locations.', count( $success ), 'one-click-blog-to-social-media' ), count( $success ) ),
			) );
		}
	}

	public function render_settings() {
		$s             = $this->settings();
		$available     = get_option( self::OPTION_TARGETS, array() );
		$connected     = $this->is_connected();
		$cta_options   = array( 'LEARN_MORE', 'BOOK', 'SIGN_UP', 'CALL', 'ORDER' );
		?>
		<form method="post">
			<?php wp_nonce_field( 'ocsm_google_save' ); ?>
			<input type="hidden" name="ocsm_action" value="google_save" />
			<h2><?php echo esc_html( $this->icon() . ' ' . $this->label() ); ?></h2>
			<p><?php esc_html_e( 'Redirect URI (add this in Google Cloud Console under Authorized redirect URIs):', 'one-click-blog-to-social-media' ); ?></p>
			<code><?php echo esc_html( $this->redirect_uri() ); ?></code>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ocsm_google_client_id"><?php esc_html_e( 'Client ID', 'one-click-blog-to-social-media' ); ?></label></th>
					<td><input type="text" class="regular-text" id="ocsm_google_client_id" name="ocsm_google_client_id" value="<?php echo esc_attr( $s['client_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ocsm_google_client_secret"><?php esc_html_e( 'Client Secret', 'one-click-blog-to-social-media' ); ?></label></th>
					<td><input type="password" class="regular-text" autocomplete="off" id="ocsm_google_client_secret" name="ocsm_google_client_secret" value="<?php echo esc_attr( $s['client_secret'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'one-click-blog-to-social-media' ); ?></th>
					<td>
						<?php if ( $connected ) : ?>
							<p style="color:#00a32a;font-weight:bold;"><?php esc_html_e( 'Connected to Google', 'one-click-blog-to-social-media' ); ?></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Not connected. Save your credentials first, then click Connect.', 'one-click-blog-to-social-media' ); ?></p>
						<?php endif; ?>
						<?php if ( $this->is_configured() ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $this->get_connect_url() ); ?>"><?php esc_html_e( 'Connect Google account', 'one-click-blog-to-social-media' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ocsm_google_enabled"><?php esc_html_e( 'Post to Google Business', 'one-click-blog-to-social-media' ); ?></label></th>
					<td>
						<input type="checkbox" id="ocsm_google_enabled" name="ocsm_google_enabled" <?php checked( ! empty( $s['enabled'] ) ); ?> />
						<span class="description"><?php esc_html_e( 'Cross-post articles as "What\'s New" posts.', 'one-click-blog-to-social-media' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ocsm_google_cta_type"><?php esc_html_e( 'Call-to-action button', 'one-click-blog-to-social-media' ); ?></label></th>
					<td>
						<select id="ocsm_google_cta_type" name="ocsm_google_cta_type">
							<?php foreach ( $cta_options as $cta ) : ?>
								<option value="<?php echo esc_attr( $cta ); ?>" <?php selected( $s['cta_type'], $cta ); ?>><?php echo esc_html( $cta ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The button on the Google post links to your article.', 'one-click-blog-to-social-media' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Google settings', 'one-click-blog-to-social-media' ) ); ?>
		</form>

		<h3><?php esc_html_e( 'Business locations', 'one-click-blog-to-social-media' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'ocsm_google_refresh' ); ?>
			<input type="hidden" name="ocsm_action" value="google_refresh" />
			<?php submit_button( __( 'Refresh locations', 'one-click-blog-to-social-media' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( ! empty( $available ) ) : ?>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'ocsm_google_targets' ); ?>
				<input type="hidden" name="ocsm_action" value="google_targets" />
				<?php foreach ( $available as $location ) : ?>
					<label style="display:block;">
						<input type="checkbox" name="ocsm_google_targets[]" value="<?php echo esc_attr( $location['name'] ); ?>" <?php checked( in_array( $location['name'], (array) $s['targets'], true ) ); ?> />
						<?php
						$location_label = isset( $location['title'] ) && '' !== $location['title'] ? $location['title'] : $location['name'];
						echo esc_html( $location_label );
						?>
					</label>
				<?php endforeach; ?>
				<?php submit_button( __( 'Save locations', 'one-click-blog-to-social-media' ) ); ?>
			</form>
		<?php elseif ( $connected ) : ?>
			<p><?php esc_html_e( 'Click "Refresh locations" to load your Google Business Profile locations.', 'one-click-blog-to-social-media' ); ?></p>
		<?php endif; ?>

		<?php if ( $connected ) : ?>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'ocsm_google_disconnect' ); ?>
				<input type="hidden" name="ocsm_action" value="google_disconnect" />
				<?php submit_button( __( 'Disconnect Google account', 'one-click-blog-to-social-media' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif;
	}
}
