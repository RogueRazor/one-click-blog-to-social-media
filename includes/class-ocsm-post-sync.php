<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Post_Sync {

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_post' ) );
	}

	private static function settings() {
		return wp_parse_args(
			get_option( 'ocsm_settings', array() ),
			array(
				'allowed_languages' => array(),
				'post_categories'   => array(),
				'fallback_image_id' => 0,
				'enabled'           => 1,
			)
		);
	}

	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		self::sync_post( $post->ID );
	}

	public static function row_action( $actions, $post ) {
		if ( 'post' === $post->post_type && current_user_can( 'manage_options' ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_sync_post=' . $post->ID ),
				'ocsm_sync_' . $post->ID
			);
			$actions['ocsm_sync'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Cross-post', 'one-click-blog-to-social-media' ) . '</a>';
		}
		return $actions;
	}

	public static function handle_manual_post() {
		if ( ! isset( $_GET['ocsm_sync_post'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$post_id = absint( $_GET['ocsm_sync_post'] );
		check_admin_referer( 'ocsm_sync_' . $post_id );
		self::sync_post( $post_id );
		wp_safe_redirect( admin_url( 'admin.php?page=one-click-blog-to-social-media&ocsm_synced=' . $post_id ) );
		exit;
	}

	public static function sync_post( $post_id ) {
		$settings = self::settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$providers = OCSM_Providers::enabled();
		if ( empty( $providers ) ) {
			return;
		}

		$lang = OCSM_Language::detect( $post_id );
		if ( ! empty( $settings['allowed_languages'] ) && ! in_array( $lang, (array) $settings['allowed_languages'], true ) ) {
			OCSM_Logger::log( array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'lang'    => $lang ? $lang : __( 'unknown', 'one-click-blog-to-social-media' ),
				'status'  => 'skipped',
				'message' => __( 'Language not selected for cross-posting.', 'one-click-blog-to-social-media' ),
			) );
			return;
		}

		if ( ! empty( $settings['post_categories'] ) ) {
			$post_cats = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
			if ( empty( array_intersect( (array) $settings['post_categories'], $post_cats ) ) ) {
				OCSM_Logger::log( array( 'post_id' => $post_id, 'title' => get_the_title( $post_id ), 'status' => 'skipped', 'message' => __( 'Post not in selected categories.', 'one-click-blog-to-social-media' ) ) );
				return;
			}
		}

		$post = get_post( $post_id );
		$excerpt = self::build_excerpt( $post );
		if ( '' === $excerpt ) {
			OCSM_Logger::log( array( 'post_id' => $post_id, 'title' => get_the_title( $post_id ), 'status' => 'skipped', 'message' => __( 'Post has no content to share.', 'one-click-blog-to-social-media' ) ) );
			return;
		}

		$payload = array(
			'title'         => $post->post_title,
			'excerpt'       => $excerpt,
			'summary'       => trim( $post->post_title . "\n\n" . $excerpt ),
			'url'           => get_permalink( $post_id ),
			'image_url'     => self::get_image_url( $post_id, $settings ),
			'language_code' => $lang ? $lang : get_locale(),
		);

		foreach ( $providers as $provider ) {
			if ( ! $provider->is_connected() ) {
				$provider->log( array( 'post_id' => $post_id, 'title' => get_the_title( $post_id ), 'status' => 'skipped', 'message' => sprintf( __( '%s account not connected.', 'one-click-blog-to-social-media' ), $provider->label() ) ) );
				continue;
			}
			$provider->post( $post_id, $payload );
		}
	}

	private static function build_excerpt( $post ) {
		if ( $post->post_excerpt ) {
			$excerpt = $post->post_excerpt;
		} else {
			$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$excerpt = wp_trim_words( $content, 40, '…' );
		}
		if ( mb_strlen( $excerpt ) > 1500 ) {
			$excerpt = mb_substr( $excerpt, 0, 1497 ) . '…';
		}
		return $excerpt;
	}

	private static function get_image_url( $post_id, $settings ) {
		$attachment_id = get_post_thumbnail_id( $post_id );
		if ( ! $attachment_id && ! empty( $settings['fallback_image_id'] ) ) {
			$attachment_id = absint( $settings['fallback_image_id'] );
		}
		if ( ! $attachment_id ) {
			return '';
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return wp_get_attachment_url( $attachment_id );
		}
		$check = wp_check_filetype( $file );
		$ext   = strtolower( (string) $check['ext'] );
		$needs_conversion = ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true );
		$needs_resize = false;

		if ( function_exists( 'getimagesize' ) ) {
			$size = @getimagesize( $file );
			if ( $size && ( $size[0] < 400 || $size[1] < 300 ) ) {
				$needs_resize = true;
			}
		}
		if ( filesize( $file ) > 5 * MB_IN_BYTES ) {
			$needs_resize = true;
		}
		if ( ! $needs_conversion && ! $needs_resize ) {
			return wp_get_attachment_url( $attachment_id );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return wp_get_attachment_url( $attachment_id );
		}
		if ( $needs_conversion ) {
			$editor->set_quality( 85 );
		}
		if ( filesize( $file ) > 5 * MB_IN_BYTES ) {
			$editor->resize( 1600, 1600, false );
		}
		$upload = wp_upload_dir();
		$target_dir = trailingslashit( $upload['basedir'] ) . 'ocsm';
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return wp_get_attachment_url( $attachment_id );
		}
		$target = $target_dir . '/ocsm-' . $attachment_id . '.jpg';
		$result = $editor->save( $target, 'image/jpeg' );
		if ( is_wp_error( $result ) || filesize( $result['path'] ) > 5 * MB_IN_BYTES ) {
			return wp_get_attachment_url( $attachment_id );
		}
		return trailingslashit( $upload['baseurl'] ) . 'ocsm/' . basename( $result['path'] );
	}
}
