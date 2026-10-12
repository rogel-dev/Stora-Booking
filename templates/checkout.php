<?php
/**
 * Checkout / contact step — aligned with Stora storefront contact-details pattern.
 *
 * @var string $unit_id Sanitized unit id from shortcode.
 */

if (!defined('ABSPATH')) {
	exit;
}

$unit_id = isset($unit_id) ? (string) $unit_id : '';
if ($unit_id === '') {
	echo '<p class="stora-checkout stora-checkout--error">No unit selected. Please choose a unit from the list first.</p>';
	return;	
}

$api = class_exists('Stora_API') ? new Stora_API() : null;
$units_payload = ($api instanceof Stora_API) ? $api->get_units() : null;
$unit = null;
if (is_array($units_payload)) {
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
		if ((string) ($u['id'] ?? '') === (string) $unit_id) {
			$unit = $u;
			break;
		}
	}
}

if (!is_array($unit)) {
	echo '<p class="stora-checkout stora-checkout--error">This unit is no longer available. Please <a href="' . esc_url(home_url('/')) . '">choose another unit</a>.</p>';
	return;
}

$unit_status = strtolower((string) ($unit['status'] ?? ''));
if ($unit_status !== '' && $unit_status !== 'available') {
	echo '<p class="stora-checkout stora-checkout--error">This unit is no longer available. Please <a href="' . esc_url(home_url('/')) . '">choose another unit</a>.</p>';
	return;
}

$unit_type = is_array($unit) && isset($unit['unit_type']) && is_array($unit['unit_type']) ? $unit['unit_type'] : [];
$unit_type_id = (string) ($unit_type['id'] ?? '');
$unit_site_id = is_array($unit) && is_array($unit['site'] ?? null) ? (string) ($unit['site']['id'] ?? '') : '';
if ($unit_type_id !== '' && $api instanceof Stora_API) {
	$unit_type = $api->resolve_canonical_unit_type_for_display($unit_type, $unit_site_id);
}
$unit_type_name = (string) ($unit_type['name'] ?? '');
$unit_ref = is_array($unit) ? (string) ($unit['reference_id'] ?? $unit_id) : (string) $unit_id;

$image_url = ($api instanceof Stora_API) ? Stora_API::resolve_unit_type_image_url($unit_type) : '';
$placeholder_url = defined('STORA_PLUGIN_URL') ? (string) STORA_PLUGIN_URL . 'assets/img/unit-placeholder.png' : '';

$dim_length = '';
$dim_width = '';
$dim_height = '';
$dims = $unit_type['dimensions'] ?? null;
if (is_array($dims)) {
	$l = $dims['length'] ?? null;
	$w = $dims['width'] ?? null;
	$h = $dims['height'] ?? null;
	$mu = isset($dims['measurement_unit']) ? (string) $dims['measurement_unit'] : '';
	$fmt = static function ($n) {
		if (!is_numeric($n)) {
			return '';
		}
		$f = (float) $n;
		return (floor($f) === $f) ? (string) (int) $f : (string) $f;
	};
	$ls = $fmt($l);
	$ws = $fmt($w);
	$hs = $fmt($h);
	if ($ls !== '') {
		$dim_length = $ls . ($mu !== '' ? (' ' . $mu) : '');
	}
	if ($ws !== '') {
		$dim_width = $ws . ($mu !== '' ? (' ' . $mu) : '');
	}
	if ($hs !== '') {
		$dim_height = $hs . ($mu !== '' ? (' ' . $mu) : '');
	}
}

$price_formatted = '';
$billing_period = 'monthly';
if (class_exists('Stora_API')) {
	$price_formatted = Stora_API::resolve_unit_type_monthly_display_price($unit_type, $unit_site_id);
}
if ($price_formatted === '' && !empty($unit_type['prices']) && is_array($unit_type['prices'])) {
	foreach ($unit_type['prices'] as $p) {
		if (!is_array($p)) {
			continue;
		}
		if (($p['billing_period'] ?? '') === 'monthly') {
			$price_formatted = (string) ($p['price']['formatted'] ?? '');
			$billing_period = 'monthly';
			break;
		}
	}
	if ($price_formatted === '') {
		$first = $unit_type['prices'][0] ?? null;
		if (is_array($first)) {
			$price_formatted = (string) ($first['price']['formatted'] ?? '');
			$billing_period = (string) ($first['billing_period'] ?? '');
		}
	}
}

