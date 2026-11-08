<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSM_Providers {

	private static $instances = null;

	public static function all() {
		if ( null === self::$instances ) {
			self::$instances = array(
				'google'   => new OCSM_Provider_Google(),
				'facebook' => new OCSM_Provider_Facebook(),
				'instagram' => new OCSM_Provider_Instagram(),
			);
		}
		return self::$instances;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function enabled() {
		$enabled = array();
		foreach ( self::all() as $provider ) {
			if ( $provider->enabled() ) {
				$enabled[] = $provider;
			}
		}
		return $enabled;
	}
}
