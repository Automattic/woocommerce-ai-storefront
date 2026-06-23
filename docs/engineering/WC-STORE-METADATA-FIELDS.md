# WooCommerce / WooPayments — store metadata field reference

Inventory of the actual option keys, gateway sub-options, REST endpoints, and
block attributes that hold the data a headless storefront commonly needs:
store address, sender identity, payments state, shipping config, and the
Terms / Privacy policy plumbing (shortcode **and** block paths).

All keys verified against local checkouts of `woocommerce` (core) and
`woocommerce-payments` (WCPay).

> **Three storage shapes, three fetchers.** Settings live as (1) top-level
> WP options, (2) sub-keys inside a single serialized gateway-settings array,
> or (3) **block attributes on the Checkout page's post content**. A headless
> layer that only reads WP options will silently miss block-side strings.

---

## 1. Store address (WC core)

Source: `includes/admin/settings/class-wc-settings-general.php`. All
top-level WP options.

| Field                   | Option key                             | Notes                                                      |
| ----------------------- | -------------------------------------- | ---------------------------------------------------------- |
| Street line 1           | `woocommerce_store_address`            | string                                                     |
| Street line 2           | `woocommerce_store_address_2`          | string                                                     |
| City                    | `woocommerce_store_city`               | string                                                     |
| Country / State         | `woocommerce_default_country`          | `"US:CA"` form — country and state joined with `:`         |
| Postcode                | `woocommerce_store_postcode`           | string                                                     |
| Currency                | `woocommerce_currency`                 | ISO-4217 uppercase                                         |
| Currency position       | `woocommerce_currency_pos`             | `left` / `right` / `left_space` / `right_space`            |
| Thousand separator      | `woocommerce_price_thousand_sep`       | string                                                     |
| Decimal separator       | `woocommerce_price_decimal_sep`        | string                                                     |
| Num decimals            | `woocommerce_price_num_decimals`       | integer                                                    |
| Default customer addr   | `woocommerce_default_customer_address` | `geolocation` / `base` / `geolocation_ajax`                |
| Tax calc enabled        | `woocommerce_calc_taxes`               | `yes` / `no`                                               |
| Prices include tax      | `woocommerce_prices_include_tax`       | `yes` / `no`                                               |
| Weight unit             | `woocommerce_weight_unit`              | products tab                                               |
| Dimension unit          | `woocommerce_dimension_unit`           | products tab                                               |

**Headless fetch:** `GET /wp-json/wc/v3/settings/general` returns all of the
above as an array of setting objects with `id` / `value`.

---

## 2. Emails — From / Reply-To (WC core)

Source: `includes/admin/settings/class-wc-settings-emails.php`.

| Field              | Option key                                      |
| ------------------ | ----------------------------------------------- |
| **From name**      | `woocommerce_email_from_name`                   |
| **From address**   | `woocommerce_email_from_address`                |
| Header image       | `woocommerce_email_header_image`                |
| Header image width | `woocommerce_email_header_image_width`          |
| Footer text        | `woocommerce_email_footer_text`                 |
| Brand colors       | `woocommerce_email_base_color`, `woocommerce_email_background_color`, `woocommerce_email_body_background_color`, `woocommerce_email_text_color`, `woocommerce_email_footer_text_color` |

### Reply-To is not stored as a setting

`WC_Email::get_headers()` assembles Reply-To at send-time:

- **Customer-facing emails** → Reply-To = `woocommerce_email_from_name <woocommerce_email_from_address>`
- **Admin-facing emails** (new order, failed order, etc.) → Reply-To = order
  billing first_name/last_name `<billing_email>` (the customer's address — so
  "Reply" from a new-order notification messages the customer)

If your headless layer sends its own mail, replicate that pattern or filter
`woocommerce_email_headers`. Don't expect a stored option.

---

## 3. WooPayments — support, currencies, live/test mode

Two separate stores: the **gateway settings** (locally editable on the WP
site) and the **remote account object** (cached in option
`wcpay_account_data`).

### 3a. Gateway settings

Stored as sub-keys of the single serialized option
`woocommerce_woocommerce_payments_settings`. Gateway id is `woocommerce_payments`.

Source: `class-wc-payment-gateway-wcpay.php`.

| Setting                | Sub-key                              | Notes                          |
| ---------------------- | ------------------------------------ | ------------------------------ |
| Enabled                | `enabled`                            | `yes` / `no`                   |
| **Live / test mode**   | `test_mode`                          | `yes` = sandbox, `no` = live   |
| Business name          | `account_business_name`              |                                |
| Business URL           | `account_business_url`               |                                |
| **Support email**      | `account_business_support_email`     |                                |
| **Support phone**      | `account_business_support_phone`     |                                |
| Support address        | `account_business_support_address`   | array                          |
| Branding logo / icon   | `account_branding_logo`, `account_branding_icon` |                    |

The canonical accessors are `WC_Payments_Account::get_business_support_email()`
and `get_business_support_phone()`, which fall back to the stored gateway
option when the live account fetch is unavailable.