$checkout_options = class_exists('Stora_Booking') ? Stora_Booking::checkout_select_options() : [
	'use_case' => [],
	'type' => [],
	'marketing_source' => [],
	'countries' => ['GB' => 'United Kingdom'],
];

$move_in_amount_minor = ($billing_period !== '' && class_exists('Stora_API'))
	? Stora_API::resolve_unit_type_checkout_total_minor_for_period($unit_type, $billing_period, $unit_site_id)
	: null;
$move_in_dates_deferred = false;
if (
	$api instanceof Stora_API
	&& $unit_site_id !== ''
	&& $unit_type_id !== ''
	&& $billing_period !== ''
	&& $move_in_amount_minor
	&& class_exists('Stora_Booking')
) {
	$cached_move_in_window = Stora_Booking::get_cached_move_in_date_window($unit_site_id, $unit_type_id, $billing_period);
	if (is_array($cached_move_in_window)) {
		$move_in_window = $cached_move_in_window;
	} else {
		$move_in_window = Stora_Booking::get_move_in_date_window();
		$move_in_dates_deferred = true;
	}
} else {
	$move_in_window = class_exists('Stora_Booking') ? Stora_Booking::get_move_in_date_window() : [
		'min' => '',
		'max' => '',
		'dates' => [],
		'timezone' => 'UTC',
	];
}
$move_in_dates = isset($move_in_window['dates']) && is_array($move_in_window['dates']) ? $move_in_window['dates'] : [];

$weekly_price_formatted = class_exists('Stora_API') ? Stora_API::resolve_unit_type_weekly_display_price($unit_type, $unit_site_id) : '';
$unit_promotion = class_exists('Stora_API') ? Stora_API::resolve_unit_type_promotion($unit_type) : null;
$promotion_badge = $unit_promotion ? Stora_API::format_unit_type_promotion_badge($unit_promotion) : '';
$promo_weekly_prices = class_exists('Stora_API') ? Stora_API::resolve_unit_type_promotion_weekly_prices($unit_type, $unit_site_id) : null;
$promo_billing_prices = ($billing_period !== '' && class_exists('Stora_API'))
	? Stora_API::resolve_unit_type_promotion_prices_for_period($unit_type, $billing_period, $unit_site_id)
	: null;
$has_promotion = is_array($promo_billing_prices);
$regular_billing_minor = ($billing_period !== '' && class_exists('Stora_API'))
	? Stora_API::resolve_unit_type_amount_minor_for_period($unit_type, $billing_period, $unit_site_id)
	: null;
$summary_base_total_minor = ($billing_period !== '' && class_exists('Stora_API'))
	? Stora_API::resolve_unit_type_checkout_total_minor_for_period($unit_type, $billing_period, $unit_site_id)
	: null;

if ($has_promotion) {
	$summary_rent_formatted = (string) $promo_billing_prices['regular'];
	$summary_total_formatted = (string) $promo_billing_prices['discounted'];
	$summary_discount_formatted = (string) ($promo_billing_prices['discount_amount'] ?? '');
} elseif ($summary_base_total_minor !== null && class_exists('Stora_API')) {
	$rent_minor = $regular_billing_minor !== null ? (int) $regular_billing_minor : (int) $summary_base_total_minor;
	$summary_rent_formatted = Stora_API::format_checkout_price_minor($rent_minor, $unit_type, $billing_period);
	$summary_total_formatted = Stora_API::format_checkout_price_minor((int) $summary_base_total_minor, $unit_type, $billing_period);
	$summary_discount_formatted = '';
} else {
	$summary_rent_formatted = $price_formatted;
	$summary_total_formatted = $price_formatted;
	$summary_discount_formatted = '';
}
$display_weekly_price = is_array($promo_weekly_prices)
	? (string) $promo_weekly_prices['discounted']
	: $weekly_price_formatted;
$regular_weekly_price = is_array($promo_weekly_prices)
	? (string) ($promo_weekly_prices['regular'] ?? $weekly_price_formatted)
	: '';
