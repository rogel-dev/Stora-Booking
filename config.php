<?php
if (!defined('STORA_API_BASE')) {
    // Stora Public API (versioned)
    define('STORA_API_BASE', 'https://public-api.stora.co/2025-09/');
}

if (!defined('STORA_API_KEY')) {
    // Prefer defining STORA_API_KEY in wp-config.php; otherwise use saved option; then env var.
    $stora_api_key = '';

    if (function_exists('get_option')) {
        $opt = get_option('stora_api_key', '');
        if (is_string($opt) && $opt !== '') {
            $stora_api_key = $opt;
        }
    }

    if ($stora_api_key === '') {
        $env = getenv('STORA_API_KEY');
        if (is_string($env) && $env !== '') {
            $stora_api_key = $env;
        }
    }

    define('STORA_API_KEY', (string) $stora_api_key);
}

if (!defined('STORA_CACHE_TTL')) {
    define('STORA_CACHE_TTL', 300);
}

// WordPress core constants should typically be set in wp-config.php.
// Guard these to avoid "already defined" fatals when WordPress loads this plugin.
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', true);
}

/**
 * Site ID → label for grouping and `[stora_units site="…"]` filtering.
 * IDs come from GET /units (`site.id` on each unit). Optional: while logged in as admin,
 * use shortcode `[stora_sites debug="1"]` on a draft page to list site ids and names.
 *
 * If an id is missing here, the template falls back to `site.name` from Stora when present.
 */
if (!isset($GLOBALS['STORA_SITE_LABELS']) || !is_array($GLOBALS['STORA_SITE_LABELS'])) {
    $GLOBALS['STORA_SITE_LABELS'] = [
        'site_18b374f39049edce' => 'Winsford',
        'site_4c8c1d18c89fe1c8' => 'Warrington Latchford',
        'site_415ba1e5137a7aeb' => 'Warrington Central',
        'site_0d8eb5079a64831c' => 'Arley',
        // Add the Stora site ids for these locations when you have them (see [stora_sites] or dashboard):
        // 'site_…' => 'Warrington Central',
        // 'site_…' => 'Arley',
    ];
}