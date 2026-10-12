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

// Include all non-hidden inventory by default; sold-out unit types still appear with a label.
$status_filter = isset($stora_status_filter) && is_string($stora_status_filter) ? strtolower(trim($stora_status_filter)) : 'all';
$stora_api = (isset($api) && $api instanceof Stora_API) ? $api : (class_exists('Stora_API') ? new Stora_API() : null);
$include_sold_out_catalog = ($status_filter === 'all');
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

// Optional: filter to a single site id (set by query string / code).
if (isset($stora_site_id_filter) && is_string($stora_site_id_filter) && trim($stora_site_id_filter) !== '') {
    $wanted_site_id = trim($stora_site_id_filter);
    $units_by_site = isset($units_by_site[$wanted_site_id]) ? [$wanted_site_id => $units_by_site[$wanted_site_id]] : [];
}

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
<?php elseif (((isset($stora_site_filter) && is_string($stora_site_filter) && trim($stora_site_filter) !== '') || (isset($stora_site_id_filter) && is_string($stora_site_id_filter) && trim($stora_site_id_filter) !== '')) && !$units_by_site): ?>
    <p>
        <?php if ($status_filter === 'all'): ?>
            No units found for <?php echo esc_html(trim((string) ($stora_site_filter !== '' ? $stora_site_filter : $stora_site_id_filter))); ?> right now.
        <?php else: ?>
            No units available for <?php echo esc_html(trim((string) ($stora_site_filter !== '' ? $stora_site_filter : $stora_site_id_filter))); ?> right now.
        <?php endif; ?>
    </p>
<?php else: ?>

<?php
$unit_type_filter_enabled = isset($stora_unit_type_filter) && (string) $stora_unit_type_filter === '1';

// If we're filtered to a single site, show a right-hand info column pulled from STORA_LOCATIONS.
$is_single_site_view = is_array($units_by_site) && count($units_by_site) === 1;
$single_site_id = $is_single_site_view ? (string) array_key_first($units_by_site) : '';
$single_site_label = $is_single_site_view ? (string) (($units_by_site[$single_site_id]['label'] ?? '') ?: '') : '';
$locations_cfg = isset($GLOBALS['STORA_LOCATIONS']) && is_array($GLOBALS['STORA_LOCATIONS']) ? $GLOBALS['STORA_LOCATIONS'] : [];
$site_location = null;
if ($is_single_site_view && $single_site_label !== '' && $locations_cfg) {
    foreach ($locations_cfg as $loc) {
        if (!is_array($loc)) {
            continue;
        }
        $loc_label = trim((string) ($loc['site_label'] ?? $loc['label'] ?? ''));
        if ($loc_label !== '' && strcasecmp($loc_label, $single_site_label) === 0) {
            $site_location = $loc;
            break;
        }
    }
}

// Unit-type filter options from Stora across all returned units (all sites shown by this shortcode).
$unit_type_options = [];
if ($unit_type_filter_enabled) {
    foreach ($unit_items as $u) {
        if (!is_array($u) || !isset($u['unit_type']) || !is_array($u['unit_type'])) {
            continue;
        }
        // When showing "available" only, restrict unit-type options to bookable units.
        if ($status_filter !== 'all' && strtolower((string) ($u['status'] ?? '')) !== 'available') {
            continue;
        }
        $utid = isset($u['unit_type']['id']) ? (string) $u['unit_type']['id'] : '';
        $utname = isset($u['unit_type']['name']) ? trim((string) $u['unit_type']['name']) : '';
        if ($utid === '' || $utname === '') {
            continue;
        }
        $unit_type_options[$utid] = $utname;
    }
    asort($unit_type_options, SORT_NATURAL | SORT_FLAG_CASE);
}

$storage_categories_cfg = null;
$active_storage_category = '';
$show_storage_category_picker = false;
$active_category_def = null;
$single_site_types = [];

if ($is_single_site_view && $single_site_label !== '') {
    $categories_by_site = isset($GLOBALS['STORA_SITE_STORAGE_CATEGORIES']) && is_array($GLOBALS['STORA_SITE_STORAGE_CATEGORIES'])
        ? $GLOBALS['STORA_SITE_STORAGE_CATEGORIES']
        : [];
    foreach ($categories_by_site as $site_key => $cfg) {
        if (!is_array($cfg) || empty($cfg['categories'])) {
            continue;
        }
        if (strcasecmp((string) $site_key, $single_site_label) === 0) {
            $storage_categories_cfg = $cfg;
            break;
        }
    }
}

