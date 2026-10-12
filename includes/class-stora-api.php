<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_API {

	private $last_debug = null;

	public function get_last_debug() {
		return $this->last_debug;
	}

	public function is_rate_limited() {
		$debug = $this->last_debug;
		return is_array($debug) && (int) ($debug['response_code'] ?? 0) === 429;
	}

	/**
	 * Get a bearer token for Stora Public API.
	 *
	 * Priority:
	 * 1) STORA_API_KEY constant / stored option (assumed to already be an access token)
	 * 2) OAuth client credentials (client_id/client_secret) -> fetch access token and cache it
	 */
	private function get_bearer_token() {
		// If config.php already defined STORA_API_KEY from option/env, treat it as an access token.
		if (defined('STORA_API_KEY') && is_string(STORA_API_KEY) && STORA_API_KEY !== '') {
			return (string) STORA_API_KEY;
		}

		// OAuth client credentials flow.
		$client_id = function_exists('get_option') ? (string) get_option('stora_client_id', '') : '';
		$client_secret = function_exists('get_option') ? (string) get_option('stora_client_secret', '') : '';
		$scope = function_exists('get_option') ? (string) get_option('stora_oauth_scope', 'public.site:read public.unit:read public.unit_type:read') : 'public.site:read public.unit:read public.unit_type:read';
		$client_id = trim($client_id);
		$client_secret = trim($client_secret);
		$scope = trim($scope);

		if ($client_id === '' || $client_secret === '') {
			return '';
		}

		$cache_key = 'stora_oauth_access_token';
		$cached = function_exists('get_transient') ? get_transient($cache_key) : false;
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$token_url = 'https://public-api.stora.co/oauth2/token';
		$payload = [
			'grant_type' => 'client_credentials',
			'client_id' => $client_id,
			'client_secret' => $client_secret,
			'scope' => $scope !== '' ? $scope : 'public.site:read public.unit:read public.unit_type:read',
		];

		$res = wp_remote_post($token_url, [
			'timeout' => 15,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
			'body' => json_encode($payload),
		]);

		if (is_wp_error($res)) {
			$this->last_debug = [
				'endpoint' => 'oauth2/token',
				'method' => 'POST',
				'url' => $token_url,
				'response_code' => null,
				'wp_error' => $res->get_error_message(),
				'body_snippet' => null,
			];
			return '';
		}

		$code = wp_remote_retrieve_response_code($res);
		$body = wp_remote_retrieve_body($res);
		$snippet = is_string($body) ? substr($body, 0, 2000) : null;
		if ($code < 200 || $code >= 300) {
			$this->last_debug = [
				'endpoint' => 'oauth2/token',
				'method' => 'POST',
				'url' => $token_url,
				'response_code' => $code,
				'wp_error' => null,
				'body_snippet' => $snippet,
			];
			return '';
		}

		$json = is_string($body) ? json_decode($body, true) : null;
		$token = is_array($json) && isset($json['access_token']) && is_string($json['access_token']) ? $json['access_token'] : '';
		$expires_in = is_array($json) && isset($json['expires_in']) && is_numeric($json['expires_in']) ? (int) $json['expires_in'] : 300;
		if ($token === '') {
			$this->last_debug = [
				'endpoint' => 'oauth2/token',
				'method' => 'POST',
				'url' => $token_url,
				'response_code' => $code,
				'wp_error' => 'Missing access_token in response',
				'body_snippet' => $snippet,
			];
			return '';
		}

		$ttl = max(30, $expires_in - 60);
		if (function_exists('set_transient')) {
			set_transient($cache_key, $token, $ttl);
		}

		return $token;
	}

	/**
	 * Best URL for a unit type image. Stora Public API 2025-09 uses:
	 * `unit_type.images.default.{ original, small, medium, large }` (each a full HTTPS URL).
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_image_url($unit_type) {
		if (!is_array($unit_type)) {
			return '';
		}

		if (!empty($unit_type['images']) && is_array($unit_type['images'])) {
			$images = $unit_type['images'];
			if (isset($images['default']) && is_array($images['default'])) {
				$def = $images['default'];
				foreach (['medium', 'large', 'original', 'small'] as $size) {
					if (!empty($def[$size]) && is_string($def[$size]) && $def[$size] !== '') {
						return $def[$size];
					}
				}
			}
			$keys = array_keys($images);
			$is_list = $keys === range(0, count($images) - 1);
			if ($is_list) {
				foreach ($images as $img) {
					if (is_string($img) && $img !== '') {
						return $img;
					}
					if (is_array($img)) {
						foreach (['url', 'src', 'href'] as $uk) {
							if (!empty($img[$uk]) && is_string($img[$uk]) && $img[$uk] !== '') {
								return $img[$uk];
							}
						}
					}
				}
			}
		}

		$scalar_keys = ['image_url', 'image', 'photo_url', 'thumbnail_url', 'cover_image', 'hero_image', 'picture_url'];
		foreach ($scalar_keys as $k) {
			if (empty($unit_type[$k])) {
				continue;
			}
			$v = $unit_type[$k];
			if (is_string($v) && $v !== '') {
				return $v;
			}
			if (is_array($v) && !empty($v['url']) && is_string($v['url']) && $v['url'] !== '') {
				return $v['url'];
			}
		}

		return '';
	}

	/**
	 * Format a minor-unit amount (e.g. pence) using the currency prefix from a reference string.
	 *
	 * @param int    $amount_minor
	 * @param string $formatted_reference
	 */
	private static function format_price_from_minor_units($amount_minor, $formatted_reference = '') {
		$major = ((int) $amount_minor) / 100;
		$formatted = number_format($major, 2, '.', '');

		if ($formatted_reference !== '' && preg_match('/^([^\d\-+.\s]+)\s*/', $formatted_reference, $m)) {
			return $m[1] . $formatted;
		}
		if ($formatted_reference !== '' && preg_match('/^(\D+)/', $formatted_reference, $m)) {
			return trim($m[1]) . $formatted;
		}

		return '£' . $formatted;
	}

	/**
	 * Weekly equivalents from every billing period on a unit type.
	 * Stora hosted booking shows the lowest weekly figure (inc. tax), e.g. 20 sq ft = £12.26 not £14.42.
	 *
	 * @param array<int, mixed> $prices
	 * @return array<int, array{amount:int, formatted:string, source:string}>
	 */
	private static function collect_weekly_price_candidates($prices) {
		$candidates = [];

		if (!is_array($prices)) {
			return $candidates;
		}

		foreach ($prices as $p) {
			if (!is_array($p) || ($p['billing_period'] ?? '') !== 'weekly') {
				continue;
			}
			$amount = $p['price']['amount'] ?? null;
			if (!is_numeric($amount) || (int) $amount <= 0) {
				continue;
			}
			$candidates[] = [
				'amount' => (int) $amount,
				'formatted' => is_string($p['price']['formatted'] ?? null) ? trim($p['price']['formatted']) : '',
				'source' => 'weekly',
			];
		}

		foreach ($prices as $p) {
			if (!is_array($p) || ($p['billing_period'] ?? '') !== 'every_four_weeks') {
				continue;
			}
			$amount = $p['price']['amount'] ?? null;
			if (!is_numeric($amount) || (int) $amount <= 0) {
				continue;
			}
			$weekly = (int) round(((int) $amount) / 4);
			if ($weekly <= 0) {
				continue;
			}
			$candidates[] = [
				'amount' => $weekly,
				'formatted' => is_string($p['price']['formatted'] ?? null) ? trim($p['price']['formatted']) : '',
				'source' => 'every_four_weeks',
			];
		}

		foreach ($prices as $p) {
			if (!is_array($p) || ($p['billing_period'] ?? '') !== 'monthly') {
				continue;
			}
			$amount = $p['price']['amount'] ?? null;
			if (!is_numeric($amount) || (int) $amount <= 0) {
				continue;
			}
			$weekly = (int) round(((int) $amount) * 12 / 52);
			if ($weekly <= 0) {
				continue;
			}
			$candidates[] = [
				'amount' => $weekly,
				'formatted' => is_string($p['price']['formatted'] ?? null) ? trim($p['price']['formatted']) : '',
				'source' => 'monthly',
			];
		}

		return $candidates;
	}

	/**
	 * @param array<int, mixed> $prices
	 * @return array{amount:int, formatted:string, source:string}|null
	 */
	private static function resolve_weekly_from_prices($prices) {
		$candidates = self::collect_weekly_price_candidates($prices);
		if (!$candidates) {
			return null;
		}

		usort($candidates, static function ($a, $b) {
			return ((int) $a['amount']) <=> ((int) $b['amount']);
		});

		return $candidates[0];
	}

	/**
	 * Weekly list price for display (matches Stora hosted booking).
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_weekly_display_price($unit_type, $site_id = '') {
		$storefront = class_exists('Stora_Storefront_Prices')
			? Stora_Storefront_Prices::resolve_weekly_minor($unit_type, $site_id)
			: null;
		if (is_array($storefront) && isset($storefront['amount']) && (int) $storefront['amount'] > 0) {
			return self::format_price_from_minor_units((int) $storefront['amount'], (string) ($storefront['formatted'] ?? ''));
		}

		if (!is_array($unit_type) || empty($unit_type['prices']) || !is_array($unit_type['prices'])) {
			return '';
		}

		$resolved = self::resolve_weekly_from_prices($unit_type['prices']);
		if ($resolved === null) {
			return '';
		}

		if ($resolved['source'] === 'weekly' && $resolved['formatted'] !== '') {
			return $resolved['formatted'];
		}

		return self::format_price_from_minor_units($resolved['amount'], $resolved['formatted']);
	}

	/**
	 * Weekly amount in minor currency units (for comparing "from" prices).
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_weekly_amount_minor($unit_type, $site_id = '') {
		$storefront = class_exists('Stora_Storefront_Prices')
			? Stora_Storefront_Prices::resolve_weekly_minor($unit_type, $site_id)
			: null;
		if (is_array($storefront) && isset($storefront['amount']) && (int) $storefront['amount'] > 0) {
			return (int) $storefront['amount'];
		}

		if (!is_array($unit_type) || empty($unit_type['prices']) || !is_array($unit_type['prices'])) {
			return null;
		}

		$resolved = self::resolve_weekly_from_prices($unit_type['prices']);
		if ($resolved === null) {
			return null;
		}

		return (int) $resolved['amount'];
	}

	/**
	 * Monthly list price for display, derived from the weekly storefront figure when available.
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_monthly_display_price($unit_type, $site_id = '') {
		$weekly_minor = self::resolve_unit_type_weekly_amount_minor($unit_type, $site_id);
		if ($weekly_minor !== null && $weekly_minor > 0) {
			$monthly_minor = (int) round($weekly_minor * 52 / 12);
			$formatted_ref = self::resolve_unit_type_weekly_display_price($unit_type, $site_id);

			return self::format_price_from_minor_units($monthly_minor, $formatted_ref);
		}

		return self::resolve_unit_type_display_price_for_period($unit_type, 'monthly');
	}

	/**
	 * Expanded promotion coupon attached to a unit type, if any.
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 * @return array<string,mixed>|null
	 */
	public static function resolve_unit_type_promotion($unit_type) {
		if (!is_array($unit_type)) {
			return null;
		}
		$promotion = $unit_type['promotion'] ?? null;
		if (!is_array($promotion) || empty($promotion['type'])) {
			return null;
		}

		return $promotion;
	}

	/**
	 * Badge label for a unit-type promotion (e.g. "50% off first month").
	 *
	 * @param array<string,mixed> $promotion
	 */
	public static function format_unit_type_promotion_badge($promotion) {
		if (!is_array($promotion)) {
			return '';
		}

		$title = trim((string) ($promotion['title'] ?? ''));
		if ($title !== '') {
			return $title;
		}

		$name = trim((string) ($promotion['name'] ?? ''));
		$type = (string) ($promotion['type'] ?? '');
		$discount_part = '';

		if ($type === 'percentage' && isset($promotion['percent']) && is_numeric($promotion['percent'])) {
			$pct = (float) $promotion['percent'];
			$discount_part = ((floor($pct) === $pct) ? (string) (int) $pct : rtrim(rtrim(number_format($pct, 1), '0'), '.')) . '% off';
		} elseif ($type === 'amount' && isset($promotion['amount']) && is_array($promotion['amount'])) {
			$formatted = trim((string) ($promotion['amount']['formatted'] ?? ''));
			if ($formatted !== '') {
				$discount_part = $formatted . ' off';
			}
		}

		if ($discount_part === '') {
			return $name;
		}

		$duration_label = self::format_promotion_duration_label($promotion);
		if ($duration_label !== '') {
			return trim($discount_part . ' ' . $duration_label);
		}

		return $name !== '' ? $name : $discount_part;
	}

	/**
	 * Discounted and regular weekly display prices when a unit type has a promotion.
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 * @return array{discounted:string,regular:string}|null
	 */
	public static function resolve_unit_type_promotion_weekly_prices($unit_type, $site_id = '') {
		$promotion = self::resolve_unit_type_promotion($unit_type);
		if (!$promotion) {
			return null;
		}

		$regular_minor = self::resolve_unit_type_weekly_amount_minor($unit_type, $site_id);
		if ($regular_minor === null || $regular_minor <= 0) {
			return null;
		}

		$formatted_ref = self::resolve_unit_type_weekly_display_price($unit_type, $site_id);
		$discounted_minor = self::compute_promotion_discounted_minor($regular_minor, $promotion, 'weekly');
		if ($discounted_minor === null) {
			return null;
		}

		return self::build_promotion_price_result($regular_minor, $discounted_minor, $formatted_ref);
	}

	/**
	 * Dynamic ("Billed On Storefront") amount in minor units for a billing period,
	 * derived from the scraped weekly storefront price. Returns null when the
	 * storefront price is unavailable (e.g. the storefront can't be reached).
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_dynamic_amount_minor_for_period($unit_type, $billing_period, $site_id = '') {
		if ((string) $site_id === '' || !class_exists('Stora_Storefront_Prices')) {
			return null;
		}

		$storefront = Stora_Storefront_Prices::resolve_weekly_minor($unit_type, $site_id);
		if (!is_array($storefront) || !isset($storefront['amount']) || (int) $storefront['amount'] <= 0) {
			return null;
		}

		$weekly = (int) $storefront['amount'];
		switch ((string) $billing_period) {
			case 'weekly':
				return $weekly;
			case 'every_four_weeks':
				return $weekly * 4;
			case 'monthly':
				return (int) round($weekly * 52 / 12);
			case 'every_three_months':
				return (int) round($weekly * 52 / 4);
			case 'every_six_months':
				return (int) round($weekly * 52 / 2);
			case 'yearly':
				return (int) round($weekly * 52);
			default:
				return null;
		}
	}

	/**
	 * Amount in minor units for a unit type billing period.
	 *
	 * When a site id is supplied, the dynamic storefront price is preferred so the
	 * amount matches what Stora bills on its hosted storefront; falls back to the
	 * base API price when the storefront price can't be resolved.
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_amount_minor_for_period($unit_type, $billing_period, $site_id = '') {
		$dynamic = self::resolve_unit_type_dynamic_amount_minor_for_period($unit_type, $billing_period, $site_id);
		if ($dynamic !== null && $dynamic > 0) {
			return $dynamic;
		}

		if (!is_array($unit_type) || empty($unit_type['prices']) || !is_array($unit_type['prices'])) {
			return null;
		}

		$billing_period = (string) $billing_period;
		foreach ($unit_type['prices'] as $p) {
			if (!is_array($p) || ($p['billing_period'] ?? '') !== $billing_period) {
				continue;
			}
			$amount = $p['price']['amount'] ?? null;
			if (is_numeric($amount)) {
				return (int) $amount;
			}
		}

		return null;
	}

	/**
	 * Checkout summary rent total in minor units (promotion applied when present).
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_checkout_total_minor_for_period($unit_type, $billing_period, $site_id = '') {
		$regular_minor = self::resolve_unit_type_amount_minor_for_period($unit_type, $billing_period, $site_id);
		if ($regular_minor === null || $regular_minor <= 0) {
			return null;
		}

		$promotion = self::resolve_unit_type_promotion($unit_type);
		if (!$promotion) {
			return (int) $regular_minor;
		}

		$discounted_minor = self::compute_promotion_discounted_minor($regular_minor, $promotion, (string) $billing_period);
		return $discounted_minor !== null ? (int) $discounted_minor : (int) $regular_minor;
	}

	/**
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_display_price_for_period($unit_type, $billing_period) {
		if (!is_array($unit_type) || empty($unit_type['prices']) || !is_array($unit_type['prices'])) {
			return '';
		}

		$billing_period = (string) $billing_period;
		foreach ($unit_type['prices'] as $p) {
			if (!is_array($p) || ($p['billing_period'] ?? '') !== $billing_period) {
				continue;
			}
			$formatted = trim((string) ($p['price']['formatted'] ?? ''));
			if ($formatted !== '') {
				return $formatted;
			}
			$amount = $p['price']['amount'] ?? null;
			if (is_numeric($amount)) {
				return self::format_price_from_minor_units((int) $amount, '');
			}
		}

		return '';
	}

	/**
	 * Discounted and regular prices for a billing period when a unit type has a promotion.
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 * @return array{discounted:string,regular:string,discount_amount:string}|null
	 */
	public static function resolve_unit_type_promotion_prices_for_period($unit_type, $billing_period, $site_id = '') {
		$promotion = self::resolve_unit_type_promotion($unit_type);
		if (!$promotion) {
			return null;
		}

		$billing_period = (string) $billing_period;
		$regular_minor = self::resolve_unit_type_amount_minor_for_period($unit_type, $billing_period, $site_id);
		if ($regular_minor === null || $regular_minor <= 0) {
			return null;
		}

		$formatted_ref = self::resolve_unit_type_display_price_for_period($unit_type, $billing_period);
		$discounted_minor = self::compute_promotion_discounted_minor($regular_minor, $promotion, $billing_period);
		if ($discounted_minor === null) {
			return null;
		}

		return self::build_promotion_price_result($regular_minor, $discounted_minor, $formatted_ref);
	}

	/**
	 * Coupon id attached to a unit type promotion, if expanded.
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_promotion_coupon_id($unit_type) {
		$promotion = self::resolve_unit_type_promotion($unit_type);
		if (!$promotion || empty($promotion['id'])) {
			return '';
		}

		return (string) $promotion['id'];
	}

	/**
	 * @return array{discounted:string,regular:string,discount_amount:string}|null
	 */
	private static function build_promotion_price_result($regular_minor, $discounted_minor, $formatted_ref) {
		if ($discounted_minor === null || $discounted_minor >= $regular_minor) {
			return null;
		}

		return [
			'discounted' => self::format_price_from_minor_units((int) $discounted_minor, $formatted_ref),
			'regular' => self::format_price_from_minor_units((int) $regular_minor, $formatted_ref),
			'discount_amount' => self::format_price_from_minor_units((int) $regular_minor - (int) $discounted_minor, $formatted_ref),
		];
	}

	/**
	 * Format a billing-period price for checkout display (always derived from minor units).
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function format_checkout_price_minor($amount_minor, $unit_type, $billing_period) {
		$formatted_ref = self::resolve_unit_type_display_price_for_period($unit_type, (string) $billing_period);

		return self::format_price_from_minor_units((int) $amount_minor, $formatted_ref);
	}

	/**
	 * @param array<string,mixed> $promotion
	 */
	private static function compute_promotion_discounted_minor($regular_minor, $promotion, $target_billing_period) {
		$type = (string) ($promotion['type'] ?? '');

		if ($type === 'percentage' && isset($promotion['percent']) && is_numeric($promotion['percent'])) {
			$pct = (float) $promotion['percent'];
			return (int) round(((int) $regular_minor) * (1 - ($pct / 100)));
		}

		if ($type === 'amount' && isset($promotion['amount']) && is_array($promotion['amount'])) {
			$amount_minor = $promotion['amount']['amount'] ?? null;
			if (is_numeric($amount_minor)) {
				$discount = self::convert_promotion_discount_to_period_minor((int) $amount_minor, $promotion, $target_billing_period);
				return max(0, (int) $regular_minor - $discount);
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $promotion
	 */
	private static function convert_promotion_discount_to_period_minor($amount_minor, $promotion, $target_billing_period) {
		$promo_billing = (string) ($promotion['billing_period'] ?? 'monthly');
		if ($promo_billing === $target_billing_period) {
			return $amount_minor;
		}

		$weekly = self::convert_promotion_discount_to_weekly_minor($amount_minor, $promotion);
		return self::convert_weekly_minor_to_period_minor($weekly, $target_billing_period);
	}

	private static function convert_weekly_minor_to_period_minor($weekly_minor, $billing_period) {
		if ($billing_period === 'weekly') {
			return (int) $weekly_minor;
		}
		if ($billing_period === 'monthly') {
			return (int) round(((int) $weekly_minor) * 52 / 12);
		}
		if ($billing_period === 'yearly') {
			return (int) round(((int) $weekly_minor) * 52);
		}
		if ($billing_period === 'every_four_weeks') {
			return (int) round(((int) $weekly_minor) * 4);
		}

		return (int) round(((int) $weekly_minor) * 52 / 12);
	}

	/**
	 * @param array<string,mixed> $promotion
	 */
	private static function format_promotion_duration_label($promotion) {
		$duration = (string) ($promotion['duration'] ?? '');
		$months = $promotion['months'] ?? null;
		$billing = (string) ($promotion['billing_period'] ?? '');

		if ($duration === 'forever') {
			return '';
		}
		if ($duration === 'once') {
			if ($billing === 'monthly') {
				return 'first month';
			}
			if ($billing === 'weekly') {
				return 'first week';
			}
			if ($billing === 'yearly') {
				return 'first year';
			}
			return 'first payment';
		}
		if ($duration === 'repeating' && is_numeric($months)) {
			$m = (int) $months;
			if ($m === 1 && $billing === 'monthly') {
				return 'first month';
			}
			if ($m === 1 && $billing === 'weekly') {
				return 'first week';
			}
			if ($m > 1 && $billing === 'monthly') {
				return 'first ' . $m . ' months';
			}
			if ($m > 1) {
				return 'first ' . $m . ' periods';
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $promotion
	 */
	private static function convert_promotion_discount_to_weekly_minor($amount_minor, $promotion) {
		$billing = (string) ($promotion['billing_period'] ?? 'monthly');
		if ($billing === 'weekly') {
			return $amount_minor;
		}
		if ($billing === 'every_four_weeks') {
			return (int) round($amount_minor / 4);
		}
		if ($billing === 'yearly') {
			return (int) round($amount_minor / 52);
		}

		return (int) round($amount_minor * 12 / 52);
	}

	/**
	 * Weekly display price for a protection level (same weekly resolution as unit types).
	 *
	 * @param array<string,mixed>|mixed $protection_level
	 */
	public static function resolve_protection_weekly_display_price($protection_level) {
		if (!is_array($protection_level) || empty($protection_level['prices']) || !is_array($protection_level['prices'])) {
			return '';
		}

		$resolved = self::resolve_weekly_from_prices($protection_level['prices']);
		if ($resolved === null) {
			return '';
		}

		if ($resolved['source'] === 'weekly' && $resolved['formatted'] !== '') {
			return $resolved['formatted'];
		}

		return self::format_price_from_minor_units($resolved['amount'], $resolved['formatted']);
	}

	/**
	 * Formatted protection price for order summary (matches unit billing period).
	 *
	 * @param array<string,mixed>|mixed $protection_level
	 */
	public static function resolve_protection_summary_display_price($protection_level, $billing_period = 'monthly') {
		if (!is_array($protection_level)) {
			return '';
		}

		$pick = self::pick_protection_price_for_period($protection_level, (string) $billing_period);
		if (!$pick) {
			return '';
		}

		if (!empty($protection_level['prices']) && is_array($protection_level['prices'])) {
			foreach ($protection_level['prices'] as $p) {
				if (!is_array($p) || ($p['billing_period'] ?? '') !== $pick['billing_period']) {
					continue;
				}
				$formatted = trim((string) ($p['price']['formatted'] ?? ''));
				if ($formatted !== '') {
					return $formatted;
				}
				break;
			}
		}

		$formatted_ref = '';
		if (!empty($protection_level['prices']) && is_array($protection_level['prices'])) {
			foreach ($protection_level['prices'] as $p) {
				if (!is_array($p)) {
					continue;
				}
				$formatted_ref = trim((string) ($p['price']['formatted'] ?? ''));
				if ($formatted_ref !== '') {
					break;
				}
			}
		}

		return self::format_price_from_minor_units((int) $pick['amount'], $formatted_ref);
	}

	/**
	 * Pick protection price amount for a given order billing period.
	 *
	 * @param array<string,mixed>|mixed $protection_level
	 * @return array{billing_period:string, amount:int}|null
	 */
	public static function pick_protection_price_for_period($protection_level, $billing_period) {
		if (!is_array($protection_level) || empty($protection_level['prices']) || !is_array($protection_level['prices'])) {
			return null;
		}

		$billing_period = (string) $billing_period;
		$by_period = [];

		foreach ($protection_level['prices'] as $p) {
			if (!is_array($p)) {
				continue;
			}
			$period = (string) ($p['billing_period'] ?? '');
			$amount = $p['price']['amount'] ?? null;
			if ($period === '' || !is_numeric($amount)) {
				continue;
			}
			$by_period[$period] = (int) $amount;
		}

		if ($billing_period !== '' && isset($by_period[$billing_period])) {
			return ['billing_period' => $billing_period, 'amount' => $by_period[$billing_period]];
		}

		$preferred = ['monthly', 'weekly', 'every_four_weeks', 'yearly', 'every_three_months', 'every_six_months'];
		foreach ($preferred as $period) {
			if (isset($by_period[$period])) {
				return ['billing_period' => $period, 'amount' => $by_period[$period]];
			}
		}

		foreach ($by_period as $period => $amount) {
			return ['billing_period' => (string) $period, 'amount' => (int) $amount];
		}

		return null;
	}

	/**
	 * Format cover level label for display (e.g. "£2,500 cover").
	 *
	 * @param array<string,mixed>|mixed $protection_level
	 */
	public static function format_protection_cover_label($protection_level) {
		if (!is_array($protection_level)) {
			return '';
		}

		$cover = $protection_level['cover_level'] ?? null;
		if (!is_array($cover)) {
			return '';
		}

		$formatted = trim((string) ($cover['formatted'] ?? ''));
		if ($formatted === '' && isset($cover['amount']) && is_numeric($cover['amount'])) {
			$formatted = self::format_price_from_minor_units((int) $cover['amount'], '');
		}

		if ($formatted === '') {
			return '';
		}

		$display = preg_replace('/\.00(?!\d)/', '', $formatted);
		return $display . ' cover';
	}

	/**
	 * Site id attached to a protection level, or empty for a global level.
	 *
	 * @param array<string,mixed>|mixed $protection_level
	 */
	public static function protection_level_site_id($protection_level) {
		if (!is_array($protection_level) || !isset($protection_level['site']) || !is_array($protection_level['site'])) {
			return '';
		}

		return (string) ($protection_level['site']['id'] ?? '');
	}

	/**
	 * Public protection levels for a site.
	 *
	 * Stora moved protection from account-global IDs to site-scoped IDs.
	 * Orders must send a level that belongs to the order's site.
	 *
	 * @see https://docs.stora.co/2025-09/guides/site-scoped-protection-levels
	 * @param string $site_id
	 * @return array<int, array<string,mixed>>
	 */
	public function get_protection_levels($site_id = '') {
		$site_id = (string) $site_id;
		if ($site_id === '') {
			return [];
		}

		$cache_key = 'protection_levels_site_v4_' . $site_id;
		$cached = Stora_Cache::get($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		$site_query = 'site_id=' . rawurlencode($site_id) . '&expand=site';
		$all = $this->fetch_protection_levels($site_query);
		$filtered = $this->filter_protection_levels_for_site($all, $site_id);

		// site_id can still return globals if the API ignores the filter; site_scoped is the
		// catalogue that includes site.id, so use it whenever the first pass has no site match.
		if ($filtered === []) {
			$filtered = $this->filter_protection_levels_for_site(
				$this->fetch_protection_levels('site_scoped=true&expand=site'),
				$site_id
			);
		}

		usort($filtered, static function ($a, $b) {
			$aa = is_array($a['cover_level'] ?? null) && is_numeric($a['cover_level']['amount'] ?? null)
				? (int) $a['cover_level']['amount']
				: 0;
			$bb = is_array($b['cover_level'] ?? null) && is_numeric($b['cover_level']['amount'] ?? null)
				? (int) $b['cover_level']['amount']
				: 0;
			return $aa <=> $bb;
		});

		if ($filtered !== []) {
			Stora_Cache::set($cache_key, $filtered);
		}

		return $filtered;
	}

	/**
	 * Keep public levels that belong to this site. Globals (site null/missing) are dropped.
	 *
	 * @param array<int, mixed>|null $levels
	 * @return array<int, array<string,mixed>>
	 */
	private function filter_protection_levels_for_site($levels, $site_id) {
		$site_id = (string) $site_id;
		if (!is_array($levels) || $site_id === '') {
			return [];
		}

		$filtered = [];
		foreach ($levels as $level) {
			if (!is_array($level)) {
				continue;
			}
			if (array_key_exists('public', $level) && $level['public'] === false) {
				continue;
			}
			if (self::protection_level_site_id($level) !== $site_id) {
				continue;
			}
			$filtered[] = $level;
		}

		return $filtered;
	}

	/**
	 * @param string $query Extra query string without leading ?.
	 * @return array<int, array<string,mixed>>|null
	 */
	private function fetch_protection_levels($query) {
		$all = [];
		$page = 1;
		$limit = 100;
		$max_pages = 20;
		$got_page = false;

		while ($page <= $max_pages) {
			$endpoint = 'protection_levels?' . $query . '&page=' . (int) $page . '&limit=' . (int) $limit;
			$res = $this->request($endpoint);
			$list = null;
			if (is_array($res) && isset($res['protection_levels']) && is_array($res['protection_levels'])) {
				$list = $res['protection_levels'];
			} elseif (is_array($res) && isset($res['data']) && is_array($res['data'])) {
				$list = $res['data'];
			}

			if (!is_array($list)) {
				return $got_page ? $all : null;
			}

			$got_page = true;
			foreach ($list as $level) {
				if (is_array($level)) {
					$all[] = $level;
				}
			}

			if (count($list) < $limit) {
				break;
			}

			$next = null;
			if (is_array($res) && isset($res['meta']['pagination']['next'])) {
				$next = $res['meta']['pagination']['next'];
			}
			if ($next === null) {
				break;
			}
			$page = (int) $next;
			if ($page < 1) {
				break;
			}
		}

		return $all;
	}

	/**
	 * @param array<string,mixed>|mixed $ut
	 */
	private function unit_type_has_full_dimensions($ut) {
		if (!is_array($ut) || empty($ut['dimensions']) || !is_array($ut['dimensions'])) {
			return false;
		}
		$d = $ut['dimensions'];
		return isset($d['length'], $d['width'], $d['height']);
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function get_unit_types_for_site($site_id) {
		$site_id = (string) $site_id;
		if ($site_id === '') {
			return [];
		}

		$cache_key = 'unit_types_site_' . $site_id . '_v2_expand_images_promotion';
		$cached = Stora_Cache::get($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		$all = [];
		$page = 1;
		$limit = 100;
		$expand = rawurlencode('images,promotion');

		while (true) {
			$endpoint = 'unit_types?site_id=' . rawurlencode($site_id)
				. '&page=' . (int) $page
				. '&limit=' . (int) $limit
				. '&expand=' . $expand;
			$res = $this->request($endpoint);

			$list = null;
			if (is_array($res) && isset($res['unit_types']) && is_array($res['unit_types'])) {
				$list = $res['unit_types'];
			} elseif (is_array($res) && isset($res['data']) && is_array($res['data'])) {
				$list = $res['data'];
			}

			if (!is_array($list)) {
				break;
			}

			foreach ($list as $ut) {
				if (is_array($ut)) {
					$all[] = $ut;
				}
			}

			$next = null;
			if (is_array($res) && isset($res['meta']['pagination']['next'])) {
				$next = $res['meta']['pagination']['next'];
			}

			if ($next === null || count($list) < $limit) {
				break;
			}

			$page = (int) $next;
			if ($page < 1) {
				break;
			}
		}

		if ($all) {
			Stora_Cache::set($cache_key, $all);
		}

		return $all;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function find_unit_type_in_site_catalog($site_id, $unit_type_id) {
		$site_id = (string) $site_id;
		$unit_type_id = (string) $unit_type_id;
		if ($site_id === '' || $unit_type_id === '') {
			return null;
		}

		foreach ($this->get_unit_types_for_site($site_id) as $catalog_ut) {
			if (!is_array($catalog_ut) || (string) ($catalog_ut['id'] ?? '') !== $unit_type_id) {
				continue;
			}
			return $catalog_ut;
		}

		return null;
	}

	/**
	 * Merge price rows by billing_period across API sources.
	 * For display periods, keep the lowest amount (matches Stora hosted storefront).
	 *
	 * @param array<int, mixed> ...$price_arrays
	 * @return array<int, array<string,mixed>>
	 */
	private static function merge_unit_type_prices(...$price_arrays) {
		$by_period = [];
		$prefer_lowest = ['weekly', 'monthly', 'every_four_weeks'];

		foreach ($price_arrays as $prices) {
			if (!is_array($prices)) {
				continue;
			}
			foreach ($prices as $p) {
				if (!is_array($p)) {
					continue;
				}
				$period = (string) ($p['billing_period'] ?? '');
				if ($period === '') {
					continue;
				}

				$incoming = $p['price']['amount'] ?? null;
				$incoming_minor = is_numeric($incoming) ? (int) $incoming : 0;

				if (!isset($by_period[$period])) {
					$by_period[$period] = $p;
					continue;
				}

				if (!in_array($period, $prefer_lowest, true)) {
					$by_period[$period] = $p;
					continue;
				}

				$existing = $by_period[$period]['price']['amount'] ?? null;
				$existing_minor = is_numeric($existing) ? (int) $existing : 0;
				if ($incoming_minor > 0 && ($existing_minor <= 0 || $incoming_minor < $existing_minor)) {
					$by_period[$period] = $p;
				}
			}
		}

		return array_values($by_period);
	}

	/**
	 * Canonical unit type for pricing/display (merges /units, unit type, and site catalog prices).
	 *
	 * @param array<string,mixed> $unit_type
	 * @return array<string,mixed>
	 */
	public function resolve_canonical_unit_type_for_display($unit_type, $site_id = '') {
		if (!is_array($unit_type)) {
			return [];
		}

		$utid = (string) ($unit_type['id'] ?? '');
		if ($utid === '') {
			return $unit_type;
		}

		$merged = $unit_type;
		$price_sources = [isset($unit_type['prices']) && is_array($unit_type['prices']) ? $unit_type['prices'] : []];

		$full = $this->get_unit_type($utid);
		if (is_array($full) && isset($full['unit_type']) && is_array($full['unit_type'])) {
			$merged = array_merge($merged, $full['unit_type']);
			if (isset($full['unit_type']['prices']) && is_array($full['unit_type']['prices'])) {
				$price_sources[] = $full['unit_type']['prices'];
			}
		}

		$site_id = (string) $site_id;
		$catalog_ut = $site_id !== '' ? $this->find_unit_type_in_site_catalog($site_id, $utid) : null;
		if (is_array($catalog_ut)) {
			$merged = array_merge($merged, $catalog_ut);
			if (isset($catalog_ut['prices']) && is_array($catalog_ut['prices'])) {
				$price_sources[] = $catalog_ut['prices'];
			}
		}

		$merged['prices'] = self::merge_unit_type_prices(...$price_sources);

		return $merged;
	}

	private function enrich_units_with_unit_types($data){
		if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
			return $data;
		}

		foreach ($data['data'] as $i => $u) {
			if (!is_array($u)) {
				continue;
			}

			$embedded = isset($u['unit_type']) && is_array($u['unit_type']) ? $u['unit_type'] : [];
			$utid = (string) ($embedded['id'] ?? '');
			if ($utid === '') {
				continue;
			}

			$site_id = isset($u['site']['id']) ? (string) $u['site']['id'] : '';
			$data['data'][$i]['unit_type'] = $this->resolve_canonical_unit_type_for_display($embedded, $site_id);
		}

		return $data;
	}

	private function request($endpoint,$method='GET',$body=null){

		$base_url = rtrim((string) STORA_API_BASE, '/');
		$endpoint_path = ltrim((string) $endpoint, '/');
		$url = $base_url . '/' . $endpoint_path;

		$token = $this->get_bearer_token();
		if (!is_string($token) || $token === '') {
			$this->last_debug = [
				'endpoint' => $endpoint,
				'method' => $method,
				'url' => $url,
				'response_code' => null,
				'wp_error' => 'Missing Stora credentials (access token or OAuth client credentials)',
				'body_snippet' => null,
			];
			return null;
		}

		$args = [
			'method'=>$method,
			'timeout' => 15,
			'headers'=>[
				'Authorization'=>'Bearer '.$token,
				'Accept' => 'application/json',
				'Content-Type'=>'application/json'
			]
		];

		if($body){
			$args['body'] = json_encode($body);
		}

		$max_attempts = defined('STORA_API_RATE_LIMIT_RETRIES') ? max(1, (int) STORA_API_RATE_LIMIT_RETRIES) : 3;
		$response = null;
		$code = null;
		$raw_body = '';

		for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
			$response = wp_remote_request($url, $args);

			if (is_wp_error($response)) {
				$this->last_debug = [
					'endpoint' => $endpoint,
					'method' => $method,
					'url' => $url,
					'response_code' => null,
					'wp_error' => $response->get_error_message(),
					'body_snippet' => null,
				];
				return null;
			}

			$code = wp_remote_retrieve_response_code($response);
			$raw_body = wp_remote_retrieve_body($response);

			if ((int) $code !== 429 || $attempt >= $max_attempts) {
				break;
			}

			$retry_after = wp_remote_retrieve_header($response, 'retry-after');
			$sleep_seconds = is_numeric($retry_after) ? max(1, (int) $retry_after) : min(8, 2 ** $attempt);
			sleep($sleep_seconds);
		}

		$this->last_debug = [
			'endpoint' => $endpoint,
			'method' => $method,
			'url' => $url,
			'response_code' => $code,
			'wp_error' => null,
			'body_snippet' => is_string($raw_body) && $raw_body !== '' ? substr($raw_body, 0, 2000) : null,
		];

		if ($code < 200 || $code >= 300) {
			return null;
		}

		if (!is_string($raw_body) || $raw_body === '') {
			return null;
		}

		$decoded = json_decode($raw_body, true);
		if (!is_array($decoded)) {
			return null;
		}

		return $decoded;
	}

	public function get_units(){

		// Optional cache bypass for admins (useful when sites/units change in Stora).
		// Example: add `?stora_nocache=1` to the page URL while logged in.
		$can_bypass = function_exists('is_user_logged_in')
			&& is_user_logged_in()
			&& function_exists('current_user_can')
			&& current_user_can('manage_options');
		$nocache = $can_bypass && isset($_GET['stora_nocache']) && (string) $_GET['stora_nocache'] === '1';
		if ($nocache) {
			Stora_Cache::clear('units');
			if (class_exists('Stora_Storefront_Prices')) {
				Stora_Storefront_Prices::clear_all_caches();
			}
		}

		$cached = Stora_Cache::get('units');

		if ($cached) {
			// Normalise cached responses too (in case API version/shape changed).
			if (is_array($cached) && isset($cached['units']) && is_array($cached['units'])) {
				$cached = ['data' => $cached['units']];
			}

			if (is_array($cached)) {
				return $cached;
			}

			return $cached;
		}

		$lock_key = 'units_fetch_lock';
		if (Stora_Cache::get($lock_key)) {
			for ($i = 0; $i < 8; $i++) {
				usleep(250000);
				$cached = Stora_Cache::get('units');
				if (is_array($cached)) {
					if (isset($cached['units']) && is_array($cached['units'])) {
						$cached = ['data' => $cached['units']];
					}
					return $cached;
				}
			}

			$stale = Stora_Cache::get_stale('units');
			if (is_array($stale)) {
				return $stale;
			}
		}

		Stora_Cache::set($lock_key, 1, 60);

		// Stora list endpoints are paginated. Fetch all pages so we don't miss sites/units.
		$all = [];
		$page = 1;
		$limit = 100; // max per API docs
		$units_request_debug = null;

		while (true) {
			$endpoint = 'units?page=' . (int) $page . '&limit=' . (int) $limit;
			$res = $this->request($endpoint);
			if ($units_request_debug === null) {
				$units_request_debug = $this->last_debug;
			}

			if ($res === null && $this->is_rate_limited()) {
				$stale = Stora_Cache::get_stale('units');
				Stora_Cache::clear($lock_key);
				if (is_array($stale)) {
					return $stale;
				}
				return null;
			}

			$list = null;
			if (is_array($res) && isset($res['data']) && is_array($res['data'])) {
				$list = $res['data'];
			} elseif (is_array($res) && isset($res['units']) && is_array($res['units'])) {
				$list = $res['units'];
			}

			if (!is_array($list)) {
				Stora_Cache::clear($lock_key);
				// Preserve original behavior on failure.
				return null;
			}

			$all = array_merge($all, $list);

			$next = null;
			if (is_array($res) && isset($res['meta']) && is_array($res['meta']) && isset($res['meta']['pagination']) && is_array($res['meta']['pagination'])) {
				$next = $res['meta']['pagination']['next'] ?? null;
			}

			if ($next === null) {
				break;
			}

			$page = (int) $next;
			if ($page < 1) {
				break;
			}
		}

		$data = ['data' => $all];
		$data = $this->enrich_units_with_unit_types($data);

		// Only cache successful responses.
		if (is_array($data)) {
			Stora_Cache::set('units', $data);
			Stora_Cache::set_stale('units', $data);
		}

		Stora_Cache::clear($lock_key);

		// Keep debug pointing at the primary /units request.
		if ($units_request_debug !== null) {
			$this->last_debug = $units_request_debug;
		}

		return $data;
	}

	public function get_unit_type($unit_type_id){

		$unit_type_id = (string) $unit_type_id;
		if ($unit_type_id === '') {
			return null;
		}

		// Cache key includes expand so older entries without expanded images/promotion are not reused.
		$cache_key = 'unit_type_' . $unit_type_id . '_expand_images_promotion';
		$cached = Stora_Cache::get($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		$endpoint = 'unit_types/' . rawurlencode($unit_type_id) . '?expand=' . rawurlencode('images,promotion');
		$data = $this->request($endpoint);
		if (is_array($data)) {
			Stora_Cache::set($cache_key, $data);
		}

		return $data;
	}

	public function create_order($data){
		return $this->request('orders','POST',$data);
	}

	public function update_order($order_id, $data){
		$order_id = (string) $order_id;
		if ($order_id === '') {
			return null;
		}
		return $this->request('orders/' . rawurlencode($order_id), 'PATCH', $data);
	}

	public function delete_order($order_id) {
		$order_id = (string) $order_id;
		if ($order_id === '') {
			return null;
		}
		return $this->request('orders/' . rawurlencode($order_id), 'DELETE');
	}

	public function list_contacts($query = []){
		$endpoint = 'contacts';
		if (is_array($query) && $query) {
			$endpoint .= '?' . http_build_query($query);
		}
		$res = $this->request($endpoint, 'GET');

		// Normalise list response shape { contacts: [...] } to { data: [...] } for internal callers.
		if (is_array($res) && isset($res['contacts']) && is_array($res['contacts']) && !isset($res['data'])) {
			$res['data'] = $res['contacts'];
		}

		return $res;
	}

	public function create_contact($data) {
		return $this->request('contacts', 'POST', $data);
	}

	public function update_contact($contact_id, $data) {
		$contact_id = (string) $contact_id;
		if ($contact_id === '') {
			return null;
		}
		return $this->request('contacts/' . rawurlencode($contact_id), 'PATCH', $data);
	}

	public function list_coupons($query = []){
		$endpoint = 'coupons';
		if (is_array($query) && $query) {
			$endpoint .= '?' . http_build_query($query);
		}
		return $this->request($endpoint, 'GET');
	}

	public function validate_order($order_id, $expand = []){
		$order_id = (string) $order_id;
		if ($order_id === '') {
			return null;
		}
		$endpoint = 'orders/' . rawurlencode($order_id) . '/validate';
		if (is_array($expand) && $expand) {
			$endpoint .= '?' . http_build_query(['expand' => array_values($expand)]);
		}
		return $this->request($endpoint, 'GET');
	}

	public function finalize_order($order_id, $data){
		$order_id = (string) $order_id;
		if ($order_id === '') {
			return null;
		}
		return $this->request('orders/' . rawurlencode($order_id) . '/finalize','POST',$data);
	}

	public function create_reservation($data){

		return $this->request('reservations','POST',$data);
	}
	

}