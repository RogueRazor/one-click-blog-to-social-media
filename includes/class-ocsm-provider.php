<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class OCSM_Provider {

	abstract public function id();

	abstract public function label();

	abstract public function icon();

	abstract public function settings();

	abstract public function update_settings( $fields );

	abstract public function is_configured();

	abstract public function is_connected();

	abstract public function redirect_uri();

	abstract public function get_connect_url();

	abstract public function exchange_code( $code );

	abstract public function disconnect();

	abstract public function get_targets();

	abstract public function selected_targets();

	abstract public function render_settings();

	abstract public function handle_action( $action );

	abstract public function post( $post_id, $payload );

	public function install_defaults() {}

	public function enabled() {
		return ! empty( $this->settings()['enabled'] );
	}

	public function log( $entry ) {
		$entry['provider'] = $this->id();
		OCSM_Logger::log( $entry );
	}
}
