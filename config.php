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
    // 15 minutes — webhooks clear the cache early when unit availability changes.
    define('STORA_CACHE_TTL', 900);
}

if (!defined('STORA_COUPON_LOOKUP_TTL')) {
    // Coupon code index used during checkout apply.
    define('STORA_COUPON_LOOKUP_TTL', 600);
}

if (!defined('STORA_MOVE_IN_DATES_CACHE_TTL')) {
    // Move-in availability probing is API-heavy; cache longer than unit lists.
    define('STORA_MOVE_IN_DATES_CACHE_TTL', 3600);
}

if (!defined('STORA_STALE_CACHE_TTL')) {
    // Fallback unit data when Stora returns HTTP 429 (rate limit).
    define('STORA_STALE_CACHE_TTL', DAY_IN_SECONDS);
}

if (!defined('STORA_API_RATE_LIMIT_RETRIES')) {
    // Automatic retries when Stora returns HTTP 429 too_many_requests.
    define('STORA_API_RATE_LIMIT_RETRIES', 3);
}

if (!defined('STORA_AJAX_RATE_LIMIT')) {
    // Max coupon/total preview requests per IP per minute.
    define('STORA_AJAX_RATE_LIMIT', 30);
}

/**
 * OAuth scopes required for the full booking flow (units + checkout + coupons).
 * Override in wp-config.php if Stora issues a custom scope list.
 */
if (!defined('STORA_OAUTH_SCOPE_DEFAULT')) {
    define('STORA_OAUTH_SCOPE_DEFAULT', implode(' ', [
        'public.site:read',
        'public.unit:read',
        'public.unit_type:read',
        'public.contact:write',
        'public.order:write',
        'public.order:read',
        'public.coupon:read',
        'public.protection_level:read',
    ]));
}

/**
 * Set to true in wp-config.php on local/staging only to accept unsigned webhooks
 * when no webhook secret is configured. Never enable on production.
 */
if (!defined('STORA_WEBHOOK_ALLOW_UNSIGNED')) {
    define('STORA_WEBHOOK_ALLOW_UNSIGNED', false);
}

if (!defined('STORA_WEBHOOK_CACHE_CLEAR_LIMIT')) {
    // Max cache flushes triggered by webhooks per minute.
    define('STORA_WEBHOOK_CACHE_CLEAR_LIMIT', 30);
}

if (!defined('STORA_MOVE_IN_MIN_DAYS')) {
    // Earliest move-in is this many days after today (1 = tomorrow).
    define('STORA_MOVE_IN_MIN_DAYS', 1);
}

if (!defined('STORA_MOVE_IN_MAX_DAYS')) {
    // Fallback window when Stora date probing is unavailable.
    define('STORA_MOVE_IN_MAX_DAYS', 3);
}

if (!defined('STORA_MOVE_IN_PROBE_DAYS')) {
    // How many consecutive days to probe against Stora when resolving availability.
    define('STORA_MOVE_IN_PROBE_DAYS', 14);
}

/**
 * Site ID → label for grouping and `[stora_units site="…"]` filtering.
 * IDs come from GET /units (`site.id` on each unit).
 *
 * If an id is missing here, the template falls back to `site.name` from Stora when present.
 */
/**
 * Stora hosted storefront URLs used for weekly list prices shown to customers.
 * Required when dynamic occupancy pricing is enabled: the Public API returns the
 * current dynamic rate while the hosted storefront shows the marketing weekly price.
 *
 * Keys are Stora site ids (see STORA_SITE_LABELS).
 */
if (!isset($GLOBALS['STORA_STOREFRONT_PRICE_PAGES']) || !is_array($GLOBALS['STORA_STOREFRONT_PRICE_PAGES'])) {
    $GLOBALS['STORA_STOREFRONT_PRICE_PAGES'] = [
        'site_4c8c1d18c89fe1c8' => 'https://bookings.selfstoragecentre.co.uk/sites/warrington-self-storage-units/',
        'site_415ba1e5137a7aeb' => 'https://bookings.selfstoragecentre.co.uk/sites/warrington-central-self-storage-units/',
        'site_18b374f39049edce' => 'https://bookings.selfstoragecentre.co.uk/sites/winsford-self-storage-units/',
        'site_0d8eb5079a64831c' => 'https://bookings.selfstoragecentre.co.uk/sites/arley-self-storage-units/',
    ];
}

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

/**
 * Stora site ID → URL slug for public unit listing pages.
 * Example: /storage-units/warrington-latchford/
 */
if (!isset($GLOBALS['STORA_SITE_SLUGS']) || !is_array($GLOBALS['STORA_SITE_SLUGS'])) {
    $GLOBALS['STORA_SITE_SLUGS'] = [
        'site_18b374f39049edce' => 'winsford',
        'site_4c8c1d18c89fe1c8' => 'warrington-latchford',
        'site_415ba1e5137a7aeb' => 'warrington-central',
        'site_0d8eb5079a64831c' => 'arley',
    ];
}

