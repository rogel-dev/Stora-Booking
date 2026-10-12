<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * SEO-friendly unit listing URLs routed through the existing /units/ WordPress page.
 *
 * Example: /storage-units/warrington-latchford/ → /units/ page + stora_location query var.
 * This keeps the theme header, footer, and layout identical to other pages.
 */
class Stora_Routes {

	private const ROUTES_VERSION = '2';

	public static function init(): void {
		add_action('init', [self::class, 'register_rewrites']);
		add_filter('query_vars', [self::class, 'register_query_vars']);
		add_action('init', [self::class, 'maybe_flush_rewrites'], 20);
		add_action('parse_request', [self::class, 'parse_request']);
		add_action('template_redirect', [self::class, 'handle_legacy_redirects'], 1);
		add_action('template_redirect', [self::class, 'ensure_units_shortcode'], 2);
		add_action('template_redirect', [self::class, 'validate_location_slug'], 5);
		add_action('template_redirect', [self::class, 'send_checkout_headers'], 1);
		add_action('wp', [self::class, 'maybe_set_units_title']);
		add_filter('wp_robots', [self::class, 'checkout_robots']);
		add_action('wp_head', [self::class, 'units_canonical_link'], 1);
	}

	public static function register_query_vars(array $vars): array {
		$vars[] = 'stora_location';
		$vars[] = 'stora_category';
		$vars[] = 'stora_unit';
		return $vars;
	}

	public static function units_base_path(): string {
		$path = defined('STORA_UNITS_BASE_PATH') ? (string) STORA_UNITS_BASE_PATH : 'storage-units';
		return trim($path, '/');
	}

	public static function units_page_slug(): string {
		$slug = defined('STORA_UNITS_PAGE_SLUG') ? (string) STORA_UNITS_PAGE_SLUG : 'units';
		return trim($slug, '/');
	}

	public static function units_page_url(): string {
		$slug = self::units_page_slug();
		if ($slug === '') {
			return trailingslashit(home_url('/'));
		}

		if (function_exists('get_page_by_path')) {
			$page = get_page_by_path($slug);
			if ($page instanceof WP_Post && function_exists('get_permalink')) {
				$permalink = get_permalink($page);
				if (is_string($permalink) && $permalink !== '') {
					return $permalink;
				}
			}
		}

		return trailingslashit(home_url('/' . $slug));
	}

	public static function checkout_path(): string {
		$path = defined('STORA_CHECKOUT_PATH') ? (string) STORA_CHECKOUT_PATH : 'checkout';
		return trim($path, '/');
	}

	/** @return array<string, string> */
	public static function site_slugs(): array {
		if (!isset($GLOBALS['STORA_SITE_SLUGS']) || !is_array($GLOBALS['STORA_SITE_SLUGS'])) {
			return [];
		}

		$out = [];
		foreach ($GLOBALS['STORA_SITE_SLUGS'] as $site_id => $slug) {
			$site_id = (string) $site_id;
			$slug = sanitize_title((string) $slug);
			if ($site_id !== '' && $slug !== '') {
				$out[$site_id] = $slug;
			}
		}

		return $out;
	}

	public static function slug_for_site_id(string $site_id): string {
		$slugs = self::site_slugs();
		return isset($slugs[$site_id]) ? (string) $slugs[$site_id] : '';
	}

	public static function site_id_from_slug(string $slug): string {
		$slug = sanitize_title($slug);
		if ($slug === '') {
			return '';
		}

		foreach (self::site_slugs() as $site_id => $configured_slug) {
			if (sanitize_title((string) $configured_slug) === $slug) {
				return (string) $site_id;
			}
		}

		return '';
	}

	public static function site_label_from_slug(string $slug): string {
		$site_id = self::site_id_from_slug($slug);
		if ($site_id === '') {
			return '';
		}

		$labels = isset($GLOBALS['STORA_SITE_LABELS']) && is_array($GLOBALS['STORA_SITE_LABELS'])
			? $GLOBALS['STORA_SITE_LABELS']
			: [];

		return isset($labels[$site_id]) ? (string) $labels[$site_id] : '';
	}

