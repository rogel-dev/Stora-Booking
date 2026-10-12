<?php
/*
Plugin Name: Stora Booking Integration
Description: Native WordPress booking integration with Stora
Version: 1.1.0
Author: Bloom
Author URI: https://bloomdigitalmarketing.uk/
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
	exit;
}

define('STORA_PLUGIN_VERSION', '1.1.0');
define('STORA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('STORA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once STORA_PLUGIN_PATH . 'config.php';

require_once STORA_PLUGIN_PATH . 'includes/class-routes.php';

register_activation_hook(__FILE__, static function () {
	require_once STORA_PLUGIN_PATH . 'includes/class-routes.php';
	Stora_Routes::activation_flush();
});

register_deactivation_hook(__FILE__, static function () {
	flush_rewrite_rules();
});

add_action('plugins_loaded', static function () {
	$stored = get_option('stora_plugin_version', '');
	if ($stored === STORA_PLUGIN_VERSION) {
		return;
	}

	update_option('stora_plugin_version', STORA_PLUGIN_VERSION);

	if (class_exists('Stora_Routes')) {
		Stora_Routes::activation_flush();
	}
	if (class_exists('Stora_Cache')) {
		Stora_Cache::clear_all();
	}
}, 5);

require_once STORA_PLUGIN_PATH . 'includes/class-cache.php';
require_once STORA_PLUGIN_PATH . 'includes/class-storefront-prices.php';
require_once STORA_PLUGIN_PATH . 'includes/class-stora-api.php';
require_once STORA_PLUGIN_PATH . 'includes/class-units.php';
require_once STORA_PLUGIN_PATH . 'includes/class-locations.php';
require_once STORA_PLUGIN_PATH . 'includes/class-checkout.php';
require_once STORA_PLUGIN_PATH . 'includes/class-booking.php';
require_once STORA_PLUGIN_PATH . 'includes/class-webhook.php';
require_once STORA_PLUGIN_PATH . 'includes/class-admin-settings.php';

/**
 * Format a UK phone number for display (e.g. 01925 989 540).
 */
function stora_format_display_phone($phone) {
	$phone = trim((string) $phone);
	if ($phone === '') {
		return '';
	}

	$digits = preg_replace('/\D+/', '', $phone);
	if ($digits === '') {
		return $phone;
	}

	if (strlen($digits) === 12 && substr($digits, 0, 2) === '44') {
		$digits = '0' . substr($digits, 2);
	}

	if (strlen($digits) !== 11 || $digits[0] !== '0') {
		return $phone;
	}

	if ($digits[1] === '7') {
		return substr($digits, 0, 5) . ' ' . substr($digits, 5);
	}

	if ($digits[1] === '2') {
		return substr($digits, 0, 3) . ' ' . substr($digits, 3, 4) . ' ' . substr($digits, 7);
	}

	if ($digits[1] === '1') {
		$area_len = 5;
		if (($digits[2] === '1' && $digits[3] === '1') || ($digits[3] === '1' && $digits[2] >= '2' && $digits[2] <= '9')) {
			$area_len = 4;
		}

		$local = substr($digits, $area_len);
		if (strlen($local) === 6) {
			return substr($digits, 0, $area_len) . ' ' . substr($local, 0, 3) . ' ' . substr($local, 3);
		}
	}

	return $phone;
}

/**
 * Title-case text for plugin headings (capitalise each word; hyphenated parts too).
 */
function stora_heading_case($text) {
	$text = trim((string) $text);
	if ($text === '') {
		return '';
	}

	$lower = mb_strtolower($text, 'UTF-8');

	return preg_replace_callback(
		'/[^\s-]+/u',
		static function ($matches) {
			$word = $matches[0];
			if (strpos($word, '-') !== false) {
				$parts = explode('-', $word);
				$parts = array_map(static function ($part) {
					if ($part === '') {
						return $part;
					}
					return mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($part, 1, null, 'UTF-8');
				}, $parts);
				return implode('-', $parts);
			}
			return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
		},
		$lower
	);
}

/**
 * Lowercase size units in a unit-type name for display, e.g.
 * "50 SQ FT" -> "50 sq ft" and "20FT" / "20 FT" -> "20ft" / "20 ft".
 * Spacing is preserved.
 */
function stora_format_unit_size_label($text) {
	$text = (string) $text;
	if ($text === '') {
		return '';
	}

	// "SQ FT", "SQ. FT", "SQFT" -> "sq ft".
	$text = preg_replace('/\bsq\.?\s*ft\b/i', 'sq ft', $text);

	// A number followed by "FT" -> lowercase "ft", keeping any spacing.
	$text = preg_replace_callback('/(\d)(\s*)ft\b/i', static function ($m) {
		return $m[1] . $m[2] . 'ft';
	}, $text);

	return $text;
}

