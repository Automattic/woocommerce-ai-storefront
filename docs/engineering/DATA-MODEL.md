# Data Model

Inventory of every persisted artifact this plugin writes — options, transients, post meta, scheduled events. For each: where it's defined, who reads/writes it, lifetime, and behavior on uninstall.

The plugin maintains a deliberately small surface. Two options, three transients, one scheduled event, four order-meta keys, one post-meta key. No custom tables. No custom post types.

## Options (wp_options)

### `wc_ai_storefront_settings`

The single source of truth for all runtime settings.

- **Type:** serialized PHP array
- **Autoload:** `yes` — every page load reads this option, so we want it in `alloptions` cache
- **Defined in:** `WC_AI_Storefront::SETTINGS_OPTION` constant ([`includes/class-wc-ai-storefront.php`](../../includes/class-wc-ai-storefront.php))
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

### Silent migrations

`get_settings()` performs in-place migration on read for legacy values. The migrated value is written back to the option (with cache invalidation) so subsequent reads short-circuit:

| Legacy value | Migrated to | Reason |
|--------------|-------------|--------|
| `product_selection_mode = 'categories'` | `'by_taxonomy'` | Pre-0.1.5 had three separate enum values for category/tag/brand-only selection. Consolidated into UNION-style `by_taxonomy` mode. |
| `product_selection_mode = 'tags'` | `'by_taxonomy'` | Same. |
| `product_selection_mode = 'brands'` | `'by_taxonomy'` | Same. |

Migrating on read (rather than activation) means rollback-then-forward across plugin versions converges to the same state, and avoids coupling to activation-hook timing.

### `wc_ai_storefront_version`

Tracks the currently-installed plugin version. Used to detect upgrades and trigger one-time post-upgrade work (rewrite-rule flush, cache bust).

- **Type:** string (`X.Y.Z`)
- **Autoload:** `yes`
- **Written by:** `WC_AI_Storefront::register_rewrite_rules()` after detecting `WC_AI_STOREFRONT_VERSION !== get_option('wc_ai_storefront_version')`
- **Read by:** the same boot path that writes it
- **Uninstall:** deleted by `uninstall.php`

**Why version detection runs in the rewrite path, not in activation:** WordPress fires the activation hook only on fresh activate, not on in-place zip upgrades. To catch upgrades reliably, the version check has to run on every boot — and the cheapest place to put it is the rewrite-rule registration that runs unconditionally during `init`.

---

## Transients (wp_options or persistent object cache)

### `wc_ai_storefront_llms_txt`

Cached `/llms.txt` Markdown body. Avoids regenerating on every crawler hit.

- **TTL:** 1 hour (`HOUR_IN_SECONDS`)
- **Defined in:** `WC_AI_Storefront_Llms_Txt::CACHE_KEY`
- **Written by:** `WC_AI_Storefront_Llms_Txt::serve_llms_txt()` after generating; also eagerly written on settings save when `enabled` flips on
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` on product/category/settings changes
- **Uninstall:** deleted by `uninstall.php`

### `wc_ai_storefront_ucp`

Cached `/.well-known/ucp` JSON manifest body.

- **TTL:** 1 hour
- **Defined in:** `WC_AI_Storefront_Ucp::CACHE_KEY`
- **Written by:** `WC_AI_Storefront_Ucp::serve_manifest()`; also eagerly written on settings save when `enabled` flips on
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` on product/category/settings changes; version-based bust on plugin update
- **Uninstall:** deleted by `uninstall.php`

### `wc_ai_storefront_flush_rewrite`

Marker that a rewrite-rule flush is pending. Set by `update_settings()` when the `enabled` flag flips; consumed and deleted on the next boot that picks it up.

- **TTL:** 1 hour (defensive — should be consumed on the next request)
- **Set by:** `WC_AI_Storefront_Admin_Controller::update_settings()`
- **Consumed by:** `WC_AI_Storefront::register_rewrite_rules()`
- **Uninstall:** deleted by `uninstall.php`

**Why a transient instead of a direct `flush_rewrite_rules()` call:** flushing inside a REST request adds 100ms+ of latency on every settings save. Deferring the flush to the next page load keeps the admin UI responsive.

### Note on UCP REST responses

UCP REST endpoint responses (`/catalog/search`, `/catalog/lookup`, `/checkout-sessions`) are **not** cached. Every dispatch computes fresh because:

- Per-request attribution (`utm_source` from `UCP-Agent`) must vary per agent.
- `chk_*` session IDs must be unique per request.
- Caching catalog results would require keying on the full request body shape — high risk, low payoff for real-world request mixes.

Store API responses dispatched via `rest_do_request()` may be cached by WP's REST infrastructure if upstream filters elect to; we don't introduce additional cache layers.

---

## Order meta

WooCommerce stores order meta in the `wp_postmeta` table (legacy mode) or the `wp_wc_orders_meta` table (HPOS — High-Performance Order Storage). This plugin is HPOS-compatible: order access goes through `WC_Order` methods exclusively, never raw post meta queries.

### `_wc_order_attribution_utm_source`

The agent's brand identifier — `chatgpt`, `gemini`, `claude`, etc.

