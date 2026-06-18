# Base-Currency Shopify Feed — Design

**Date:** 2026-06-17
**Status:** Approved (design); pending implementation plan
**Branch:** `fix/base-currency-feed`

## Context

WooPayments multi-currency converts product prices to a geo-detected *presentment* currency at display time. The Shopify-compatible products feed (`class-wc-ai-storefront-products-feed.php`) builds prices via `$product->get_price()` — the `'view'` context — which fires the `woocommerce_product_get_price` filter that multi-currency uses to convert. So the feed emits the presentment currency, not the store base.

Each feed endpoint caches its output under a host+version-keyed transient (`CACHE_TTL = 1 hour`) with **no currency dimension** in the key. Whichever currency was active when an endpoint's cache was built gets *frozen* and served to every subsequent request regardless of that request's geo — **per-endpoint cache poisoning**.

Confirmed live on saltwarp.shop (USD base, CAD presentment for Canada). Two independent agents (Claude, ChatGPT), both US-based, fetched `/products/canvas-belt.json` and **both got `64.99`** (a CAD-frozen cache, poisoned by Canada-routed fetches during diagnosis) while the UCP API and the bulk `/products.json` returned `45.99` (USD base). The scoped per-product endpoint — the one a fetch-only agent most reliably reaches (the bulk feed *truncated* before the belt for ChatGPT) — served a price **+41 % wrong** and unlabeled. Agents that cross-checked ≥4 surfaces caught it and quoted `45.99`; a single-surface agent quotes the wrong price.

The Shopify `products.json` shape is, by convention, a single base-currency surface (currency discovered out-of-band). Our feed must match that: always base currency, geo-independent.

## Goal

Make the Shopify-compatible feed — every price-bearing endpoint (`/products.json`, `/products/{handle}.json`, `/collections/{handle}/products.json`) — emit the store's **base currency** regardless of request geolocation or any active multi-currency presentment, so its prices are stable, cacheable, and consistent with the UCP API and the storefront base.

## Non-goals

- **No change to the product-page JSON-LD** (`Offer`/`BuyAction`). It stays geo-presentment: a dual human-SEO/agent surface that correctly labels `priceCurrency` and honors `?currency=`. (Locked with user.) The homepage/archive `ItemList` JSON-LD shares that emitter and stays geo-presentment too.
- **No change to the UCP catalog API** — it already returns base currency, labeled, by default. It is the model this fix brings the feed up to.
- **No currency field added to the feed.** Stay strictly Shopify-faithful — bare base-currency numbers; currency declared out-of-band in `/llms.txt` (which already states the base). (Locked with user.)
- **No per-currency support on the feed** (the feed does not honor `?currency=`). Shopify feeds are single-currency; sophisticated agents get per-currency pricing via `context.currency` on the UCP API, as `/llms.txt` already advertises.
- No conversion logic — we read the already-stored base price, never convert.

## Decisions (locked with user)

- **Approach A — read prices in the `'edit'` context.** `$product->get_price('edit')` returns the raw stored value *without* the `'view'`-context `woocommerce_product_get_price` filter that multi-currency uses to convert. WooCommerce stores prices in base currency, so `'edit'` *is* base. Decoupled from WooPayments internals; no global-state manipulation, no try/finally restore.
  - **Fallback — Approach B** (force base currency around the render via try/finally) is used **only if** the live verification gate shows `'edit'` does not bypass WooPayments on the real store.
- **Cache:** always-base output is geo-independent, so the cache can never re-poison and needs no currency key. Existing poisoned entries are abandoned by a one-time feed-cache-version bump on upgrade.

## Design

### Components

1. **`base_price()` helper** — new `private static` on `WC_AI_Storefront_Products_Feed`:
   ```php
   private static function base_price( $product ): string {
       return (string) $product->get_price( 'edit' );
   }
   ```
   Centralizes the "this feed is base currency" intent in one named, documented place. The docblock records *why* `'edit'`: it bypasses the multi-currency `'view'`-context display filter, and WC stores prices in base currency.

2. **`map_product()` price reads** → use `self::base_price( $product )` for the simple-product / default-variant price (line 801) and `self::base_price( $variation )` for each variation (line 904), replacing the bare `$product->get_price()` / `$variation->get_price()` calls.

