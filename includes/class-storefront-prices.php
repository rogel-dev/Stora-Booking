<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Weekly display prices from Stora's hosted storefront HTML.
 *
 * When dynamic occupancy pricing is enabled, the Public API `prices` array reflects
 * the current dynamic rate (e.g. £62.50/month) while the hosted storefront shows
 * the customer-facing weekly figure (e.g. £12.26/week inc. tax).
 */
class Stora_Storefront_Prices {

	/**
	 * @return array<string, string>
	 */
	public static function get_site_page_map() {
		return isset($GLOBALS['STORA_STOREFRONT_PRICE_PAGES']) && is_array($GLOBALS['STORA_STOREFRONT_PRICE_PAGES'])
			? $GLOBALS['STORA_STOREFRONT_PRICE_PAGES']
			: [];
	}

	public static function get_page_url_for_site($site_id) {
		$site_id = (string) $site_id;
		if ($site_id === '') {
			return '';
		}

		$map = self::get_site_page_map();
		$url = isset($map[$site_id]) ? trim((string) $map[$site_id]) : '';

		return $url !== '' ? $url : '';
	}

	/**
	 * @param array<string,mixed>|mixed $unit_type
	 * @return array{amount:int, formatted:string}|null
	 */
	public static function resolve_weekly_minor($unit_type, $site_id = '') {
		if (!is_array($unit_type)) {
			return null;
		}

		$slug = trim((string) ($unit_type['slug'] ?? ''));
		$site_id = (string) $site_id;
		if ($slug === '' || $site_id === '') {
			return null;
		}

		$page_url = self::get_page_url_for_site($site_id);
		if ($page_url === '') {
			return null;
		}

		$prices = self::get_prices_for_page($page_url);
		if (!isset($prices[$slug]) || !is_numeric($prices[$slug])) {
			return null;
		}

		$amount = (int) $prices[$slug];
		if ($amount <= 0) {
			return null;
		}

		return [
			'amount' => $amount,
			'formatted' => '',
		];
	}

	/**
	 * @return array<string, int>
	 */
	public static function get_prices_for_page($page_url) {
		$page_url = trim((string) $page_url);
		if ($page_url === '') {
			return [];
		}

		$cache_key = 'storefront_prices_' . md5($page_url);
		$cached = Stora_Cache::get($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		$response = wp_remote_get($page_url, [
			'timeout' => 15,
			'headers' => [
				'Accept' => 'text/html',
			],
		]);

		if (is_wp_error($response)) {
			return [];
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return [];
		}

		$html = wp_remote_retrieve_body($response);
		if (!is_string($html) || $html === '') {
			return [];
		}

		$parsed = self::parse_weekly_inc_prices($html, $page_url);
		if ($parsed) {
			Stora_Cache::set($cache_key, $parsed);
		}

		return $parsed;
	}

	/**
	 * Parse hosted storefront HTML for inc-tax weekly prices keyed by unit type slug.
	 *
	 * @return array<string, int> slug => amount in minor units (pence)
	 */
	public static function parse_weekly_inc_prices($html, $page_url) {
		$html = (string) $html;
		$path = (string) parse_url($page_url, PHP_URL_PATH);
		$path = rtrim($path, '/');
		if ($path === '') {
			return [];
		}

		$prices = [];
		$pattern = '#' . preg_quote($path, '#') . '/([a-z0-9\-]+)(?:/bookings/new|")#i';
		if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
			return [];
		}

		foreach ($matches[1] as $i => $slug_entry) {
			$slug = (string) ($slug_entry[0] ?? '');
			if ($slug === '' || isset($prices[$slug])) {
				continue;
			}

			$offset = (int) ($matches[0][$i][1] ?? 0);
			$chunk = substr($html, $offset, 5000);
			if (!is_string($chunk) || $chunk === '') {
				continue;
			}

			if (!preg_match('/data-tax-toggle-target="inc"[^>]*>\s*(?:&pound;|&#163;|£)\s*([0-9]+\.[0-9]{2})/', $chunk, $price_match)) {
				continue;
			}

			$major = (float) $price_match[1];
			if ($major <= 0) {
				continue;
			}

			$prices[$slug] = (int) round($major * 100);
		}

		return $prices;
	}

	public static function clear_all_caches() {
		foreach (self::get_site_page_map() as $url) {
			$url = trim((string) $url);
			if ($url === '') {
				continue;
			}
			Stora_Cache::clear('storefront_prices_' . md5($url));
		}
	}
}
