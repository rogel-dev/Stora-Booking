<?php

class Stora_Booking {

	public function __construct(){

		add_action('admin_post_stora_booking',[$this,'process']);
		add_action('admin_post_nopriv_stora_booking',[$this,'process']);

		add_action('wp_ajax_stora_validate_coupon', [$this, 'validate_coupon']);
		add_action('wp_ajax_nopriv_stora_validate_coupon', [$this, 'validate_coupon']);

		add_action('wp_ajax_stora_preview_totals', [$this, 'preview_totals']);
		add_action('wp_ajax_nopriv_stora_preview_totals', [$this, 'preview_totals']);

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
	private function pick_billing_period_and_amount($unit_type) {
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

		foreach ($preferred as $period) {
			if (isset($by_period[$period])) {
				return ['billing_period' => $period, 'amount' => (int) $by_period[$period]];
			}
		}

		// Fallback to the first available period.
		foreach ($by_period as $period => $amount) {
			return ['billing_period' => (string) $period, 'amount' => (int) $amount];
		}

		return null;
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

	private function booking_failed_message($api) {
		$base = 'Booking failed.';
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
	 * Find a coupon id by coupon code (non-public coupons only).
	 *
	 * @return string Coupon id (cpn_*) or empty string when not found.
	 */
	private function find_coupon_id_by_code($api, $code) {
		if (!($api instanceof Stora_API)) {
			return '';
		}
		$code = strtoupper(trim((string) $code));
		if ($code === '') {
			return '';
		}

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
				return '';
			}

			foreach ($list as $coupon) {
				if (!is_array($coupon)) {
					continue;
				}
				$coupon_code = $coupon['code'] ?? null;
				if (!is_string($coupon_code) || $coupon_code === '') {
					continue;
				}
				if (strtoupper($coupon_code) === $code) {
					return (string) ($coupon['id'] ?? '');
				}
			}

			// If the API returns fewer than the page size, we reached the end.
			if (count($list) < $limit) {
				return '';
			}

			$page++;
		}

		return '';
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
				return null;
			}

			foreach ($list as $coupon) {
				if (!is_array($coupon)) {
					continue;
				}
				$coupon_code = $coupon['code'] ?? null;
				if (!is_string($coupon_code) || $coupon_code === '') {
					continue;
				}
				if (strtoupper($coupon_code) === $code) {
					return $coupon;
				}
			}

			if (count($list) < $limit) {
				return null;
			}

			$page++;
		}

