# PLAN: UCP REST Adapter (Plugin v1.3.0)

**Status**: Draft — awaiting implementation.
**Target version**: 1.3.0
**Scope**: Build UCP-compliant REST endpoints for the `dev.ucp.shopping.catalog` and `dev.ucp.shopping.checkout` capabilities, hosted at `/wp-json/wc/ucp/v1/`.

This document replaces ad-hoc planning conversation with a durable design record. All claims in this plan are grounded in the UCP spec schemas fetched during authorship — specifically:

- `source/schemas/ucp.json`
- `source/discovery/profile_schema.json`
- `source/schemas/shopping/types/product.json`
- `source/schemas/shopping/types/variant.json`
- `source/schemas/shopping/types/search_filters.json`
- `source/schemas/shopping/types/price_filter.json`
- `source/schemas/shopping/types/line_item.json`
- `source/schemas/shopping/catalog_search.json`
- `source/schemas/shopping/checkout.json`
- `docs/specification/overview.md`
- `docs/specification/checkout.md`
- `docs/specification/checkout-rest.md`
- `docs/specification/catalog/rest.md`

Where this plan differs from external assistants (like Gemini) that produced plausible-looking JSON with hallucinated fields, the plan matches the schema.

---

## Deliverable

Plugin 1.3.0 ships:

1. **New REST namespace** at `/wp-json/wc/ucp/v1/` with three routes:
   - `POST /catalog/search` — query products (UCP `dev.ucp.shopping.catalog.search`)
   - `POST /catalog/lookup` — get specific products by ID (UCP `dev.ucp.shopping.catalog.lookup`)
   - `POST /checkout-sessions` — one-shot stateless handoff (UCP `dev.ucp.shopping.checkout` create)

2. **Updated `/.well-known/ucp` manifest** declaring `dev.ucp.shopping.catalog` and `dev.ucp.shopping.checkout` capabilities, with service endpoint pointing at `/wp-json/wc/ucp/v1/` (not Store API).

3. **Server-side product filtering** via `woocommerce_store_api_product_collection_query_args` hook so the existing `selected_categories` / `selected_products` plugin settings actually restrict what UCP agents see.

4. **UCP-Agent header parsing** for agent identification + attribution.

5. **`Allow: /wp-json/wc/ucp/` in robots.txt** for every allowed crawler.

---

## Posture: stateless, redirect-only

The plugin's product principle is unchanged: merchants own the checkout, AI agents are referrers. Under UCP's vocabulary this means:

