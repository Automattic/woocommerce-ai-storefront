# JSON-LD Schema Reference

The structured-data shapes WooCommerce AI Storefront emits, where, and what controls each field. Use this when integrating with the plugin, debugging structured-data output, or extending it via the public filters.

## What the plugin emits

Two distinct JSON-LD blocks:

| Surface | Block | Location | Source |
|---------|-------|----------|--------|
| Product page (single product) | Enhanced `Product` | Inside the `<head>` via `wp_head`, layered on top of WooCommerce core's existing `Product` block | [`includes/ai-storefront/class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) `enhance_product_data()` |
| Store homepage / shop page | `OnlineStore` (an `Organization` subtype, since 0.10.0; previously `Store`) | Inside the `<head>` via `wp_head`, on `is_front_page() || is_shop()` when the plugin is enabled | Same file, `output_store_jsonld()` |

Both blocks emit only when the plugin is enabled (`enabled === 'yes'` in `wc_ai_storefront_settings`). Disabling the plugin removes the markup entirely; the underlying WooCommerce core JSON-LD (basic Product, Offer, AggregateRating) continues to render unchanged.

The plugin **does not replace** WC core's JSON-LD. It registers a `woocommerce_structured_data_product` filter that runs after WC has built its base markup and merges enhancement fields into the existing array.

## Product page: enhanced Product schema

Below is a representative full output for a published product after the plugin's enhancement pass. Annotations call out which fields are added or modified by this plugin vs. inherited from WC core.

```jsonc
{
  "@context": "https://schema.org/",
  "@type": "Product",

  // ---- Inherited from WC core (shape may vary by theme/extension) ----
  "@id": "https://yourstore.example.com/product/hoodie-with-zipper/#product",
  "name": "Hoodie with Zipper",
  "url": "https://yourstore.example.com/product/hoodie-with-zipper/",
  "description": "Zip-front hoodie in heavyweight cotton fleece.",
  "image": ["https://yourstore.example.com/wp-content/uploads/.../hoodie.jpg"],
  "sku": "woo-hoodie-with-zipper",

  "offers": [{
    "@type": "Offer",
    "price": "45.00",
    "availability": "https://schema.org/InStock",
    "url": "https://yourstore.example.com/product/hoodie-with-zipper/",
    "seller": { "@type": "Organization", "name": "Your Store" },

    // ---- Added by this plugin ----
    "priceCurrency": "USD",
    "inventoryLevel": { "@type": "QuantitativeValue", "value": 12 },
    "shippingDetails": { "@type": "OfferShippingDetails", "shippingDestination": [{
      "@type": "DefinedRegion", "addressCountry": "US"
    }]},
    "hasMerchantReturnPolicy": {
      "@type": "MerchantReturnPolicy",
      "applicableCountry": "US",
      "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
      "merchantReturnDays": 30,
      "returnMethod": ["https://schema.org/ReturnByMail"],
      "returnFees": "https://schema.org/FreeReturn"
    }
  }],

  // ---- Added by this plugin ----
  "potentialAction": {
    "@type": "BuyAction",
    "target": "https://yourstore.example.com/?add-to-cart=123&utm_source={agent}&utm_medium=ai_agent&utm_id=woo_ucp&ai_session_id={session}",
    "result": { "@type": "Order" }
  },
  "category": "Clothing > Hoodies",
  "weight":  { "@type": "QuantitativeValue", "value": 0.6, "unitCode": "KGM" },
  "depth":   { "@type": "QuantitativeValue", "value": 5,   "unitCode": "CMT" },
  "width":   { "@type": "QuantitativeValue", "value": 30,  "unitCode": "CMT" },
  "height":  { "@type": "QuantitativeValue", "value": 60,  "unitCode": "CMT" },
  "additionalProperty": [
    { "@type": "PropertyValue", "name": "Color", "value": "Navy" },
    { "@type": "PropertyValue", "name": "Material", "value": "Cotton fleece" },
    { "@type": "PropertyValue", "name": "Sizes available", "value": "S, M, L, XL" }
  ]
}
```

## Field reference

Each field added by the plugin, with the rule that controls its presence.

### `potentialAction` (BuyAction)

A Schema.org `BuyAction` pointing to a URL the AI agent can use to send the shopper to checkout with that product pre-added.