if ($storage_categories_cfg) {
    if (class_exists('Stora_Routes')) {
        $route_category = Stora_Routes::current_category_slug();
        if ($route_category !== '') {
            $active_storage_category = $route_category;
        }
    }
    if ($active_storage_category === '' && isset($_GET['stora_category'])) {
        $active_storage_category = sanitize_text_field((string) wp_unslash($_GET['stora_category']));
    }
}
if ($storage_categories_cfg && $active_storage_category !== '') {
    $active_category_def = Stora_Units::find_storage_category($storage_categories_cfg, $active_storage_category);
    if (!$active_category_def) {
        $active_storage_category = '';
    }
}

if ($storage_categories_cfg && $active_storage_category === '') {
    $show_storage_category_picker = true;
}

if ($is_single_site_view && isset($units_by_site[$single_site_id]['units']) && is_array($units_by_site[$single_site_id]['units'])) {
    $single_site_types = Stora_Units::build_site_unit_types_display(
        $single_site_id,
        $units_by_site[$single_site_id]['units'],
        $stora_api,
        $include_sold_out_catalog
    );
}

$site_page_title = '';
$site_intro_paragraphs = [];
$show_site_page_header = false;

if ($storage_categories_cfg) {
    $site_page_title = trim((string) ($storage_categories_cfg['page_title'] ?? ''));
    if ($site_page_title === '' && $single_site_label !== '') {
        $site_page_title = 'Self Storage in ' . $single_site_label;
    }
    $show_site_page_header = true;
} elseif ($is_single_site_view && $single_site_label !== '') {
    $site_pages_cfg = isset($GLOBALS['STORA_SITE_PAGES']) && is_array($GLOBALS['STORA_SITE_PAGES'])
        ? $GLOBALS['STORA_SITE_PAGES']
        : [];
    foreach ($site_pages_cfg as $site_key => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        if (strcasecmp((string) $site_key, $single_site_label) === 0) {
            $site_page_title = trim((string) ($cfg['page_title'] ?? ''));
            if ($site_page_title === '') {
                $site_page_title = 'Self Storage in ' . $single_site_label;
            }
            $show_site_page_header = true;
            break;
        }
    }
    if (!$show_site_page_header && is_array($site_location)) {
        $site_page_title = 'Self Storage in ' . $single_site_label;
        $show_site_page_header = true;
    }
}

if ($show_site_page_header && is_array($site_location) && $active_storage_category === '') {
    $intro_main = trim((string) ($site_location['intro'] ?? ''));
    $intro_extra = trim((string) ($site_location['intro_extra'] ?? ''));
    if ($intro_main !== '') {
        $site_intro_paragraphs[] = $intro_main;
    }
    if ($intro_extra !== '') {
        $site_intro_paragraphs[] = $intro_extra;
    }
}
?>

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

<?php if ($is_single_site_view): ?>
    <div class="stora-site-layout">
        <div class="stora-site-layout__main">
<?php endif; ?>

