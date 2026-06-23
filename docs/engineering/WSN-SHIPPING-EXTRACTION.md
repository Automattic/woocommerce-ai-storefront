# WSN shipping extraction

How to enrich the WSN catalog with per-merchant shipping intel — where
they ship, free-shipping rules, local pickup, handling/transit times,
policy docs. WSN currently extracts **none** of this; the only shipping
reference in the index today is `raw.taxonomy.product_shipping_class[]`,
which is just a label string.

Runs server-side at index time via `switch_to_blog( $blog_id )`, **not**
in the request path. Crawl/scrape pieces (schema.org, policy pages) run
in a separate worker.

## What shoppers ask vs. where the data lives

| Shopper question | Core WC? | Best signal | Reliability |
|---|---|---|---|
| Which countries do they ship to? | ✅ | `WC_Shipping_Zones::get_zones()` + zone 0 | High |
| Free shipping available? | ✅ | `free_shipping` method instance present in any zone | High |
| Free shipping threshold? | ✅ | `free_shipping` instance: `requires`, `min_amount`, `ignore_discounts` | High |
| Local pickup offered? | ✅ | `local_pickup` method instance present in any zone | High |
| Pickup address? | ⚠️ | Store address (`woocommerce_store_*`); plugins for multi-location | Mixed |
| Handling time (days to ship)? | ❌ | Schema.org `OfferShippingDetails.handlingTime` → plugin meta → method title regex → policy page | Sparse |
| Transit time (days in delivery)? | ❌ | Schema.org `OfferShippingDetails.transitTime` → plugin meta → method title regex | Sparse |
| Shipping policy doc? | ❌ | Page slug heuristic (`/shipping`, `/shipping-policy`, …) | Per-merchant |
| Return policy doc? | ❌ | Page slug heuristic (`/returns`, `/refund-policy`, …) | Per-merchant |

## Extraction recipe — by field

### Coverage countries

Walk all configured zones + zone 0. Union of locations across zones with
≥1 enabled method = coverage set. Zone 0 with enabled methods ⇒ worldwide.

```php
$coverage = [ 'countries' => [], 'worldwide' => false ];
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
    $zone    = new WC_Shipping_Zone( $z['id'] );
    if ( empty( $zone->get_shipping_methods( true ) ) ) continue;
    foreach ( $zone->get_zone_locations() as $loc ) {
        if ( 'country' === $loc->type )   $coverage['countries'][] = $loc->code;
        if ( 'continent' === $loc->type ) $coverage['countries'] = array_merge(
            $coverage['countries'],
            WC()->countries->get_continents()[ $loc->code ]['countries']
        );
    }
}
$rest = new WC_Shipping_Zone( 0 );
$coverage['worldwide'] = ! empty( $rest->get_shipping_methods( true ) );
$coverage['countries'] = array_values( array_unique( $coverage['countries'] ) );
```

> **Gotcha:** Zone 0 is virtual — won't appear in `get_zones()`. Always
> instantiate it explicitly. Without this, "worldwide" detection silently
> fails for the most common configuration.

### Free shipping rule

Read `free_shipping` instance options. Don't oversimplify
`requires=both` / `requires=either` to a single number — surface the
qualifier or skip the claim.

| Stored option | Type | Meaning |
|---|---|---|
| `requires` | enum | `""` / `coupon` / `min_amount` / `either` / `both` |
| `min_amount` | decimal | Threshold in store currency |
| `ignore_discounts` | `yes`/`no` | Whether min_amount is pre- or post-coupon |
| `title` | string | Label shown at checkout |

### Local pickup

| Stored option | Source | Notes |
|---|---|---|
| Method presence | `local_pickup` instance in any zone | Boolean signal |
| `title` | Method instance | Many merchants put location name here — mine it |
| `cost` | Method instance | Usually `0` |
| Address (single-location) | `woocommerce_store_address`, `_2`, `_city`, `_postcode`, `_default_country` (split on `:`) | Core has one address |
| Address (multi-location) | Plugin CPT / options | Detect plugin first |

Multi-location plugin detection:

| Plugin slug | Stores addresses in |
|---|---|
| `woocommerce-local-pickup-plus/woocommerce-local-pickup-plus.php` | CPT `wc_pickup_location` |
| `woocommerce-distance-rate-shipping/woocommerce-distance-rate-shipping.php` | option `woocommerce_distance_rate_shipping_locations` |
| `wc-store-locator/wc-store-locator.php` | CPT `wc_store_locator` |