if (!defined('STORA_UNITS_BASE_PATH')) {
    define('STORA_UNITS_BASE_PATH', 'storage-units');
}

if (!defined('STORA_UNITS_PAGE_SLUG')) {
    define('STORA_UNITS_PAGE_SLUG', 'units');
}

if (!defined('STORA_CHECKOUT_PATH')) {
    define('STORA_CHECKOUT_PATH', 'checkout');
}

/**
 * Sidebar content for `[stora_locations]` (right column).
 */
if (!isset($GLOBALS['STORA_LOCATIONS_SIDEBAR']) || !is_array($GLOBALS['STORA_LOCATIONS_SIDEBAR'])) {
    $GLOBALS['STORA_LOCATIONS_SIDEBAR'] = [
        'cta_title' => 'Book Online Today',
        'cta_text' => 'Instantly reserve safe and secure storage with our simple online booking system.',
        'good_to_know' => [
            'Flexible contract',
            'Open daily',
            'Safe and secure',
            'Extended opening hours available',
        ],
    ];
}

/**
 * Location cards for `[stora_locations]`.
 * Keep `site_label` values aligned with STORA_SITE_LABELS (or Stora site.name) so filtering works.
 */
if (!isset($GLOBALS['STORA_LOCATIONS']) || !is_array($GLOBALS['STORA_LOCATIONS'])) {
    $GLOBALS['STORA_LOCATIONS'] = [
        [
            'label' => 'Warrington Latchford',
            'site_label' => 'Warrington Latchford',
            'intro' => 'Modern self storage units ideally located on the A50 in Warrington.',
            'hours' => [
                'Mon - Sun 6:00am - 8:00pm',
            ],
            'good_to_know' => [
                'Flexible contract',
                'Open daily',
                'Safe and secure',
                'Extended opening hours available',
            ],
            'address_lines' => [
                'Jamie House',
                'Knutsford Road',
                'Warrington',
                'WA4 1JE',
            ],
            'phone' => '01925 989 540',
            'access_type' => 'PIN Code',
            'directions_url' => 'https://maps.app.goo.gl/NQPcJKPX194aCAcx8',
            'image_url' => STORA_PLUGIN_URL . 'assets/img/warrington-latchford.png',
        ],
        [
            'label' => 'Winsford',
            'site_label' => 'Winsford',
            'intro' => 'Located in Winsford, we offer both indoor and container self storage to local residents, and those further afield.',
            'intro_extra' => 'Our flexible terms means that we only require one month\'s notice, however we always have customers who stay with us for years!',
            'hours' => [
                'Mon - Sun 6:00am - 8:00pm',
            ],
            'good_to_know' => [
                'Flexible contract',
                'Open daily',
                'Safe and secure',
            ],
            'address_lines' => [
                '1 Leslie Road',
                'Winsford',
                'CW7 2RB',
            ],
            'phone' => '01606 531 531',
            'access_type' => 'PIN Code',
            'directions_url' => 'https://maps.app.goo.gl/axfpAmAWUB2JmvHV7',
            'image_url' => STORA_PLUGIN_URL . 'assets/img/winsford.png',
        ],
        [
            'label' => 'Arley',
            'site_label' => 'Arley',
            'intro' => 'Secure container storage at Burley Heyes, with drive-up access for personal and business use near Appleton and Warrington.',
            'hours' => [
                'Mon - Sun 8:00am - 6:00pm',
            ],
            'good_to_know' => [
                'Flexible contract',
                'Open daily',
                'Safe and secure',
                'Extended opening hours available',
            ],
            'address_lines' => [
                'Burley Heyes',
                'Arley Road',
                'Stretton, Warrington',
                'WA4 4RS',
            ],
            'phone' => '01925 989 540',
            'access_type' => 'Padlock',
            'directions_url' => 'https://maps.app.goo.gl/SSTKbqkBZ5zYxM7AA',
            'image_url' => STORA_PLUGIN_URL . 'assets/img/arley.png',
        ],
        [
            'label' => 'Warrington Central',
            'site_label' => 'Warrington Central',
            'hours' => [
                'Mon - Sun 6:00am - 8:00pm',
            ],
            'address_lines' => [
                'Napier Street',
                'Warrington',
                'WA1 2HF',
            ],
            'phone' => '01925 989 540',
            'access_type' => 'PIN Code / App',
            'directions_url' => 'https://maps.app.goo.gl/EtZ7aEZeBVJJMgFMA',
            'image_url' => STORA_PLUGIN_URL . 'assets/img/warrington-central.jpg',
        ],
    ];
}