<?php if ($show_storage_category_picker): ?>
    <?php if ($site_page_title !== ''): ?>
        <h1 class="stora-site-page-title"><?php echo esc_html(stora_heading_case($site_page_title)); ?></h1>
    <?php endif; ?>

    <?php if ($site_intro_paragraphs): ?>
        <div class="stora-site-intro">
            <?php foreach ($site_intro_paragraphs as $paragraph): ?>
                <p><?php echo esc_html($paragraph); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="site-group site-group--categories">
        <?php
        $placeholder_url = defined('STORA_PLUGIN_URL')
            ? (string) STORA_PLUGIN_URL . 'assets/img/unit-placeholder.png'
            : '';
        $category_list = isset($storage_categories_cfg['categories']) && is_array($storage_categories_cfg['categories'])
            ? $storage_categories_cfg['categories']
            : [];
        foreach ($category_list as $cat):
            if (!is_array($cat) || empty($cat['id'])) {
                continue;
            }
            $cat_id = (string) $cat['id'];
            $cat_label = trim((string) ($cat['label'] ?? ''));
            $cat_subtitle = trim((string) ($cat['subtitle'] ?? $cat_label));
            $from_price = Stora_Units::storage_category_from_price($single_site_types, $cat, $storage_categories_cfg);
            $cat_image = trim((string) ($cat['image_url'] ?? ''));

            if ($cat_image === '') {
                foreach ($single_site_types as $t) {
                    $ut = isset($t['unit_type']) && is_array($t['unit_type']) ? $t['unit_type'] : [];
                    if (!Stora_Units::unit_type_matches_storage_category($ut, $cat, $storage_categories_cfg)) {
                        continue;
                    }
                    $cat_image = Stora_API::resolve_unit_type_image_url($ut);
                    if ($cat_image !== '') {
                        break;
                    }
                }
            }

            $select_url = class_exists('Stora_Routes') && $single_site_id !== ''
                ? Stora_Routes::units_url($single_site_id, $cat_id)
                : add_query_arg('stora_category', $cat_id);
            ?>
            <article class="stora-unit stora-unit-card stora-storage-category">
                <div class="stora-unit-row">
                    <div class="stora-unit-row__media">
                        <img
                            src="<?php echo esc_url($cat_image !== '' ? $cat_image : $placeholder_url); ?>"
                            alt="<?php echo esc_attr($cat_label); ?>"
                            loading="lazy"
                            decoding="async"
                        />
                    </div>

                    <div class="stora-unit-row__main">
                        <h3 class="stora-unit-row__title"><?php echo esc_html(stora_heading_case($cat_label)); ?></h3>
                        <?php if ($cat_subtitle !== ''): ?>
                            <p class="stora-storage-category__subtitle"><?php echo esc_html($cat_subtitle); ?></p>
                        <?php endif; ?>
                        <?php if ($from_price !== ''): ?>
                            <p class="stora-storage-category__from">
                                from <strong><?php echo esc_html($from_price); ?></strong><span class="stora-unit-row__price-suffix">/week (inc. tax)</span>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="stora-unit-row__aside">
                        <a href="<?php echo esc_url($select_url); ?>" class="stora-btn stora-btn-primary">Select</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

<?php else: ?>

