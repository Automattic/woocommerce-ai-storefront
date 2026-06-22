# Multi-currency: convert UCP catalog/checkout prices to the requested currency (Phase 2 completion)

- **Date:** 2026-06-21
- **Status:** Shipped — Mechanism B confirmed working on WCPay 10.9 (live spike on saltwarp.shop). The dispatch-time `with_active_currency()` switch is implemented around every price-returning in-process Store-API dispatch (catalog/search, catalog/lookup single + batch, checkout-sessions line items); per-currency cache keys + the unaccepted-currency fallback warning are in place.
- **Issue:** #517
- **Depends on:** WooPayments **>= 10.9** (the Store-API currency fix; confirmed working on saltwarp.shop 10.9 RC).
- **Target:** 0.25.0 (minor).

## Problem (proven root cause)

When an agent sends `context.currency` (e.g. CAD) to the UCP catalog/checkout endpoints, response prices come back in the **store base currency**, not the requested currency — even on WCPay 10.9 with multi-currency enabled.

Phase 2 (#411) conveys the currency as a **param on the internal `rest_do_request`** Store API call:

```php
$store_request->set_param( 'currency', $request_currency ); // ineffective
```

But WooPayments switches currency from **`$_GET['currency']` at `init`** (`MultiCurrency::update_selected_currency_by_url()`, which `return`s unless `isset($_GET['currency'])`). For a non-Store-API REST request with no `$_GET['currency']` it actively **disables conversion** (`should_convert_product_price → __return_false`, `should_return_store_currency → __return_true`). A request-object param never reaches `$_GET`, so the inner dispatch returns base prices.

### Evidence (live, saltwarp.shop, WCPay 10.9 RC)

| Request | Result |
|---|---|
| `POST /catalog/search?currency=CAD` (currency in URL → `$_GET` set at init) | **6599 CAD** ✅ |
| `POST /catalog/search` body `context.currency=CAD` only (#411 path) | **4599 USD** ❌ |
| `POST /catalog/search?currency=GBP` | **3500 GBP** ✅ (rules out coincidence) |
| `GET /wc/store/v1/products?currency=CAD` (direct Store API, real HTTP) | **6599 CAD** ✅ (WCPay conversion itself works) |

So WCPay 10.9 is required and confirmed working; the remaining defect is plugin-side: the plugin must get the requested currency to WCPay the way WCPay reads it.

## Goal

On WCPay >= 10.9 with multi-currency enabled, UCP `catalog/search`, `catalog/lookup` (single + batch), and `checkout-sessions` line-item prices are returned in the agent's `context.currency` when that currency is in `accepted_currencies` — with WCPay's full conversion (rate + rounding + charm), matching the buyer-facing PDP. When the currency is base, absent, or not accepted, prices stay in base and an explicit `currency_conversion_unsupported`-style signal is surfaced (no silent base-for-requested).

## The key uncertainty — RESOLVED (Mechanism B confirmed)

The proven-working reference path (evidence row 1) is: **`$_GET['currency']` is set before WCPay's `init`-time switcher runs**, so WCPay registers its currency switch + price-conversion hooks and the inner Store API `rest_do_request` inherits the converted currency.

The open question was how to reproduce that from the plugin, since the agent sends `context.currency` in the **POST body** (parsed *after* WCPay's `init` switcher). Two candidate mechanisms were considered:

- **A — Early `$_GET` seed.** Read `context.currency` from `php://input` on `init:1` and set `$_GET['currency']` before WCPay's `init:10` switcher. **Tried and abandoned — it did NOT work:** the seed runs too late / the proven `?currency=` page-load path is not the same path a REST dispatch takes, so the inner dispatch still returned base prices. The dead code + its tests have been removed.
- **B — Dispatch-time active-currency switch. CONFIRMED WORKING (WCPay 10.9, live on saltwarp.shop).** Wrap the inner `rest_do_request` in `WC_AI_Storefront_Multi_Currency::with_active_currency($code, $fn)`: add the `wcpay_multi_currency_override_selected_currency` filter (priority 99) + `should_convert_product_price => __return_true` + `should_return_store_currency => __return_false`, **and** call `update_selected_currency($code, false)**, all restored in `finally`.

**Key finding:** the override filter alone is **not sufficient** — it converts the price *amount* but the response's currency *code* stays at the store base, because WCPay's FrontendCurrencies reads the **selected-currency object**, not the override filter. `update_selected_currency($code, false)` (non-persistent) is therefore **required in addition to** the override filter so both the amount and the code follow the requested currency. Live test: `catalog/search` body `context.currency=CAD` → CAD amount + CAD code; EUR → EUR; USD/none → base; unaccepted currency → base + warning; no state leak across requests.

WCPay's internal conversion path is not unit-testable (mocked in unit tests); the live spike is the integration oracle and it passed.

## Architecture

### Helper (single seam)

All currency-switch logic lives in `WC_AI_Storefront_Multi_Currency` (where `get_accepted_currencies()`, `stamp_currency_query()`, `convert_amount()` already live). The spike decides the body of the seam:

- Mechanism A → `seed_request_currency( ?string $code ): void` (+ a restore), invoked from an early hook registered in the controller bootstrap, gated to UCP catalog/checkout routes.
- Mechanism B → `with_active_currency( ?string $code, callable $fn ): mixed`, wrapping each dispatch.

Either way the seam **no-ops** when: WCPay absent, multi-currency disabled, `$code` null/malformed, `$code` === base, or `$code` not in `get_accepted_currencies()`.

### Call sites

The inner Store API dispatches that must run in the requested currency:

- `catalog/search` — `fetch_wc_products_for_search()` → `rest_do_request(GET /wc/store/v1/products)`.
- `catalog/lookup` — single + batch product fetch.
- `checkout-sessions` — `process_line_item()` price fetch / `expected_unit_price` comparison.

Remove the ineffective `set_param('currency', …)` and the "Store API accepts a native currency query param … no WCPay filter override needed" comment (it is the wrong assumption).

### Per-currency cache keys

Now that response bodies legitimately differ by currency, every cache key on the catalog path (translator/response caches, and any catalog cache the cache-invalidator owns) must include the resolved currency, so a CAD lookup can't return a cached USD body. This was the Phase 1 spec's deferred TODO #5 and is now required.

### Unaccepted / fallback signal

When `context.currency` is set but not in `accepted_currencies` (or WCPay can't convert), return base prices **and** emit the existing `currency_conversion_unsupported` warning at the response level (today it only fires when a price *filter* is dropped). Agents must never get base-priced results for a requested currency with no signal.

## Edge cases

- WCPay absent / MC disabled / single-currency store → no-op; base prices; no warning needed when only base is accepted.
- `context.currency` === base → no switch; base prices; no warning.
- Malformed `context.currency` → ignored (existing ISO-4217 validation).
- Variations / bundles / `price_range` / `list_price_range` → all read from the same Store API response, so the single switch covers them.
- State leak: the switch/seed must be fully restored per request (`finally` / end-of-request), verified by a test that a second request in the same process is unaffected.

## Testing

- **Spike (Task 1):** deploy the branch to saltwarp; assert `catalog/search` with `context.currency=CAD` returns CAD at the converted amount (≈ base × FX, matching the PDP); GBP too; base/USD unchanged; unaccepted currency → base + warning.
- **Unit (Brain Monkey + WCPay stub):** the seam is invoked with the resolved currency around each dispatch; no-op conditions (absent WCPay, base, unaccepted); cache key includes currency; `$_GET`/selected-currency restored after. WCPay's actual conversion is not unit-tested (mocked) — the spike is the integration oracle.
- Update `UcpCatalogSearchTest`, `UcpCatalogLookupTest`, `UcpCheckoutSessionsTest`, `MultiCurrencyTest`.

## Docs to correct (now inaccurate)

- Controller comments claiming "the Store API returns prices already converted … no WCPay filter override needed" → describe the actual mechanism + the WCPay >= 10.9 dependency.
- `WC_AI_Storefront_Multi_Currency` docblock "Phase 2 (deferred)" → update to "shipped, requires WCPay >= 10.9".
- `USER-GUIDE.md` "Full WooPayments multi-currency support" → state the WCPay >= 10.9 requirement (on <10.9, catalog prices stay base).
- `docs/engineering/` per the path→doc map (API-REFERENCE, UCP-BUY-FLOW, ARCHITECTURE) + a `KNOWN-GAPS.md` note that <10.9 returns base.

## Versioning

MINOR (0.25.0). New behavior (currency-converted catalog/checkout prices) gated on WCPay >= 10.9; backwards compatible (older WCPay → base prices, as today). CHANGELOG/readme/USER-GUIDE at the pre-release pass. `skip-changelog` on the feature PR.

## Process

Tracking issue #517; branch `fix/multicurrency-catalog-conversion`; PR references #517. No direct push to `main`; no self-merge. Verification is on saltwarp.shop (10.9) — built here, deployed by the maintainer to test.
