# WooPayments Multi-Currency Phase 2: Active-Presentment Switching

**Status:** Design approved, ready for implementation plan.

**Scope:** Phase 2 of 2. Builds on the Phase 1 work shipped in v0.17.0–0.17.2 ([2026-05-15 design](2026-05-15-woopayments-multicurrency-exposure-design.md)).

**Goal:** Close the Phase 1 honesty gap. When an agent sends `context.currency: EUR` on a UCP catalog or checkout request and EUR is in the store's accepted-currencies set, every price in the response is quoted in EUR (rate + rounding + charm applied per merchant settings) and the `filters.price` bounds are converted into the base currency for the Store API query. The `expected_unit_price` validation on checkout-session creation accepts any accepted-set currency, with the comparison happening in the agent's currency rather than base.

**Non-scope:**
- New transient/object-cache layers (none exist today; one-line guardrail in the spec ensures future caching keys by presentment currency).
- Currency-aware variants of Phase 1's filter override mechanism — Phase 1 already passes `context.currency` through unaltered; Phase 2 only changes what happens *inside* the dispatch.
- UCP MCP transport (Phase 2 scopes to REST only; MCP currency handling is its own work).

---

## Why this exists

Phase 1 lets the store **advertise** multi-currency support: the UCP manifest, llms.txt, and JSON-LD homepage all list `accepted_currencies` derived from WooPayments' enabled set. Phase 1 also **honors** the agent's `context.currency` hint by stamping `?currency=XXX` onto outbound `url` and `continue_url` fields, so the human buyer lands on WooPayments' page-level currency switcher in the right currency.

But Phase 1 stops short of **quoting** the catalog response itself in the requested currency. Today:

- `POST /catalog/search { context: { currency: "EUR" } }` returns prices with `currency: "USD"` (the store base).
- `filters.price` denominated in EUR is *dropped* with a `currency_conversion_unsupported` warning, because we don't have FX math.
- `expected_unit_price.currency` must equal base on `POST /checkout-sessions`, even when the agent's previous catalog response showed prices in EUR.

So the agent recommends a product based on USD prices, the buyer lands on a EUR checkout, and the two views aren't directly comparable. The honesty trade-off was deliberate and documented in the Phase 1 spec — it's what Phase 2 fixes.

WooPayments already does all the math we need: rate conversion, per-currency rounding, per-currency charm pricing. Phase 2's job is to wire that math into the UCP dispatch path without leaking presentment state into anything else.

---

## Architecture

Phase 2 turns the UCP REST adapter into a **currency-aware dispatch layer**.

When the agent sends `context.currency` and that code is in `get_accepted_currencies()`, the controller enters a **request-scoped presentment override** before any Store API call and exits it before returning. Inside the override, every price WCPay touches — catalog responses, filter conversions, line-item validation — flows through merchant-configured rate, rounding, and charm settings. Outside the override, nothing changes for human buyers, and no persistent state is written.

The override mechanism is WCPay's `wcpay_multi_currency_override_selected_currency` filter, defined in `woocommerce-payments/includes/multi-currency/Compatibility.php:83`. When hooked, `MultiCurrency::get_selected_currency()` reads our value first, before touching the WC session. WCPay built this filter for exactly this case — overriding presentment without committing to a session change — and uses it themselves in their URL-param compat layer.

A single new building block, `WC_AI_Storefront_Multi_Currency::with_active_currency( $code, callable $fn )`, hooks the filter, runs the callable, and unhooks in `finally`. Everything else is wiring: four call sites in the REST controller wrap their dispatches in this helper.

---

## Components

### 1. `WC_AI_Storefront_Multi_Currency::with_active_currency( string $code, callable $fn ): mixed`

The single new helper.

- Validates `$code` is in `get_accepted_currencies()`. If not, runs `$fn` unwrapped (no override hooked). This lets callers pass `context.currency` blindly; the helper is a no-op when it shouldn't apply.
- Hooks `wcpay_multi_currency_override_selected_currency` to return `$code`.
- Runs `$fn` inside a `try { ... } finally { remove_filter(...); }` block.
- Returns whatever `$fn` returns.
- If `$fn` throws, the filter is unhooked in `finally` and the exception propagates. The caller is responsible for graceful degradation (see Error Handling below).

WooPayments preconditions (plugin active + customer feature enabled) are inherited from `get_accepted_currencies()`'s existing gate — no new probe code.

### 2. `WC_AI_Storefront_Multi_Currency::convert_amount( int $minor_units, string $from, string $to ): int`