### 3b. Live account object (`wcpay_account_data`)

Source: `class-wc-payments-account.php`.

| Field                                  | Path                                | Notes                                            |
| -------------------------------------- | ----------------------------------- | ------------------------------------------------ |
| **Is live**                            | `is_live`                           | boolean — the canonical signal                   |
| Is test-drive (sandbox onboarded)      | `is_test_drive`                     | boolean                                          |
| Account email                          | `email`                             |                                                  |
| Country                                | `country`                           |                                                  |
| **Default store currency**             | `store_currencies.default`          | ISO-4217 **lowercase** (e.g. `usd`)              |
| **All store currencies**               | `store_currencies.supported`        | array                                            |
| **Customer-chargeable currencies**     | `customer_currencies.supported`     | array — `get_account_customer_supported_currencies()` |
| Payments enabled                       | `payments_enabled`                  | boolean — KYC passed                             |
| Deposits enabled / interval            | `deposits.*`                        |                                                  |
| Capabilities                           | `capabilities`                      | per-payment-method status                        |

### Live vs test — which source to trust?

> Prefer `wcpay_account_data.is_live` over the gateway `test_mode` toggle.
> Operators sometimes flip the gateway toggle without finishing onboarding,
> so `test_mode = no` doesn't actually mean the account is processing live
> charges. `is_live` is set by the Stripe-side onboarding result and is the
> authoritative bit.

### Headless fetch

- **Cheap local read:** `get_option('woocommerce_woocommerce_payments_settings')`
  and `get_option('wcpay_account_data')`
- **REST:** `/wp-json/wc/v3/payments/accounts` (admin-only); status via
  `WCPay\Status`.

---

## 4. Shipping data for a headless cart

### 4a. Top-level shipping options

Source: `class-wc-settings-shipping.php`.

| Option                                       | Purpose                                  |
| -------------------------------------------- | ---------------------------------------- |
| `woocommerce_enable_shipping_calc`           | Show cart shipping calculator            |
| `woocommerce_shipping_cost_requires_address` | Hide rates until address is entered      |
| `woocommerce_ship_to_destination`            | `billing` / `shipping` / `billing_only`  |
| `woocommerce_shipping_tax_class`             | Inherit vs explicit class                |

### 4b. Zones, methods, classes — surface via REST

| Endpoint                                                  | Returns                                                                                                                |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `GET /wp-json/wc/v3/shipping/zones`                       | All zones (`id`, `name`, `order`)                                                                                      |
| `GET /wp-json/wc/v3/shipping/zones/{id}/locations`        | Country / state / postcode / continent matchers per zone                                                               |
| `GET /wp-json/wc/v3/shipping/zones/{id}/methods`          | Method instances; each has `method_id` (`free_shipping` / `flat_rate` / `local_pickup`), `enabled`, plus `settings.*`  |
| `GET /wp-json/wc/v3/shipping_methods`                     | Method catalog (definitions, not instances)                                                                            |
| `GET /wp-json/wc/v3/products/shipping_classes`            | Shipping classes                                                                                                       |
| `GET /wp-json/wc/store/v1/cart`                           | **Per-cart live rates** under `shipping_rates[]` — the right call for a headless cart UI                               |

### 4c. Free-shipping instance settings

Method id `free_shipping`, source
`includes/shipping/free-shipping/class-wc-shipping-free-shipping.php`.

Returned inside `settings` for each zone method:

```text
title             - label shown at checkout
requires          - "" | "coupon" | "min_amount" | "either" | "both"
min_amount        - decimal threshold
ignore_discounts  - "yes" | "no"  (evaluate min_amount pre-/post-discount)
```

### 4d. Other common methods

| Method id      | Notable instance settings                                                                                                                |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `flat_rate`    | `cost` (math expr like `10 + [qty] * 2`), `tax_status`, per-class `class_cost_<id>`, `no_class_cost`, `type` (`class` / `order` / `item`) |
| `local_pickup` | `cost`, `tax_status`                                                                                                                     |

### 4e. Zone-0 ("Rest of the World") gotcha

Zone id `0` is virtual — it doesn't appear in the `/zones` list response
until you specifically request `/zones/0/methods`. If your headless layer
fetches all zones and never asks for `0`, you'll miss the global fallback
rates. The Store API hides this complexity (just returns final rates for
the current cart), which is why it's preferable for runtime rate lookup.

### 4f. `requires=both` gotcha

`free_shipping` with `requires=both` needs **min_amount AND coupon**. UIs
that show "free shipping over $X" without also checking the coupon
condition will mis-promise rates. `GET /wc/store/v1/cart` is the only
source that handles this correctly without re-implementing the predicate.

---

## 5. Terms of Service & Privacy Policy

### 5a. Page IDs (shared by both rendering paths)

