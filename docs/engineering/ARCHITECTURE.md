# WooCommerce AI Storefront — Architecture

A WooCommerce plugin that makes merchant product catalogs discoverable by AI agents (ChatGPT, Gemini, Claude, Perplexity, Copilot) while keeping checkout, customer data, and brand experience under merchant control.

**Core principle: AI agents discover and recommend. The merchant owns the transaction.**

## Why it exists

AI agents are a fast-growing product-discovery channel. The plugin lets merchants participate while staying in control:

- **Agnostic** — works with any AI agent that crawls the web; not tied to a platform.
- **Data sovereignty** — checkout on the merchant's domain; customer data never leaves the store.
- **No authentication** — discovery uses open web standards (llms.txt, JSON-LD, robots.txt).
- **No payment-provider lock-in** — works with any WooCommerce-compatible gateway.
- **Standard attribution** — uses WooCommerce's built-in Order Attribution.

## High-level diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                          AI AGENTS                                  │
│     (ChatGPT, Gemini, Perplexity, Claude, Copilot, any bot)         │
└───────┬──────────────┬──────────────┬────────────────────┬──────────┘
        │              │              │                    │
  ┌─────▼─────┐ ┌─────▼──────┐ ┌─────▼─────────┐ ┌──────────▼──────────┐
  │ /llms.txt │ │ UCP        │ │  JSON-LD      │ │ UCP REST API        │
  │ (Markdown)│ │ Manifest   │ │ Product +     │ │ /wp-json/wc/ucp/v1/ │
  │           │ │ (JSON)     │ │ OnlineBusiness│ │                     │
  │           │ │/.well-known│ │ (homepage +   │ │                     │
  │           │ │   /ucp     │ │ product page) │ │                     │
  └────────────┘ └─────────────┘ └───────────────┘ └──────────┬─────────┘
        │              │              │                     │
        └──────────────┼──────────────┴─────────────────────┘
                       │                         │
           ┌───────────▼────────────┐            │
           │   WooCommerce Core     │◄───────────┘
           │  Store API (public)    │  rest_do_request
           │  Order Attribution     │  (in-process)
           │  robots.txt            │
           └───────────┬────────────┘
                       │
           ┌───────────▼────────────┐
           │  Customer lands on     │
           │  merchant's store via  │
           │  Shareable Checkout    │
           │  URL (continue_url);   │
           │  checkout on their     │
           │  domain, their gateway │
           └────────────────────────┘