/**
 * Single-site units page copy (title + intro) when not using the storage-category picker.
 * Keys match STORA_SITE_LABELS values (e.g. Arley, Warrington Central).
 */
if (!isset($GLOBALS['STORA_SITE_PAGES']) || !is_array($GLOBALS['STORA_SITE_PAGES'])) {
    $GLOBALS['STORA_SITE_PAGES'] = [
        'Arley' => [
            'page_title' => 'Self Storage in Arley',
        ],
        'Warrington Latchford' => [
            'page_title' => 'Self Storage in Warrington Latchford',
        ],
        'Warrington Central' => [
            'page_title' => 'Self Storage in Warrington Central',
        ],
    ];
}

/**
 * Optional storage-type picker shown before unit sizes (e.g. Indoor vs Container at Winsford).
 *
 * Matching options per category:
 * - unit_type_ids: explicit Stora unit type ids (most reliable)
 * - match_names: substring match in name, slug, descriptions, selling points
 * - exclude_names: exclude unit types containing these substrings
 * - match_inverse_of: match everything that does NOT match another category id
 */
if (!isset($GLOBALS['STORA_SITE_STORAGE_CATEGORIES']) || !is_array($GLOBALS['STORA_SITE_STORAGE_CATEGORIES'])) {
    $GLOBALS['STORA_SITE_STORAGE_CATEGORIES'] = [
        'Winsford' => [
            'page_title' => 'Self Storage in Winsford',
            'categories' => [
                [
                    'id' => 'indoor',
                    'label' => 'Indoor Storage',
                    'subtitle' => 'Secure lockable rooms from 15 sq ft',
                    'match_names' => ['sq ft', 'sqft', 'square foot', 'square feet'],
                    'exclude_names' => ['office', 'container'],
                    'image_url' => '',
                ],
                [
                    'id' => 'container',
                    'label' => 'Container Storage',
                    'subtitle' => 'About the size of a single garage',
                    'match_names' => ['container', '10ft', '20ft', '40ft', 'ft long', 'steel'],
                    'image_url' => '',
                ],
            ],
        ],
    ];
}

/**
 * Optional: "size hint" copy shown under each unit size card.
 *
 * Stora doesn't always provide marketing copy like "Size of a walk in wardrobe" on unit types,
 * so you can curate it here by unit type id (preferred) or by unit type name.
 *
 * Examples:
 * - $GLOBALS['STORA_UNIT_TYPE_HINTS']['unit_type_id']['ut_123'] = 'Size of a walk in wardrobe';
 * - $GLOBALS['STORA_UNIT_TYPE_HINTS']['unit_type_name']['20 sq ft'] = 'Size of a walk in wardrobe';
 */
if (!isset($GLOBALS['STORA_UNIT_TYPE_HINTS']) || !is_array($GLOBALS['STORA_UNIT_TYPE_HINTS'])) {
    $GLOBALS['STORA_UNIT_TYPE_HINTS'] = [
        'unit_type_id' => [
            // 'ut_...' => 'Size of a walk in wardrobe',
        ],
        'unit_type_name' => [
            // '20 sq ft' => 'Size of a walk in wardrobe',
        ],
    ];
}

/**
 * Optional testimonial shown in the checkout sidebar.
 */
if (!isset($GLOBALS['STORA_CHECKOUT_TESTIMONIAL']) || !is_array($GLOBALS['STORA_CHECKOUT_TESTIMONIAL'])) {
    $GLOBALS['STORA_CHECKOUT_TESTIMONIAL'] = [
        'quote' => 'Great experience with self storage facility. Neil keeps the containers and rooms in a spotless condition, and was very helpful with any questions I had. It really was painless.',
        'author' => 'Peter E',
        'rating' => 5,
    ];
}

/**
 * Email address shown on the protection step for customers using their own cover.
 */
if (!defined('STORA_PROTECTION_EVIDENCE_EMAIL')) {
    define('STORA_PROTECTION_EVIDENCE_EMAIL', 'info@selfstoragecentre.co.uk');
}

/**
 * Example placeholder for checkout phone fields only.
 * Any phone number with 10–15 digits is accepted (not UK-only).
 */
if (!defined('STORA_CHECKOUT_PHONE_PLACEHOLDER')) {
    define('STORA_CHECKOUT_PHONE_PLACEHOLDER', '+44 07400 123456');
}

if (!defined('STORA_TERMS_URL')) {
    define('STORA_TERMS_URL', 'https://bookings.selfstoragecentre.co.uk/terms/');
}

if (!defined('STORA_PRIVACY_URL')) {
    define('STORA_PRIVACY_URL', 'https://bookings.selfstoragecentre.co.uk/privacy/');
}