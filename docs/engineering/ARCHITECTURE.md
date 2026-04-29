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
  ┌─────▼─────┐ ┌─────▼──────┐ ┌─────▼────────┐ ┌──────────▼──────────┐
  │ /llms.txt │ │ UCP        │ │  JSON-LD on  │ │ UCP REST API        │
  │ (Markdown)│ │ Manifest   │ │ product pages│ │ /wp-json/wc/ucp/v1/ │
  │           │ │ (JSON)     │ │              │ │                     │
  │           │ │/.well-known│ │              │ │                     │
  │           │ │   /ucp     │ │              │ │                     │
  └────────────┘ └─────────────┘ └──────────────┘ └──────────┬─────────┘
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
| `class-wc-ai-storefront-llms-txt.php` | `/llms.txt` | Machine-readable store guide: name, categories, products, attribution instructions. |
| `class-wc-ai-storefront-jsonld.php` | Product pages | Enhanced Schema.org Product markup: BuyAction, inventory, attributes, return policy. |
| `class-wc-ai-storefront-robots.php` | `/robots.txt` | Allow-lists known AI crawlers, allows discovery endpoints, blocks checkout/account. |
| `class-wc-ai-storefront-ucp.php` | `/.well-known/ucp` | JSON manifest declaring implemented capabilities (catalog, checkout), pointing at the UCP REST adapter, advertising empty `payment_handlers` for the redirect-only posture. |

### UCP REST adapter

The operational counterpart to the discovery layer. Translates the WooCommerce Store API into UCP-shaped responses agents can consume directly. Lives at `/wp-json/wc/ucp/v1/`.

Module location: `includes/ai-storefront/ucp-rest/`

| File | Responsibility |
|------|----------------|
| `class-wc-ai-storefront-ucp-rest-controller.php` | Registers POST routes (`/catalog/search`, `/catalog/lookup`, `/checkout-sessions`) plus a 405 stub for `/checkout-sessions/{id}` and a public `/extension/schema`. Each handler dispatches through `rest_do_request()` to the WC Store API — in-process, no HTTP overhead — so the UCP store-api filter automatically applies. |
| `class-wc-ai-storefront-ucp-product-translator.php` | WC product → UCP product. Pure function. Optionally accepts pre-fetched variations for variable-product expansion and `source_host` / `raw_host` for UTM stamping. Simple products synthesize a single default variant to satisfy UCP's `minItems: 1` on `variants`. |
| `class-wc-ai-storefront-ucp-variant-translator.php` | WC variation → UCP variant. Builds titles from attribute values, preserves integer minor units for prices, handles simple-product defaults via `synthesize_default()`. |
| `class-wc-ai-storefront-ucp-envelope.php` | Builds the `ucp: { version, capabilities, payment_handlers }` wrapper that prefixes every response. Reads `PROTOCOL_VERSION` from `WC_AI_Storefront_Ucp` so manifest and response envelopes stay in sync. |
| `class-wc-ai-storefront-ucp-agent-header.php` | Parses the `UCP-Agent` header (RFC 8941 Dictionary or RFC 7231 Product/Version), normalizes hostnames, canonicalizes brand names, and falls back to `ucp_unknown` when missing/malformed. Used as `utm_source` and for the per-brand allow-list gate. |
| `class-wc-ai-storefront-ucp-store-api-filter.php` | Hooks `pre_get_posts` (gated by an internal UCP-dispatch depth counter + `post_type === 'product'`) to enforce `product_selection_mode` on UCP-controller-initiated Store API queries. Front-end Cart, themes, and third-party Store API consumers are untouched. Intersects with incoming `post__in` and merges (outer AND) with incoming `tax_query`, so the merchant's allow-list can't be bypassed AND the caller's filters stay in effect. |
| `class-wc-ai-storefront-store-api-extension.php` | Adds an `extensions.com_woocommerce_ai_storefront` block to Store API product responses with `barcodes` (GTIN/UPC/EAN/MPN) sourced from WC core's `global_unique_id`. Removable once core surfaces the field directly. |

**Stateless checkout pattern.** `/checkout-sessions` never persists anything. Successful responses return `status: requires_escalation` with a `continue_url` pointing at WooCommerce's native Shareable Checkout URL (`/checkout-link/?products=ID:QTY`). The `chk_…` session ID is a correlation token — no GET/PUT/PATCH/DELETE endpoints. Once the agent redirects, WooCommerce owns the rest.

**Endpoint-to-WC dispatch map:**
- `POST /catalog/search` → translates `query/filters` to Store API params → `GET /wc/store/v1/products`.
- `POST /catalog/lookup` → `GET /wc/store/v1/products/{id}` per requested ID.
- `POST /checkout-sessions` → `GET /wc/store/v1/products/{id}` per line item for validation → assembles Shareable Checkout URL.

**Variable product expansion.** When search or lookup hits a variable product, the controller pre-fetches each variation's Store API record via additional `rest_do_request` calls and passes them to the translator. Per-request memoization is a follow-up for high-variation catalogs (a page of 20 products with 5 variables × 5 variations each = 26 dispatches).

