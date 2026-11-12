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
				'hashtag_always'    => '',
				'hashtag_tags'      => 1,
				'hashtag_categories' => 0,
				'hashtag_max'       => 5,
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
			'image_urls'    => self::collect_image_urls( $post_id, $settings ),
			'video_url'     => self::collect_video_urls( $post_id ),
			'video_urls'    => self::collect_video_urls( $post_id ),
			'language_code' => $lang ? $lang : get_locale(),
			'hashtags'      => self::build_hashtags( $post_id, $settings ),
		);

		OCSM_Logger::log( array(
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'status'  => 'info',
			'message' => sprintf(
				__( 'Collected %d image(s) and %d video(s) for cross-posting.', 'one-click-blog-to-social-media' ),
				count( (array) $payload['image_urls'] ),
				count( (array) $payload['video_urls'] )
			),
		) );

		foreach ( $providers as $provider ) {
			if ( ! $provider->is_connected() ) {
				$provider->log( array( 'post_id' => $post_id, 'title' => get_the_title( $post_id ), 'status' => 'skipped', 'message' => sprintf( __( '%s account not connected.', 'one-click-blog-to-social-media' ), $provider->label() ) ) );
				continue;
			}
			$provider->post( $post_id, $payload );
		}
	}

	private static function build_hashtags( $post_id, $settings ) {
		$tags = array();

		if ( ! empty( $settings['hashtag_always'] ) ) {
			foreach ( preg_split( '/[\s,]+/', trim( (string) $settings['hashtag_always'] ) ) as $word ) {
				if ( '' !== $word ) {
					$tags[] = ltrim( $word, '#' );
				}
			}
		}

		$auto = array();
		if ( ! empty( $settings['hashtag_tags'] ) ) {
			$auto = array_merge( $auto, wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) );
		}
		if ( ! empty( $settings['hashtag_categories'] ) ) {
			$auto = array_merge( $auto, wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ) );
		}

		$max = isset( $settings['hashtag_max'] ) ? max( 0, (int) $settings['hashtag_max'] ) : 5;
		if ( $max ) {
			$auto = array_slice( $auto, 0, $max );
		}

		foreach ( $auto as $name ) {
			$tag = preg_replace( '/[^\p{L}\p{N}_]+/u', '', str_replace( ' ', '', (string) $name ) );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		// Per-article hashtags approved in the post editor metabox.
		$per_post = get_post_meta( $post_id, '_ocsm_hashtags', true );
		if ( is_array( $per_post ) ) {
			foreach ( $per_post as $name ) {
				$tag = preg_replace( '/[^\p{L}\p{N}_]+/u', '', str_replace( ' ', '', ltrim( (string) $name, '#' ) ) );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
		}

		$tags = array_unique( $tags );
		if ( empty( $tags ) ) {
			return '';
		}
		return implode( ' ', array_map( function ( $tag ) {
			return '#' . $tag;
		}, $tags ) );
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

	/**
	 * Collect MP4 videos embedded in the article content (video blocks,
	 * source tags). Only local, publicly servable MP4 URLs are returned.
	 */
	private static function collect_video_urls( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$urls = array();
		if ( preg_match_all( '/<video[^>]*\ssrc=[\'"]([^\'"]+)[\'"]/i', $post->post_content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$urls[] = $url;
			}
		}
		if ( preg_match_all( '/<source[^>]*\ssrc=[\'"]([^\'"]+)[\'"]/i', $post->post_content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$urls[] = $url;
			}
		}
		$out = array();
		foreach ( $urls as $url ) {
			if ( 0 === strpos( $url, 'data:' ) ) {
				continue;
			}
			if ( false === stripos( $url, '.mp4' ) ) {
				continue;
			}
			if ( ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	private static function get_image_url( $post_id, $settings ) {
		$attachment_id = get_post_thumbnail_id( $post_id );
		if ( ! $attachment_id && ! empty( $settings['fallback_image_id'] ) ) {
			$attachment_id = absint( $settings['fallback_image_id'] );
		}
		if ( ! $attachment_id ) {
			return '';
		}
		return self::prepare_attachment( $attachment_id );
	}

	/**
	 * Resolve an image URL (full-size, scaled, or any intermediate size)
	 * to its attachment ID. Returns 0 when the URL is not a known attachment.
	 */
	private static function attachment_id_from_url( $url ) {
		$url = (string) $url;
		$id  = (int) attachment_url_to_postid( $url );
		if ( $id ) {
			return $id;
		}

		// Strip known WordPress size suffixes: -1024x768, -scaled, -e12.
		$base = preg_replace( '/-(\d+x\d+|scaled|e\d+)(?=\.[a-zA-Z]{3,4}$)/', '', $url );
		if ( $base && $base !== $url ) {
			$id = (int) attachment_url_to_postid( $base );
			if ( $id ) {
				return $id;
			}
		}

		// Last resort: look the attachment up by filename in the uploads meta.
		$path = wp_parse_url( $base ? $base : $url, PHP_URL_PATH );
		$filename = $path ? basename( $path ) : '';
		if ( $filename ) {
			$query = new WP_Query( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_wp_attached_file',
						'value'   => $filename,
						'compare' => 'LIKE',
					),
				),
			) );
			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}

		return 0;
	}

	/**
	 * Collect every usable image for the article: featured image first,
	 * then all images embedded in the content, in order. Each is converted
	 * to a platform-safe JPEG (max width 1440, max 5 MB).
	 */
	private static function collect_image_urls( $post_id, $settings ) {
		$ids = array();
		$featured_id = get_post_thumbnail_id( $post_id );
		if ( ! $featured_id && ! empty( $settings['fallback_image_id'] ) ) {
			$featured_id = absint( $settings['fallback_image_id'] );
		}
		if ( $featured_id ) {
			$ids[] = (int) $featured_id;
		}

		$post = get_post( $post_id );
		if ( $post ) {
			$content = $post->post_content;

			// 1. Gallery/image blocks: <!-- wp:gallery {"ids":[1,2,3]} -->
			if ( preg_match_all( '/"ids":\[([0-9,\s]+)\]/', $content, $matches ) ) {
				foreach ( $matches[1] as $list ) {
					foreach ( explode( ',', $list ) as $id ) {
						$id = (int) trim( $id );
						if ( $id && ! in_array( $id, $ids, true ) ) {
							$ids[] = $id;
						}
					}
				}
			}

			// 2. Classic gallery shortcodes: [gallery ids="1,2,3"]
			if ( preg_match_all( '/\[gallery[^\]]*ids=[\'"]([0-9,\s]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $list ) {
					foreach ( explode( ',', $list ) as $id ) {
						$id = (int) trim( $id );
						if ( $id && ! in_array( $id, $ids, true ) ) {
							$ids[] = $id;
						}
					}
				}
			}

			// 3. Inline image tags (image blocks, HTML galleries).
			if ( preg_match_all( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $matches ) ) {
				foreach ( $matches[1] as $src ) {
					if ( 0 === strpos( $src, 'data:' ) ) {
						continue;
					}
					$attachment_id = self::attachment_id_from_url( $src );
					if ( $attachment_id && ! in_array( $attachment_id, $ids, true ) ) {
						$ids[] = $attachment_id;
					}
				}
			}
		}

		$urls = array();
		foreach ( $ids as $attachment_id ) {
			$url = self::prepare_attachment( $attachment_id );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Return a URL to a platform-safe version of the attachment: JPEG,
	 * max width 1440px, max 5 MB (accepted by Google, Facebook, Instagram).
	 * Returns the original URL when it already complies.
	 */
	private static function prepare_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			return $url ? $url : '';
		}
		$check = wp_check_filetype( $file );
		$ext   = strtolower( (string) $check['ext'] );
		// Instagram only accepts JPEG; Google and Facebook accept it too.
		$needs_conversion = ! in_array( $ext, array( 'jpg', 'jpeg' ), true );
		$needs_resize = false;

		if ( function_exists( 'getimagesize' ) ) {
			$size = @getimagesize( $file );
			if ( $size && (int) $size[0] > 1440 ) {
				$needs_resize = true;
			}
		}
		if ( filesize( $file ) > 5 * MB_IN_BYTES ) {
			$needs_resize = true;
		}
		if ( ! $needs_conversion && ! $needs_resize ) {
			$url = wp_get_attachment_url( $attachment_id );
			return $url ? $url : '';
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			return $url ? $url : '';
		}
		$editor->set_quality( 85 );
		if ( $needs_resize ) {
			$editor->resize( 1440, 1440, false );
		}
		$upload = wp_upload_dir();
		$target_dir = trailingslashit( $upload['basedir'] ) . 'ocsm';
		if ( ! wp_mkdir_p( $target_dir ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			return $url ? $url : '';
		}
		$target = $target_dir . '/ocsm-' . $attachment_id . '.jpg';
		$result = $editor->save( $target, 'image/jpeg' );
		if ( is_wp_error( $result ) || filesize( $result['path'] ) > 5 * MB_IN_BYTES ) {
			$url = wp_get_attachment_url( $attachment_id );
			return $url ? $url : '';
		}
		return trailingslashit( $upload['baseurl'] ) . 'ocsm/' . basename( $result['path'] );
	}
}
