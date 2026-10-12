# Stora Booking Integration

WordPress plugin for Self Storage Centre — browse units, complete checkout, apply coupons, and redirect customers to Stora hosted payment.

**Version:** 1.1.0

## Requirements

- WordPress 6.0+
- PHP 7.4+
- HTTPS on the live site (required for Stora payment redirect)
- Stora Public API credentials with booking scopes

## Deploy to live

### 1. Upload the plugin

Deploy the entire `stora-booking` folder to:

`wp-content/plugins/stora-booking/`

Include all assets (`assets/vendor/fontawesome`, `assets/img`, templates, and includes).

### 2. Activate and flush permalinks

1. Activate **Stora Booking Integration** in WP Admin → Plugins
2. Visit **Settings → Permalinks** and click **Save** (flushes rewrite rules for `/storage-units/...` and checkout URLs)

### 3. Configure Stora credentials

Go to **WP Admin → Stora** and configure **one** of:

- **OAuth Client ID + Client Secret** (recommended), or
- **API key** (long-lived access token)

Set **OAuth scope** to the full booking list (pre-filled):

```
public.site:read public.unit:read public.unit_type:read public.contact:write public.order:write public.order:read public.coupon:read public.protection_level:read
```

Alternatively define credentials in `wp-config.php` (never commit secrets):

```php
define('STORA_API_KEY', 'your-token'); // optional if using OAuth options
```

### 4. Configure webhook (production)

1. Copy the **Webhook URL** from the Stora admin page
2. Register it in Stora with unit availability events
3. Paste the **Webhook secret** from Stora into the plugin settings

Unsigned webhooks are **rejected** unless you explicitly enable local development:

```php
// wp-config.php — local/staging ONLY
define('STORA_WEBHOOK_ALLOW_UNSIGNED', true);
```

### 5. WordPress pages and shortcodes

| Page | Purpose | Shortcode / URL |
|------|---------|-----------------|
| Units hub | All locations | `[stora_locations]` |
| Units list | Per-site units | `[stora_units]` on a page slug `units` (see `STORA_UNITS_PAGE_SLUG`) |
| Checkout | Booking form | `[stora_checkout]` or `/checkout/` route |

SEO-friendly unit URLs are configured in `config.php` via `STORA_SITE_SLUGS`, e.g. `/storage-units/warrington-latchford/`.

### 6. Pre-launch test checklist

- [ ] Units load for each location with correct prices
- [ ] Book flow: contact → details → protection → move-in → consent
- [ ] Valid coupon applies and updates totals
- [ ] Invalid/replaced coupon reverts totals
- [ ] Checkout redirects to Stora payment
- [ ] Booked unit disappears after webhook (or within cache TTL)
- [ ] Mobile checkout usable
- [ ] Admin → Stora shows no credential/scope/webhook warnings

## Security

- Checkout form uses WordPress nonces and a honeypot field
- Coupon/total preview AJAX is nonce-protected and rate-limited (30 req/min/IP)
- Webhook requests require HMAC signature when a secret is configured
- Checkout pages send `noindex`, `nocache`, and basic security headers
- Stora API debug output is only appended for administrators

## Operations

- **Clear Stora cache:** WP Admin → Stora → Maintenance → Clear Stora cache
- **Unit cache TTL:** 15 minutes (`STORA_CACHE_TTL` in `config.php`)
- **Coupon lookup cache:** 10 minutes (`STORA_COUPON_LOOKUP_TTL`)
- Plugin version upgrades automatically flush rewrite rules and clear API caches

## Configuration

Site-specific content (locations, phone numbers, testimonials, storefront price pages) lives in `config.php`. Adjust `STORA_LOCATIONS`, `STORA_SITE_SLUGS`, and related globals for your operator.

## Support

Plugin by [Bloom Digital Marketing](https://bloomdigitalmarketing.uk/).
