<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Webhook {

	/** @var string[] */
	private static $unit_cache_events = [
		'unit.updated',
		'unit.available',
		'unit.unavailable',
		'unit.reserved',
		'unit.occupied',
		'unit.deallocated',
		'unit.overlocked',
		'unit.repossessed',
		'unit.created',
		'unit_type.updated',
	];

	public function __construct() {
		add_action('rest_api_init', function () {
			register_rest_route('stora/v1', '/webhook', [
				'methods' => 'POST',
				'callback' => [$this, 'handle'],
				'permission_callback' => '__return_true',
			]);
		});
	}

	/**
	 * Public webhook URL for registration in Stora.
	 */
	public static function endpoint_url() {
		return rest_url('stora/v1/webhook');
	}

	/**
	 * Whether unsigned webhook requests are permitted (local/staging only).
	 */
	public static function allows_unsigned_requests() {
		return defined('STORA_WEBHOOK_ALLOW_UNSIGNED') && STORA_WEBHOOK_ALLOW_UNSIGNED;
	}

	/**
	 * Resolve event type from Stora payload or legacy test payloads.
	 */
	private function resolve_event_type($payload) {
		if (!is_array($payload)) {
			return '';
		}

		if (isset($payload['event']) && is_string($payload['event'])) {
			return trim($payload['event']);
		}

		if (isset($payload['event']) && is_array($payload['event'])) {
			$type = $payload['event']['type'] ?? '';
			return is_string($type) ? trim($type) : '';
		}

		return '';
	}

	/**
	 * Verify Stora HMAC signature. Requires a configured secret in production.
	 */
	private function verify_signature($request) {
		$secret = function_exists('get_option') ? (string) get_option('stora_webhook_secret', '') : '';
		$secret = trim($secret);
		if ($secret === '') {
			return self::allows_unsigned_requests();
		}

		$signature_header = $request->get_header('x-stora-signature');
		if (!is_string($signature_header) || $signature_header === '') {
			return false;
		}

		$parts = explode(',', $signature_header);
		if (count($parts) < 2) {
			return false;
		}

		$timestamp = explode('=', $parts[0])[1] ?? '';
		$signature = explode('=', $parts[1])[1] ?? '';
		if ($timestamp === '' || $signature === '') {
			return false;
		}

		$ts = (int) $timestamp;
		if ($ts > 20000000000) {
			$ts = (int) floor($ts / 1000);
		}
		if ($ts <= 0 || abs(time() - $ts) > 300) {
			return false;
		}

		$body = $request->get_body();
		if (!is_string($body)) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $body;
		$computed = hash_hmac('sha256', $signed_payload, $secret);

		return hash_equals($computed, $signature);
	}

	/**
	 * Limit how often webhook events can flush plugin caches (abuse protection).
	 */
	private function should_clear_cache_for_event() {
		$key = 'stora_webhook_cache_clear';
		$count = (int) get_transient($key);
		$max = defined('STORA_WEBHOOK_CACHE_CLEAR_LIMIT') ? max(5, (int) STORA_WEBHOOK_CACHE_CLEAR_LIMIT) : 30;
		if ($count >= $max) {
			return false;
		}
		set_transient($key, $count + 1, MINUTE_IN_SECONDS);

		return true;
	}

	public function handle($request) {
		if (!$this->verify_signature($request)) {
			$secret = trim((string) get_option('stora_webhook_secret', ''));
			$message = $secret === '' && !self::allows_unsigned_requests()
				? 'Webhook secret is not configured.'
				: 'Invalid signature.';

			return new WP_REST_Response(['success' => false, 'message' => $message], 401);
		}

		$payload = $request->get_json_params();
		$event_type = $this->resolve_event_type($payload);

		if ($event_type !== '' && in_array($event_type, self::$unit_cache_events, true)) {
			if (!$this->should_clear_cache_for_event()) {
				return new WP_REST_Response(['success' => true, 'cache' => 'throttled'], 200);
			}
			if (class_exists('Stora_Cache')) {
				Stora_Cache::clear_all();
			}
		}

		return new WP_REST_Response(['success' => true], 200);
	}
}

new Stora_Webhook();
