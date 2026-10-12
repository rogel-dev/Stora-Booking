<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Locations {
	public function __construct() {
		add_shortcode('stora_locations', [$this, 'render']);
	}

	public function render($atts = []) {
		$atts = shortcode_atts(
			[
				// Page to send users to when they click "See prices".
				// This page should contain [stora_units].
				'units_url' => '/units/',
				'title' => 'Our Locations',
			],
			is_array($atts) ? $atts : [],
			'stora_locations'
		);

		$units_url = (string) ($atts['units_url'] ?? '/units/');
		$units_url = $units_url !== '' ? $units_url : '/units/';
		$units_url = trailingslashit(home_url($units_url));

		$locations = isset($GLOBALS['STORA_LOCATIONS']) && is_array($GLOBALS['STORA_LOCATIONS'])
			? $GLOBALS['STORA_LOCATIONS']
			: [];

		$page_title = trim((string) ($atts['title'] ?? 'Our Locations'));
		$sidebar = isset($GLOBALS['STORA_LOCATIONS_SIDEBAR']) && is_array($GLOBALS['STORA_LOCATIONS_SIDEBAR'])
			? $GLOBALS['STORA_LOCATIONS_SIDEBAR']
			: [];

		ob_start();
		include STORA_PLUGIN_PATH . 'templates/locations.php';
		return ob_get_clean();
	}
}

new Stora_Locations();

