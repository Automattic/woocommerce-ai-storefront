# WSN merchant index — WC/WooPayments source fields

Field-level reference for enriching the WSN Elasticsearch merchant index.
Maps each piece of merchant data to its authoritative WC core, WooPayments,
or WooCommerce Shipping source. All reads happen server-side at index time via
`switch_to_blog( $blog_id )`.

Related docs:
- `WSN-SHIPPING-EXTRACTION.md` — extraction recipe + index shape for shipping intel
- `WC-STORE-METADATA-FIELDS.md` — headless consumer perspective (REST endpoints, block paths)

---

## Store identity & mode

### Identity

| What | WP option | Notes |
|---|---|---|
| Store name | `blogname` | WP core |
| Tagline | `blogdescription` | WP core |
| Home URL | `home` | WP core |

### Live vs coming soon (WC 9.3+)

| What | WP option | Values |
|---|---|---|
| Coming soon active | `woocommerce_coming_soon` | `yes` = not yet publicly transacting, `no` = live |
| Scope | `woocommerce_store_pages_only` | `yes` = only `/shop`, `/product/*`, `/cart`, `/checkout` gated; `no` = whole site |

> A store with `woocommerce_coming_soon = yes` should not be shown as actively selling on WSN.
> Older stores (pre-9.3) will have the option absent — treat missing as `no`.

---

## Store address

All top-level WP options. REST equivalent: `GET /wc/v3/settings/general`.

| What | WP option |
|---|---|
| Street line 1 | `woocommerce_store_address` |
| Street line 2 | `woocommerce_store_address_2` |
| City | `woocommerce_store_city` |
| Country / State | `woocommerce_default_country` (`"US:FL"` format — split on `:`) |
| Postcode | `woocommerce_store_postcode` |
| Default currency | `woocommerce_currency` (ISO-4217 uppercase, e.g. `USD`) |

---

## Checkout type

No stored boolean — inspect the Checkout page post content:

```php
$post            = get_post( get_option( 'woocommerce_checkout_page_id' ) );
$is_block_checkout = $post && strpos( $post->post_content, '<!-- wp:woocommerce/checkout' ) !== false;
```

`true` = block-based checkout; `false` = classic shortcode checkout.

---

## WooPayments

### Gateway settings

All sub-keys inside the single serialized option
`woocommerce_woocommerce_payments_settings` (gateway id: `woocommerce_payments`).

| What | Sub-key | Values | Notes |
|---|---|---|---|
| WooPayments active | `enabled` | `yes` / `no` | |
| Test mode toggle | `test_mode` | `yes` / `no` | **Not authoritative** — see `is_live` below |
| **WooPay enabled** | `platform_checkout` | `yes` / `no` | Full eligibility also requires `platform_checkout_eligible = true` in account data |
| **WooPay themed checkout** | `is_woopay_global_theme_support_enabled` | `yes` / `no` | Inherits merchant's block theme colors into WooPay modal; also gated by `is_woopay_global_theme_support_eligible` in account data |
| WooPay store logo | `platform_checkout_store_logo` | attachment ID or URL | Logo shown in WooPay checkout modal |
| WooPay custom message | `platform_checkout_custom_message` | string | Default: `"By placing this order, you agree to our [terms]…"`. Supports `[terms]` and `[privacy_policy]` placeholders |
| Business name | `account_business_name` | string | |
| Business URL | `account_business_url` | string | |
| Support email | `account_business_support_email` | string | |
| Support phone | `account_business_support_phone` | string | |

### Account data — `wcpay_account_data`

Cached from Stripe. Read via `get_option('wcpay_account_data')`.

| What | Path | Type | Notes |
|---|---|---|---|
| **Authoritative live signal** | `is_live` | bool | Set by Stripe onboarding. Trust this over `test_mode`. |
| Payments enabled (KYC passed) | `payments_enabled` | bool | Can actually process transactions |
| Sandbox-only account | `is_test_drive` | bool | |
| WooPay eligible (Stripe-side) | `platform_checkout_eligible` | bool | Gate for `platform_checkout` sub-key above |
| Account email | `email` | string | |
| Merchant country | `country` | ISO-3166 | |
| Default store currency | `store_currencies.default` | string | ISO-4217 **lowercase** (e.g. `usd`) |
| All store currencies | `store_currencies.supported` | array | |
| Customer-chargeable currencies | `customer_currencies.supported` | array | What shoppers can actually pay in |