Wraps `WCPay\MultiCurrency\MultiCurrency::get_price( $value, 'product' )` to convert filter-bound amounts.

- Applies the merchant's per-currency rate, rounding, and charm settings.
- Returns the converted minor-unit integer.
- Used by the filter-conversion path for `filters.price.min` and `filters.price.max`.
- **Not** used for response prices — those flow through WCPay's existing per-product price hooks automatically while the override is active.
- Throws `RuntimeException` if WCPay isn't installed or the conversion fails; the caller is responsible for catching and falling back.

### 3. UCP REST controller wraps — four call sites

| Method | What to wrap |
|--------|--------------|
| `handle_catalog_search` | The `rest_do_request( /wc/store/v1/products )` dispatch |
| `handle_catalog_lookup` | The per-ID `fetch_product_via_store_api()` loop (one wrap around the whole loop, not per-ID) |
| `handle_checkout_sessions` (create + update) | The line-item resolution that calls `process_line_item`, so `expected_unit_price` validation runs in agent currency |
| Product translator price extractors | No wrap. They read from the Store API response already produced inside the wrapped dispatch. |

Each wrap reads `context.currency` via the existing private `get_request_currency( WP_REST_Request $request ): ?string` helper (introduced by Phase 1), passes the result to `with_active_currency`, and runs the existing dispatch logic inside the callable.

### 4. Filter-conversion logic in `map_ucp_search_to_store_api`

The Phase 1 "drop + emit `currency_conversion_unsupported` warning" branch (around line 4248 of the controller) is replaced.

New logic when `context.currency` is in the accepted set and differs from base:

```
if ( has filters.price.min ) {
    $params['min_price'] = convert_amount( filters.price.min, $context_currency, $base );
}
if ( has filters.price.max ) {
    $params['max_price'] = convert_amount( filters.price.max, $context_currency, $base );
}
```

When the converted bounds reach the Store API, the DB query runs against base-currency `_price` meta values. WCPay's existing per-product price hooks render the response prices in the agent's currency on the way back out. No new round-trip; no extra Store API call.

Floor/ceil rounding is **inherited from WCPay's `get_price()` logic** — we pass the agent's raw value straight to WCPay and let its `ceil_price()` + charm-addition logic decide the rounded bound. This is what produces the right behavior: a product the agent will see in the response at €19.99 (after rate+rounding+charm) falls inside a `max: 2000` (EUR) filter as the agent intended.

### 5. `process_line_item` validation change

The existing rule "expected_unit_price.currency must equal base" widens to "must equal `context.currency` if set, falling back to base if absent."

