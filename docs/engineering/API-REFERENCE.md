# REST API Reference

Endpoint-level reference for the two REST surfaces this plugin exposes:

- **UCP REST adapter** — `/wp-json/wc/ucp/v1/*`. Public; called by AI agents.
- **Admin REST API** — `/wp-json/wc/v3/ai-storefront/admin/*`. Authenticated; called by the React admin UI.

Discovery surfaces (`/llms.txt`, `/.well-known/ucp`, `/robots.txt`) aren't REST in the conventional sense — they're rewrite-rule-served virtual paths. They're documented in [`ARCHITECTURE.md`](ARCHITECTURE.md#discovery-layer).

## Conventions

- Every UCP response is wrapped in a `ucp` envelope with `version`, `capabilities`, and `payment_handlers`. Built by `WC_AI_Storefront_UCP_Envelope`.
- Errors use `error: { code, message }` plus an HTTP status code matching the failure class.
- Currency amounts on UCP responses are integers in **minor units** (cents for USD, pence for GBP). No floats. Read currency precision from the response context.
- Date-times are ISO 8601 UTC.
- The `UCP-Agent` request header is parsed in two formats: profile-URL (RFC 8941 Dictionary) and Product/Version (RFC 7231 §5.5.3). Either form works; absence is also valid (anonymous).

## Authentication

| Surface | Auth model |
|---------|------------|
| UCP REST (`/wc/ucp/v1/*`) | None. Public. Permission gate is `WC_AI_Storefront_UCP_REST_Controller::check_agent_access()`, which inspects merchant settings (`allowed_crawlers`, `allow_unknown_ucp_agents`) against the `UCP-Agent` header. |
| Admin REST (`/wc/v3/ai-storefront/admin/*`) | `manage_woocommerce` capability via WordPress's standard cookie/nonce or application-password authentication. |

The UCP gate is **secure-by-default**: an unrecognized `UCP-Agent` host returns 403 unless the merchant has opted in via `allow_unknown_ucp_agents=yes`. Agents whose host resolves to a known brand (e.g. `chatgpt.com`) are allowed only if at least one of their mapped crawler IDs is in the merchant's `allowed_crawlers` list.

When syndication is paused (`enabled=no`), every UCP commerce route returns **503** with `error.code=ucp_disabled` so agents read it as "transient pause, retry later" rather than 403's "permanent deny."

---

## UCP REST adapter

Base URL: `https://your-store.com/wp-json/wc/ucp/v1`

Module: [`includes/ai-storefront/ucp-rest/`](../../includes/ai-storefront/ucp-rest/)

### `POST /catalog/search`

Search the merchant's syndicated catalog. Translates UCP search params into a WC Store API call.

**Permission:** `check_agent_access`.

**Request body** (JSON object):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `query` | string | no | Free-text search term. |
| `filters` | object | no | UCP filter object. Honored fields: `categories`, `tags`, `brands`, `price` (`{min, max}`). |
| `pagination` | object | no | `{ "page": int, "per_page": int }`. Default 1 / 20. Max `per_page` 100. |
| `sort` | object | no | `{ "field": "relevance"\|"price"\|"date", "order": "asc"\|"desc" }`. |
| `context` | object | no | UCP context block (currency, locale). Logged but not currently honored. |
| `signals` | object | no | Platform-observed environment data. Logged but not honored — UCP spec mandates these MUST NOT be buyer-asserted; until we have a trust model we ignore values. |

**Response (200):**

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
      "url": "https://your-store.com/product/acme-running-shoes/?utm_source=chatgpt.com&utm_medium=referral&utm_id=woo_ucp&ai_agent_host_raw=chatgpt.com",
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

The product `url` carries the canonical 0.5.0+ UTM payload (`utm_source=<hostname>`, `utm_medium=referral`, `utm_id=woo_ucp`, optional `ai_agent_host_raw`). Buyers who follow the bare product link from chat — rather than going through `/checkout-sessions` — still attribute correctly via WC Order Attribution.

**Errors:**
- `503` `ucp_disabled` — syndication paused.
- `400` `ucp_invalid_request` — body fails JSON Schema validation.
- `429` — Store API rate limit exceeded for the user-agent.

**Curl:**

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
{ "products": ["wc_42", "wc_43", "wc_99"] }
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `products` | string[] | yes | Array of UCP product IDs (`wc_<post_id>` for simple, `wc_<post_id>_<variation_id>` for variations). Max 100 items. |

**Response:** same envelope as `/catalog/search` but with `products` matching requested IDs in order. Missing or excluded products are omitted (no per-ID error — agents diff against their request). Same UTM stamping on the product `url` field.

**Errors:** `503` `ucp_disabled`; `400` `ucp_invalid_request` when `products` is missing, empty, or > 100 items.

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
| `items` | array | yes | `variant_id` (string), `quantity` (int >=1). Max 100 items. |
| `shipping_address` | object | no | UCP address block. Used for shipping/tax preview. |
| `context` | object | no | UCP context block (currency, locale). |

**Response — happy path (200):**

```json
{
  "ucp": { "version": "2026-04-08", "capabilities": ["dev.ucp.shopping.checkout"], "payment_handlers": {} },
  "id": "chk_a1b2c3d4e5f6g7h8",
  "status": "requires_escalation",
  "continue_url": "https://your-store.com/checkout-link/?products=42:1,56:2&utm_source=chatgpt.com&utm_medium=referral&utm_id=woo_ucp&ai_agent_host_raw=chatgpt.com&ai_session_id=chk_a1b2c3d4e5f6g7h8",
  "totals": {
    "subtotal": { "amount_minor": 38997, "currency": "USD" },
    "shipping": { "amount_minor": 0, "currency": "USD" },
    "tax":      { "amount_minor": 0, "currency": "USD" },
    "total":    { "amount_minor": 38997, "currency": "USD" }
  },
  "messages": [
    {
      "type": "info",
      "code": "buyer_handoff_required",
      "severity": "advisory",
      "content": "Continue checkout on the merchant's site to complete your purchase."
    }
  ]
}
```

