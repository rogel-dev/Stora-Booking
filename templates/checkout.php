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

$unit_type = is_array($unit) && isset($unit['unit_type']) && is_array($unit['unit_type']) ? $unit['unit_type'] : [];
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
$billing_period = '';
if (!empty($unit_type['prices']) && is_array($unit_type['prices'])) {
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
?>

<div class="stora-checkout">
	<div class="stora-checkout__split">
		<div class="stora-checkout__main">
			<header class="stora-checkout__header">
				<h2 class="stora-checkout__title">Complete your booking</h2>
				<h3 class="stora-checkout__subtitle">Add contact details</h3>
				<p class="stora-checkout__lead">Enter your details so we can send your move-in information</p>
			</header>

			<form id="stora_checkout_form" class="stora-checkout__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" novalidate data-stora-checkout-form>
				<input type="hidden" name="action" value="stora_booking">
				<input type="hidden" name="unit_id" value="<?php echo esc_attr($unit_id); ?>">
				<?php wp_nonce_field('stora_booking_nonce'); ?>

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
					<input class="stora-checkout__input" id="stora_phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" required data-stora-required data-stora-phone>
				</div>

				<div class="stora-checkout__field">
					<label class="stora-checkout__label" for="stora_phone_confirm">Confirm mobile number <abbr class="stora-checkout__req" title="required">*</abbr></label>
					<input class="stora-checkout__input" id="stora_phone_confirm" name="phone_confirm" type="tel" autocomplete="tel" inputmode="tel" required data-stora-required data-stora-phone-confirm>
				</div>

				<p class="stora-checkout__hp" aria-hidden="true">
					<label for="stora_hp">If you are a human, ignore this field</label>
					<input id="stora_hp" name="stora_hp" type="text" value="" tabindex="-1" autocomplete="off">
				</p>

				<div class="stora-checkout__field">
					<label class="stora-checkout__label" for="stora_move_in">Preferred move-in date <abbr class="stora-checkout__req" title="required">*</abbr></label>
					<input class="stora-checkout__input" id="stora_move_in" name="move_in" type="date" required data-stora-required>
				</div>

				<div class="stora-checkout__actions">
					<button type="submit" class="stora-checkout__submit">Proceed to Payment</button>
				</div>
			</form>
		</div>

		<aside class="stora-checkout__side" aria-label="Unit and coupon">
			<?php if ($unit_type_name !== '' || $price_formatted !== '' || $image_url !== '' || $placeholder_url !== ''): ?>
				<div class="stora-checkout__top-summary" aria-label="Selected unit">
					<div class="stora-checkout__top-summary-image">
						<img
							src="<?php echo esc_url(($image_url !== '' ? $image_url : $placeholder_url)); ?>"
							alt="<?php echo esc_attr($unit_type_name !== '' ? ($unit_type_name . ' — ' . $unit_ref) : $unit_ref); ?>"
							loading="lazy"
							decoding="async"
						/>
					</div>
					<div class="stora-checkout__top-summary-body">
						<p class="stora-checkout__top-summary-title"><?php echo esc_html($unit_type_name !== '' ? $unit_type_name : 'Selected unit'); ?></p>
						<?php if ($price_formatted !== ''): ?>
							<p class="stora-checkout__top-summary-price">
								<?php echo esc_html($price_formatted); ?>
								<?php if ($billing_period !== ''): ?><span class="stora-checkout__top-summary-period">/<?php echo esc_html(str_replace('_', ' ', $billing_period)); ?></span><?php endif; ?>
							</p>
						<?php endif; ?>

						<div class="stora-checkout__top-summary-coupon" data-stora-coupon>
							<label class="stora-checkout__label" for="stora_coupon">Coupon code</label>
							<div class="stora-checkout__coupon-inputwrap">
								<input
									class="stora-checkout__input stora-checkout__coupon-input"
									id="stora_coupon"
									name="coupon_code"
									type="text"
									autocomplete="off"
									inputmode="text"
									placeholder="e.g. 5OFF"
									form="stora_checkout_form"
									data-stora-coupon-code
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

						<div class="stora-checkout__order-summary" data-stora-order-summary>
							<?php if ($billing_period !== ''): ?>
								<p class="stora-checkout__order-summary-period">
									Billed every <?php echo esc_html(str_replace('_', ' ', $billing_period)); ?>
								</p>
							<?php endif; ?>

							<div class="stora-checkout__order-summary-row">
								<span>Rent</span>
								<span data-stora-summary-rent><?php echo esc_html($price_formatted !== '' ? $price_formatted : '-'); ?></span>
							</div>

							<div class="stora-checkout__order-summary-row stora-checkout__order-summary-row--subtotal">
								<span>Subtotal</span>
								<span data-stora-summary-subtotal><?php echo esc_html($price_formatted !== '' ? $price_formatted : '-'); ?></span>
							</div>

							<div class="stora-checkout__order-summary-row stora-checkout__order-summary-row--discount" hidden data-stora-summary-discount-row>
								<span data-stora-summary-discount-label>Discount</span>
								<span data-stora-summary-discount-value>-</span>
							</div>

							<div class="stora-checkout__order-summary-row stora-checkout__order-summary-row--total">
								<span>Total</span>
								<span data-stora-summary-total><?php echo esc_html($price_formatted !== '' ? $price_formatted : '-'); ?></span>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</aside>
	</div>
</div>