<?php foreach ($units_by_site as $group_site_id => $site_data): ?>
    <?php
    $group_label = (string) ($site_data['label'] ?? $group_site_id);
    $site_units = $site_data['units'] ?? [];
    $types = ($is_single_site_view && $group_site_id === $single_site_id && $single_site_types)
        ? $single_site_types
        : Stora_Units::build_site_unit_types_display($group_site_id, $site_units, $stora_api, $include_sold_out_catalog);
    ?>

    <?php if (!$storage_categories_cfg && $show_site_page_header && $site_page_title !== '' && $is_single_site_view && $group_site_id === $single_site_id): ?>
        <h1 class="stora-site-page-title"><?php echo esc_html(stora_heading_case($site_page_title)); ?></h1>
    <?php endif; ?>

    <?php if ($site_intro_paragraphs && !$storage_categories_cfg && $is_single_site_view && $group_site_id === $single_site_id): ?>
        <div class="stora-site-intro">
            <?php foreach ($site_intro_paragraphs as $paragraph): ?>
                <p><?php echo esc_html($paragraph); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="site-group">
        <div class="stora-site-header">
            <?php if ($active_category_def): ?>
                <a class="stora-site-back" href="<?php echo esc_url(class_exists('Stora_Routes') && $single_site_id !== '' ? Stora_Routes::units_url($single_site_id) : remove_query_arg('stora_category')); ?>">← Go back</a>
                <h2 class="stora-site-heading"><?php echo esc_html(stora_heading_case((string) ($active_category_def['label'] ?? $group_label))); ?></h2>
            <?php elseif ($show_site_page_header && $is_single_site_view && $group_site_id === $single_site_id): ?>
                <h2 class="stora-site-heading stora-site-subtitle--section">Storage Sizes</h2>
            <?php else: ?>
                <h2 class="stora-site-heading"><?php echo esc_html(stora_heading_case($group_label)); ?></h2>
            <?php endif; ?>
            <?php if (!$storage_categories_cfg && (!$show_site_page_header || !$is_single_site_view || $group_site_id !== $single_site_id || $active_category_def)): ?>
            <h2 class="stora-site-subtitle">Storage Sizes</h2>
            <?php endif; ?>
        </div>

        <?php
        $category_unit_count = 0;
        foreach ($types as $utid => $t):
            $unit_type = isset($t['unit_type']) && is_array($t['unit_type']) ? $t['unit_type'] : [];
            if ($active_category_def && !Stora_Units::unit_type_matches_storage_category($unit_type, $active_category_def, $storage_categories_cfg)) {
                continue;
            }
            $category_unit_count++;
            ?>

            <?php
            $unit_type_name = isset($unit_type['name']) ? (string) $unit_type['name'] : '';
            $unit_type_description = '';
            if (isset($unit_type['description']) && is_string($unit_type['description'])) {
                $unit_type_description = trim($unit_type['description']);
            }

            // Size hint ("Size of a walk in wardrobe") if provided by Stora or curated in config.php.
            $size_hint = '';
            foreach (['size_hint', 'size_description', 'short_description', 'subtitle', 'tagline'] as $k) {
                if (!empty($unit_type[$k]) && is_string($unit_type[$k])) {
                    $size_hint = trim((string) $unit_type[$k]);
                    break;
                }
            }
            if ($size_hint === '') {
                $hints = isset($GLOBALS['STORA_UNIT_TYPE_HINTS']) && is_array($GLOBALS['STORA_UNIT_TYPE_HINTS'])
                    ? $GLOBALS['STORA_UNIT_TYPE_HINTS']
                    : [];
                $by_id = isset($hints['unit_type_id']) && is_array($hints['unit_type_id']) ? $hints['unit_type_id'] : [];
                $by_name = isset($hints['unit_type_name']) && is_array($hints['unit_type_name']) ? $hints['unit_type_name'] : [];
                if (isset($by_id[$utid]) && is_string($by_id[$utid]) && trim((string) $by_id[$utid]) !== '') {
                    $size_hint = trim((string) $by_id[$utid]);
                } elseif ($unit_type_name !== '' && isset($by_name[$unit_type_name]) && is_string($by_name[$unit_type_name]) && trim((string) $by_name[$unit_type_name]) !== '') {
                    $size_hint = trim((string) $by_name[$unit_type_name]);
                }
            }

            $dim_label = '';
            $dims = $unit_type['dimensions'] ?? null;
            if (is_array($dims)) {
                $l = $dims['length'] ?? null;
                $w = $dims['width'] ?? null;
                $mu = isset($dims['measurement_unit']) ? (string) $dims['measurement_unit'] : '';
                if (is_numeric($l) && is_numeric($w)) {
                    $lf = (float) $l;
                    $wf = (float) $w;
                    $fmt = static function ($n) {
                        $f = (float) $n;
                        return (floor($f) === $f) ? (string) (int) $f : (string) $f;
                    };
                    $dim_label = $fmt($lf) . ' × ' . $fmt($wf) . ($mu !== '' ? (' ' . $mu) : '');
                }
            }

            if ($stora_api instanceof Stora_API && $group_site_id !== '') {
                $unit_type = $stora_api->resolve_canonical_unit_type_for_display($unit_type, $group_site_id);
            }

            $price_formatted = Stora_API::resolve_unit_type_weekly_display_price($unit_type, $group_site_id);
            $unit_promotion = Stora_API::resolve_unit_type_promotion($unit_type);
            $promotion_badge = $unit_promotion ? Stora_API::format_unit_type_promotion_badge($unit_promotion) : '';
            $promo_prices = Stora_API::resolve_unit_type_promotion_weekly_prices($unit_type, $group_site_id);
            $has_promotion = is_array($promo_prices);
            $display_price = $has_promotion ? (string) $promo_prices['discounted'] : $price_formatted;
            $regular_price_after = $has_promotion ? (string) $promo_prices['regular'] : '';

            $available_unit_id = isset($t['available_unit_id']) ? (string) $t['available_unit_id'] : '';
            $available_count = (int) ($t['available_count'] ?? 0);
            $is_available = $available_unit_id !== '' && $available_count > 0;
            $checkout_url = $is_available
                ? esc_url(class_exists('Stora_Routes') ? Stora_Routes::checkout_url($available_unit_id) : add_query_arg('unit_id', $available_unit_id, home_url('/checkout/')))
                : '';

            $image_url = Stora_API::resolve_unit_type_image_url($unit_type);
            $placeholder_url = defined('STORA_PLUGIN_URL')
                ? (string) STORA_PLUGIN_URL . 'assets/img/unit-placeholder.png'
                : '';

            $unit_detail_url = class_exists('Stora_Routes') && $group_site_id !== ''
                ? Stora_Routes::unit_url($group_site_id, $unit_type, $active_storage_category)
                : '';

            $available_count = (int) ($t['available_count'] ?? 0);
            $total_count = (int) ($t['total_count'] ?? 0);
            ?>

            <article class="stora-unit stora-unit-card <?php echo $is_available ? 'is-available' : 'is-soldout'; ?><?php echo $has_promotion ? ' has-promotion' : ''; ?>"
                <?php if ($unit_type_filter_enabled): ?>
                    data-unit-type-id="<?php echo esc_attr($utid); ?>"
                <?php endif; ?>
            >
                <div class="stora-unit-row">
                    <div class="stora-unit-row__media">
                        <img
                            src="<?php echo esc_url($image_url ?: $placeholder_url); ?>"
                            alt="<?php echo esc_attr($unit_type_name); ?>"
                            loading="lazy"
                            decoding="async"
                        />
                    </div>

                    <div class="stora-unit-row__main">
                        <?php if ($unit_detail_url !== ''): ?>
                            <h3 class="stora-unit-row__title">
                                <a class="stora-unit-row__title-link" href="<?php echo esc_url($unit_detail_url); ?>"><?php echo esc_html(stora_format_unit_size_label($unit_type_name)); ?></a>
                            </h3>
                        <?php else: ?>
                            <h3 class="stora-unit-row__title"><?php echo esc_html(stora_format_unit_size_label($unit_type_name)); ?></h3>
                        <?php endif; ?>
                        <?php if ($size_hint !== ''): ?>
                            <p class="stora-unit-row__hint"><?php echo esc_html($size_hint); ?></p>
                        <?php endif; ?>
                        <?php if ($promotion_badge !== ''): ?>
                            <p class="stora-unit-row__promo">
                                <i class="fa-solid fa-tags" aria-hidden="true"></i>
                                <span><?php echo esc_html($promotion_badge); ?></span>
                            </p>
                        <?php endif; ?>
                        <?php if ($unit_type_description !== ''): ?>
                            <div class="stora-unit-row__desc"><?php echo esc_html($unit_type_description); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="stora-unit-row__aside">
                        <?php if ($display_price !== ''): ?>
                            <div class="stora-unit-row__price">
                                <div class="stora-unit-row__price-line">
                                    <?php echo esc_html($display_price); ?><span class="stora-unit-row__price-suffix">/week (inc. tax)</span>
                                </div>
                                <?php if ($regular_price_after !== ''): ?>
                                    <p class="stora-unit-row__price-after"><?php echo esc_html($regular_price_after); ?> after discount period</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($is_available): ?>
                            <a href="<?php echo $checkout_url; ?>" class="stora-btn stora-btn-primary">Book now</a>
                        <?php else: ?>
                            <span class="stora-unit-row__soldout">Sold out</span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($active_category_def && $category_unit_count === 0): ?>
            <p>No <?php echo esc_html(strtolower((string) ($active_category_def['label'] ?? 'storage'))); ?> units are available right now.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php endif; ?>

<?php if ($is_single_site_view): ?>
        </div>
		<aside class="stora-site-layout__aside" aria-label="Location information">
            <?php if (is_array($site_location)): ?>
                <?php
                $hide_sidebar_intro = !empty($site_intro_paragraphs) || $active_storage_category !== '';
                include STORA_PLUGIN_PATH . 'templates/partials/site-aside.php';
                ?>
            <?php endif; ?>
        </aside>
    </div>
<?php endif; ?>
<?php endif; ?>

</div>