	public static function slug_from_label(string $label): string {
		$label = trim($label);
		if ($label === '') {
			return '';
		}

		$labels = isset($GLOBALS['STORA_SITE_LABELS']) && is_array($GLOBALS['STORA_SITE_LABELS'])
			? $GLOBALS['STORA_SITE_LABELS']
			: [];

		foreach ($labels as $site_id => $configured_label) {
			if (strcasecmp((string) $configured_label, $label) === 0) {
				return self::slug_for_site_id((string) $site_id);
			}
		}

		return '';
	}

	public static function resolve_slug($site): string {
		$site = trim((string) $site);
		if ($site === '') {
			return '';
		}

		if (strpos($site, 'site_') === 0) {
			return self::slug_for_site_id($site);
		}

		if (self::site_id_from_slug($site) !== '') {
			return sanitize_title($site);
		}

		return self::slug_from_label($site);
	}

	public static function uses_pretty_permalinks(): bool {
		if (!function_exists('get_option')) {
			return true;
		}

		$structure = get_option('permalink_structure', '');
		return is_string($structure) && $structure !== '';
	}

	public static function request_path(): string {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		$path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');
		if ($path === '') {
			return '';
		}

		$home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
		if ($home_path !== '') {
			if ($path === $home_path) {
				return '';
			}
			if (strpos($path, $home_path . '/') === 0) {
				$path = substr($path, strlen($home_path) + 1);
			}
		}

		return trim($path, '/');
	}

	/** @return array{slug: string, category: string, unit: string} */
	public static function parse_path_segments(): array {
		$path = self::request_path();
		$base = self::units_base_path();
		if ($base === '' || $path === '' || strpos($path, $base . '/') !== 0) {
			return ['slug' => '', 'category' => '', 'unit' => ''];
		}

		$remainder = trim(substr($path, strlen($base) + 1), '/');
		if ($remainder === '') {
			return ['slug' => '', 'category' => '', 'unit' => ''];
		}

		$parts = explode('/', $remainder);
		$location = sanitize_title((string) ($parts[0] ?? ''));
		$seg2 = isset($parts[1]) ? sanitize_title((string) $parts[1]) : '';
		$seg3 = isset($parts[2]) ? sanitize_title((string) $parts[2]) : '';

		return self::classify_path_segments($location, $seg2, $seg3);
	}

	/**
	 * Resolve whether URL segments are a storage category and/or unit type slug.
	 *
	 * @return array{slug: string, category: string, unit: string}
	 */
	public static function classify_path_segments(string $location, string $seg2, string $seg3): array {
		$empty = ['slug' => '', 'category' => '', 'unit' => ''];
		if ($location === '') {
			return $empty;
		}

		if ($seg2 === '') {
			return ['slug' => $location, 'category' => '', 'unit' => ''];
		}

		if ($seg3 !== '') {
			return [
				'slug' => $location,
				'category' => $seg2,
				'unit' => $seg3,
			];
		}

		$site_id = self::site_id_from_slug($location);
		if ($site_id !== '' && class_exists('Stora_Units') && Stora_Units::is_storage_category_slug($site_id, $seg2)) {
			return ['slug' => $location, 'category' => $seg2, 'unit' => ''];
		}

		return ['slug' => $location, 'category' => '', 'unit' => $seg2];
	}

	/** @param array{slug: string, category: string, unit: string} $parsed */
	public static function sync_location_query_vars(array $parsed): void {
		if ($parsed['slug'] === '') {
			return;
		}

		global $wp_query;
		if ($wp_query instanceof WP_Query) {
			$wp_query->set('stora_location', $parsed['slug']);
			$wp_query->set('stora_category', $parsed['category'] !== '' ? $parsed['category'] : '');
			$wp_query->set('stora_unit', $parsed['unit'] !== '' ? $parsed['unit'] : '');
		}
	}