- **Declare** catalog + checkout capabilities (agents can discover products and initiate purchase flow)
- **Do NOT declare** cart capability (agent holds items in its own memory; we don't persist carts server-side)
- **Do NOT declare** Identity Linking, Order, Fulfillment, Discount, Payment Token Exchange capabilities
- **Empty `payment_handlers`** (merchant's WC checkout handles payment via whatever gateway they configured)
- **`status: requires_escalation`** on every checkout session create response, with `continue_url` pointing at the merchant's Shareable Checkout URL
- **No `GET`/`PUT`/`POST /complete`/`POST /cancel` on checkout-sessions** — the session exists only for the duration of the create response; once the agent redirects the user, WooCommerce takes over and our session dies

---

## Schema-grounded field reference

### Required fields on every product (UCP spec)

Per `types/product.json`, `required: ["id", "title", "description", "price_range", "variants"]`. Critical: `variants` has `minItems: 1` — every product MUST emit at least one variant, even simple products without variations.

| UCP field | Required? | v1 source |
|---|---|---|
| `id` | **Yes** | `"prod_" . $wc_product->get_id()` |
| `title` | **Yes** | `$wc_product->get_name()` |
| `description` | **Yes** | `{ "plain": wp_strip_all_tags + html_entity_decode of short_description }` |
| `price_range` | **Yes** | `{min: {amount, currency}, max: {amount, currency}}` in minor units |
| `variants` | **Yes** (minItems 1) | For simple: 1 variant mirroring the product. For variable: N variants, one per WC variation. |
| `handle` | No | `$wc_product->get_slug()` |
| `url` | No | `$wc_product->get_permalink()` |
| `categories` | No | `[{value: $cat->name, taxonomy: "merchant"}, ...]` |
| `media` | No | `[{type: "image", url, alt_text}, ...]` from image + gallery |
| `options` | No | For variable products only; aggregate attribute values |
| `rating`, `tags`, `metadata`, `list_price_range` | No | Skip in v1 |

### Required fields on every variant (UCP spec)

Per `types/variant.json`, `required: ["id", "title", "description", "price"]`.

| UCP field | Required? | v1 source |
|---|---|---|
| `id` | **Yes** | For simple product: `"var_" . $wc_id . "_default"`. For variation: `"var_" . $wc_variation_id`. |
| `title` | **Yes** | Variation attributes joined ("Blue / Large") or product title for simple products |
| `description` | **Yes** | `{plain: ""}` if no variant-specific description; otherwise variation description |
| `price` | **Yes** | `{amount: int_minor_units, currency: ISO_4217}` |
| `sku` | No | WC variation SKU (or parent SKU for simple products) |
| `availability` | No | `{available: bool, status: "in_stock"/"out_of_stock"}` |
| `options` | No | For variation: `[{name: "Color", value: "Blue"}, ...]` (the `selected_option` shape) |
| `media`, `barcodes`, `unit_price`, `seller`, `tags`, `metadata` | No | Skip in v1 |

### Required fields on every checkout response (UCP spec)

Per `shopping/checkout.json`, `required: ["ucp", "id", "line_items", "status", "currency", "totals", "links"]`.

| UCP field | Required? | v1 source |
|---|---|---|
| `ucp` | **Yes** | `response_checkout_schema` wrapper |
| `id` | **Yes** | `"chk_" . wp_generate_password(16, false)` |
| `line_items` | **Yes** | Echoed back from request, enriched with prices + totals per line |
| `status` | **Yes** | `"requires_escalation"` (always, for our redirect-only model) |
| `currency` | **Yes** | `get_woocommerce_currency()` (ISO 4217) |
| `totals` | **Yes** | Array of `{type, amount}` — at minimum `[{type: "subtotal", amount}]` |
| `links` | **Yes** | Must include Privacy Policy + TOS at minimum (legal compliance) |
| `messages` | Recommended | Explain the redirect: `[{type: "error", code: "buyer_handoff_required", severity: "requires_buyer_input", content: "Complete your purchase on the merchant site."}]` |
| `continue_url` | **MUST when status is requires_escalation** | Shareable Checkout URL + utm |
| `expires_at`, `buyer`, `context`, `signals` | No | Skip in v1 |

### Search request/response (UCP spec)

Per `catalog_search.json`:

**Request** accepts: `query` (string), `context`, `signals`, `filters: {categories: [], price: {min, max}}`, `pagination`.

v1 implements: `query`, `filters.categories`, `filters.price`. Skip `context`, `signals`, `pagination` (defer to 1.4.0 if agents need them).

**Response** requires: `ucp`, `products`. Optional: `pagination`, `messages`.

---

## Critical discovery-time bug fixes

### 1. Price is already integer minor units

WC Store API's product response has:
```json
"prices": {
  "price": "12000",           // string, already in minor units
  "currency_code": "USD",
  "currency_minor_unit": 2,
  "price_range": { "min_amount": "10000", "max_amount": "15000" }  // variable products
}
```

Translation: `(int) $wc['prices']['price']`. Never `* 100`, never `round()`, never float math. JPY / BHD / other non-2-decimal currencies Just Work because WC already computed correctly.

### 2. `payment_handlers` must be object, not array

PHP: `(object) []` so `wp_json_encode` produces `{}` not `[]`. Schema requires object shape.

### 3. `links` is mandatory on checkout responses

Source: `shopping/checkout.json` line listing `required: ["ucp", "id", "line_items", "status", "currency", "totals", "links"]` + description: "Mandatory for legal compliance."

v1 implementation: read `get_privacy_policy_url()` from WordPress and `wc_get_page_permalink('terms')` from WooCommerce. If either is missing, emit empty `links` and add a warning message explaining the merchant should configure those pages.

---

## Module layout

```
includes/ai-syndication/ucp-rest/
├── class-wc-ai-syndication-ucp-rest-controller.php   # Register routes, dispatch
├── class-wc-ai-syndication-ucp-product-translator.php # WC product → UCP product
├── class-wc-ai-syndication-ucp-variant-translator.php # WC variation → UCP variant
├── class-wc-ai-syndication-ucp-envelope.php           # Build response envelopes
├── class-wc-ai-syndication-ucp-agent-header.php       # Parse UCP-Agent header
└── class-wc-ai-syndication-ucp-store-api-filter.php   # Server-side product filter

tests/php/unit/
├── UcpRestControllerTest.php
├── UcpProductTranslatorTest.php
├── UcpVariantTranslatorTest.php
├── UcpEnvelopeTest.php
├── UcpAgentHeaderTest.php
└── UcpStoreApiFilterTest.php
```

### Checkout handoff uses WC's native Shareable Checkout URLs

WooCommerce's built-in `/checkout-link/?products=ID:QTY` feature handles the cart-construction-and-redirect flow we need. Verified on pierorocca.com: a URL like `/checkout-link/?products=123:1` adds the product to the cart and redirects to checkout. No custom handler needed — our plugin's only job at the handoff point is emitting a correctly-formatted URL as `continue_url` in the checkout-sessions response.

Minimum WooCommerce version requirement (not yet verified): the Shareable Checkout URLs feature was added in WC ~9.x. Since the plugin already declares `WC requires at least: 9.9`, we're safely above the threshold.

**Modified**:
- `includes/class-wc-ai-syndication.php` — load new classes, register controller on `rest_api_init`
- `includes/ai-syndication/class-wc-ai-syndication-ucp.php` — manifest: new endpoint, declare capabilities, update PROTOCOL_VERSION to `2026-04-08`
- `includes/ai-syndication/class-wc-ai-syndication-robots.php` — add `Allow: /wp-json/wc/ucp/`
- `woocommerce-ai-syndication.php` — version → 1.3.0
- `readme.txt` — changelog
- `languages/woocommerce-ai-syndication.pot` — regenerate
- `AGENTS.md` — document the UCP adapter module

---

## Route: `POST /catalog/search`

### Request
```json
{
  "query": "blue shirt",
  "filters": {
    "categories": ["clothing", "tops"],
    "price": { "min": 1000, "max": 5000 }
  }
}
```

### Algorithm
1. Read `UCP-Agent` header for attribution (stored for logging, not used in response)
2. Parse request body → UCP search request
3. Map UCP fields to Store API query params:
   - `query` → `search`
   - `filters.categories` → `category` (comma-separated slugs or IDs — WC supports both)
   - `filters.price.min` → `min_price`, converting from minor units to presentment units
   - `filters.price.max` → `max_price`
4. Call `rest_do_request( new WP_REST_Request('GET', '/wc/store/v1/products') )` with translated params
5. `rest_do_request` respects our `woocommerce_store_api_product_collection_query_args` filter so selected_categories/products filtering is automatic
6. For each returned product, translate via `UcpProductTranslator::translate()`
7. For variable products, fetch variations via additional `rest_do_request` calls and translate via `UcpVariantTranslator::translate()`
8. Wrap in UCP envelope: `{ucp: {...response_catalog_schema...}, products: [...]}`
9. Return 200 OK

### Errors
- Invalid `filters.price` shape → HTTP 400 + UCP error message
- Store API dispatch fails → HTTP 500 + UCP error with code `internal_error`

---

## Route: `POST /catalog/lookup`

### Request
```json
{
  "ids": ["prod_123", "prod_456"]
}
```

(The UCP spec is less prescriptive about lookup request shape; we use `ids` array matching the lookup response's `products` array pattern.)

### Algorithm
1. Parse `ids`, strip `"prod_"` / `"var_"` prefix from each
2. For each ID: `rest_do_request( GET /wc/store/v1/products/{id} )`
3. Translate each response; accumulate into `products` array
4. For missing products: add message `{type: "error", code: "not_found", path: "$.ids[N]", severity: "unrecoverable"}`
5. Wrap in UCP envelope, return 200

---

## Route: `POST /checkout-sessions`

### Request
```json
{
  "line_items": [
    { "item": { "id": "var_123" }, "quantity": 2 },
    { "item": { "id": "var_456" }, "quantity": 1 }
  ]
}
```

Note: `item.id` can be either `prod_N` (simple product) or `var_N` (specific variation). We handle both.

### Algorithm
1. Parse `UCP-Agent` header → extract profile URL hostname → `$agent_host`
2. Parse `line_items` from request
3. For each line item:
   - Strip `prod_` / `var_` prefix to get WC ID
   - `rest_do_request( GET /wc/store/v1/products/{id} )` to validate + get price
   - If not found: add error message
   - If out of stock: add warning
   - Compute line total (price × quantity, in minor units)
4. Build `continue_url` using WooCommerce's Shareable Checkout URL format (per https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/):

   | Case | Template | v1 handling |
   |---|---|---|
   | Single simple product | `/checkout-link/?products=ID:QTY` | Strip `prod_` prefix from `item.id` |
   | Single variable product | Same — but pass variation ID | Strip `var_` prefix from `item.id` |
   | Multiple products | `/checkout-link/?products=ID:Q,ID:Q` | Comma-join all line_items |

   Final URL (composite):
   ```
   {site_url}/checkout-link/?products={id1}:{qty1},{id2}:{qty2}&utm_source={agent_host}&utm_medium=ai_agent
   ```

   **Coupons are out of scope.** Promo codes are applied on the merchant's checkout page after redirect — not relevant to the AI handoff. If a user mentions a coupon to the agent, the agent should tell them to enter it on the merchant's checkout page.

   **Parent variable product edge case**: if agent sends `item.id = "prod_N"` where N is the parent of a variable product (not a specific variation), Shareable Checkout URLs cannot add it — WC needs a variation ID. v1 returns an error message for that line item: `{type: "error", code: "variation_required", path: "$.line_items[N].item.id", severity: "unrecoverable", content: "Product is variable — specify a variation ID instead of the parent product ID."}` and does NOT include it in `continue_url`.
5. Fetch legal links:
   - Privacy Policy: `get_privacy_policy_url()` — fall back to omit with warning if not set
   - Terms: `wc_get_page_permalink('terms')` — fall back to omit with warning if not set
6. Assemble UCP envelope:
   ```php
   [
     'ucp' => [
       'version' => '2026-04-08',
       'status' => 'success',
       'capabilities' => [
         'dev.ucp.shopping.checkout' => [ ['version' => '2026-04-08'] ]
       ],
       'payment_handlers' => (object) [],
     ],
     'id' => 'chk_' . bin2hex( random_bytes(8) ),
     'status' => 'requires_escalation',
     'currency' => get_woocommerce_currency(),
     'line_items' => [ ... ],
     'totals' => [ ['type' => 'subtotal', 'amount' => $total] ],
     'links' => [
       ['type' => 'privacy_policy', 'url' => $privacy_url],
       ['type' => 'terms_of_service', 'url' => $terms_url],
     ],
     'messages' => [
       [
         'type' => 'error',
         'code' => 'buyer_handoff_required',
         'severity' => 'requires_buyer_input',
         'content' => 'Complete your purchase on the merchant site.',
       ],
     ],
     'continue_url' => $shareable_url,
   ]
   ```
7. Return 201 Created

### Errors
- Empty `line_items` → HTTP 400 + `invalid_input` message at path `$.line_items`
- All items invalid/unavailable → HTTP 200 with `ucp.status: "error"` and `messages` (per spec: business outcome, not protocol error)
- Grouped/external product in line items → include message with severity `unrecoverable` explaining Shareable Checkout URLs don't support that type; agent should use product URL directly

---

## UCP-Agent header parsing

v1 implementation: regex extraction, hostname only.

```php
class WC_AI_Syndication_UCP_Agent_Header {
    public static function extract_profile_hostname( string $header_value ): string {
        // Format: profile="https://agent.example.com/profiles/shopping.json"
        if ( ! preg_match( '/profile="([^"]+)"/', $header_value, $m ) ) {
            return '';
        }
        $host = wp_parse_url( $m[1], PHP_URL_HOST );
        return is_string( $host ) ? $host : '';
    }
}
```

Used for:
- `utm_source` in `continue_url`
- Attribution logging via `Logger::debug()`
- Future: fetching the agent's profile to verify identity (deferred)

Fallback when header missing or malformed: `utm_source=ucp_unknown`.

---

## Server-side product filter

The existing plugin setting `product_selection_mode` (all / categories / selected) restricts what's published to llms.txt and JSON-LD. It must also restrict what's queryable via Store API through our UCP routes.

Implementation: hook `woocommerce_store_api_product_collection_query_args`:

```php
add_filter( 'woocommerce_store_api_product_collection_query_args', [ $this, 'restrict_to_syndicated_products' ] );

public function restrict_to_syndicated_products( array $args ): array {
    $settings = WC_AI_Syndication::get_settings();
    $mode = $settings['product_selection_mode'] ?? 'all';

    if ( $mode === 'categories' && ! empty( $settings['selected_categories'] ) ) {
        $args['tax_query'][] = [
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => array_map( 'absint', $settings['selected_categories'] ),
        ];
    } elseif ( $mode === 'selected' && ! empty( $settings['selected_products'] ) ) {
        $args['post__in'] = array_map( 'absint', $settings['selected_products'] );
    }

    return $args;
}
```

This filter fires for ALL Store API product queries, not just UCP routes — which is intentional. If merchants set the plugin to "only these 10 products," they genuinely mean it, and block-theme Cart/Checkout blocks using Store API should honor it too.

**v1 decision**: ship the filter. The current plugin is silently lying to merchants about what "only these products" means. This commit makes it true.

---

## Tests

### UcpProductTranslatorTest (~10 tests)

- `test_simple_product_translates_with_all_required_ucp_fields`
- `test_simple_product_emits_single_default_variant` (schema requires minItems 1)
- `test_variable_product_emits_one_variant_per_wc_variation`
- `test_prices_preserved_as_integer_minor_units_no_float_math`
- `test_jpy_product_amount_matches_wc_response_unchanged` (no hardcoded *100)
- `test_description_html_stripped_and_entities_decoded`
- `test_categories_tagged_with_merchant_taxonomy`
- `test_product_with_no_image_omits_media_array`
- `test_product_id_prefixed_with_prod`
- `test_variant_id_prefixed_with_var`

### UcpVariantTranslatorTest (~6 tests)

- `test_simple_product_variant_has_default_suffix_id`
- `test_wc_variation_translates_to_ucp_variant_shape`
- `test_variation_options_emit_selected_option_shape`
- `test_out_of_stock_variant_has_availability_false`
- `test_variant_price_structure_matches_spec`
- `test_variant_without_sku_omits_sku_field`

### UcpRestControllerTest (~15 tests)

- `test_catalog_search_returns_ucp_envelope_with_products`
- `test_catalog_search_query_passes_to_store_api_search_param`
- `test_catalog_search_categories_filter_maps_to_store_api_category`
- `test_catalog_search_price_filter_maps_to_min_max_price`
- `test_catalog_search_empty_result_returns_empty_products_array`
- `test_catalog_lookup_returns_requested_products`
- `test_catalog_lookup_missing_id_includes_not_found_message`
- `test_catalog_lookup_mixed_valid_and_missing_ids`
- `test_checkout_sessions_requires_escalation_status`
- `test_checkout_sessions_continue_url_uses_shareable_format`
- `test_checkout_sessions_utm_source_extracted_from_ucp_agent`
- `test_checkout_sessions_without_ucp_agent_uses_fallback_utm_source`
- `test_checkout_sessions_empty_line_items_returns_400`
- `test_checkout_sessions_out_of_stock_item_includes_warning_message`
- `test_checkout_sessions_response_includes_legal_links`

### UcpEnvelopeTest (~4 tests)

- `test_catalog_envelope_uses_response_catalog_schema_shape`
- `test_checkout_envelope_uses_response_checkout_schema_shape`
- `test_empty_payment_handlers_serializes_as_object_not_array`
- `test_envelope_version_matches_plugin_protocol_version`

### UcpAgentHeaderTest (~5 tests)

- `test_extracts_hostname_from_valid_profile_header`
- `test_returns_empty_for_missing_header`
- `test_returns_empty_for_malformed_header`
- `test_handles_header_with_extra_dictionary_fields`
- `test_handles_https_and_http_profile_urls`

### UcpStoreApiFilterTest (~5 tests)

- `test_all_mode_does_not_modify_query_args`
- `test_categories_mode_injects_tax_query`
- `test_selected_mode_injects_post_in`
- `test_empty_selected_categories_does_not_inject_filter`
- `test_filter_does_not_clobber_existing_tax_query_entries`

**Total new tests: ~45.** Plus existing 91 PHP tests + 42 JS tests stays green.

---

## Task breakdown

| # | Task | Estimate |
|---|---|---|
| 1 | Scaffold module directories and empty class files | 15 min |
| 2 | Bootstrap registration (`init_components()` + `rest_api_init`) | 15 min |
| 3 | `UcpAgentHeader` + tests | 30 min |
| 4 | `UcpEnvelope` + tests | 45 min |
| 5 | `UcpProductTranslator` + tests (simple products path) | 1 hour |
| 6 | `UcpVariantTranslator` + tests | 1 hour |
| 7 | Variable product variant expansion in `UcpProductTranslator` | 1 hour |
| 8 | `UcpStoreApiFilter` + tests (server-side product filter) | 45 min |
| 9 | REST controller scaffold + route registration | 30 min |
| 10 | `catalog/lookup` route + tests | 45 min |
| 11 | `catalog/search` route + tests (filter mapping) | 1.5 hours |
| 12 | `checkout-sessions` route + tests (envelope, continue_url, legal links) | 2 hours |
| 13 | Manifest updates (endpoint, capabilities, PROTOCOL_VERSION to 2026-04-08) + UcpTest updates | 1 hour |
| 14 | robots.txt update + test | 15 min |
| 15 | Version bump + readme changelog + .pot regen + AGENTS.md | 45 min |
| 16 | Full gate (phpcs, phpstan, phpunit, build, lint) + fixes | 1 hour |
| 17 | Commit sequence + PR prep | 30 min |

**Total: ~13 hours focused work.** With 30% buffer for unknowns surfaced during implementation: **~17 hours.** This is closer to a full day than a half-day; expect to land over 2-3 focused sessions.

---

## Rollout & compatibility

### Upgrade path
- Plugin 1.2.1 → 1.3.0 triggers the existing version-mismatch cache-bust branch
- Cached UCP manifest regenerates with new shape
- Cached llms.txt regenerates (unchanged content, just invalidated)
- Rewrite rules re-flushed synchronously (no merchant action required, from 1.1.2+ fix)

### Breaking changes
- `/.well-known/ucp` **manifest shape changes** (new endpoint, new capabilities). Agents parsing the old shape will see a new shape and should re-discover. UCP spec supports this via `supported_versions` map, which we could add in a future release.
- Any site that hit `/wp-json/wc/store/v1/` from a UCP context based on the old manifest will still work — the Store API still lives there. But our new manifest points at `/wp-json/wc/ucp/v1/` instead, so agents following the new manifest hit our wrapper.

### What merchants will observe
- New REST endpoints live at `/wp-json/wc/ucp/v1/*` (can be probed via `curl`)
- UCP manifest at `/.well-known/ucp` has new shape (validators should show higher compliance scores)
- Product filter settings now **actually apply** to Store API responses (behavioral change worth highlighting in changelog — merchants whose carts-via-Store-API depend on showing all products may see unexpected filtering; advise checking the Product Visibility setting)

### What merchants will NOT observe
- No admin UI changes (the adapter is agent-facing, not merchant-facing)
- No performance changes on normal store operations
- No changes to llms.txt / JSON-LD

---

## Known unknowns (surface now, resolve during implementation)

1. **`rest_do_request` header propagation**: does `UCP-Agent` header pass through to internal Store API calls? Need to test. (Not blocking — we don't need propagation for v1 flows, but informs future auth work.)

2. **Variable product variant performance at scale**: 20 products × 3 variations each = 80 dispatches per search. `rest_do_request` has zero HTTP overhead but runs full WP REST pipeline. Measure in a test site with 100+ products. If slow, cache variation lookups within a request.

3. **WC Store API response shape for variations**: does the endpoint return variation-specific images, or just inherit parent product images? Affects `variants[].media` completeness.

4. **WC Store API category filter parameter**: accepts slug OR ID? Need to verify which. If IDs: our filter mapping from UCP `filters.categories` (strings matching product `categories[].value`) needs a slug→ID lookup. Minor, but affects translator.

5. **Privacy Policy URL when unset**: `get_privacy_policy_url()` returns empty string if merchant hasn't configured one. Spec says links is required. Decision: emit empty `links: []` with a warning message explaining the compliance gap. An empty array might trigger validator rejection; alternative is to emit `[{type: "privacy_policy", url: home_url('/privacy-policy')}]` as a guess, which could 404. **v1 call**: empty array + warning message is more honest.

6. **UCP version target `2026-04-08` vs `2026-01-11`**: Allbirds uses 2026-04-08. Our 1.2.x uses 2026-01-11. Shift to 2026-04-08 to match Allbirds and stay current. Verify during implementation that all our response shapes match 2026-04-08 spec (not just catalog/checkout).

---

## Ship decision checklist

Before committing the first task, confirm:

- [ ] Plan reviewed by user
- [ ] All 6 "known unknowns" have a fallback path documented
- [ ] Test counts projected (existing 91+42 plus ~45 new = 136 + 42 tests)
- [ ] Version bump staged (1.2.1 → 1.3.0)
- [ ] Changelog entry drafted

---

## Out of scope (explicit deferrals to 1.4.0+)

- **Cart capability** — agent holds items in memory; may revisit if agents request server-side cart sessions
- **Identity Linking** — requires OAuth 2.0 flow; significant implementation
- **Order capability** — webhook-based order lifecycle; requires merchant configuration UI
- **Fulfillment capability** — agent-queryable shipping rates; requires hooking WC shipping calculations
- **Discount capability** — agent applying coupon codes; requires Store API coupon integration
- **Payment handlers** — Google Pay, Shop Pay, etc.; requires payment provider integration work
- **Signed responses (RFC 9421)** — signing_keys + message signatures; future security hardening
- **`supported_versions` map in manifest** — for multi-version support; add when we have multiple versions
- **Full RFC 8941 Dictionary parsing for UCP-Agent** — v1 uses regex; upgrade when we need other header fields
- **Pagination on `/catalog/search`** — v1 returns up to Store API default (10 or so); add when agents request it

---

## References

- UCP spec repo: https://github.com/Universal-Commerce-Protocol/ucp
- UCP website: https://ucp.dev
- Production example: https://www.allbirds.com/.well-known/ucp
- WC Store API docs: https://developer.woocommerce.com/docs/apis/store-api
- WC Shareable Checkout URLs: https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/
- WC add-to-cart URLs: https://woocommerce.com/document/quick-guide-to-woocommerce-add-to-cart-urls/
- WC Order Attribution: https://woocommerce.com/document/order-attribution-tracking/
