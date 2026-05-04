<?php
/*
Plugin Name: Stora Booking Integration
Description: Native WordPress booking integration with Stora
Version: 1.0.0
Author: Bloom Digital Marketing Ltd
Author URI: https://bloomdigital.co.uk
*/

if (!defined('ABSPATH')) {
	exit;
}

define('STORA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('STORA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once STORA_PLUGIN_PATH . 'config.php';

require_once STORA_PLUGIN_PATH . 'includes/class-cache.php';
require_once STORA_PLUGIN_PATH . 'includes/class-stora-api.php';
require_once STORA_PLUGIN_PATH . 'includes/class-units.php';
require_once STORA_PLUGIN_PATH . 'includes/class-checkout.php';
require_once STORA_PLUGIN_PATH . 'includes/class-booking.php';
require_once STORA_PLUGIN_PATH . 'includes/class-webhook.php';
require_once STORA_PLUGIN_PATH . 'includes/class-admin-settings.php';

add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'stora-booking',
		STORA_PLUGIN_URL . 'assets/css/stora.css',
		[],
		file_exists(STORA_PLUGIN_PATH . 'assets/css/stora.css') ? filemtime(STORA_PLUGIN_PATH . 'assets/css/stora.css') : '1.0.0'
	);

	wp_enqueue_script(
		'stora-booking',
		STORA_PLUGIN_URL . 'assets/js/stora.js',
		[],
		file_exists(STORA_PLUGIN_PATH . 'assets/js/stora.js') ? filemtime(STORA_PLUGIN_PATH . 'assets/js/stora.js') : '1.0.0',
		true
	);
});