- **Defined by:** WooCommerce core (Order Attribution feature, since WC 8.5)
- **Written by:** WC core's Order Attribution capture (sourced from URL UTM params)
- **Read by:** WC core's Orders list "Origin" column; this plugin's `WC_AI_Storefront_Attribution::get_stats()` SQL aggregator
- **Uninstall:** **NOT** deleted by `uninstall.php` — these are historical merchant transaction records.

### `_wc_order_attribution_utm_medium`

Always `ai_agent` for AI-referred orders. Used as a discriminator in the stats query.

- **Defined by:** WooCommerce core
- **Same lifecycle as `utm_source`**
- **Uninstall:** NOT deleted

### `_wc_ai_storefront_agent`

Canonical brand name (denormalized from `utm_source` for fast indexed queries on stats aggregation). Goes through `WC_AI_Storefront_UCP_Agent_Header::canonicalize()` — unknown hosts bucket to `WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET` (`other_ai`).

- **Defined in:** `WC_AI_Storefront_Attribution::AGENT_META_KEY`
- **Written by:** `WC_AI_Storefront_Attribution::capture_ai_attribution()`
- **Read by:** the per-agent breakdown stats query, the Recent AI Orders REST endpoint
- **Uninstall:** NOT deleted

### `_wc_ai_storefront_agent_host_raw`

Raw host from the request's `UCP-Agent` header, preserved for provenance auditing — useful when a stats anomaly needs to be debugged back to the actual incoming traffic.

- **Defined in:** `WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY`
- **Written alongside `_wc_ai_storefront_agent`**
- **Uninstall:** NOT deleted

### `_wc_ai_storefront_session_id`

The `chk_<16 hex chars>` correlation token returned from `POST /checkout-sessions`. Stored on the order so a support engineer can trace a completed order back to the exact UCP session that produced the cart.

- **Defined in:** `WC_AI_Storefront_Attribution::SESSION_META_KEY`
- **Written by:** `WC_AI_Storefront_Attribution::capture_ai_attribution()`
- **Uninstall:** NOT deleted

### Why uninstall doesn't delete order meta

These keys live on historical orders — purchased products, paid invoices, real customer transactions. Destroying them on plugin delete would erase legitimate business records that the merchant may need for accounting, support, or analytics. If a merchant explicitly wants to purge after uninstall, they can do it with WP-CLI:

```bash
wp post meta delete --all --keys=_wc_ai_storefront_agent,_wc_ai_storefront_session_id,_wc_ai_storefront_agent_host_raw
```

This is the canonical WooCommerce pattern — WC itself doesn't delete order data on uninstall either.

---

## Post meta (products)

### `_wc_ai_storefront_final_sale`

Per-product override for the store-wide return policy. When `'yes'`, the product's JSON-LD emits a `MerchantReturnPolicy` with the final-sale flag regardless of the store-wide setting.

- **Type:** string (`'yes'` or empty)
- **Defined in:** `WC_AI_Storefront_Product_Meta_Box::META_KEY`
- **Written by:** the **AI: Final sale** checkbox in the product editor's Inventory tab
- **Read by:** `WC_AI_Storefront_JsonLd::build_return_policy_block()`
- **Uninstall:** NOT deleted (it's per-product editorial data — same rationale as order meta)

**Why underscore-prefixed:** WordPress treats meta keys starting with `_` as protected (not editable from the default Custom Fields meta box). This is what WooCommerce does for its own product meta and is the right convention for keys we control programmatically.

---

## Scheduled events (cron)

### `wc_ai_storefront_warm_llms_txt_cache`

Debounced WP-Cron event that regenerates the `/llms.txt` cache after a content change.

- **Schedule:** one-shot (rescheduled on each invalidation event)
- **Defined in:** `WC_AI_Storefront_Cache_Invalidator`
- **Triggered by:** product CRUD, category CRUD, settings updates
- **Uninstall:** cleared by `uninstall.php` via `wp_clear_scheduled_hook()`

**Why debounced:** a bulk product import would otherwise fire dozens of cache regenerations in sequence. The debounce coalesces them — the cache is stale during the gap, but staleness is bounded by the regeneration window (seconds, not minutes).

---

## Multisite

When activated network-wide, options and transients are per-site (each site has its own `wp_options` row). `uninstall.php` loops through `get_sites()` and deletes from each site:

- `wc_ai_storefront_settings`
- `wc_ai_storefront_version`
- `wc_ai_storefront_llms_txt` (transient)
- `wc_ai_storefront_ucp` (transient)
- `wc_ai_storefront_flush_rewrite` (transient)
- `wc_ai_storefront_warm_llms_txt_cache` (cron)

The cleanup loop is wrapped in a function-existence guard so re-running uninstall (e.g. by mistake) doesn't redefine the function and warn.

---

## Data flow summary

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


Customer completes checkout from utm_source=chatgpt link
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

---

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does
- [`API-REFERENCE.md`](API-REFERENCE.md) — endpoint shapes that read/write this data
- [`TESTING.md`](TESTING.md) — `SettingsMigrationTest`, `CacheInvalidatorTest`, `AttributionTest` exercise this surface
- [`uninstall.php`](../../uninstall.php) — the canonical cleanup script