```

## Plugin components

### Discovery layer

| File | Endpoint | Purpose |
|------|----------|---------|
| `class-wc-ai-storefront-llms-txt.php` | `/llms.txt` | Catalog-discovery document, seven Markdown H2s: store identity (Store), browse/search URLs (Browse), top categories (Catalog), shipping & returns (Shipping & Returns), JSON-LD signpost (Structured data), agent-facing endpoints (For agents), UCP extension schema pointer (Extension schema). Sources store identity / postal address / catalog summary from the same `JsonLd` helpers feeding the homepage `OnlineBusiness` block so the two surfaces never drift. Browse-discovery URLs carry `utm_medium=referral&utm_id=woo_llms` for channel-split attribution. |
| `class-wc-ai-storefront-jsonld.php` | Product pages + homepage / shop page | **Per product:** enhanced Schema.org `Product` markup — typed properties (`color`/`size`/`material`/`pattern`), BuyAction with Shareable Checkout URL, `Offer.checkoutPageURLTemplate`, inventory, attributes, shipping (`OfferShippingDetails` with handlingTime + free-shipping rate), per-Offer return policy, and `isRelatedTo`/`isSimilarTo` from cross-sells/upsells. Variable products convert to Schema.org `ProductGroup` with per-variant `hasVariant` entries (with a postmeta-direct override path for misconfigured variations where the parent's "Used for variations" flag is unset). **Homepage / shop page:** Schema.org `OnlineBusiness` (an `Organization` subtype) with auto-sourced identity fields — `logo` (custom-logo theme mod → site-icon fallback), `address` (PostalAddress from `WC()->countries->get_base_*` minus `streetAddress`, suppressed for privacy), `contactPoint.email` (two-stage from `woocommerce_email_reply_to_address` → `woocommerce_email_from_address` with noreply guard, never falls back to `admin_email`) — plus `knowsAbout` (top product category names from cached catalog summary) and Org-level `hasMerchantReturnPolicy` when the merchant's return policy is configured. See [`JSON-LD-SCHEMA.md`](JSON-LD-SCHEMA.md) for the full field reference and example output. |
| `class-wc-ai-storefront-robots.php` | `/robots.txt` | Allow-lists known AI crawlers, allows discovery endpoints, blocks checkout/account. |
| `class-wc-ai-storefront-ucp.php` | `/.well-known/ucp` | JSON manifest declaring implemented capabilities (catalog, checkout), pointing at the UCP REST adapter, advertising empty `payment_handlers` for the redirect-only posture. |

### UCP REST adapter

The operational counterpart to the discovery layer. Translates the WooCommerce Store API into UCP-shaped responses agents can consume directly. Lives at `/wp-json/wc/ucp/v1/`.

Module location: `includes/ai-storefront/ucp-rest/`

| File | Responsibility |
|------|----------------|
| `class-wc-ai-storefront-ucp-rest-controller.php` | Registers POST routes (`/catalog/search`, `/catalog/lookup`, `/checkout-sessions`) plus a 405 stub for `/checkout-sessions/{id}` and a public `/extension/schema`. Each handler dispatches through `rest_do_request()` to the WC Store API — in-process, no HTTP overhead — so the UCP store-api filter automatically applies. Pre-builds a `category_paths` map once per request (`build_category_paths_map()`) that walks parent chains via a batch `GET /wc/store/v1/products/categories?include=<csv>` and threads the result into product translation so every category emits a `>`-delimited hierarchy string per `category.json`. |
| `class-wc-ai-storefront-ucp-product-translator.php` | WC product → UCP product. Pure function. Optionally accepts pre-fetched variations for variable-product expansion, an optional seller block, and a `category_paths` map for hierarchy strings. UTM stamping is *not* done here — callers operating in an agent context apply `WC_AI_Storefront_Attribution::with_woo_ucp_utm()` to `$product['url']` after translation, preserving the pure-function contract. Non-variable products (simple, bundle, grouped) synthesize a single default variant to satisfy UCP's `minItems: 1` on `variants`; the synthesized variant emits no `options[]` (the array of `selected_option`-shaped entries) because there's no buyer-selectable axis to lock in. `options[]` is reserved for `has_variations: true` axes — UCP `product_option.json` characterizes options by example as size, color, or material (variant-selection axes the buyer chooses between, not descriptive properties), so Color/Size/Pattern/Material on a non-variable product route to `metadata.attributes` regardless of name. Extracts the parent's variation-axis attribute names and a `term_slug_map` (keyed by `pa_*` taxonomy) from the Store API payload and threads both into the variant translator so it stays a pure function with no WP API calls. All Store API `name` fields are decoded via `decode()` (`html_entity_decode()` then `wp_strip_all_tags()`). |
| `class-wc-ai-storefront-ucp-variant-translator.php` | WC variation → UCP variant. Pure function. Builds titles and `options[]` from attribute values; when WC 9.x leaves `attributes[]` empty, falls back to parsing the `variation` formatted string (e.g. `"Color: Tan, Size: 9"`) using an anchored regex split that disambiguates commas inside values when an anchor list of known attribute names is provided. Accepts a `term_slug_map` to emit stable `selected_option.id` (`<taxonomy>:<slug>`) for taxonomy-backed attributes. Preserves integer minor units for prices. Handles simple-product defaults via `synthesize_default()` — which carries the parent's `short_description` through to the synthesized variant's `description.plain` via the same `extract_description()` helper used on the real-variation path (#375), so an agent that drills into a variant ID directly sees useful descriptive copy instead of an empty string. All Store API `name` fields are decoded via `decode()` (`html_entity_decode()` then `wp_strip_all_tags()`). |
| `class-wc-ai-storefront-ucp-envelope.php` | Builds the `ucp: { version, capabilities, payment_handlers }` wrapper that prefixes every response. Reads `PROTOCOL_VERSION` from `WC_AI_Storefront_Ucp` so manifest and response envelopes stay in sync. |
| `class-wc-ai-storefront-ucp-agent-header.php` | Parses the `UCP-Agent` header (RFC 8941 Dictionary or RFC 7231 Product/Version), normalizes hostnames, canonicalizes brand names, and falls back to `ucp_unknown` when missing/malformed. Used as `utm_source` and for the per-brand allow-list gate. |
| `class-wc-ai-storefront-ucp-store-api-filter.php` | Hooks `pre_get_posts` (gated by an internal UCP-dispatch depth counter + `post_type === 'product'`) to enforce `product_selection_mode` on UCP-controller-initiated Store API queries. Also hooks `posts_clauses` at priority 9 (one tick before WooCommerce's `add_query_clauses` at priority 10) to replace the default phrase LIKE on `post_title` with **taxonomy-aware per-signal-term matching**: signal-term extraction (lowercase, stopword strip, apostrophe-strip-in-place, hyphen/slash/comma→space split) → resolution against `product_cat` / `product_tag` / `product_brand` / `pa_*` via two scoped `get_terms()` calls (`name__in` + `slug__in`, merged by term_id) plus a suffix-flip dictionary (`ies↔y`, `{ch,sh,x,s,z}es↔base`, `s↔drop`, `y→ies`, `+es`, `+s`) → EXISTS subquery on hits (constrained by `taxonomy IN (...)` to prevent shared-term_id false positives), title LIKE expanded to both morphological forms on misses, OR per term. **Cross-term join operator** (fixed #315): the join between per-term clauses is context-sensitive, not a fixed AND. Two connector types, two rules — both read `$raw_search` (pre-stopword, pre-punctuation) because connectors are stripped before `$terms` is built: (1) **`or`** — intent is unambiguous (explicit choice); `$has_or_connector` (`/\bor\b/i`) forces OR-join regardless of taxonomy resolution, so "Hat or Shoes" returns products from either category even if a term is unresolved. (2) **`and` / comma** — ambiguous ("hat and shoes" could describe a combo product); `$has_and_connector` (`/\s+and\s+|,\s*/i`) only triggers OR when `$all_taxonomy_matched` is also true — every extracted term resolved to a taxonomy hit. If any term fell back to a title LIKE, AND is kept. Examples: "Hoodies and Belts" → OR (both taxonomy-matched); "blue and hat" → AND ("blue" unresolved); "Hoodies, Belts" → OR; "Hoodies,Belts" → OR (comma splits to space in `extract_search_terms()`); "blue or Shoes" → OR ("or" overrides the guard). Front-end Cart, themes, and third-party Store API consumers are untouched. Intersects with incoming `post__in` and merges (outer AND) with incoming `tax_query`, so the merchant's allow-list can't be bypassed AND the caller's filters stay in effect. |
| `class-wc-ai-storefront-store-api-extension.php` | Adds an `extensions.com_woocommerce_ai_storefront` block to Store API product responses with `barcodes` (GTIN/UPC/EAN/MPN) sourced from WC core's `global_unique_id`. Removable once core surfaces the field directly. |
| `class-wc-ai-storefront-ucp-error-codes.php` | Typed string constants for every UCP error code used across the REST controller. Eliminates bare string literals in handler logic and enables static-analysis exhaustiveness checks. |
| `class-wc-ai-storefront-ucp-request-context.php` | Per-request product memoization cache. Holds already-fetched `WC_Product` objects by ID so a single outer UCP request never dispatches to the Store API for the same product twice. A fresh context is created for each outer UCP request, making the controller safe under persistent-worker runtimes (Swoole, RoadRunner, FrankenPHP) where static properties survive across requests. |
| `class-wc-ai-storefront-ucp-dispatch-context.php` | Named API around the dispatch-depth counter used by `WC_AI_Storefront_UCP_Store_API_Filter` to gate the product-selection filter. `enter()` / `exit()` / `is_active()` methods replace the former anonymous static variable; `is_in_ucp_dispatch()` on the filter class is now a thin forwarding wrapper. |

**Stateless checkout pattern.** `/checkout-sessions` never persists anything. Successful responses return `status: requires_escalation` with a `continue_url` whose shape depends on the cart contents — Shareable Checkout (`/checkout-link/?products=ID:QTY`) for simple/variation carts, `/checkout/?add-to-cart=…` direct-checkout for deterministic bundles/grouped, or the product permalink for configurable bundles/grouped (see [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md#layer-3--checkout-session-the-real-green-light) for the full table). The `chk_…` session ID is a correlation token — no GET/PUT/PATCH/DELETE endpoints. Once the agent redirects, WooCommerce owns the rest.

**Endpoint-to-WC dispatch map:**
- `POST /catalog/search` → translates `query/filters` to Store API params → `GET /wc/store/v1/products`.
- `POST /catalog/lookup` → `GET /wc/store/v1/products/{id}` per requested ID.
- `POST /checkout-sessions` → `GET /wc/store/v1/products/{id}` per line item for validation (plus per-bundled-item / per-child fetches for bundle and grouped parents) → assembles a continue_url whose shape depends on the cart contents.

**Variable product expansion.** When search or lookup hits a variable product, the controller pre-fetches each variation's Store API record via additional `rest_do_request` calls and passes them to the translator. As of #369 the gate accepts both `variable` and `variable-subscription` types — WC Subscriptions' extension type extends `WC_Product_Variable` and exposes its variations through the same `variations[]` array on the Store API response, so subscription parents enumerate identically to plain variable parents. `WC_AI_Storefront_UCP_Request_Context` memoizes translated products within a single outer request, so a high-variation catalog (e.g. 20 products × 5 variations each = 100 inner dispatches) never fetches the same product twice.

**Featured-variant precision.** The catalog response assembly picks exactly one featured variant when an input resolves to a parent product, using the merchant's `_default_attributes` postmeta (surfaced through the Store API's `attributes[].terms[].default` flag) when available, falling back to first-by-`menu_order` otherwise. The featured variant is reordered to `variants[0]` so both signals (`match: featured` in `inputs[]` AND position 0) agree. Sibling variants emit `inputs: [{id: <input>}]` with no `match` field — spec-clean per `input_correlation.json` where `match` is optional. See [`API-REFERENCE.md`](API-REFERENCE.md#post-cataloglookup) for the lookup-side semantics.

### Attribution

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-attribution.php` | Captures AI-referred orders via WooCommerce Order Attribution. Two recognition gates evaluated in parallel: STRICT (`utm_id === 'woo_ucp'` or legacy `utm_medium === 'ai_agent'`) and LENIENT (`utm_source` matches a known agent host). Hosts the `with_woo_ucp_utm()` helper — the single source of truth for the canonical UTM wire shape stamped onto continue_urls AND bare product URLs from `/catalog/search` and `/catalog/lookup`. |

