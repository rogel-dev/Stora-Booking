<?php
if (!defined('ABSPATH')) {
	exit;
}

$placeholder_url = defined('STORA_PLUGIN_URL') ? (string) STORA_PLUGIN_URL . 'assets/img/unit-placeholder.png' : '';

$page_title = isset($page_title) ? trim((string) $page_title) : 'Our Locations';
$sidebar = isset($sidebar) && is_array($sidebar) ? $sidebar : [];

$cta_title = trim((string) ($sidebar['cta_title'] ?? 'Book Online Today'));
$cta_text = trim((string) ($sidebar['cta_text'] ?? ''));
$good_to_know = isset($sidebar['good_to_know']) && is_array($sidebar['good_to_know']) ? $sidebar['good_to_know'] : [];

// Normalise config structure.
$items = [];
if (is_array($locations)) {
	foreach ($locations as $loc) {
		if (is_array($loc) && !empty($loc['label'])) {
			$items[] = $loc;
		}
	}
}
?>

<div class="stora-locations" aria-label="<?php echo esc_attr($page_title !== '' ? stora_heading_case($page_title) : 'Our Locations'); ?>">
	<div class="stora-locations__split">
		<div class="stora-locations__main">
			<?php if ($page_title !== ''): ?>
				<h1 class="stora-locations__page-title"><?php echo esc_html(stora_heading_case($page_title)); ?></h1>
			<?php endif; ?>

			<?php if (!$items): ?>
				<p class="stora-locations__empty">No locations found.</p>
			<?php else: ?>
				<div class="stora-locations__list">
					<?php foreach ($items as $loc): ?>
						<?php
						$label = trim((string) ($loc['label'] ?? ''));
						$address_lines = isset($loc['address_lines']) && is_array($loc['address_lines']) ? $loc['address_lines'] : [];
						$phone = trim((string) ($loc['phone'] ?? ''));
						$phone_display = function_exists('stora_format_display_phone') ? stora_format_display_phone($phone) : $phone;
						$access_type = trim((string) ($loc['access_type'] ?? ''));
						$hours = isset($loc['hours']) && is_array($loc['hours']) ? $loc['hours'] : [];
						$image_url = trim((string) ($loc['image_url'] ?? ''));
						$site_label = trim((string) ($loc['site_label'] ?? $label));

						$site_labels = isset($GLOBALS['STORA_SITE_LABELS']) && is_array($GLOBALS['STORA_SITE_LABELS']) ? $GLOBALS['STORA_SITE_LABELS'] : [];
						$site_id = '';
						if ($site_labels) {
							$site_id = (string) array_search($site_label, $site_labels, true);
						}

						$prices_url = class_exists('Stora_Routes')
							? Stora_Routes::units_url($site_id !== '' ? $site_id : $site_label)
							: ($site_id !== ''
								? add_query_arg('stora_site_id', $site_id, $units_url)
								: add_query_arg('stora_site', $site_label, $units_url));

						$directions_url = trim((string) ($loc['directions_url'] ?? ''));
						if ($directions_url === '' && $address_lines) {
							$directions_query = implode(', ', array_map('trim', array_filter(array_map('strval', $address_lines))));
							if ($directions_query !== '') {
								$directions_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($directions_query);
							}
						}

						$phone_href = $phone !== '' ? preg_replace('/\D+/', '', $phone) : '';
						?>

						<article class="stora-location">
							<header class="stora-location__header">
								<h2 class="stora-location__title"><?php echo esc_html(stora_heading_case($label)); ?></h2>
								<a class="stora-location__cta" href="<?php echo esc_url($prices_url); ?>">See Prices</a>
							</header>

							<div class="stora-location__content">
								<div class="stora-location__image">
									<img
										src="<?php echo esc_url($image_url !== '' ? $image_url : $placeholder_url); ?>"
										alt="<?php echo esc_attr(sprintf('Image of %s site', $label)); ?>"
										loading="lazy"
										decoding="async"
									/>
								</div>

								<?php if ($address_lines): ?>
									<div class="stora-location__address">
										<h4 class="stora-location__label">Address</h4>
										<address class="stora-location__address-lines">
											<?php foreach ($address_lines as $line): ?>
												<?php
												$line = trim((string) $line);
												if ($line === '') {
													continue;
												}
												?>
												<span><?php echo esc_html($line); ?></span>
											<?php endforeach; ?>
										</address>
										<?php if ($directions_url !== ''): ?>
											<a class="stora-location__link" href="<?php echo esc_url($directions_url); ?>" target="_blank" rel="noopener noreferrer">Get Directions</a>
										<?php endif; ?>
									</div>
								<?php endif; ?>

								<div class="stora-location__details">
									<?php if ($phone !== ''): ?>
										<div class="stora-location__detail">
											<h4 class="stora-location__label">Phone</h4>
											<?php if ($phone_href !== ''): ?>
												<a class="stora-location__link" href="<?php echo esc_url('tel:' . $phone_href); ?>"><?php echo esc_html($phone_display); ?></a>
											<?php else: ?>
												<p class="stora-location__value"><?php echo esc_html($phone_display); ?></p>
											<?php endif; ?>
										</div>
									<?php endif; ?>

									<?php if ($access_type !== '' || $hours): ?>
										<div class="stora-location__detail">
											<h4 class="stora-location__label">Access</h4>
											<?php if ($access_type !== ''): ?>
												<p class="stora-location__value"><?php echo esc_html($access_type); ?></p>
											<?php endif; ?>
											<?php foreach ($hours as $h): ?>
												<?php
												$h = trim((string) $h);
												if ($h === '') {
													continue;
												}
												?>
												<p class="stora-location__value"><?php echo esc_html($h); ?></p>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<aside class="stora-locations__side" aria-label="Booking information">
			<?php if ($cta_title !== '' || $cta_text !== ''): ?>
				<div class="stora-locations__promo">
					<?php if ($cta_title !== ''): ?>
						<p class="stora-locations__promo-title"><?php echo esc_html(stora_heading_case($cta_title)); ?></p>
					<?php endif; ?>
					<?php if ($cta_text !== ''): ?>
						<p class="stora-locations__promo-text"><?php echo esc_html($cta_text); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ($good_to_know): ?>
				<div class="stora-locations__good-to-know">
					<p class="stora-locations__good-to-know-title">Good to Know</p>
					<ul class="stora-locations__good-to-know-list">
						<?php foreach ($good_to_know as $item): ?>
							<?php
							$item = trim((string) $item);
							if ($item === '') {
								continue;
							}
							?>
							<li class="stora-locations__good-to-know-item">
								<span class="stora-locations__good-to-know-icon" aria-hidden="true">
									<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
										<circle cx="8" cy="8" r="8" fill="#e8641b"/>
										<path d="M4.5 8.25L6.75 10.5L11.5 5.75" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</span>
								<span><?php echo esc_html($item); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</aside>
	</div>
</div>
