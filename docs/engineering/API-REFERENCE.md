# API Reference

Endpoint-level reference for the two REST surfaces this plugin exposes:

- **UCP REST adapter** — `/wp-json/wc/ucp/v1/*`. Public, called by AI agents. Implements the Universal Commerce Protocol's catalog and checkout-session capabilities.
- **Admin REST API** — `/wp-json/wc/v3/ai-storefront/admin/*`. Authenticated, called by the React admin UI.

Plus three discovery surfaces that aren't REST per se but are part of the public contract:

- `/llms.txt` — Markdown store guide
- `/.well-known/ucp` — JSON manifest
- `/robots.txt` — Crawler allow-list (WordPress-served, AI Storefront amends)

## Conventions

- All UCP endpoints emit responses inside a `ucp` envelope with `version`, `capabilities`, and `payment_handlers`. The envelope is built by `WC_AI_Storefront_UCP_Envelope`.
- Error envelopes use `error: { code, message }` plus an HTTP status code that matches the failure class.
- Currency amounts are integers in **minor units** (cents for USD, pence for GBP, etc.). No floats. No hardcoded `* 100` — read currency precision from the response context.
- Date-times are ISO 8601 UTC.
- The `UCP-Agent` request header is parsed for two formats: profile-URL (RFC 8941 Dictionary) and Product/Version (RFC 7231 §5.5.3). Either form works; absence is also valid (treated as anonymous).

## Authentication

| Surface | Auth model |
|---------|------------|
| UCP REST (`/wc/ucp/v1/*`) | None. Public. Permission gate is `WC_AI_Storefront_UCP_REST_Controller::check_agent_access()` which inspects merchant settings (`allowed_crawlers`, `allow_unknown_ucp_agents`) against the `UCP-Agent` header. |
| Admin REST (`/wc/v3/ai-storefront/admin/*`) | `manage_woocommerce` capability via WordPress's standard cookie/nonce or application-password authentication. |
| `/llms.txt`, `/.well-known/ucp`, `/robots.txt` | None. Public. |

The UCP gate is **secure-by-default**: an unrecognized `UCP-Agent` host returns 403 unless the merchant has opted in via `allow_unknown_ucp_agents=yes`. Agents whose host resolves to a known brand (e.g. `chatgpt.com`) are allowed only if at least one of their mapped crawler IDs is in the merchant's `allowed_crawlers` list.

When syndication is paused (`enabled=no`), every UCP commerce route returns **503** with `error.code=ucp_disabled`. Agents read 503 as "transient pause, retry later" — preserving the right semantic vs. 403 ("permanent deny").

---

## UCP REST adapter

Base URL: `https://your-store.com/wp-json/wc/ucp/v1`

Module: [`includes/ai-storefront/ucp-rest/`](../../includes/ai-storefront/ucp-rest/)

### `POST /catalog/search`

Search the merchant's syndicated catalog. Translates UCP search params into a WooCommerce Store API call.

**Permission:** `check_agent_access`.

**Request body** (JSON object):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `query` | string | no | Free-text search term. |
| `filters` | object | no | UCP filter object. Honored fields: `categories`, `tags`, `brands`, `price` (`{min, max}`). |
| `pagination` | object | no | `{ "page": int, "per_page": int }`. Default 1 / 20. Max `per_page` is 100. |
| `sort` | object | no | `{ "field": "relevance"|"price"|"date", "order": "asc"|"desc" }`. |
| `context` | object | no | UCP context block (currency, locale). Logged but not currently honored. |
| `signals` | object | no | Platform-observed environment data. Logged but not honored — UCP spec mandates these MUST NOT be buyer-asserted; until we have a trust model we ignore values. |

**Response:** `200 OK` with a UCP catalog envelope:

```json
{
  "ucp": {
    "version": "2026-04-08",
    "capabilities": ["dev.ucp.shopping.catalog.search"],
    "payment_handlers": {}
  },
  "products": [
    {
      "id": "wc_42",
      "title": "Acme Running Shoes",
      "description": "...",
      "url": "https://your-store.com/product/acme-running-shoes/?utm_source=chatgpt&utm_medium=ai_agent&ai_session_id=...",
      "variants": [
        {
          "id": "wc_42_default",
          "title": "Default",
          "price": { "amount_minor": 12999, "currency": "USD" },
          "availability": "in_stock"
        }
      ]
    }
  ],
  "pagination": { "page": 1, "per_page": 20, "total": 142 }
}
```

