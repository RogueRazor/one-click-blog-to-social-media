<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Logger {

	const OPTION = 'ocsm_log';
	const MAX_ENTRIES = 100;

	public static function log( $entry ) {
		$entry = array_merge(
			array(
				'post_id' => 0,
				'title'   => '',
			),
			$entry
		);
		if ( empty( $entry['title'] ) && ! empty( $entry['post_id'] ) ) {
			$entry['title'] = get_the_title( (int) $entry['post_id'] );
		}
		$entries = get_option( self::OPTION, array() );
		array_unshift( $entries, array_merge(
			array(
				'time'     => current_time( 'mysql' ),
				'post_id'  => 0,
				'title'    => '',
				'lang'     => '',
				'provider' => '',
				'status'   => 'info',
				'message'  => '',
			),
			$entry
		) );
		$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		update_option( self::OPTION, $entries );
	}

	public static function get_entries() {
		return get_option( self::OPTION, array() );
	}

	public static function clear() {
		update_option( self::OPTION, array() );
	}
}
