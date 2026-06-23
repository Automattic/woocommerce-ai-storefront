# GET batch `catalog.lookup` + conformance — Design

**Status:** approved design, pending spec review → implementation plan.

## Goal

Let fetch-only AI agents (which can't POST) collapse an N-product lookup fan-out into a single request, by extending the GET `/catalog/lookup` surface to the batch core that already backs POST. Also tighten one UCP conformance detail (`request_too_large`). This is the spec-aligned reduction of the request burst that trips the WordPress.com/Atomic edge rate limit — without inventing any off-spec surface.

## Why this, and why it's in-spec

UCP defines catalog access as `catalog.search` (cursor-paginated) + `catalog.lookup` (**batch** — "returns partial results for batch requests") + `catalog.product` (single). There is no bulk-feed concept; a static `/ucp-catalog.json` would be off-spec and, worse, ignored by spec-following agents (they'd paginate `catalog.search` and hit the same rate limit). Batch `catalog.lookup` is the spec's own answer to "fetch many products at once," so completing it on the GET surface is both conformant and targeted at the failing segment.

`catalog.lookup` request schema (UCP `source/schemas/shopping/catalog_lookup.json`): `{ "ids": [string,…] (minItems 1, product/variant IDs MUST; SKU/handle MAY), filters?, context?, signals?, attribution? }`. Conformance rules (`docs/specification/catalog/rest.md`): cursor pagination default limit 10; partial results with `not_found` info messages at HTTP 200; **HTTP 400 `request_too_large` when batch exceeds the implementation's limit**.

## Current state (verified)

| Surface | Batch? | Detail |
|---|---|---|
| POST `/catalog/lookup` (`handle_catalog_lookup` → `run_catalog_lookup`) | ✅ full batch | reads `ids` array; `run_catalog_lookup` does partial results + `not_found` messages; `validate_lookup_ids_param` caps at `MAX_IDS_PER_LOOKUP` |
| GET `/catalog/lookup` (`handle_catalog_lookup_get`, ~:802) | ❌ single only | builds a 1-element `ids` from one `?id=` (positive int) or one `?slug=` (`get_page_by_path`); 404s on miss |
| over-limit error | ⚠️ nonconformant | `validate_lookup_ids_param` returns `INVALID_INPUT`, not the spec's `request_too_large` |
| `/catalog/product` (single-resource) | ❌ absent | out of scope; see below |

The batch engine already exists; the GET surface just never feeds it more than one id.

## Design

### 1. GET `?ids=` batch path (`handle_catalog_lookup_get`)
- Accept `?ids=prod_22,var_4079,prod_99` — comma-separated **opaque UCP id strings**, exactly the ids agents already receive from `catalog.search` results (the translator id space: `prod_<digits>`, `var_<digits>`). Split on `,`, `trim()` each, drop empty entries.
- Pass the resulting array **straight to the existing `run_catalog_lookup()`** — no GET-specific id parsing, no prefixing, no new lookup logic. The core already validates, dedupes, looks up, caps, and emits `not_found` info messages for unresolved ids (partial result, HTTP 200). This is why full UCP ids (not bare numerics) is the right choice: it matches the POST batch id space, supports **variant IDs** (the spec's MUST), and gives spec-conformant lenient `not_found` semantics instead of harsh per-token rejection. An unknown/garbage token simply comes back as a `not_found` message, not a 400.
- **Structural errors only → 400:** empty `?ids=` (no usable tokens after trim) → `400 INVALID_INPUT`; over the cap → `400 request_too_large` (see §2). Everything else is a 200 business outcome.
- **Precedence:** if `?ids=` is present, use the batch path. Otherwise the existing single `?id=` (bare numeric convenience) / `?slug=` behaviour is unchanged (back-compat, including their 404-on-miss single semantics). Mixing `?ids=` with `?id=`/`?slug=` is not supported (`?ids=` wins). (Minor, documented wart: `?id=` stays bare-numeric for back-compat while `?ids=` takes full UCP ids — chosen so `?ids=` is POST-consistent and variant-capable.)

### 2. `request_too_large` conformance (`validate_lookup_ids_param`)
- When `count(ids) > MAX_IDS_PER_LOOKUP`, return UCP `request_too_large` at HTTP 400 instead of `INVALID_INPUT`. Because `validate_lookup_ids_param` is shared, this upgrades **both** POST and GET to conformance rule #5.

### 3. Advertise GET batch
- Add a line to `/llms.txt` and the `com.woocommerce.ai_storefront` extension schema/docs noting `GET /catalog/lookup?ids=` collapses multiple lookups into one request. (POST batch is already spec-standard and self-describing; the GET form is our fetch-only-agent convenience and must be advertised to be discovered.)

## Data flow

`GET …/wc/ucp/v1/catalog/lookup?ids=prod_22,var_4079,prod_99`
→ split + trim → `['prod_22','var_4079','prod_99']`
→ `run_catalog_lookup(['ids'=>…])` → core validates / dedupes / looks up → translator
→ `{ "ucp": {…}, "products": [ …found… ], "messages": [ …not_found for unresolved… ] }`, HTTP 200.
Identical envelope to POST `/catalog/lookup`.

## Error handling

- Empty `?ids=` (no usable tokens after trim) → `400 INVALID_INPUT`.
- `count > MAX_IDS_PER_LOOKUP` → `400 request_too_large`.
- Unknown / garbage / unresolvable ids → `200`, omitted from `products`, one `not_found` message each (lenient batch semantics — NOT a 400). All-unresolved → `200` with empty `products` + `not_found` messages.
- Syndication disabled → `503` (existing early guard).
- `permission_callback` (`check_agent_access`) unchanged — GET batch honours `allowed_crawlers` exactly like the single GET.

## Testing (Brain Monkey unit + existing route tests)

In `tests/php/unit/UcpCatalogGetRoutesTest.php` / `UcpCatalogLookupTest.php`:
- `?ids=prod_1,prod_2` (multiple valid) → multiple products, HTTP 200.
- `?ids=` mixing valid + a garbage token (e.g. `?ids=prod_1,gibberish`) → partial products + a `not_found` message, HTTP 200 (NOT a 400).
- `?ids=` over `MAX_IDS_PER_LOOKUP` → `request_too_large` (400).
- empty `?ids=` (e.g. `?ids=` or `?ids=,,`) → `400 INVALID_INPUT`.
- a variant id (`?ids=var_<id>`) resolves per the spec MUST (or is asserted as the documented limitation — see research note 1).
- Single `?id=` and `?slug=` back-compat intact (still single, still 404-on-miss).
- POST over-cap assertion updated from `INVALID_INPUT` → `request_too_large`.

## Files

- `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` — GET handler `?ids=` branch; `validate_lookup_ids_param` error-code change.
- UCP error-codes class — add a `request_too_large` code if absent.
- `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` + extension schema — advertise GET batch.
- `docs/engineering/API-REFERENCE.md` — document `?ids=`.
- the two test files above; `CHANGELOG.md` + `readme.txt`.

## Implementation notes — verify during planning research (not design holes)

1. **Variant IDs (spec MUST):** passing full UCP ids straight to `run_catalog_lookup` should make variant IDs work for free (the core already operates on the `prod_`/`var_` translator id space that POST uses). Confirm the core actually resolves a `var_*` id to its variant; if a gap exists, document `?ids=` as product-id-only for v1 and file variant support as a separate item.
2. **`request_too_large` code:** confirm whether `WC_AI_Storefront_UCP_Error_Codes` already defines it; add if not. Check what HTTP status `ucp_catalog_error_response` assigns and that it yields 400.
3. **`MAX_IDS_PER_LOOKUP` value:** confirm the constant's value so the cap is documented/advertised consistently.
4. **Advertising home:** confirm the right spot in the extension schema / llms.txt for the GET-batch note.

## Out of scope (separate item)

- **`/catalog/product` single-resource endpoint.** Spec rule #1 says advertising Lookup requires both `/catalog/lookup` and `/catalog/product`. Verify whether the pinned `2026-04-08` version we target actually mandates it before building — do not implement to a newer spec than we advertise. Its own design/plan.
- The platform rate-limit carve-out (infra) remains the complementary fix for `catalog.search` enumeration.
