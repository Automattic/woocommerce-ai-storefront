# Data Model

Inventory of every persisted artifact this plugin writes — options, transients, post meta, order meta, scheduled events. For each: where it's defined, who reads/writes it, lifetime, and behavior on uninstall.

The surface is deliberately small: two options, three transients, one scheduled event, one post-meta key, four order-meta keys. No custom tables. No custom post types.

## Data flow

```
Settings UI (React)
    │
    │  POST /wc/v3/ai-storefront/admin/settings
    ▼
WC_AI_Storefront::update_settings()
    │
    │  update_option('wc_ai_storefront_settings', ...)
    ▼
wp_options ──── triggers ────► WC_AI_Storefront_Cache_Invalidator
                                         │
                                         │  delete_transient(...)
                                         ▼
                                    wc_ai_storefront_llms_txt
                                    wc_ai_storefront_ucp
                                         │
                                         │  schedule cron
                                         ▼
                            wc_ai_storefront_warm_llms_txt_cache


AI agent fetches /llms.txt
    │
    ▼
WC_AI_Storefront_Llms_Txt::serve_llms_txt()
    │
    │  get_transient('wc_ai_storefront_llms_txt')
    ▼
HIT → return cached
MISS → regenerate, set_transient, return


Customer completes checkout from a UTM-tagged link
    │
    ▼
WC core captures utm_* into _wc_order_attribution_*
    │
    ▼
woocommerce_checkout_order_processed hook
    │
    ▼
WC_AI_Storefront_Attribution::capture_ai_attribution()
    │
    │  $order->update_meta_data(...)
    ▼
_wc_ai_storefront_agent
_wc_ai_storefront_session_id
_wc_ai_storefront_agent_host_raw
```

The rest of this document is per-key reference.

## Options (wp_options)

### `wc_ai_storefront_settings`

Single source of truth for all runtime settings.

- **Type:** serialized PHP array
- **Autoload:** `yes` — read on every page load, so it lives in the `alloptions` cache
- **Defined in:** `WC_AI_Storefront::SETTINGS_OPTION` ([`includes/class-wc-ai-storefront.php`](../../includes/class-wc-ai-storefront.php))
- **Written by:** `WC_AI_Storefront::update_settings()`, called from the admin REST controller's `POST /settings`
- **Read by:** every component, via `WC_AI_Storefront::get_settings()` (memoized via static `$settings_cache`)
- **Uninstall:** deleted by `uninstall.php`

**Schema:**

```php
[
    'enabled'                  => 'yes' | 'no',                          // default 'no'
    'product_selection_mode'   => 'all' | 'by_taxonomy' | 'selected',    // default 'all'
    'selected_categories'      => int[],                                 // term IDs
    'selected_tags'            => int[],                                 // term IDs
    'selected_brands'          => int[],                                 // term IDs (only if a brands taxonomy exists)
    'selected_products'        => int[],                                 // post IDs
    'rate_limit_rpm'           => int,                                   // 1-1000, default 25
    'allowed_crawlers'         => string[],                              // subset of WC_AI_Storefront_Robots::AI_CRAWLERS
    'allow_unknown_ucp_agents' => 'yes' | 'no',                          // default 'no' (secure-by-default)
    'return_policy'            => [
        'mode'    => 'unconfigured' | 'returns_accepted' | 'final_sale', // default 'unconfigured'
        'page_id' => int|null,                                           // optional: link to a policy page
        'days'    => int|null,                                           // null when no window configured
        'fees'    => string,                                             // 'free' | 'customer_pays' | ...
        'methods' => string[],                                           // ['mail', 'in_store', ...]
    ],
]
```

#### Silent migrations

`get_settings()` performs in-place migration on read for legacy values. The migrated value is written back (with cache invalidation) so subsequent reads short-circuit:

| Legacy value | Migrated to | Reason |
|--------------|-------------|--------|
| `product_selection_mode = 'categories'` | `'by_taxonomy'` | Pre-0.1.5 had three separate enum values for category/tag/brand-only selection; consolidated into UNION-style `by_taxonomy`. |
| `product_selection_mode = 'tags'`       | `'by_taxonomy'` | Same. |
| `product_selection_mode = 'brands'`     | `'by_taxonomy'` | Same. |

Migrating on read (rather than activation) means rollback-then-forward across plugin versions converges, and avoids coupling to activation-hook timing.

### `wc_ai_storefront_version`