The canonical UTM payload (0.5.0+):

```
utm_source=<lowercase agent hostname, or ucp_unknown>
utm_medium=referral
utm_id=woo_ucp
ai_agent_host_raw=<raw producer-side identifier>      # optional, only when agent was identifiable
```

`ai_session_id=<chk_…>` is **agent-supplied**, not server-stamped. Agents that want session-correlation append their own `ai_session_id` query param to the continue_url before redirecting; the plugin's order-attribution capture reads it from `$_GET` on the WC checkout page and writes it to the `_wc_ai_storefront_session_id` order meta.

Agent name is surfaced in WC core's "Origin" column (fed by `_wc_order_attribution_utm_source`).

### Multi-currency

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-multi-currency.php` | Pure helper. Two public methods: `get_accepted_currencies()` (returns an ordered, deduplicated ISO-4217 list with the base currency first; soft-reads the WooPayments multi-currency enabled set when present, falls back to `[ base_currency ]` otherwise) and `stamp_currency_query()` (stamps `?currency=XXX` on outbound URLs when the request currency is in the accepted set). Called by the UCP manifest (`class-wc-ai-storefront-ucp.php::build_store_context()` for `store_context.accepted_currencies`), the JSON-LD homepage emitter (`currenciesAccepted` space-separated list), the llms.txt generator (`**Accepted currencies**` line), and the UCP REST controller's `build_continue_url()` plus per-product URL stamping in `/catalog/search` / `/catalog/lookup`. Filter: `wc_ai_storefront_accepted_currencies`. Since 0.17.0. |

### Rate limiting

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-store-api-rate-limiter.php` | Two-layer rate limiting for AI bot traffic. (1) **Outer layer:** `check_outer_rate_limit()` is called by `check_agent_access()` and counts exactly one slot per logical outer UCP request (e.g. one `/catalog/lookup` with 50 IDs = 1 slot). Uses a per-fingerprint WP transient with a 60-second sliding window. (2) **Inner layer suppression:** `configure_rate_limits()` returns `enabled: false` when `WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch()` is true, so WC's built-in per-Store-API-call counter is disabled for inner `rest_do_request()` dispatches. Fingerprints AI bots by user-agent via `woocommerce_store_api_rate_limit_id`. Regular customer traffic is unaffected; direct `/wc/store/v1/` requests from AI bot UAs outside the UCP bracket remain subject to WC's default counter. The merchant's `rate_limit_rpm` setting reflects outer-request semantics. |

