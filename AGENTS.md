# UCP Syndicate — WooCommerce AI Syndication Plugin

## What This Is

A standalone WooCommerce plugin that lets merchants expose their product catalog to AI shopping agents (ChatGPT, Gemini, Perplexity, Claude, etc.) while keeping **full control** over checkout, data, and brand experience. This is a **Merchant-Led AI Syndicate** — not a marketplace integration, not a delegated payment flow.

**Core principle: AI agents discover and recommend products. Checkout happens exclusively on the merchant's WooCommerce store.**

## Why It Exists

AI agents are becoming a primary product discovery channel. Merchants need a way to participate without surrendering control to platform-specific protocols like Stripe's Agentic Commerce Protocol (ACP). This plugin provides:

- An **agnostic** system — works with any AI agent, not tied to Stripe or any single provider
- **Store-only checkout** — no in-chat payments, no delegated checkout, web redirect only
- **Standard WooCommerce attribution** — uses the built-in Order Attribution system, not custom tracking
- **Merchant control** — choose which products to expose, which bots get access, rate limits

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                    AI AGENTS                            │
│  (ChatGPT, Gemini, Perplexity, Claude, custom bots)    │
└──────────┬──────────────┬───────────────┬───────────────┘
           │              │               │
     ┌─────▼─────┐ ┌─────▼──────┐ ┌──────▼───────┐
     │  llms.txt  │ │ UCP Manifest│ │ Catalog API  │
     │ (Markdown) │ │   (JSON)    │ │   (REST)     │
     │ /llms.txt  │ │/.well-known │ │/wp-json/wc/  │
     │            │ │   /ucp      │ │v3/ai-syndic. │
     └────────────┘ └─────────────┘ └──────┬───────┘
                                           │
                         ┌─────────────────▼──────────────┐
                         │     Bot Manager + Rate Limiter  │
                         │  (API key auth, permissions)    │
                         └─────────────────┬──────────────┘
                                           │
                    ┌──────────────────────▼────────────────┐
                    │         WooCommerce Core               │
                    │  Products · Store API · Order Attrib.  │
                    └──────────────────────┬────────────────┘
                                           │
                              ┌─────────────▼──────────────┐
                              │  Customer lands on store    │
                              │  Cart pre-populated         │
                              │  Attribution captured       │
                              │  Checkout on merchant site  │
                              └────────────────────────────┘
