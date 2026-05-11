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

The free-text `query` is preprocessed by `WC_AI_Storefront_UCP_Store_API_Filter::on_posts_clauses_search()` (hooked at `posts_clauses` priority 9): the phrase is split into signal terms and each term is resolved against the store's own `product_cat`, `product_tag`, `product_brand`, and `pa_*` taxonomies via a suffix-flip dictionary, then combined with title LIKE fallback. This means natural-language queries like `"hoodie with logo"` or `"running shoes for men"` resolve to relevant products even when the exact phrase isn't in any product title — `hoodie` matches the "Hoodies" category, `running shoes` resolves morphologically to "Running Shoe", etc. See [`ARCHITECTURE.md`](ARCHITECTURE.md#ucp-rest-adapter) for the full clause-shape spec.

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

**Notable enrichment fields on each `product` object:**

- **`categories[]`** — each entry's `value` is a `>`-delimited hierarchy string (e.g. `"Clothing > Tshirts"`) per `category.json`. Brands stay flat (no hierarchy in WC). Falls back to bare leaf name when ancestry can't be resolved.
- **`options[].values[].id`** — stable `<taxonomy>:<slug>` identifier (e.g. `pa_color:black`) for taxonomy-backed variant attributes. Omitted for custom inline attributes and when the term slug is unavailable. Agents echo this back as `selected_option.id` for cross-locale variant matching.
- **Non-variable products (simple, bundle, grouped) emit no `options[]`** regardless of attribute names. UCP `product_option.json` characterizes options by example as size, color, or material — variant-selection axes the buyer chooses between. A non-variable WC product has no `has_variations: true` axis, so there's no buyer-selectable axis to lock in. Color / Size / Pattern / Material attributes on these products surface as descriptive `metadata.attributes` entries (same path as Origin, Fabric Weight, etc.), with all declared values preserved. Variable products' `has_variations: true` attributes still emit legitimate selectable `options[]`.

**Errors:**
- `503` `ucp_disabled` — syndication paused.
- `400` `invalid_input` — body fails JSON Schema validation.
- `429` `ucp_rate_limit_exceeded` — outer-UCP-request rate limit exceeded. One slot is consumed per outer request (not per inner Store API call). The limit is the merchant's `rate_limit_rpm` setting; window is 60 seconds. Response includes `retry_after: 60`.

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

**Featured-variant precision** (#369). When an agent looks up a parent product ID (`prod_<digits>`) and the product has multiple variations, the response marks exactly one variant as `featured` in its `inputs[]` correlation, with the featured variant reordered to `variants[0]`. The selection rules:

- **Merchant signal present** — if the parent has `_default_attributes` covering every variation axis, the resolved variation is featured. Single source of truth: `WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id()`.
- **No merchant signal** — first variation by `menu_order` is featured (the Store API already orders variations by `menu_order`).
- **Sibling variants** emit with `inputs: [{"id": <input>}]` and no `match` field (spec-clean per `input_correlation.json` where `match` is optional).

Both signals must agree — `variants[0]` carries `match: featured` in its inputs entry. Per `product.json` (verbatim from `Universal-Commerce-Protocol/ucp`): _"First item is the featured variant for listings."_

This applies to both `variable` and `variable-subscription` parents. The merchant's `_default_attributes` is the signal we use; we do NOT auto-resolve it server-side in `/checkout-sessions` (see Supported product types above for the design rationale).

**Errors:** `503` `ucp_disabled`; `400` `invalid_input` when `products` is missing, empty, or > 100 items.

### `POST /checkout-sessions`

Validate a cart and return a redirect URL for the buyer to complete checkout on the merchant site. **Stateless** — never persists anything.

The exact `continue_url` shape depends on the cart contents: simple/variation/subscription carts get `/checkout-link/?products=ID:QTY`; deterministic bundles or grouped products get `/checkout/?add-to-cart=…` with per-bundled-item / per-child params; configurable bundles, grouped, or variable/variable-subscription parents get the merchant PDP permalink. See [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md#layer-3--checkout-session-the-real-green-light) for the full URL-shape table.

**Supported product types** (#370):

| Type | At `/checkout-sessions` |
|---|---|
| `simple`, `variation`, `subscription`, `subscription_variation` | Accepted directly — Shareable Checkout `/checkout-link/?products=ID:1` |
| `bundle`, `grouped` | Accepted; deterministic shape when configurable, permalink fallback otherwise |
| `variable`, `variable-subscription` parent (no variation chosen) | Accepted with permalink fallback to the parent's PDP + `field_required` / `requires_buyer_input` (pre-#370 this was a hard `variation_required` rejection) |
| `external` | Rejected with `product_type_unsupported` (third-party seller's site has no permalink fallback) |

For variable + variable-subscription parents, the permalink fallback does NOT auto-resolve the merchant's `_default_attributes` into a specific variation URL. WC core's PDP behavior pre-fills the dropdown with the merchant's default, but the buyer retains the final selection. The catalog response's `match: featured` marker conveys the merchant's hint at lookup time (see below).

**Permission:** `check_agent_access`.

**Request body:**

```json
{
  "line_items": [
    { "item": { "id": "var_42_default" }, "quantity": 1 },
    { "item": { "id": "var_56" }, "quantity": 2 }
  ],
  "context": { "locale": "en-US" }
}
```

UCP ID grammar accepted by the parser (`parse_ucp_id_to_wc_int()`):

| ID form | Meaning |
|---|---|
| `prod_<digits>` | A WC product (simple, variable parent, bundle, grouped) |
| `var_<digits>` | A real WC variation |
| `var_<digits>_default` | A synthesized default variant for any non-variable product. UCP's `variants[] minItems: 1` requirement means every product must emit at least one variant; for simple / bundle / grouped products (or any case where variations aren't pre-fetched), the translator synthesizes one default variant whose ID carries the `_default` suffix to keep it distinguishable from real variations |
| `<digits>` | Bare numeric IDs (e.g. `"123"`) are also accepted — the parser strips a known prefix if present but doesn't require one. Discouraged in agent-facing flows (loses the prod/var distinction); useful for round-tripping IDs from systems that don't carry the prefix |

Malformed IDs — non-prefixed non-numeric strings, `var_56_2` (non-`_default` non-digit suffix), `var_abc`, etc. — parse to `0` and surface as `not_found`.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `line_items` | array | yes | Each entry: `item.id` (string product/variation ID echoed back in the response), `quantity` (int ≥1). Max 100 entries. Duplicate entries targeting the same product ID are collapsed before validation — quantities are summed, and the response echoes one line per product. A `merged_duplicate_items` info message accompanies the response when collapsing occurs so agents can reconcile. |
| `context` | object | no | UCP context block. Currently only `context.locale` is read (BCP-47 language tag, e.g. `en-US`). |

**Response — happy path (201 Created):**

```json
{
  "ucp": { "version": "2026-04-08", "capabilities": ["dev.ucp.shopping.checkout"], "payment_handlers": {} },
  "id": "chk_a1b2c3d4e5f6g7h8",
  "status": "requires_escalation",
  "currency": "USD",
  "line_items": [
    {
      "item": { "id": "var_42_default" },
      "quantity": 1,
      "unit_price": { "amount": 12999, "currency": "USD" },
      "line_total": { "amount": 12999, "currency": "USD" },
      "price_includes_tax": false
    },
    {
      "item": { "id": "var_56" },
      "quantity": 2,
      "unit_price": { "amount": 12999, "currency": "USD" },
      "line_total": { "amount": 25998, "currency": "USD" },
      "price_includes_tax": false
    }
  ],
  "totals": [
    { "type": "subtotal", "amount": 38997 },
    { "type": "total", "amount": 38997 }
  ],
  "links": [],
  "expires_at": null,
  "continue_url": "https://your-store.com/checkout-link/?products=42:1,56:2&utm_source=chatgpt.com&utm_medium=referral&utm_id=woo_ucp&ai_agent_host_raw=chatgpt.com",
  "messages": [
    {
      "type": "info",
      "code": "buyer_handoff_required",
      "content": "Complete your purchase on the merchant site."
    },
    {
      "type": "info",
      "code": "total_is_provisional",
      "content": "Total excludes tax and shipping, which are calculated at the merchant checkout."
    }
  ]
}
```

The `continue_url` is the agent's signal to render a Buy CTA. See [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) for the three-layer decision tree.

The `buyer_handoff_required` message uses `type: info` so AI assistants render it informationally, not as an error. Per UCP `message_info.json` (release/2026-04-08), info messages carry no `severity` field — only `type: error` does. The accompanying `total_is_provisional` info message explains the `subtotal == total` collapse: tax and shipping are computed at the merchant checkout, not server-side here.

The continue_url's UTM payload (`utm_source`, `utm_medium=referral`, `utm_id=woo_ucp`, optional `ai_agent_host_raw`) matches the canonical 0.5.0+ shape — same as bare product URLs. The plugin's stamping helper does **not** append `ai_session_id`; agents that want session-correlation can add their own `ai_session_id=chk_…` query param to the continue_url before redirecting (the plugin's order-attribution capture reads it from `$_GET` and writes it to order meta).

**Response — incomplete (200, no `continue_url`):**

The standard envelope fields (`ucp`, `id`, `currency`, `line_items`, `totals`, `links`, `expires_at`) are always present; only `continue_url` is omitted in the incomplete case. The `messages[]` entries describe what failed.

```json
{
  "ucp": { "version": "2026-04-08", "capabilities": ["dev.ucp.shopping.checkout"], "payment_handlers": {} },
  "id": "chk_a1b2c3d4e5f6g7h8",
  "status": "incomplete",
  "currency": "USD",
  "line_items": [],
  "totals": [
    { "type": "subtotal", "amount": 0 },
    { "type": "total", "amount": 0 }
  ],
  "links": [],
  "expires_at": null,
  "messages": [
    {
      "type": "error",
      "code": "out_of_stock",
      "severity": "unrecoverable",
      "path": "$.line_items[0].item.id",
      "content": "Product is out of stock."
    }
  ]
}
```

The `severity: unrecoverable` value above is the `checkout_error_message()` default — it's the per-line-item code's natural severity (the line itself can't be recovered without merchant restocking, even though the buyer can recover the cart by removing the line). For codes whose default is overridden (like `minimum_not_met` → `requires_buyer_input`), see the per-code list below.

Other in-cart error codes the response may carry:

- `out_of_stock` — **per-line-item** rejection (`severity: unrecoverable`, the default from `checkout_error_message()`). The OOS line is dropped from `line_items` and surfaced as a `messages[]` entry; the overall response can still be `201 requires_escalation` + `continue_url` when other lines validate. Only when *no* lines validate does the response collapse to `200 incomplete`.
- `minimum_not_met` — **cart-level** rejection (`severity: requires_buyer_input`, deliberately overriding the default — the buyer can resolve by adding items, so `unrecoverable` would mislead agents into abandoning the cart). Even when all lines validate individually, a surviving subtotal below the merchant minimum forces `status: incomplete` with no `continue_url`.
- `field_required` — path-attributed via `$.line_items[N]`; emitted for bundles and grouped products. Can appear in **either** response status:
    - `status: incomplete` (no `continue_url`) when the cart is mixed/multi bundle-or-grouped (must split and retry) or when the configurable bundle/grouped has no usable permalink (`severity: recoverable`).
    - `status: requires_escalation` (with `continue_url` pointing at the bundle/grouped PDP) when the bundle/grouped is configurable but has a permalink — buyer completes configuration on the merchant site (`severity: requires_buyer_input`).

The `severity` field on each error tells the agent how to recover (per UCP `message_error.json`). `recoverable` means **the platform can resolve by modifying inputs and retrying via API** — for mixed/multi bundle-or-grouped carts that means splitting into per-container `/checkout-sessions` calls; for a single configurable container with no usable permalink it means the merchant must fix the underlying misconfig before retry succeeds. `requires_buyer_input` means the buyer must complete configuration on the merchant site. See [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md#layer-3--checkout-session-the-real-green-light) for the full URL-shape table.

**Errors:** `503` `ucp_disabled` when syndication is paused; `400` `invalid_input` when `line_items` is missing/empty or exceeds the per-request cap. Per-line-item validation failures (unrecognized ID formats outside `prod_…` / `var_…[_default]`, unknown product IDs, out-of-stock items, etc.) are surfaced as `messages[].code` entries on the standard response, not as top-level errors.

The response status is `201 requires_escalation` + `continue_url` only when **both** conditions hold:

1. **At least one line item validates** (survives per-line-item filtering — non-validating lines are dropped from `line_items` and surfaced via `messages[]`).
2. **No cart-level redirect blocker fires.** Cart-level blockers force `200 incomplete` with no `continue_url` even when valid lines exist:
   - Surviving subtotal below the merchant's minimum order amount (`minimum_not_met`).
   - Mixed/multi bundle or grouped cart that requires splitting (`field_required` per offending container).
   - Configurable bundle/grouped without a usable PDP permalink (`field_required` recoverable).

When zero lines validate, the response is `200 incomplete` regardless of cart-level rules. Agents read `messages[].code` to surface the per-line outcome to the buyer in either response status.

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

### `GET /crawl-stats`

Aggregated crawler-visibility stats for the Discovery tab. Reads from the summary table (`{prefix}wc_ai_storefront_crawl_summary`) — refreshed on every rollup run (hourly by default, overridable via the `wc_ai_storefront_rollup_interval` filter). Today's in-progress events appear within one rollup cycle. The rolled-up aggregates are cached for 5 minutes via the `wc_ai_storefront_crawl_stats_{period}` transient. Two fields — `rollup_interval` and `raw_event_count` — are explicitly excluded from the transient and queried live on every response so a filter change or a fresh raw-log hit takes effect immediately, without waiting for the cache to expire.

**Query params:**

| Param | Type | Default | Values |
|-------|------|---------|--------|
| `period` | string | `month` | `day` \| `week` \| `month` \| `quarter` |

**Response:**

```json
{
  "period":            "month",
  "total_requests":    12483,
  "unique_products":   147,
  "store_api_queries": 4521,
  "llms_txt_hits":     2104,
  "ucp_hits":          5872,
  "throttle_count":    14,
  "throttle_rate":     0.1,
  "by_agent": [
    { "agent": "ChatGPT",    "requests": 8120 },
    { "agent": "Perplexity", "requests": 2944 },
    { "agent": "Gemini",     "requests": 1419 }
  ],
  "top_queries": [
    { "query": "running shoes", "count": 47, "agents": ["ChatGPT", "Perplexity"] },
    { "query": "hoodies",       "count": 31, "agents": ["ChatGPT"] }
  ],
  "top_queries_window_days": 30,
  "raw_event_count": 12891,
  "rollup_interval": "hourly"
}
```

`throttle_rate` is `(throttle_count / total_requests) * 100` rounded to one decimal place; returns `0.0` when `total_requests` is zero. `top_queries[].agents` is the deduplicated list of agents that issued each search term in the period.

`top_queries_window_days` is the effective lookback for `top_queries` (always `min(period_days, RAW_RETENTION_DAYS=30)`). Top searches read from the raw log (query strings aren't aggregated into the summary table), and the raw log retains only 30 days, so for `period=quarter` (90d) this value is `30` while every other field reflects the full 90-day period.

`raw_event_count` is `COUNT(*)` over the raw log (`{prefix}wc_ai_storefront_crawl_log`) for the requested period. This field is **not cached** — it runs on every response, before the transient cache check, so brand-new traffic (llms.txt, UCP, product-page hits) becomes visible immediately even if the rollup hasn't fired yet. The Discovery tab's empty-state guard checks this in addition to `total_requests` and `top_queries`, so a fresh install that already has raw activity doesn't falsely render "no AI agent activity recorded".

`rollup_interval` is the validated cron recurrence slug currently in use: one of `"hourly"` (default), `"twicedaily"`, or `"daily"`. This is the value returned by `WC_AI_Storefront_Crawl_Logger::get_effective_rollup_interval()` — the same logic used by `schedule_crons()`. Like `raw_event_count`, this field is **not cached** in the transient — it's injected live on every response (cache-hit and fresh paths alike) so a `wc_ai_storefront_rollup_interval` filter change is reflected on the very next request. Clients use this to render a specific subtitle ("Updated hourly.", "Updated every 12 hours.", "Updated daily.") rather than a generic fallback.

---

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — component overview and design rationale
- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — how an agent decides to render a Buy CTA from the discovery layers
- [`DATA-MODEL.md`](DATA-MODEL.md) — options, transients, and meta keys this API reads/writes
- [`HOOKS.md`](HOOKS.md) — filters and actions extending plugins can use
- [`TESTING.md`](TESTING.md) — how to test endpoints without a live WP install
