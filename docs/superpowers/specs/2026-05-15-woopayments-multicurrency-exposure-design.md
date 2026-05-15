# WooPayments multi-currency exposure (Phase 1)

**Date:** 2026-05-15
**Status:** Approved for implementation
**Scope:** Phase 1 of 2. Phase 2 (live currency switching on UCP product/checkout endpoints) is tracked separately as follow-up work.

## Audience and purpose

This document specifies how the WooCommerce AI Storefront plugin will publish the list of currencies a store accepts, when WooPayments' multi-currency feature is active. It is the design contract that the implementation plan will be derived from.

The reader is a developer about to implement, review, or extend this feature. Cross-references to engineering docs use relative paths from the repo root.

## Goal

When a store runs WooPayments with the multi-currency feature enabled, machine-readable surfaces (UCP manifest, homepage JSON-LD, llms.txt) should declare the full set of currencies the store accepts at checkout, not only the base currency.

Stores without WooPayments — or with the multi-currency feature disabled — continue to emit a single-element list containing the base currency. Output shape is stable across both states.

## Non-goals (Phase 1)

- Quoting catalog prices in non-base currencies. UCP `catalog/search`, `catalog/lookup`, and per-product JSON-LD continue to return base-currency-denominated prices. See `Follow-up work` for the Phase 2 spec hook.
- Switching WooPayments' active currency in response to UCP `context.currency`. Today the controller drops mismatched price filters with a `currency_conversion_unsupported` warning; that behavior is unchanged here.
- Currency-switcher UI on the storefront.
- Detection of non-WooPayments multi-currency plugins (CURCY, WC Currency Switcher, etc.). Those integrators use the override filter.
- Per-product JSON-LD multi-currency `Offer` arrays.

## Surfaces

Three public surfaces gain currency-list awareness.

| File | Field | Before | After |
|---|---|---|---|
| `includes/ai-storefront/class-wc-ai-storefront-ucp.php` (`build_store_context()`) | `store_context.accepted_currencies` | absent | array of ISO-4217 codes, base first |
| `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` (homepage `OnlineStore` enricher) | `currenciesAccepted` | string `"USD"` | space-separated string `"USD EUR GBP"` per Schema.org convention |
| `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` | `**Accepted currencies**` line | absent | comma-separated codes plus a qualifier; omitted when the list has one entry |

The existing `store_context.currency` scalar (base currency) is unchanged. Agents that only read `store_context.currency` keep working without modification — that's the back-compat contract.

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

Per-request memoization is implemented via a `static` cache on the class so the three call sites can hit it without redundant work. No transients or persistent cache — `MultiCurrency::get_enabled_currencies()` is already memoized inside WCPay, and the UCP manifest endpoint is not a hot path.

### Caller integration

All three callers read the same array.

1. **`build_store_context()`** in `class-wc-ai-storefront-ucp.php` adds an `accepted_currencies` key alongside the existing `currency` scalar.
2. **Homepage JSON-LD enricher** in `class-wc-ai-storefront-jsonld.php` (around line 1897) replaces the single-code emission with `implode( ' ', $codes )`. Schema.org documents `currenciesAccepted` as a space-separated list of currencies — that's the canonical format.
3. **llms.txt builder** in `class-wc-ai-storefront-llms-txt.php` (around line 343, right after the existing `**Currency**` line) emits a new line only when `count( $codes ) > 1`. The qualifier string is hard-coded English; it's covered by a single `__()` call so translators can rephrase the parenthetical for other languages.

### New filter

`wc_ai_storefront_accepted_currencies` — receives the auto-detected list, returns the final list. Documented in `docs/engineering/HOOKS.md`.

Use cases:
- Non-WooPayments multi-currency plugins (CURCY, WC Currency Switcher, etc.) populate the list.
- Merchants curate the WooPayments list (e.g., hide a test-only currency).
- Integrators temporarily force single-currency for a regression repro.

The implementation rejects non-array filter returns, empty returns, and returns where no entry passes ISO-4217 validation. In all three failure modes, the list falls back to `[ base ]` — the field never disappears and never goes empty.

## Edge cases

| Case | Behavior |
|---|---|
| WooPayments not installed | `accepted_currencies: [ base ]`. |
| WooPayments installed, multi-currency feature off | `accepted_currencies: [ base ]`. (Guarded by `MultiCurrency::is_multi_currency_enabled()`.) |
| WooPayments enabled list excludes the store base (theoretical) | Base is prepended. List never lacks the base currency. |
| WCPay returns duplicate codes | Deduped, first occurrence wins for ordering after base. |
| WCPay returns malformed code (length != 3, non-alpha) | Dropped during ISO-4217 validation. Surfaces with a `wc_ai_storefront_logger` warning at debug level so we have a signal if a future WCPay refactor breaks the assumption. |
| Filter returns `null`, `false`, `[]`, or all-invalid codes | Falls back to `[ base ]`. |
| `get_woocommerce_currency()` returns empty (WC not loaded) | The helper returns `[ 'USD' ]` as the documented fallback. This matches the existing convention in `class-wc-ai-storefront-jsonld.php:435` and the UCP product translator. |

