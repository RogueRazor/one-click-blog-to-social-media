<?php
/**
 * Plugin Name: One Click Blog to Social Media
 * Description: Automatically cross-posts published articles to your Google Business Profile and social media (Facebook Pages, and more) from one plugin.
 * Version: 1.0.1
 * Author: RogueRazor
 * License: GPL-2.0-or-later
 * Text Domain: one-click-blog-to-social-media
 * Update URI: https://github.com/RogueRazor/one-click-blog-to-social-media
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OCSM_VERSION', '1.0.1' );
define( 'OCSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OCSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once OCSM_PLUGIN_DIR . 'includes/class-ocsm-provider.php';
require_once OCSM_PLUGIN_DIR . 'includes/class-ocsm-providers.php';
require_once OCSM_PLUGIN_DIR . 'includes/providers/class-ocsm-provider-google.php';
require_once OCSM_PLUGIN_DIR . 'includes/providers/class-ocsm-provider-facebook.php';
require_once OCSM_PLUGIN_DIR . 'includes/providers/class-ocsm-provider-instagram.php';
require_once OCSM_PLUGIN_DIR . 'includes/class-ocsm-language.php';
require_once OCSM_PLUGIN_DIR . 'includes/class-ocsm-logger.php';
require_once OCSM_PLUGIN_DIR . 'includes/class-ocsm-post-sync.php';
require_once OCSM_PLUGIN_DIR . 'includes/class-ocsm-admin.php';
require_once OCSM_PLUGIN_DIR . 'includes/class-ocsm-updater.php';

final class OCSM_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'init', array( $this, 'init' ) );
	}

	public function activate() {
		if ( false === get_option( 'ocsm_settings' ) ) {
			add_option( 'ocsm_settings', array(
				'allowed_languages' => array(),
				'post_categories'   => array(),
				'fallback_image_id' => 0,
				'enabled'           => 1,
			) );
		}
		if ( false === get_option( 'ocsm_log' ) ) {
			add_option( 'ocsm_log', array() );
		}
		foreach ( OCSM_Providers::all() as $provider ) {
			$provider->install_defaults();
		}
	}

	public function init() {
		OCSM_Admin::init();
		OCSM_Post_Sync::init();
		OCSM_Updater::init();
	}
}

function ocsm() {
	return OCSM_Plugin::instance();
}

ocsm();