### Cache invalidation

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-cache-invalidator.php` | Event-driven cache invalidation. Components register their own transient keys via `WC_AI_Storefront_Cache_Invalidator::register()` rather than the key list being hardcoded here. Hooks product/category CRUD, stock changes, and settings updates. Debounced WP-Cron warm-up. |

### Debug logging

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-logger.php` | Off-by-default. Enable per-request via `add_filter( 'wc_ai_storefront_debug', '__return_true' );`. Instruments cache hit/miss, rate-limit fingerprint matches, attribution captures. Output goes to `error_log()` (usually `wp-content/debug.log` when `WP_DEBUG_LOG` is on) prefixed `[wc-ai-storefront]`. The filter is evaluated once per request and cached. |

### Crawler analytics

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-crawl-logger.php` | Records every identified AI-agent request into `{prefix}wc_ai_storefront_crawl_log` (raw events, 30-day retention) and `{prefix}wc_ai_storefront_crawl_summary` (daily aggregates, 90-day retention). `record()` is called from attribution, llms-txt, robots, ucp, ucp-rest-controller, and the rate limiter; calls push onto a static pending array that flushes on WordPress's `shutdown` action so per-request latency is unchanged. Schema is created/upgraded via `dbDelta` on plugin version bump (idempotent). Two WP cron jobs handle retention and rollup: `wc_ai_storefront_prune_crawl_log` (daily) and `wc_ai_storefront_rollup_crawl_log` (hourly by default, overridable via the `wc_ai_storefront_rollup_interval` filter). Powers the Discovery tab's analytics surface via `GET /admin/crawl-stats`. See [`DATA-MODEL.md`](DATA-MODEL.md#custom-tables) for schema and retention details. |

### Admin

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-admin-controller.php` | REST API for the admin settings UI: settings CRUD, stats, recent orders, product count, category/tag/brand/product search, policy pages, endpoint URLs, and crawler-visibility stats (`/crawl-stats`). |
| `class-wc-ai-storefront-product-meta-box.php` | Adds the `AI: Final sale` checkbox to the product editor's Inventory tab. Read by `WC_AI_Storefront_JsonLd` to override the store-wide return policy on a per-product basis. |
| `class-wc-ai-storefront.php` | Main orchestrator (singleton): dependency loading, rewrite rules, settings with memoization + cache busting, version-based flush. |