Tracks the currently-installed plugin version. Used to detect upgrades and trigger one-time post-upgrade work (rewrite-rule flush, cache bust).

- **Type:** string (`X.Y.Z`)
- **Autoload:** `yes`
- **Written by:** `WC_AI_Storefront::register_rewrite_rules()` after detecting `WC_AI_STOREFRONT_VERSION !== get_option('wc_ai_storefront_version')`
- **Uninstall:** deleted by `uninstall.php`

The version check runs in the rewrite path (not the activation hook) because WordPress fires `register_activation_hook` only on fresh activation, not on in-place zip upgrades. To catch upgrades reliably the check has to run on every boot.

---

## Transients (wp_options or persistent object cache)

### `wc_ai_storefront_llms_txt`

Cached `/llms.txt` Markdown body. Avoids regenerating on every crawler hit.

- **TTL:** 1 hour (`HOUR_IN_SECONDS`)
- **Defined in:** `WC_AI_Storefront_Llms_Txt::CACHE_KEY`
- **Written by:** `serve_llms_txt()` after generating; eagerly written on settings save when `enabled` flips on
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` on product/category/settings changes
- **Uninstall:** deleted by `uninstall.php`

### `wc_ai_storefront_ucp`

Cached `/.well-known/ucp` JSON manifest body.

- **TTL:** 1 hour
- **Defined in:** `WC_AI_Storefront_Ucp::CACHE_KEY`
- **Written by:** `serve_manifest()`; eagerly written on settings save when `enabled` flips on
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` on product/category/settings changes; version-based bust on plugin update
- **Uninstall:** deleted by `uninstall.php`

### `wc_ai_storefront_flush_rewrite`

Marker that a rewrite-rule flush is pending. Set by `update_settings()` when the `enabled` flag flips; consumed and deleted on the next boot.

- **TTL:** 1 hour (defensive; should be consumed on the next request)
- **Set by:** `WC_AI_Storefront_Admin_Controller::update_settings()`
- **Consumed by:** `WC_AI_Storefront::register_rewrite_rules()`
- **Uninstall:** deleted by `uninstall.php`

A transient (instead of a direct `flush_rewrite_rules()` call) defers the 100ms+ flush latency to the next page load.

### Note on UCP REST responses

UCP REST endpoint responses (`/catalog/search`, `/catalog/lookup`, `/checkout-sessions`) are **not** cached. Every dispatch computes fresh because per-request attribution (UTM stamping) and `chk_…` session IDs must vary per agent and per request.

---

## Order meta

WooCommerce stores order meta in `wp_postmeta` (legacy) or `wp_wc_orders_meta` (HPOS — High-Performance Order Storage). The plugin is HPOS-compatible: order access goes through `WC_Order` methods exclusively, never raw post-meta queries.

### `_wc_order_attribution_utm_source`

Agent identifier — typically the agent's lowercase hostname (`chatgpt.com`, `gemini.google.com`) under the canonical 0.5.0+ UTM shape. Pre-0.5.0 orders carry the canonical brand name (`chatgpt`, `gemini`) instead; both are recognized by `capture_ai_attribution()`.

- **Defined by:** WooCommerce core (Order Attribution feature, since WC 8.5)
- **Written by:** WC core's Order Attribution capture (sourced from URL UTM params)
- **Read by:** WC core's "Origin" column on the Orders list; the plugin's `WC_AI_Storefront_Attribution::get_stats()` SQL aggregator
- **Uninstall:** **NOT** deleted — historical merchant transaction record

### `_wc_order_attribution_utm_medium`

Always `referral` for AI-referred orders under the canonical 0.5.0+ shape. Pre-0.5.0 orders carry `ai_agent`; both are still recognized by the STRICT gate.

- **Defined by:** WooCommerce core
- **Same lifecycle as `utm_source`**
- **Uninstall:** NOT deleted

### `_wc_order_attribution_utm_id`

`woo_ucp` for orders routed through this plugin's `/checkout-sessions` endpoint. The STRICT gate matches on this regardless of `utm_source` / `utm_medium` values, decoupling **who** sent the user from **how** the URL was routed.

- **Defined by:** WooCommerce core
- **Set by:** `WC_AI_Storefront_Attribution::with_woo_ucp_utm()` on every continue_url and on every product `url` returned by `/catalog/search` and `/catalog/lookup`
- **Uninstall:** NOT deleted