	/** @return array{slug: string, category: string, unit: string} */
	public static function resolve_location_request(): array {
		$from_path = self::parse_path_segments();
		if ($from_path['slug'] !== '') {
			self::sync_location_query_vars($from_path);
			return $from_path;
		}

		$slug = get_query_var('stora_location');
		$category = get_query_var('stora_category');
		$unit = get_query_var('stora_unit');
		if (is_string($slug) && $slug !== '') {
			$parsed = [
				'slug' => sanitize_title($slug),
				'category' => is_string($category) && $category !== '' ? sanitize_text_field($category) : '',
				'unit' => is_string($unit) && $unit !== '' ? sanitize_title($unit) : '',
			];

			if ($parsed['unit'] === '' && $parsed['category'] !== '') {
				$site_id = self::site_id_from_slug($parsed['slug']);
				if (
					$site_id !== ''
					&& class_exists('Stora_Units')
					&& !Stora_Units::is_storage_category_slug($site_id, $parsed['category'])
				) {
					$parsed['unit'] = sanitize_title($parsed['category']);
					$parsed['category'] = '';
				}
			}

			self::sync_location_query_vars($parsed);
			return $parsed;
		}

		if (isset($_GET['stora_location'])) {
			$slug = sanitize_title((string) wp_unslash($_GET['stora_location']));
			$category = isset($_GET['stora_category'])
				? sanitize_text_field((string) wp_unslash($_GET['stora_category']))
				: '';
			$unit = isset($_GET['stora_unit'])
				? sanitize_title((string) wp_unslash($_GET['stora_unit']))
				: '';
			if ($slug !== '') {
				if ($unit !== '') {
					$parsed = [
						'slug' => $slug,
						'category' => $category !== '' ? sanitize_title($category) : '',
						'unit' => $unit,
					];
				} elseif ($category !== '') {
					$parsed = self::classify_path_segments($slug, sanitize_title($category), '');
				} else {
					$parsed = ['slug' => $slug, 'category' => '', 'unit' => ''];
				}
				self::sync_location_query_vars($parsed);
				return $parsed;
			}
		}

		return ['slug' => '', 'category' => '', 'unit' => ''];
	}

	public static function current_location_slug(): string {
		return self::resolve_location_request()['slug'];
	}

	public static function current_category_slug(): string {
		return self::resolve_location_request()['category'];
	}

	public static function current_unit_slug(): string {
		return self::resolve_location_request()['unit'];
	}

	public static function is_unit_detail_request(): bool {
		return self::current_unit_slug() !== '';
	}

	public static function is_location_units_request(): bool {
		return self::current_location_slug() !== '';
	}

	public static function is_on_units_page(): bool {
		if (self::parse_path_segments()['slug'] !== '') {
			return true;
		}

		if (!function_exists('is_page')) {
			return false;
		}

		$page_slug = self::units_page_slug();
		return $page_slug !== '' && is_page($page_slug);
	}

	public static function unit_url($site, $unit_type, $category = ''): string {
		$location_slug = self::resolve_slug($site);
		$unit_slug = class_exists('Stora_Units') ? Stora_Units::unit_type_slug($unit_type) : '';
		$category = sanitize_title((string) $category);

		if ($location_slug === '' || $unit_slug === '') {
			return self::units_url($site, $category);
		}

		if (!self::uses_pretty_permalinks()) {
			$args = [
				'stora_location' => $location_slug,
				'stora_unit' => $unit_slug,
			];
			if ($category !== '') {
				$args['stora_category'] = $category;
			}

			return add_query_arg($args, self::units_page_url());
		}

		$path = self::units_base_path() . '/' . $location_slug;
		if ($category !== '') {
			$path .= '/' . $category;
		}
		$path .= '/' . $unit_slug;

		return trailingslashit(home_url('/' . $path));
	}

	public static function units_url($site = '', $category = ''): string {
		$slug = self::resolve_slug($site);
		$category = sanitize_title((string) $category);

		if (!self::uses_pretty_permalinks()) {
			$args = [];
			if ($slug !== '') {
				$args['stora_location'] = $slug;
			}
			if ($category !== '') {
				$args['stora_category'] = $category;
			}

			return !empty($args) ? add_query_arg($args, self::units_page_url()) : self::units_page_url();
		}

		$path = self::units_base_path();
		if ($slug !== '') {
			$path .= '/' . $slug;
		}
		if ($category !== '') {
			$path .= '/' . $category;
		}

		return trailingslashit(home_url('/' . $path));
	}

	public static function checkout_url(string $unit_id): string {
		$unit_id = trim($unit_id);
		$base = trailingslashit(home_url('/' . self::checkout_path()));
		if ($unit_id === '') {
			return $base;
		}

		return add_query_arg('unit_id', $unit_id, $base);
	}

