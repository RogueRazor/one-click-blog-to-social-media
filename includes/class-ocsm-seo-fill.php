<?php
/**
 * Automatic SEO field filling: when a post is saved and its Excerpt,
 * SEO Title, or SEO Description is empty, generate them from the content.
 * Existing values are never overwritten. Also provides a batch backfill
 * for existing posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_SEO_Fill {

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'fill_for_post' ), 20, 2 );
	}

	/**
	 * SEO plugins and their meta keys for title and description.
	 */
	public static function seo_plugins() {
		$plugins = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			$plugins['yoast'] = array(
				'title' => '_yoast_wpseo_title',
				'desc'  => '_yoast_wpseo_metadesc',
			);
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$plugins['rank_math'] = array(
				'title' => 'rank_math_title',
				'desc'  => 'rank_math_description',
			);
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$plugins['aioseo'] = array(
				'title' => '_aioseo_title',
				'desc'  => '_aioseo_description',
			);
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$plugins['seopress'] = array(
				'title' => '_seopress_titles_title',
				'desc'  => '_seopress_titles_desc',
			);
		}
		if ( defined( 'TSF_VERSION' ) ) {
			$plugins['tsf'] = array(
				'title' => 'autodescription-title',
				'desc'  => 'autodescription-description',
			);
		}
		if ( defined( 'JETPACK__VERSION' ) || class_exists( 'Jetpack' ) ) {
			$plugins['jetpack'] = array(
				'title' => 'jetpack_seo_html_title',
				'desc'  => 'advanced_seo_description',
			);
		}

		return $plugins;
	}

	/**
	 * Generate a clean excerpt from the post content.
	 */
	public static function generate_excerpt( $content ) {
		$text = wp_strip_all_tags( strip_shortcodes( $content ) );
		// Remove block markup that survives stripping.
		$text = trim( preg_replace( '/<!--(.|\s)*?-->/', '', $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$words = explode( ' ', $text );
		$words = array_filter( $words );
		$excerpt = implode( ' ', array_slice( $words, 0, 40 ) );
		return rtrim( $excerpt, " .,;:!?" ) . '.';
	}

	/**
	 * Fill empty Excerpt/SEO fields for a single post. Never overwrites.
	 */
	public static function fill_for_post( $post_id, $post = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || 'post' !== $post->post_type ) {
			return;
		}

		self::fill( $post );
	}

	/**
	 * The actual filling logic, shared by the save hook and the backfill.
	 */
	public static function fill( $post ) {
		$filled = 0;

		// Excerpt (WordPress core field).
		if ( empty( trim( (string) $post->post_excerpt ) ) ) {
			$excerpt = self::generate_excerpt( $post->post_content );
			if ( '' !== $excerpt ) {
				wp_update_post( array(
					'ID'           => $post->ID,
					'post_excerpt' => $excerpt,
				) );
				$post->post_excerpt = $excerpt;
				$filled++;
			}
		}

		// SEO plugin fields.
		$description = self::generate_description( $post );
		foreach ( self::seo_plugins() as $keys ) {
			$title = get_post_meta( $post->ID, $keys['title'], true );
			if ( '' === trim( (string) $title ) && ! empty( $post->post_title ) ) {
				update_post_meta( $post->ID, $keys['title'], $post->post_title );
				$filled++;
			}
			$desc = get_post_meta( $post->ID, $keys['desc'], true );
			if ( '' === trim( (string) $desc ) && '' !== $description ) {
				update_post_meta( $post->ID, $keys['desc'], $description );
				$filled++;
			}
		}

		return $filled;
	}

	/**
	 * SEO description: excerpt (or generated excerpt) trimmed to 155 chars.
	 */
	public static function generate_description( $post ) {
		$source = trim( (string) $post->post_excerpt );
		if ( '' === $source ) {
			$source = self::generate_excerpt( $post->post_content );
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $source ) > 155 ) {
			$source = mb_substr( $source, 0, 152 ) . '...';
		} elseif ( ! function_exists( 'mb_strlen' ) && strlen( $source ) > 155 ) {
			$source = substr( $source, 0, 152 ) . '...';
		}
		return $source;
	}

	/**
	 * Batch backfill: fill empty SEO fields for all existing posts.
	 */
	public static function fill_all() {
		$query = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$filled = 0;
		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$filled += self::fill( $post );
			}
		}
		return $filled;
	}
}
