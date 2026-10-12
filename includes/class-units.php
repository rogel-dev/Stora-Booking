<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Units {

	public function __construct(){

		add_shortcode('stora_units',[$this,'render']);
		add_shortcode('stora_units_winsford', [$this, 'render_winsford']);
		add_shortcode('stora_units_warrington_latchford', [$this, 'render_warrington_latchford']);
		add_shortcode('stora_units_warrington_central', [$this, 'render_warrington_central']);
		add_shortcode('stora_units_arley', [$this, 'render_arley']);

	}

	public function render_winsford($atts = []) {
		$atts = is_array($atts) ? $atts : [];
		$atts['site'] = 'Winsford';
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
		$atts['site'] = 'Arley';
		return $this->render($atts);
	}

	/**
	 * Group site units by unit type (one card per type).
	 *
	 * @param array<int, array<string,mixed>> $site_units
	 * @return array<string, array<string,mixed>>
	 */
	public static function build_unit_types_group($site_units) {
		$types = [];
		foreach ($site_units as $u) {
			if (!is_array($u)) {
				continue;
			}
			$ut = isset($u['unit_type']) && is_array($u['unit_type']) ? $u['unit_type'] : [];
			$utid = isset($ut['id']) ? (string) $ut['id'] : '';
			if ($utid === '') {
				continue;
			}
			if (!isset($types[$utid])) {
				$types[$utid] = [
					'unit_type' => $ut,
					'units' => [],
					'available_unit_id' => '',
					'available_count' => 0,
					'total_count' => 0,
				];
			}
			$types[$utid]['units'][] = $u;
			$types[$utid]['total_count']++;
			$st = strtolower((string) ($u['status'] ?? ''));
			if ($st === 'available') {
				$types[$utid]['available_count']++;
				if ($types[$utid]['available_unit_id'] === '' && !empty($u['id'])) {
					$types[$utid]['available_unit_id'] = (string) $u['id'];
				}
			}
		}

		uasort($types, static function ($a, $b) {
			$an = is_array($a) && isset($a['unit_type']['name']) ? (string) $a['unit_type']['name'] : '';
			$bn = is_array($b) && isset($b['unit_type']['name']) ? (string) $b['unit_type']['name'] : '';
			return strnatcasecmp($an, $bn);
		});

		return $types;
	}

	/**
	 * All unit types for a site: inventory grouped by type plus catalog types with no stock.
	 *
	 * @param array<int, array<string,mixed>> $site_units
	 * @return array<string, array<string,mixed>>
	 */
	public static function build_site_unit_types_display($site_id, $site_units, $api = null, $include_sold_out_catalog = true) {
		$types = self::build_unit_types_group($site_units);

		if (!$include_sold_out_catalog || !($api instanceof Stora_API)) {
			return $types;
		}

		$site_id = (string) $site_id;
		if ($site_id === '') {
			return $types;
		}

		foreach ($api->get_unit_types_for_site($site_id) as $catalog_ut) {
			if (!is_array($catalog_ut)) {
				continue;
			}

			$utid = (string) ($catalog_ut['id'] ?? '');
			if ($utid === '') {
				continue;
			}

			$catalog_status = strtolower((string) ($catalog_ut['status'] ?? ''));
			if ($catalog_status === 'hidden') {
				continue;
			}

			if (isset($types[$utid])) {
				$embedded = isset($types[$utid]['unit_type']) && is_array($types[$utid]['unit_type'])
					? $types[$utid]['unit_type']
					: [];
				$types[$utid]['unit_type'] = $api->resolve_canonical_unit_type_for_display($embedded, $site_id);
				continue;
			}

			$types[$utid] = [
				'unit_type' => $api->resolve_canonical_unit_type_for_display($catalog_ut, $site_id),
				'units' => [],
				'available_unit_id' => '',
				'available_count' => 0,
				'total_count' => 0,
			];
		}

		uasort($types, static function ($a, $b) {
			$an = is_array($a) && isset($a['unit_type']['name']) ? (string) $a['unit_type']['name'] : '';
			$bn = is_array($b) && isset($b['unit_type']['name']) ? (string) $b['unit_type']['name'] : '';
			return strnatcasecmp($an, $bn);
		});

		return $types;
	}

	/**
	 * Searchable text from a Stora unit type (name, slug, descriptions, selling points).
	 *
	 * @param array<string,mixed> $unit_type
	 */
	private static function unit_type_search_text($unit_type) {
		$parts = [];
		foreach (['name', 'slug', 'size_description', 'description'] as $key) {
			if (!empty($unit_type[$key]) && is_string($unit_type[$key])) {
				$parts[] = $unit_type[$key];
			}
		}
		if (!empty($unit_type['selling_points']) && is_array($unit_type['selling_points'])) {
			foreach ($unit_type['selling_points'] as $point) {
				if (is_string($point) && trim($point) !== '') {
					$parts[] = $point;
				}
			}
		}

		return strtolower(implode(' ', $parts));
	}

	/**
	 * @param array<string,mixed>      $unit_type
	 * @param array<string,mixed>      $category
	 * @param array<string,mixed>|null $storage_categories_cfg Full site config (for match_inverse_of).
	 */
	public static function unit_type_matches_storage_category($unit_type, $category, $storage_categories_cfg = null) {
		if (!is_array($unit_type) || !is_array($category)) {
			return false;
		}

		$utid = (string) ($unit_type['id'] ?? '');
		if (!empty($category['unit_type_ids']) && is_array($category['unit_type_ids'])) {
			foreach ($category['unit_type_ids'] as $id) {
				if ($utid !== '' && $utid === (string) $id) {
					return true;
				}
			}
			return false;
		}

		if (!empty($category['match_inverse_of']) && is_array($storage_categories_cfg)) {
			$inverse_id = (string) $category['match_inverse_of'];
			$inverse_cat = self::find_storage_category($storage_categories_cfg, $inverse_id);
			if ($inverse_cat) {
				return !self::unit_type_matches_storage_category($unit_type, $inverse_cat, null);
			}
		}

		$haystack = self::unit_type_search_text($unit_type);

		$exclude_names = $category['exclude_names'] ?? [];
		if (is_array($exclude_names)) {
			foreach ($exclude_names as $needle) {
				$needle = strtolower(trim((string) $needle));
				if ($needle !== '' && strpos($haystack, $needle) !== false) {
					return false;
				}
			}
		}

		$match_names = $category['match_names'] ?? $category['match'] ?? [];
		if (!is_array($match_names)) {
			$match_names = [];
		}

		if ($match_names) {
			foreach ($match_names as $needle) {
				$needle = strtolower(trim((string) $needle));
				if ($needle !== '' && strpos($haystack, $needle) !== false) {
					return true;
				}
			}
			return false;
		}

		if (is_array($exclude_names) && $exclude_names) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, array<string,mixed>> $types
	 * @param array<string,mixed>              $category
	 * @param array<string,mixed>|null         $storage_categories_cfg
	 */
	public static function storage_category_from_price($types, $category, $storage_categories_cfg = null) {
		$min = null;
		$best_unit_type = null;

		foreach ($types as $t) {
			if (!is_array($t)) {
				continue;
			}
			$ut = isset($t['unit_type']) && is_array($t['unit_type']) ? $t['unit_type'] : [];
			if (!self::unit_type_matches_storage_category($ut, $category, $storage_categories_cfg)) {
				continue;
			}
			$amount = Stora_API::resolve_unit_type_weekly_amount_minor($ut);
			if ($amount === null) {
				continue;
			}
			if ($min === null || $amount < $min) {
				$min = $amount;
				$best_unit_type = $ut;
			}
		}

		if (!is_array($best_unit_type)) {
			return '';
		}

		return Stora_API::resolve_unit_type_weekly_display_price($best_unit_type);
	}

	/**
	 * @param array<string,mixed> $storage_categories_cfg
	 * @return array<string,mixed>|null
	 */
	public static function find_storage_category($storage_categories_cfg, $category_id) {
		$category_id = (string) $category_id;
		if ($category_id === '' || !is_array($storage_categories_cfg)) {
			return null;
		}
		$categories = isset($storage_categories_cfg['categories']) && is_array($storage_categories_cfg['categories'])
			? $storage_categories_cfg['categories']
			: [];
		foreach ($categories as $cat) {
			if (!is_array($cat)) {
				continue;
			}
			if ((string) ($cat['id'] ?? '') === $category_id) {
				return $cat;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function unit_type_slug($unit_type) {
		if (!is_array($unit_type)) {
			return '';
		}

		$slug = trim((string) ($unit_type['slug'] ?? ''));
		if ($slug !== '') {
			return sanitize_title($slug);
		}

		$name = trim((string) ($unit_type['name'] ?? ''));
		return $name !== '' ? sanitize_title($name) : '';
	}

	public static function is_storage_category_slug($site_id, $segment) {
		$segment = sanitize_title((string) $segment);
		if ($segment === '') {
			return false;
		}

		$labels = isset($GLOBALS['STORA_SITE_LABELS']) && is_array($GLOBALS['STORA_SITE_LABELS'])
			? $GLOBALS['STORA_SITE_LABELS']
			: [];
		$site_label = isset($labels[(string) $site_id]) ? trim((string) $labels[(string) $site_id]) : '';
		if ($site_label === '') {
			return false;
		}

		$categories_by_site = isset($GLOBALS['STORA_SITE_STORAGE_CATEGORIES']) && is_array($GLOBALS['STORA_SITE_STORAGE_CATEGORIES'])
			? $GLOBALS['STORA_SITE_STORAGE_CATEGORIES']
			: [];

		foreach ($categories_by_site as $site_key => $cfg) {
			if (!is_array($cfg) || strcasecmp((string) $site_key, $site_label) !== 0) {
				continue;
			}
			$categories = isset($cfg['categories']) && is_array($cfg['categories']) ? $cfg['categories'] : [];
			foreach ($categories as $cat) {
				if (!is_array($cat)) {
					continue;
				}
				if (sanitize_title((string) ($cat['id'] ?? '')) === $segment) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $unit_type
	 */
	public static function resolve_size_hint($unit_type, $utid = '') {
		$size_hint = '';
		foreach (['size_hint', 'size_description', 'short_description', 'subtitle', 'tagline'] as $k) {
			if (!empty($unit_type[$k]) && is_string($unit_type[$k])) {
				$size_hint = trim((string) $unit_type[$k]);
				break;
			}
		}

		if ($size_hint !== '') {
			return $size_hint;
		}

		$hints = isset($GLOBALS['STORA_UNIT_TYPE_HINTS']) && is_array($GLOBALS['STORA_UNIT_TYPE_HINTS'])
			? $GLOBALS['STORA_UNIT_TYPE_HINTS']
			: [];
		$by_id = isset($hints['unit_type_id']) && is_array($hints['unit_type_id']) ? $hints['unit_type_id'] : [];
		$by_name = isset($hints['unit_type_name']) && is_array($hints['unit_type_name']) ? $hints['unit_type_name'] : [];
		$unit_type_name = trim((string) ($unit_type['name'] ?? ''));
		$utid = (string) $utid;

		if ($utid !== '' && isset($by_id[$utid]) && is_string($by_id[$utid]) && trim((string) $by_id[$utid]) !== '') {
			return trim((string) $by_id[$utid]);
		}
		if ($unit_type_name !== '' && isset($by_name[$unit_type_name]) && is_string($by_name[$unit_type_name]) && trim((string) $by_name[$unit_type_name]) !== '') {
			return trim((string) $by_name[$unit_type_name]);
		}

		return '';
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find_unit_type_display_by_slug($site_id, $slug, $api = null) {
		$slug = sanitize_title((string) $slug);
		$site_id = (string) $site_id;
		if ($slug === '' || $site_id === '') {
			return null;
		}

		if (!($api instanceof Stora_API)) {
			$api = new Stora_API();
		}

		$units = $api->get_units();
		$unit_items = [];
		if (is_array($units)) {
			if (isset($units['data']) && is_array($units['data'])) {
				$unit_items = $units['data'];
			} elseif (isset($units[0]) && is_array($units[0])) {
				$unit_items = $units;
			}
		}

		$site_units = array_values(array_filter($unit_items, static function ($unit) use ($site_id) {
			if (!is_array($unit)) {
				return false;
			}
			$unit_site_id = isset($unit['site']['id']) ? (string) $unit['site']['id'] : '';
			return $unit_site_id === $site_id;
		}));

		$types = self::build_site_unit_types_display($site_id, $site_units, $api, true);
		foreach ($types as $utid => $type_data) {
			if (!is_array($type_data)) {
				continue;
			}
			$unit_type = isset($type_data['unit_type']) && is_array($type_data['unit_type']) ? $type_data['unit_type'] : [];
			if (self::unit_type_slug($unit_type) === $slug) {
				return $type_data;
			}
		}

		return null;
	}

	public function render($atts = []){

		$atts = shortcode_atts(
			[
				// Optional: filter to a single site label (e.g. Winsford / Warrington).
				'site' => '',
				// Which unit statuses to include when building availability.
				// - "all" (default): show every unit type; sold-out types get a label
				// - "available": only list unit types that have at least one bookable unit
				'status' => 'all',
				// Optional: show a unit-type filter derived from Stora for the current site.
				// Example: [stora_units unit_type_filter="1"]
				'unit_type_filter' => '0',
			],
			$atts,
			'stora_units'
		);

		$api = new Stora_API();

		$units = $api->get_units();
		$stora_site_filter = is_string($atts['site']) ? trim($atts['site']) : '';
		$stora_site_id_filter = '';

		if (class_exists('Stora_Routes')) {
			$location_slug = Stora_Routes::current_location_slug();
			if ($location_slug !== '') {
				$resolved_site_id = Stora_Routes::site_id_from_slug($location_slug);
				if ($resolved_site_id !== '') {
					$stora_site_id_filter = $resolved_site_id;
					$stora_site_filter = '';
				}
			}
		}

		// Allow linking to a single page and switching site via query string.
		// Example: /units/?stora_site=Winsford or /units/?stora_site_id=site_xxx
		//
		// Important: query string should take precedence over shortcode defaults, so a single
		// /units/ page can be reused for multiple location "See prices" links.
		if (isset($_GET['stora_site_id'])) {
			$raw = (string) wp_unslash($_GET['stora_site_id']);
			$raw = urldecode($raw);
			$stora_site_id_filter = trim(sanitize_text_field($raw));
			if ($stora_site_id_filter !== '') {
				// When an id is provided, it is the most reliable selector.
				$stora_site_filter = '';
			}
		}
		if ($stora_site_id_filter === '' && isset($_GET['stora_site'])) {
			$raw = (string) wp_unslash($_GET['stora_site']);
			// Handle both normal querystrings and accidental pre-encoded values.
			$raw = urldecode($raw);
			$q_site = trim(sanitize_text_field($raw));
			if ($q_site !== '') {
				$stora_site_filter = $q_site;
			}
		}
		$stora_status_filter = is_string($atts['status']) ? strtolower(trim($atts['status'])) : 'all';
		$stora_unit_type_filter = (string) ($atts['unit_type_filter'] ?? '0');

		if (class_exists('Stora_Routes') && Stora_Routes::is_unit_detail_request()) {
			return $this->render_unit_detail($api, $stora_site_id_filter, $stora_site_filter);
		}

		ob_start();

		include STORA_PLUGIN_PATH.'templates/units.php';

		return ob_get_clean();
	}

	public function render_unit_detail($api, $stora_site_id_filter = '', $stora_site_filter = '') {
		$unit_slug = class_exists('Stora_Routes') ? Stora_Routes::current_unit_slug() : '';
		$location_slug = class_exists('Stora_Routes') ? Stora_Routes::current_location_slug() : '';
		$category_slug = class_exists('Stora_Routes') ? Stora_Routes::current_category_slug() : '';

		if ($unit_slug === '') {
			return '<p>Unit not found.</p>';
		}

		if ($stora_site_id_filter === '' && $location_slug !== '' && class_exists('Stora_Routes')) {
			$stora_site_id_filter = Stora_Routes::site_id_from_slug($location_slug);
		}

		if ($stora_site_id_filter === '') {
			return '<p>Unit not found.</p>';
		}

		$type_data = self::find_unit_type_display_by_slug($stora_site_id_filter, $unit_slug, $api);
		if (!is_array($type_data)) {
			return '<p>Unit not found.</p>';
		}

		$site_labels = isset($GLOBALS['STORA_SITE_LABELS']) && is_array($GLOBALS['STORA_SITE_LABELS']) ? $GLOBALS['STORA_SITE_LABELS'] : [];
		$single_site_label = isset($site_labels[$stora_site_id_filter]) ? (string) $site_labels[$stora_site_id_filter] : '';

		$locations_cfg = isset($GLOBALS['STORA_LOCATIONS']) && is_array($GLOBALS['STORA_LOCATIONS']) ? $GLOBALS['STORA_LOCATIONS'] : [];
		$site_location = null;
		if ($single_site_label !== '' && $locations_cfg) {
			foreach ($locations_cfg as $loc) {
				if (!is_array($loc)) {
					continue;
				}
				$loc_label = trim((string) ($loc['site_label'] ?? $loc['label'] ?? ''));
				if ($loc_label !== '' && strcasecmp($loc_label, $single_site_label) === 0) {
					$site_location = $loc;
					break;
				}
			}
		}

		ob_start();

		include STORA_PLUGIN_PATH . 'templates/unit.php';

		return ob_get_clean();
	}

}

new Stora_Units();