The `continue_url` is the agent's signal to render a Buy CTA. See [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) for the three-layer decision tree.

The `buyer_handoff_required` message uses `type: info` + `severity: advisory` (post-PR #119) so AI assistants render it informationally, not as an error. The continue_url's UTM payload matches the canonical 0.5.0+ shape — same as bare product URLs.

**Response — incomplete (200, no `continue_url`):**

```json
{
  "ucp": { ... },
  "id": "chk_...",
  "status": "incomplete",
  "messages": [
    {
      "type": "error",
      "code": "out_of_stock",
      "severity": "requires_buyer_input",
      "content": "Acme Running Shoes (Size 11) is out of stock."
    }
  ]
}
```

**Errors:** `503` `ucp_disabled`; `400` `ucp_invalid_request` for missing/empty `items` or malformed variant IDs.

**Note on session IDs.** `chk_<16 hex chars>` is a correlation token for logging and attribution. There is no GET/PUT/PATCH/DELETE endpoint that operates on it — see the next section.

### `GET|PUT|PATCH|DELETE /checkout-sessions/{id}`

Returns a structured `405 Method Not Allowed` for any verb other than POST on a session URL:

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

**Why this exists.** UCP-aware agents that come from a stateful-session model try PATCH (add items), PUT (replace cart), GET (look up status), DELETE (cancel). Without these route stubs WP REST returns generic `rest_no_route` 404s, which agents misinterpret as "the session was lost" and may retry destructively. The structured 405 gives them an actionable answer.

### `GET /extension/schema`

Returns the JSON Schema for our `com.woocommerce.ai_storefront` extension capability. Public — schema metadata is not commerce data, and gating it would break the manifest's `schema` URL discoverability.

**Permission:** `__return_true`.

**Response:** `200 OK`, `application/json`. Per-site so the schema matches the running plugin version exactly.

---

## Admin REST API

Base URL: `https://your-store.com/wp-json/wc/v3/ai-storefront/admin`

Module: [`includes/admin/class-wc-ai-storefront-admin-controller.php`](../../includes/admin/class-wc-ai-storefront-admin-controller.php)

All endpoints require the `manage_woocommerce` capability. Authentication is via WordPress's standard cookie/nonce (admin UI) or application-password (external).

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
| `product_selection_mode` | string | enum: `all`, `by_taxonomy`, `selected` (legacy `categories`/`tags`/`brands` silently migrated to `by_taxonomy`) |
| `selected_categories` | int[] | term IDs |
| `selected_tags` | int[] | term IDs |
| `selected_brands` | int[] | term IDs |
| `selected_products` | int[] | post IDs |
| `rate_limit_rpm` | int | 1–1000 |
| `allowed_crawlers` | string[] | intersected with `WC_AI_Storefront_Robots::AI_CRAWLERS` on save; unknown IDs stripped silently |
| `allow_unknown_ucp_agents` | string | enum: `yes`, `no` |
| `return_policy` | object | sub-fields validated by `WC_AI_Storefront_Return_Policy::sanitize()` |

**Response:** the updated settings object.

**Side effects:** when `enabled` flips, schedules a rewrite-rule flush; on enable, eagerly warms the `/llms.txt` and UCP-manifest transients.

### `GET /stats`

AI-attributed order aggregates by period.

**Query params:**

| Param | Type | Default | Enum |
|-------|------|---------|------|
| `period` | string | `month` | `day`, `week`, `month`, `year` |

**Response:**

```json
{
  "period": "month",
  "ai_orders": 42,
  "ai_revenue": 5400.00,
  "ai_aov": 128.57,
  "all_orders": 100,
  "ai_share_percent": 42.0,
  "currency": "USD",
  "currency_symbol": "$",
  "by_agent": {
    "chatgpt": { "orders": 24, "revenue": 3100.00 },
    "perplexity": { "orders": 12, "revenue": 1500.00 },
    "gemini": { "orders": 6, "revenue": 800.00 }
  },
  "top_agent": {
    "name": "chatgpt",
    "orders": 24,
    "revenue": 3100.00,
    "share_percent": 57.1
  }
}
```

`ai_revenue` and per-agent `revenue` are floats in the store's currency. `currency_symbol` is the decoded symbol (`$`, `€`, `£`) or empty when unavailable; `currency` is always the ISO 4217 code. `top_agent` is `null` when there are no AI orders in the period. Tie-break for `top_agent` is `orders DESC, revenue DESC, name ASC` (stable across snapshots).

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

**Response:** `{ "count": int }`. Without overrides, reads saved settings — what the Store API filter actually enforces.

### `GET /policy-pages`

Pages suitable for linking from the Policies tab. Excludes WC system pages (Cart, Checkout, My Account, Shop). Same shape as `/wp/v2/pages` for drop-in replacement.

### `GET /search/categories`, `/search/tags`, `/search/brands`, `/search/products`

Picker data for the Product Visibility tab. Each returns an array of `{ id, name, count }` (or `{ id, name, sku, image }` for products).

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

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — component overview and design rationale
- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — how an agent decides to render a Buy CTA from the discovery layers
- [`DATA-MODEL.md`](DATA-MODEL.md) — options, transients, and meta keys this API reads/writes
- [`HOOKS.md`](HOOKS.md) — filters and actions extending plugins can use
- [`TESTING.md`](TESTING.md) — how to test endpoints without a live WP install