```

## The Three Layers

### 1. Discovery Layer (how AI finds the store)

| File | Endpoint | Format | Purpose |
|------|----------|--------|---------|
| `class-wc-ai-syndication-llms-txt.php` | `/llms.txt` | Markdown | Machine-readable store map: name, categories, featured products, API endpoints, attribution instructions |
| `class-wc-ai-syndication-jsonld.php` | Product pages | JSON-LD | Enhanced Schema.org markup: `BuyAction` with attribution URL template, inventory levels, product attributes as `PropertyValue`, shipping/return info |
| `class-wc-ai-syndication-robots.php` | `/robots.txt` | Text | Whitelists 12 known AI crawlers (GPTBot, Gemini, ClaudeBot, PerplexityBot, etc.), allows `/llms.txt`, `/.well-known/ucp`, `/wp-json/...`, blocks `/cart/`, `/checkout/`, `/my-account/` |

### 2. Universal Commerce Protocol (how AI interacts)

| File | Endpoint | Purpose |
|------|----------|---------|
| `class-wc-ai-syndication-ucp.php` | `/.well-known/ucp` | JSON manifest declaring: checkout method (`web_redirect` only, `in_chat: false`, `delegated: false`), capabilities (product search, cart sync, price verification, inventory check), API discovery with auth requirements, attribution URL template, rate limits |

### 3. Commerce API (how AI queries products and prepares carts)

| File | Endpoints | Purpose |
|------|-----------|---------|
| `class-wc-ai-syndication-catalog-api.php` | `GET /products`, `GET /products/{id}`, `GET /categories`, `GET /store`, `POST /cart/prepare` | Authenticated REST API for product search/browse, single product detail with variations, category listing, store info, and cart pre-population returning a checkout redirect URL |

## Supporting Systems

### Bot Manager (`class-wc-ai-syndication-bot-manager.php`)
- Stores bots in `wp_options` as `wc_ai_syndication_bots`
- Each bot: UUID id, name, `wp_hash()`-ed API key, key prefix for display, permissions map, status, access log
- API keys prefixed `wc_ai_` + 32 random chars
- Auth via `X-AI-Agent-Key` header (fallback: `?ai_agent_key=` query param)
- Permissions: `read_products`, `read_categories`, `prepare_cart`, `check_inventory`

### Rate Limiter (`class-wc-ai-syndication-rate-limiter.php`)
- Per-bot, per-minute and per-hour limits via WordPress transients
- Configurable RPM/RPH in settings
- Returns 429 with `retry_after` seconds

### Attribution (`class-wc-ai-syndication-attribution.php`)
- **Uses WooCommerce's built-in Order Attribution** — not Additional Fields API
- AI traffic identified by `utm_medium=ai_agent` (standard WooCommerce attribution param)
- `utm_source` = agent identifier (chatgpt, gemini, etc.)
- Custom `ai_session_id` stored as order meta `_wc_ai_syndication_session_id`
- Agent name stored as `_wc_ai_syndication_agent`
- Hooks into both classic checkout (`woocommerce_checkout_order_created`) and Blocks/Store API (`woocommerce_store_api_checkout_order_processed`)
- Adds "AI Agent" column to WooCommerce orders list (HPOS compatible)
- `get_stats()` method aggregates orders by agent with revenue totals

### Admin Controller (`class-wc-ai-syndication-admin-controller.php`)
- REST namespace: `wc/v3/ai-syndication/admin`
- CRUD for settings, bots, stats, product/category search
- Permission: `manage_woocommerce`

### Main Orchestrator (`class-wc-ai-syndication.php`)
- Singleton pattern
- Settings stored in `wp_options` as `wc_ai_syndication_settings`
- Default settings: `enabled: no`, `product_selection_mode: all`, RPM: 60, RPH: 1000
- Product selection modes: `all`, `categories` (by term IDs), `selected` (by product IDs)
- Registers WooCommerce admin submenu page: WooCommerce > AI Syndication

## Frontend (React Admin UI)

**Location:** `client/`

**Entry point:** `client/settings/ai-syndication/index.js` → renders into `#wc-ai-syndication-settings`

