# Data Model

Inventory of every persisted artifact this plugin writes — options, transients, post meta, order meta, scheduled events. For each: where it's defined, who reads/writes it, lifetime, and behavior on uninstall.

The surface is deliberately small: two options, eight transients, three scheduled events, one post-meta key, four order-meta keys, and two custom tables. No custom post types.

The two custom tables back the Discovery analytics surface. They were added in 0.8.6 alongside the crawler-side visibility stats. Pre-0.8.6 installs got both tables created on the version-bump dbDelta path; the schema is rebuildable on every version bump and the tables are dropped on uninstall.

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
                                    wc_ai_storefront_llms_txt_{md5(host)}
                                    (wc_ai_storefront_ucp — manifest per-request, delete cleans up warm-up copy)
                                         │
                                         │  schedule cron
                                         ▼
                            wc_ai_storefront_warm_llms_txt_cache


AI agent fetches /llms.txt
    │
    ▼
WC_AI_Storefront_Llms_Txt::serve_llms_txt()
    │
    │  get_transient(host_cache_key())  ← 'wc_ai_storefront_llms_txt_{md5(HTTP_HOST)}'
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

### `wc_ai_storefront_llms_txt` (and host-keyed variant)

Cached `/llms.txt` Markdown body. Avoids regenerating on every crawler hit.

- **TTL:** 1 hour (`HOUR_IN_SECONDS`)
- **Base key defined in:** `WC_AI_Storefront_Llms_Txt::CACHE_KEY`
- **Actual storage key:** `CACHE_KEY . '_' . md5(HTTP_HOST)` — per-virtual-host segmentation so two WordPress instances sharing a Redis/Memcached object cache (e.g. multisite) never serve each other's cached bodies. The base constant is kept for backward-compat; all read/write calls use `WC_AI_Storefront_Llms_Txt::host_cache_key()`.
- **Written by:** `serve_llms_txt()` after generating; eagerly written on settings save when `enabled` flips on
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` on product/category/settings changes
- **Uninstall:** deleted by `uninstall.php`

### `wc_ai_storefront_ucp`

The `WC_AI_Storefront_Ucp::CACHE_KEY` constant has been removed (closes #177). The UCP manifest is now **generated per-request** rather than cached: the generation path is cheap (no external HTTP probes, no unbounded DB queries) and per-request computation eliminates the Host-keying problem entirely. The `Vary: Host` response header handles HTTP-layer caching separately.

- **Currently written by:** `WC_AI_Storefront_Admin_Controller::update_settings()` on enable (warm-up hint, labeled legacy in-code). `serve_manifest()` does **not** read this key — the manifest is generated per-request.
- **Currently deleted by:** cache invalidator and `deactivate()`, to clean up both pre-1.0 installs and the admin warm-up copy.
- **Uninstall:** `uninstall.php` still deletes it (defensive, covers any stale value from a pre-0.6.6 install).

### `wc_ai_storefront_flush_rewrite`

Marker that a rewrite-rule flush is pending. Set by `update_settings()` when the `enabled` flag flips; consumed and deleted on the next boot.

- **TTL:** 1 hour (defensive; should be consumed on the next request)
- **Set by:** `WC_AI_Storefront_Admin_Controller::update_settings()`
- **Consumed by:** `WC_AI_Storefront::register_rewrite_rules()`
- **Uninstall:** deleted by `uninstall.php`

A transient (instead of a direct `flush_rewrite_rules()` call) defers the 100ms+ flush latency to the next page load.

### `wc_ai_storefront_catalog_summary`

Cached top-category list used by the store/home-page JSON-LD `ItemList` block in `WC_AI_Storefront_JsonLd`. Avoids a `get_terms()` query on every page load.

- **TTL:** 1 hour (`HOUR_IN_SECONDS`)
- **Written by:** `WC_AI_Storefront_JsonLd::get_catalog_summary()` after building the category list
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` (registered via `WC_AI_Storefront_Cache_Invalidator::register()` in the main plugin class)
- **Uninstall:** deleted by `uninstall.php`

### `wc_ai_storefront_website_jsonld`

Cached global `WebSite` + `SearchAction` JSON-LD block emitted on every page by `WC_AI_Storefront_JsonLd::output_website_jsonld()`. The block depends only on the store URL and settings (not the current page or user), so a single site-wide key serves every page.

