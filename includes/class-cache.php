<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Cache {

	private const COUPON_LOOKUP_KEY = 'stora_coupon_lookup_v1';

	public static function get($key) {
		return get_transient('stora_' . $key);
	}

	public static function set($key, $value, $ttl = null) {
		$ttl = $ttl !== null ? (int) $ttl : (defined('STORA_CACHE_TTL') ? (int) STORA_CACHE_TTL : 900);
		set_transient('stora_' . $key, $value, max(30, $ttl));
	}

	/**
	 * Longer-lived fallback used when live API fetches are rate-limited.
	 */
	public static function get_stale($key) {
		return get_transient('stora_stale_' . $key);
	}

	public static function set_stale($key, $value) {
		$ttl = defined('STORA_STALE_CACHE_TTL') ? (int) STORA_STALE_CACHE_TTL : DAY_IN_SECONDS;
		set_transient('stora_stale_' . $key, $value, max(3600, $ttl));
	}

	public static function clear($key) {
		delete_transient('stora_' . $key);
	}

	public static function clear_coupon_lookup() {
		delete_transient(self::COUPON_LOOKUP_KEY);
	}

	/**
	 * Clear plugin API caches (units, coupons, OAuth token).
	 */
	public static function clear_all() {
		self::clear('units');
		self::clear_coupon_lookup();
		delete_transient('stora_oauth_access_token');
	}
}