> **Live/test rule:** `test_mode = no` does NOT mean the account is live — operators
> flip the toggle without finishing onboarding. `is_live` is the only reliable signal.

### WooPay appearance — `wcpay_woopay_checkout_appearance`

Cached WP option. Populated automatically for block themes (computed from
`wp_get_global_styles()`); for classic themes it requires a client-side DOM
extraction pass. Invalidated on theme change, Customizer save, or plugin update.

```jsonc
{
  "appearance": {
    "theme":     "stripe",      // "stripe" (light) | "night" (dark) — derived from bg brightness
    "labels":    "floating",    // "floating" | "above"
    "variables": {
      "colorBackground": "#ffffff",
      "colorText":       "#000000",
      "fontFamily":      "sans-serif",
      "fontSizeBase":    "16px"
    },
    "rules": {
      ".Input":        { "color": "…", "fontFamily": "…", "fontSize": "…", "borderColor": "…",
                         "borderRadius": "…", "backgroundColor": "…" },
      ".Label":        { "color": "…", "fontFamily": "…", "fontSize": "…" },
      ".Text":         { "color": "…", "fontFamily": "…", "fontSize": "…" },
      ".Heading":      { "color": "…", "fontFamily": "…" },
      ".Header":       { "backgroundColor": "…", "color": "…" },
      ".Footer":       { "backgroundColor": "…", "color": "…" },
      ".Footer-link":  { "color": "…" },
      ".Button":       { "color": "…", "backgroundColor": "…", "fontFamily": "…", "fontSize": "…" },
      ".Link":         { "color": "…", "fontFamily": "…" },
      ".Tab":          { "color": "…", "backgroundColor": "…", "fontFamily": "…" },
      ".Block":        { "backgroundColor": "…" }
      // plus .Input--invalid, .Label--resting, .Label--floating, .Tab:hover,
      //      .Tab--selected, .TabIcon*, .TabLabel, .Text--redirect, .Container
    }
  },
  "font_rules": [
    { "cssSrc": "https://fonts.googleapis.com/css2?family=…" }
    // up to 10 entries; only from allowed CDN domains
  ],
  "version": "<md5-hash>"   // invalidated on theme/style changes; checked before use
}
```

**Reading it:**
```php
$stored = get_option( 'wcpay_woopay_checkout_appearance' );
$cache_version = get_option( 'wcpay_styles_cache_version' );
$valid = ! empty( $stored ) && ( $stored['version'] ?? '' ) === $cache_version;
$appearance = $valid ? $stored['appearance'] : null;
$font_rules  = $valid ? $stored['font_rules']  : [];
```

> **Index relevance:** `theme` (`stripe` / `night`) tells you whether the merchant's
> store is visually light or dark — useful for WSN card rendering without needing to
> scrape the site. `variables` gives you the brand's background, text, font, and
> base font size. `null` appearance = merchant has not enabled themed checkout or is on
> a classic theme that hasn't run the extraction pass.

### Multi-currency

| What | WP option | Values |
|---|---|---|
| Feature enabled | `_wcpay_feature_customer_multi_currency` | `'1'` enabled (default), `'0'` disabled |
| Enabled currencies list | `wcpay_multi_currency_enabled_currencies` | array of ISO-4217 codes — always includes default currency |

---

## WooCommerce Shipping

All shipping data lives in the REST API, not in WP options.

### Zone coverage

| What | REST endpoint | Notes |
|---|---|---|
| All zones | `GET /wc/v3/shipping/zones` | **Zone 0 (Rest of World) is NOT listed here** |
| Zone locations | `GET /wc/v3/shipping/zones/{id}/locations` | `type=country` → ISO code; `type=continent` → expand with `WC()->countries->get_continents()[$code]['countries']` |
| Zone 0 (worldwide fallback) | `GET /wc/v3/shipping/zones/0/methods` | Non-empty + any `enabled=true` → merchant ships worldwide |

### Free shipping

Per zone via `GET /wc/v3/shipping/zones/{id}/methods` → entries with `method_id = "free_shipping"` + `enabled = true`.

| Setting | REST field | Values | Notes |
|---|---|---|---|
| Threshold | `settings.min_amount.value` | decimal string | Only meaningful when `requires` ∈ `{min_amount, either, both}` |
| Qualifier | `settings.requires.value` | `""` / `coupon` / `min_amount` / `either` / `both` | **Never flatten `both` or `either` to just a dollar amount** — `both` requires coupon AND min_amount |
| Pre/post-discount | `settings.ignore_discounts.value` | `yes` / `no` | Whether min_amount is evaluated before or after coupons |