## Frontend (React admin UI)

**Entry point:** `client/settings/ai-storefront/index.js`.

**Data store:** `client/data/ai-storefront/` — `@wordpress/data` with `createReduxStore`, async thunk resolvers and actions.

**Tabs:**

- `settings-page.js` — **Overview**: enable/disable banner, stat cards (products exposed, AI orders, total orders, AI revenue, AI AOV, top agent, top agent share), Recent AI Orders DataViews table.
- `product-selection.js` — **Product Visibility**: mode selector (all / by_taxonomy / selected), Categories/Tags/Brands sub-tabs, individual product picker, included-fields display.
- `policies-tab.js` — **Policies**: return policy mode + window + fees + methods, optional link to a returns/refunds page.
- `endpoint-info.js` — **Discovery**: discovery endpoint URLs (`/llms.txt`, `/.well-known/ucp`, UCP REST API base, `/robots.txt`), AI crawler allow-list, rate-limit slider, unknown-UCP-agent toggle.

**Shared modules:**

- `tokens.js` — design tokens (semantic color names mapped to the WP admin palette). See [`UI-CONVENTIONS.md`](UI-CONVENTIONS.md) for the rule.

**Build integration:**

- `webpack.config.js` swaps WP's default dependency extractor for `@woocommerce/dependency-extraction-webpack-plugin`, which handles both `@wordpress/*` and `@woocommerce/*` imports as runtime externals. `@wordpress/dataviews` is in the bundled-packages list — its JS and CSS ship with our build, no merchant-environment dependency.

