# WooPayments multi-currency exposure (Phase 1)

**Date:** 2026-05-15
**Status:** Approved for implementation
**Scope:** Phase 1 of 2. Phase 2 (live currency switching of UCP catalog response prices and price-filter denominations) is tracked separately as follow-up work.

## Audience and purpose

This document specifies how the WooCommerce AI Storefront plugin will publish the list of currencies a store accepts, when WooPayments' multi-currency feature is active, and how it will stamp `?currency=XXX` onto outbound buyer-facing URLs to honor a requesting agent's `context.currency`. It is the design contract that the implementation plan will be derived from.

The reader is a developer about to implement, review, or extend this feature. Cross-references to engineering docs use relative paths from the repo root.

## Goal

Two related capabilities:

1. **Advertise.** When a store runs WooPayments with the multi-currency feature enabled, machine-readable surfaces (UCP manifest, homepage JSON-LD, llms.txt) declare the full set of currencies the store accepts at checkout, not only the base currency.
2. **Honor on outbound URLs.** When an agent specifies a `context.currency` that is in the advertised set, UCP `continue_url` (checkout-link) and product `permalink` fields carry `?currency=XXX` so the buyer lands on the merchant's checkout / product page in the requested currency. WooPayments' built-in `?currency=` query-param handler does the rest.

Stores without WooPayments — or with the multi-currency feature disabled — continue to emit a single-element list containing the base currency and stamp no `?currency=` param. Output shape is stable across all states.

## Non-goals (Phase 1)

- **Quoting UCP catalog response prices in non-base currencies.** `catalog/search`, `catalog/lookup`, and per-line item `selling_price` / `list_price` / `price_range` / bundle prices continue to return base-currency-denominated values. This is deferred to Phase 2 — it requires switching WooPayments' active currency *within* the UCP REST dispatch path, which has separate state-leak and Store API integration concerns. Honesty trade-off: the buyer lands on a `?currency=EUR` PDP after the agent quoted USD prices. Documented explicitly so agents can warn buyers.
- **Switching the price-filter denomination.** `catalog/search` `filters.price` with a mismatched `context.currency` continues to be dropped with a `currency_conversion_unsupported` warning. Loosening this requires Phase 2's live currency switching.
- **Per-product JSON-LD multi-currency `Offer` arrays.** The product JSON-LD reflects whatever currency the storefront page request was rendered in — when WooPayments-multicurrency processes a crawler's `?currency=XXX` query param on the way in, `get_woocommerce_currency()` already returns the switched code, and our JSON-LD emits `priceCurrency: "XXX"` automatically. No code change needed; we document the behavior so crawler-side currency-discovery flows can rely on it.
- **Currency-switcher UI on the storefront.**
- **Detection of non-WooPayments multi-currency plugins** (CURCY, WC Currency Switcher, etc.). Those integrators use the override filter.

## Surfaces

Five surfaces gain currency awareness. Three are advertise-only (output a list); two are outbound-URL stamping driven by request context.

### Advertise (output the supported set)

| File | Field | Before | After |
|---|---|---|---|
| `includes/ai-storefront/class-wc-ai-storefront-ucp.php` (`build_store_context()`) | `store_context.accepted_currencies` | absent | array of ISO-4217 codes, base first |
| `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` (homepage `OnlineBusiness` enricher) | `currenciesAccepted` | string `"USD"` | space-separated string `"USD EUR GBP"` per Schema.org convention |
| `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` | `**Accepted currencies**` line | absent | comma-separated codes plus a qualifier; omitted when the list has one entry |

### Honor on outbound URLs (stamp `?currency=XXX` when context-currency is in the set)

| File | URL surface | Before | After |
|---|---|---|---|
| `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` (`build_continue_url()`) | `continue_url` returned from `POST /checkout-sessions` (every variant: `/checkout-link/?products=`, `/checkout/?add-to-cart=BUNDLE`, `/checkout/?add-to-cart=GROUPED&quantity[CHILD]=`, variable-parent permalinks) | UTM-stamped only | `?currency=XXX` stamped first, then UTM block, when `context.currency` is in `accepted_currencies` |
| `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-product-translator.php` (product translator) | Per-product `permalink` in `catalog/search` and `catalog/lookup` responses | Plain permalink | `?currency=XXX` stamped when `context.currency` is in `accepted_currencies` |