$checkout_testimonial = isset($GLOBALS['STORA_CHECKOUT_TESTIMONIAL']) && is_array($GLOBALS['STORA_CHECKOUT_TESTIMONIAL'])
	? $GLOBALS['STORA_CHECKOUT_TESTIMONIAL']
	: [];

$protection_levels = ($api instanceof Stora_API) ? $api->get_protection_levels($unit_site_id) : [];
$protection_email = defined('STORA_PROTECTION_EVIDENCE_EMAIL')
	? (string) STORA_PROTECTION_EVIDENCE_EMAIL
	: 'info@selfstoragecentre.co.uk';
$terms_url = defined('STORA_TERMS_URL') ? trailingslashit((string) STORA_TERMS_URL) : '';
$privacy_url = defined('STORA_PRIVACY_URL') ? trailingslashit((string) STORA_PRIVACY_URL) : '';
// Recommend a specific cover level (default £2,500). Override via STORA_RECOMMENDED_PROTECTION_COVER_MINOR.
$recommended_cover_minor = defined('STORA_RECOMMENDED_PROTECTION_COVER_MINOR')
	? (int) STORA_RECOMMENDED_PROTECTION_COVER_MINOR
	: 250000;
$recommended_protection_id = '';
foreach ($protection_levels as $level_option) {
	if (!is_array($level_option) || empty($level_option['id'])) {
		continue;
	}
	$cover_option = isset($level_option['cover_level']) && is_array($level_option['cover_level']) ? $level_option['cover_level'] : [];
	if (isset($cover_option['amount']) && is_numeric($cover_option['amount']) && (int) $cover_option['amount'] === $recommended_cover_minor) {
		$recommended_protection_id = (string) $level_option['id'];
		break;
	}
}
if ($recommended_protection_id === '' && !empty($protection_levels[0]['id'])) {
	$recommended_protection_id = (string) $protection_levels[0]['id'];
}
$checkout_phone_placeholder = defined('STORA_CHECKOUT_PHONE_PLACEHOLDER') ? trim((string) STORA_CHECKOUT_PHONE_PLACEHOLDER) : '+44 07400 123456';
if ($checkout_phone_placeholder === '') {
	$checkout_phone_placeholder = '+44 07400 123456';
}

if ($price_formatted === '' && $weekly_price_formatted === '') {
	echo '<p class="stora-checkout stora-checkout--error">Unable to load pricing for this unit. Please <a href="' . esc_url(home_url('/')) . '">choose another unit</a> or contact us.</p>';
	return;
}
?>