UI conventions, component-library precedence, and styling rules live in [`UI-CONVENTIONS.md`](UI-CONVENTIONS.md).

## File map

```
woocommerce-ai-storefront/
├── woocommerce-ai-storefront.php           # Bootstrap, HPOS declaration, activation/deactivation
├── README.md                                # GitHub-facing project overview
├── readme.txt                               # WP.org-format plugin readme
├── AGENTS.md                                # Pointer for AI coding agents → docs/engineering/
├── CONTRIBUTING.md                          # Branch naming, code review, PR conventions
├── package.json                             # Node dependencies
├── composer.json                            # PHP dev deps only (PHPUnit, Brain Monkey, PHPStan, PHPCS)
├── webpack.config.js                        # Build config (Woo dependency extraction)
├── phpunit.xml.dist                         # PHPUnit config
├── phpcs.xml.dist                           # PHPCS config (WordPress-Extra standard)
├── phpstan.neon.dist                        # PHPStan config (level 5)
├── phpstan-bootstrap.php                    # Plugin constants for PHPStan
├── uninstall.php                            # Removes options/transients on plugin delete
│
├── docs/
│   ├── README.md                            # Documentation index
│   ├── user-guide/                          # Merchant docs
│   └── engineering/                         # Developer docs
│
├── .github/workflows/
│   ├── ci.yml                               # PHPUnit (8.1–8.4), PHPCS, PHPStan, JS tests, JS lint
│   └── release.yml                          # Build distribution zip on v* tags
├── bin/
│   └── make-pot.sh                          # Regenerate translation template
├── languages/
│   └── woocommerce-ai-storefront.pot        # Gettext template
│
├── includes/
│   ├── autoload.php                          # Committed classmap autoloader (no Composer needed at runtime)
│   ├── class-wc-ai-storefront.php           # Main orchestrator
│   ├── class-wc-ai-storefront-updater.php   # Self-updater wrapper around the PUC library
│   ├── admin/
│   │   ├── class-wc-ai-storefront-admin-controller.php
│   │   └── class-wc-ai-storefront-product-meta-box.php
│   └── ai-storefront/
│       ├── class-wc-ai-storefront-llms-txt.php
│       ├── class-wc-ai-storefront-jsonld.php
│       ├── class-wc-ai-storefront-robots.php
│       ├── class-wc-ai-storefront-ucp.php
│       ├── class-wc-ai-storefront-store-api-rate-limiter.php
│       ├── class-wc-ai-storefront-attribution.php
│       ├── class-wc-ai-storefront-cache-invalidator.php
│       ├── class-wc-ai-storefront-crawl-logger.php
│       ├── class-wc-ai-storefront-logger.php
│       ├── class-wc-ai-storefront-multi-currency.php
│       ├── class-wc-ai-storefront-return-policy.php
│       └── ucp-rest/
│           ├── class-wc-ai-storefront-ucp-rest-controller.php
│           ├── class-wc-ai-storefront-ucp-product-translator.php
│           ├── class-wc-ai-storefront-ucp-variant-translator.php
│           ├── class-wc-ai-storefront-ucp-envelope.php
│           ├── class-wc-ai-storefront-ucp-agent-header.php
│           ├── class-wc-ai-storefront-ucp-store-api-filter.php
│           ├── class-wc-ai-storefront-ucp-error-codes.php
│           ├── class-wc-ai-storefront-ucp-request-context.php
│           ├── class-wc-ai-storefront-ucp-dispatch-context.php
│           └── class-wc-ai-storefront-store-api-extension.php
│
├── client/
│   ├── data/ai-storefront/                  # @wordpress/data store + Jest tests
│   └── settings/ai-storefront/              # React admin UI (4 tabs + tokens + DataViews tables)
│
├── tests/
│   └── php/
│       ├── bootstrap.php
│       ├── stubs.php                        # WC_Product, WC_Order, WP_REST_* stubs
│       ├── stubs/class-wc-ai-storefront-stub.php
│       └── unit/                            # 38+ test files
│
└── build/                                   # Compiled JS bundle (committed)
```

