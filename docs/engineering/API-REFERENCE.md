# REST API Reference

Endpoint-level reference for the REST surfaces this plugin exposes:

- **UCP REST adapter** — `/wp-json/wc/ucp/v1/*`. Public; called by AI agents.
- **Shopify-compatible products feed** — `/products.json`, `/collections/all/products.json`, `/products/{handle}.json`, `/collections/{handle}/products.json`, `/collections.json`. Public; **non-UCP, additive Shopify-compat surface** (see below).
- **Admin REST API** — `/wp-json/wc/v3/ai-storefront/admin/*`. Authenticated; called by the React admin UI.

Discovery surfaces (`/llms.txt`, `/agents.md`, `/.well-known/ucp`, `/robots.txt`, `/opensearch.xml`) aren't REST in the conventional sense — they're rewrite-rule-served virtual paths. They're documented in [`ARCHITECTURE.md`](ARCHITECTURE.md#discovery-layer). (`/agents.md` is a byte-identical mirror of `/llms.txt` — same generator, same cache.) The `/products.json` feed is also a rewrite-rule-served virtual path, but because it returns a JSON catalog body REST clients code against, it's documented here.

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

## Manifest `store_context`

The `/.well-known/ucp` manifest carries a `config.store_context` block agents consult before quoting prices, picking a shipping destination, or asking for a localized response. The discovery surface itself is documented in [`ARCHITECTURE.md`](ARCHITECTURE.md#discovery-layer); the field reference below is the contract REST clients should code against.

| Field | Type | Description |
|-------|------|-------------|
| `currency` | `string` | ISO-4217 base currency code. Sourced from `get_woocommerce_currency()`. Single source of truth for "the store's default currency." |
| `accepted_currencies` | `array<string>` | Ordered, deduplicated list of ISO-4217 currency codes the store accepts. **Conditionally emitted** — present only when the store accepts more than one currency (WooPayments multi-currency enabled with at least one additional currency configured). Omitted on single-currency stores so the manifest doesn't falsely advertise multi-currency support. Base currency is always first when present. Since 0.17.0. |
| `prices_include_tax` | `bool` | Whether the catalog `price` figures already include tax. Sourced from `wc_prices_include_tax()`. |
| `shipping_enabled` | `bool` | Whether the store offers shipping at all. Sourced from `wc_shipping_enabled()`. |
| `country` | `string` | ISO-3166 base country code. Sourced from `WC()->countries->get_base_country()`. |
| `locale` | `string` | BCP-47 site locale. Sourced from `determine_locale()` (admin / WP-CLI / Site Health correctness over `get_locale()`). |

**Multi-currency contract.** When `accepted_currencies` carries more than one code, agents can include `context.currency` in `POST /catalog/search` / `/catalog/lookup` / `/checkout-sessions` request bodies. The returned UCP `continue_url` (every variant) and per-product `url` fields then carry a `?currency=XXX` query parameter ahead of the UTM block. See the per-endpoint notes below. When `context.currency` is in the store's `accepted_currencies` set, the catalog response itself is quoted in that currency: every `price.currency` field in `price_range`, `list_price_range`, bundle prices, and per-variant `selling_price` / `list_price` carries the requested code, with amounts converted via WooPayments' configured exchange rate, rounding rule, and charm pricing offset. `filters.price` bounds are converted from the agent's currency to base before the Store API query and back to the agent's currency in the response, so a `min: 5000` (EUR) filter behaves consistently with EUR-quoted response prices. `POST /checkout-sessions` accepts `expected_unit_price.currency` in any code present in `accepted_currencies`; the comparison runs in that currency. When `context.currency` is absent, or is not in `accepted_currencies`, the response falls back to base currency with a `currency_conversion_unsupported` warning at `$.context.currency` (or `$.filters.price` for the filter-only mismatch).

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
| `pagination` | object | no | `{ "limit": int, "cursor": string }`. `limit` defaults to 10, clamped to `[1, 100]`. `cursor` is the opaque `pagination.cursor` value from a previous response; omit it for the first page. **`page` and `per_page` are not accepted on POST** — they are GET-only spellings, translated to `cursor` / `limit` internally. |
| `sort` | object | no | `{ "field": "price"\|"title"\|"date"\|"newest"\|"popularity"\|"rating"\|"menu_order", "direction": "asc"\|"desc" }`. `direction` defaults to `asc` (ignored for `newest`, which is always `desc`). An unrecognized `field` doesn't error — it emits an `invalid_sort_field` warning in `messages` and falls back to default ordering. **`order` is not accepted** — it is the internal Store API spelling, never a request key. |
| `context` | object | no | UCP context block (currency, locale). Logged but not currently honored. |
| `signals` | object | no | Platform-observed environment data. Logged but not honored — UCP spec mandates these MUST NOT be buyer-asserted; until we have a trust model we ignore values. |

**Response (200):**

```json
{
  "ucp": {
    "version": "2026-04-08",
    "capabilities": { "dev.ucp.shopping.catalog.search": [{ "version": "2026-04-08" }] }
  },
  "products": [
    {
      "id": "prod_42",
      "title": "Acme Running Shoes",
      "description": { "plain": "..." },
      "url": "https://your-store.com/product/acme-running-shoes/?utm_source=chatgpt.com&utm_medium=referral&utm_id=woo_ucp&ai_agent_host_raw=chatgpt.com",
      "variants": [
        {
          "id": "var_42_default",
          "title": "Acme Running Shoes",
          "description": { "plain": "Premium running shoes designed for daily training." },
          "price": { "amount": 12999, "currency": "USD" },
          "availability": { "available": true }
        }
      ],
      "metadata": {
        "lifecycle":  { "status": "published" },
        "timestamps": {
          "published_at": "2026-01-15T10:30:00Z",
          "updated_at":   "2026-04-20T14:22:31Z"
        }
      }
    }
  ],
  "pagination": { "has_next_page": true, "cursor": "cDI=", "total_count": 142 }
}
```

The product `url` carries the canonical 0.5.0+ UTM payload (`utm_source=<hostname>`, `utm_medium=referral`, `utm_id=woo_ucp`, optional `ai_agent_host_raw`). Buyers who follow the bare product link from chat — rather than going through `/checkout-sessions` — still attribute correctly via WC Order Attribution.

**Multi-currency stamping (0.17.0+).** When the request body carries `context.currency` and the value is a member of `store_context.accepted_currencies`, the returned `url` carries `?currency=XXX` ahead of the UTM block. This activates WooPayments' built-in currency switcher on the destination page so the buyer sees the requested currency on the merchant's product page. When `context.currency` is absent, malformed, or outside the accepted set, the URL is unchanged. Per-product page JSON-LD automatically reflects the same `?currency=XXX` value (WooPayments switches `get_woocommerce_currency()` before the JSON-LD enricher runs); see [`JSON-LD-SCHEMA.md`](JSON-LD-SCHEMA.md#store-homepage-onlinebusiness-schema) for the free-win details.

**Notable enrichment fields on each `product` object:**

- **`categories[]`** — each entry's `value` is a `>`-delimited hierarchy string (e.g. `"Clothing > Tshirts"`) per `category.json`. Brands stay flat (no hierarchy in WC). Falls back to bare leaf name when ancestry can't be resolved.
- **`options[].values[].id`** — stable `<taxonomy>:<slug>` identifier (e.g. `pa_color:black`) for taxonomy-backed variant attributes. Omitted for custom inline attributes and when the term slug is unavailable. Agents echo this back as `selected_option.id` for cross-locale variant matching.
- **Non-variable products (simple, bundle, grouped) emit no `options[]`** regardless of attribute names. UCP `product_option.json` characterizes options by example as size, color, or material — variant-selection axes the buyer chooses between. A non-variable WC product has no `has_variations: true` axis, so there's no buyer-selectable axis to lock in. Color / Size / Pattern / Material attributes on these products surface as descriptive `metadata.attributes` entries (same path as Origin, Fabric Weight, etc.), with all declared values preserved. Variable products' `has_variations: true` attributes still emit legitimate selectable `options[]`.
- **`metadata.lifecycle.status`** (#374) — always `"published"` since the catalog handlers only translate Store-API-returned products, which already filter out drafts/private. Emitted under `metadata` (per UCP spec — `product.json` doesn't define `status` as a first-class property) so strict validators that tighten `additionalProperties` in a future spec revision won't reject it. **Pre-v0.14.2 this was a top-level `status` field; the relocation is a breaking shape change with no merchant migration since the field was only 3 days old when relocated.**
- **`metadata.timestamps.published_at` / `metadata.timestamps.updated_at`** (#374) — ISO 8601 UTC strings sourced from our Store API extension at `extensions.com-woocommerce-ai-storefront.{date_created,date_modified}`. The whole `timestamps` sub-block is omitted when no timestamps are extracted, rather than emitted as empty scaffolding. Same relocation rationale as `lifecycle.status` above — both previously lived at the product top level.
- **Synthesized variants carry the parent's `short_description`** (#375). For simple / bundle / grouped products (and the `synthesize_default()` fallback for malformed-variable parents), the variant's `description.plain` now carries the parent's `short_description` (strip-tags + decode-entities) instead of an empty string. Agents that drill into a variant ID directly see the variant entity with useful descriptive copy. Graceful degradation preserved: when the parent has no `short_description`, the variant still emits `description.plain = ""`.
- **Unpurchasable variations are filtered from `variants[]`** (#373). A misconfigured variation (e.g. missing a price) reads `is_in_stock: true` but `is_purchasable: false` in WC core. `fetch_variations_for()` drops these before they reach the product translator, so the broken variant ID never leaks to agents. If *every* variation of a variable parent is unpurchasable, the response falls through to a single `synthesize_default()` placeholder rather than emitting a schema-invalid empty `variants[]` array.

**Zero-results hints.** When the response returns no products (`products: []`, `total_count: 0`), the body includes a `hints` object with a step-by-step recovery recipe:

```json
"hints": {
  "zero_results": true,
  "recovery_steps": {
    "1_bare_query":         "Retry with a bare product noun in \"query\" only...",
    "2_drop_filters":       "Remove one filter at a time to isolate over-restricting constraints.",
    "3_browse_categories":  "Enumerate valid category slugs via the extension schema, then retry with filters.categories.",
    "4_report_unavailable": "Only report unavailable after bare-noun + category-browse both return zero."
  }
}
```

This gives agents a recovery path at the moment they need it, without requiring a prior llms.txt or discovery-document read.

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
    "pagination": { "limit": 10 }
  }'
```

**Paging.** Feed the response's `pagination.cursor` back as the request's `pagination.cursor` to advance. The value is opaque — do not construct or parse it. `cursor` is only present when `has_next_page` is `true` — the last page omits the key entirely rather than sending it as `null`, so check `has_next_page` before reading `cursor`.

```bash
# First page
curl -X POST https://your-store.com/wp-json/wc/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' \
  -d '{ "query": "tote", "pagination": { "limit": 5 } }'
# -> "pagination": { "has_next_page": true, "cursor": "cDI=", "total_count": 39 }

# Next page
curl -X POST https://your-store.com/wp-json/wc/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' \
  -d '{ "query": "tote", "pagination": { "limit": 5, "cursor": "cDI=" } }'
```

### `GET /catalog/search`

Public, fetch-friendly variant of `POST /catalog/search` for agents (Perplexity, Bing, fetch-based crawlers) that cannot POST a JSON body or scan `<head>` for a discovery document. It translates flat query-string params into the same `$params` shape the POST handler builds, then delegates to the identical `run_catalog_search()` neutral core — so normalization, validation, warning emission, and the response envelope are all shared. There is no new filter logic.

**Permission:** `check_agent_access` — the same gate as POST, so the merchant's `allowed_crawlers` and `allow_unknown_ucp_agents` settings apply equally to GET and POST. A request with no `UCP-Agent` header passes the gate on the same empty-header path as POST. Attribution then resolves in precedence order: `UCP-Agent` profile/product → body `meta.source` → **User-Agent-derived** (#465) → `ucp_unknown`. The User-Agent step (`WC_AI_Storefront_UCP_Agent_Header::classify_user_agent()`) maps answer-agent UA tokens (e.g. `ChatGPT-User`/`GPTBot`/`OAI-SearchBot` → `chatgpt.com`, `Claude-User`/`ClaudeBot`/`Claude-SearchBot` → `claude.ai`, `Perplexity-User`/`PerplexityBot` → `perplexity.ai`) to the same `{name, source_host, raw_host}` triple a declared header produces, so an inferred ChatGPT attributes identically to a declared one (`utm_source=chatgpt.com`), with the raw UA token stored in `_wc_ai_storefront_agent_host_raw` so merchants can tell inferred from declared. Generic indexers (Bingbot/Googlebot/Applebot) are deliberately not mapped and stay `ucp_unknown`. This is **attribution only** — it never touches `check_agent_access`, so a spoofed UA earns attribution credit but no access.

**Query params:**

| Param | Maps to | Description |
|-------|---------|-------------|
| `q` | `query` | Free-text search term. |
| `category` | `filters.categories[0]` | A single category slug. |
| `min_price` | `filters.price.min` | Integer lower bound, store currency (minor unit per the price filter contract). |
| `max_price` | `filters.price.max` | Integer upper bound. |
| `in_stock` | `filters.in_stock` | `1` or `true` → `true`; any other value → `false`. |
| `attribute[<axis>]` | `filters.attributes.<axis>` | Bracket notation, one slug per axis: `?attribute[color]=blue&attribute[size]=M`. Each key maps to a single term slug. A scalar `?attribute=blue` (no brackets) is logged at debug level and ignored — multi-value per axis is POST-only. |
| `page` | `pagination.cursor` | 1-indexed page; encoded to the opaque cursor internally (`encode_cursor()`). |
| `per_page` | `pagination.limit` | Results per page. Clamped to `[1, 100]` downstream. |

**Validation parity.** Price and `per_page` params are forwarded as their **raw string values**, not pre-cast to `int`. The shared `is_integer_like_non_negative()` validator (digit-only string or native int) then gates them exactly as on the POST path: `?min_price=abc` is rejected (the filter is dropped, not silently applied as `min=0`), `?min_price=19.99` is rejected rather than truncated to `19`, and `?per_page=abc` produces the validator's truthful "must be a non-negative integer" warning rather than a misleading "clamped from 0". This keeps GET and POST behaviorally identical for malformed numeric input.

**Response:** identical body + status to `POST /catalog/search`. The `X-WC-AI-Storefront-Unknown-Params` advisory header is not emitted on GET (there's no JSON body to inspect for unknown keys).

**Errors:** same as `POST /catalog/search` — `503 ucp_disabled` (the neutral core 503s before any work when syndication is paused), `429 ucp_rate_limit_exceeded`.

**Curl:**

```bash
# Bare query
curl 'https://your-store.com/wp-json/wc/ucp/v1/catalog/search?q=hoodie'

# Filtered: blue hoodies under 60 (store currency minor units)
curl 'https://your-store.com/wp-json/wc/ucp/v1/catalog/search?q=hoodie&attribute[color]=blue&max_price=6000&per_page=10'
```

### `GET /catalog/lookup`

Public, fetch-friendly variant of `POST /catalog/lookup`. Accepts `?ids=` (a comma-separated batch) **or** exactly one of `?id=<numeric_wc_id>` / `?slug=<product_slug>`, builds the same `$params` shape as the POST handler, and delegates to `run_catalog_lookup()`.

**Permission:** `check_agent_access` (see GET `/catalog/search` above).

**Query params:**

| Param | Maps to | Description |
|-------|---------|-------------|
| `ids` | `ids[]` | A comma-separated list of opaque UCP product/variant IDs (`prod_*`, `var_*`) — the same IDs `/catalog/search` returns — for batch lookup, e.g. `?ids=prod_1,var_2`. Whitespace around each ID is trimmed and empty entries dropped. Unresolved IDs come back as `not_found` messages in a partial-results envelope (HTTP 200), not a hard error. Capped at `MAX_IDS_PER_LOOKUP` (100); over the cap → `400 request_too_large`. A non-string value (e.g. array-style `?ids[]=`) → `400 invalid_input`; an empty/whitespace-only list → `400 invalid_input`. Takes precedence over `?id` / `?slug`. |
| `id` | `ids[0]` = `prod_<id>` | A positive integer WC product ID. `0`, negative, non-numeric → `400 invalid_input`. |
| `slug` | `ids[0]` = `prod_<resolved_id>` | A product slug, resolved via `get_page_by_path( $slug, OBJECT, 'product' )`. Must be a non-empty string. A slug that resolves to no published product → `404 not_found` (the resolved ID is dispatched through the Store API, which 404s drafts/private — so a non-public slug behaves identically to a nonexistent one, leaking no draft data). |

`?ids` takes precedence over both; `?id` takes precedence over `?slug`.

**Response:** same envelope as `POST /catalog/lookup`.

**Errors:** `503 ucp_disabled` — returned **before** any param validation when syndication is paused, so a paused store answers a malformed or missing-param GET lookup with a consistent `503` (matching POST) rather than leaking a `400`, and runs no `get_page_by_path()` query. `400 invalid_input` — `?ids` is non-string or empty, or neither `?id` nor `?slug` present, or a malformed `?id`/`?slug` value. `400 request_too_large` — `?ids` exceeds `MAX_IDS_PER_LOOKUP`. `404 not_found` — slug resolves to no published product. (Unresolved entries in a `?ids` batch are reported as `not_found` messages inside an HTTP 200 partial-results envelope, not a top-level error.)

**Curl:**

```bash
curl 'https://your-store.com/wp-json/wc/ucp/v1/catalog/lookup?ids=prod_42,var_99'
curl 'https://your-store.com/wp-json/wc/ucp/v1/catalog/lookup?id=42'
curl 'https://your-store.com/wp-json/wc/ucp/v1/catalog/lookup?slug=day-hoodie'
```

### `POST /catalog/lookup`

Look up specific products by ID.

**Permission:** `check_agent_access`.

**Request body:**

```json
{ "ids": ["prod_42", "prod_43", "var_99"] }
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ids` | string[] | yes | Array of UCP IDs in the standard grammar: `prod_<post_id>` for products (simple, variable parent, bundle, grouped), `var_<variation_id>` for real WC variations, `var_<post_id>_default` for synthesized default variants. Max 100 items. |

**Response:** same envelope as `/catalog/search` but with `products` matching requested IDs in order. Missing or excluded products are omitted (no per-ID error — agents diff against their request). Same UTM stamping on the product `url` field, and the same `?currency=XXX` multi-currency stamping (0.17.0+) when the request carries `context.currency` and the value is in `store_context.accepted_currencies` — see the note under `/catalog/search` above.

**Featured-variant precision** (#369). When an agent looks up a parent product ID (`prod_<digits>`) and the product has multiple variations, the response marks exactly one variant as `featured` in its `inputs[]` correlation, with the featured variant reordered to `variants[0]`. The selection rules:

- **Merchant signal present** — if the parent has `_default_attributes` covering every variation axis, the resolved variation is featured. Single source of truth: `WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id()`.
- **No merchant signal** — first variation by `menu_order` is featured (the Store API already orders variations by `menu_order`).
- **Sibling variants** emit with `inputs: [{"id": <input>}]` and no `match` field (spec-clean per `input_correlation.json` where `match` is optional).

Both signals must agree — `variants[0]` carries `match: featured` in its inputs entry. Per `product.json` (verbatim from `Universal-Commerce-Protocol/ucp`): _"First item is the featured variant for listings."_

This applies to both `variable` and `variable-subscription` parents. The merchant's `_default_attributes` is the signal we use; we do NOT auto-resolve it server-side in `/checkout-sessions` (see [Supported product types](#post-checkout-sessions) below for the design rationale).

**Errors:** `503` `ucp_disabled`; `400` `invalid_input` when `ids` is missing or empty; `400` `request_too_large` when `ids` exceeds 100 items.

### `POST /checkout-sessions`

Validate a cart and return a redirect URL for the buyer to complete checkout on the merchant site. **Stateless** — never persists anything.

The exact `continue_url` shape depends on the cart contents: simple/variation/subscription carts get `/checkout-link/?products=ID:QTY`; deterministic bundles or grouped products get `/checkout/?add-to-cart=…` with per-bundled-item / per-child params; configurable bundles, grouped, or variable/variable-subscription parents get the merchant PDP permalink. See [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md#layer-3--checkout-session-the-real-green-light) for the full URL-shape table.

**Supported product types** (#370):

| Type | At `/checkout-sessions` |
|---|---|
| `simple`, `variation`, `subscription`, `subscription_variation` | Accepted directly — Shareable Checkout `/checkout-link/?products=ID:1` |
| `bundle`, `grouped` | Accepted; deterministic shape when all defaults resolve, permalink fallback when configurable (any optional toggle, or any variable child without bundle-author-preset defaults) |
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

**Multi-currency stamping (0.17.0+).** When the request body carries `context.currency` and the value is a member of `store_context.accepted_currencies`, the returned `continue_url` carries `?currency=XXX` ahead of the UTM block. This activates WooPayments' built-in currency switcher on the destination page so the buyer sees the requested currency on the merchant's checkout. When `context.currency` is absent, malformed, or outside the accepted set, the URL is unchanged. Applies to every `continue_url` variant (Shareable Checkout, bundle `/checkout/?add-to-cart=…`, grouped, PDP permalink).

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
- `item_unpurchasable` (#373) — **per-line-item** rejection (`severity: unrecoverable`). Distinct from `out_of_stock`: the variation has stock but WC reports `is_purchasable: false` (typically missing a price, draft / hidden, or merchant-misconfigured). Same routing as `out_of_stock` — line dropped from `continue_url` / `line_items[]` / `totals`; surfaced as a `messages[]` entry. Distinct code so agents can route remediation correctly ("no inventory" vs "merchant config issue"). Catalog responses already filter unpurchasable variations upstream (see "Notable enrichment fields" above), but this code defends against stale or guessed variant IDs arriving at the checkout endpoint.
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

## Shopify-compatible products feed

> **Non-UCP, additive compatibility surface.** This feed is **not** part of UCP. It does not touch the UCP manifest, the UCP REST adapter, `/llms.txt`, `/agents.md`, or JSON-LD. It exists because AI agents are trained to probe Shopify's `/products.json` as the de-facto "give me the catalog as JSON" endpoint — so we answer that probe in the shape those agents parse zero-shot. The data is the same syndicated catalog the UCP surfaces expose, re-shaped. Design rationale: [`../superpowers/specs/2026-06-15-products-json-feed-design.md`](../superpowers/specs/2026-06-15-products-json-feed-design.md).

Class: [`WC_AI_Storefront_Products_Feed`](../../includes/ai-storefront/class-wc-ai-storefront-products-feed.php). Served on `template_redirect` via a rewrite rule (not `/wp-json`), so it is **edge-cacheable** — agent discovery bursts are absorbed by the CDN instead of tripping the platform per-origin rate limit, the same posture as the other discovery surfaces.

### `GET /products.json`
### `GET /collections/all/products.json`

Both URLs resolve to the **same all-products feed** (the second is an alias — the secondary catalog-probe path we observe — pointing at the same query var and handler). There is no per-collection filtering in v1; `/collections/all/products.json` returns the identical body to `/products.json`.

**Permission:** none — public read. The feed honors the merchant's syndication/visibility gate (only exposed products appear), but there is no agent allow-list check (unlike the UCP REST adapter). It's a public catalog mirror.

**Gating.** Returns **404** (`status_header(404)`) unless **all** of:

- `enabled === 'yes'` (plugin not paused), **and**
- `products_json_enabled === 'yes'` (the Discovery-tab toggle, default `'yes'`), **and**
- per product, the existing `WC_AI_Storefront::is_product_syndicated()` visibility/syndication gate — the same rule UCP and JSON-LD use. Non-syndicated products are silently dropped from the array.

**Query params:**

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `limit` | int | `30` | Per-page product count, Shopify-style. Clamped to a max of **250** (values above 250 → 250); `≤ 0` or non-numeric → the `30` default. |
| `page` | int | `1` | 1-based page number. `< 1` → `1`. An out-of-range page returns `{ "products": [] }` (Shopify behavior). |

**Response (200):** `application/json`. Shopify product shape (pragmatic full — the fields a trained parser keys on; Shopify-internal fields like `admin_graphql_api_id`, `template_suffix`, `published_scope` are omitted):

```json
{
  "products": [
    {
      "id": 22,
      "title": "Day Hoodie",
      "handle": "day-hoodie",
      "body_html": "<p>Heavyweight French terry.</p>",
      "published_at": "2026-01-15T10:30:00Z",
      "created_at": "2026-01-15T10:30:00Z",
      "updated_at": "2026-04-20T14:22:31Z",
      "vendor": "Saltwarp",
      "product_type": "Hoodies & Sweatshirts",
      "tags": "fleece, French terry",
      "variants": [
        {
          "id": 456,
          "title": "M",
          "option1": "M",
          "option2": null,
          "option3": null,
          "sku": "DH-M",
          "price": "48.00",
          "compare_at_price": null,
          "available": true,
          "requires_shipping": true
        }
      ],
      "images": [ { "id": 789, "src": "https://your-store.com/wp-content/uploads/day-hoodie.jpg" } ],
      "options": [ { "name": "Size", "position": 1, "values": ["S", "M", "L"] } ]
    }
  ]
}
```

**Field map (WC → Shopify):**

| Shopify field | WC source / rule |
|---|---|
| `id` | Product ID (int). |
| `title` | Product name, HTML-entity-decoded. |
| `handle` | Product slug. |
| `body_html` | Long description (`post_content`). |
| `published_at` / `created_at` | RFC 3339 UTC (`…Z`) string of the product's WC created date. Both carry the created date — WC has no separate publish date distinct from creation. **`null`** when the date is unset (an uninitialized / epoch-0 `WC_DateTime` is dropped rather than rendered as a misleading 1970 timestamp). |
| `updated_at` | RFC 3339 UTC string of the product's WC modified date; **`null`** when unset. |
| `vendor` | First `product_brand` term name, else **`null`** (genuinely absent when there's no brand). |
| `product_type` | A single string synthesized from the product's categories, in priority order: SEO-plugin primary category (Yoast `_yoast_wpseo_primary_product_cat` / RankMath `rank_math_primary_product_cat`) → deepest (most-specific) assigned `product_cat` term → first assigned term → **`""`** (Shopify always emits the key as a string). Note the deliberate divergence from `vendor`: empty string, not `null`. |
| `tags` | Comma-joined `product_tag` term names (Shopify emits a string, not an array). `""` when none. |
| `variants[]` | WC variations for a variable product; a **single default variant** for any non-variable product (simple / bundle / grouped), with `option1: "Default Title"` (Shopify convention). |
| `variants[].option1/2/3` | Variation attribute values in `options[]` order; unused slots `null`. Shopify supports exactly 3 option positions. |
| `variants[].sku` | Variation/product SKU. |
| `variants[].price` | Active price as a 2-decimal string (e.g. `"48.00"`); non-numeric → `"0.00"`. |
| `variants[].compare_at_price` | Regular price (2-decimal string) **when the product is on sale**, else `null`. |
| `variants[].available` | `is_in_stock() && is_purchasable()`. |
| `variants[].requires_shipping` | `needs_shipping()` (defaults `true` when the method is unavailable). |
| `variants[].grams` | Weight as an **integer** number of grams, converted from the store's configured `woocommerce_weight_unit` (so lbs/oz stores convert correctly rather than being read as kilograms). **`0`** when no weight is recorded — never `null`, never omitted. A variation with no weight of its own inherits the parent's, which is WooCommerce's intended behaviour for shipping weight. |
| `variants[].taxable` | `'taxable' === get_tax_status()`. WooCommerce has no per-variation tax status — the variation data store copies the parent's unconditionally — so this can never diverge from the product-level value. |
| `variants[].position` | 1-based index within `variants[]`. Taken from the loop order, **not** `get_menu_order()`: WooCommerce already returns children sorted by menu order, and its menu order is 0-based, so reading the raw value produces duplicate positions. |
| `variants[].product_id` | The variation's parent product id. For a simple product's synthesized variant, the product's own id. |
| `variants[].created_at` / `updated_at` | RFC 3339 UTC strings from the variation's WC created/modified dates; `null` when unset. |
| `variants[].featured_image` | The **full image record** (same struct as an `images[]` entry) for the photo assigned to this specific variation, else **`null`**. Resolved via `get_image_id( 'edit' )` — the `'edit'` context is required, because in `'view'` context `WC_Product_Variation` falls back to the parent's image and every photo-less variation would appear to own it. A simple product's synthesized variant is **always `null`**, matching Shopify: the field marks a photo specific to one variant, and a simple product has no sibling to differ from (its photos are already in `images[]`). |
| `images[]` | Sources, in order and de-duplicated: featured image, then gallery, then **images assigned to individual variations**. Entries with no resolvable URL are dropped. The single-product feed `/products/{handle}.json` emits them **all**; the **list feeds** (`/products.json` and `/collections/{handle}/products.json`) keep only the **first that resolves** — usually the featured image, but the first surviving gallery or variation-owned image when earlier ones fail — or `[]` when none does. Including variation-owned images is a deliberate divergence from a naive port: WooCommerce keeps them outside the parent gallery, whereas Shopify guarantees a variant's image is always one of the product's images, so without them `variant_ids` would be empty on every image. |
| `images[].src` | Full-size URL from `wp_get_attachment_image_url( $id, 'full' )`. |
| `images[].width` / `height` | From `wp_get_attachment_metadata()`; **`null`** when absent (SVGs and programmatically inserted attachments often carry neither). |
| `images[].position` | 1-based rank within the product's **full** resolved gallery. Assigned before any list-feed truncation, so the single image a list feed keeps reports its real gallery rank. |
| `images[].product_id` | Owning product id. |
| `images[].created_at` / `updated_at` | RFC 3339 UTC strings from the attachment's `post_date_gmt` / `post_modified_gmt`; `null` when unset (`0000-00-00` is dropped rather than rendered as year zero). |
| `images[].alt` | `_wp_attachment_image_alt`, HTML-entity-decoded; `null` when empty. **Deliberate divergence:** Shopify carries `alt` on `featured_image` but omits it from `images[]`. We emit it in both — alt text is often the only description of what is actually *in* a photo, which is exactly what a vision-less agent needs. |
| `images[].variant_ids` | Every variation using this image, which reverses WooCommerce's relation (each variation points at one image) into Shopify's (each image lists its variants). One colourway photo therefore lists all of its sizes. `[]` for an image no variation owns. |
| `options[]` | Variation attributes — `{ name, position, values }` with distinct decoded values. A product with nothing to choose emits Shopify's placeholder, `[ { "name": "Title", "position": 1, "values": ["Default Title"] } ]`, pairing with the `Default Title` variant on the same path. The key is **always present**: a client written against Shopify's shape assumes it exists, so omitting it makes `product.options.map(…)` throw and `product["options"]` raise `KeyError`. |

Per-product output can be overridden via the [`wc_ai_storefront_products_feed_product`](HOOKS.md#wc_ai_storefront_products_feed_product) filter (mirrors `wc_ai_storefront_ucp_product`).

**Headers.** `Content-Type: application/json; charset=utf-8`, `Cache-Control: public, max-age=N` (via `WC_AI_Storefront::discovery_cache_control()`, tunable through the [`wc_ai_storefront_discovery_cache_max_age`](HOOKS.md#wc_ai_storefront_discovery_cache_max_age) filter — shared with the other discovery surfaces), `Vary: Host`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex` (keeps the machine surface out of search indexes, matching `/opensearch.xml`), and CORS (`Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET, OPTIONS`). An `OPTIONS` preflight returns `204`. The same header set is sent by all five feed endpoints (`send_feed_headers()`).

**Caching.** Origin computes each `(limit, page)` page once and stores it in a host-scoped transient keyed by `md5( host | version | limit | page )` (TTL 1 hour). The `version` component is the `wc_ai_storefront_products_feed_version` option, bumped by `WC_AI_Storefront_Cache_Invalidator` on product save/delete and settings change — a single bump orphans every cached page at once, no key enumeration. See [`DATA-MODEL.md`](DATA-MODEL.md#wc_ai_storefront_products_feed_version).

**Curl:**

```bash
curl 'https://your-store.com/products.json?limit=2'
curl 'https://your-store.com/collections/all/products.json?limit=2'   # same body
```

### `GET /products/{handle}.json`

A single product by slug. Same gating, headers, and OPTIONS preflight as the bulk feed.

**Response (200):** `application/json`. Note the **singular `product` key holding an OBJECT** — not the bulk feed's `{ "products": [array] }`. The object uses the same `map_product()` mapper, field map, and timestamp fields above, with one deliberate difference: this single-product feed emits **all** of a product's images, where the list feeds emit only the first (see the `images[]` field-map row above):

```json
{
  "product": {
    "id": 22,
    "title": "Day Hoodie",
    "handle": "day-hoodie",
    "body_html": "<p>Heavyweight French terry.</p>",
    "published_at": "2026-01-15T10:30:00Z",
    "created_at": "2026-01-15T10:30:00Z",
    "updated_at": "2026-04-20T14:22:31Z",
    "vendor": "Saltwarp",
    "product_type": "Hoodies & Sweatshirts",
    "tags": "fleece, French terry",
    "variants": [ … ],
    "images": [ … ],
    "options": [ … ]
  }
}
```

**Resolution & 404.** The slug is resolved via `wc_get_products( slug, status=publish, visibility=catalog )` (so WC drops Hidden / Search-only products at the query) plus the per-product `is_product_syndicated()` gate. WC enforces unique product slugs, so the match is exact. The endpoint **404s** when the slug is unknown **OR** resolves only to a hidden/unsyndicated product — it must never `200` with a product body it would otherwise hide, so a hidden product's existence never leaks. A 404 is **not cached** (only a resolved body is).

**Caching.** A resolved body is cached under a host-scoped transient keyed `wc_ai_sf_prod_<md5( host | version | handle )>` (TTL 1 hour). No pagination component. Same shared `wc_ai_storefront_products_feed_version` integer as the bulk feed, so one bump orphans this family along with every other at once. See [`DATA-MODEL.md`](DATA-MODEL.md#wc_ai_sf_prod_md5-keyspace-family).

**Curl:**

```bash
curl 'https://your-store.com/products/day-hoodie.json'
```

### `GET /collections/{handle}/products.json`

The products in one `product_cat` category (by slug), in the bulk Shopify shape `{ "products": [ … ] }`. Same gating, headers, and OPTIONS preflight as the bulk feed.

**Query params:** `?limit` (default 30, max 250) and `?page` (1-based) — identical clamps to the bulk feed.

**Empty vs 404 rule.** Unlike the single-product endpoint, an unknown **OR** empty-after-gate category returns **`200 { "products": [] }`** (a uniform empty body), never a 404 — only the global gate (plugin/feed off) 404s. Uniform empties avoid leaking which category slugs exist. The same `visibility=catalog` + `is_product_syndicated()` gate applies per product, so a hidden product assigned to a visible category never appears here.

> The `^collections/(?!all/)…` rewrite uses a negative lookahead so `/collections/all/products.json` keeps resolving to the **bulk** feed (above), not this per-collection handler. A category genuinely slugged `all-weather` is unaffected — the lookahead matches only the exact `all/` segment.

**Caching.** Each `(handle, limit, page)` page is cached under a host-scoped transient keyed `wc_ai_sf_coll_<md5( host | version | handle | limit | page )>` (TTL 1 hour). An empty result is a valid, cached body; the shared version bump refreshes it (including when a previously-missing category is created). See [`DATA-MODEL.md`](DATA-MODEL.md#wc_ai_sf_coll_md5-keyspace-family).

**Curl:**

```bash
curl 'https://your-store.com/collections/hoodies/products.json?limit=2'
```

### `GET /collections.json`

The store's category list, in Shopify's collection shape `{ "collections": [ … ] }`. Same gating, headers, and OPTIONS preflight as the bulk feed. Unpaginated.

**Response (200):**

```json
{
  "collections": [
    {
      "id": 15,
      "handle": "hoodies",
      "title": "Hoodies",
      "body_html": "Heavyweight everyday hoodies.",
      "published_at": null,
      "updated_at": null,
      "products_count": 4
    }
  ]
}
```

**Field map (WC `product_cat` term → Shopify collection):**

| Shopify field | WC source / rule |
|---|---|
| `id` | Term ID (int). |
| `handle` | Term slug. |
| `title` | Term name, HTML-entity-decoded. |
| `body_html` | Term description (Shopify's collection-description slot). |
| `published_at` / `updated_at` | Always **`null`**. The `wp_terms` table carries no created/modified timestamps, and fabricating one (e.g. "now") would poison agent diff-sync — so the keys are present (Shopify always emits them) but null. |
| `products_count` | The **post-gate** count: catalog-visible **and** syndicated products in the category. |

**Inclusion rule.** Only categories with at least one catalog-visible, syndicated product are listed, and `products_count` is that same post-gate count — so `/collections.json` never advertises a category the per-collection endpoint would return empty for. (`get_terms( hide_empty => true )` pre-filters zero-product terms; the post-gate count then drops any remaining category whose products are all hidden/unsyndicated.)

Each collection can be overridden via the [`wc_ai_storefront_products_feed_collection`](HOOKS.md#wc_ai_storefront_products_feed_collection) filter (mirrors `wc_ai_storefront_products_feed_product`).

**Caching.** Cached under a host-scoped transient keyed `wc_ai_sf_colls_<md5( host | version )>` (TTL 1 hour, unpaginated). The per-category visible/syndicated count is the expensive part, so it's computed once per version bump and reused. See [`DATA-MODEL.md`](DATA-MODEL.md#wc_ai_sf_colls_md5-keyspace-family).

**Curl:**

```bash
curl 'https://your-store.com/collections.json'
```

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
| `period` | string | `month` | `day`, `week`, `month`, `quarter`, `year` |

**Response:**

```json
{
  "period": "month",
  "ai_orders": 42,
  "ai_revenue": 5400.00,
  "ai_aov": 128.57,
  "all_orders": 100,
  "all_revenue": 12800.00,
  "ai_share_percent": 42.0,
  "currency": "USD",
  "currency_symbol": "$",
  "by_agent": {
    "ChatGPT": { "orders": 24, "revenue": 3100.00 },
    "Perplexity": { "orders": 12, "revenue": 1500.00 },
    "Gemini": { "orders": 6, "revenue": 800.00 }
  },
  "top_agent": {
    "name": "ChatGPT",
    "orders": 24,
    "revenue": 3100.00,
    "share_percent": 57.1
  },
  "by_channel": {
    "woo_ucp":    { "orders": 30, "revenue": 4200.00, "share_percent": 78.9 },
    "woo_jsonld": { "orders":  8, "revenue":  900.00, "share_percent": 21.1 }
  },
  "top_channel": "woo_ucp"
}
```

**Field notes:**

- `ai_revenue` and per-agent `revenue` are floats in the store's currency. `currency_symbol` is the decoded symbol (`$`, `€`, `£`) or empty when unavailable; `currency` is always the ISO 4217 code.
- `top_agent` is `null` when there are no AI orders in the period. Tie-break: `orders DESC, revenue DESC, name ASC` (stable across snapshots).
- `by_channel` (0.15.0+) splits AI orders by their stamped `utm_id`. Keys are limited to `woo_ucp` (orders from `/checkout-sessions` continue_url, the live-UCP-session channel) and `woo_jsonld` (orders from JSON-LD `PotentialAction` URL templates, the search-result-channel). Legacy LENIENT-attributed orders (host-match without a stamped `utm_id`) do not appear here. `share_percent` is rounded to 1 decimal and **self-normalizes against the channel-known subset**, not against `ai_orders` — the two rows always sum to 100.
- `top_channel` (0.15.0+) is the dominant channel utm_id (`woo_ucp` or `woo_jsonld`) or `null` when `by_channel` is empty. Tie-break: `orders DESC, revenue DESC, key ASC`.

**Transient cache:** results are cached for 5 minutes under the key `wc_ai_storefront_stats_v2_<period>`. The `_v2_` suffix forces a one-shot cache miss on upgrade from pre-0.15 so v1-shaped payloads can't propagate to v2 frontend reads. The cache is busted on AI-attributed order status changes via `bust_stats_cache()`, which deletes both the current `_v2_` key and the legacy `_<period>` key during the transition window.

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

Aggregated crawler-visibility stats for the Discovery tab. Reads from the summary table (`{prefix}wc_ai_storefront_crawl_summary`) — refreshed on every rollup run (hourly by default, overridable via the `wc_ai_storefront_rollup_interval` filter). Today's in-progress events appear within one rollup cycle. The rolled-up aggregates are cached for 5 minutes via the `wc_ai_storefront_crawl_stats_{period}` transient. Two fields — `rollup_interval` and `raw_event_count` — are explicitly excluded from the transient and queried live on every response so a filter change or a fresh raw-log hit takes effect immediately, without waiting for the cache to expire. The `/.well-known/ucp` and `/llms.txt` discovery surfaces are edge-cached and no longer logged per request, so every count here — `total_requests`, the `by_agent` request totals/rankings, and (via its denominator) `throttle_rate` — reflects only AI requests that reached origin and excludes those two surfaces' fetches.

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

`throttle_rate` is `(throttle_count / total_requests) * 100` rounded to one decimal place; returns `0.0` when `total_requests` is zero. (Because `total_requests` excludes the edge-cached `/.well-known/ucp` and `/llms.txt` fetches, the denominator — and thus this rate — covers only origin-reaching AI requests.) `top_queries[].agents` is the deduplicated list of agents that issued each search term in the period.

`top_queries_window_days` is the effective lookback for `top_queries` (always `min(period_days, RAW_RETENTION_DAYS=30)`). Top searches read from the raw log (query strings aren't aggregated into the summary table), and the raw log retains only 30 days, so for `period=quarter` (90d) this value is `30` while every other field reflects the full 90-day period.

`raw_event_count` is `COUNT(*)` over the raw log (`{prefix}wc_ai_storefront_crawl_log`) for the requested period. This field is **not cached** — it runs on every response, before the transient cache check, so brand-new traffic (product-page hits, Store API queries) becomes visible immediately even if the rollup hasn't fired yet. (The `/.well-known/ucp` and `/llms.txt` discovery surfaces are edge-cached and no longer logged per request, so they don't contribute raw events.) The Discovery tab's empty-state guard checks this in addition to `total_requests` and `top_queries`, so a fresh install that already has raw activity doesn't falsely render "No AI shopping-API activity recorded".

`rollup_interval` is the validated cron recurrence slug currently in use: one of `"hourly"` (default), `"twicedaily"`, or `"daily"`. This is the value returned by `WC_AI_Storefront_Crawl_Logger::get_effective_rollup_interval()` — the same logic used by `schedule_crons()`. Like `raw_event_count`, this field is **not cached** in the transient — it's injected live on every response (cache-hit and fresh paths alike) so a `wc_ai_storefront_rollup_interval` filter change is reflected on the very next request. Clients use this to render a specific subtitle ("Updated hourly.", "Updated every 12 hours.", "Updated daily.") rather than a generic fallback.

### `POST /regenerate-indexnow-key`

Generates a new IndexNow ownership key and persists it, replacing the existing one. The old key file path (`/{old_key}.txt`) immediately returns 404; the new key is served at `/{new_key}.txt`.

**Permission:** `manage_woocommerce`.

**Body:** none.

**Response:**

```json
{
  "indexnow_key": "a1b2c3d4e5f6789012345678901234ab"
}
```

`indexnow_key` is the new key string (a 32-character lowercase hex token). The key file is served publicly at `https://your-store.com/{indexnow_key}.txt` (plain text, the key value only, HTTP 200 with no redirect). Engines fetch that file to confirm site ownership before trusting submissions.

### `POST /indexnow-submit-all`

Submits every published product URL, every non-empty product-category URL, every non-empty product-brand (`product_brand`) URL, and the discovery surfaces (homepage, `/shop/`, `/llms.txt`, `/products.json`) to IndexNow, then returns the stored result of that submission.

The work runs **in-request**, not on cron: the handler calls `WC_AI_Storefront_IndexNow::submit_all()` directly, so the response reflects a submission that has already happened. This route backs the "Submit entire catalog now" button on the Discovery tab. The automatic first-enable seed performs the same submission but reaches it differently — through the one-shot [`wc_ai_storefront_indexnow_submit_all`](DATA-MODEL.md#wc_ai_storefront_indexnow_submit_all) cron, so that it runs after the settings save commits.

**Permission:** `manage_woocommerce`.

**Body:** none.

**Response:**

```json
{
  "indexnow_last_result": {
    "time": "2026-06-23T14:07:02Z",
    "count": 62,
    "code": 200,
    "ok": true
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `time` | string | ISO 8601 UTC timestamp of the submission. |
| `count` | int | Number of URLs included in the submission batch. |
| `code` | int | HTTP response code returned by the IndexNow endpoint. `200` = accepted and key validated; `202` = accepted, key validation pending; `4xx`/`5xx` = error. |
| `ok` | bool | `true` when `code` is 200 or 202 (submission reached the engine); `false` on network or server error. |

### `GET /{key}.txt` (public key file)

Not an Admin REST route — served via a rewrite rule at the virtual path `/{key}.txt`, where `{key}` is the stored `indexnow_key` option value. Returns the key as plain text (`text/plain`) with HTTP 200 and no redirect. Engines fetch this to complete ownership verification after a submission. Returns 404 for any path that does not match the current key.

---

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — component overview and design rationale
- [`UCP-BUY-FLOW.md`](UCP-BUY-FLOW.md) — how an agent decides to render a Buy CTA from the discovery layers
- [`DATA-MODEL.md`](DATA-MODEL.md) — options, transients, and meta keys this API reads/writes
- [`HOOKS.md`](HOOKS.md) — filters and actions extending plugins can use
- [`TESTING.md`](TESTING.md) — how to test endpoints without a live WP install