### `_wc_ai_storefront_agent`

Canonical brand name (denormalized from `utm_source` for fast indexed queries). Goes through `WC_AI_Storefront_UCP_Agent_Header::canonicalize()` — unknown hosts bucket to `OTHER_AI_BUCKET` (`Other AI`).

- **Defined in:** `WC_AI_Storefront_Attribution::AGENT_META_KEY`
- **Written by:** `capture_ai_attribution()`
- **Read by:** the per-agent breakdown stats query, the Recent AI Orders REST endpoint
- **Uninstall:** NOT deleted

### `_wc_ai_storefront_agent_host_raw`

Raw host from the request's `UCP-Agent` header (or the `ai_agent_host_raw` URL param), preserved for provenance auditing — useful when a stats anomaly needs to be debugged back to actual incoming traffic. Validated against the RFC 1035 hostname-shape regex and a 253-char length cap on capture.

- **Defined in:** `WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY`
- **Written alongside `_wc_ai_storefront_agent`**
- **Uninstall:** NOT deleted

### `_wc_ai_storefront_session_id`

The `chk_<16 hex chars>` correlation token returned from `POST /checkout-sessions`. Stored on the order so a support engineer can trace a completed order back to the exact UCP session that produced the cart.

- **Defined in:** `WC_AI_Storefront_Attribution::SESSION_META_KEY`
- **Written by:** `capture_ai_attribution()`
- **Uninstall:** NOT deleted

### Why uninstall doesn't delete order meta

These keys live on historical orders — purchased products, paid invoices, real customer transactions. Destroying them on plugin delete would erase legitimate business records. Merchants who explicitly want to purge can do it with WP-CLI:

```bash
wp post meta delete --all --keys=_wc_ai_storefront_agent,_wc_ai_storefront_session_id,_wc_ai_storefront_agent_host_raw
```

This matches WooCommerce's own pattern — WC doesn't delete order data on uninstall either.

---

## Post meta (products)

### `_wc_ai_storefront_final_sale`

Per-product override for the store-wide return policy. When `'yes'`, the product's JSON-LD emits a `MerchantReturnPolicy` with the final-sale flag regardless of the store-wide setting.

- **Type:** string (`'yes'` or empty)
- **Defined in:** `WC_AI_Storefront_Product_Meta_Box::META_KEY`
- **Written by:** the `AI: Final sale` checkbox in the product editor's Inventory tab
- **Read by:** `WC_AI_Storefront_JsonLd::build_return_policy_block()`
- **Uninstall:** NOT deleted (per-product editorial data — same rationale as order meta)

The underscore prefix marks the key as protected (not editable from the default Custom Fields meta box). This matches WooCommerce's convention for keys we control programmatically.

---

## Scheduled events (cron)

### `wc_ai_storefront_warm_llms_txt_cache`

Debounced WP-Cron event that regenerates the `/llms.txt` cache after a content change.

- **Schedule:** one-shot (rescheduled on each invalidation event)
- **Defined in:** `WC_AI_Storefront_Cache_Invalidator`
- **Triggered by:** product CRUD, category CRUD, settings updates
- **Uninstall:** cleared by `uninstall.php` via `wp_clear_scheduled_hook()`

The debounce coalesces invalidations so a bulk product import doesn't fire dozens of regenerations in sequence. Staleness is bounded by the regeneration window — seconds, not minutes.

---

## Multisite

When activated network-wide, options and transients are per-site (each site has its own `wp_options` row). `uninstall.php` loops through `get_sites()` and deletes from each:

- `wc_ai_storefront_settings`, `wc_ai_storefront_version`
- `wc_ai_storefront_llms_txt`, `wc_ai_storefront_ucp`, `wc_ai_storefront_flush_rewrite` (transients)
- `wc_ai_storefront_warm_llms_txt_cache` (cron)

The cleanup loop is wrapped in a function-existence guard so re-running uninstall by mistake doesn't redefine the function and warn.

---

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does
- [`API-REFERENCE.md`](API-REFERENCE.md) — endpoint shapes that read/write this data
- [`HOOKS.md`](HOOKS.md) — filters that intercept the data before it's written
- [`TESTING.md`](TESTING.md) — `SettingsMigrationTest`, `CacheInvalidatorTest`, `AttributionTest` exercise this surface
- [`../../uninstall.php`](../../uninstall.php) — canonical cleanup script