## Key design decisions

1. **No authentication.** AI agents discover via open web standards. UCP REST routes are public (`permission_callback` returns `true` unless the merchant has paused the plugin or blocked a specific brand). Merchants who want to block all access pause syndication via the admin UI.

2. **Stateless redirect-only checkout.** UCP manifest declares zero `payment_handlers`. Every successful `/checkout-sessions` response returns `status: requires_escalation` with a `continue_url` pointing at a merchant-domain checkout URL — Shareable Checkout for simple/variation carts, `/checkout/?add-to-cart=…` for deterministic bundles/grouped, or the product PDP permalink for configurable bundles/grouped. No cart persistence, no session tokens, no get/update/complete/cancel endpoints. Merchants keep full ownership of payment, tax, fulfillment.

3. **Data sovereignty.** Checkout happens on the merchant's domain. No delegated payments, no platform lock-in.

4. **Standard WooCommerce attribution.** Uses the built-in Order Attribution system. The UCP REST adapter auto-stamps `utm_source=<agent hostname>&utm_medium=referral&utm_id=woo_ucp` on every continue_url AND on every product `url` field returned by `/catalog/search` and `/catalog/lookup`, so merchants see agent-sourced traffic regardless of which URL the buyer follows.

5. **Store API rate limiting.** Two-layer design: (a) one slot consumed per outer UCP request via a transient-backed counter in `check_outer_rate_limit()`; (b) WC's per-Store-API-call counter suppressed for inner `rest_do_request()` dispatches inside the UCP bracket. AI bots fingerprinted by user-agent; regular customer traffic unaffected. The merchant's `rate_limit_rpm` setting reflects outer-request semantics — 1 outer UCP call = 1 slot regardless of how many inner Store API calls it fans out to.

6. **Product selection enforced at every plugin-controlled layer.** The `product_selection_mode` setting applies to llms.txt, JSON-LD enhancement, robots.txt, AND Store API query results dispatched through the UCP controller — enforced via a `pre_get_posts` action gated on UCP dispatch depth + `post_type === 'product'`. A product excluded from syndication won't appear in UCP-mediated responses, and the plugin won't add any enhancement fields (`BuyAction`, `inventoryLevel`, `OfferShippingDetails`, etc.) to its product-page JSON-LD. **What we do NOT control:** WC core's own `Product` JSON-LD block still ships for excluded products (we layer on via filter; we don't suppress WC's emission). See `JSON-LD-SCHEMA.md` § "Visibility gating" for the full breakdown. Direct Store API access (front-end Cart, themes, third-party plugins) is also intentionally NOT scoped because Store API doesn't conform to UCP and merchants have legitimate non-AI consumers of it.

7. **Pure translators, caller-orchestrated dispatch.** Product and variant translators are pure functions — they transform data shape, never dispatch. The REST controller orchestrates fetching (detect variable products, pre-fetch variations, assemble) before handing data to translators. Keeps translators hermetically testable without stubbing WP's REST pipeline.

8. **Cache invalidation.** llms.txt uses a host-keyed transient (`CACHE_KEY + '_' + md5(HTTP_HOST)`) with event-driven invalidation and a `Vary: Host` response header so CDN/proxy and PHP-layer caches stay in sync across virtual-host boundaries. The UCP manifest is now **generated per-request** (cheap — no HTTP probes, no unbounded queries); `Vary: Host` handles HTTP-layer caching. UCP REST responses are not cached — every dispatch computes fresh because per-request attribution and session IDs vary.

