<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Language {

	public static function detect( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id, 'slug' );
			if ( $lang ) {
				return $lang;
			}
		}
		$lang = get_post_meta( $post_id, '_wpml_language', true );
		if ( $lang ) {
			return $lang;
		}
		return self::detect_text( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) );
	}

	public static function detect_text( $text ) {
		if ( trim( $text ) === '' ) {
			return '';
		}
		if ( preg_match( '/[\x{4E00}-\x{9FFF}]/u', $text ) ) {
			return 'zh';
		}
		if ( preg_match( '/[\x{3040}-\x{30FF}]/u', $text ) ) {
			return 'ja';
		}
		if ( preg_match( '/[\x{AC00}-\x{D7AF}]/u', $text ) ) {
			return 'ko';
		}
		if ( preg_match( '/[\x{0400}-\x{04FF}]/u', $text ) ) {
			return 'ru';
		}
		if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $text ) ) {
			return 'ar';
		}
		$latin = array(
			'fr' => '/\b(le|la|les|des|une|est|pour|dans|avec|sur|nous)\b/i',
			'de' => '/\b(der|die|das|und|ist|nicht|mit|für|auf|wir)\b/i',
			'es' => '/\b(el|la|los|las|una|es|para|con|por|nosotros)\b/i',
			'pt' => '/\b(o|a|os|as|uma|é|para|com|nós)\b/i',
			'it' => '/\b(il|la|gli|una|è|per|con|noi)\b/i',
			'nl' => '/\b(de|het|een|is|niet|met|voor|wij)\b/i',
			'en' => '/\b(the|and|is|for|with|that|this|from|have)\b/i',
		);
		foreach ( $latin as $code => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return $code;
			}
		}
		return 'en';
	}

	public static function available_languages() {
		$languages = array(
			'en' => __( 'English', 'one-click-blog-to-social-media' ),
			'fr' => __( 'French', 'one-click-blog-to-social-media' ),
			'de' => __( 'German', 'one-click-blog-to-social-media' ),
			'es' => __( 'Spanish', 'one-click-blog-to-social-media' ),
			'pt' => __( 'Portuguese', 'one-click-blog-to-social-media' ),
			'it' => __( 'Italian', 'one-click-blog-to-social-media' ),
			'nl' => __( 'Dutch', 'one-click-blog-to-social-media' ),
			'ru' => __( 'Russian', 'one-click-blog-to-social-media' ),
			'ar' => __( 'Arabic', 'one-click-blog-to-social-media' ),
			'zh' => __( 'Chinese', 'one-click-blog-to-social-media' ),
			'ja' => __( 'Japanese', 'one-click-blog-to-social-media' ),
			'ko' => __( 'Korean', 'one-click-blog-to-social-media' ),
		);
		return apply_filters( 'ocsm_available_languages', $languages );
	}
}