/**
 * Convenience: if a page is visited with ?stora_site_id=... (or ?stora_site=...),
 * automatically render the units list even if the editor content doesn't include the shortcode.
 *
 * This lets you create simple landing pages like /units-testing/ and drive filtering purely via URL.
 */
add_filter('the_content', function ($content) {
	if (!is_string($content)) {
		return $content;
	}
	if (is_admin()) {
		return $content;
	}
	if (!function_exists('is_singular') || !is_singular('page')) {
		return $content;
	}
	if (!function_exists('in_the_loop') || !in_the_loop()) {
		return $content;
	}
	if (!function_exists('is_main_query') || !is_main_query()) {
		return $content;
	}

	$has_site = isset($_GET['stora_site_id']) || isset($_GET['stora_site']);
	$has_location = class_exists('Stora_Routes') && Stora_Routes::current_location_slug() !== '';
	if (!$has_site && !$has_location) {
		return $content;
	}

	if ($has_location && class_exists('Stora_Routes') && Stora_Routes::is_on_units_page()) {
		return Stora_Routes::append_units_shortcode_to_content($content);
	}

	if (!function_exists('in_the_loop') || !in_the_loop() || !function_exists('is_main_query') || !is_main_query()) {
		return $content;
	}

	if (function_exists('has_shortcode') && (has_shortcode($content, 'stora_units') || has_shortcode($content, 'stora_units_warrington_latchford') || has_shortcode($content, 'stora_units_warrington_central') || has_shortcode($content, 'stora_units_winsford') || has_shortcode($content, 'stora_units_arley'))) {
		return $content;
	}

	if (function_exists('do_shortcode')) {
		$content .= "\n\n" . do_shortcode('[stora_units]');
	}

	return $content;
}, 20);

function stora_should_enqueue_assets(): bool {
	if (is_admin()) {
		return false;
	}

	if (isset($_GET['stora_site_id']) || isset($_GET['stora_site']) || isset($_GET['stora_location'])) {
		return true;
	}

	if (class_exists('Stora_Routes')) {
		if (Stora_Routes::is_location_units_request() || Stora_Routes::is_unit_detail_request() || Stora_Routes::is_checkout_request()) {
			return true;
		}
	}

	global $post;
	if (!$post instanceof WP_Post) {
		return false;
	}

	foreach (['stora_units', 'stora_units_winsford', 'stora_units_warrington_latchford', 'stora_units_warrington_central', 'stora_units_arley', 'stora_locations', 'stora_checkout'] as $shortcode) {
		if (function_exists('has_shortcode') && has_shortcode($post->post_content, $shortcode)) {
			return true;
		}
	}

	return false;
}

function stora_is_checkout_view(): bool {
	if (class_exists('Stora_Routes') && Stora_Routes::is_checkout_request()) {
		return true;
	}

	global $post;
	if ($post instanceof WP_Post && function_exists('has_shortcode') && has_shortcode($post->post_content, 'stora_checkout')) {
		return true;
	}

	return false;
}

add_action('wp_enqueue_scripts', function () {
	if (!stora_should_enqueue_assets()) {
		return;
	}

	wp_enqueue_style(
		'stora-font-awesome',
		STORA_PLUGIN_URL . 'assets/vendor/fontawesome/css/all.min.css',
		[],
		'6.5.2'
	);

	wp_enqueue_style(
		'stora-rokkitt',
		'https://fonts.googleapis.com/css2?family=Rokkitt:wght@400;700;800&display=swap',
		[],
		null
	);

	wp_enqueue_style(
		'stora-booking',
		STORA_PLUGIN_URL . 'assets/css/stora.css',
		['stora-font-awesome', 'stora-rokkitt'],
		file_exists(STORA_PLUGIN_PATH . 'assets/css/stora.css') ? (string) filemtime(STORA_PLUGIN_PATH . 'assets/css/stora.css') : STORA_PLUGIN_VERSION
	);

	$script_deps = [];

	// International phone selector (flag + dial code) is only needed on the checkout form.
	if (stora_is_checkout_view()) {
		wp_enqueue_style(
			'stora-intl-tel-input',
			'https://cdn.jsdelivr.net/npm/intl-tel-input@17.0.19/build/css/intlTelInput.min.css',
			[],
			'17.0.19'
		);

		wp_enqueue_script(
			'stora-intl-tel-input',
			'https://cdn.jsdelivr.net/npm/intl-tel-input@17.0.19/build/js/intlTelInput.min.js',
			[],
			'17.0.19',
			true
		);

		$script_deps[] = 'stora-intl-tel-input';
	}

	wp_enqueue_script(
		'stora-booking',
		STORA_PLUGIN_URL . 'assets/js/stora.js',
		$script_deps,
		file_exists(STORA_PLUGIN_PATH . 'assets/js/stora.js') ? (string) filemtime(STORA_PLUGIN_PATH . 'assets/js/stora.js') : STORA_PLUGIN_VERSION,
		true
	);
});