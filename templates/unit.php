<?php
if (!defined('ABSPATH')) {
	exit;
}

$unit_type = isset($type_data['unit_type']) && is_array($type_data['unit_type']) ? $type_data['unit_type'] : [];
$utid = isset($unit_type['id']) ? (string) $unit_type['id'] : '';
$unit_type_name = trim((string) ($unit_type['name'] ?? ''));
$size_hint = Stora_Units::resolve_size_hint($unit_type, $utid);

if ($api instanceof Stora_API && $stora_site_id_filter !== '') {
	$unit_type = $api->resolve_canonical_unit_type_for_display($unit_type, $stora_site_id_filter);
}

$price_formatted = Stora_API::resolve_unit_type_weekly_display_price($unit_type, $stora_site_id_filter);
$unit_promotion = Stora_API::resolve_unit_type_promotion($unit_type);
$promotion_badge = $unit_promotion ? Stora_API::format_unit_type_promotion_badge($unit_promotion) : '';
$promo_prices = Stora_API::resolve_unit_type_promotion_weekly_prices($unit_type, $stora_site_id_filter);
$has_promotion = is_array($promo_prices);
$display_price = $has_promotion ? (string) $promo_prices['discounted'] : $price_formatted;
$regular_price_after = $has_promotion ? (string) $promo_prices['regular'] : '';

$available_unit_id = isset($type_data['available_unit_id']) ? (string) $type_data['available_unit_id'] : '';
$available_count = (int) ($type_data['available_count'] ?? 0);
$is_available = $available_unit_id !== '' && $available_count > 0;
$checkout_url = $is_available
	? (class_exists('Stora_Routes') ? Stora_Routes::checkout_url($available_unit_id) : add_query_arg('unit_id', $available_unit_id, home_url('/checkout/')))
	: '';

$image_url = Stora_API::resolve_unit_type_image_url($unit_type);
$placeholder_url = defined('STORA_PLUGIN_URL')
	? (string) STORA_PLUGIN_URL . 'assets/img/unit-placeholder.png'
	: '';

$dims = isset($unit_type['dimensions']) && is_array($unit_type['dimensions']) ? $unit_type['dimensions'] : [];
$dim_length = isset($dims['length']) && is_numeric($dims['length']) ? (float) $dims['length'] : null;
$dim_width = isset($dims['width']) && is_numeric($dims['width']) ? (float) $dims['width'] : null;
$dim_height = isset($dims['height']) && is_numeric($dims['height']) ? (float) $dims['height'] : null;
$dim_unit = trim((string) ($dims['measurement_unit'] ?? 'ft'));
$has_dimensions = $dim_length !== null && $dim_width !== null;

$format_dim = static function ($n) {
	$f = (float) $n;
	return (floor($f) === $f) ? (string) (int) $f : (string) $f;
};

$phone = is_array($site_location) ? trim((string) ($site_location['phone'] ?? '')) : '';
$phone_display = function_exists('stora_format_display_phone') ? stora_format_display_phone($phone) : $phone;

$units_list_url = class_exists('Stora_Routes')
	? Stora_Routes::units_url($stora_site_id_filter, isset($category_slug) ? (string) $category_slug : '')
	: home_url('/units/');
?>

<div class="stora-units stora-unit-detail">
	<div class="stora-unit-detail__hero">
		<div class="stora-unit-detail__hero-content">
			<a class="stora-unit-detail__back" href="<?php echo esc_url($units_list_url); ?>">← View all storage sizes</a>

			<h1 class="stora-unit-detail__title"><?php echo esc_html(stora_format_unit_size_label($unit_type_name)); ?></h1>

			<?php if ($size_hint !== ''): ?>
				<p class="stora-unit-detail__hint"><?php echo esc_html($size_hint); ?></p>
			<?php endif; ?>

			<?php if ($has_dimensions): ?>
				<details class="stora-unit-detail__dims" open>
					<summary class="stora-unit-detail__dims-summary">Dimensions</summary>
					<div class="stora-unit-detail__dims-panel">
						<?php if ($dim_length !== null): ?>
							<span>Length <?php echo esc_html($format_dim($dim_length) . $dim_unit); ?></span>
						<?php endif; ?>
						<?php if ($dim_width !== null): ?>
							<span>Width <?php echo esc_html($format_dim($dim_width) . $dim_unit); ?></span>
						<?php endif; ?>
						<?php if ($dim_height !== null): ?>
							<span>Height <?php echo esc_html($format_dim($dim_height) . $dim_unit); ?></span>
						<?php endif; ?>
					</div>
				</details>
			<?php endif; ?>

			<?php if ($promotion_badge !== ''): ?>
				<p class="stora-unit-detail__promo">
					<i class="fa-solid fa-tags" aria-hidden="true"></i>
					<span><?php echo esc_html($promotion_badge); ?></span>
				</p>
			<?php endif; ?>

			<?php if ($display_price !== ''): ?>
				<div class="stora-unit-detail__price">
					<strong><?php echo esc_html($display_price); ?></strong><span class="stora-unit-row__price-suffix">/week (inc. tax)</span>
					<?php if ($regular_price_after !== ''): ?>
						<p class="stora-unit-detail__price-after"><?php echo esc_html($regular_price_after); ?> after discount period</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ($is_available): ?>
				<a href="<?php echo esc_url($checkout_url); ?>" class="stora-btn stora-btn-primary stora-unit-detail__cta">Book Now</a>
			<?php endif; ?>

			<?php if ($phone !== ''): ?>
				<p class="stora-unit-detail__help">
					Need help? <a href="<?php echo esc_url('tel:' . preg_replace('/\D+/', '', $phone)); ?>"><?php echo esc_html($phone_display); ?></a>
				</p>
			<?php endif; ?>
		</div>

		<div class="stora-unit-detail__hero-media">
			<img
				src="<?php echo esc_url($image_url ?: $placeholder_url); ?>"
				alt="<?php echo esc_attr($unit_type_name); ?>"
				loading="eager"
				decoding="async"
			/>
		</div>
	</div>
</div>
