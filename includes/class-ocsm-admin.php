<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Admin {

	const PAGE_SLUG = 'one-click-blog-to-social-media';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	public static function add_menu() {
		add_options_page(
			__( 'One Click Blog to Social Media', 'one-click-blog-to-social-media' ),
			__( 'Blog → Social & Google', 'one-click-blog-to-social-media' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['page'], $_GET['ocsm_oauth'] ) && self::PAGE_SLUG === $_GET['page'] ) {
			$provider_id = sanitize_key( wp_unslash( $_GET['ocsm_oauth'] ) );
			$provider    = OCSM_Providers::get( $provider_id );
			if ( ! $provider ) {
				return;
			}

			if ( ! isset( $_GET['code'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ocsm_msg=oauth_failed' ) );
				exit;
			}
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			if ( ! wp_verify_nonce( $state, 'ocsm_oauth_' . $provider_id ) ) {
				OCSM_Logger::log( array(
					'status'  => 'error',
					'provider' => $provider_id,
					'title'   => 'OAuth callback',
					'message' => 'Invalid OAuth state (nonce check failed). This usually means the browser session or a caching plugin dropped the state parameter.',
				) );
				wp_die( esc_html__( 'Invalid OAuth state.', 'one-click-blog-to-social-media' ) );
			}
			$code   = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$result = $provider->exchange_code( $code );
			if ( is_wp_error( $result ) ) {
				OCSM_Logger::log( array(
					'status'   => 'error',
					'provider' => $provider_id,
					'title'    => 'OAuth connect',
					'message'  => 'Token exchange failed: ' . $result->get_error_message(),
				) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ocsm_msg=oauth_error&ocsm_err=' . rawurlencode( $result->get_error_message() ) ) );
				exit;
			}
			$s = $provider->settings();
			OCSM_Logger::log( array(
				'status'   => 'success',
				'provider' => $provider_id,
				'title'    => 'OAuth connect',
				'message'  => 'Connected via app ID ' . ( isset( $s['app_id'] ) ? $s['app_id'] : ( isset( $s['client_id'] ) ? $s['client_id'] : 'n/a' ) ) . '. Token stored.',
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ocsm_msg=oauth_success' ) );
			exit;
		}

		if ( ! isset( $_POST['ocsm_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['ocsm_action'] ) );

		if ( 'save_global' === $action ) {
			check_admin_referer( 'ocsm_save_global' );
			$settings = get_option( 'ocsm_settings', array() );
			$new = array(
				'allowed_languages' => isset( $_POST['ocsm_languages'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['ocsm_languages'] ) ) : array(),
				'post_categories'   => isset( $_POST['ocsm_categories'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ocsm_categories'] ) ) : array(),
				'fallback_image_id' => isset( $_POST['ocsm_fallback_image_id'] ) ? absint( wp_unslash( $_POST['ocsm_fallback_image_id'] ) ) : 0,
				'hashtag_always'    => isset( $_POST['ocsm_hashtag_always'] ) ? sanitize_text_field( wp_unslash( $_POST['ocsm_hashtag_always'] ) ) : '',
				'hashtag_tags'      => isset( $_POST['ocsm_hashtag_tags'] ) ? 1 : 0,
				'hashtag_categories' => isset( $_POST['ocsm_hashtag_categories'] ) ? 1 : 0,
				'hashtag_max'       => isset( $_POST['ocsm_hashtag_max'] ) ? absint( wp_unslash( $_POST['ocsm_hashtag_max'] ) ) : 5,
				'enabled'           => isset( $_POST['ocsm_enabled'] ) ? 1 : 0,
			);
			update_option( 'ocsm_settings', array_merge( $settings, $new ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ocsm_msg=saved' ) );
			exit;
		}

		if ( 'save_destinations' === $action ) {
			check_admin_referer( 'ocsm_save_destinations' );
			$enabled = isset( $_POST['ocsm_dest_enabled'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['ocsm_dest_enabled'] ) ) : array();
			foreach ( OCSM_Providers::all() as $provider ) {
				$provider->update_settings( array( 'enabled' => in_array( $provider->id(), $enabled, true ) ? 1 : 0 ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ocsm_msg=saved' ) );
			exit;
		}

		if ( 'clear_log' === $action ) {
			check_admin_referer( 'ocsm_clear_log' );
			OCSM_Logger::clear();
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ocsm_msg=log_cleared' ) );
			exit;
		}

		foreach ( OCSM_Providers::all() as $provider ) {
			if ( 0 === strpos( $action, $provider->id() . '_' ) ) {
				$provider->handle_action( $action );
				return;
			}
		}
	}

	private static function messages() {
		return array(
			'saved'             => __( 'Settings saved.', 'one-click-blog-to-social-media' ),
			'oauth_success'     => __( 'Account connected successfully.', 'one-click-blog-to-social-media' ),
			'oauth_error'       => __( 'Could not connect. Check your credentials and that the redirect URI matches exactly.', 'one-click-blog-to-social-media' ),
			'oauth_failed'      => __( 'The provider returned no authorization code.', 'one-click-blog-to-social-media' ),
			'disconnected'      => __( 'Account disconnected.', 'one-click-blog-to-social-media' ),
			'targets_refreshed' => __( 'Targets refreshed.', 'one-click-blog-to-social-media' ),
			'targets_error'     => __( 'Could not load targets: ', 'one-click-blog-to-social-media' ),
			'log_cleared'       => __( 'Log cleared.', 'one-click-blog-to-social-media' ),
		);
	}

	public static function render_page() {
		$settings = wp_parse_args( get_option( 'ocsm_settings', array() ), array(
			'allowed_languages' => array(),
			'post_categories'   => array(),
			'fallback_image_id' => 0,
			'hashtag_always'    => '',
			'hashtag_tags'      => 1,
			'hashtag_categories' => 0,
			'hashtag_max'       => 5,
			'enabled'           => 1,
		) );
		$messages = self::messages();

		if ( isset( $_GET['ocsm_msg'] ) && isset( $messages[ $_GET['ocsm_msg'] ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = sanitize_key( wp_unslash( $_GET['ocsm_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg = $messages[ $key ];
			if ( isset( $_GET['ocsm_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$msg .= ' ' . sanitize_text_field( rawurldecode( wp_unslash( $_GET['ocsm_err'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$error_keys = array( 'oauth_error', 'oauth_failed', 'targets_error' );
			$class = in_array( $key, $error_keys, true ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'One Click Blog to Social Media', 'one-click-blog-to-social-media' ); ?></h1>

			<h2><?php esc_html_e( 'Destinations', 'one-click-blog-to-social-media' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'ocsm_save_destinations' ); ?>
				<input type="hidden" name="ocsm_action" value="save_destinations" />
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'On', 'one-click-blog-to-social-media' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'one-click-blog-to-social-media' ); ?></th>
							<th><?php esc_html_e( 'Status', 'one-click-blog-to-social-media' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( OCSM_Providers::all() as $provider ) : ?>
							<tr>
								<td><input type="checkbox" name="ocsm_dest_enabled[]" value="<?php echo esc_attr( $provider->id() ); ?>" <?php checked( $provider->enabled() ); ?> /></td>
								<td><?php echo esc_html( $provider->icon() . ' ' . $provider->label() ); ?></td>
								<td>
									<?php if ( $provider->is_connected() ) : ?>
										<span style="color:#00a32a;"><?php esc_html_e( 'Connected', 'one-click-blog-to-social-media' ); ?></span>
									<?php else : ?>
										<span style="color:#d63638;"><?php esc_html_e( 'Not connected', 'one-click-blog-to-social-media' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Turn each destination on or off. The language and category filters below apply to every destination.', 'one-click-blog-to-social-media' ); ?></p>
				<?php submit_button( __( 'Save destinations', 'one-click-blog-to-social-media' ) ); ?>
			</form>

			<hr />

			<form method="post">
				<?php wp_nonce_field( 'ocsm_save_global' ); ?>
				<input type="hidden" name="ocsm_action" value="save_global" />
				<h2><?php esc_html_e( 'General settings', 'one-click-blog-to-social-media' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ocsm_enabled"><?php esc_html_e( 'Enable auto-posting', 'one-click-blog-to-social-media' ); ?></label></th>
						<td>
							<input type="checkbox" id="ocsm_enabled" name="ocsm_enabled" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<span class="description"><?php esc_html_e( 'Cross-post new published articles to all enabled destinations below.', 'one-click-blog-to-social-media' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Languages to post', 'one-click-blog-to-social-media' ); ?></th>
						<td>
							<?php foreach ( OCSM_Language::available_languages() as $code => $label ) : ?>
								<label style="display:inline-block;margin-right:12px;">
									<input type="checkbox" name="ocsm_languages[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $settings['allowed_languages'], true ) ); ?> />
									<?php echo esc_html( $label . ' (' . $code . ')' ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Leave all unchecked to post in all languages. Articles in other languages are skipped.', 'one-click-blog-to-social-media' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Only these categories', 'one-click-blog-to-social-media' ); ?></th>
						<td>
							<div style="max-height:200px;overflow:auto;">
								<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $category ) : ?>
									<label style="display:block;">
										<input type="checkbox" name="ocsm_categories[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, array_map( 'intval', (array) $settings['post_categories'] ), true ) ); ?> />
										<?php echo esc_html( $category->name ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Leave all unchecked to post articles from all categories.', 'one-click-blog-to-social-media' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ocsm_fallback_image_id"><?php esc_html_e( 'Fallback image ID', 'one-click-blog-to-social-media' ); ?></label></th>
						<td>
							<input type="number" min="0" id="ocsm_fallback_image_id" name="ocsm_fallback_image_id" value="<?php echo esc_attr( $settings['fallback_image_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Media library attachment ID used when an article has no featured image. Leave 0 for text-only posts.', 'one-click-blog-to-social-media' ); ?></p>
						</td>
					</tr>
						<tr>
							<th scope="row"><label for="ocsm_hashtag_always"><?php esc_html_e( 'Always include these hashtags', 'one-click-blog-to-social-media' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="ocsm_hashtag_always" name="ocsm_hashtag_always" value="<?php echo esc_attr( $settings['hashtag_always'] ); ?>" placeholder="#RogueRazor" />
								<p class="description"><?php esc_html_e( 'Space- or comma-separated. Added to every Facebook and Instagram post, e.g. your business name.', 'one-click-blog-to-social-media' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Automatic hashtags', 'one-click-blog-to-social-media' ); ?></th>
							<td>
								<label style="display:block;">
									<input type="checkbox" name="ocsm_hashtag_tags" <?php checked( ! empty( $settings['hashtag_tags'] ) ); ?> />
									<?php esc_html_e( "From the article's tags", 'one-click-blog-to-social-media' ); ?>
								</label>
								<label style="display:block;">
									<input type="checkbox" name="ocsm_hashtag_categories" <?php checked( ! empty( $settings['hashtag_categories'] ) ); ?> />
									<?php esc_html_e( "From the article's categories", 'one-click-blog-to-social-media' ); ?>
								</label>
								<label style="display:block;margin-top:6px;">
									<?php esc_html_e( 'Maximum automatic hashtags:', 'one-click-blog-to-social-media' ); ?>
									<input type="number" min="0" max="30" name="ocsm_hashtag_max" value="<?php echo esc_attr( $settings['hashtag_max'] ); ?>" style="width:70px;" />
								</label>
								<p class="description"><?php esc_html_e( 'Added to Facebook and Instagram posts. Google Business Profile posts stay hashtag-free.', 'one-click-blog-to-social-media' ); ?></p>
							</td>
						</tr>
				</table>
				<?php submit_button( __( 'Save general settings', 'one-click-blog-to-social-media' ) ); ?>
			</form>

			<hr />

			<?php foreach ( OCSM_Providers::all() as $provider ) : ?>
				<?php $provider->render_settings(); ?>
				<hr />
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Posting log', 'one-click-blog-to-social-media' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'ocsm_clear_log' ); ?>
				<input type="hidden" name="ocsm_action" value="clear_log" />
				<?php submit_button( __( 'Clear log', 'one-click-blog-to-social-media' ), 'delete', 'submit', false ); ?>
			</form>
			<table class="widefat striped" style="max-width:900px;margin-top:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'one-click-blog-to-social-media' ); ?></th>
						<th><?php esc_html_e( 'Article', 'one-click-blog-to-social-media' ); ?></th>
						<th><?php esc_html_e( 'Destination', 'one-click-blog-to-social-media' ); ?></th>
						<th><?php esc_html_e( 'Language', 'one-click-blog-to-social-media' ); ?></th>
						<th><?php esc_html_e( 'Status', 'one-click-blog-to-social-media' ); ?></th>
						<th><?php esc_html_e( 'Message', 'one-click-blog-to-social-media' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php $entries = OCSM_Logger::get_entries(); ?>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No entries yet.', 'one-click-blog-to-social-media' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td>
									<?php
									if ( ! empty( $entry['post_id'] ) ) {
										echo '<a href="' . esc_url( get_edit_post_link( (int) $entry['post_id'] ) ) . '">' . esc_html( $entry['title'] ? $entry['title'] : ( '#' . $entry['post_id'] ) ) . '</a>';
									} else {
										echo esc_html( $entry['title'] );
									}
									?>
								</td>
								<td><?php echo esc_html( $entry['provider'] ); ?></td>
								<td><?php echo esc_html( $entry['lang'] ); ?></td>
								<td><?php echo esc_html( $entry['status'] ); ?></td>
								<td><?php echo esc_html( $entry['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