**Errors:**
- `503` `ucp_disabled` — syndication is paused.
- `400` `ucp_invalid_request` — body fails JSON Schema validation.
- `429` — Store API rate limit exceeded for this user-agent.

**Curl example:**

```bash
curl -X POST https://your-store.com/wp-json/wc/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' \
  -H 'UCP-Agent: profile=:https://chatgpt.com:' \
  -d '{
    "query": "running shoes",
    "filters": { "price": { "max": 15000 } },
    "pagination": { "per_page": 10 }
  }'
```

### `POST /catalog/lookup`

Look up specific products by ID.

**Permission:** `check_agent_access`.

**Request body:**

```json
{
  "products": ["wc_42", "wc_43", "wc_99"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `products` | string[] | yes | Array of UCP product IDs (`wc_<post_id>` for simple, `wc_<post_id>_<variation_id>` for variations). |

**Response:** Same envelope as `/catalog/search` but with `products` matching the requested IDs in order. Missing or excluded products are omitted (no error per ID — agents are expected to diff against their request).

**Errors:**
- `503` `ucp_disabled`
- `400` `ucp_invalid_request` — `products` missing, empty, or > 100 items.

### `POST /checkout-sessions`

Validate a cart and return a redirect URL to WooCommerce's native Shareable Checkout. **Stateless** — never persists anything.

**Permission:** `check_agent_access`.

**Request body:**

```json
{
  "items": [
    { "variant_id": "wc_42_default", "quantity": 1 },
    { "variant_id": "wc_56_2", "quantity": 2 }
  ],
  "shipping_address": { "country": "US", "postal_code": "94110" },
  "context": { "currency": "USD" }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `items` | array | yes | Each item: `variant_id` (string, UCP variant ID format), `quantity` (int, >=1). Max 100 items. |
| `shipping_address` | object | no | UCP address block. Used for shipping/tax preview. |
| `context` | object | no | UCP context block (currency, locale). |

**Response — happy path** (`200 OK`):

```json
{
  "ucp": { "version": "2026-04-08", "capabilities": ["dev.ucp.shopping.checkout"], "payment_handlers": {} },
  "id": "chk_a1b2c3d4e5f6g7h8",
  "status": "requires_escalation",
  "continue_url": "https://your-store.com/checkout-link/?products=42:1,56:2&utm_source=chatgpt&utm_medium=ai_agent&ai_session_id=chk_a1b2c3d4e5f6g7h8",
  "totals": {
    "subtotal": { "amount_minor": 38997, "currency": "USD" },
    "shipping": { "amount_minor": 0, "currency": "USD" },
    "tax": { "amount_minor": 0, "currency": "USD" },
    "total": { "amount_minor": 38997, "currency": "USD" }
  },
  "messages": []
}
```

The `continue_url` is the agent's signal to render a "Buy" button. See [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) for the full decision tree.

**Response — incomplete** (`200 OK`, but no `continue_url`):

```json
{
  "ucp": { ... },
  "id": "chk_...",
  "status": "incomplete",
  "messages": [
    { "code": "out_of_stock", "message": "Acme Running Shoes (Size 11) is out of stock." }
  ]
}
```

**Errors:**
- `503` `ucp_disabled`
- `400` `ucp_invalid_request` — missing/empty `items`, malformed variant IDs.

**Note on session IDs:** `chk_<16 hex chars>` is a correlation token for logging and attribution. There is no GET/PUT/PATCH/DELETE endpoint that operates on it — see the next section.

### `GET|PUT|PATCH|DELETE /checkout-sessions/{id}`

Returns a structured `405 Method Not Allowed` for any verb other than POST on a session URL. The response body explains the stateless model:

```json
{
  "ucp": { ... },
  "error": {
    "code": "unsupported_operation",
    "message": "This endpoint is stateless. POST a fresh /checkout-sessions to recompute the cart."
  },
  "id": "chk_..."
}
```

**Permission:** `check_agent_access`.

**Why this exists:** UCP-aware agents that come from a stateful-session mental model try PATCH (add items), PUT (replace cart), GET (look up status), DELETE (cancel). Without these route stubs WP REST would return generic `rest_no_route` 404s, which agents misinterpret as "the session was lost" and may retry destructively. The structured 405 gives them an actionable answer: "no state under any verb; POST again."

### `GET /extension/schema`

Returns the JSON Schema for our `com.woocommerce.ai_storefront` extension capability. Public — schema metadata is not commerce data, and gating it would break the manifest's `schema` URL discoverability.

**Permission:** `__return_true`.

**Response:** `200 OK`, `application/json`. Per-site so the schema matches the running plugin version exactly.

---

## Admin REST API

Base URL: `https://your-store.com/wp-json/wc/v3/ai-storefront/admin`

Module: [`includes/admin/class-wc-ai-storefront-admin-controller.php`](../../includes/admin/class-wc-ai-storefront-admin-controller.php)

All endpoints require the `manage_woocommerce` capability. Authentication is via WordPress's standard cookie/nonce (when called from the admin UI) or application-password (when called externally).

### `GET /settings`

Read current settings.

**Response:**

```json
{
  "enabled": "yes",
  "product_selection_mode": "by_taxonomy",
  "selected_categories": [12, 34],
  "selected_tags": [],
  "selected_brands": [],
  "selected_products": [],
  "rate_limit_rpm": 25,
  "allowed_crawlers": ["GPTBot", "ChatGPT-User", "ClaudeBot", "Claude-User", "..."],
  "allow_unknown_ucp_agents": "no",
  "return_policy": { "mode": "returns_accepted", "days": 30, "fees": "free", "methods": ["mail"] }
}
```

### `POST /settings`

Update settings. Partial updates allowed — only fields present in the body are touched.

**Body:** any subset of:

| Field | Type | Validation |
|-------|------|------------|
| `enabled` | string | enum: `yes`, `no` |
| `product_selection_mode` | string | enum: `all`, `by_taxonomy`, `selected` (legacy: `categories`, `tags`, `brands` silently migrated to `by_taxonomy`) |
| `selected_categories` | int[] | term IDs |
| `selected_tags` | int[] | term IDs |
| `selected_brands` | int[] | term IDs |
| `selected_products` | int[] | post IDs |
| `rate_limit_rpm` | int | 1–1000 |
| `allowed_crawlers` | string[] | intersected with `WC_AI_Storefront_Robots::AI_CRAWLERS` on save; unknown IDs stripped silently |
| `allow_unknown_ucp_agents` | string | enum: `yes`, `no` |
| `return_policy` | object | sub-fields validated by `WC_AI_Storefront_Return_Policy::sanitize()` |

**Response:** the updated settings object (same shape as `GET /settings`).

**Side effects:**
- If `enabled` flipped, schedules a rewrite-rule flush.
- On enable, eagerly warms the `/llms.txt` and UCP manifest caches.

### `GET /stats`

AI-attributed order aggregates by period.

**Query params:**

| Param | Type | Default | Enum |
|-------|------|---------|------|
| `period` | string | `month` | `day`, `week`, `month`, `year` |

**Response:**

```json
{
  "ai_orders": 42,
  "ai_revenue": { "amount_minor": 5400000, "currency": "USD" },
  "by_agent": [
    { "agent": "chatgpt", "orders": 24, "revenue": { "amount_minor": 3100000 } },
    { "agent": "perplexity", "orders": 12, "revenue": { "amount_minor": 1500000 } },
    { "agent": "gemini", "orders": 6, "revenue": { "amount_minor": 800000 } }
  ]
}
```

### `GET /recent-orders`

Most recent AI-attributed orders for the Overview tab's DataViews table. Scoped to orders with `_wc_ai_storefront_agent` meta set.

**Query params:**

| Param | Type | Default | Range |
|-------|------|---------|-------|
| `per_page` | int | 10 | 1–50 |

**Response:** array of order rows with `id`, `number`, `date`, `status`, `agent`, `total`, `edit_url`.

### `GET /product-count`

Count of products that would currently be exposed under the saved (or hypothetical) settings.

**Query params:** any subset, used as overrides for live preview before save.

| Param | Type | Notes |
|-------|------|-------|
| `mode` | string | enum: `all`, `by_taxonomy`, `selected` |
| `selected_categories` | int[] | |
| `selected_tags` | int[] | |
| `selected_brands` | int[] | |
| `selected_products` | int[] | |

**Response:** `{ "count": int }`. Without overrides, reads saved settings — what the Store API filter actually enforces today.

### `GET /policy-pages`

Pages suitable for linking from the Policies tab. Excludes WC system pages (Cart, Checkout, My Account, Shop). Same shape as `/wp/v2/pages` for drop-in replacement.

### `GET /search/categories`, `/search/tags`, `/search/brands`, `/search/products`

Picker data for the Products tab. Each returns an array of `{ id, name, count }` (or `{ id, name, sku, image }` for products).

`/search/products` accepts:

| Param | Type | Default |
|-------|------|---------|
| `search` | string | (none) |
| `per_page` | int | 20 |

### `GET /endpoints`

Discovery endpoint URLs for the Discovery tab.

**Response:**

```json
{
  "llms_txt": "https://your-store.com/llms.txt",
  "ucp":      "https://your-store.com/.well-known/ucp",
  "ucp_api":  "https://your-store.com/wp-json/wc/ucp/v1",
  "robots":   "https://your-store.com/robots.txt"
}
```

---

## Discovery surfaces

### `GET /llms.txt`

A Markdown store guide. Generated by `WC_AI_Storefront_Llms_Txt`, cached as a transient (`wc_ai_storefront_llms_txt`), invalidated on product/category/settings changes by `WC_AI_Storefront_Cache_Invalidator`.

Returns `404` when syndication is paused.

Content-Type: `text/markdown; charset=utf-8`.

### `GET /.well-known/ucp`

JSON manifest. Generated by `WC_AI_Storefront_Ucp::generate_manifest()`, cached as a transient (`wc_ai_storefront_ucp`).

Top-level keys:

- `name`, `version`, `description`
- `capabilities` — array of `dev.ucp.shopping.catalog.search`, `.lookup`, `.checkout`
- `payment_handlers` — empty object (web-redirect-only posture)
- `services` — array with one entry pointing at the UCP REST adapter (`/wp-json/wc/ucp/v1`)
- `config.store_context` — currency, `prices_include_tax`, `shipping_enabled`, country, locale
- `com.woocommerce.ai_storefront` — vendor extension block; references the schema URL at `/wc/ucp/v1/extension/schema`

Returns `404` when syndication is paused.

Content-Type: `application/json; charset=utf-8`.

### `GET /robots.txt`

WordPress serves this; AI Storefront filters it via `do_robots`. When enabled, the plugin appends:

```
User-agent: GPTBot
Allow: /
User-agent: ChatGPT-User
Allow: /
User-agent: ClaudeBot
Allow: /
... (one stanza per checked crawler)

Disallow: /checkout/
Disallow: /my-account/
```

When disabled, the filter is removed and `robots.txt` reverts to WordPress default.

---

## Order meta keys (write-side surface)

When a customer completes checkout via an AI-referred URL, AI Storefront writes these meta keys on the resulting order:

| Key | Source | Description |
|-----|--------|-------------|
| `_wc_order_attribution_utm_source` | WC core | Agent identifier (`chatgpt`, `gemini`, etc.) |
| `_wc_order_attribution_utm_medium` | WC core | Always `ai_agent` for AI-referred orders |
| `_wc_ai_storefront_agent` | This plugin | Canonical brand name (denormalized for fast queries) |
| `_wc_ai_storefront_agent_host_raw` | This plugin | Raw host from `UCP-Agent` header for provenance |
| `_wc_ai_storefront_session_id` | This plugin | Session correlation ID (`chk_...` from `/checkout-sessions`) |

See [`DATA-MODEL.md`](DATA-MODEL.md) for the full data inventory.

---

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — component overview and design rationale
- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — how an AI agent decides to render a "Buy" button from the three discovery layers
- [`DATA-MODEL.md`](DATA-MODEL.md) — option keys, transients, and meta keys
- [`TESTING.md`](TESTING.md) — how to test endpoints without a live WP install