### Local pickup

Per zone via same endpoint → entries with `method_id = "local_pickup"` + `enabled = true`.

| What | Source | Notes |
|---|---|---|
| Offered | Any enabled `local_pickup` method | Boolean |
| Location title | Method `title` | Merchants often embed location name here |
| Address — single location | WP options: `woocommerce_store_address*`, `woocommerce_default_country` | Only valid when no multi-location plugin detected |
| Address — multi-location | Plugin-specific (see table below) | Detect plugin before reading address |

**Multi-location plugin detection:**

| Plugin slug | Addresses stored in |
|---|---|
| `woocommerce-local-pickup-plus/…php` | CPT `wc_pickup_location` post meta |
| `woocommerce-distance-rate-shipping/…php` | WP option `woocommerce_distance_rate_shipping_locations` |
| `wc-store-locator/…php` | CPT `wc_store_locator` |

---

## Store pages

### WC registered pages

`wc_get_page_id( $key )` resolves to `get_option( 'woocommerce_{key}_page_id' )`.

| Key | Option | Default slug | Notes |
|---|---|---|---|
| `shop` | `woocommerce_shop_page_id` | `/shop` | |
| `cart` | `woocommerce_cart_page_id` | `/cart` | |
| `checkout` | `woocommerce_checkout_page_id` | `/checkout` | |
| `myaccount` | `woocommerce_myaccount_page_id` | `/my-account` | |
| `refund_returns` | `woocommerce_refund_returns_page_id` | `/refund_returns` | Created as **draft** — check `post_status = publish` before using permalink |
| `terms` | `woocommerce_terms_page_id` | merchant-configured | |

### Terms of Service

| What | Source | Notes |
|---|---|---|
| Page URL | `get_permalink( get_option('woocommerce_terms_page_id') )` | |
| Checkbox label (shortcode checkout) | WP option `woocommerce_checkout_terms_and_conditions_checkbox_text` | Default: `"I have read and agree to the website [terms]"` |
| Terms text (block checkout) | Block attribute `text` on `woocommerce/checkout-terms-block` in Checkout page post content | Empty → falls back to above option; read via `parse_blocks()` |

### Privacy Policy

| What | Source | Notes |
|---|---|---|
| Page URL | `get_permalink( get_option('wp_page_for_privacy_policy') )` | **WP core option, not a WC option** — reading "all WC options" misses this |
| Privacy notice at checkout | WP option `woocommerce_checkout_privacy_policy_text` | WC option; default includes `[privacy_policy]` placeholder |
| Privacy notice at registration | WP option `woocommerce_registration_privacy_policy_text` | |

### Refund & returns policy

| What | Source | Notes |
|---|---|---|
| Page URL | `get_permalink( get_option('woocommerce_refund_returns_page_id') )` | Created as a draft — verify `post_status = publish` first |

---

## No WC core field — requires crawl/scrape

| Data | Best available source |
|---|---|
| Handling time (days to ship) | Schema.org `OfferShippingDetails.handlingTime` on product pages → plugin postmeta (`_lead_time`, `_handling_time`, `_processing_time`) → method title regex → LLM policy extraction |
| Transit / delivery time | Schema.org `OfferShippingDetails.transitTime` → method title regex → LLM |
| Shipping policy URL | Slug walk: `shipping`, `shipping-policy`, `shipping-and-returns`, `delivery`, `shipping-info` |
| Return window days | LLM extraction from returns page content |
| **About page URL** | **No WC or WP core option.** Slug walk: `about`, `about-us`, `our-story`, `who-we-are`, `company` |

```php
// Generic slug-walk pattern for pages with no registered option
$candidates = [ 'about', 'about-us', 'our-story', 'who-we-are' ];
foreach ( $candidates as $slug ) {
    $page = get_page_by_path( $slug );
    if ( $page && 'publish' === $page->post_status ) {
        // record get_permalink( $page->ID )
        break;
    }
}
// Fallback: WP_Query title search
$q = new WP_Query( [ 'post_type' => 'page', 's' => 'about', 'posts_per_page' => 3 ] );
```

> See `WSN-SHIPPING-EXTRACTION.md` for the full extraction recipe, index shape, and
> placement of crawl workers for schema.org / policy page scraping.