3. **`compare_at()`** (≈ line 826) → read `$product->get_regular_price( 'edit' )` for the returned regular price. `is_on_sale()` is kept unchanged — it is a sale-vs-regular *ratio* comparison, so it is currency-invariant (a proportional conversion of both operands does not change the result).

4. **Cache-version bump on upgrade** → in the existing version-mismatch branch (`includes/class-wc-ai-storefront.php:310`, the `$stored_version !== WC_AI_STOREFRONT_VERSION` block, right where it `update_option( 'wc_ai_storefront_version', … )`), call `WC_AI_Storefront_Cache_Invalidator::bump_products_feed_version()` once. Pre-fix CAD-poisoned transients are abandoned immediately on activation rather than lingering up to `CACHE_TTL` (1 hour).

### Data flow

request → `serve_products_json` / `serve_single_product` / `serve_collection_products` → `map_product()` → `base_price()` / `compare_at()` read the `'edit'` context (raw base) → `money()` formats a 2-decimal string → response cached under the existing host+version-keyed transient, now **always base, geo-independent** → edge `Cache-Control: public, max-age=300`.

### Edge cases

- **Store without multi-currency:** `get_price('edit') == get_price('view')`, so output is byte-identical to today — zero behavior change. The fix is inert except where a presentment filter is active.
- **Variable products:** each variation's base price via `base_price($variation)`; the parent `price_range`/variants are all base.
- **On sale:** `compare_at()` returns the base regular price (`get_regular_price('edit')`); `is_on_sale()` determines sale-active (currency-invariant). The `price` is the base active price via `base_price()` (sale price when on sale, since `get_price('edit')` reflects the stored sale price).
- **Unpriced product:** `get_price('edit')` returns `''` → `money('')` → `'0.00'` (unchanged from today).
- **`'edit'` does not bypass WooPayments (unlikely):** caught by the live verification gate in the plan → switch to Approach B (force base currency around the render in a try/finally).

## Testing

Mirror the existing mapper tests (`tests/php/unit/ProductsFeedMapperTest.php`, Brain Monkey + Mockery):

- **Base-currency immunity (product):** mock a product whose `get_price('view')` returns a *converted* value (e.g. `'64.99'`) and `get_price('edit')` returns base (e.g. `'45.99'`); assert `map_product()` emits `price = '45.99'` — the feed reads base, ignoring the presentment.
- **Base-currency immunity (variation):** same shape for a variation under the variants branch.
- **`compare_at` reads base:** mock `get_regular_price('view') = '79.99'`, `get_regular_price('edit') = '64.99'`, `is_on_sale() = true`; assert `compare_at_price = '64.99'`. And `null` when `is_on_sale() = false`.
- **No-multi-currency regression:** when `'edit' == 'view'`, the mapped output equals the current fixtures (existing tests stay green).

## Verification (live, post-deploy)

- `/products/canvas-belt.json`, `/products.json`, and `/collections/belts/products.json` all return **`45.99`** (base USD) from a Canada-geolocated request — the CAD `64.99` is gone.
- Confirm `'edit'` actually bypassed WooPayments on the live store (this is the gate that decides A vs the B fallback).
- Re-run the fresh-agent cross-check (Claude/ChatGPT): every feed surface now agrees with the UCP API and the storefront base; the +41 % scoped-endpoint outlier is resolved.

## Files

- `includes/ai-storefront/class-wc-ai-storefront-products-feed.php` — `base_price()` helper; `map_product()` (×2 reads) + `compare_at()` read `'edit'`.
- `includes/class-wc-ai-storefront.php` — one-line feed-cache-version bump in the upgrade branch.
- `tests/php/unit/ProductsFeedMapperTest.php` — base-currency immunity tests + regression.

## Out of scope / deferred

- Product-page JSON-LD base-currency option (decided: stays geo-presentment).
- **Checkout-anchor reachability** — a separate finding from the same agent test: the `wp_footer` anchor sits ~59 % down a 242 KB product page (past where Claude's `web_fetch` truncated), and the concrete per-variant `<a href>` URLs are dropped by markdown extraction while the construct-kit `<code>` template (URL-as-text) survives. To be its own brainstorm (likely: render higher via `woocommerce_single_product_summary`, and surface actionable URLs as visible text).