	public static function parse_request($wp): void {
		if (!($wp instanceof WP)) {
			return;
		}

		$parsed = self::parse_path_segments();
		if ($parsed['slug'] === '') {
			return;
		}

		$page_slug = self::units_page_slug();
		if ($page_slug === '') {
			return;
		}

		$wp->query_vars['pagename'] = $page_slug;
		$wp->query_vars['stora_location'] = $parsed['slug'];
		$wp->query_vars['stora_category'] = $parsed['category'] !== '' ? $parsed['category'] : '';
		$wp->query_vars['stora_unit'] = $parsed['unit'] !== '' ? $parsed['unit'] : '';
	}

	public static function register_rewrites(): void {
		$page_slug = self::units_page_slug();
		$base = preg_quote(self::units_base_path(), '/');
		if ($page_slug === '' || $base === '') {
			return;
		}

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/([^/]+)/([^/]+)/?$',
			'index.php?pagename=' . $page_slug . '&stora_location=$matches[1]&stora_category=$matches[2]&stora_unit=$matches[3]',
			'top'
		);

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/([^/]+)/?$',
			'index.php?pagename=' . $page_slug . '&stora_location=$matches[1]&stora_category=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^' . $base . '/([^/]+)/?$',
			'index.php?pagename=' . $page_slug . '&stora_location=$matches[1]',
			'top'
		);
	}

	public static function maybe_flush_rewrites(): void {
		if (!function_exists('get_option') || !function_exists('update_option')) {
			return;
		}

		if (get_option('stora_routes_version', '') === self::ROUTES_VERSION) {
			return;
		}

		self::register_rewrites();
		flush_rewrite_rules(false);
		update_option('stora_routes_version', self::ROUTES_VERSION);
	}

	public static function activation_flush(): void {
		self::register_rewrites();
		flush_rewrite_rules();
		if (function_exists('update_option')) {
			update_option('stora_routes_version', self::ROUTES_VERSION);
		}
	}

	public static function handle_legacy_redirects(): void {
		if (is_admin()) {
			return;
		}

		$site_id = isset($_GET['stora_site_id'])
			? trim(sanitize_text_field((string) wp_unslash($_GET['stora_site_id'])))
			: '';
		$site_label = isset($_GET['stora_site'])
			? trim(sanitize_text_field((string) wp_unslash($_GET['stora_site'])))
			: '';
		$category = isset($_GET['stora_category'])
			? sanitize_text_field((string) wp_unslash($_GET['stora_category']))
			: '';

		if ($site_id === '' && $site_label === '') {
			return;
		}

		$slug = $site_id !== '' ? self::slug_for_site_id($site_id) : self::slug_from_label($site_label);
		if ($slug === '') {
			return;
		}

		$target = self::units_url($slug, $category);
		$current = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
		if (trailingslashit($target) === trailingslashit($current)) {
			return;
		}

		wp_safe_redirect($target, 301);
		exit;
	}

	public static function ensure_units_shortcode(): void {
		if (is_admin() || !self::is_on_units_page()) {
			return;
		}

		if (self::current_location_slug() === '' && !isset($_GET['stora_site_id']) && !isset($_GET['stora_site'])) {
			return;
		}

		add_filter('the_content', [self::class, 'append_units_shortcode_to_content'], 5);
	}

	public static function append_units_shortcode_to_content(string $content): string {
		static $done = false;
		if ($done) {
			return $content;
		}

		$shortcodes = [
			'stora_units',
			'stora_units_winsford',
			'stora_units_warrington_latchford',
			'stora_units_warrington_central',
			'stora_units_arley',
		];

		if (function_exists('has_shortcode')) {
			foreach ($shortcodes as $shortcode) {
				if (has_shortcode($content, $shortcode)) {
					$done = true;
					return $content;
				}
			}
		}

		if (!function_exists('do_shortcode')) {
			return $content;
		}

		$done = true;
		return $content . "\n\n" . do_shortcode('[stora_units]');
	}

	public static function validate_location_slug(): void {
		if (!self::is_on_units_page()) {
			return;
		}

		$slug = self::current_location_slug();
		if ($slug === '') {
			return;
		}

		$site_id = self::site_id_from_slug($slug);
		if ($site_id === '') {
			global $wp_query;
			if ($wp_query instanceof WP_Query) {
				$wp_query->set_404();
			}
			status_header(404);
			nocache_headers();
			return;
		}

		$unit_slug = self::current_unit_slug();
		if ($unit_slug === '' || !class_exists('Stora_Units')) {
			return;
		}

		$api = new Stora_API();
		if (Stora_Units::find_unit_type_display_by_slug($site_id, $unit_slug, $api) !== null) {
			return;
		}

		global $wp_query;
		if ($wp_query instanceof WP_Query) {
			$wp_query->set_404();
		}
		status_header(404);
		nocache_headers();
	}

	public static function maybe_set_units_title(): void {
		$slug = self::current_location_slug();
		if ($slug === '' || !self::is_on_units_page()) {
			return;
		}

		$label = self::site_label_from_slug($slug);
		$unit_slug = self::current_unit_slug();

		add_filter('pre_get_document_title', static function () use ($label, $slug, $unit_slug) {
			if ($unit_slug !== '' && class_exists('Stora_Units')) {
				$site_id = Stora_Routes::site_id_from_slug($slug);
				if ($site_id !== '') {
					$api = new Stora_API();
					$display = Stora_Units::find_unit_type_display_by_slug($site_id, $unit_slug, $api);
					if (is_array($display)) {
						$unit_type = isset($display['unit_type']) && is_array($display['unit_type']) ? $display['unit_type'] : [];
						$name = trim((string) ($unit_type['name'] ?? ''));
						if ($name !== '') {
							$site_name = $label !== '' ? $label : 'Self Storage';
							return $name . ' — ' . $site_name;
						}
					}
				}
			}

			$pages = isset($GLOBALS['STORA_SITE_PAGES']) && is_array($GLOBALS['STORA_SITE_PAGES'])
				? $GLOBALS['STORA_SITE_PAGES']
				: [];
			if ($label !== '' && isset($pages[$label]['page_title'])) {
				return (string) $pages[$label]['page_title'];
			}
			return $label !== '' ? ('Self Storage in ' . $label) : 'Storage Units';
		});
	}

	public static function is_checkout_request(): bool {
		if (is_admin() || !isset($_GET['unit_id']) || trim((string) wp_unslash($_GET['unit_id'])) === '') {
			return false;
		}

		global $post;
		if ($post instanceof WP_Post && function_exists('has_shortcode') && has_shortcode($post->post_content, 'stora_checkout')) {
			return true;
		}

		$checkout_path = self::checkout_path();
		if ($checkout_path === '') {
			return false;
		}

		return self::request_path() === $checkout_path;
	}

	public static function send_checkout_headers(): void {
		if (!self::is_checkout_request()) {
			return;
		}

		nocache_headers();

		if (!headers_sent()) {
			header('X-Frame-Options: SAMEORIGIN');
			header('X-Content-Type-Options: nosniff');
			header('Referrer-Policy: strict-origin-when-cross-origin');
		}
	}

	/** @param array<string, bool|string> $robots */
	public static function checkout_robots(array $robots): array {
		if (!self::is_checkout_request()) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['nofollow'] = true;
		return $robots;
	}

	public static function units_canonical_link(): void {
		if (!self::is_location_units_request()) {
			return;
		}

		if (self::is_unit_detail_request()) {
			$site_id = self::site_id_from_slug(self::current_location_slug());
			if ($site_id === '' || !class_exists('Stora_Units')) {
				return;
			}

			$api = new Stora_API();
			$display = Stora_Units::find_unit_type_display_by_slug($site_id, self::current_unit_slug(), $api);
			if (!is_array($display)) {
				return;
			}

			$unit_type = isset($display['unit_type']) && is_array($display['unit_type']) ? $display['unit_type'] : [];
			$url = esc_url(self::unit_url($site_id, $unit_type, self::current_category_slug()));
			echo '<link rel="canonical" href="' . $url . '" />' . "\n";
			return;
		}

		$url = esc_url(self::units_url(self::current_location_slug(), self::current_category_slug()));
		echo '<link rel="canonical" href="' . $url . '" />' . "\n";
	}
}

Stora_Routes::init();