If none detected, fall back to store address.

### Handling time & transit time

Multi-source fallback chain. Record provenance per field.

| Priority | Source | Signal shape | Cache |
|---|---|---|---|
| 1 | Schema.org `OfferShippingDetails` on a sampled product page | JSON-LD with `handlingTime` + `transitTime` (each `{minValue, maxValue, unitCode: "DAY"}`) | Per-merchant, 7d |
| 2 | Product postmeta — common keys: `_lead_time`, `_handling_time`, `_processing_time`, `_wc_estimated_delivery_min`, `_wc_estimated_delivery_max` | Plugin-defined; varies | Per-product (already in ES `_source`) |
| 3 | Shipping method `title` regex — `(\d+)[-–]?(\d+)?\s*(business\s+)?days?` | Capture min/max | Per-merchant, 24h |
| 4 | Policy page text (LLM extraction) | Last resort | Per-merchant, 7d |

### Policy pages

Cheap slug walk first, title search as fallback.

| Doc | Candidate slugs |
|---|---|
| Shipping | `shipping`, `shipping-policy`, `shipping-and-returns`, `delivery`, `shipping-info` |
| Returns | `returns`, `return-policy`, `refund-policy`, `refunds-and-returns` |

```php
foreach ( $shipping_slugs as $slug ) {
    if ( $page = get_page_by_path( $slug ) ) { /* record permalink */ break; }
}
// Fallback
$q = new WP_Query( [ 'post_type' => 'page', 's' => 'shipping policy', 'posts_per_page' => 3 ] );
```

Run the same LLM extractor used for `ai_category` against the page
content to produce structured `return_window_days`,
`restocking_fee_pct`, `ships_internationally`, etc.

## Index shape

Add a per-store `shipping` sub-block (sibling to existing `store.raw`).
Always carry provenance so downstream UI can audit confidence.

```jsonc
{
  "shipping": {
    "ships_to_countries":            ["US", "CA"],
    "ships_worldwide":               false,
    "has_free_shipping":             true,
    "free_shipping_min_amount":      50,        // null if requires=coupon only
    "free_shipping_currency":        "USD",
    "free_shipping_requires_coupon": false,
    "has_local_pickup":              true,
    "local_pickup_locations":        [
      { "title": "Dunedin store", "address": { "line_1": "…", "city": "…", "country": "US", "state": "FL", "postcode": "34698" }, "zone": "Local" }
    ],
    "handling_time_days":            { "min": 1, "max": 3 },
    "delivery_time_days":            { "min": 3, "max": 7 },
    "policy": {
      "shipping_page_url":  "https://example.com/shipping/",
      "returns_page_url":   "https://example.com/returns/",
      "return_window_days": 30
    },
    "extracted_from": {
      "handling_time_days":  "schema_org",
      "delivery_time_days":  "method_title_regex",
      "return_window_days":  "policy_page_llm"
    },
    "extracted_at": "2026-05-20T12:00:00Z"
  }
}
```

## Placement in woopay

| Piece | Lives in | Why |
|---|---|---|
| Zone/method/option walk | New `MerchantShippingExtractor` next to `EsSource.php` | Same blog-context boundary as existing marketplace handlers |
| Plugin detection | Same extractor | One pass per merchant |
| Schema.org / page fetch | Separate worker — **not** in sync path | Hits the public site; must not block index updates |
| LLM policy extraction | Separate worker | Same reason; long-running |
| Permission gate | Match `permission_callback_proxy` | A8C-proxied, like the existing WSN routes |
| Trigger | Per-blog event (option update, plugin activation) + daily ceiling | Settings drift but rarely change |

## What not to do

| Anti-pattern | Why |
|---|---|
| Inferring coverage from `taxonomy.product_shipping_class[]` | Class names like "USPS" don't imply destinations |
| Showing a `free_shipping` rule without honoring `requires=both` or `requires=either` | Mis-promises rates to shoppers |
| Treating the store address as a pickup address when no `local_pickup` method exists | Implies pickup is offered when it isn't |
| Running the extractor on every WSN request | All of this is stale-tolerant; index-time only |
| Dropping the `extracted_from` provenance | You lose the audit trail when merchants complain about UI claims |