		return null;
	}

	public function validate_coupon() {
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
			wp_send_json_error(['message' => 'Unable to validate coupon for this unit.'], 400);
		}

		$unit_type_id = (string) ($unit['unit_type']['id'] ?? '');
		$unit_type_payload = $unit_type_id !== '' ? $api->get_unit_type($unit_type_id) : null;
		$unit_type = is_array($unit_type_payload) && isset($unit_type_payload['unit_type']) && is_array($unit_type_payload['unit_type'])
			? $unit_type_payload['unit_type']
			: (is_array($unit['unit_type']) ? $unit['unit_type'] : []);

		$price_pick = $this->pick_billing_period_and_amount($unit_type);
		$billing_period = $price_pick ? (string) $price_pick['billing_period'] : '';

		$coupon = $this->find_coupon_by_code($api, $code);
		if (!is_array($coupon) || empty($coupon['id'])) {
			wp_send_json_error(['message' => 'Coupon code is invalid.'], 404);
		}

		// Best-effort applicability checks without creating an order.
		// Stora ultimately validates coupons against an Order, but coupons include metadata about what they can apply to.
		$auto = isset($coupon['auto_apply_to']) && is_array($coupon['auto_apply_to']) ? $coupon['auto_apply_to'] : [];
		$applies_to_unit_types = !empty($auto['unit_types']);
		$applies_to_subscription = !empty($auto['subscriptions']);
		if (!$applies_to_unit_types && !$applies_to_subscription) {
			wp_send_json_error(['message' => 'This coupon cannot be applied to storage units.'], 400);
		}

		$coupon_period = $coupon['billing_period'] ?? null;
		if (is_string($coupon_period) && $coupon_period !== '' && $billing_period !== '' && $coupon_period !== $billing_period) {
			wp_send_json_error(['message' => 'This coupon is not valid for the selected billing period.'], 400);
		}

		$max_redemptions = $coupon['max_redemptions'] ?? null;
		if (is_int($max_redemptions) && $max_redemptions === 0) {
			wp_send_json_error(['message' => 'This coupon is no longer available.'], 400);
		}

		$type = (string) ($coupon['type'] ?? '');
		$label = (string) ($coupon['name'] ?? $code);
		$discount = '';
		if ($type === 'percentage' && isset($coupon['percent']) && is_numeric($coupon['percent'])) {
			$discount = (string) $coupon['percent'] . '% off';
		} elseif ($type === 'amount' && isset($coupon['amount']) && is_array($coupon['amount'])) {
			$discount = (string) ($coupon['amount']['formatted'] ?? '');
		}

		wp_send_json_success([
			'message' => $discount !== '' ? ('Coupon applied: ' . $label . ' (' . $discount . ').') : ('Coupon applied: ' . $label . '.'),
			'coupon_id' => (string) $coupon['id'],
		]);
	}

	public function preview_totals() {
		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'stora_coupon_nonce')) {
			wp_send_json_error(['message' => 'Security check failed.'], 403);
		}

		$unit_id = isset($_POST['unit_id']) ? sanitize_text_field(wp_unslash($_POST['unit_id'])) : '';
		if ($unit_id === '') {
			wp_send_json_error(['message' => 'Missing unit selection.'], 400);
		}

		$full = isset($_POST['full_name']) ? trim(sanitize_text_field(wp_unslash($_POST['full_name']))) : '';
		$email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
		$phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

		// Stora order creation generally expects a Contact. Require these for an accurate preview.
		if ($full === '' || $email === '' || $phone === '') {
			wp_send_json_error(['message' => 'Enter your name, email, and phone to calculate an accurate total.'], 400);
		}

		$move_in = isset($_POST['move_in']) ? sanitize_text_field(wp_unslash($_POST['move_in'])) : '';
		$coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field(wp_unslash($_POST['coupon_code'])) : '';

		$api = new Stora_API();
		$unit = $this->find_unit_by_id($api->get_units(), $unit_id);
		if (!is_array($unit)) {
			wp_send_json_error(['message' => 'Unable to find unit.'], 400);
		}

		$site_id = (string) ($unit['site']['id'] ?? '');
		$unit_type_id = (string) ($unit['unit_type']['id'] ?? '');
		if ($site_id === '' || $unit_type_id === '') {
			wp_send_json_error(['message' => 'Unable to price this unit.'], 400);
		}

		$unit_type_payload = $api->get_unit_type($unit_type_id);
		$unit_type = is_array($unit_type_payload) && isset($unit_type_payload['unit_type']) && is_array($unit_type_payload['unit_type'])
			? $unit_type_payload['unit_type']
			: [];
		$price_pick = $this->pick_billing_period_and_amount($unit_type);
		if (!$price_pick) {
			wp_send_json_error(['message' => 'Unable to price this unit.'], 400);
		}

		$order_payload = [
			'site' => ['id' => $site_id],
			'payment_method' => 'card',
			'starts_at' => $this->format_starts_at($move_in),
			'billing_period' => (string) $price_pick['billing_period'],
			'contact' => [
				'full_name' => $full,
				'email' => $email,
				'phone_number' => $phone,
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
			$msg = 'Unable to calculate total right now.';
			$is_admin = function_exists('is_user_logged_in')
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

		if ($coupon_code !== '') {
			$coupon_id = $this->find_coupon_id_by_code($api, $coupon_code);
			if ($coupon_id !== '') {
				$api->update_order($order_id, ['coupon' => ['id' => $coupon_id]]);
			}
		}

		$validated = $api->validate_order($order_id);
		$order = is_array($validated) && isset($validated['order']) && is_array($validated['order'])
			? $validated['order']
			: (is_array($order_response) && isset($order_response['order']) && is_array($order_response['order']) ? $order_response['order'] : null);

		if (!is_array($order)) {
			wp_send_json_error(['message' => 'Unable to calculate total right now.'], 500);
		}

		$get_money = static function ($v) {
			if (!is_array($v)) {
				return '';
			}
			$f = $v['formatted'] ?? '';
			return is_string($f) ? $f : '';
		};

		$subtotal = $get_money($order['subtotal'] ?? null);
		$total = $get_money($order['total'] ?? null);
		$discount = $get_money($order['total_discount'] ?? null);
		$tax = $get_money($order['tax'] ?? null);

		wp_send_json_success([
			'subtotal' => $subtotal,
			'total' => $total,
			'discount' => $discount,
			'tax' => $tax,
		]);
	}

	public function process(){

		if(!wp_verify_nonce($_POST['_wpnonce'],'stora_booking_nonce')){
			wp_die('Security check failed');
		}

		// Honeypot (matches storefront anti-bot field pattern).
		if (!empty($_POST['stora_hp'])) {
			wp_die('Unable to process this request.', 'Booking', ['response' => 400]);
		}

		$full = isset($_POST['full_name']) ? trim(wp_unslash($_POST['full_name'])) : '';
		if ($full === '') {
			wp_die('Please enter your full name.');
		}

		$parts = preg_split('/\s+/u', $full, 2, PREG_SPLIT_NO_EMPTY);
		$first_name = isset($parts[0]) ? $parts[0] : '';
		$last_name = isset($parts[1]) ? trim($parts[1]) : '';

		$email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
		$email_confirm = isset($_POST['email_confirm']) ? sanitize_email(wp_unslash($_POST['email_confirm'])) : '';
		if ($email === '' || $email_confirm === '' || strtolower($email) !== strtolower($email_confirm)) {
			wp_die('Email addresses do not match. Please check and try again.');
		}

		$phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
		$phone_confirm = isset($_POST['phone_confirm']) ? sanitize_text_field(wp_unslash($_POST['phone_confirm'])) : '';
		if ($phone === '' || !$this->phones_match($phone, $phone_confirm)) {
			wp_die('Mobile numbers do not match. Please check and try again.');
		}

		$api = new Stora_API();

		$unit_id = sanitize_text_field($_POST['unit_id']);
		$move_in = sanitize_text_field($_POST['move_in']);
		$coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field(wp_unslash($_POST['coupon_code'])) : '';

		// Prefer the documented 2025-09 flow: create a draft order, optionally apply coupon, finalize, then redirect to payment_url.
		$units_payload = $api->get_units();
		$unit = $this->find_unit_by_id($units_payload, $unit_id);

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

				$price_pick = $this->pick_billing_period_and_amount($unit_type);

				// cancel_redirect_url must be https per API schema; force https scheme.
				$cancel_redirect_url = set_url_scheme(home_url('/'), 'https');

				$contact_payload = [
					'full_name' => $full,
					'email' => $email,
					'phone_number' => $phone,
					'source' => 'booking',
				];

				$order_payload = [
					'site' => ['id' => $site_id],
					'payment_method' => 'card',
					// API expects date-time or "now".
					'starts_at' => $this->format_starts_at($move_in),
					'contact' => $contact_payload,
					'line_items' => [
						[
							'type' => 'unit_type',
							'quantity' => 1,
							'price' => ['amount' => ($price_pick ? (int) $price_pick['amount'] : 0)],
							'item' => ['id' => $unit_type_id],
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
						// Apply coupon if provided and resolvable.
						$coupon_id = $coupon_code !== '' ? $this->find_coupon_id_by_code($api, $coupon_code) : '';
						if ($coupon_id !== '') {
							$api->update_order($order_id, ['coupon' => ['id' => $coupon_id]]);
						} elseif ($coupon_code !== '') {
							wp_die('Coupon code is invalid. Please check and try again.');
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