- **Key defined in:** `WC_AI_Storefront_JsonLd::WEBSITE_JSONLD_CACHE_KEY`
- **TTL:** 1 hour (`HOUR_IN_SECONDS`)
- **Written by:** `output_website_jsonld()` after the `wc_ai_storefront_jsonld_website` filter (a filter that returns falsy suppresses the block and nothing is cached)
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` (registered in the main plugin class)
- **Uninstall:** deleted by `uninstall.php`

### `wc_ai_storefront_itemlist_{context}` (keyspace family)

Cached archive `ItemList` blocks emitted by `WC_AI_Storefront_JsonLd::output_archive_itemlist_jsonld()`, one transient per archive page view. Like `stats_{period}`, this is a family of keys sharing a prefix rather than a single key.

- **Prefix defined in:** `WC_AI_Storefront_JsonLd::ITEMLIST_JSONLD_CACHE_PREFIX` (`wc_ai_storefront_itemlist_`). The same constant is read by `WC_AI_Storefront_Cache_Invalidator` so the wildcard-delete pattern can't drift from the write keys.
- **Key shapes:** `…itemlist_cat_<term_id>_<page>`, `…itemlist_tag_<term_id>_<page>`, `…itemlist_shop_<page>`. **Search pages are intentionally not cached** — a `…itemlist_search_<md5(query)>_<page>` key would have cardinality bounded only by the distinct `?s=` values unauthenticated visitors supply, which would flood `wp_options`; search blocks recompute fresh on every request instead (no read, no write).
- **TTL:** 1 hour (`HOUR_IN_SECONDS`)
- **Written by:** `output_archive_itemlist_jsonld()` for the shop / category / tag contexts only. An un-encodable payload (`wp_json_encode` returns `false`) is never written.
- **Invalidated by:** `WC_AI_Storefront_Cache_Invalidator` via a `LIKE '_transient_<prefix>%' OR '_transient_timeout_<prefix>%'` wildcard delete (single-site and per-blog multisite paths) on any product or term change — the whole family is purged at once.
- **Uninstall:** deleted by `uninstall.php` via the same prefix wildcard.

### `wc_ai_storefront_stats_{period}`

Cached AI-attributed order aggregates served by `GET /admin/stats`. Four variants: `wc_ai_storefront_stats_day`, `wc_ai_storefront_stats_week`, `wc_ai_storefront_stats_month`, `wc_ai_storefront_stats_year`.

- **TTL:** 5 minutes (`5 * MINUTE_IN_SECONDS`)
- **Written by:** `WC_AI_Storefront_Attribution::get_stats()` after computing the SQL aggregates
- **Invalidated by:** `WC_AI_Storefront_Attribution::bust_stats_cache()` on order status transitions (`woocommerce_order_status_completed`, `woocommerce_order_status_processing`) and order deletion/trash hooks. All four period variants are deleted together on each bust.
- **Uninstall:** deleted by `uninstall.php` (all four variants)

### `wc_ai_storefront_crawl_stats_{period}`

Cached crawler-visibility aggregates served by `GET /admin/crawl-stats`. Four variants: `wc_ai_storefront_crawl_stats_day`, `wc_ai_storefront_crawl_stats_week`, `wc_ai_storefront_crawl_stats_month`, `wc_ai_storefront_crawl_stats_quarter`.

- **TTL:** 5 minutes (`5 * MINUTE_IN_SECONDS`)
- **Written by:** `WC_AI_Storefront_Admin_Controller::get_crawl_stats()` after the four supporting SELECTs against the summary table
- **Invalidated by:** the daily rollup cron (`wc_ai_storefront_rollup_crawl_log`) busts all four variants once new aggregate rows are written so the next admin request re-reads
- **Uninstall:** deleted by `uninstall.php` (all four variants)

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

## Custom tables

Two MySQL tables back the Discovery analytics surface. Both are scoped to the site's `$wpdb->prefix`, so multisite installs get one pair per site. Schema is created and upgraded via `dbDelta` on plugin version bump (idempotent — safe to re-run); both tables are dropped on uninstall.

### `{prefix}wc_ai_storefront_crawl_log`

Raw event log — one row per identified AI-agent request. Written from a static pending-array buffer that flushes on WordPress's `shutdown` action, so the latency added to any individual AI request is one batched INSERT at the end of the response.

- **Defined in:** `WC_AI_Storefront_Crawl_Logger::TABLE_LOG` ([`includes/ai-storefront/class-wc-ai-storefront-crawl-logger.php`](../../includes/ai-storefront/class-wc-ai-storefront-crawl-logger.php))
- **Written by:** `WC_AI_Storefront_Crawl_Logger::record()` calls from `WC_AI_Storefront_Attribution`, `WC_AI_Storefront_Robots`, `WC_AI_Storefront_UCP_REST_Controller`, `WC_AI_Storefront_Store_API_Rate_Limiter`. The `/.well-known/ucp` manifest and `/llms.txt` surfaces are no longer recorded — they are now edge-cached (`Cache-Control: public, max-age`), so a CDN HIT never reaches PHP; `WC_AI_Storefront_UCP` and `WC_AI_Storefront_Llms_Txt` no longer call `record()`.
- **Retention:** `WC_AI_Storefront_Crawl_Logger::RAW_RETENTION_DAYS = 30`. Pruned by the daily cron `wc_ai_storefront_prune_crawl_log`.
- **Uninstall:** dropped via `DROP TABLE` in `uninstall.php`

**Schema (key columns):** event timestamp, agent name (resolved from User-Agent), endpoint kind (one of llms.txt, UCP manifest, UCP REST, robots, Store API), URL path, status code, throttled flag, optional product IDs returned, optional Store API search query. The `llms_txt` and `ucp` (manifest) `ENDPOINT_*` constants remain defined on `WC_AI_Storefront_Crawl_Logger`, but no rows are written for those two kinds anymore — both surfaces are edge-cached, so their fetches no longer reach the logger.

### `{prefix}wc_ai_storefront_crawl_summary`

Daily aggregates rolled up from the raw log. Powers the `/crawl-stats` admin endpoint without scanning the raw table on every request.

- **Defined in:** `WC_AI_Storefront_Crawl_Logger::TABLE_SUMMARY`
- **Written by:** `wc_ai_storefront_rollup_crawl_log` cron — selects yesterday's and today's raw rows, groups by (date, agent, endpoint, product_id), and upserts one row per group. Default schedule is `hourly`; override with the `wc_ai_storefront_rollup_interval` filter.
- **Retention:** `WC_AI_Storefront_Crawl_Logger::SUMMARY_RETENTION_DAYS = 90`. Pruned inside `rollup()` via `prune_summary()` on every successful rollup run.
- **Uninstall:** dropped via `DROP TABLE` in `uninstall.php`

The summary table is refreshed on every rollup run (hourly by default). Today's in-progress events appear within one rollup cycle. The rollup uses `INSERT … ON DUPLICATE KEY UPDATE` so repeated runs are safe.

**Note on top_queries:** Search query strings are *not* aggregated into the summary table — `top_queries` in `/crawl-stats` reads from the raw log directly. Because raw rows are pruned at `RAW_RETENTION_DAYS = 30`, the effective top-searches lookback is clamped to `min(period_days, 30)`. The API surfaces the effective window as `top_queries_window_days` in the response so consumers can label it accurately (e.g. `period=quarter` returns the last 30 days of searches, not 90).

---

## Scheduled events (cron)

### `wc_ai_storefront_warm_llms_txt_cache`

Debounced WP-Cron event that regenerates the `/llms.txt` cache after a content change.

- **Schedule:** one-shot (rescheduled on each invalidation event)
- **Defined in:** `WC_AI_Storefront_Cache_Invalidator`
- **Triggered by:** product CRUD, category CRUD, settings updates
- **Uninstall:** cleared by `uninstall.php` via `wp_clear_scheduled_hook()`

The debounce coalesces invalidations so a bulk product import doesn't fire dozens of regenerations in sequence. Staleness is bounded by the regeneration window — seconds, not minutes.

### `wc_ai_storefront_prune_crawl_log`

Daily cron that deletes raw log rows older than `RAW_RETENTION_DAYS` (30) and summary rows older than `SUMMARY_RETENTION_DAYS` (90).

- **Schedule:** `daily`, anchored to UTC midnight
- **Defined in:** `WC_AI_Storefront_Crawl_Logger`
- **Uninstall:** cleared by `uninstall.php`

### `wc_ai_storefront_rollup_crawl_log`

Hourly cron that rolls yesterday's and today's raw log into the summary table, keeping stats within ~1 hour of real-time.

- **Schedule:** `hourly` by default. Override with the `wc_ai_storefront_rollup_interval` filter; allowed values are `hourly`, `twicedaily`, and `daily` — only these three cadences are safe within the 2-day rollup window. Any other value silently falls back to `hourly`. `schedule_crons()` runs on every request (it's wired into plugin bootstrap, not gated to admin), so each request compares the existing event's recurrence to the filtered value and auto-migrates mismatches. Filter changes therefore take effect on the very next request — no manual `wp cron` commands needed.
- **Defined in:** `WC_AI_Storefront_Crawl_Logger`
- **Uninstall:** cleared by `uninstall.php`

---

## Multisite

When activated network-wide, options and transients are per-site (each site has its own `wp_options` row). `uninstall.php` loops through `get_sites()` and deletes from each:

- `wc_ai_storefront_settings`, `wc_ai_storefront_version`
- `wc_ai_storefront_llms_txt`, `wc_ai_storefront_ucp`, `wc_ai_storefront_flush_rewrite`, `wc_ai_storefront_catalog_summary`, `wc_ai_storefront_stats_{day,week,month,year}`, `wc_ai_storefront_crawl_stats_{day,week,month,quarter}` (transients)
- `wc_ai_storefront_warm_llms_txt_cache`, `wc_ai_storefront_prune_crawl_log`, `wc_ai_storefront_rollup_crawl_log` (cron)
- `{prefix}wc_ai_storefront_crawl_log`, `{prefix}wc_ai_storefront_crawl_summary` (custom tables, dropped via `DROP TABLE`)

The cleanup loop is wrapped in a function-existence guard so re-running uninstall by mistake doesn't redefine the function and warn.

---

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does
- [`API-REFERENCE.md`](API-REFERENCE.md) — endpoint shapes that read/write this data
- [`HOOKS.md`](HOOKS.md) — filters that intercept the data before it's written
- [`TESTING.md`](TESTING.md) — `SettingsMigrationTest`, `CacheInvalidatorTest`, `AttributionTest` exercise this surface
- [`../../uninstall.php`](../../uninstall.php) — canonical cleanup script
