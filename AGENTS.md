# WooCommerce AI Syndication — Data Sovereignty for AI Commerce

## What This Is

A WooCommerce plugin that makes merchant product catalogs discoverable by AI agents (ChatGPT, Gemini, Claude, Perplexity, Copilot) while keeping **full data sovereignty** — checkout, customer data, and brand experience stay under merchant control.

**Core principle: AI agents discover and recommend. The merchant owns the transaction.**

## Why It Exists

AI agents are becoming a primary product discovery channel. This plugin gives merchants a way to participate while keeping control:

- **Agnostic** — works with any AI agent that crawls the web, not tied to any platform
- **Data sovereignty** — checkout on the merchant's domain, customer data never leaves the store
- **No authentication required** — discovery is open, using web standards (llms.txt, JSON-LD, robots.txt)
- **No Stripe dependency** — works with any payment gateway
- **Standard attribution** — uses WooCommerce's built-in Order Attribution system

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      AI AGENTS                              │
│  (ChatGPT, Gemini, Perplexity, Claude, Copilot, any bot)   │
└──────────┬──────────────┬──────────────┬────────────────────┘
           │              │              │
     ┌─────▼─────┐ ┌─────▼──────┐ ┌────▼─────────┐
     │  llms.txt  │ │ UCP Manifest│ │  JSON-LD on  │
     │ (Markdown) │ │   (JSON)    │ │ product pages│
     │ /llms.txt  │ │/.well-known │ │              │
     │            │ │   /ucp      │ │              │
     └────────────┘ └─────────────┘ └──────────────┘
           │              │              │
           └──────────────┼──────────────┘
                          │
              ┌───────────▼────────────┐
              │   WooCommerce Core     │
              │  Store API (public)    │
              │  Order Attribution     │
              │  robots.txt            │
              └───────────┬────────────┘
                          │
              ┌───────────▼────────────┐
              │  Customer lands on     │
              │  merchant's store      │
              │  Checkout on their     │
              │  domain, their gateway │
              └────────────────────────┘