- **Always emitted** for purchasable products (not draft, not out of stock when stock management is on, has a price).
- **`target` URL** is built from `$product->add_to_cart_url()` plus `utm_*` placeholders (`{agent}`, `{session}`) the AI agent substitutes at runtime per UCP convention.
- **`result.@type`** is always `Order` (Schema.org's expected result type for `BuyAction`).
- **Source**: `add_buy_action()` (line ~108 in `class-wc-ai-storefront-jsonld.php`).

### `offers[0].inventoryLevel`

Schema.org `QuantitativeValue` exposing the current stock level.

- **Emitted only when** WooCommerce stock management is enabled for the product AND the product has a numeric `stock_quantity`.
- **Skipped for** products with `manage_stock=false` (out of scope for inventory-level discovery).

### `category`

The primary category path as a breadcrumb string (e.g. `"Clothing > Hoodies"`).

- **Emitted when** the product has at least one assigned category.
- **Selection rule**: deepest leaf in the longest breadcrumb path. Ties broken by category ID.
- **Format**: " > " separator.

### `weight`, `depth`, `width`, `height` (dimensions)

Schema.org `QuantitativeValue` blocks with `unitCode` set to UN/CEFACT codes (`KGM`, `LBR`, `CMT`, `INH`, etc.) per the store's WC unit settings.

- **Each emitted independently** if the product has a non-empty value for that dimension.
- **Unit codes** map from WC's wordpress unit setting (kg, lbs, cm, in, m, mm) via `get_weight_unit_code()` / `get_dimension_unit_code()`.

### `additionalProperty` (attributes)

Array of `PropertyValue` entries from product attributes.

- **Emitted when** the product has at least one attribute that's marked "Visible on the product page" (the WC `is_visible()` check).
- **Excluded**: variation-defining attributes (those that drive the variation matrix), since they're already represented in the `offers` variations.

### `offers[0].priceCurrency`

ISO 4217 currency code, normalized.

- **Always emitted**. Inherits from WC core when present; otherwise plugin synthesizes from store settings.
- **Defensive**: covers a WC core edge case where nested `priceSpecification[0].priceCurrency` was set but top-level `priceCurrency` wasn't.

### `offers[0].seller.name`

Store name, double-decoded for HTML entities.

- **Modification, not addition**: WC core sets this; the plugin double-decodes any HTML entities to ensure correct rendering when the store name contains characters like `'`, `&`, `<`.

### `offers[0].shippingDetails`

`OfferShippingDetails` declaring the shipping destination country, free-shipping rate, and handling time.

- **Emitted when** the WC base country is set in WooCommerce settings.
- **Country source**: `wc_get_base_location()`.

#### `offers[0].shippingDetails.shippingRate`

Schema.org `MonetaryAmount` emitted when unconditional free shipping is available for the store's base country.

- **Emitted when** a WooCommerce shipping zone covers the base country and contains a free-shipping method with no minimum order amount.
- **Value**: always `{ "@type": "MonetaryAmount", "value": 0, "currency": "<store currency>" }`.
- **Not emitted** when free shipping requires a coupon or minimum spend — those are conditional, not unconditional.
- **Source**: `add_shipping_details()` → `has_free_shipping_for_country()`. Result is per-request cached keyed by country code.

#### `offers[0].shippingDetails.deliveryTime.handlingTime`

Schema.org `ShippingDeliveryTime` → `QuantitativeValue` emitted when the merchant has configured handling time on the Policies tab.

- **Emitted when** Policies tab → Shipping → Minimum > 0 AND Maximum > 0 AND min ≤ max.
- **Value**: `{ "@type": "QuantitativeValue", "minValue": <min>, "maxValue": <max>, "unitCode": "DAY" }`.
- **Guard**: invalid pairs stored in the database (e.g. `{min:5, max:2}`) are silently skipped — the block is omitted rather than emitting broken structured data.
- **Source**: `add_handling_time()`. Sanitization is enforced by `WC_AI_Storefront_Handling_Time::sanitize()` at save time (0–365 clamp, max raised to min when below).

### `offers[0].hasMerchantReturnPolicy`

Schema.org `MerchantReturnPolicy` describing return rules.

- **Emitted when** Policies tab → Returns mode is "Returns accepted" or "Final sale" AND the WC base country is set.
- **Per-product override**: if the product has the "AI: Final sale" checkbox enabled in its Inventory tab, the merchant return policy block reflects final-sale terms regardless of the store-wide setting.
- **Schema-validated values**:
  - `returnPolicyCategory`: `MerchantReturnFiniteReturnWindow` (returns accepted) or `MerchantReturnNotPermitted` (final sale).
  - `returnFees`: from a Schema.org allow-list (`FreeReturn`, `ReturnFeesCustomerResponsibility`, etc.). Invalid values are dropped at emit time.
  - `returnMethod`: same allow-list defense (`ReturnByMail`, `ReturnInStore`, `ReturnAtKiosk`).
- **Source**: `add_return_policy()` (line ~356) and `build_return_policy_block()` (line ~559).

## Store homepage: OnlineStore schema

A separate JSON-LD block emitted on the front page or shop page (`is_front_page() || is_shop()`) when the plugin is enabled.

The `@type` is `OnlineStore` (a Schema.org `Organization` subtype). Prior to 0.10.0 this was `Store` (a `LocalBusiness`/`Place` subtype), which doesn't satisfy AI-readiness audits looking for `Organization`-shaped brand entities. `OnlineStore` is the most accurate type for a Woo storefront and inherits all the descriptive fields (`name`, `url`, `description`) that `Store` carried.

```jsonc
{
  "@context": "https://schema.org",
  "@type": "OnlineStore",
  "name": "Your Store",
  "description": "Your store's tagline / blog description",
  "url": "https://yourstore.example.com/",
  "currenciesAccepted": "USD",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "https://yourstore.example.com/?s={search_term}&post_type=product&utm_source={agent_id}&utm_medium=referral&utm_id=woo_ucp"
    },
    "query-input": "required name=search_term"
  },
  "hasOfferCatalog": {
    "@type": "OfferCatalog",
    "name": "Products",
    "itemListElement": [
      // Top-level categories with product counts; built by get_catalog_summary().
      // Empty categories (zero exposed products) are omitted.
    ]
  },

  // Identity fields (since 0.10.0). Each is omit-when-empty: a merchant
  // who has no logo, no WC base country, and no usable email gets none of
  // these keys.
  "logo": "https://yourstore.example.com/wp-content/uploads/.../brand.png",
  "address": {
    "@type": "PostalAddress",
    "addressCountry": "US",
    "addressLocality": "Springfield",
    "addressRegion":   "IL",
    "postalCode":      "62701"
    // Note: streetAddress is intentionally NEVER emitted. See "Identity
    // field sourcing" below.
  },
  "contactPoint": {
    "@type":       "ContactPoint",
    "contactType": "Customer Service",
    "email":       "support@yourstore.example.com"
  }
}
```

### Identity field sourcing

All three identity fields are auto-sourced from existing WP/WC data. There are **no plugin-owned settings** for these — the plugin reads what's already configured at the platform level.

| Field | Source | Omit-when-empty rule |
|-------|--------|----------------------|
| `logo` | WP custom-logo theme mod (`get_theme_mod( 'custom_logo' )` → resolved via `wp_get_attachment_image_src`), with `get_site_icon_url()` as fallback. | Omitted when neither is set. Avoids publishing the default WP favicon as a brand mark. |
| `address.addressCountry` | `WC()->countries->get_base_country()`. | The whole `address` block is omitted when country is empty (the minimum viable address signal). |
| `address.addressLocality` / `addressRegion` / `postalCode` | `WC()->countries->get_base_city()` / `get_base_state()` / `get_base_postcode()`. | Each sub-key is omitted when WC has no value. |
| `address.streetAddress` | **NEVER emitted.** Not even when WC has the street address populated (`get_base_address()` / `get_base_address_2()`). | See "Why streetAddress is suppressed" below. |
| `contactPoint.email` | Two-stage: (1) `woocommerce_email_reply_to_address` when `woocommerce_email_reply_to_enabled === 'yes'`, (2) `woocommerce_email_from_address` as fallback (rejected when local-part is a noreply pattern). | The whole `contactPoint` block is omitted when neither stage produces a usable address. **Never** falls back to `admin_email`. |

### Why streetAddress is suppressed

For an `OnlineStore` (vs. a `LocalBusiness`), street address has low signal value: buyers transact remotely and don't visit. But the privacy/safety risk is real — many small Woo merchants populate WooCommerce > Settings > General with their home address (the field is required at WC setup so tax calculations work) and don't realize that saving it would publish the address in machine-readable form on the homepage's JSON-LD. By emitting only `addressLocality`, `addressRegion`, `postalCode`, and `addressCountry`, we preserve every meaningful identity signal (jurisdiction, shipping origin, fraud-check disambiguation) without leaking a residential address. `build_postal_address()` in the emitter doesn't even read `get_base_address()` or `get_base_address_2()` — even a future filter that wants to re-emit street would have to source it independently.

### Why the email resolver has a noreply guard

WC's "From" address is often set to `noreply@store.com` to avoid bounce-handling on outgoing transactional emails. Publishing that as `contactPoint.email` would route legitimate customer questions into a black hole. The `is_noreply_email()` heuristic matches the four canonical noreply local-parts (`noreply`, `no-reply`, `donotreply`, `do-not-reply`) case-insensitively, including their RFC 5233 plus-addressing variants (`noreply+orders@…`, `do-not-reply+billing@…`) which route to the same underlying mailbox at most providers. Local-part-only matching prevents false positives on legitimate mailboxes hosted on a `noreply.*` subdomain (e.g. `support@noreply.example.com` stays publishable). The guard only applies to the From-address fallback path; the merchant's explicit reply-to address (when enabled in WC settings) is trusted as-is.

### What this plugin does NOT emit

- **`sameAs`** (social profile URLs). WC has no canonical storage for these; ecosystem plugins (Jetpack Social, Yoast Knowledge Graph, etc.) own the merchant capture and can inject via the `wc_ai_storefront_jsonld_store` filter. See [`HOOKS.md`](HOOKS.md) for a worked example.
- **`contactPoint.telephone`**. Same reason: WC has no phone option, so the plugin can't auto-source. Plugins that capture a merchant phone number can inject via the same filter.

The `hasOfferCatalog.itemListElement` is built by `get_catalog_summary()` and respects the plugin's product visibility setting — categories with zero exposed products are omitted.

## Public filters

Both blocks expose filters for theme and extension authors to customize:

- `wc_ai_storefront_jsonld_product` -- runs at the end of `enhance_product_data()`. Receives the enhanced markup, the WC_Product, and a minimal safe subset of plugin settings (`enabled`, `product_selection_mode`, `return_policy`). Security-sensitive fields (rate limits, crawler allow-lists) are intentionally excluded.
- `wc_ai_storefront_jsonld_store` -- runs at the end of `output_store_jsonld()`. Same safe-subset settings.

Both filters return arrays. Returning a partial array replaces only those keys; existing keys not in the return value persist.

## Validation

Three external tools cover this output:

- [Schema.org validator](https://validator.schema.org/) -- accepts a URL or pasted JSON-LD; reports type-shape errors.
- [Google's Rich Results Test](https://search.google.com/test/rich-results) -- validates Product structured data specifically; warns on missing fields Google considers important for rich results (price, availability, image).
- [Schema Markup Validator](https://validator.schema.org/) -- the canonical Schema.org-side checker.

For automated checks, the plugin's PHPUnit suite covers:

- `JsonLdTest.php` -- per-method coverage of `enhance_product_data`, `add_buy_action`, `add_inventory_level`, `add_dimensions`, etc.
- `JsonLdReturnPolicyTest.php` -- Schema.org allow-list enforcement on `returnFees` / `returnMethod`.
- `JsonLdNormalizationTest.php` -- HTML entity decoding, currency normalization, edge cases.

## What this plugin deliberately does not emit

For reference, fields that a JSON-LD reader might expect but aren't part of this plugin's enhancement:

- **`aggregateRating` / `review`** -- inherited from WC core if present (e.g. via WooCommerce's reviews feature); plugin doesn't add or modify.
- **`gtin`, `mpn`, `productID`** -- plugin doesn't synthesize. WC core may emit these if the store has them; plugin doesn't enforce.
- **`audience`, `eligibleRegion`** -- not modeled. AI agents that need region/audience scoping should infer from `shippingDetails.shippingDestination`.
- **Variation-level JSON-LD** -- the plugin enhances the parent Product. Variations remain in WC core's `offers` array; per-variation JSON-LD blocks are not emitted.

These omissions are deliberate: the plugin's scope is "AI-shopping discovery essentials," not full Schema.org coverage. Extensions can fill these via the `wc_ai_storefront_jsonld_product` filter.

## Reference

- [`includes/ai-storefront/class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) -- the source of truth for emitted shapes.
- [`HOOKS.md`](HOOKS.md) -- full filter signatures for `wc_ai_storefront_jsonld_product` and `wc_ai_storefront_jsonld_store`.
- [Schema.org Product](https://schema.org/Product) -- canonical type reference.
- [Schema.org BuyAction](https://schema.org/BuyAction), [InventoryLevel](https://schema.org/inventoryLevel), [MerchantReturnPolicy](https://schema.org/MerchantReturnPolicy) -- spec for the headline enhancements.