### Attribution

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-attribution.php` | Captures AI-referred orders via WooCommerce Order Attribution. Two recognition gates evaluated in parallel: STRICT (`utm_id === 'woo_ucp'` or legacy `utm_medium === 'ai_agent'`) and LENIENT (`utm_source` matches a known agent host). Hosts the `with_woo_ucp_utm()` helper — the single source of truth for the canonical UTM wire shape stamped onto continue_urls AND bare product URLs from `/catalog/search` and `/catalog/lookup`. |

The canonical UTM payload (0.5.0+):

```
utm_source=<lowercase agent hostname, or ucp_unknown>
utm_medium=referral
utm_id=woo_ucp
ai_agent_host_raw=<raw producer-side identifier>      # optional
ai_session_id=<chk_… correlation token>               # continue_url only
```

Agent name is surfaced in WC core's "Origin" column (fed by `_wc_order_attribution_utm_source`).

### Rate limiting

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-store-api-rate-limiter.php` | Two-layer rate limiting for AI bot traffic. (1) **Outer layer:** `check_outer_rate_limit()` is called by `check_agent_access()` and counts exactly one slot per logical outer UCP request (e.g. one `/catalog/lookup` with 50 IDs = 1 slot). Uses a per-fingerprint WP transient with a 60-second sliding window. (2) **Inner layer suppression:** `configure_rate_limits()` returns `enabled: false` when `WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch()` is true, so WC's built-in per-Store-API-call counter is disabled for inner `rest_do_request()` dispatches. Fingerprints AI bots by user-agent via `woocommerce_store_api_rate_limit_id`. Regular customer traffic is unaffected; direct `/wc/store/v1/` requests from AI bot UAs outside the UCP bracket remain subject to WC's default counter. The merchant's `rate_limit_rpm` setting reflects outer-request semantics. |

### Cache invalidation

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-cache-invalidator.php` | Event-driven cache invalidation for the llms.txt and UCP-manifest transients. Hooks product/category CRUD, stock changes, and settings updates. Debounced WP-Cron warm-up. |

### Debug logging

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-logger.php` | Off-by-default. Enable per-request via `add_filter( 'wc_ai_storefront_debug', '__return_true' );`. Instruments cache hit/miss, rate-limit fingerprint matches, attribution captures. Output goes to `error_log()` (usually `wp-content/debug.log` when `WP_DEBUG_LOG` is on) prefixed `[wc-ai-storefront]`. The filter is evaluated once per request and cached. |

### Admin

| File | Purpose |
|------|---------|
| `class-wc-ai-storefront-admin-controller.php` | REST API for the admin settings UI: settings CRUD, stats, recent orders, product count, category/tag/brand/product search, policy pages, endpoint URLs. |
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
├── composer.json                            # PHP deps (PHPUnit, Brain Monkey, PHPStan, PHPCS)
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
│       ├── class-wc-ai-storefront-logger.php
│       ├── class-wc-ai-storefront-return-policy.php
│       └── ucp-rest/
│           ├── class-wc-ai-storefront-ucp-rest-controller.php
│           ├── class-wc-ai-storefront-ucp-product-translator.php
│           ├── class-wc-ai-storefront-ucp-variant-translator.php
│           ├── class-wc-ai-storefront-ucp-envelope.php
│           ├── class-wc-ai-storefront-ucp-agent-header.php
│           ├── class-wc-ai-storefront-ucp-store-api-filter.php
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

2. **Stateless redirect-only checkout.** UCP manifest declares zero `payment_handlers`. Every successful `/checkout-sessions` response returns `status: requires_escalation` with a `continue_url` pointing at WooCommerce's native Shareable Checkout URL. No cart persistence, no session tokens, no get/update/complete/cancel endpoints. Merchants keep full ownership of payment, tax, fulfillment.

3. **Data sovereignty.** Checkout happens on the merchant's domain. No delegated payments, no platform lock-in.

4. **Standard WooCommerce attribution.** Uses the built-in Order Attribution system. The UCP REST adapter auto-stamps `utm_source=<agent hostname>&utm_medium=referral&utm_id=woo_ucp` on every continue_url AND on every product `url` field returned by `/catalog/search` and `/catalog/lookup`, so merchants see agent-sourced traffic regardless of which URL the buyer follows.

5. **Store API rate limiting.** Two-layer design: (a) one slot consumed per outer UCP request via a transient-backed counter in `check_outer_rate_limit()`; (b) WC's per-Store-API-call counter suppressed for inner `rest_do_request()` dispatches inside the UCP bracket. AI bots fingerprinted by user-agent; regular customer traffic unaffected. The merchant's `rate_limit_rpm` setting reflects outer-request semantics — 1 outer UCP call = 1 slot regardless of how many inner Store API calls it fans out to.

6. **Product selection enforced at every layer.** The `product_selection_mode` setting applies to llms.txt, JSON-LD, robots.txt, AND Store API query results dispatched through the UCP controller — enforced via a `pre_get_posts` action gated on UCP dispatch depth + `post_type === 'product'`. A product excluded from syndication won't appear in UCP-mediated responses; direct Store API access (front-end Cart, themes, third-party plugins) is intentionally NOT scoped because Store API doesn't conform to UCP and merchants have legitimate non-AI consumers of it.

7. **Pure translators, caller-orchestrated dispatch.** Product and variant translators are pure functions — they transform data shape, never dispatch. The REST controller orchestrates fetching (detect variable products, pre-fetch variations, assemble) before handing data to translators. Keeps translators hermetically testable without stubbing WP's REST pipeline.

8. **Cache invalidation.** llms.txt uses a host-keyed transient (`CACHE_KEY + '_' + md5(HTTP_HOST)`) with event-driven invalidation and a `Vary: Host` response header so CDN/proxy and PHP-layer caches stay in sync across virtual-host boundaries. The UCP manifest is now **generated per-request** (cheap — no HTTP probes, no unbounded queries); `Vary: Host` handles HTTP-layer caching. UCP REST responses are not cached — every dispatch computes fresh because per-request attribution and session IDs vary.

9. **No MCP (Model Context Protocol) support, intentionally.** MCP requires a server surface reachable by external, non-admin clients — neither WordPress core nor WooCommerce scaffold one. AI Storefront targets UCP, which works with the public HTTP/REST surfaces WP/WC already expose. MCP support will be evaluated if WP or WC grow native MCP-server primitives.

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
