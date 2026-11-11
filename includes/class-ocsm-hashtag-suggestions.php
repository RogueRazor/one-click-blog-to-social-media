<?php
/**
 * Per-article hashtag suggestions: a metabox in the post editor that
 * suggests hashtags (from tags, categories, and keywords extracted from
 * the content) which the author can tick per article.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Hashtag_Suggestions {

	const META_KEY = '_ocsm_hashtags';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function add_meta_box() {
		add_meta_box(
			'ocsm_hashtags',
			__( 'Social media hashtags', 'one-click-blog-to-social-media' ),
			array( __CLASS__, 'render' ),
			'post',
			'side'
		);
	}

	/**
	 * Suggested hashtags for an article: its tags, its categories, and
	 * the most frequent meaningful words from the content.
	 */
	public static function suggestions( $post_id ) {
		$items = array();

		foreach ( wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) as $name ) {
			$items[] = $name;
		}
		foreach ( wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ) as $name ) {
			$items[] = $name;
		}

		$post = get_post( $post_id );
		if ( $post ) {
			$stopwords = array(
				'the', 'and', 'for', 'you', 'your', 'with', 'that', 'this', 'from', 'have',
				'are', 'was', 'were', 'not', 'but', 'can', 'will', 'our', 'their', 'when',
				'what', 'which', 'them', 'they', 'has', 'had', 'how', 'why', 'who', 'all',
				'out', 'about', 'into', 'over', 'after', 'before', 'more', 'most', 'some',
				'such', 'only', 'also', 'than', 'then', 'been', 'being', 'its', 'just',
				'one', 'two', 'get', 'got', 'her', 'his', 'she', 'him', 'make', 'made',
				'want', 'need', 'like', 'very', 'much', 'here', 'there', 'where', 'while',
				'because', 'could', 'should', 'would', 'these', 'those', 'other', 'again',
				'still', 'even', 'ever', 'never', 'always', 'between', 'both', 'under',
				'above', 'below', 'each', 'same', 'too', 'own', 'het', 'deze', 'een', 'van',
				'en', 'met', 'voor', 'niet', 'zijn', 'dat', 'ook', 'maar', 'bij', 'om',
				'er', 'te', 'je', 'wij', 'ons', 'hij', 'zij', 'dan', 'of', 'op', 'als',
			);
			$text   = wp_strip_all_tags( $post->post_content );
			$words  = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
			$counts = array();
			foreach ( (array) $words as $word ) {
				if ( function_exists( 'mb_strlen' ) ? mb_strlen( $word ) < 4 : strlen( $word ) < 4 ) {
					continue;
				}
				$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word ) : strtolower( $word );
				if ( in_array( $lower, $stopwords, true ) ) {
					continue;
				}
				$counts[ $lower ] = isset( $counts[ $lower ] ) ? $counts[ $lower ] + 1 : 1;
			}
			arsort( $counts );
			foreach ( array_slice( array_keys( $counts ), 0, 8 ) as $keyword ) {
				$items[] = $keyword;
			}
		}

		$clean = array();
		foreach ( $items as $name ) {
			$tag = preg_replace( '/[^\p{L}\p{N}_]+/u', '', str_replace( ' ', '', (string) $name ) );
			if ( '' !== $tag && ! isset( $clean[ strtolower( $tag ) ] ) ) {
				$clean[ strtolower( $tag ) ] = $tag;
			}
		}
		return array_values( $clean );
	}

	public static function render( $post ) {
		wp_nonce_field( 'ocsm_hashtags_save', 'ocsm_hashtags_nonce' );
		$saved = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$suggestions = self::suggestions( $post->ID );
		?>
		<p><?php esc_html_e( 'Tick the hashtags to add to this article\'s Facebook and Instagram posts:', 'one-click-blog-to-social-media' ); ?></p>
		<?php if ( empty( $suggestions ) ) : ?>
			<p><em><?php esc_html_e( 'No suggestions yet — add tags or write some content first.', 'one-click-blog-to-social-media' ); ?></em></p>
		<?php else : ?>
			<?php foreach ( $suggestions as $tag ) : ?>
				<label style="display:block;">
					<input type="checkbox" name="ocsm_hashtag_suggestions[]" value="<?php echo esc_attr( $tag ); ?>" <?php checked( in_array( $tag, $saved, true ) ); ?> />
					#<?php echo esc_html( $tag ); ?>
				</label>
			<?php endforeach; ?>
		<?php endif; ?>
		<p>
			<label for="ocsm_hashtag_custom"><?php esc_html_e( 'Custom hashtags:', 'one-click-blog-to-social-media' ); ?></label>
			<input type="text" class="widefat" id="ocsm_hashtag_custom" name="ocsm_hashtag_custom"
				value="<?php echo esc_attr( implode( ' ', array_diff( $saved, $suggestions ) ) ); ?>"
				placeholder="#amsterdam #haircut" />
		</p>
		<p class="description"><?php esc_html_e( 'Added on top of the automatic hashtags configured in the plugin settings.', 'one-click-blog-to-social-media' ); ?></p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['ocsm_hashtags_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ocsm_hashtags_nonce'] ) ), 'ocsm_hashtags_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || 'post' !== $post->post_type ) {
			return;
		}

		$checked = isset( $_POST['ocsm_hashtag_suggestions'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ocsm_hashtag_suggestions'] ) )
			: array();
		$custom = isset( $_POST['ocsm_hashtag_custom'] )
			? sanitize_text_field( wp_unslash( $_POST['ocsm_hashtag_custom'] ) )
			: '';

		$all = $checked;
		foreach ( preg_split( '/[\s,]+/', trim( $custom ) ) as $word ) {
			$word = ltrim( trim( $word ), '#' );
			if ( '' !== $word ) {
				$all[] = $word;
			}
		}

		$all = array_values( array_unique( array_filter( $all ) ) );
		if ( empty( $all ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $all );
		}
	}
}