## Testing

Per `docs/engineering/TESTING.md`: one PHPUnit file per production class, Brain Monkey for WP/WC mocks, no real WordPress install needed.

### New file: `tests/php/unit/WCAIStorefrontMultiCurrencyTest.php`

Test methods follow `test_<what>_<conditions>_<outcome>` snake_case naming.

- `test_get_accepted_currencies_no_wcpay_returns_base_only`
- `test_get_accepted_currencies_wcpay_disabled_returns_base_only`
- `test_get_accepted_currencies_wcpay_enabled_returns_full_list_with_base_first`
- `test_get_accepted_currencies_base_missing_from_wcpay_list_is_prepended`
- `test_get_accepted_currencies_duplicates_are_deduped_preserving_order`
- `test_get_accepted_currencies_malformed_codes_are_dropped`
- `test_get_accepted_currencies_filter_can_override_list`
- `test_get_accepted_currencies_filter_returning_non_array_falls_back_to_base`
- `test_get_accepted_currencies_filter_returning_empty_falls_back_to_base`
- `test_get_accepted_currencies_filter_returning_all_invalid_codes_falls_back_to_base`
- `test_get_accepted_currencies_memoizes_within_request`

### Updates to existing test files

- `tests/php/unit/WCAIStorefrontUCPTest.php` — assert `store_context.accepted_currencies` is present, base-first, on both single- and multi-currency mocks.
- `tests/php/unit/WCAIStorefrontJsonldTest.php` — assert `currenciesAccepted` is space-separated when more than one code is enabled.
- `tests/php/unit/WCAIStorefrontLlmsTxtTest.php` — assert the `**Accepted currencies**` line is present (with qualifier) when multi-currency is active and absent when only the base is enabled.

No integration test against a real WooPayments install. Brain Monkey mocks the WCPay class and `MultiCurrency::instance()` return value.

## Versioning and changelog

This is a **MINOR** bump per `docs/engineering/RELEASE.md`:

- New UCP `store_context` field.
- New filter.
- Backwards-compatible — existing `currency` scalar is unchanged, single-currency stores see the new field as a one-element array rather than a behavior change.

Three places must agree on the new version: `woocommerce-ai-storefront.php` header **and** the `WC_AI_STOREFRONT_VERSION` constant, `package.json`, and `readme.txt` `Stable tag`.

CHANGELOG / USER-GUIDE updates happen in the **pre-release pass**, not during PR iteration, per project convention.

## Documentation impact

Per `AGENTS.md`'s path → doc map, the implementation PR touches the following engineering docs in the same commit set:

| Code path | Docs to update |
|---|---|
| New `includes/ai-storefront/class-wc-ai-storefront-multi-currency.php` | Add row to `AGENTS.md` path map + `.github/workflows/docs-followup.yml`; document in `ARCHITECTURE.md` |
| `includes/ai-storefront/class-wc-ai-storefront-ucp.php` | `API-REFERENCE.md`, `UCP-BUY-FLOW.md`, `ARCHITECTURE.md` |
| `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` | `ARCHITECTURE.md`, `HOOKS.md`, `JSON-LD-SCHEMA.md` |
| `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` | `ARCHITECTURE.md`, `HOOKS.md` |
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

1. Detecting whether the agent's `context.currency` is in the WooPayments enabled set.
2. Switching the WooPayments active currency for the duration of a Store API dispatch — likely via `MultiCurrency::set_user_selected_currency_code()` or equivalent, scoped to the request so we don't leak state.
3. Updating the four price-emitting paths in `class-wc-ai-storefront-ucp-product-translator.php` (`extract_price_range`, `extract_list_price_range`, variant translator, bundle range extractor) so the response carries the requested currency.
4. Loosening the `context.currency != base → drop filter` branch in the controller's `map_ucp_search_to_store_api` when the requested currency is supported.
5. Aligning `process_line_item` validation in the checkout-link path — and resolving the semantics for what happens when an agent submits a line item in a non-base currency but our final continue-URL lands on a base-currency checkout page.
6. The integration test story: at least one happy-path test against a real WCPay mock that asserts USD vs EUR responses round-trip cleanly.

Phase 1 must not preclude Phase 2: the helper class exposes `get_accepted_currencies()` only, leaving room to add `set_active_currency()` and `is_currency_supported()` siblings without churn at the call sites.

## See also

- `docs/engineering/ARCHITECTURE.md` — component overview.
- `docs/engineering/API-REFERENCE.md` — UCP endpoint shapes.
- `docs/engineering/HOOKS.md` — filter/action catalog.
- `docs/engineering/JSON-LD-SCHEMA.md` — JSON-LD field reference.
- `docs/engineering/TESTING.md` — PHPUnit conventions.
- `docs/engineering/RELEASE.md` — version bump and changelog flow.