| Concept       | Option key                                | Resolver                                |
| ------------- | ----------------------------------------- | --------------------------------------- |
| Terms page    | `woocommerce_terms_page_id`               | `wc_terms_and_conditions_page_id()`     |
| Privacy page  | **`wp_page_for_privacy_policy`** (WP-core) | `wc_privacy_policy_page_id()`           |

**Asymmetry:** WC stores its own Terms page id, but reuses the WordPress
core `wp_page_for_privacy_policy` option for Privacy. Reading "all WC
options" won't surface the privacy page.

URLs from headless code:

```php
get_permalink( get_option( 'woocommerce_terms_page_id' ) );
get_permalink( get_option( 'wp_page_for_privacy_policy' ) );
```

Both resolvers are filterable via `woocommerce_get_terms_page_id` and
`woocommerce_privacy_policy_page_id`.

### 5b. Classic shortcode checkout — checkbox/notice texts

Source: `class-wc-settings-accounts.php`. Token substitution happens in
`wc_replace_policy_page_link_placeholders()`; `[terms]` and
`[privacy_policy]` become anchors to the configured page URLs.

| Field                          | Option key                                                | Where it renders                                |
| ------------------------------ | --------------------------------------------------------- | ----------------------------------------------- |
| Privacy text at checkout       | `woocommerce_checkout_privacy_policy_text`                | Below checkout (notice, not checkbox)           |
| **Terms checkbox label**       | `woocommerce_checkout_terms_and_conditions_checkbox_text` | Required checkbox when Terms page is set        |
| Privacy text at registration   | `woocommerce_registration_privacy_policy_text`            | Account registration                            |

### 5c. Block-based checkout — `woocommerce/checkout-terms-block`

Source: `client/blocks/assets/js/blocks/checkout/inner-blocks/checkout-terms-block/block.json`.

Stored as **block attributes on the Checkout page's post content**, not as
options:

```jsonc
{
  "name": "woocommerce/checkout-terms-block",
  "attributes": {
    "checkbox":      { "type": "boolean", "default": false }, // notice vs required checkbox
    "text":          { "type": "string"  },                   // override; empty = use default
    "showSeparator": { "type": "boolean", "default": true },
    "className":     { "type": "string"  }
  }
}
```

Defaults come from JS constants in the same folder
(`./constants.js` → `termsConsentDefaultText`, `termsCheckboxDefaultText`).
Terms/Privacy URLs are not stored in the block — they're injected from
server-side via the `@woocommerce/block-settings` script data as `TERMS_URL`
and `PRIVACY_URL`, computed from the same `woocommerce_terms_page_id` /
`wp_page_for_privacy_policy` options.

**Headless fetch for the block path:**

1. Find the checkout page id: `get_option( 'woocommerce_checkout_page_id' )`
2. `GET /wp-json/wp/v2/pages/{id}?context=edit` to read `content.raw`
3. Parse with the block parser (`parse_blocks()` in PHP,
   `@wordpress/block-serialization-default-parser` in JS)
4. Walk for `blockName === 'woocommerce/checkout-terms-block'`; read
   `attrs.text`, `attrs.checkbox`
5. If `attrs.text` is empty, fall back to the default constants

> **The Checkout block's terms text isn't versioned alongside settings** —
> it lives in the post revision history of the Checkout page, which means
> edits there don't show up in `wc-settings` and vice versa. A headless
> layer should read **both** sources: gateway/options for store metadata,
> post content for block-driven UI strings.

---

## Quick reference — one-line answers

| Question                                  | Answer                                                                   |
| ----------------------------------------- | ------------------------------------------------------------------------ |
| Where is the store's mailing address?     | `woocommerce_store_address[_2]`, `woocommerce_store_city`, `woocommerce_store_postcode`, `woocommerce_default_country` |
| Where is the email sender?                | `woocommerce_email_from_name`, `woocommerce_email_from_address`          |
| Where is the email Reply-To?              | Not stored — built at send-time in `WC_Email::get_headers()`             |
| Is the store live or in test mode?        | `wcpay_account_data.is_live` (authoritative) or gateway `test_mode`      |
| What currencies can I charge in?          | `wcpay_account_data.customer_currencies.supported`                       |
| What's the support contact?               | `account_business_support_email` / `account_business_support_phone` in `woocommerce_woocommerce_payments_settings` |
| Live shipping rates for the cart?         | `GET /wc/store/v1/cart` → `shipping_rates[]`                             |
| Configured zones / methods?               | `GET /wc/v3/shipping/zones` (+ `/locations`, `/methods`) — **plus** explicit `/zones/0/methods` |
| Free-shipping threshold?                  | `min_amount` (with `requires` and `ignore_discounts` modifiers)          |
| Terms / Privacy page URLs?                | `get_permalink()` of `woocommerce_terms_page_id` / `wp_page_for_privacy_policy` |
| Shortcode-checkout ToS label?             | `woocommerce_checkout_terms_and_conditions_checkbox_text`                |
| Block-checkout ToS text?                  | Block attribute `text` on `woocommerce/checkout-terms-block` in the Checkout page's post content |