The existing `store_context.currency` scalar (base currency) is unchanged. Agents that only read `store_context.currency` and never send `context.currency` keep working without modification — that's the back-compat contract.

### Free win: per-page JSON-LD already reflects the request currency

When a crawler hits a single-product page with `?currency=EUR`, WooPayments-multicurrency's `?currency=` handler runs in `init` (before our `wp_head` JSON-LD enricher). `get_woocommerce_currency()` returns `EUR` for that request; every `priceCurrency` field on the page's product JSON-LD emits `EUR`; every `price` reflects the EUR-converted amount. **No code change needed.** This is called out in JSON-LD-SCHEMA.md so crawler-side currency-discovery flows (e.g. "Googlebot fetches the page in each accepted currency to build a multi-currency index") can rely on it. It does NOT apply to:

- `/.well-known/ucp` manifest (not a per-page render — store-wide).
- Homepage `OnlineBusiness.currenciesAccepted` (this is the *list*, not a single quote).
- UCP REST responses (the `/wp-json/wc/ucp/v1/...` path doesn't traverse WCPay's `?currency=` handler — that's Phase 2).

### Example outputs

UCP manifest `store_context` (multi-currency enabled):

```json
{
  "currency": "USD",
  "accepted_currencies": ["USD", "EUR", "GBP"],
  "locale": "en-US",
  "country": "US",
  "prices_include_tax": false,
  "shipping_enabled": true
}
```

UCP manifest `store_context` (single-currency, including WooPayments-absent or multi-currency-disabled stores):

```json
{
  "currency": "USD",
  "accepted_currencies": ["USD"],
  "locale": "en-US",
  "country": "US",
  "prices_include_tax": false,
  "shipping_enabled": true
}
```

Homepage JSON-LD (`OnlineStore` node):

```json
{ "currenciesAccepted": "USD EUR GBP" }
```

llms.txt (multi-currency case — qualifier is required when more than one code is listed):

```
- **Currency**: USD
- **Accepted currencies**: USD, EUR, GBP (catalog prices quoted in USD; checkout converts at WooPayments' rates)
```

llms.txt (single-currency case): the `**Accepted currencies**` line is omitted entirely. The existing `**Currency**` line is unchanged.

## Architecture

### New helper class

A new pure helper, `WC_AI_Storefront_Multi_Currency`, owns the question "what currencies does this store accept?" It is a soft-dependency reader — it must never assume WooPayments is loaded.

```
WC_AI_Storefront_Multi_Currency::get_accepted_currencies(): array<string>

  1. base := strtoupper( get_woocommerce_currency() )
  2. detected := []
     if class_exists( '\WCPay\MultiCurrency\MultiCurrency' ):
       if MultiCurrency::instance()->is_multi_currency_enabled():
         // get_enabled_currencies() returns an associative array
         // keyed by ISO-4217 code (uppercase), values are
         // WCPay\MultiCurrency\Currency objects. We read the keys
         // — they are the canonical codes — rather than calling
         // get_code() on each value, to keep the helper resilient
         // to WCPay refactors of the Currency object shape.
         currencies := MultiCurrency::instance()->get_enabled_currencies()
         detected := array_keys( currencies )
  3. list := [ base ] ∪ detected   (base first, preserve detected order, dedupe)
  4. list := array_filter( list, is_valid_iso_4217 )   (^[A-Z]{3}$ after uppercase)
  5. list := apply_filters( 'wc_ai_storefront_accepted_currencies', $list )
  6. if filter returned non-array OR empty OR no valid codes:
       list := [ base ]
  7. return list
```

Per-request memoization is implemented via a `static` cache on the class so the call sites can hit it without redundant work. No transients or persistent cache — `MultiCurrency::get_enabled_currencies()` is already memoized inside WCPay, and the UCP manifest endpoint is not a hot path.

### New URL-stamping helper method

A second pure helper method on the same class — `stamp_currency_query( string $url, ?string $requested_currency ): string` — answers the question "should this outbound buyer-facing URL carry `?currency=XXX`?"

```
WC_AI_Storefront_Multi_Currency::stamp_currency_query( $url, $requested ): string

  1. if $url === '' OR $url is not a string → return $url unchanged
  2. if $requested is null OR not a string → return $url unchanged
  3. normalized := strtoupper( trim( $requested ) )
  4. if normalized fails /^[A-Z]{3}$/ → return $url unchanged
  5. accepted := get_accepted_currencies()
  6. if normalized not in accepted → return $url unchanged
  7. if $url already carries `currency=` → return $url unchanged
     (parse via wp_parse_url + parse_str; preserves any agent-supplied override)
  8. return add_query_arg( 'currency', normalized, $url )
```

The fail-closed paths in steps 1, 2, 4, 6, and 7 are explicit. The function never throws, never modifies the URL in any way other than appending the single param when all conditions are met. Stamping is **idempotent**: calling twice with the same args produces the same URL.

`add_query_arg()` is used so URLs that already carry query params (multilingual permalinks, attribution UTMs, bundle/grouped `?add-to-cart=` params) receive `currency=` cleanly without double-`?` malformation. WooCommerce / WP core handles the merging.

### Caller integration

Five callers read from the helper. Three read `get_accepted_currencies()` (advertise); two call `stamp_currency_query()` (honor).

**Advertise callers:**

1. **`build_store_context()`** in `class-wc-ai-storefront-ucp.php` adds an `accepted_currencies` key alongside the existing `currency` scalar.
2. **Homepage `OnlineBusiness` JSON-LD enricher** in `class-wc-ai-storefront-jsonld.php` (around line 1897) replaces the single-code emission with `implode( ' ', $codes )`. Schema.org documents `currenciesAccepted` as a space-separated list of currencies — that's the canonical format.
3. **llms.txt builder** in `class-wc-ai-storefront-llms-txt.php` (around line 343, right after the existing `**Currency**` line) emits a new line only when `count( $codes ) > 1`. The qualifier string is hard-coded English; it's covered by a single `__()` call so translators can rephrase the parenthetical for other languages.

**Honor callers:**

4. **`build_continue_url()`** in `class-wc-ai-storefront-ucp-rest-controller.php`: read `$context_currency` from the request body's `context.currency` and pass it into `stamp_currency_query()` **before** the existing `WC_AI_Storefront_Attribution::with_woo_ucp_utm()` call. Currency appears before the UTM block in the final query string; downstream UTM-stamping is unchanged. Applies to every `continue_url` shape: `/checkout-link/?products=`, bundle `/checkout/?add-to-cart=BUNDLE`, grouped `/checkout/?add-to-cart=PARENT&quantity[CHILD]=`, variable-parent permalinks.
5. **UCP product `url` stamping** in `class-wc-ai-storefront-ucp-rest-controller.php` at the two existing controller-side post-translation sites (`catalog/lookup` handler ~line 1079, `catalog/search` handler ~line 1842). Today both sites call `WC_AI_Storefront_Attribution::with_woo_ucp_utm( $product['url'], $agent_source_host, $agent_raw_host )` after `WC_AI_Storefront_UCP_Product_Translator::translate()` returns; we wrap that call with `stamp_currency_query()` so the final URL carries `?currency=XXX` ahead of the UTM block. The translator (issue #176 made it a pure function) is **not** touched — agent-context side-effects continue to live in the controller, where they have always lived.

The request currency is extracted in the controller from `$request->get_param( 'context' )['currency']` (validating as ISO-4217 via the existing pattern at line 4266 in `map_ucp_search_to_store_api`). Today that extraction is inlined inside the price-filter branch; Phase 1 hoists it to a private helper `get_request_currency( WP_REST_Request $request ): ?string` so both `catalog/lookup`, `catalog/search`, and `POST /checkout-sessions` can read it from one place. The new helper is private to the controller — not a public API.

### New filter

`wc_ai_storefront_accepted_currencies` — receives the auto-detected list, returns the final list. Documented in `docs/engineering/HOOKS.md`.

Use cases:
- Non-WooPayments multi-currency plugins (CURCY, WC Currency Switcher, etc.) populate the list.
- Merchants curate the WooPayments list (e.g., hide a test-only currency).
- Integrators temporarily force single-currency for a regression repro.

The implementation rejects non-array filter returns, empty returns, and returns where no entry passes ISO-4217 validation. In all three failure modes, the list falls back to `[ base ]` — the field never disappears and never goes empty.

The filter ALSO drives URL stamping: a currency excluded by the filter won't pass step 6 of `stamp_currency_query()` and so won't be stamped onto any URL. That's the intended coupling — there is one source of truth for "what currencies does this store accept" and both the advertised list and the URL-stamping eligibility flow from it.

## Edge cases

### `get_accepted_currencies()`

| Case | Behavior |
|---|---|
| WooPayments not installed | `accepted_currencies: [ base ]`. |
| WooPayments installed, multi-currency feature off | `accepted_currencies: [ base ]`. (Guarded by `MultiCurrency::is_multi_currency_enabled()`.) |
| WooPayments enabled list excludes the store base (theoretical) | Base is prepended. List never lacks the base currency. |
| WCPay returns duplicate codes | Deduped, first occurrence wins for ordering after base. |
| WCPay returns malformed code (length != 3, non-alpha) | Dropped during ISO-4217 validation. |
| Filter returns `null`, `false`, `[]`, or all-invalid codes | Falls back to `[ base ]`. |
| `get_woocommerce_currency()` returns empty (WC not loaded) | The helper returns `[ 'USD' ]` as the documented fallback. This matches the existing convention in `class-wc-ai-storefront-jsonld.php:435` and the UCP product translator. |

### `stamp_currency_query()`

| Case | Behavior |
|---|---|
| `context.currency` absent from request | URL unchanged. (Most common case for agents that don't speak multi-currency.) |
| `context.currency` matches base currency | URL stamped with `?currency=BASE`. Harmless redundancy; keeps the stamping rule predictable. |
| `context.currency` is in `accepted_currencies` but != base | URL stamped. |
| `context.currency` is NOT in `accepted_currencies` | URL unchanged. The agent's hint is silently ignored — we do NOT emit a warning here because the agent will see the upstream `currency_conversion_unsupported` warning on the price-filter path or simply not see their currency reflected on the destination page. (Future polish: surface a warning in the message envelope; deferred.) |
| `context.currency` is malformed (length != 3, non-alpha, empty, non-string) | URL unchanged. |
| URL already carries `currency=...` | URL unchanged. Preserves any agent-supplied override or filter-injected value upstream. |
| URL is empty string | URL unchanged (returns `''`). Matches `build_continue_url()`'s "no usable URL" sentinel. |
| URL has no existing query string | `add_query_arg()` appends `?currency=XXX`. |
| URL has an existing query string with UTM params | `add_query_arg()` inserts `currency=XXX` adjacent to existing params; UTM-stamping pass runs AFTER, so the final order is `?currency=XXX&products=...&utm_source=...&utm_medium=referral&utm_id=woo_ucp` (typical). |

## Testing

Per `docs/engineering/TESTING.md`: one PHPUnit file per production class, Brain Monkey for WP/WC mocks, no real WordPress install needed.

### New file: `tests/php/unit/MultiCurrencyTest.php`

Already shipped at commits `63432b1` (Task 1) and `dfc55cb`, `cee5a55` (Task 2). Covers `get_accepted_currencies()`. Task 3 will add the filter / memoization / `stamp_currency_query()` test methods.

`get_accepted_currencies()` coverage (already shipped, naming follows `test_<what>_<conditions>_<outcome>`):

- `test_get_accepted_currencies_no_wcpay_double_returns_base_only`
- `test_get_accepted_currencies_wcpay_disabled_returns_base_only`
- `test_get_accepted_currencies_wcpay_enabled_returns_full_list_with_base_first`
- `test_get_accepted_currencies_base_missing_from_wcpay_list_is_prepended`
- `test_get_accepted_currencies_duplicates_are_deduped_preserving_order`
- `test_get_accepted_currencies_malformed_codes_are_dropped`

Additional coverage to ship in Task 3:

- `test_get_accepted_currencies_filter_can_override_list`
- `test_get_accepted_currencies_filter_returning_non_array_falls_back_to_base`
- `test_get_accepted_currencies_filter_returning_empty_falls_back_to_base`
- `test_get_accepted_currencies_filter_returning_all_invalid_codes_falls_back_to_base`
- `test_get_accepted_currencies_memoizes_within_request`

`stamp_currency_query()` coverage (ships in Task 6a):

- `test_stamp_currency_query_no_request_currency_returns_url_unchanged`
- `test_stamp_currency_query_request_currency_matches_base_stamps_url`
- `test_stamp_currency_query_request_currency_in_accepted_set_stamps_url`
- `test_stamp_currency_query_request_currency_not_in_accepted_set_returns_url_unchanged`
- `test_stamp_currency_query_malformed_request_currency_returns_url_unchanged`
- `test_stamp_currency_query_url_with_existing_currency_param_returns_url_unchanged`
- `test_stamp_currency_query_empty_url_returns_empty_string`
- `test_stamp_currency_query_url_with_existing_query_appends_currency_cleanly`
- `test_stamp_currency_query_idempotent_when_called_twice`

### Updates to existing test files

- `tests/php/unit/UcpTest.php` — assert `store_context.accepted_currencies` is present, base-first, on both single- and multi-currency mocks.
- `tests/php/unit/JsonLdTest.php` — assert `currenciesAccepted` is space-separated when more than one code is enabled.
- `tests/php/unit/LlmsTxtTest.php` — assert the `**Accepted currencies**` line is present (with qualifier) when multi-currency is active and absent when only the base is enabled.
- `tests/php/unit/UcpRestControllerTest.php` (or the relevant checkout-sessions test file) — assert `continue_url` carries `?currency=XXX` when `context.currency` is in `accepted_currencies`, and is unchanged otherwise. Test all `continue_url` variants: `/checkout-link/?products=`, bundle, grouped, variable-parent.
- `tests/php/unit/UcpProductTranslatorTest.php` — assert per-product `permalink` carries `?currency=XXX` when the dispatch context has a request currency in the accepted set. Test that it does NOT stamp when the request currency is the base, when it's absent, or when it's outside the accepted set.

No integration test against a real WooPayments install. Brain Monkey mocks the WCPay class and `MultiCurrency::instance()` return value.

## Versioning and changelog

This is a **MINOR** bump per `docs/engineering/RELEASE.md`:

- New UCP `store_context.accepted_currencies` field.
- New `currency` query param on UCP `continue_url` and product `permalink` responses (only when agent provides `context.currency` in the accepted set).
- New filter `wc_ai_storefront_accepted_currencies`.
- Backwards-compatible — existing `currency` scalar is unchanged, single-currency stores see the new field as a one-element array, agents that don't send `context.currency` get unchanged URLs.

Three places must agree on the new version: `woocommerce-ai-storefront.php` header **and** the `WC_AI_STOREFRONT_VERSION` constant, `package.json`, and `readme.txt` `Stable tag`.

CHANGELOG / USER-GUIDE updates happen in the **pre-release pass**, not during PR iteration, per project convention.

## Documentation impact

Per `AGENTS.md`'s path → doc map, the implementation PR touches the following engineering docs in the same commit set:

| Code path | Docs to update |
|---|---|
| New `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` | Add row to `AGENTS.md` path map + `.github/workflows/docs-followup.yml`; document in `ARCHITECTURE.md` |
| `includes/ai-storefront/class-wc-ai-storefront-ucp.php` | `API-REFERENCE.md`, `UCP-BUY-FLOW.md`, `ARCHITECTURE.md` |
| `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` | `ARCHITECTURE.md`, `HOOKS.md`, `JSON-LD-SCHEMA.md` (free-win note about per-page `?currency=` reflection) |
| `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` | `ARCHITECTURE.md`, `HOOKS.md` |
| `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-rest-controller.php` | `API-REFERENCE.md`, `UCP-BUY-FLOW.md`, `ARCHITECTURE.md` (currency-stamping on `continue_url`) |
| `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-product-translator.php` | `API-REFERENCE.md` (permalink shape note) |
| New filter `wc_ai_storefront_accepted_currencies` | `HOOKS.md` row |

`USER-GUIDE.md` and `CHANGELOG.md` updates wait for the pre-release pass.

## Coding-standards reminders

- WordPress + Automattic PHP standards: tabs, Yoda conditions, `array()` not `[]`, strict `===` / `!==`.
- Defensive guards: `function_exists`, `class_exists`, `is_array` before reading optional structures.
- No em-dashes in merchant-facing copy. The llms.txt qualifier uses commas and parentheses only.
- Sentence case for the llms.txt label (`**Accepted currencies**`, not `**Accepted Currencies**`).
- Translators-friendly: the qualifier is a single `__()` call, not concatenated sentences.

## Follow-up work (Phase 2)

Tracked as a separate spec when Phase 1 ships. The Phase 2 design will need to address, at minimum:

1. **Switching the WooPayments active currency for the duration of a Store API dispatch** — likely via `MultiCurrency::set_user_selected_currency_code()` or equivalent, scoped to the request so we don't leak state.
2. **Quoting catalog response prices in the requested currency.** Updating the four price-emitting paths in `class-wc-ai-storefront-ucp-product-translator.php` (`extract_price_range`, `extract_list_price_range`, variant translator, bundle range extractor) so the response carries the requested currency. Closes the Phase 1 honesty gap: the agent's recommendation (in EUR) matches the catalog data it consumed (also in EUR), not just the PDP the buyer lands on.
3. **Loosening the `context.currency != base → drop filter` branch** in `map_ucp_search_to_store_api()` when the requested currency is in `accepted_currencies`. Today this drops `filters.price` and emits a `currency_conversion_unsupported` warning; Phase 2 should accept the filter in the requested currency.
4. **Aligning `process_line_item` validation** in the checkout-link path. Today line-item `expected_unit_price.currency` must match the base; Phase 2 should accept any code in `accepted_currencies`.
5. **Per-currency cache keys.** Cache invalidator and translator caches today are keyed on `(product_id, ...)` — Phase 2 needs `(product_id, currency, ...)` so a EUR cache lookup doesn't return a USD-quoted body.
6. **Integration test story.** At least one happy-path test against a real WCPay mock that asserts USD vs EUR catalog responses round-trip cleanly.

Phase 1's design must not preclude Phase 2:

- `WC_AI_Storefront_Multi_Currency` exposes only `get_accepted_currencies()` and `stamp_currency_query()` — leaving room to add `set_active_currency()`, `is_currency_supported( $code ): bool`, or a scoped `with_active_currency( callable $fn )` wrapper without churning the existing call sites.
- The controller's new private `get_request_currency( WP_REST_Request $request ): ?string` (introduced by Phase 1 to read `context.currency` once per handler) is the natural integration point for Phase 2's "which currency are we dispatching in" — Phase 2 reads from the same helper and adds the WCPay switch around the `rest_do_request()` Store API call.

## See also

- `docs/engineering/ARCHITECTURE.md` — component overview.
- `docs/engineering/API-REFERENCE.md` — UCP endpoint shapes.
- `docs/engineering/HOOKS.md` — filter/action catalog.
- `docs/engineering/JSON-LD-SCHEMA.md` — JSON-LD field reference.
- `docs/engineering/TESTING.md` — PHPUnit conventions.
- `docs/engineering/RELEASE.md` — version bump and changelog flow.