**Data store:** `client/data/ai-syndication/` — uses `@wordpress/data` with `createReduxStore`
- Store name: `wc/ai-syndication`
- Async actions use thunks (not generators like the Stripe plugin's older pattern)
- Resolvers use generators with `yield apiFetch()`

**UI components** (`client/settings/ai-syndication/`):
- `settings-page.js` — Main page with 5 TabPanel tabs (General, Bot Permissions, Product Selection, Attribution, Endpoints)
- `bot-manager.js` — Bot CRUD: create with modal, revoke/reactivate, regenerate key, delete with confirmation. Shows one-time API key in warning Notice
- `product-selection.js` — SelectControl for mode, CheckboxControl lists for categories/products, SearchControl for product search
- `attribution-stats.js` — Period selector, stat cards (orders/revenue), per-agent breakdown table, attribution explainer
- `endpoint-info.js` — Table of all endpoints with URLs, integration guide for AI developers

**Build:** Webpack config at `webpack.config.js`, entry point `ai-syndication-settings`. Output to `build/`.

## File Map

```
ucp-syndicate/
├── woocommerce-ai-syndication.php          # Plugin bootstrap, activation/deactivation hooks
├── package.json                             # Node dependencies (webpack, @wordpress/*)
├── webpack.config.js                        # Build config, single entry point
├── assets/css/                              # (placeholder for future styles)
│
├── includes/
│   ├── class-wc-ai-syndication.php          # Main orchestrator (Singleton)
│   ├── admin/
│   │   └── class-wc-ai-syndication-admin-controller.php  # Admin REST API
│   └── ai-syndication/
│       ├── class-wc-ai-syndication-llms-txt.php       # /llms.txt endpoint
│       ├── class-wc-ai-syndication-jsonld.php         # Enhanced JSON-LD output
│       ├── class-wc-ai-syndication-robots.php         # robots.txt AI crawler rules
│       ├── class-wc-ai-syndication-ucp.php            # /.well-known/ucp manifest
│       ├── class-wc-ai-syndication-catalog-api.php    # Public product catalog REST API
│       ├── class-wc-ai-syndication-bot-manager.php    # Bot registration & auth
│       ├── class-wc-ai-syndication-rate-limiter.php   # Per-agent rate limiting
│       └── class-wc-ai-syndication-attribution.php    # WooCommerce Order Attribution integration
│
└── client/
    ├── data/ai-syndication/
    │   ├── index.js            # Store registration (createReduxStore)
    │   ├── constants.js        # STORE_NAME, ADMIN_NAMESPACE
    │   ├── action-types.js     # Action type constants
    │   ├── actions.js          # Sync + async (thunk) actions
    │   ├── reducer.js          # State shape and mutations
    │   ├── selectors.js        # State queries
    │   └── resolvers.js        # Auto-fetch on first select (generators)
    │
    └── settings/ai-syndication/
        ├── index.js               # Entry point, mounts React app
        ├── settings-page.js       # Main settings page with TabPanel
        ├── bot-manager.js         # Bot CRUD UI
        ├── product-selection.js   # Product/category selection UI
        ├── attribution-stats.js   # Attribution analytics dashboard
        └── endpoint-info.js       # Endpoint discovery + integration guide
```

## Key Design Decisions

1. **No Stripe dependency.** Zero imports from woocommerce-gateway-stripe. Uses only WooCommerce core APIs (wc_get_products, Store API, Order Attribution).

2. **Attribution via standard WooCommerce Order Attribution**, not Additional Fields API. The `utm_source`/`utm_medium` params flow through WooCommerce's existing `_wc_order_attribution_*` meta keys. We only add `ai_session_id` as custom meta.

3. **Web redirect only.** The UCP manifest explicitly declares `"in_chat": false, "delegated": false`. This is non-negotiable — checkout sovereignty stays with the merchant.

4. **API key auth, not OAuth.** Simpler for AI agent integrations. Keys are hashed with `wp_hash()` before storage, only the prefix is stored for display. Plaintext shown once on creation.

5. **Rewrite rules for /llms.txt and /.well-known/ucp.** These use WordPress rewrite rules (not REST API) so they serve at the root domain without the `/wp-json/` prefix.

6. **Product selection is enforced at every layer.** The llms.txt, JSON-LD, robots.txt, UCP manifest, and Catalog API all respect the same `product_selection_mode` setting. A product excluded from syndication won't appear anywhere.

## What Needs Work Next

### High Priority
- **Cart preparation flow** — The `/cart/prepare` endpoint validates items and returns URLs, but doesn't yet use WooCommerce's Store API `POST /wc/store/v1/cart/add-item` server-side. Currently returns instructions for client-side Store API usage. Could be enhanced to create a server-side cart session with a shareable token.
- **OAuth 2.0 handoff** — The Gemini plan mentions secure identity pre-fill via OAuth. Not yet implemented. Would allow AI agents to pass authenticated customer identity for pre-filled checkout.
- **Webhook/callback for price verification** — AI agents need real-time price verification before showing prices to users. The `/products/{id}` endpoint serves this, but a dedicated lightweight `/verify-price` endpoint would reduce payload.

### Medium Priority
- **PHPUnit tests** — No test coverage yet. Need tests for: bot authentication, rate limiting, product selection filtering, attribution capture, API response formats.
- **Jest tests** — No frontend test coverage. Need tests for: Redux store actions/reducers, component rendering.
- **Build pipeline** — `npm run build` not yet configured. Need to run `npm install && npm run build:webpack` and verify the admin UI loads.
- **Rewrite rule flush** — After activation, the user may need to visit Settings > Permalinks to flush rewrites. Consider adding an admin notice.
- **HPOS compatibility audit** — Attribution column hooks use `manage_woocommerce_page_wc-orders_columns` which is HPOS-specific. Verify fallback for legacy `shop_order` post type.

### Lower Priority
- **Internationalization** — All strings use `__()` with `woocommerce-ai-syndication` text domain but no `.pot` file generated yet.
- **Multisite support** — Not tested on WordPress multisite.
- **Performance** — The `get_stats()` method queries all orders with meta. Should use WooCommerce Analytics tables or custom summary table for scale.
- **Admin UI styling** — Functional but uses inline styles. Could benefit from a proper CSS file.
- **Permissions granularity** — Bot permissions exist but aren't enforced on read endpoints (only on `prepare_cart`). Decide if `read_products` should be checkable.

## Development Commands

```bash
cd /home/user/ucp-syndicate
npm install                   # Install dependencies
npm run build                 # Build frontend assets (needs webpack config entry point setup)
```

This plugin requires WooCommerce 9.9+, WordPress 6.7+, PHP 8.0+.

## Settings Storage

| Option Key | Type | Description |
|------------|------|-------------|
| `wc_ai_syndication_settings` | array | Main settings (enabled, product mode, rate limits, crawler list) |
| `wc_ai_syndication_bots` | array | Registered bots (keyed by UUID, contains hashed keys, permissions, access logs) |
| `wc_ai_syndication_version` | string | Plugin version (set on activation) |

## Order Meta Keys

| Meta Key | Source | Description |
|----------|--------|-------------|
| `_wc_order_attribution_utm_source` | WooCommerce core | Agent identifier (chatgpt, gemini, etc.) |
| `_wc_order_attribution_utm_medium` | WooCommerce core | `ai_agent` for AI-referred orders |
| `_wc_ai_syndication_session_id` | This plugin | AI conversation/session ID |
| `_wc_ai_syndication_agent` | This plugin | Denormalized agent name for fast queries |

## REST API Summary

### Public (requires `X-AI-Agent-Key`):
| Method | Path | Description |
|--------|------|-------------|
| GET | `/wc/v3/ai-syndication/products` | Search/browse products |
| GET | `/wc/v3/ai-syndication/products/{id}` | Single product detail |
| GET | `/wc/v3/ai-syndication/categories` | Category listing |
| GET | `/wc/v3/ai-syndication/store` | Store info + checkout policy |
| POST | `/wc/v3/ai-syndication/cart/prepare` | Validate items, return checkout URL |

### Admin (requires `manage_woocommerce`):
| Method | Path | Description |
|--------|------|-------------|
| GET/POST | `/wc/v3/ai-syndication/admin/settings` | Read/write settings |
| GET/POST | `/wc/v3/ai-syndication/admin/bots` | List/create bots |
| PUT/DELETE | `/wc/v3/ai-syndication/admin/bots/{id}` | Update/delete bot |
| POST | `/wc/v3/ai-syndication/admin/bots/{id}/regenerate-key` | New API key |
| GET | `/wc/v3/ai-syndication/admin/stats` | Attribution analytics |
| GET | `/wc/v3/ai-syndication/admin/search/categories` | Category picker data |
| GET | `/wc/v3/ai-syndication/admin/search/products` | Product picker data |
| GET | `/wc/v3/ai-syndication/admin/endpoints` | Endpoint URLs |