<div class="stora-checkout">
	<div class="stora-checkout__split">
		<div class="stora-checkout__main">
			<header class="stora-checkout__header">
				<h1 class="stora-checkout__title">Complete Your Booking</h1>
				<div class="stora-checkout__progress" aria-hidden="true">
					<div class="stora-checkout__progress-bar" data-stora-checkout-progress style="width: 20%;"></div>
				</div>
			</header>

			<form id="stora_checkout_form" class="stora-checkout__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" novalidate data-stora-checkout-form>
				<input type="hidden" name="action" value="stora_booking">
				<input type="hidden" name="unit_id" value="<?php echo esc_attr($unit_id); ?>">
				<?php wp_nonce_field('stora_booking_nonce'); ?>

				<div class="stora-checkout__step" data-stora-checkout-step="1">
					<h3 class="stora-checkout__subtitle">Add Contact Details</h3>
					<p class="stora-checkout__lead">Enter your details so we can send your move-in information.</p>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_full_name">Full name <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<input class="stora-checkout__input" id="stora_full_name" name="full_name" type="text" autocomplete="name" required data-stora-required>
					</div>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_email">Email address <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<input class="stora-checkout__input" id="stora_email" name="email" type="email" autocomplete="email" required data-stora-required data-stora-email>
					</div>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_email_confirm">Confirm email address <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<input class="stora-checkout__input" id="stora_email_confirm" name="email_confirm" type="email" autocomplete="email" required data-stora-required data-stora-email-confirm>
					</div>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_phone">Mobile number <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<input class="stora-checkout__input" id="stora_phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="<?php echo esc_attr($checkout_phone_placeholder); ?>" required data-stora-required data-stora-phone>
					</div>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_phone_confirm">Confirm mobile number <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<input class="stora-checkout__input" id="stora_phone_confirm" name="phone_confirm" type="tel" autocomplete="tel" inputmode="tel" placeholder="<?php echo esc_attr($checkout_phone_placeholder); ?>" required data-stora-required data-stora-phone-confirm>
					</div>

					<p class="stora-checkout__hp" aria-hidden="true">
						<label for="stora_hp">If you are a human, ignore this field</label>
						<input id="stora_hp" name="stora_hp" type="text" value="" tabindex="-1" autocomplete="off">
					</p>

					<div class="stora-checkout__nav stora-checkout__nav--end">
						<button type="button" class="stora-checkout__continue" data-stora-checkout-next="2">Continue</button>
					</div>
				</div>

				<div class="stora-checkout__step" data-stora-checkout-step="2" hidden>
					<h3 class="stora-checkout__subtitle">Tell Us About You</h3>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_address_line_1">Your address <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<input class="stora-checkout__input" id="stora_address_line_1" name="address_line_1" type="text" autocomplete="address-line1" placeholder="Address" required data-stora-required data-stora-step="2">
						<input class="stora-checkout__input stora-checkout__input--stacked" id="stora_address_line_2" name="address_line_2" type="text" autocomplete="address-line2" placeholder="Address Line 2">
						<input class="stora-checkout__input stora-checkout__input--stacked" id="stora_address_city" name="address_city" type="text" autocomplete="address-level2" placeholder="City / Town" required data-stora-required data-stora-step="2">
						<input class="stora-checkout__input stora-checkout__input--stacked" id="stora_address_postcode" name="address_postcode" type="text" autocomplete="postal-code" placeholder="Postcode" required data-stora-required data-stora-step="2">
						<select class="stora-checkout__input stora-checkout__input--stacked stora-checkout__select" id="stora_address_country" name="address_country" required data-stora-required data-stora-step="2">
							<?php foreach ($checkout_options['countries'] as $code => $label): ?>
								<option value="<?php echo esc_attr($code); ?>"<?php echo $code === 'GB' ? ' selected' : ''; ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_use_case">What will you use storage for? <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<select class="stora-checkout__input stora-checkout__select" id="stora_use_case" name="use_case" required data-stora-required data-stora-step="2">
							<option value="">Select an option</option>
							<?php foreach ($checkout_options['use_case'] as $value => $label): ?>
								<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_storage_type">Storage type <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<select class="stora-checkout__input stora-checkout__select" id="stora_storage_type" name="storage_type" required data-stora-required data-stora-step="2">
							<option value="">Select an option</option>
							<?php foreach ($checkout_options['type'] as $value => $label): ?>
								<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="stora-checkout__field">
						<label class="stora-checkout__label" for="stora_marketing_source">How did you hear about us? <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<select class="stora-checkout__input stora-checkout__select" id="stora_marketing_source" name="marketing_source" required data-stora-required data-stora-step="2">
							<option value="">Select an option</option>
							<?php foreach ($checkout_options['marketing_source'] as $value => $label): ?>
								<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="stora-checkout__nav stora-checkout__nav--split">
						<button type="button" class="stora-checkout__back" data-stora-checkout-back="1">Back</button>
						<button type="button" class="stora-checkout__continue" data-stora-checkout-next="3">Continue</button>
					</div>
				</div>

				<div class="stora-checkout__step" data-stora-checkout-step="3" hidden>
					<h3 class="stora-checkout__subtitle">Protection</h3>
					<div class="stora-checkout__leads">
						<p class="stora-checkout__lead">You must have cover to stay with us. You can purchase an appropriate amount of cover alongside your booking. Simply select how much it would cost to replace the value of the items being stored and we'll add the cost to your bill.</p>
						<p class="stora-checkout__lead">If you'd like to use your own cover you must still select a cover level. Please then email evidence of your cover to <a class="stora-checkout__link" href="<?php echo esc_url('mailto:' . $protection_email); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($protection_email); ?></a> prior to move-in to remove our protection costs. If no evidence is received our cover will remain applied and charged accordingly.</p>
					</div>

					<?php if ($protection_levels): ?>
						<fieldset class="stora-checkout__protection" data-stora-protection-field>
							<legend class="stora-checkout__sr-only">Select protection cover level</legend>
							<div class="stora-checkout__protection-list">
								<?php foreach ($protection_levels as $index => $level): ?>
									<?php
									if (!is_array($level)) {
										continue;
									}
									$level_id = (string) ($level['id'] ?? '');
									$level_site_id = class_exists('Stora_API') ? Stora_API::protection_level_site_id($level) : '';
									if ($level_id === '' || ($unit_site_id !== '' && $level_site_id !== $unit_site_id)) {
										continue;
									}
									$cover_label = class_exists('Stora_API') ? Stora_API::format_protection_cover_label($level) : '';
									$weekly_price = class_exists('Stora_API') ? Stora_API::resolve_protection_weekly_display_price($level) : '';
									$summary_price = class_exists('Stora_API')
										? Stora_API::resolve_protection_summary_display_price($level, $billing_period !== '' ? $billing_period : 'monthly')
										: '';
									$protection_price_pick = class_exists('Stora_API')
										? Stora_API::pick_protection_price_for_period($level, $billing_period !== '' ? $billing_period : 'monthly')
										: null;
									$protection_amount_minor = is_array($protection_price_pick) ? (int) $protection_price_pick['amount'] : 0;
									$is_recommended = ($recommended_protection_id !== '' && $level_id === $recommended_protection_id);
									$is_default = $is_recommended || ($recommended_protection_id === '' && $index === 0);
									?>
									<label class="stora-checkout__protection-option<?php echo $is_default ? ' is-selected' : ''; ?>">
										<input
											type="radio"
											name="protection_level_id"
											value="<?php echo esc_attr($level_id); ?>"
											class="stora-checkout__protection-input"
											data-stora-step="3"
											data-stora-protection-cover="<?php echo esc_attr($cover_label); ?>"
											data-stora-protection-price="<?php echo esc_attr($summary_price); ?>"
											data-stora-protection-amount="<?php echo esc_attr((string) $protection_amount_minor); ?>"
											<?php echo $is_default ? 'checked' : ''; ?>
										>
										<span class="stora-checkout__protection-card">
											<span class="stora-checkout__protection-main">
												<span class="stora-checkout__protection-cover"><?php echo esc_html($cover_label !== '' ? $cover_label : 'Cover level'); ?></span>
												<?php if ($weekly_price !== ''): ?>
													<span class="stora-checkout__protection-price"><?php echo esc_html($weekly_price); ?>/week <span class="stora-checkout__protection-tax">(inc. tax)</span></span>
												<?php endif; ?>
											</span>
											<span class="stora-checkout__protection-meta">
												<?php if ($is_recommended): ?>
													<span class="stora-checkout__protection-badge">★ Recommended</span>
												<?php endif; ?>
												<span class="stora-checkout__protection-shield" aria-hidden="true">
													<i class="fa-solid fa-shield-halved fa-xl"></i>
												</span>
											</span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
							<div class="stora-checkout__error" data-stora-protection-error hidden></div>
						</fieldset>
					<?php else: ?>
						<p class="stora-checkout__lead stora-checkout__lead--warn">Protection options are unavailable right now. Please try again later or contact us to complete your booking.</p>
					<?php endif; ?>

					<div class="stora-checkout__nav stora-checkout__nav--split">
						<button type="button" class="stora-checkout__back" data-stora-checkout-back="2">Back</button>
						<button type="button" class="stora-checkout__continue" data-stora-checkout-next="4"<?php echo $protection_levels ? '' : ' disabled'; ?>>Continue</button>
					</div>
				</div>

				<div class="stora-checkout__step" data-stora-checkout-step="4" hidden>
					<h3 class="stora-checkout__subtitle">Choose Your Move-In Date</h3>
					<p class="stora-checkout__lead">Choose the day that most suits you for move-in.</p>

					<div class="stora-checkout__field stora-checkout__field--move-in">
						<label class="stora-checkout__label" id="stora_move_in_label">Preferred Move-In Date <abbr class="stora-checkout__req" title="required">*</abbr></label>
						<div
							class="stora-checkout__move-in"
							data-stora-move-in-picker
							<?php if ($move_in_dates_deferred): ?>
								data-stora-move-in-deferred="1"
								data-stora-unit-id="<?php echo esc_attr($unit_id); ?>"
							<?php endif; ?>
							role="radiogroup"
							aria-labelledby="stora_move_in_label"
						>
							<?php if ($move_in_dates_deferred): ?>
								<p class="stora-checkout__move-in-hint">Move-in dates will load when you open this step.</p>
							<?php elseif ($move_in_dates): ?>
								<div class="stora-checkout__move-in-options">
									<?php foreach ($move_in_dates as $index => $move_in_date): ?>
										<?php
										if (!is_array($move_in_date)) {
											continue;
										}
										$move_in_value = (string) ($move_in_date['value'] ?? '');
										if ($move_in_value === '') {
											continue;
										}
										$move_in_day = isset($move_in_date['day']) ? (string) (int) $move_in_date['day'] : wp_date('j', strtotime($move_in_value . ' 12:00:00 UTC'));
										$move_in_weekday = (string) ($move_in_date['weekday'] ?? wp_date('D', strtotime($move_in_value . ' 12:00:00 UTC')));
										$move_in_month = wp_date('M', strtotime($move_in_value . ' 12:00:00 UTC'));
										$is_default_move_in = $index === 0;
										?>
										<label class="stora-checkout__move-in-option<?php echo $is_default_move_in ? ' is-selected' : ''; ?>">
											<input
												type="radio"
												name="move_in"
												value="<?php echo esc_attr($move_in_value); ?>"
												class="stora-checkout__move-in-input"
												required
												data-stora-required
												data-stora-step="4"
												<?php echo $is_default_move_in ? 'checked' : ''; ?>
											>
											<span class="stora-checkout__move-in-card">
												<span class="stora-checkout__move-in-day"><?php echo esc_html($move_in_day); ?></span>
												<span class="stora-checkout__move-in-meta"><?php echo esc_html($move_in_weekday . ' ' . $move_in_month); ?></span>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
							<?php else: ?>
								<p class="stora-checkout__move-in-hint">Move-in dates are unavailable right now. Please contact us to complete your booking.</p>
							<?php endif; ?>
							<p class="stora-checkout__move-in-selected" data-stora-move-in-selected hidden></p>
						</div>
					</div>

					<div class="stora-checkout__nav stora-checkout__nav--split">
						<button type="button" class="stora-checkout__back" data-stora-checkout-back="3">Back</button>
						<button type="button" class="stora-checkout__continue" data-stora-checkout-next="5"<?php echo ($move_in_dates && !$move_in_dates_deferred) ? '' : ' disabled'; ?>>Continue</button>
					</div>
				</div>

				<div class="stora-checkout__step" data-stora-checkout-step="5" hidden>
					<h3 class="stora-checkout__subtitle">Confirm Your Booking</h3>

					<div class="stora-checkout__consent" data-stora-consent-field>
						<h4 class="stora-checkout__consent-title">Please Note</h4>
						<p class="stora-checkout__consent-note">You won&rsquo;t be charged anything until your move-in day. Cancel anytime before for free.</p>
						<label class="stora-checkout__consent-label">
							<input
								type="checkbox"
								name="booking_consent"
								value="1"
								class="stora-checkout__consent-input"
								data-stora-step="5"
								required
							>
							<span class="stora-checkout__consent-text">
								I agree to your
								<?php if ($terms_url !== ''): ?>
									<a class="stora-checkout__link" href="<?php echo esc_url($terms_url); ?>" target="_blank" rel="noopener noreferrer">terms</a>
								<?php else: ?>
									terms
								<?php endif; ?>
								and
								<?php if ($privacy_url !== ''): ?>
									<a class="stora-checkout__link" href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener noreferrer">privacy policy</a>
								<?php else: ?>
									privacy policy
								<?php endif; ?>
								. I have selected the correct value of the stored items.
							</span>
						</label>
						<div class="stora-checkout__error" data-stora-consent-error hidden></div>
					</div>

					<div class="stora-checkout__nav stora-checkout__nav--confirm">
						<button type="button" class="stora-checkout__back" data-stora-checkout-back="4">Back</button>
						<button type="submit" class="stora-checkout__continue stora-checkout__continue--wide">Confirm Booking Now</button>
					</div>
					<p class="stora-checkout__secure-note">
						<i class="fa-solid fa-lock" aria-hidden="true"></i>
						Payments are secure and encrypted.
					</p>
				</div>
			</form>
		</div>

		<aside class="stora-checkout__side" aria-label="Unit and coupon">
			<?php if ($unit_type_name !== '' || $display_weekly_price !== '' || $price_formatted !== '' || $image_url !== '' || $placeholder_url !== ''): ?>
				<div class="stora-checkout__unit-head" aria-label="Selected unit">
					<div class="stora-checkout__unit-thumb">
						<img
							src="<?php echo esc_url(($image_url !== '' ? $image_url : $placeholder_url)); ?>"
							alt="<?php echo esc_attr($unit_type_name !== '' ? ($unit_type_name . ' — ' . $unit_ref) : $unit_ref); ?>"
							loading="lazy"
							decoding="async"
						/>
					</div>
					<div class="stora-checkout__unit-meta">
						<p class="stora-checkout__unit-name"><?php echo esc_html($unit_type_name !== '' ? stora_format_unit_size_label($unit_type_name) : 'Selected unit'); ?></p>
						<?php if ($display_weekly_price !== ''): ?>
							<p class="stora-checkout__unit-price">
								<?php echo esc_html($display_weekly_price); ?><span class="stora-checkout__unit-price-suffix">/week (inc. tax)</span>
								<?php if ($regular_weekly_price !== '' && $regular_weekly_price !== $display_weekly_price): ?>
									<span class="stora-checkout__unit-price-after"><?php echo esc_html($regular_weekly_price); ?> after discount period</span>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</div>
				</div>

				<div class="stora-checkout__coupon-block" data-stora-coupon>
					<div class="stora-checkout__coupon-inputwrap">
						<span class="stora-checkout__coupon-icon" aria-hidden="true">
							<i class="fa-solid fa-tags"></i>
						</span>
						<input
							class="stora-checkout__input stora-checkout__coupon-input"
							id="stora_coupon"
							name="coupon_code"
							type="text"
							autocomplete="off"
							inputmode="text"
							placeholder="Enter discount code"
							form="stora_checkout_form"
							data-stora-coupon-code
							aria-label="Enter discount code"
						>
						<button
							type="button"
							class="stora-checkout__coupon-apply"
							data-stora-coupon-apply
						>Apply</button>
					</div>
					<input type="hidden" name="stora_coupon_nonce" value="<?php echo esc_attr(wp_create_nonce('stora_coupon_nonce')); ?>" form="stora_checkout_form" data-stora-coupon-nonce>
					<div class="stora-checkout__coupon-msg" aria-live="polite" data-stora-coupon-msg></div>
				</div>

				<div
					class="stora-checkout__order-summary"
					data-stora-order-summary
					data-stora-auto-promotion="<?php echo $has_promotion ? '1' : '0'; ?>"
					data-stora-promotion-label="<?php echo esc_attr($promotion_badge); ?>"
					data-stora-base-total-amount="<?php echo esc_attr($summary_base_total_minor !== null ? (string) (int) $summary_base_total_minor : '0'); ?>"
					data-stora-currency-ref="<?php echo esc_attr($summary_total_formatted); ?>"
				>
					<div class="stora-checkout__order-summary-row stora-checkout__order-summary-row--rent">
						<span class="stora-checkout__order-summary-label">
							<span class="stora-checkout__order-summary-icon" aria-hidden="true">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="14" viewBox="0 0 16 14" fill="none">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M11.7998 7.14691C11.7998 6.81087 11.5323 6.54053 11.1998 6.54053H4.79982C4.46732 6.54053 4.19981 6.81087 4.19981 7.14691L4.19494 7.82338H4.16603L4.16603 7.84466H4.19479L4.19263 8.1442L4.19156 8.19838L4.18836 8.73753L4.16577 11.8739H4.16971L4.16603 12.4936C4.16603 12.8296 4.43354 13.1 4.76604 13.1L11.166 13.1C11.4985 13.1 11.766 12.8296 11.766 12.4936L11.7709 11.8171H11.7998V11.7958H11.7711L11.7732 11.4964L11.7743 11.4421L11.7775 10.9026L11.8001 7.76664H11.7961L11.7998 7.14691Z" fill="currentColor"/>
									<path d="M16 12.5V4.26578C16 3.60634 15.6018 3.01007 14.9943 2.76499L8.2975 0.0578148C8.1075 -0.0192716 7.8925 -0.0192716 7.7025 0.0578148L1.005 2.76499C0.397501 3.01007 0 3.60634 0 4.26578V12.5C0 12.836 0.2675 13.1064 0.6 13.1064H1.8C2.1325 13.1064 2.4 12.836 2.4 12.5V5.57456C2.4 5.15009 2.7575 4.76605 3.2 4.76605H12.8C13.2425 4.76605 13.6 5.15009 13.6 5.57456V12.5C13.6 12.836 13.8687 13.1064 14.2 13.1064H15.4C15.7312 13.1064 16 12.836 16 12.5Z" fill="currentColor"/>
								</svg>
							</span>
							Rent
						</span>
						<span data-stora-summary-rent><?php echo esc_html($summary_rent_formatted !== '' ? $summary_rent_formatted : '-'); ?></span>
					</div>

					<div class="stora-checkout__order-summary-row stora-checkout__order-summary-row--discount"<?php echo $has_promotion ? '' : ' hidden'; ?> data-stora-summary-discount-row>
						<span class="stora-checkout__order-summary-label">
							<span class="stora-checkout__order-summary-icon" aria-hidden="true">
								<i class="fa-solid fa-tags"></i>
							</span>
							<span data-stora-summary-discount-label><?php echo esc_html($promotion_badge !== '' ? $promotion_badge : 'Discount'); ?></span>
						</span>
						<span data-stora-summary-discount-value><?php echo $summary_discount_formatted !== '' ? esc_html('-' . ltrim($summary_discount_formatted, '-')) : '-'; ?></span>
					</div>

					<div class="stora-checkout__order-summary-row stora-checkout__order-summary-row--protection" hidden data-stora-summary-protection-row>
						<span class="stora-checkout__order-summary-label">
							<span class="stora-checkout__order-summary-icon" aria-hidden="true">
								<i class="fa-solid fa-shield-halved"></i>
							</span>
							<span data-stora-summary-protection-label>Protection</span>
						</span>
						<span data-stora-summary-protection-value>-</span>
					</div>

					<div class="stora-checkout__order-summary-row stora-checkout__order-summary-row--total">
						<span data-stora-summary-total-label>First Month Total</span>
						<span data-stora-summary-total><?php echo esc_html($summary_total_formatted !== '' ? $summary_total_formatted : '-'); ?></span>
					</div>
					<span data-stora-summary-subtotal hidden aria-hidden="true"><?php echo esc_html($summary_rent_formatted !== '' ? $summary_rent_formatted : '-'); ?></span>
				</div>

				<?php
				$testimonial_quote = trim((string) ($checkout_testimonial['quote'] ?? ''));
				$testimonial_author = trim((string) ($checkout_testimonial['author'] ?? ''));
				$testimonial_rating = (int) ($checkout_testimonial['rating'] ?? 5);
				if ($testimonial_quote !== ''):
				?>
					<blockquote class="stora-checkout__testimonial">
						<div class="stora-checkout__testimonial-stars" aria-label="<?php echo esc_attr(max(0, min(5, $testimonial_rating)) . ' out of 5 stars'); ?>">
							<?php for ($i = 0; $i < max(0, min(5, $testimonial_rating)); $i++): ?>
								<span aria-hidden="true">★</span>
							<?php endfor; ?>
						</div>
						<p class="stora-checkout__testimonial-quote"><?php echo esc_html($testimonial_quote); ?></p>
						<?php if ($testimonial_author !== ''): ?>
							<cite class="stora-checkout__testimonial-author">- <?php echo esc_html($testimonial_author); ?></cite>
						<?php endif; ?>
					</blockquote>
				<?php endif; ?>
			<?php endif; ?>
		</aside>
	</div>
</div>
