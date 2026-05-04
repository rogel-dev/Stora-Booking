<div class="stora-units">

<?php
$unit_items = [];
if (is_array($units)) {
    if (isset($units['data']) && is_array($units['data'])) {
        $unit_items = $units['data'];
    } elseif (isset($units[0]) && is_array($units[0])) {
        $unit_items = $units;
    }
}

// Only bookable inventory: by default show available units, but allow other statuses via shortcode.
$status_filter = isset($stora_status_filter) && is_string($stora_status_filter) ? strtolower(trim($stora_status_filter)) : 'available';
$unit_items = array_values(array_filter($unit_items, function ($unit) use ($status_filter) {
    if (!is_array($unit)) {
        return false;
    }
    $unit_status = strtolower((string) ($unit['status'] ?? ''));
    if ($unit_status === 'hidden') {
        return false;
    }

    $unit_type_status = '';
    if (isset($unit['unit_type']) && is_array($unit['unit_type'])) {
        $unit_type_status = strtolower((string) ($unit['unit_type']['status'] ?? ''));
    }
    if ($unit_type_status === 'hidden') {
        return false;
    }

    if ($status_filter === 'all') {
        return true;
    }

    // Default: only show units that are currently available to book.
    return $unit_status === $status_filter;
}));

$site_labels = isset($GLOBALS['STORA_SITE_LABELS']) && is_array($GLOBALS['STORA_SITE_LABELS']) ? $GLOBALS['STORA_SITE_LABELS'] : [];
$units_by_site = [];
foreach ($unit_items as $unit) {
    $site_id = isset($unit['site']['id']) ? (string) $unit['site']['id'] : 'unknown';
    // Prefer config map (friendly names), then Stora site.name from API, then a safe fallback.
    $site_label = 'Site';
    if (isset($site_labels[$site_id]) && (string) $site_labels[$site_id] !== '') {
        $site_label = (string) $site_labels[$site_id];
    } elseif (isset($unit['site']['name']) && is_string($unit['site']['name']) && trim($unit['site']['name']) !== '') {
        $site_label = trim($unit['site']['name']);
    }
    if (!isset($units_by_site[$site_id])) {
        $units_by_site[$site_id] = [
            'label' => $site_label,
            'units' => [],
        ];
    }
    $units_by_site[$site_id]['units'][] = $unit;
}
uasort($units_by_site, function ($a, $b) {
    return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
});

// Optional: filter to a single site label (set by shortcode).
if (isset($stora_site_filter) && is_string($stora_site_filter) && trim($stora_site_filter) !== '') {
    $wanted = trim($stora_site_filter);
    $wanted_lc = strtolower($wanted);
    $units_by_site = array_filter($units_by_site, function ($site_data) use ($wanted, $wanted_lc) {
        if (!is_array($site_data)) {
            return false;
        }
        if (!isset($site_data['label']) || !is_string($site_data['label'])) {
            return false;
        }
        $label = trim($site_data['label']);
        if ($label === '') {
            return false;
        }

        // Prefer exact match, but allow partial match so `site="arley"` matches e.g. "Arley Storage".
        if (strcasecmp($label, $wanted) === 0) {
            return true;
        }

        return strpos(strtolower($label), $wanted_lc) !== false;
    });
    $units_by_site = array_values($units_by_site) ? $units_by_site : [];
}
?>

<?php if (!$unit_items): ?>
    <p>
        <?php if ($status_filter === 'all'): ?>
            No units found right now.
        <?php else: ?>
            No units available right now.
        <?php endif; ?>
    </p>
<?php elseif (isset($stora_site_filter) && is_string($stora_site_filter) && trim($stora_site_filter) !== '' && !$units_by_site): ?>
    <p>
        <?php if ($status_filter === 'all'): ?>
            No units found for <?php echo esc_html(trim($stora_site_filter)); ?> right now.
        <?php else: ?>
            No units available for <?php echo esc_html(trim($stora_site_filter)); ?> right now.
        <?php endif; ?>
    </p>
<?php else: ?>

<?php
$category_filter = (isset($stora_category_filter) && is_string($stora_category_filter)) ? strtolower(trim($stora_category_filter)) : '';
$unit_type_filter_enabled = isset($stora_unit_type_filter) && (string) $stora_unit_type_filter === '1' && isset($stora_site_filter) && is_string($stora_site_filter) && trim($stora_site_filter) !== '';
$show_category_toggle = (!$unit_type_filter_enabled) && ($category_filter === '');

// Unit-type filter options from Stora for the currently filtered site only.
$unit_type_options = [];
if ($unit_type_filter_enabled) {
    foreach ($units_by_site as $site_data) {
        if (!is_array($site_data) || !isset($site_data['units']) || !is_array($site_data['units'])) {
            continue;
        }
        foreach ($site_data['units'] as $u) {
            if (!is_array($u) || !isset($u['unit_type']) || !is_array($u['unit_type'])) {
                continue;
            }
            $utid = isset($u['unit_type']['id']) ? (string) $u['unit_type']['id'] : '';
            $utname = isset($u['unit_type']['name']) ? trim((string) $u['unit_type']['name']) : '';
            if ($utid === '' || $utname === '') {
                continue;
            }
            $unit_type_options[$utid] = $utname;
        }
    }
    asort($unit_type_options, SORT_NATURAL | SORT_FLAG_CASE);
}
?>

