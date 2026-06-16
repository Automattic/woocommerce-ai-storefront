# Shopify-Compatible `/products.json` Catalog Feed — Design

**Issue:** [#449](https://github.com/Automattic/woocommerce-ai-storefront/issues/449)
**Status:** approved design, pending implementation plan
**Tier:** 3 (discovery enrichment)

## Why

AI agents are trained to probe Shopify's `/products.json` as the first "give me the catalog as JSON" endpoint — a de-facto convention created by Shopify's scale, not a standard. Validated live: asked only *"store at saltwarp.shop, get the full catalog as raw JSON, what URL first?"* (no mention of Shopify), ChatGPT auto-titled the chat "Shopify Product Catalog API" and returned, in priority order, `/products.json?limit=250`, `/collections/all/products.json?limit=250`, `/collections.json?limit=250`. It did not reach for the WC Store API, the sitemap, or our UCP/llms.txt — and saltwarp 404s on all three.

The **URL** gets the probe; the **shape** gets the parse. An agent expecting Shopify's structure (`variants[].price`, `available`, `images[].src`, `handle`, `body_html`, `vendor`, `option1/2/3`) can extract zero-shot only if the response matches that structure. Returning our own shape at the URL yields a `200` the agent then silently fails to parse. So we serve the Shopify shape at the probed endpoints.

This is a `GET` on a rewrite path (not `/wp-json`), so it is **edge-cacheable** — it doubles as the ingestion/scaling feed from the performance retrospective (Unit 2). It is **explicitly non-UCP, additive compatibility surface**: it does not replace or alter our UCP manifest, REST/MCP endpoints, llms.txt, or JSON-LD.

## Locked decisions

1. **Opt-in:** a Discovery-tab toggle `products_json_enabled`, **default `'yes'`** for syndicated stores. Works out of the box (the probe succeeds without config); a merchant who does not want a bulk feed can switch it off.
2. **Field fidelity:** pragmatic full shape — populate the fields a trained parser keys on; omit Shopify-internal fields with no WC meaning (`admin_graphql_api_id`, `template_suffix`, `published_scope`).
3. **v1 scope:** `/products.json` (the #1 probe) + `/collections/all/products.json` (the #2 probe, aliased to the same all-products handler). Defer `/collections.json`, `/products/{handle}.json`, `/collections/{handle}/products.json` to v2.

## Architecture

A new class **`WC_AI_Storefront_Products_Feed`** in `includes/ai-storefront/`, structured parallel to `WC_AI_Storefront_Llms_Txt` and `WC_AI_Storefront_Ucp` (the established rewrite-endpoint pattern). Three internal units:

1. **Endpoint** — rewrite rules, query var, `serve_products_feed()` on `template_redirect`, cache read/write. Mirrors `serve_llms_txt()`.
2. **Mapper** — `map_product( WC_Product $product ): array` translating one WC product to the Shopify product shape. Sourced **directly from WC** (`wc_get_products()` + product objects), not the UCP translator, because the Shopify shape (`body_html`, `option1/2/3`, `vendor`) diverges enough that a dedicated mapper is cleaner than bending the UCP one.
3. **Setting** — `products_json_enabled` (default `'yes'`) surfaced as a Discovery-tab toggle.

### Endpoints (v1)

| Rewrite | Query var | Handler |
|---|---|---|
| `^products\.json$` | `wc_ai_storefront_products_json=1` | `serve_products_feed()` |
| `^collections/all/products\.json$` | `wc_ai_storefront_products_json=1` (same) | same |

Both resolve to the same all-products feed; the alias is a second `add_rewrite_rule` pointing at the same query var. Canonical-redirect suppression extended to this query var (mirroring llms.txt/agents.md).

### Data flow

```
request → query var set → gate (enabled + syndication + products_json_enabled)
       → get_cached_feed( limit, page )
            → [cache hit]  echo
            → [cache miss] wc_get_products( syndicated set, paginated )
                         → map_product() per product
                         → wrap { "products": [...] } → json_encode
                         → cache (per (limit,page)) → echo
```

### Pagination

- `?limit=` — default **30**, max **250** (Shopify's cap; values above 250 clamp to 250, ≤0 → default).
- `?page=` — 1-based; out-of-range pages return `{ "products": [] }` (Shopify behavior).
- Each `(limit, page)` pair is a distinct cache entry.

### Shopify product shape (pragmatic full)

```json
{
  "products": [
    {
      "id": 123,
      "title": "Day Hoodie",
      "handle": "day-hoodie",
      "body_html": "<p>...</p>",
      "published_at": "2026-05-01T00:00:00-07:00",
      "created_at": "...",
      "updated_at": "...",
      "vendor": "Saltwarp",
      "product_type": "Hoodies & Sweatshirts",
      "tags": "fleece, French terry, Heavyweight",
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
      "images": [ { "id": 789, "src": "https://.../day-hoodie.jpg" } ],
      "options": [ { "name": "Size", "position": 1, "values": ["S","M","L"] } ]
    }
  ]
}
```

WC → Shopify field map:

| Shopify | WC source |
|---|---|
| `id` | product ID |
| `title` | product name |
| `handle` | slug |
| `body_html` | long description (post_content) |
| `vendor` | first `product_brand` term, else `null` |
| `product_type` | a single string synthesized from the product's categories — see [`product_type` mapping](#product_type-mapping) below |
| `tags` | comma-joined `product_tag` terms (Shopify emits a string) |
| `variants[]` | WC variations; simple product → one default variant |
| `variants[].option1/2/3` | variation attribute values, in `options[]` order |
| `variants[].price` / `compare_at_price` | active price / regular price when on sale (else null) |
| `variants[].available` | `is_in_stock() && is_purchasable()` |
| `images[]` | featured + gallery image URLs |
| `options[]` | variation attributes (`name`, `position`, distinct `values`) |

`null` for fields with no WC value (e.g. unused `option2/3`). Simple products emit a single variant with `option1: "Default Title"` (Shopify convention) and no `options[]`.

### `product_type` mapping

Shopify's `product_type` is a **single free-text string** naming the product's specific type (e.g. "Hoodie"). It is *not* the same as Shopify **collections** — a product's many groupings, which map to WC categories and surface via the deferred `/collections/*` endpoints. Two WC mismatches make this non-trivial:

- WC has no single "product type" text field. (WC's own `product_type` means simple / variable / grouped — an unrelated concept; do **not** use it.)
- A WC product can belong to several `product_cat` terms, so there is no 1:1 source.

We therefore synthesize a single string from the product's categories, in this priority order (data-sourcing-first, mirroring how `sameAs` reuses SEO-plugin data):

1. **Merchant-designated primary category**, if an SEO plugin set one — Yoast `_yoast_wpseo_primary_product_cat` meta or RankMath `rank_math_primary_product_cat` meta (guarded; absent on most stores). Respects explicit merchant intent.
2. Else the **most-specific assigned category** — the `product_cat` term with the greatest hierarchy depth (prefer "Hoodies" over its parent "Tops"), which best mimics Shopify's specific-type convention. Ties broken by `menu_order`, then term ID.
3. Else the **first assigned** `product_cat` term.
4. Else `""` (Shopify permits an empty `product_type`).

The chosen term name is decoded to plain text (no HTML entities), matching the other text fields. Note this deliberately diverges from the `vendor`/null choice: `product_type` falls back to `""` (not `null`) because Shopify always emits the key as a string, whereas `vendor` is genuinely absent when there is no brand.

### Gating

- 404 (`status_header(404)`) when the plugin is disabled, syndication is off, or `products_json_enabled !== 'yes'`.
- Product set honors the existing visibility/syndication gate (the same `is_product_syndicated()` rule UCP and JSON-LD use) — only exposed products appear.

### Caching, invalidation, edge

- **Origin:** per-`(limit, page)` transient keyed by host (reuse the `host_cache_key()` pattern) so multi-domain installs do not cross-pollinate.
- **Edge:** `Cache-Control: public, max-age=N` (via `WC_AI_Storefront::discovery_cache_control()`), `Vary: Host`, `X-Content-Type-Options: nosniff`, CORS, OPTIONS→204 — identical to the other discovery surfaces, so the Atomic edge caches it.
- **Invalidation:** a **versioned cache-key prefix** — an integer `products_feed_version` option is part of every cache key. On product save/delete (`save_post_product`, `woocommerce_update_product`, `woocommerce_delete_product`) and settings change (`update_option_<settings>`), bump the version (one option write). All old `(limit,page)` entries are immediately orphaned (no key-list to track) and expire via their TTL. This is the only sane way to invalidate a paginated, unbounded key space.

### Settings

Add `products_json_enabled` to the settings schema (default `'yes'`, validated to `'yes'|'no'`) and a Discovery-tab checkbox: "Serve a Shopify-compatible `/products.json` catalog feed (helps AI agents that probe that endpoint find your catalog)."

## Documentation

The implementation plan must include a dedicated documentation task covering:

- `docs/engineering/API-REFERENCE.md` — the new `/products.json` + `/collections/all/products.json` endpoints: params (`limit`/`page` + caps), the Shopify response shape, gating, and caching.
- `docs/engineering/ARCHITECTURE.md` — the `WC_AI_Storefront_Products_Feed` component and its place in the discovery-surfaces list.
- The **discovery-surfaces enumeration** (wherever `/llms.txt`, `/.well-known/ucp`, `/agents.md`, `/opensearch.xml` are listed) — add `/products.json`, clearly flagged as a non-UCP Shopify-compat surface.
- `docs/engineering/DATA-MODEL.md` — the `products_json_enabled` setting and the `products_feed_version` invalidation option.
- `docs/engineering/HOOKS.md` — any new filter (a per-product `wc_ai_storefront_products_feed_product` override is likely worth adding, mirroring `wc_ai_storefront_ucp_product`; confirm in the plan).
- `docs/user-guide/USER-GUIDE.md` — the merchant-facing Discovery-tab toggle (what it does, why an agent benefits).
- `CHANGELOG.md` + `readme.txt` — the `### Features` / `**New**` entry, ending `Closes #449.`
- `docs/engineering/KNOWN-GAPS.md` — record the deferred v2 endpoints and the proprietary-format-tracking caveat.

## Testing

- **Mapper** (`ProductsFeedMapperTest`): simple product → single default variant; variable product → variants with correct `option1/2/3` ordering; on-sale product → `price`/`compare_at_price`; out-of-stock → `available: false`; brand/category/tags mapping; images; `body_html`.
- **Endpoint** (`ProductsFeedTest`): gate (404 when disabled / syndication off / toggle off); headers (content-type `application/json`, `Cache-Control`, `Vary: Host`); pagination (`limit` clamp to 250, `?page` out-of-range → empty `products`); alias endpoint returns the same body; OPTIONS→204.
- **Cache:** second request hits the cache; product save invalidates.

## Non-goals (out of scope for v1)

- `/collections.json`, `/products/{handle}.json`, `/collections/{handle}/products.json` (v2).
- Any change to UCP, MCP, llms.txt, JSON-LD, or the OpenRPC manifest question (tracked separately).
- Shopify Storefront/Admin GraphQL compatibility.

## Risks

- **Proprietary-format tracking:** Shopify's shape is stable but external; we track it best-effort. Mitigated by the "pragmatic full" subset (the fields agents actually use) rather than chasing every internal field.
- **Scrape-ability:** a bulk feed is easier to scrape than per-page — but the data is already public via the storefront and UCP, so there is no new exposure. The toggle gives merchants an off switch.
- **Not impersonation:** no Shopify branding or trademarks; serving a widely-consumed open JSON shape at a conventional path.
