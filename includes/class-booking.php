<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Booking {

	public function __construct(){

		add_action('admin_post_stora_booking',[$this,'process']);
		add_action('admin_post_nopriv_stora_booking',[$this,'process']);

		add_action('wp_ajax_stora_validate_coupon', [$this, 'validate_coupon']);
		add_action('wp_ajax_nopriv_stora_validate_coupon', [$this, 'validate_coupon']);

		add_action('wp_ajax_stora_preview_totals', [$this, 'preview_totals']);
		add_action('wp_ajax_nopriv_stora_preview_totals', [$this, 'preview_totals']);

		add_action('wp_ajax_stora_move_in_dates', [$this, 'move_in_dates']);
		add_action('wp_ajax_nopriv_stora_move_in_dates', [$this, 'move_in_dates']);

	}

	/**
	 * Dropdown options for the "Tell us about you" checkout step (Stora contact fields).
	 *
	 * Array keys are the values Stora expects and must not be changed; the labels are
	 * display-only and use sentence case for readability, so they can be reworded freely.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function checkout_select_options() {
		return [
			'use_case' => [
				'business_needs' => 'Business needs',
				'moving_home' => 'Moving home',
				'more_space' => 'More space/declutter',
				'refurb' => 'Refurb/renovation',
				'travelling' => 'Going abroad/travelling',
				'other' => 'Other',
			],
			'type' => [
				'domestic' => 'Home',
				'company' => 'Business',
				'student' => 'Student',
				'charity' => 'Charity',
				'local_authority' => 'Local Authority',
			],
			'marketing_source' => [
				'used_before' => 'Used before',
				'recommendation' => 'Recommendation',
				'received_leaflet' => 'Received leaflet',
				'seen_advert' => 'Saw advert',
				'facebook_marketplace' => 'Facebook Marketplace',
				'google_search' => 'Google search',
				'other_online_search' => 'Other online search',
				'social_media' => 'Social media',
				'email' => 'Email',
				'radio' => 'Radio',
				'other' => 'Other',
			],
			'countries' => [
				'GB' => 'United Kingdom',
			],
		];
	}

	/**
	 * Build a move-in date window from explicit date rows.
	 *
	 * @param array<int, array{value: string, label: string, month: string, day?: int, weekday?: string}> $dates
	 * @return array{
	 *   min: string,
	 *   max: string,
	 *   dates: array<int, array{value: string, label: string, month: string, day?: int, weekday?: string}>,
	 *   timezone: string
	 * }
	 */
	private static function build_move_in_date_window(array $dates, $tz = null) {
		$tz = $tz instanceof DateTimeZone ? $tz : (function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC'));
		$dates = array_values(array_filter($dates, static function ($row) {
			return is_array($row) && !empty($row['value']);
		}));

		if (!$dates) {
			return [
				'min' => '',
				'max' => '',
				'dates' => [],
				'timezone' => $tz->getName(),
			];
		}

		return [
			'min' => (string) $dates[0]['value'],
			'max' => (string) $dates[count($dates) - 1]['value'],
			'dates' => $dates,
			'timezone' => $tz->getName(),
		];
	}

	/**
	 * Fallback move-in window when Stora probing is unavailable.
	 *
	 * @return array{
	 *   min: string,
	 *   max: string,
	 *   dates: array<int, array{value: string, label: string, month: string, day?: int, weekday?: string}>,
	 *   timezone: string
	 * }
	 */
	public static function get_move_in_date_window($max_days = null) {
		$tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
		$today = new DateTimeImmutable('today', $tz);
		$min_days = defined('STORA_MOVE_IN_MIN_DAYS') ? max(0, (int) STORA_MOVE_IN_MIN_DAYS) : 1;
		$min = $today->modify('+' . $min_days . ' day');
		if ($max_days === null) {
			$max_days = defined('STORA_MOVE_IN_MAX_DAYS') ? max(1, (int) STORA_MOVE_IN_MAX_DAYS) : 3;
		}
		$max_days = max(1, (int) $max_days);
		$max = $min->modify('+' . ($max_days - 1) . ' days');

		$dates = [];
		for ($date = $min; $date <= $max; $date = $date->modify('+1 day')) {
			$dates[] = self::format_move_in_date_row($date);
		}

		return self::build_move_in_date_window($dates, $tz);
	}

	/**
	 * Resolve move-in dates for a checkout unit by probing Stora order validation.
	 *
	 * @return array{
	 *   min: string,
	 *   max: string,
	 *   dates: array<int, array{value: string, label: string, month: string, day?: int, weekday?: string}>,
	 *   timezone: string
	 * }
	 */
	public static function move_in_dates_cache_key($site_id, $unit_type_id, $billing_period) {
		return 'move_in_dates_v2_' . md5((string) $site_id . '|' . (string) $unit_type_id . '|' . (string) $billing_period);
	}

	/**
	 * Cached move-in window when available (no Stora probing).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_cached_move_in_date_window($site_id, $unit_type_id, $billing_period) {
		$cached = Stora_Cache::get(self::move_in_dates_cache_key($site_id, $unit_type_id, $billing_period));
		if (is_array($cached) && !empty($cached['dates']) && is_array($cached['dates'])) {
			return $cached;
		}
		return null;
	}

	public static function resolve_move_in_dates_for_checkout($api, $site_id, $unit_type_id, $billing_period, $amount_minor) {
		$site_id = (string) $site_id;
		$unit_type_id = (string) $unit_type_id;
		$billing_period = (string) $billing_period;
		$amount_minor = (int) $amount_minor;

		if (!($api instanceof Stora_API) || $site_id === '' || $unit_type_id === '' || $billing_period === '' || $amount_minor <= 0) {
			return self::get_move_in_date_window();
		}

		$cache_key = self::move_in_dates_cache_key($site_id, $unit_type_id, $billing_period);
		$cached = Stora_Cache::get($cache_key);
		if (is_array($cached) && !empty($cached['dates']) && is_array($cached['dates'])) {
			return $cached;
		}

		$tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
		$today = new DateTimeImmutable('today', $tz);
		$min_days = defined('STORA_MOVE_IN_MIN_DAYS') ? max(0, (int) STORA_MOVE_IN_MIN_DAYS) : 1;
		$probe_days = defined('STORA_MOVE_IN_PROBE_DAYS') ? max(1, (int) STORA_MOVE_IN_PROBE_DAYS) : 14;
		$cursor = $today->modify('+' . $min_days . ' day');
		$first_iso = $cursor->format('Y-m-d');

		$order_payload = [
			'site' => ['id' => $site_id],
			'payment_method' => 'card',
			'starts_at' => $first_iso . 'T00:00:00Z',
			'billing_period' => $billing_period,
			'contact' => [
				'full_name' => 'Move-in Availability',
				'email' => 'move-in-probe-' . wp_generate_password(12, false, false) . '@availability.invalid',
				'phone_number' => '+447000000000',
				'source' => 'booking',
			],
			'line_items' => [
				[
					'type' => 'unit_type',
					'quantity' => 1,
					'price' => ['amount' => $amount_minor],
					'item' => ['id' => $unit_type_id],
				],
			],
		];

		$order_response = $api->create_order($order_payload);
		if (!is_array($order_response) && $api->is_rate_limited()) {
			return self::get_move_in_date_window();
		}

		$order_id = is_array($order_response) && isset($order_response['order']) && is_array($order_response['order'])
			? (string) ($order_response['order']['id'] ?? '')
			: '';

		if ($order_id === '') {
			return self::get_move_in_date_window();
		}

		$allowed = [];
		for ($i = 0; $i < $probe_days; $i++) {
			$iso = $cursor->format('Y-m-d');
			if ($i > 0) {
				$api->update_order($order_id, ['starts_at' => $iso . 'T00:00:00Z']);
				if ($api->is_rate_limited()) {
					break;
				}
			}
			if (!self::order_accepts_starts_at($api, $order_id)) {
				if ($api->is_rate_limited()) {
					break;
				}
				break;
			}
			$allowed[] = self::format_move_in_date_row($cursor);
			$cursor = $cursor->modify('+1 day');
		}

		if ($order_id !== '') {
			$api->delete_order($order_id);
		}

		$window = $allowed ? self::build_move_in_date_window($allowed, $tz) : self::get_move_in_date_window();
		if ($allowed) {
			$move_in_ttl = defined('STORA_MOVE_IN_DATES_CACHE_TTL') ? (int) STORA_MOVE_IN_DATES_CACHE_TTL : 3600;
			Stora_Cache::set($cache_key, $window, $move_in_ttl);
		}

		return $window;
	}

	/**
	 * @return array{value: string, label: string, month: string, day: int, weekday: string}
	 */
	private static function format_move_in_date_row(DateTimeImmutable $date) {
		return [
			'value' => $date->format('Y-m-d'),
			'label' => $date->format('D j M'),
			'month' => $date->format('Y-m'),
			'day' => (int) $date->format('j'),
			'weekday' => $date->format('D'),
		];
	}

	private static function order_accepts_starts_at($api, $order_id) {
		if (!($api instanceof Stora_API)) {
			return false;
		}

		$validated = $api->validate_order($order_id);
		return is_array($validated) && isset($validated['order']) && is_array($validated['order']);
	}

	/**
	 * @param string $value
	 * @param array<string, string> $options
	 */
	private function sanitize_enum_choice($value, $options) {
		$value = sanitize_text_field((string) wp_unslash($value));
		return isset($options[$value]) ? $value : '';
	}

	/**
	 * Build a Stora contact payload from checkout POST fields.
	 *
	 * @return array<string,mixed>
	 */
	private function build_contact_payload_from_post($full, $email, $phone) {
		$options = self::checkout_select_options();

		$line_1 = trim(sanitize_text_field((string) wp_unslash($_POST['address_line_1'] ?? '')));
		$line_2 = trim(sanitize_text_field((string) wp_unslash($_POST['address_line_2'] ?? '')));
		$city = trim(sanitize_text_field((string) wp_unslash($_POST['address_city'] ?? '')));
		$postal_code = trim(sanitize_text_field((string) wp_unslash($_POST['address_postcode'] ?? '')));
		$country = $this->sanitize_enum_choice($_POST['address_country'] ?? 'GB', $options['countries']);
		if ($country === '') {
			$country = 'GB';
		}

		$use_case = $this->sanitize_enum_choice($_POST['use_case'] ?? '', $options['use_case']);
		$type = $this->sanitize_enum_choice($_POST['storage_type'] ?? '', $options['type']);
		$marketing_source = $this->sanitize_enum_choice($_POST['marketing_source'] ?? '', $options['marketing_source']);

		$payload = [
			'full_name' => $full,
			'email' => $email,
			'phone_number' => self::normalize_phone_e164($phone),
			'source' => 'booking',
		];

		if ($use_case !== '') {
			$payload['use_case'] = $use_case;
		}
		if ($type !== '') {
			$payload['type'] = $type;
		}
		if ($marketing_source !== '') {
			$payload['marketing_source'] = $marketing_source;
		}

		if ($line_1 !== '' || $line_2 !== '' || $city !== '' || $postal_code !== '') {
			$address = [
				'line_1' => $line_1,
				'city' => $city,
				'postal_code' => $postal_code,
				'country_alpha2' => $country,
			];
			if ($line_2 !== '') {
				$address['line_2'] = $line_2;
			}
			$payload['address'] = $address;
		}

		return $payload;
	}

	/**
	 * Validate required "Tell us about you" fields.
	 */
	private function validate_about_you_fields() {
		$options = self::checkout_select_options();

		$line_1 = trim(sanitize_text_field((string) wp_unslash($_POST['address_line_1'] ?? '')));
		$city = trim(sanitize_text_field((string) wp_unslash($_POST['address_city'] ?? '')));
		$postal_code = trim(sanitize_text_field((string) wp_unslash($_POST['address_postcode'] ?? '')));
		$country = $this->sanitize_enum_choice($_POST['address_country'] ?? '', $options['countries']);
		$use_case = $this->sanitize_enum_choice($_POST['use_case'] ?? '', $options['use_case']);
		$type = $this->sanitize_enum_choice($_POST['storage_type'] ?? '', $options['type']);
		$marketing_source = $this->sanitize_enum_choice($_POST['marketing_source'] ?? '', $options['marketing_source']);

		if ($line_1 === '') {
			wp_die('Please enter your address.');
		}
		if ($city === '') {
			wp_die('Please enter your city or town.');
		}
		if ($postal_code === '') {
			wp_die('Please enter your postcode.');
		}
		if ($country === '') {
			wp_die('Please select your country.');
		}
		if ($use_case === '') {
			wp_die('Please tell us what you will use storage for.');
		}
		if ($type === '') {
			wp_die('Please select a storage type.');
		}
		if ($marketing_source === '') {
			wp_die('Please tell us how you heard about us.');
		}
	}

	private function validate_booking_consent() {
		$consent = isset($_POST['booking_consent']) ? sanitize_text_field(wp_unslash($_POST['booking_consent'])) : '';
		if ($consent !== '1') {
			wp_die('Please agree to the terms and privacy policy before continuing.');
		}
	}

	/**
	 * Metadata recorded on the Stora contact and order when booking consent is given.
	 *
	 * @return array<string, string>
	 */
	private function build_booking_consent_metadata() {
		$accepted_at = function_exists('wp_date') ? wp_date('c') : gmdate('c');

		$metadata = [
			'terms_accepted' => 'true',
			'terms_accepted_at' => (string) $accepted_at,
			'stored_value_confirmed' => 'true',
		];

		if (defined('STORA_TERMS_URL')) {
			$terms_url = esc_url_raw(trailingslashit((string) STORA_TERMS_URL));
			if ($terms_url !== '') {
				$metadata['terms_url'] = $terms_url;
			}
		}

		if (defined('STORA_PRIVACY_URL')) {
			$privacy_url = esc_url_raw(trailingslashit((string) STORA_PRIVACY_URL));
			if ($privacy_url !== '') {
				$metadata['privacy_url'] = $privacy_url;
			}
		}

		return $metadata;
	}

	/**
	 * Resolve contact for order creation: inline payload or existing contact id (with update).
	 *
	 * @return array<string,mixed>
	 */
	private function resolve_order_contact($api, $contact_payload, $email) {
		if (!($api instanceof Stora_API)) {
			return $contact_payload;
		}

		$contact_id = $this->find_contact_id_by_email($api, $email);
		if ($contact_id === '') {
			return $contact_payload;
		}

		// Keep email on PATCH payloads; Stora uses id on the order either way.
		$update_payload = $contact_payload;
		unset($update_payload['email']);
		$api->update_contact($contact_id, $update_payload);

		return ['id' => $contact_id];
	}

	/**
	 * Normalise a phone number to E.164 (e.g. +447403940600) so Stora accepts it.
	 *
	 * Stora requires a country code. Numbers entered nationally (leading 0) are
	 * assumed to belong to the default country code (44 = UK unless overridden).
	 */
	public static function normalize_phone_e164($raw, $default_cc = null) {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return '';
		}

		if ($default_cc === null) {
			$default_cc = defined('STORA_DEFAULT_PHONE_COUNTRY_CODE')
				? (string) STORA_DEFAULT_PHONE_COUNTRY_CODE
				: '44';
		}
		$default_cc = preg_replace('/\D+/', '', (string) $default_cc);

		$has_plus = strpos($raw, '+') === 0;
		$digits = preg_replace('/\D+/', '', $raw);
		if ($digits === '') {
			return '';
		}

		if ($has_plus) {
			return '+' . $digits;
		}

		// International prefix "00" -> "+".
		if (strpos($digits, '00') === 0) {
			return '+' . substr($digits, 2);
		}

		// National format with trunk prefix (leading 0) -> default country code.
		if (strpos($digits, '0') === 0) {
			return '+' . $default_cc . ltrim($digits, '0');
		}

		// Already starts with the default country code (e.g. 447...).
		if ($default_cc !== '' && strpos($digits, $default_cc) === 0) {
			return '+' . $digits;
		}

		// Bare national number without trunk prefix.
		return '+' . $default_cc . $digits;
	}

	/**
	 * Compare mobile numbers ignoring spaces and non-digits.
	 */
	private function phones_match($a, $b) {
		$na = preg_replace('/\D+/', '', (string) $a);
		$nb = preg_replace('/\D+/', '', (string) $b);
		return $na !== '' && $na === $nb;
	}

	/**
	 * Validate checkout email value (format only).
	 */
	private function is_valid_checkout_email($raw) {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return false;
		}

		return (bool) is_email($raw);
	}

	/**
	 * Validate checkout phone value (minimum digit length).
	 */
	private function is_valid_checkout_phone($raw) {
		$digits = preg_replace('/\D+/', '', (string) $raw);
		$len = strlen($digits);

		return $len >= 10 && $len <= 15;
	}

	/**
	 * Validate contact fields required for order total preview.
	 *
	 * @param bool $for_coupon When true, return coupon-apply-specific messages.
	 * @return array{full: string, email: string, phone: string}|array{error: string}
	 */
	private function validate_preview_contact_fields($for_coupon = false) {
		$full = isset($_POST['full_name']) ? trim(sanitize_text_field(wp_unslash($_POST['full_name']))) : '';
		$email_raw = isset($_POST['email']) ? trim((string) wp_unslash($_POST['email'])) : '';
		$phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

		if ($for_coupon) {
			if ($full === '' || $email_raw === '' || trim($phone) === '') {
				return ['error' => 'Please enter your name, email, and phone number before applying a coupon.'];
			}
			if (!$this->is_valid_checkout_email($email_raw)) {
				return ['error' => 'Please enter a valid email address before applying the coupon.'];
			}
			if (!$this->is_valid_checkout_phone($phone)) {
				return ['error' => 'Please enter a valid phone number before applying the coupon.'];
			}
		} else {
			if ($full === '') {
				return ['error' => 'Please enter your full name.'];
			}
			if ($email_raw === '') {
				return ['error' => 'Please enter your email address.'];
			}
			if (!$this->is_valid_checkout_email($email_raw)) {
				return ['error' => 'Please enter a valid email address.'];
			}
			if (trim($phone) === '') {
				return ['error' => 'Please enter your mobile number.'];
			}
			if (!$this->is_valid_checkout_phone($phone)) {
				return ['error' => 'Please enter a valid phone number.'];
			}
		}

		return [
			'full' => $full,
			'email' => sanitize_email($email_raw),
			'phone' => $phone,
		];
	}

	/**
	 * Standard message when a coupon code cannot be found or applied.
	 */
	private function coupon_error_invalid_code() {
		return 'This coupon code is not valid. Please check the code and try again.';
	}

	/**
	 * Standard message when coupon validation hits a server/API issue.
	 */
	private function coupon_error_api_unavailable($api = null) {
		if ($api instanceof Stora_API && $api->is_rate_limited()) {
			return 'We\'re experiencing high demand right now. Please wait a minute and try again.';
		}
		return 'We couldn\'t validate the coupon right now. Please try again in a moment.';
	}

	/**
	 * Format a minor-unit amount using a formatted money string as reference.
	 */
	private function format_money_minor($amount_minor, $formatted_reference = '') {
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
	 * Validate checkout email + confirmation fields.
	 *
	 * @return array{email: string, email_confirm: string}
	 */
	private function validate_checkout_emails() {
		$email_raw = isset($_POST['email']) ? trim((string) wp_unslash($_POST['email'])) : '';
		$email_confirm_raw = isset($_POST['email_confirm']) ? trim((string) wp_unslash($_POST['email_confirm'])) : '';

		if ($email_raw === '') {
			wp_die('Please enter your email address.');
		}
		if (!$this->is_valid_checkout_email($email_raw)) {
			wp_die('Please enter a valid email address.');
		}

		if ($email_confirm_raw === '') {
			wp_die('Please confirm your email address.');
		}
		if (!$this->is_valid_checkout_email($email_confirm_raw)) {
			wp_die('Please enter a valid confirmation email address.');
		}

		$email = sanitize_email($email_raw);
		$email_confirm = sanitize_email($email_confirm_raw);
		if (strtolower($email) !== strtolower($email_confirm)) {
			wp_die('Email addresses do not match. Please check and try again.');
		}

		return [
			'email' => $email,
			'email_confirm' => $email_confirm,
		];
	}

	/**
	 * Find a unit in the cached /units payload by id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_unit_by_id($units_payload, $unit_id) {
		$unit_id = (string) $unit_id;
		if ($unit_id === '' || !is_array($units_payload)) {
			return null;
		}

		$list = [];
		if (isset($units_payload['data']) && is_array($units_payload['data'])) {
			$list = $units_payload['data'];
		} elseif (isset($units_payload[0]) && is_array($units_payload[0])) {
			$list = $units_payload;
		}

		foreach ($list as $u) {
			if (!is_array($u)) {
				continue;
			}
			if ((string) ($u['id'] ?? '') === $unit_id) {
				return $u;
			}
		}

		return null;
	}

	/**
	 * Pick a billing period + integer amount from unit type prices.
	 *
	 * @return array{billing_period:string, amount:int}|null
	 */
	private function pick_billing_period_and_amount($unit_type, $site_id = '') {
		if (!is_array($unit_type) || empty($unit_type['prices']) || !is_array($unit_type['prices'])) {
			return null;
		}

		$preferred = ['monthly', 'every_four_weeks', 'weekly', 'yearly', 'every_three_months', 'every_six_months'];
		$by_period = [];

		foreach ($unit_type['prices'] as $p) {
			if (!is_array($p)) {
				continue;
			}
			$period = (string) ($p['billing_period'] ?? '');
			$amount = $p['price']['amount'] ?? null;
			if ($period === '') {
				continue;
			}
			if (!is_int($amount)) {
				if (is_numeric($amount) && (string) (int) $amount === (string) $amount) {
					$amount = (int) $amount;
				} else {
					continue;
				}
			}
			if ($amount <= 0) {
				continue;
			}
			$by_period[$period] = $amount;
		}

		$picked_period = '';
		foreach ($preferred as $period) {
			if (isset($by_period[$period])) {
				$picked_period = $period;
				break;
			}
		}
		if ($picked_period === '') {
			// Fallback to the first available period.
			foreach ($by_period as $period => $amount) {
				$picked_period = (string) $period;
				break;
			}
		}
		if ($picked_period === '') {
			return null;
		}

		$amount = (int) $by_period[$picked_period];

		// Prefer the dynamic "Billed On Storefront" price so the amount charged
		// matches Stora's hosted storefront; fall back to the base API price when
		// the storefront price can't be resolved (e.g. it can't be reached).
		if (class_exists('Stora_API')) {
			$dynamic = Stora_API::resolve_unit_type_dynamic_amount_minor_for_period($unit_type, $picked_period, $site_id);
			if ($dynamic !== null && $dynamic > 0) {
				$amount = (int) $dynamic;
			}
		}

		return ['billing_period' => $picked_period, 'amount' => $amount];
	}

	/**
	 * Resolve coupon id for an order: manual code takes precedence, else unit-type promotion.
	 *
	 * @param array<string,mixed> $unit_type
	 * @return string|null Empty string when no coupon; null when manual code is invalid.
	 */
	private function resolve_order_coupon_id($api, $unit_type, $coupon_code = '') {
		$coupon_code = strtoupper(trim((string) $coupon_code));
		if ($coupon_code !== '') {
			$coupon_id = $this->find_coupon_id_by_code($api, $coupon_code);
			return $coupon_id !== '' ? $coupon_id : null;
		}

		return Stora_API::resolve_unit_type_promotion_coupon_id($unit_type);
	}

	/**
	 * Apply a manual coupon or auto-applied unit-type promotion to a draft order.
	 *
	 * @param array<string,mixed> $unit_type
	 */
	private function apply_order_coupon($api, $order_id, $unit_type, $coupon_code = '') {
		$coupon_id = $this->resolve_order_coupon_id($api, $unit_type, $coupon_code);
		if ($coupon_code !== '' && $coupon_id === null) {
			return false;
		}
		if ($coupon_id !== '') {
			$api->update_order($order_id, ['coupon' => ['id' => $coupon_id]]);
		}

		return true;
	}

	/**
	 * Find a public protection level by id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_protection_level_by_id($api, $protection_level_id, $site_id = '') {
		if (!($api instanceof Stora_API)) {
			return null;
		}
		$protection_level_id = (string) $protection_level_id;
		if ($protection_level_id === '') {
			return null;
		}

		foreach ($api->get_protection_levels($site_id) as $level) {
			if (!is_array($level)) {
				continue;
			}
			if ((string) ($level['id'] ?? '') === $protection_level_id) {
				return $level;
			}
		}

		return null;
	}

	/**
	 * Validate protection level selection from checkout.
	 *
	 * @return array<string,mixed>
	 */
	private function validate_protection_level($api, $protection_level_id, $billing_period, $site_id = '') {
		$protection_level_id = sanitize_text_field((string) wp_unslash($protection_level_id));
		if ($protection_level_id === '') {
			wp_die('Please select a protection cover level.');
		}

		$level = $this->find_protection_level_by_id($api, $protection_level_id, $site_id);
		if (!is_array($level)) {
			wp_die('Please select a valid protection cover level.');
		}

		$level_site_id = Stora_API::protection_level_site_id($level);
		if ($site_id === '' || $level_site_id !== (string) $site_id) {
			wp_die('Please select a valid protection cover level.');
		}

		$price_pick = Stora_API::pick_protection_price_for_period($level, $billing_period);
		if (!$price_pick) {
			wp_die('Unable to price the selected protection level.');
		}

		return [
			'id' => (string) ($level['id'] ?? ''),
			'amount' => (int) $price_pick['amount'],
		];
	}

	private function format_starts_at($move_in) {
		$move_in = trim((string) $move_in);
		if ($move_in === '') {
			return 'now';
		}
		// If it's a date (YYYY-MM-DD), convert to a date-time.
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $move_in)) {
			return $move_in . 'T00:00:00Z';
		}
		return $move_in;
	}

	/**
	 * Validate move-in date is within the allowed booking window.
	 */
	private function validate_move_in_date($move_in, $api = null, $unit = null, $unit_type = null, $billing_period = '', $amount_minor = 0) {
		$move_in = trim((string) $move_in);
		if ($move_in === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $move_in)) {
			wp_die('Please choose a move-in date.');
		}

		$allowed = [];
		if ($api instanceof Stora_API && is_array($unit) && is_array($unit_type)) {
			$site_id = (string) ($unit['site']['id'] ?? '');
			$unit_type_id = (string) ($unit_type['id'] ?? '');
			if ($site_id !== '' && $unit_type_id !== '' && $billing_period !== '' && (int) $amount_minor > 0) {
				$window = self::resolve_move_in_dates_for_checkout($api, $site_id, $unit_type_id, $billing_period, (int) $amount_minor);
				$allowed = array_column($window['dates'], 'value');
			}
		}
		if (!$allowed) {
			$allowed = array_column(self::get_move_in_date_window()['dates'], 'value');
		}
		if (!in_array($move_in, $allowed, true)) {
			wp_die('Please choose a move-in date within the available range.');
		}

		return $move_in;
	}

	private function booking_failed_message($api) {
		if ($api instanceof Stora_API && $api->is_rate_limited()) {
			$rate_limited = 'We\'re experiencing high demand right now. Please wait a minute and try again.';
			$is_admin = function_exists('is_user_logged_in')
				&& is_user_logged_in()
				&& function_exists('current_user_can')
				&& current_user_can('manage_options');
			if (!$is_admin) {
				return $rate_limited;
			}
			return $rate_limited . "\n\nStora debug:\nHTTP 429 too_many_requests";
		}

		$base = 'Booking failed. Please wait a moment and try again.';
		if (!($api instanceof Stora_API)) {
			return $base;
		}

		$is_admin = function_exists('is_user_logged_in')
			&& is_user_logged_in()
			&& function_exists('current_user_can')
			&& current_user_can('manage_options');

		if (!$is_admin) {
			return $base;
		}

		$base = 'Booking failed.';

		$debug = $api->get_last_debug();
		if (!is_array($debug)) {
			return $base;
		}

		$extra = [];
		if (!empty($debug['response_code'])) {
			$extra[] = 'HTTP ' . (string) $debug['response_code'];
		}
		if (!empty($debug['wp_error'])) {
			$extra[] = (string) $debug['wp_error'];
		}
		if (!empty($debug['body_snippet'])) {
			$extra[] = (string) $debug['body_snippet'];
		}

		return $extra ? ($base . "\n\nStora debug:\n" . implode("\n", $extra)) : $base;
	}

	/**
	 * Find a contact id by exact email.
	 */
	private function find_contact_id_by_email($api, $email) {
		if (!($api instanceof Stora_API)) {
			return '';
		}
		$email = (string) $email;
		if ($email === '') {
			return '';
		}

		$res = $api->list_contacts(['email' => $email, 'limit' => 1]);
		$list = null;
		if (is_array($res) && isset($res['data']) && is_array($res['data'])) {
			$list = $res['data'];
		} elseif (is_array($res) && isset($res['contacts']) && is_array($res['contacts'])) {
			$list = $res['contacts'];
		}
		if (!$list) {
			return '';
		}

		$contact = $list[0] ?? null;
		if (!is_array($contact)) {
			return '';
		}

		return (string) ($contact['id'] ?? '');
	}

	/**
	 * Cache duration for the Stora coupon code index.
	 */
	private function get_coupon_lookup_cache_ttl() {
		return defined('STORA_COUPON_LOOKUP_TTL') ? (int) STORA_COUPON_LOOKUP_TTL : (10 * MINUTE_IN_SECONDS);
	}

	private function throttle_ajax_request($action) {
		$max = defined('STORA_AJAX_RATE_LIMIT') ? (int) STORA_AJAX_RATE_LIMIT : 30;
		$max = max(5, $max);
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';
		$key = 'stora_throttle_' . md5($action . '|' . $ip);
		$count = (int) get_transient($key);
		if ($count >= $max) {
			wp_send_json_error(['message' => $this->coupon_error_api_unavailable()], 429);
		}
		set_transient($key, $count + 1, MINUTE_IN_SECONDS);
	}

	/**
	 * Remove a temporary draft order created only for checkout total previews.
	 */
	private function discard_preview_order($api, $order_id) {
		$order_id = trim((string) $order_id);
		if ($order_id === '' || !($api instanceof Stora_API)) {
			return;
		}

		$api->delete_order($order_id);
	}

	/**
	 * Build a map of uppercase coupon code => coupon record.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	private function get_coupon_lookup_index($api) {
		$cache_key = 'stora_coupon_lookup_v1';
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		if (!($api instanceof Stora_API)) {
			return [];
		}

		$index = [];
		$page = 1;
		$limit = 100;
		$max_pages = 20;

		while ($page <= $max_pages) {
			$res = $api->list_coupons(['page' => $page, 'limit' => $limit, 'sort_by' => 'created_at', 'sort_direction' => 'DESC']);
			$list = null;
			if (is_array($res) && isset($res['coupons']) && is_array($res['coupons'])) {
				$list = $res['coupons'];
			} elseif (is_array($res) && isset($res['data']) && is_array($res['data'])) {
				$list = $res['data'];
			}

			if (!$list) {
				break;
			}

			foreach ($list as $coupon) {
				if (!is_array($coupon)) {
					continue;
				}
				$coupon_code = $coupon['code'] ?? null;
				if (!is_string($coupon_code) || $coupon_code === '') {
					continue;
				}
				$index[strtoupper($coupon_code)] = $coupon;
			}

			if (count($list) < $limit) {
				break;
			}

			$page++;
		}

		set_transient($cache_key, $index, $this->get_coupon_lookup_cache_ttl());

		return $index;
	}

	/**
	 * Validate coupon metadata before creating a priced order.
	 *
	 * @param array<string,mixed> $coupon
	 * @return string|null Error message or null when valid.
	 */
	private function validate_coupon_metadata($coupon, $billing_period) {
		$auto = isset($coupon['auto_apply_to']) && is_array($coupon['auto_apply_to']) ? $coupon['auto_apply_to'] : [];
		$applies_to_unit_types = !empty($auto['unit_types']);
		$applies_to_subscription = !empty($auto['subscriptions']);
		if (!$applies_to_unit_types && !$applies_to_subscription) {
			return 'This coupon cannot be applied to storage units.';
		}

		$coupon_period = $coupon['billing_period'] ?? null;
		if (is_string($coupon_period) && $coupon_period !== '' && $billing_period !== '' && $coupon_period !== $billing_period) {
			return 'This coupon is not valid for the selected billing period.';
		}

		$max_redemptions = $coupon['max_redemptions'] ?? null;
		if (is_int($max_redemptions) && $max_redemptions === 0) {
			return 'This coupon is no longer available.';
		}

		return null;
	}

	/**
	 * Build a user-facing success message for an applied coupon.
	 *
	 * @param array<string,mixed> $coupon
	 */
	private function build_coupon_success_message($coupon) {
		$code = strtoupper(trim((string) ($coupon['code'] ?? '')));
		$label = (string) ($coupon['name'] ?? $code);
		$type = (string) ($coupon['type'] ?? '');
		$discount = '';
		if ($type === 'percentage' && isset($coupon['percent']) && is_numeric($coupon['percent'])) {
			$discount = (string) $coupon['percent'] . '% off';
		} elseif ($type === 'amount' && isset($coupon['amount']) && is_array($coupon['amount'])) {
			$discount = (string) ($coupon['amount']['formatted'] ?? '');
		}

		return $discount !== '' ? ('Coupon applied: ' . $label . ' (' . $discount . ').') : ('Coupon applied: ' . $label . '.');
	}

	/**
	 * Find a coupon id by coupon code (non-public coupons only).
	 *
	 * @return string Coupon id (cpn_*) or empty string when not found.
	 */
	private function find_coupon_id_by_code($api, $code) {
		$coupon = $this->find_coupon_by_code($api, $code);

		return is_array($coupon) ? (string) ($coupon['id'] ?? '') : '';
	}

	/**
	 * Find full coupon record by coupon code.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_coupon_by_code($api, $code) {
		if (!($api instanceof Stora_API)) {
			return null;
		}
		$code = strtoupper(trim((string) $code));
		if ($code === '') {
			return null;
		}

		$index = $this->get_coupon_lookup_index($api);

		return isset($index[$code]) && is_array($index[$code]) ? $index[$code] : null;
	}

	public function validate_coupon() {
		$this->throttle_ajax_request('validate_coupon');

		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'stora_coupon_nonce')) {
			wp_send_json_error(['message' => 'Security check failed.'], 403);
		}

		$code = isset($_POST['coupon_code']) ? sanitize_text_field(wp_unslash($_POST['coupon_code'])) : '';
		$code = strtoupper(trim((string) $code));
		if ($code === '') {
			wp_send_json_error(['message' => 'Please enter a coupon code.'], 400);
		}

		$unit_id = isset($_POST['unit_id']) ? sanitize_text_field(wp_unslash($_POST['unit_id'])) : '';
		if ($unit_id === '') {
			wp_send_json_error(['message' => 'Missing unit selection.'], 400);
		}

		$api = new Stora_API();

		$unit = $this->find_unit_by_id($api->get_units(), $unit_id);
		if (!is_array($unit) || empty($unit['unit_type']) || !is_array($unit['unit_type'])) {
			wp_send_json_error(['message' => $this->coupon_error_api_unavailable($api)], 400);
		}

		$unit_type_id = (string) ($unit['unit_type']['id'] ?? '');
		$unit_type_payload = $unit_type_id !== '' ? $api->get_unit_type($unit_type_id) : null;
		$unit_type = is_array($unit_type_payload) && isset($unit_type_payload['unit_type']) && is_array($unit_type_payload['unit_type'])
			? $unit_type_payload['unit_type']
			: (is_array($unit['unit_type']) ? $unit['unit_type'] : []);

		$price_pick = $this->pick_billing_period_and_amount($unit_type, (string) ($unit['site']['id'] ?? ''));
		$billing_period = $price_pick ? (string) $price_pick['billing_period'] : '';

		$coupon = $this->find_coupon_by_code($api, $code);
		if (!is_array($coupon) || empty($coupon['id'])) {
			wp_send_json_error(['message' => $this->coupon_error_invalid_code()], 404);
		}

		$metadata_error = $this->validate_coupon_metadata($coupon, $billing_period);
		if ($metadata_error !== null) {
			wp_send_json_error(['message' => $metadata_error], 400);
		}

		wp_send_json_success([
			'message' => $this->build_coupon_success_message($coupon),
			'coupon_id' => (string) $coupon['id'],
		]);
	}

	public function preview_totals() {
		$this->throttle_ajax_request('preview_totals');

		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'stora_coupon_nonce')) {
			wp_send_json_error(['message' => 'Security check failed.'], 403);
		}

		$unit_id = isset($_POST['unit_id']) ? sanitize_text_field(wp_unslash($_POST['unit_id'])) : '';
		if ($unit_id === '') {
			wp_send_json_error(['message' => 'Missing unit selection.'], 400);
		}

		$coupon_code = strtoupper(trim(sanitize_text_field(wp_unslash($_POST['coupon_code'] ?? ''))));
		$for_coupon = $coupon_code !== '';

		$contact = $this->validate_preview_contact_fields($for_coupon);
		if (isset($contact['error'])) {
			wp_send_json_error(['message' => (string) $contact['error']], 400);
		}
		$full = (string) $contact['full'];
		$email = (string) $contact['email'];
		$phone = (string) $contact['phone'];

		$move_in = isset($_POST['move_in']) ? sanitize_text_field(wp_unslash($_POST['move_in'])) : '';

		$api = new Stora_API();
		$unit = $this->find_unit_by_id($api->get_units(), $unit_id);
		if (!is_array($unit)) {
			wp_send_json_error(['message' => $for_coupon ? $this->coupon_error_api_unavailable($api) : 'Unable to find unit.'], 400);
		}

		$site_id = (string) ($unit['site']['id'] ?? '');
		$unit_type_id = (string) ($unit['unit_type']['id'] ?? '');
		if ($site_id === '' || $unit_type_id === '') {
			wp_send_json_error(['message' => $for_coupon ? $this->coupon_error_api_unavailable($api) : 'Unable to price this unit.'], 400);
		}

		$unit_type_payload = $api->get_unit_type($unit_type_id);
		$unit_type = is_array($unit_type_payload) && isset($unit_type_payload['unit_type']) && is_array($unit_type_payload['unit_type'])
			? $unit_type_payload['unit_type']
			: [];
		$price_pick = $this->pick_billing_period_and_amount($unit_type, $site_id);
		if (!$price_pick) {
			wp_send_json_error(['message' => $for_coupon ? $this->coupon_error_api_unavailable($api) : 'Unable to price this unit.'], 400);
		}

		$coupon_record = null;
		if ($for_coupon) {
			$coupon_record = $this->find_coupon_by_code($api, $coupon_code);
			if (!is_array($coupon_record) || empty($coupon_record['id'])) {
				wp_send_json_error(['message' => $this->coupon_error_invalid_code()], 404);
			}

			$metadata_error = $this->validate_coupon_metadata($coupon_record, (string) $price_pick['billing_period']);
			if ($metadata_error !== null) {
				wp_send_json_error(['message' => $metadata_error], 400);
			}
		}

		$order_payload = [
			'site' => ['id' => $site_id],
			'payment_method' => 'card',
			'starts_at' => $this->format_starts_at($move_in),
			'billing_period' => (string) $price_pick['billing_period'],
			'contact' => [
				'full_name' => $full,
				'email' => $email,
				'phone_number' => self::normalize_phone_e164($phone),
				'source' => 'booking',
			],
			'line_items' => [
				[
					'type' => 'unit_type',
					'quantity' => 1,
					'price' => ['amount' => (int) $price_pick['amount']],
					'item' => ['id' => $unit_type_id],
				],
			],
		];

		$protection_level_id = isset($_POST['protection_level_id']) ? sanitize_text_field(wp_unslash($_POST['protection_level_id'])) : '';
		if ($protection_level_id !== '') {
			$level = $this->find_protection_level_by_id($api, $protection_level_id, $site_id);
			$level_site_id = is_array($level) ? Stora_API::protection_level_site_id($level) : '';
			$price_protection = is_array($level)
				? Stora_API::pick_protection_price_for_period($level, (string) $price_pick['billing_period'])
				: null;
			if (is_array($level) && $level_site_id === $site_id && is_array($price_protection) && (string) ($level['id'] ?? '') !== '') {
				$order_payload['line_items'][] = [
					'type' => 'protection',
					'quantity' => 1,
					'price' => ['amount' => (int) $price_protection['amount']],
					'item' => ['id' => (string) $level['id']],
				];
			}
		}

		$order_response = $api->create_order($order_payload);
		if (!is_array($order_response)) {
			// Mirror main checkout behavior: if Stora rejects contact by email uniqueness,
			// retry using existing contact.id.
			$debug = $api->get_last_debug();
			$snippet = is_array($debug) ? (string) ($debug['body_snippet'] ?? '') : '';
			if ($snippet !== '' && stripos($snippet, 'Email has already been taken') !== false) {
				$contact_id = $this->find_contact_id_by_email($api, $email);
				if ($contact_id !== '') {
					$order_payload['contact'] = ['id' => $contact_id];
					$order_response = $api->create_order($order_payload);
				}
			}
		}

		$order_id = is_array($order_response) && isset($order_response['order']) && is_array($order_response['order'])
			? (string) ($order_response['order']['id'] ?? '')
			: '';
		if ($order_id === '') {
			$msg = $for_coupon ? $this->coupon_error_api_unavailable($api) : 'Unable to calculate total right now.';
			$is_admin = !$for_coupon
				&& function_exists('is_user_logged_in')
				&& is_user_logged_in()
				&& function_exists('current_user_can')
				&& current_user_can('manage_options');
			if ($is_admin) {
				$debug = $api->get_last_debug();
				if (is_array($debug)) {
					$extra = [];
					if (!empty($debug['response_code'])) {
						$extra[] = 'HTTP ' . (string) $debug['response_code'];
					}
					if (!empty($debug['wp_error'])) {
						$extra[] = (string) $debug['wp_error'];
					}
					if (!empty($debug['body_snippet'])) {
						$extra[] = (string) $debug['body_snippet'];
					}
					if ($extra) {
						$msg .= ' (Stora debug: ' . implode(' | ', $extra) . ')';
					}
				}
			}
			wp_send_json_error(['message' => $msg], 500);
		}

		// Ignore unknown manual codes during background total refresh; fail clearly during coupon apply.
		if ($coupon_code !== '' && $this->find_coupon_id_by_code($api, $coupon_code) === '') {
			if ($for_coupon) {
				$this->discard_preview_order($api, $order_id);
				wp_send_json_error(['message' => $this->coupon_error_invalid_code()], 400);
			}
			$coupon_code = '';
		}

		if (!$this->apply_order_coupon($api, $order_id, $unit_type, $coupon_code)) {
			$this->discard_preview_order($api, $order_id);
			wp_send_json_error(['message' => $for_coupon ? $this->coupon_error_invalid_code() : 'Coupon code is invalid.'], 400);
		}

		$validated = $api->validate_order($order_id);
		$order = is_array($validated) && isset($validated['order']) && is_array($validated['order'])
			? $validated['order']
			: (is_array($order_response) && isset($order_response['order']) && is_array($order_response['order']) ? $order_response['order'] : null);

		if (!is_array($order)) {
			$this->discard_preview_order($api, $order_id);
			wp_send_json_error(['message' => $for_coupon ? $this->coupon_error_api_unavailable($api) : 'Unable to calculate total right now.'], 500);
		}

		$get_money = static function ($v) {
			if (!is_array($v)) {
				return '';
			}
			$f = $v['formatted'] ?? '';
			return is_string($f) ? $f : '';
		};
		$get_amount = static function ($v) {
			if (!is_array($v)) {
				return null;
			}
			$a = $v['amount'] ?? null;
			return is_numeric($a) ? (int) $a : null;
		};

		$subtotal = $get_money($order['subtotal'] ?? null);
		$total = $get_money($order['total'] ?? null);
		$discount = $get_money($order['total_discount'] ?? null);
		$tax = $get_money($order['tax'] ?? null);
		$subtotal_amount = $get_amount($order['subtotal'] ?? null);
		$total_amount = $get_amount($order['total'] ?? null);
		$discount_amount = $get_amount($order['total_discount'] ?? null);

		if (($discount_amount === null || $discount_amount <= 0) && $subtotal_amount !== null && $total_amount !== null && $subtotal_amount > $total_amount) {
			$discount_amount = $subtotal_amount - $total_amount;
			$discount = $this->format_money_minor($discount_amount, $subtotal);
		}

		$discount_label = 'Discount';
		if ($coupon_code !== '' && is_array($coupon_record)) {
			$coupon_label = trim((string) ($coupon_record['name'] ?? ''));
			$discount_label = $coupon_label !== '' ? $coupon_label : $coupon_code;
		} else {
			$promotion = Stora_API::resolve_unit_type_promotion($unit_type);
			$discount_label = $promotion ? Stora_API::format_unit_type_promotion_badge($promotion) : 'Discount';
		}

		$response = [
			'subtotal' => $subtotal,
			'total' => $total,
			'total_amount' => $total_amount,
			'subtotal_amount' => $subtotal_amount,
			'discount' => $discount,
			'discount_amount' => $discount_amount,
			'discount_label' => $discount_label,
			'tax' => $tax,
		];
		if ($for_coupon && is_array($coupon_record)) {
			$response['message'] = $this->build_coupon_success_message($coupon_record);
		}

		$this->discard_preview_order($api, $order_id);
		wp_send_json_success($response);
	}

	public function move_in_dates() {
		$this->throttle_ajax_request('move_in_dates');

		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'stora_coupon_nonce')) {
			wp_send_json_error(['message' => 'Security check failed.'], 403);
		}

		$unit_id = isset($_POST['unit_id']) ? sanitize_text_field(wp_unslash($_POST['unit_id'])) : '';
		if ($unit_id === '') {
			wp_send_json_error(['message' => 'Missing unit selection.'], 400);
		}

		$api = new Stora_API();
		$unit = $this->find_unit_by_id($api->get_units(), $unit_id);
		if (!is_array($unit)) {
			wp_send_json_error(['message' => 'Unable to load move-in dates right now.'], 400);
		}

		$site_id = (string) ($unit['site']['id'] ?? '');
		$unit_type_id = (string) ($unit['unit_type']['id'] ?? '');
		if ($site_id === '' || $unit_type_id === '') {
			wp_send_json_error(['message' => 'Unable to load move-in dates for this unit.'], 400);
		}

		$unit_type_payload = $api->get_unit_type($unit_type_id);
		$unit_type = is_array($unit_type_payload) && isset($unit_type_payload['unit_type']) && is_array($unit_type_payload['unit_type'])
			? $unit_type_payload['unit_type']
			: (is_array($unit['unit_type']) ? $unit['unit_type'] : []);

		$price_pick = $this->pick_billing_period_and_amount($unit_type, $site_id);
		if (!$price_pick) {
			wp_send_json_error(['message' => 'Unable to load move-in dates for this unit.'], 400);
		}

		$cached = self::get_cached_move_in_date_window($site_id, $unit_type_id, (string) $price_pick['billing_period']);
		if (is_array($cached)) {
			wp_send_json_success(['dates' => $cached['dates']]);
		}

		$window = self::resolve_move_in_dates_for_checkout(
			$api,
			$site_id,
			$unit_type_id,
			(string) $price_pick['billing_period'],
			(int) $price_pick['amount']
		);

		$dates = isset($window['dates']) && is_array($window['dates']) ? $window['dates'] : [];
		if (!$dates) {
			$message = $api->is_rate_limited()
				? 'We\'re experiencing high demand right now. Please wait a minute and try again.'
				: 'Move-in dates are unavailable right now. Please try again in a moment.';
			wp_send_json_error(['message' => $message], $api->is_rate_limited() ? 429 : 500);
		}

		wp_send_json_success(['dates' => $dates]);
	}

	public function process(){

		if(!wp_verify_nonce($_POST['_wpnonce'],'stora_booking_nonce')){
			wp_die('Security check failed');
		}

		// Honeypot (matches storefront anti-bot field pattern).
		if (!empty($_POST['stora_hp'])) {
			wp_die('Unable to process this request.', 'Booking', ['response' => 400]);
		}

		$full = isset($_POST['full_name']) ? trim(sanitize_text_field(wp_unslash($_POST['full_name']))) : '';
		if ($full === '') {
			wp_die('Please enter your full name.');
		}

		$parts = preg_split('/\s+/u', $full, 2, PREG_SPLIT_NO_EMPTY);
		$first_name = isset($parts[0]) ? $parts[0] : '';
		$last_name = isset($parts[1]) ? trim($parts[1]) : '';

		$emails = $this->validate_checkout_emails();
		$email = $emails['email'];

		$phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
		$phone_confirm = isset($_POST['phone_confirm']) ? sanitize_text_field(wp_unslash($_POST['phone_confirm'])) : '';
		if ($phone === '') {
			wp_die('Please enter your mobile number.');
		}
		if (!$this->is_valid_checkout_phone($phone)) {
			wp_die('Please enter a valid phone number.');
		}
		if ($phone_confirm === '') {
			wp_die('Please confirm your mobile number.');
		}
		if (!$this->is_valid_checkout_phone($phone_confirm)) {
			wp_die('Please enter a valid confirmation phone number.');
		}
		if (!$this->phones_match($phone, $phone_confirm)) {
			wp_die('Mobile numbers do not match. Please check and try again.');
		}

		$this->validate_about_you_fields();
		$this->validate_booking_consent();

		$consent_metadata = $this->build_booking_consent_metadata();

		$api = new Stora_API();

		$unit_id = isset($_POST['unit_id']) ? sanitize_text_field(wp_unslash($_POST['unit_id'])) : '';
		$move_in_raw = isset($_POST['move_in']) ? sanitize_text_field(wp_unslash($_POST['move_in'])) : '';
		$coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field(wp_unslash($_POST['coupon_code'])) : '';
		$protection_level_id = isset($_POST['protection_level_id']) ? sanitize_text_field(wp_unslash($_POST['protection_level_id'])) : '';
		if ($protection_level_id === '') {
			wp_die('Please select a protection cover level.');
		}

		$contact_payload = $this->build_contact_payload_from_post($full, $email, $phone);
		$contact_payload['metadata'] = $consent_metadata;

		// Prefer the documented 2025-09 flow: create a draft order, optionally apply coupon, finalize, then redirect to payment_url.
		$units_payload = $api->get_units();
		$unit = $this->find_unit_by_id($units_payload, $unit_id);

		if (!is_array($unit)) {
			wp_die('This unit is no longer available. Please choose another unit.');
		}

		$unit_status = strtolower((string) ($unit['status'] ?? ''));
		if ($unit_status !== '' && $unit_status !== 'available') {
			wp_die('This unit is no longer available. Please choose another unit.');
		}

		$order_response = null;
		$finalize_response = null;
		if (is_array($unit)) {
			$site_id = (string) ($unit['site']['id'] ?? '');
			$unit_type_id = (string) ($unit['unit_type']['id'] ?? '');

			if ($site_id !== '' && $unit_type_id !== '') {
				$unit_type_payload = $api->get_unit_type($unit_type_id);
				$unit_type = is_array($unit_type_payload) && isset($unit_type_payload['unit_type']) && is_array($unit_type_payload['unit_type'])
					? $unit_type_payload['unit_type']
					: [];

				$price_pick = $this->pick_billing_period_and_amount($unit_type, $site_id);
				$move_in = $this->validate_move_in_date(
					$move_in_raw,
					$api,
					$unit,
					$unit_type,
					$price_pick ? (string) $price_pick['billing_period'] : '',
					$price_pick ? (int) $price_pick['amount'] : 0
				);

				// cancel_redirect_url must be https per API schema; force https scheme.
				$cancel_redirect_url = set_url_scheme(home_url('/'), 'https');

				$order_contact = $this->resolve_order_contact($api, $contact_payload, $email);
				$protection = $this->validate_protection_level($api, $protection_level_id, $price_pick ? (string) $price_pick['billing_period'] : 'monthly', $site_id);

				$order_payload = [
					'site' => ['id' => $site_id],
					'payment_method' => 'card',
					// API expects date-time or "now".
					'starts_at' => $this->format_starts_at($move_in),
					'contact' => $order_contact,
					'metadata' => $consent_metadata,
					'line_items' => [
						[
							'type' => 'unit_type',
							'quantity' => 1,
							'price' => ['amount' => ($price_pick ? (int) $price_pick['amount'] : 0)],
							'item' => ['id' => $unit_type_id],
						],
						[
							'type' => 'protection',
							'quantity' => 1,
							'price' => ['amount' => (int) $protection['amount']],
							'item' => ['id' => (string) $protection['id']],
						],
					],
				];

				if ($price_pick) {
					$order_payload['billing_period'] = (string) $price_pick['billing_period'];
					$order_response = $api->create_order($order_payload);

					// If the inline contact failed due to email uniqueness, retry using existing contact.id.
					if (!is_array($order_response)) {
						$debug = $api->get_last_debug();
						$snippet = is_array($debug) ? (string) ($debug['body_snippet'] ?? '') : '';
						if ($snippet !== '' && stripos($snippet, 'Email has already been taken') !== false) {
							$contact_id = $this->find_contact_id_by_email($api, $email);
							if ($contact_id !== '') {
								$update_payload = $contact_payload;
								unset($update_payload['email']);
								$api->update_contact($contact_id, $update_payload);
								$order_payload['contact'] = ['id' => $contact_id];
								$order_response = $api->create_order($order_payload);
							} else {
								// Restore useful debug by re-attempting the failing order request.
								// (Otherwise last_debug may point at the contacts lookup response.)
								$order_response = $api->create_order($order_payload);
							}
						}
					}

					$order_id = is_array($order_response) && isset($order_response['order']) && is_array($order_response['order'])
						? (string) ($order_response['order']['id'] ?? '')
						: '';

					if ($order_id !== '') {
						if (!$this->apply_order_coupon($api, $order_id, $unit_type, $coupon_code)) {
							wp_die($this->coupon_error_invalid_code());
						}

						$finalize_response = $api->finalize_order($order_id, [
							'finalize' => [
								'cancel_redirect_url' => $cancel_redirect_url,
							],
						]);
					}
				}
			}
		}

		$payment_url = '';
		if (is_array($finalize_response) && !empty($finalize_response['order']) && is_array($finalize_response['order'])) {
			$payment_url = (string) ($finalize_response['order']['payment_url'] ?? '');
		} elseif (is_array($order_response) && !empty($order_response['order']) && is_array($order_response['order'])) {
			$payment_url = (string) ($order_response['order']['payment_url'] ?? '');
		}

		if ($payment_url !== '') {
			wp_redirect($payment_url);
			exit;
		}

		// Stora Public API 2025-09 checkout uses Orders -> payment_url.
		// Do not fall back to legacy endpoints, because they are not part of 2025-09.
		wp_die($this->booking_failed_message($api));

	}

}

new Stora_Booking();