When `context.currency = EUR` and `expected_unit_price = { amount: 1999, currency: "EUR" }`:
- The outer `with_active_currency( "EUR", ... )` is already active.
- Inside the scope, `WC_Product::get_price()` returns the EUR-converted figure (because WCPay's per-product price hooks see `get_selected_currency() === "EUR"`).
- The comparison runs EUR vs EUR directly. No reverse math, no accumulated rounding error.

The existing `unit_price_currency_mismatch` error code from `WC_AI_Storefront_UCP_Error_Codes` covers the rejection case (agent sends GBP `expected_unit_price` when `context.currency` is EUR).

### 6. Caching guardrail (documentation only)

Today the UCP REST controller has no cross-request cache for product translations or Store API responses — only per-request memoization in `$this->request_context`, which is reset on every handler entry. No work to do.

But Phase 2 introduces a new dimension: response bodies are now currency-dependent. Add a one-line guardrail to the engineering docs:

> Any cross-request cache of translator output, price data, or `/wc/store/v1/products` response bodies MUST key on the active presentment currency (or never cache currency-sensitive fields).

This is one line in `docs/engineering/ARCHITECTURE.md`, not a piece of code work.

---

## Data flow

### Happy path: agent sends `context.currency: EUR`, EUR in accepted set

```
1. POST /catalog/search {
     context: { currency: "EUR" },
     filters: { price: { min: 5000, max: 10000 } }
   }

2. Controller reads context.currency → "EUR" (existing get_request_currency helper).

3. Validate via with_active_currency's inner check: "EUR" in get_accepted_currencies()? Yes.

4. Filter conversion (illustrative; actual values depend on merchant settings):
     min: convert_amount(5000, "EUR", "USD")   → some converted minor-units value
     max: convert_amount(10000, "EUR", "USD")  → some converted minor-units value
   The conversion is `WCPay\MultiCurrency\MultiCurrency::get_price($value, 'product')`,
   which applies the merchant's per-currency rate, rounding rule, and charm offset.

5. Enter currency scope:
     with_active_currency("EUR", function() use ($store_params) {
       return rest_do_request(
         GET /wc/store/v1/products?min_price=5439&max_price=10878
       );
     })

6. Inside the scope:
     - WCPay's override filter returns "EUR" when get_selected_currency() is called.
     - WCPay's per-product price hooks apply rate + rounding + charm.
     - Every product price in the Store API response is already EUR.

7. Translator builds the UCP response:
     - extract_price_range() reads response prices → EUR amounts.
     - Every price object: { amount: 1999, currency: "EUR" }.

8. Outbound URL stamping (existing Phase 1 work): adds ?currency=EUR
   to per-product url and continue_url.

9. Override filter is unhooked in finally. Response returned. No persistent state.

10. Response shape:
    {
      "ucp": { ... },
      "products": [
        {
          "id": "...",
          "url": "https://store.test/product/widget/?currency=EUR&utm_source=...",
          "price_range": {
            "min": { "amount": 1999, "currency": "EUR" },
            "max": { "amount": 2999, "currency": "EUR" }
          },
          ...
        }
      ]
      // No `messages` entry — happy path emits no warning.
    }
```

### `expected_unit_price` flow

Same shape: read `context.currency`, enter scope, run `process_line_item` inside the scope. `WC_Product::get_price()` returns the EUR-converted figure; comparison is EUR vs EUR. Exit scope before returning.

---

## Error handling

Three failure classes, each with a deliberate response. **Phase 2 never returns an HTTP error for currency issues** — it always degrades gracefully to base currency.

### 1. Agent sends `context.currency` not in `accepted_currencies`

- **Trigger:** Typo, stale manifest cache, hostile probing, or store with multi-currency disabled.
- **Behavior:** `with_active_currency` is a no-op (its inner check rejects the code). Dispatch runs in base. Response prices come back in base, every `currency` field says base.
- **Warning:** Append to response `messages`:
  ```json
  {
    "type": "warning",
    "code": "currency_conversion_unsupported",
    "path": "$.context.currency",
    "content": "Requested currency \"XYZ\" is not in this store's accepted set. Prices quoted in base currency \"USD\"."
  }
  ```

### 2. `expected_unit_price.currency` mismatches `context.currency`

- **Trigger:** Agent sends `context.currency: EUR` but `expected_unit_price: { currency: "GBP" }` on a line item.
- **Behavior:** Reject the line item with the existing `unit_price_currency_mismatch` code.
- **Rule:** `expected_unit_price.currency` must equal `context.currency` if set, or base if `context.currency` is absent.

### 3. WCPay throws during dispatch

- **Trigger:** Bug in WCPay, third-party hook explodes, partial-boot state, `convert_amount()` failing because rates aren't loaded.
- **Behavior:**
  - `with_active_currency`'s `finally` unhooks the override.
  - The exception is caught at the wrap site (the controller's handler method).
  - The handler re-runs the dispatch *without* the override (base currency).
  - The original exception is logged via `WC_AI_Storefront_Logger::debug` (same pattern as the Phase 1 WCPay probe).
  - Same `currency_conversion_unsupported` warning code as case #1 is emitted — agent-facing behavior is identical for "didn't ask correctly" and "asked correctly but something broke."
- The override filter never persists state, so there's no cleanup-on-fatal concern. If PHP dies mid-request, the worker process dies with it.

---

## Why these codes are spec-compliant

The UCP spec splits responsibility between two surfaces:

| Surface | Canonicity |
|---------|-----------|
| `request.context.currency` (input field) | **Canonical** — spec-defined ISO 4217 string |
| `response.products[*].price_range.min.currency` (output field) | **Canonical** — spec-defined ISO 4217 string on `price.json` |
| `response.messages[*].code` (freeform warning identifier) | **Freeform** — spec permits arbitrary code strings; published examples are non-exhaustive |

The canonical "did you get what you asked for?" channel is the explicit `currency` field on every `price` object in the response — *"Response prices include explicit currency confirming the resolution"* (`context.json` spec text). Agents read that field, compare to their `context.currency` request, and detect divergence without needing to interpret the warning code string.

Phase 2 reuses Phase 1's `currency_conversion_unsupported` warning code for the fallback paths. The code is freeform but consistent with what we already ship, so agents that learned to read it in v0.17.x keep their semantics. The canonical signal still lives on the price object.

---

## Testing

### Unit tests (PHPUnit + Brain Monkey)

**`MultiCurrencyTest` additions:**

- `with_active_currency` is a no-op when code not in accepted set (callable still runs, filter not hooked).
- `with_active_currency` hooks override filter, runs callable, unhooks on success.
- `with_active_currency` unhooks override filter on exception (the `finally` guarantee).
- `with_active_currency` returns whatever the callable returns.
- `convert_amount` applies rate + rounding + charm via WCPay's `get_price()`.
- `convert_amount` throws on WCPay unavailable (caller responsibility for fallback).

**`UcpRestControllerTest` additions** (split file if it grows too large):

- `handle_catalog_search` with `context.currency` in accepted set → override filter hooked during dispatch (Brain Monkey filter assertion).
- `handle_catalog_search` with `context.currency` not in accepted set → no override hooked + `currency_conversion_unsupported` warning emitted at `$.context.currency`.
- `handle_catalog_search` with `filters.price` + valid `context.currency` → converted bounds reach Store API (mock `rest_do_request`, assert params).
- `handle_catalog_search` with WCPay throw mid-dispatch → response in base + `currency_conversion_unsupported` warning + `Logger::debug` called.
- `handle_catalog_lookup` with `context.currency` → per-ID fetches all inside currency scope (single hook, not per-ID).
- `handle_checkout_sessions` with `expected_unit_price.currency` matching `context.currency` → validation runs inside scope (EUR vs EUR).
- `handle_checkout_sessions` with `expected_unit_price.currency` mismatching `context.currency` → rejected with `unit_price_currency_mismatch`.

### Integration test

One happy-path test against a WCPay mock:

- USD store, EUR in accepted set, rate 1.10, rounding 0.50, charm -0.01.
- `POST /catalog/search { context: { currency: "EUR" }, filters: { price: { min: 5000, max: 10000 } } }`.
- Assert:
  - Response prices carry `currency: "EUR"`.
  - No warning in `messages`.
  - Every `url` field carries `?currency=EUR`.
  - The Store API mock was called with `min_price`/`max_price` converted to base.

### Manual verification (post-merge, before release)

- Real WCPay store on saltwarp.shop with multi-currency enabled and three configured currencies (USD base, EUR, GBP).
- Trigger UCP catalog search with `context.currency: EUR` via curl; confirm:
  - Response prices say `EUR`.
  - Per-currency rounding and charm match what a human buyer sees on the PDP after switching to EUR.
  - `?currency=EUR` is on every outbound URL.
- Trigger same search with `context.currency: XYZ` (invalid); confirm:
  - Response prices say `USD`.
  - Single `currency_conversion_unsupported` warning at `$.context.currency`.
- Toggle WCPay multi-currency off mid-test (admin UI); confirm:
  - Subsequent UCP search with `context.currency: EUR` falls back gracefully (base USD response + warning).
  - No errors in PHP error log, only `WC_AI_Storefront_Logger::debug` entries.

---

## User-facing documentation

`USER-GUIDE.md` update — one new section under the multi-currency feature description:

> **Full WooPayments integration.** When your store uses WooPayments multi-currency, the AI Storefront plugin respects all your per-currency settings — exchange rate (manual or auto), rounding precision, and charm pricing. AI agents see the same prices a human buyer would see when switching to that currency on your storefront. No additional configuration required.

`HOOKS.md` — note the WCPay filter we hook (for transparency, not as a public extension point):

> `wcpay_multi_currency_override_selected_currency` — hooked during UCP REST dispatches when the agent requests a specific currency. The hook is added at request entry and removed in a `finally` block before the response is returned. Other plugins hooking this filter for their own purposes will see our value during our dispatch; this is the documented behavior of the WCPay filter.

---

## See also

- [`2026-05-15-woopayments-multicurrency-exposure-design.md`](2026-05-15-woopayments-multicurrency-exposure-design.md) — Phase 1 design, including the deferred-work list this spec closes.
- [`docs/engineering/ARCHITECTURE.md`](../../engineering/ARCHITECTURE.md) — UCP REST adapter overview, currently being extended with the caching guardrail.
- [`docs/engineering/API-REFERENCE.md`](../../engineering/API-REFERENCE.md) — UCP endpoint shapes, currently being updated for the post-Phase-1 `accepted_currencies` semantics.
- WCPay source — `wp-content/plugins/woocommerce-payments/includes/multi-currency/Compatibility.php:83` and `MultiCurrency.php:888`.
