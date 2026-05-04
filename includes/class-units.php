<?php

class Stora_Units {

	public function __construct(){

		add_shortcode('stora_units',[$this,'render']);
		add_shortcode('stora_units_winsford', [$this, 'render_winsford']);
		add_shortcode('stora_units_warrington', [$this, 'render_warrington']);
		add_shortcode('stora_units_warrington_latchford', [$this, 'render_warrington_latchford']);
		add_shortcode('stora_units_warrington_central', [$this, 'render_warrington_central']);
		add_shortcode('stora_units_arley', [$this, 'render_arley']);
		add_shortcode('stora_units_indoor', [$this, 'render_indoor']);
		add_shortcode('stora_units_container', [$this, 'render_container']);
		add_shortcode('stora_sites', [$this, 'render_sites']);

	}

	public function render_winsford($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		$atts['site'] = 'Winsford';
		return $this->render($atts);
	}

	public function render_warrington($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		// Legacy alias: same as Warrington Latchford (see config STORA_SITE_LABELS).
		$atts['site'] = 'Warrington Latchford';
		return $this->render($atts);
	}

	public function render_warrington_latchford($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		$atts['site'] = 'Warrington Latchford';
		return $this->render($atts);
	}

	public function render_warrington_central($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		$atts['site'] = 'Warrington Central';
		return $this->render($atts);
	}

	public function render_arley($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		// Alias: keep shortcode name but show Warrington Central.
		$atts['site'] = 'Warrington Central';
		return $this->render($atts);
	}

	public function render_indoor($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		$atts['category'] = 'indoor';
		return $this->render($atts);
	}

	public function render_container($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		$atts['category'] = 'container';
		return $this->render($atts);
	}

	public function render($atts = []){

		$atts = shortcode_atts(
			[
				'debug' => '0',
				'dump' => '0',
				'dump_limit' => '2',
				// Optional: filter to a single site label (e.g. Winsford / Warrington).
				'site' => '',
				// Optional: filter to a category (indoor|container).
				'category' => '',
				// Which unit statuses to display. Default matches current behavior.
				// - "available" (default): only units ready to book
				// - "all": show all non-hidden units regardless of status
				'status' => 'available',
				// Optional: show a unit-type filter derived from Stora for the current site.
				// Use with `site="..."`. Example: [stora_units site="Arley" unit_type_filter="1"]
				'unit_type_filter' => '0',
			],
			$atts,
			'stora_units'
		);

		$debug_enabled = is_user_logged_in()
			&& current_user_can('manage_options')
			&& (
				(defined('WP_DEBUG') && WP_DEBUG)
				|| $atts['debug'] === '1'
				|| (isset($_GET['stora_debug']) && (string) $_GET['stora_debug'] === '1')
			);

		$api = new Stora_API();

		$units = $api->get_units();
		$stora_site_filter = is_string($atts['site']) ? trim($atts['site']) : '';
		$stora_category_filter = is_string($atts['category']) ? strtolower(trim($atts['category'])) : '';
		$stora_status_filter = is_string($atts['status']) ? strtolower(trim($atts['status'])) : 'available';
		$stora_unit_type_filter = (string) ($atts['unit_type_filter'] ?? '0');
		$stora_debug = null;
		$stora_dump = null;
		if ($debug_enabled) {
			$stora_debug = $api->get_last_debug();
			if ($atts['dump'] === '1') {
				$limit = max(1, (int) $atts['dump_limit']);
				if (is_array($units) && isset($units['data']) && is_array($units['data'])) {
					$stora_dump = [
						'data_sample' => array_slice($units['data'], 0, $limit),
						'data_count' => count($units['data']),
					];
				} else {
					$stora_dump = $units;
				}
			}
		}

		ob_start();

		include STORA_PLUGIN_PATH.'templates/units.php';

		return ob_get_clean();
	}

	public function render_sites($atts = []) {
		$atts = shortcode_atts(
			[
				'debug' => '0',
				'dump' => '0',
			],
			is_array($atts) ? $atts : [],
			'stora_sites'
		);

		$debug_enabled = is_user_logged_in()
			&& current_user_can('manage_options')
			&& (
				(defined('WP_DEBUG') && WP_DEBUG)
				|| $atts['debug'] === '1'
				|| (isset($_GET['stora_debug']) && (string) $_GET['stora_debug'] === '1')
			);

		if (!$debug_enabled) {
			return '';
		}

		$api = new Stora_API();
		$units = $api->get_units();

		$sites = [];
		if (is_array($units) && isset($units['data']) && is_array($units['data'])) {
			foreach ($units['data'] as $u) {
				if (!is_array($u)) {
					continue;
				}
				$site = $u['site'] ?? null;
				if (!is_array($site)) {
					continue;
				}
				$site_id = isset($site['id']) ? (string) $site['id'] : '';
				if ($site_id === '') {
					continue;
				}

				if (!isset($sites[$site_id])) {
					$sites[$site_id] = [
						'id' => $site_id,
						'name' => isset($site['name']) ? (string) $site['name'] : '',
						'address' => isset($site['address']) && is_array($site['address']) ? $site['address'] : null,
						'units' => 0,
						'raw_site_keys' => array_keys($site),
					];
				}
				$sites[$site_id]['units']++;
			}
		}

		ksort($sites);

		$out = [
			'api_debug' => $api->get_last_debug(),
			'sites_count' => count($sites),
			'sites' => array_values($sites),
		];

		if ($atts['dump'] === '1') {
			$out['units_payload_sample'] = is_array($units) && isset($units['data']) && is_array($units['data'])
				? array_slice($units['data'], 0, 1)
				: $units;
		}

		return '<pre style="white-space:pre-wrap;max-width:100%;overflow:auto;">'
			. esc_html(wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
			. '</pre>';
	}

}

new Stora_Units();