```

## Plugin Components

### Discovery Layer

| File | Endpoint | Purpose |
|------|----------|---------|
| `class-wc-ai-syndication-llms-txt.php` | `/llms.txt` | Machine-readable store guide: name, categories, products, attribution instructions |
| `class-wc-ai-syndication-jsonld.php` | Product pages | Enhanced Schema.org Product markup: BuyAction, inventory, attributes, shipping/return info |
| `class-wc-ai-syndication-robots.php` | `/robots.txt` | Whitelists known AI crawlers, allows discovery endpoints, blocks checkout/account pages |
| `class-wc-ai-syndication-ucp.php` | `/.well-known/ucp` | JSON manifest: checkout policy (web_redirect, no in-chat, no delegated), purchase URL templates, Store API reference, attribution params, rate limits |

### Attribution

| File | Purpose |
|------|---------|
| `class-wc-ai-syndication-attribution.php` | Captures AI-referred orders via standard WooCommerce Order Attribution (`utm_medium=ai_agent`). Adds AI Agent column to orders list (HPOS + legacy). SQL aggregation for per-agent revenue stats. |

### Rate Limiting

| File | Purpose |
|------|---------|
| `class-wc-ai-syndication-store-api-rate-limiter.php` | Enables WooCommerce Store API rate limiting for AI bot traffic. Uses `woocommerce_store_api_rate_limit_options` and `woocommerce_store_api_rate_limit_id` filters. Fingerprints by user-agent, matching against the robots.txt crawler list. Regular customer traffic is unaffected. |

### Cache Invalidation

| File | Purpose |
|------|---------|
| `class-wc-ai-syndication-cache-invalidator.php` | Event-driven cache invalidation for llms.txt and UCP manifest. Hooks into product/category CRUD, stock changes, and settings updates. Debounced WP-Cron warm-up. |

### Debug Logging

| File | Purpose |
|------|---------|
| `class-wc-ai-syndication-logger.php` | Off-by-default debug logger. Enable per-request via `add_filter( 'wc_ai_syndication_debug', '__return_true' );`. Instruments: llms.txt and UCP cache hit/miss, rate-limit fingerprint matches, attribution captures. Output goes to `error_log()` (usually `/wp-content/debug.log` when `WP_DEBUG_LOG` is on) prefixed with `[wc-ai-syndication]`. The filter is evaluated once per request and cached, so call sites pay only a static-property check when logging is off. |

### Admin

| File | Purpose |
|------|---------|
| `class-wc-ai-syndication-admin-controller.php` | REST API for admin settings UI: settings CRUD, attribution stats, category/product search, endpoint URLs |
| `class-wc-ai-syndication.php` | Main orchestrator (singleton): dependency loading, rewrite rules, settings with memoization + cache busting, version-based flush |

## Frontend (React Admin UI)

**Entry point:** `client/settings/ai-syndication/index.js`

**Data store:** `client/data/ai-syndication/` — `@wordpress/data` with `createReduxStore`, async thunk resolvers and actions.

**3 tabs:**
- `settings-page.js` — **Overview**: enable/disable, stat cards (products exposed, AI orders, AI revenue), per-agent breakdown, rate limit presets
- `product-selection.js` — **Product Visibility**: mode selector (all/categories/selected), category/product checkbox lists with search, selection tokens, bulk actions
- `endpoint-info.js` — **Discovery**: table of discovery endpoint URLs (llms.txt, UCP manifest, Store API) and AI crawler allowlist

**Shared modules:**
- `tokens.js` — design tokens (semantic color names mapped to the WordPress admin palette). See [Styling](#styling) for the rule.

**Build integration:**
- `webpack.config.js` swaps WP's default dependency extractor for `@woocommerce/dependency-extraction-webpack-plugin`, which handles both `@wordpress/*` and `@woocommerce/*` imports as runtime externals. This keeps Woo components out of the bundle (the merchant's WooCommerce install supplies them) and auto-populates `wc-components` into the generated `.asset.php` dependency list.

## Styling

### Component library precedence

**`@wordpress/components` is the default. `@woocommerce/components` is adopted when *either*:**
- **(a)** Woo has already composed the equivalent from WP primitives — rebuilding with the same primitives would duplicate Woo's work and cause visual drift vs. native wc-admin screens.
- **(b)** Woo fills a real capability gap WP doesn't cover.

In both cases, the Woo component wins. Don't rebuild something Woo already ships just to keep the dep graph pure.

**Never bundle Woo or WP components.** Both are runtime externals via `@woocommerce/dependency-extraction-webpack-plugin` (configured in `webpack.config.js`) — the merchant's WooCommerce install supplies them through `window.wc.*` / `window.wp.*`. The build's generated `.asset.php` automatically lists `wc-components` as a PHP script dependency when any Woo import is present.

**Current status: Woo component adoption deferred.**

We evaluated `@woocommerce/components` (`SummaryNumber`, `Table`, `Pill`) during the Overview tab redesign and reverted. The JS externalization worked correctly — Woo components rendered their DOM and behavior — but the **stylesheet was not loaded on our custom admin page**. Woo's CSS is auto-enqueued on wc-admin native screens; it is *not* auto-enqueued on WooCommerce submenu pages like ours. The result was visually unstyled (broken) cards.

Before re-attempting Woo component adoption, the integration must solve:

1. **Stylesheet enqueue:** manually register `wc-components` (and related `wc-admin-layout`, `wc-experimental`) style handles via `wp_enqueue_style` on the plugin's admin page hook — **with fallback** for WC version variance, since handle names have rotated between WC major versions.
2. **Version pinning:** verify that the Woo components used are stable across the plugin's declared minimum WooCommerce version (currently 9.9+) through the latest release.
3. **Graceful degradation:** decide what the page renders if `window.wc.components` is undefined (older WC, wc-admin disabled, etc.). Current hand-rolled components have no such dependency.

The webpack wiring for Woo externalization is **kept** (`@woocommerce/dependency-extraction-webpack-plugin` remains a devDependency and `webpack.config.js` still configures it) so the door stays open. The wiring is cheap, and re-adopting Woo components later is a one-line package install + imports, not a build-system rewrite.

When the three blockers above are resolved, candidate components to adopt first: `SummaryNumber` (Overview stats), `Pill` (selection count indicators), `Table` (Discovery endpoints + Revenue by Agent).

### Inline styles + design tokens

The admin UI uses React components with inline `style={ ... }` props (no stylesheet). **Colors MUST come from `client/settings/ai-syndication/tokens.js`.** Raw hex literals in JSX are a lint-review red flag.

```js
import { colors } from './tokens';

// Standalone — reference the token directly.
<p style={ { color: colors.textSecondary } }>…</p>

// Embedded in a multi-value string — use a template literal.
<div style={ { border: `1px solid ${ colors.borderSubtle }` } } />
```

**Adding a new color:** define a semantic token in `tokens.js` first (e.g. `warningBg`, not `yellow100`). Map it to the nearest value in the WordPress admin palette (`@wordpress/base-styles/_colors.scss`) — choosing an existing value is nearly always preferable to inventing a new one.

**Why:** a future migration to CSS custom properties (e.g. `var( --wp-components-color-gray-700, #50575e )`) — or a palette shift in WP core — becomes a single-file change instead of a hunt-and-replace across every component.

### Layout primitives

Use `Flex` / `FlexItem` / `FlexBlock` from `@wordpress/components` instead of hand-rolling `style={{ display: 'flex', gap: ... }}`. The primitives inherit WP's spacing scale and responsive behavior for free. Hand-rolled flex is acceptable only for single-child containers where the "layout" is really just alignment or max-width.

## File Map

```
woo-ucp-syndicate-ai/
├── woocommerce-ai-syndication.php           # Bootstrap, HPOS declaration, activation/deactivation
├── README.md                                # GitHub-facing project overview
├── readme.txt                               # WP.org-format plugin readme
├── AGENTS.md                                # This file — architecture reference
├── package.json                             # Node dependencies
├── composer.json                            # PHP deps (PHPUnit, Brain Monkey, PHPStan, PHPCS)
├── webpack.config.js                        # Build config (Woo dependency extraction)
├── phpunit.xml.dist                         # PHPUnit config
├── phpcs.xml.dist                           # PHPCS config (WordPress-Extra standard)
├── phpstan.neon.dist                        # PHPStan config (level 5)
├── phpstan-bootstrap.php                    # Plugin constants for PHPStan
├── uninstall.php                            # Removes options/transients on plugin delete
├── .github/workflows/
│   ├── ci.yml                               # PHPUnit (8.1/8.2/8.3), PHPCS, PHPStan, JS tests, JS lint
│   └── release.yml                          # Build distribution zip on v* tags
├── bin/
│   └── make-pot.sh                          # Regenerate translation template
├── languages/
│   └── woocommerce-ai-syndication.pot       # Gettext template (committed; auto-regen in release)
│
├── includes/
│   ├── class-wc-ai-syndication.php          # Main orchestrator
│   ├── admin/
│   │   └── class-wc-ai-syndication-admin-controller.php
│   └── ai-syndication/
│       ├── class-wc-ai-syndication-llms-txt.php
│       ├── class-wc-ai-syndication-jsonld.php
│       ├── class-wc-ai-syndication-robots.php
│       ├── class-wc-ai-syndication-ucp.php
│       ├── class-wc-ai-syndication-store-api-rate-limiter.php
│       ├── class-wc-ai-syndication-attribution.php
│       ├── class-wc-ai-syndication-cache-invalidator.php
│       └── class-wc-ai-syndication-logger.php
│
├── client/
│   ├── data/ai-syndication/
│   │   ├── index.js              # Store registration
│   │   ├── constants.js          # STORE_NAME, ADMIN_NAMESPACE
│   │   ├── action-types.js       # Action type constants
│   │   ├── actions.js            # Thunk actions (save, fetchStats, fetchEndpoints)
│   │   ├── reducer.js            # State: settings, stats, endpoints, saving
│   │   ├── selectors.js          # State queries
│   │   ├── resolvers.js          # Async thunk resolvers
│   │   └── __tests__/            # Jest tests (actions, reducer, selectors)
│   └── settings/ai-syndication/
│       ├── index.js              # Entry point
│       ├── settings-page.js      # Overview tab (pre/post enable views)
│       ├── product-selection.js  # Product visibility controls
│       ├── endpoint-info.js      # Discovery endpoint URLs + crawler allowlist
│       └── tokens.js             # Design tokens (semantic color names) — see Styling
│
├── tests/
│   └── php/
│       ├── bootstrap.php
│       ├── stubs.php                        # WC_Product, WC_Order, WP_REST_* stubs
│       ├── stubs/class-wc-ai-syndication-stub.php
│       └── unit/
│           ├── AttributionTest.php
│           ├── CacheInvalidatorTest.php
│           ├── JsonLdTest.php
│           ├── LlmsTxtTest.php
│           ├── LoggerTest.php
│           ├── RobotsTest.php
│           ├── StoreApiRateLimiterTest.php
│           └── UcpTest.php
│
└── build/                                   # Compiled JS bundle (committed)
```

## Key Design Decisions

1. **No authentication.** AI agents discover the store via open web standards. No API keys, no OAuth, no bot registration. The WooCommerce Store API (public, unauthenticated) handles product search and cart operations.

2. **Web redirect only.** The UCP manifest declares `"in_chat": false, "delegated": false`. Checkout happens on the merchant's domain. The AI agent is a referrer, not a storefront.

3. **Data sovereignty.** Checkout happens on the merchant's domain. No delegated payments, no platform lock-in. The merchant owns the checkout experience and the customer relationship.

4. **Standard WooCommerce attribution.** Uses the built-in Order Attribution system (`utm_source`/`utm_medium`). Only `ai_session_id` is custom.

5. **Store API rate limiting.** Uses WooCommerce's built-in `woocommerce_store_api_rate_limit_options` and `woocommerce_store_api_rate_limit_id` filters. AI bots are fingerprinted by user-agent; regular customer traffic is unaffected.

6. **Product selection enforced at every layer.** llms.txt, JSON-LD, and robots.txt all respect the `product_selection_mode` setting. A product excluded from syndication won't appear in any discovery channel.

7. **Cache invalidation.** llms.txt and UCP manifest use transient caching with event-driven invalidation on product/category/settings changes. Version-based cache bust on plugin updates.

## Settings

All runtime settings are stored in a single serialized option to keep reads cheap (`autoload=true` + static memoization in `WC_AI_Syndication::get_settings()`).

| Option Key | Type | Description |
|------------|------|-------------|
| `wc_ai_syndication_settings` | array | `enabled`, `product_selection_mode`, `selected_categories`, `selected_products`, `rate_limit_rpm`, `allowed_crawlers` |
| `wc_ai_syndication_version` | string | Plugin version (triggers cache bust + rewrite flush on update) |

### `allowed_crawlers`

Subset of `WC_AI_Syndication_Robots::AI_CRAWLERS`. Sanitized on write by `WC_AI_Syndication_Robots::sanitize_allowed_crawlers()`, which intersects the incoming array with the canonical list — stale IDs left over from plugin upgrades that rotated the roster (e.g. the v1.1.0 Bytespider → OAI-SearchBot swap) are stripped on the next save. If absent, defaults to the full canonical list.

## Admin REST API

All endpoints require `manage_woocommerce` capability.

| Method | Path | Description |
|--------|------|-------------|
| GET/POST | `/wc/v3/ai-syndication/admin/settings` | Read/write settings |
| GET | `/wc/v3/ai-syndication/admin/stats` | Attribution stats by period |
| GET | `/wc/v3/ai-syndication/admin/search/categories` | Category picker data |
| GET | `/wc/v3/ai-syndication/admin/search/products` | Product picker data |
| GET | `/wc/v3/ai-syndication/admin/endpoints` | Discovery endpoint URLs |

## Order Meta Keys

| Meta Key | Source | Description |
|----------|--------|-------------|
| `_wc_order_attribution_utm_source` | WooCommerce core | Agent identifier (chatgpt, gemini, etc.) |
| `_wc_order_attribution_utm_medium` | WooCommerce core | `ai_agent` for AI-referred orders |
| `_wc_ai_syndication_session_id` | This plugin | AI conversation/session ID |
| `_wc_ai_syndication_agent` | This plugin | Denormalized agent name for fast queries |

## Development

```bash
npm install && npm run build    # Build frontend
composer install                # Install PHP dev dependencies
vendor/bin/phpunit              # Run PHP tests
npm run test:js                 # Run JS tests
npm run lint:js                 # Lint JS
vendor/bin/phpcs                # Lint PHP against WordPress-Extra + plugin rules
vendor/bin/phpcbf               # Auto-fix PHPCS violations where possible
vendor/bin/phpstan analyse      # PHP static analysis (level 5)
./bin/make-pot.sh               # Regenerate languages/*.pot from source strings
```

### PHP quality tooling

PHPCS is configured from `phpcs.xml.dist` with the `WordPress-Extra`
standard plus plugin-specific prefix declarations. PHPStan is at level 5
with a minimal WC-function ignore list; real bugs fail the build. Both
run in CI on every push to `main` and on pull requests.

When a WC function or class trips PHPStan because it's not in the WP
stubs, add a narrow `ignoreErrors` entry to `phpstan.neon.dist` (matched
by name pattern — never blanket-suppress). When a `$wpdb` query uses
`{$table}` interpolation for a hard-coded table name, wrap it in
`phpcs:disable` / `phpcs:enable` comments scoped to the specific query,
not the whole method.

Requires WooCommerce 9.9+, WordPress 6.7+, PHP 8.0+.
