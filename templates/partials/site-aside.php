<?php
if (!defined('ABSPATH')) {
	exit;
}

if (!is_array($site_location)) {
	return;
}

$addr = isset($site_location['address_lines']) && is_array($site_location['address_lines']) ? $site_location['address_lines'] : [];
$phone = trim((string) ($site_location['phone'] ?? ''));
$phone_display = function_exists('stora_format_display_phone') ? stora_format_display_phone($phone) : $phone;
$access = trim((string) ($site_location['access_type'] ?? ''));
$intro = trim((string) ($site_location['intro'] ?? ''));
$hours = isset($site_location['hours']) && is_array($site_location['hours']) ? $site_location['hours'] : [];
$good = isset($site_location['good_to_know']) && is_array($site_location['good_to_know']) ? $site_location['good_to_know'] : [];
$hero_image = trim((string) ($site_location['sidebar_image_url'] ?? $site_location['image_url'] ?? ''));
$site_label = isset($single_site_label) ? trim((string) $single_site_label) : '';

$directions_url = trim((string) ($site_location['directions_url'] ?? ''));
if ($directions_url === '' && $addr) {
	$directions_query = implode(', ', array_map('trim', array_filter(array_map('strval', $addr))));
	if ($directions_query !== '') {
		$directions_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($directions_query);
	}
}

$sidebar_good = $good;
if (!$sidebar_good && isset($GLOBALS['STORA_LOCATIONS_SIDEBAR']['good_to_know']) && is_array($GLOBALS['STORA_LOCATIONS_SIDEBAR']['good_to_know'])) {
	$sidebar_good = $GLOBALS['STORA_LOCATIONS_SIDEBAR']['good_to_know'];
}
?>

<?php if ($hero_image !== '' && $phone !== ''): ?>
	<div class="stora-site-hero">
		<img
			class="stora-site-hero__img"
			src="<?php echo esc_url($hero_image); ?>"
			alt="<?php echo esc_attr($site_label !== '' ? $site_label : 'Site photo'); ?>"
			loading="lazy"
			decoding="async"
		/>
		<div class="stora-site-hero__overlay">
			<p class="stora-site-hero__overlay-title">Have a Question? Call Us</p>
			<a class="stora-site-hero__overlay-phone" href="<?php echo esc_url('tel:' . preg_replace('/\D+/', '', $phone)); ?>">
				<?php echo esc_html($phone_display); ?>
			</a>
		</div>
	</div>
<?php endif; ?>

<div class="stora-site-info">
		<p class="stora-site-info__title">Location</p>
	<?php if ($addr): ?>
		<address class="stora-site-info__address">
			<?php foreach ($addr as $line): ?>
				<?php $line = trim((string) $line); if ($line === '') continue; ?>
				<span><?php echo esc_html($line); ?></span>
			<?php endforeach; ?>
		</address>
	<?php endif; ?>
	<?php if ($directions_url !== ''): ?>
		<a class="stora-site-info__link" href="<?php echo esc_url($directions_url); ?>" target="_blank" rel="noopener noreferrer">Get Directions</a>
	<?php endif; ?>
</div>

<?php if ($access !== '' || $hours): ?>
	<div class="stora-site-info">
		<p class="stora-site-info__title">Access</p>
		<?php if ($access !== ''): ?>
			<p class="stora-site-info__text"><?php echo esc_html($access); ?></p>
		<?php endif; ?>
		<?php foreach ($hours as $h): ?>
			<?php $h = trim((string) $h); if ($h === '') continue; ?>
			<p class="stora-site-info__text"><?php echo esc_html($h); ?></p>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ($sidebar_good): ?>
	<div class="stora-site-info">
		<p class="stora-site-info__title">Good to Know</p>
		<ul class="stora-site-info__list stora-site-info__list--checks">
			<?php foreach ($sidebar_good as $g): ?>
				<?php $g = trim((string) $g); if ($g === '') continue; ?>
				<li>
					<span class="stora-site-info__check" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
							<circle cx="8" cy="8" r="8" fill="#e8641b"/>
							<path d="M4.5 8.25L6.75 10.5L11.5 5.75" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</span>
					<span><?php echo esc_html($g); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ($intro !== '' && empty($hide_sidebar_intro)): ?>
	<div class="stora-site-info">
		<p class="stora-site-info__title">About</p>
		<p class="stora-site-info__text"><?php echo esc_html($intro); ?></p>
	</div>
<?php endif; ?>