9. **No MCP (Model Context Protocol) abilities registered by AI Storefront yet, but the path is open.** WooCommerce **core** now ships native MCP support starting in WC 10.3.0 (developer preview behind the `mcp_integration` feature flag, see [WC's MCP docs](https://github.com/woocommerce/woocommerce/blob/trunk/docs/features/mcp/README.md)). The integration lives in `plugins/woocommerce/src/Internal/MCP/` inside the core plugin (no separate extension); third-party plugins can register MCP-exposed abilities via the WordPress Abilities API (`wp_register_ability()`) and have them automatically surfaced through the shared WordPress MCP adapter, with no separate auth/transport/routing layer needed. Note the version floor: AI Storefront's minimum WC requirement is currently 9.9, so any MCP-registering code paths would have to feature-detect (`function_exists('wp_register_ability')` or a `WC_VERSION` check) to avoid breakage on WC 9.9–10.2. **The MCP and UCP audiences are orthogonal and AI Storefront only targets the latter:** MCP is an **admin-side** transport (the merchant's own AI assistants, like Claude or ChatGPT, acting on the store from inside the admin under merchant credentials); UCP is a **shopper-side** transport (external buyers' shopping agents discovering the catalog over the public web, no merchant auth involved). Confusing the two is a meaningful failure mode: MCP exposes admin operations gated by `permission_callback` and a REST API key, while UCP exposes only what the merchant configured as syndicated to anonymous external traffic. Candidate AI Storefront abilities for future MCP registration are therefore all *merchant-tooling* abilities: catalog-taxonomy audit (read WC categories + score against canonical product taxonomy), JSON-LD validation (run the plugin's own checks against a single product), syndication-state queries (what would `/llms.txt` say about product N?), and attribution-stats fetches. Each would be a small `wp_register_ability()` call with appropriate `permission_callback` and `mcp.public` metadata. None of these would change UCP behavior or expose anything shopper-facing.

## Settings

All runtime settings live in a single serialized option (`autoload=true` + static memoization in `WC_AI_Storefront::get_settings()`). See [`DATA-MODEL.md`](DATA-MODEL.md) for the full schema and migration history.

## Admin REST API

See [`API-REFERENCE.md`](API-REFERENCE.md) for endpoint shapes, request/response examples, and curl invocations.

## Order meta keys

See [`DATA-MODEL.md`](DATA-MODEL.md#order-meta) for the full inventory and uninstall behavior.

## Development

```bash
npm install && npm run build    # Build frontend
composer install                # Install PHP dev dependencies
vendor/bin/phpunit              # Run PHP tests
npm run test:js                 # Run JS tests
npm run lint:js                 # Lint JS
vendor/bin/phpcs                # Lint PHP
vendor/bin/phpcbf               # Auto-fix PHPCS violations
vendor/bin/phpstan analyse      # PHP static analysis (level 5)
./bin/make-pot.sh               # Regenerate languages/*.pot
```

PHPCS uses `WordPress-Extra` plus plugin prefix declarations; PHPStan runs at level 5 with a minimal WC-function ignore list. CI runs both on every push to `main` and on PRs. See [`TESTING.md`](TESTING.md) for the testing playbook.

Requires WooCommerce 9.9+, WordPress 6.7+, PHP 8.1+.

## See also

- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — how an AI agent decides to render a Buy CTA
- [`API-REFERENCE.md`](API-REFERENCE.md) — REST endpoint shapes and examples
- [`DATA-MODEL.md`](DATA-MODEL.md) — options, transients, meta keys, cron, uninstall
- [`HOOKS.md`](HOOKS.md) — filters and actions exposed to extending plugins
- [`TESTING.md`](TESTING.md) — PHP/JS test conventions
- [`UI-CONVENTIONS.md`](UI-CONVENTIONS.md) — React component-library and styling rules
- [`../user-guide/USER-GUIDE.md`](../user-guide/USER-GUIDE.md) — merchant-facing guide