<?php if ($show_category_toggle): ?>
    <div class="stora-category-toggle" data-stora-category-toggle>
        <button type="button" class="stora-category-toggle__btn is-active" data-category="all">All</button>
        <button type="button" class="stora-category-toggle__btn" data-category="indoor">Indoor storage</button>
        <button type="button" class="stora-category-toggle__btn" data-category="container">Container storage</button>
    </div>
<?php endif; ?>

<?php if ($unit_type_filter_enabled && $unit_type_options): ?>
    <div class="stora-unit-type-toggle" data-stora-unit-type-toggle>
        <button type="button" class="stora-unit-type-toggle__btn is-active" data-unit-type="all">All</button>
        <?php foreach ($unit_type_options as $utid => $utname): ?>
            <button type="button" class="stora-unit-type-toggle__btn" data-unit-type="<?php echo esc_attr($utid); ?>">
                <?php echo esc_html($utname); ?>
            </button>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php foreach ($units_by_site as $group_site_id => $site_data): ?>
    <?php
    $group_label = (string) ($site_data['label'] ?? $group_site_id);
    $site_units = $site_data['units'] ?? [];
    ?>
    <div class="site-group">
        <h2 class="stora-site-heading"><?php echo esc_html($group_label); ?></h2>

        <?php foreach ($site_units as $unit): ?>
            <?php
            $unit_id = $unit['id'] ?? '';
            $unit_type = $unit['unit_type'] ?? [];
            $title = $unit['reference_id'] ?? $unit_id;
            $unit_type_name = $unit_type['name'] ?? '';
            $unit_type_description = '';
            if (isset($unit_type['description']) && is_string($unit_type['description'])) {
                $unit_type_description = trim($unit_type['description']);
            }

            $haystack = strtolower(trim($unit_type_name . ' ' . $unit_type_description));
            $category = (strpos($haystack, 'container') !== false || preg_match('/\b(10|20|40)\s*ft\b/', $haystack)) ? 'container' : 'indoor';
            if ($category_filter && $category !== $category_filter) {
                continue;
            }

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
            if (!empty($unit_type['prices'])) {
                foreach ($unit_type['prices'] as $p) {
                    if (($p['billing_period'] ?? '') === 'monthly') {
                        $price_formatted = $p['price']['formatted'] ?? '';
                        break;
                    }
                }
            }

            $checkout_url = esc_url(add_query_arg('unit_id', $unit_id, home_url('/checkout/')));
            $image_url = Stora_API::resolve_unit_type_image_url($unit_type);
            $placeholder_url = defined('STORA_PLUGIN_URL')
                ? (string) STORA_PLUGIN_URL . 'assets/img/unit-placeholder.png'
                : '';
            ?>

            <div class="stora-unit"
                 data-category="<?php echo esc_attr($category); ?>"
                 <?php if ($unit_type_filter_enabled): ?>
                 data-unit-type-id="<?php echo esc_attr((string)($unit_type['id'] ?? '')); ?>"
                 <?php endif; ?>
                 data-unit-id="<?php echo esc_attr($unit_id); ?>">

                <div class="stora-unit__image">
                    <img
                        src="<?php echo esc_url($image_url ?: $placeholder_url); ?>"
                        alt="<?php echo esc_attr($unit_type_name ? $unit_type_name . ' — ' . $title : $title); ?>"
                        loading="lazy"
                        decoding="async"
                    />
                </div>

                <h3><?php echo esc_html($unit_type_name); ?></h3>
                <?php if ($unit_type_description !== ''): ?>
                    <p class="stora-unit__desc"><?php echo esc_html($unit_type_description); ?></p>
                <?php endif; ?>
                <?php if ($dim_length !== '' || $dim_width !== '' || $dim_height !== ''): ?>
                    <h4>Dimensions</h4>
                    <p class="stora-dimensions">
                        <span><strong>L:</strong> <?php echo esc_html($dim_length ?: '-'); ?></span>
                        <span><strong>W:</strong> <?php echo esc_html($dim_width ?: '-'); ?></span>
                        <span><strong>H:</strong> <?php echo esc_html($dim_height ?: '-'); ?></span>
                    </p>
                <?php endif; ?>
                <?php if ($price_formatted): ?><p>Price: <?php echo esc_html($price_formatted); ?></p><?php endif; ?>

                <div class="stora-actions">
                    <a href="<?php echo $checkout_url; ?>" class="stora-btn stora-btn-primary">Book a Unit</a>
                </div>
            </div>

        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php endif; ?>

</div>
