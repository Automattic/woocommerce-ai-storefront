# Product-Page "Agent Checkout" Anchor — Design

**Date:** 2026-06-17
**Status:** Approved (design); pending implementation plan
**Branch:** `feat/product-agent-checkout-anchor`

## Context

A live test of Claude (0.21.0, personal account, no project history) on saltwarp.shop showed the agent now **discovers** well — it fetched the site, found the Canvas Belt at $45.99, read the product page, and extracted the sizes (S/M, L/XL), SKU, and category from the rendered HTML. But it **failed to get a usable buy link**: the deterministic checkout URL (`BuyAction.urlTemplate`) lives in a `<script type="application/ld+json">` block, and markdown-extraction fetch tools (claude.ai `web_fetch`) strip `<script>` wholesale. The agent *knew* (from llms.txt) the BuyAction existed but structurally could not read it, and could not construct the per-product UCP query itself (the snap-to-seen / allowlist constraint blocks novel URLs; no POST).

This is the per-product transact counterpart to #463: #463 made the **discovery** surface (`/llms.txt`) reachable to markdown fetchers via a visible body anchor; this makes the per-product **checkout link** reachable the same way — closing the discover→handoff loop that currently breaks exactly at the BuyAction step.

## Goal

On single-product pages, render the plugin's existing deterministic per-product checkout link as a **visible body anchor** that markdown-extraction agents can extract and hand to the buyer — emitting the same URLs as the `<script>` JSON-LD BuyAction, just where these tools can read them.

## Non-goals

- No new checkout/cart logic and no new URL shapes — reuse the existing `build_checkout_url_template()`.
- No POST/agent-completes-purchase. The buyer still clicks through to the store's own checkout (buyer-confirmed).
- No change to the `<script>` JSON-LD BuyAction (it stays for structured-data consumers); this is an additive, body-visible counterpart.
- No new merchant setting.

## Decisions (locked with user)

- **Placement:** `wp_footer`, gated on `is_product()`, rendered small + muted — the same theme-agnostic, unobtrusive mechanism as #463's `render_discovery_link()`. (Chosen over WC single-product template hooks for theme-reliability and low human-visibility; agents read the whole body regardless of position.)
- **Variant handling — construct-via-template, with convenience links for small-variant products:**
  - **Simple products** → one direct working anchor.
  - **Variable products** → a "construct kit": the checkout URL **template**, a visible link to **`/products/{handle}.json`** (the uncapped, parameterless full variation list with `id` + `option1/2/3`), and — **only when the product has ≤ 4 purchasable variations** — concrete labeled per-variant links (e.g. *S/M* → `?products=3893:1`). Products with more than 4 variations get just the template + the `.json` link (no concrete links, to avoid flooding or implying the first-N are complete).
  - **Bundle / grouped** → the permalink-based link that `build_checkout_url_template()` already returns (lands the buyer on the PDP to configure).
- **`{agent_id}` placeholder:** keep it (plus a one-line "replace `{agent_id}` with your agent id" note) — identical semantics to the existing `BuyAction.urlTemplate`. The placeholder only affects attribution, not whether the link checks out the right item, so it functions if handed as-is and lets identity-aware agents attribute correctly (dovetails with #465 UA attribution).
- **Gating:** `enabled === 'yes'` **and** `is_product_syndicated($product)` — the same gate the product JSON-LD already applies. Non-purchasable variations are skipped (reusing the #373 `is_purchasable()` guard).

## Design

### The rendered block

A small muted block (mirroring #463's styling: centered, ~12px, low opacity) printed in `wp_footer` on single-product pages, for the current product:

- **Simple:** `Agent checkout: <a href="…/checkout-link/?products=<id>:1&utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld">buy this item</a> (replace {agent_id} with your agent id).`
- **Variable (≤4 purchasable variations):** a short labeled list — one `<a>` per variation (`<option summary> → …/checkout-link/?products=<variation_id>:1&…`) — plus the template line and the `/products/{handle}.json` link.
- **Variable (>4 variations):** the template line + the `/products/{handle}.json` link only.
- **Bundle / grouped:** the single permalink-based link `build_checkout_url_template()` returns.

The URLs are produced **exclusively** by the existing `build_checkout_url_template()` (called with the product for simple/bundle/grouped, and with each variation object for per-variant links), so the body anchor and the `<script>` BuyAction can never diverge.

### Components

- **`render_product_checkout_links()`** — new public method on `WC_AI_Storefront_JsonLd` (it already owns `build_checkout_url_template()` and the variation-enumeration path used for `ProductGroup`/`hasVariant`). Hooked on `wp_footer`. Steps: bail unless `is_product()`; resolve the current `WC_Product`; bail unless `enabled` + `is_product_syndicated()`; branch by product type; build the URL(s) via `build_checkout_url_template()`; `printf` the muted block with every dynamic value `esc_url`'d / `esc_html`'d.
- **Accessor for `build_checkout_url_template()`** — currently `private static`; expose via a thin `public static` wrapper (or widen visibility) so the renderer can call it without duplicating logic.
- **Variation enumeration + label** — reuse the existing `get_children()` → `resolve_variation()`/`wc_get_product()` path; derive each variant's human label from its variation attributes (the same source `build_variant_entry()` uses), skipping non-purchasable variations.
- **`/products/{handle}.json` URL** — `home_url( '/products/' . $product->get_slug() . '.json' )`, emitted only when the `products_json_enabled` toggle is on (it's the source the construct path points at); when that feed is off, variable products emit the template + concrete links (≤4) but omit the `.json` link.
- **Hook registration** — `add_action( 'wp_footer', [ $jsonld, 'render_product_checkout_links' ] )` in the main bootstrap (alongside the existing `wp_footer` registrations), mirroring how `render_discovery_link()` is wired.

### Edge cases

- **No purchasable variations / unpurchasable simple product** → emit nothing (don't hand out a link WC would reject at checkout; reuses the #373 guard).
- **`products_json_enabled` off** → variable products omit the `.json` construct link (it wouldn't resolve) and rely on the template + ≤4 concrete links; simple/bundle/grouped unaffected.
- **Disabled or unsyndicated product** → renders nothing.
- **Caching** → product pages are not cached by the plugin (the footer block renders live per request, like #463's anchor and WC's own structured-data output).

## Testing

Mirror `JsonLdTest.php` (Brain Monkey + Mockery):
- Simple product → one `/checkout-link/?products=<id>:1` anchor with the canonical UTM shape; nothing extra.
- Variable with ≤4 purchasable variations → a concrete labeled link per variation (each using the **variation** id, not the parent), plus the template line and the `/products/{handle}.json` link.
- Variable with >4 variations → template + `.json` link, **no** concrete per-variant links.
- Non-purchasable variation skipped from the concrete links.
- Bundle / grouped → the permalink-based link.
- `products_json_enabled` off → `.json` link omitted for variable products.
- Gating: renders nothing when `enabled !== 'yes'` or `is_product_syndicated()` is false, or when not `is_product()`.
- The emitted URLs are byte-identical to what `build_checkout_url_template()` produces for the same product/variation (no divergence from the JSON-LD BuyAction).

## Files

- `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` — `render_product_checkout_links()` + a public accessor for `build_checkout_url_template()` + a variant-label helper (or reuse).
- `includes/class-wc-ai-storefront.php` — register the `wp_footer` hook.
- Tests: `tests/php/unit/JsonLdTest.php` (or a new focused `JsonLdProductCheckoutLinksTest.php`).

## Out of scope / deferred

- Per-variant links for products with >4 variations (the `.json` construct path covers them; emitting all would flood).
- A merchant toggle to hide the block (it's `enabled`-gated; revisit only if a merchant asks).
- Surfacing the same per-product link inside `/llms.txt` (llms.txt already documents the BuyAction approach generally).

## Implementation note (post-build, 2026-06-17)

Live smoke against the rendered page revealed a constraint the design above missed: **`esc_url()` strips `{` `}` from a clickable `<a href>`**, so a directly-clickable link can never carry the `{agent_id}` placeholder (and `esc_url` is mandatory). Rendering the placeholder into an `<a href>` produced `utm_source=agent_id` — both a divergence from the `<script>` BuyAction and a broken literal in order attribution. Resolution (locked with user):

- **Clickable surfaces** (simple link, bundle/grouped link, ≤4 concrete variant links) carry a real, `esc_url`-safe source — the no-identity sentinel `ucp_unknown` (`WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE`), the same fallback the rest of the attribution system uses. Honest, working, and clickable.
- **The construct-kit `<code>` template** keeps the `{agent_id}` placeholder (`esc_html` preserves the braces) and remains the faithful byte-identical mirror of the BuyAction `urlTemplate` for agents that substitute their own id.

So the "can never diverge" invariant is scoped precisely: the **`<code>` template** is byte-identical to the BuyAction; the **clickable links** intentionally carry `ucp_unknown` (a placeholder in a clickable href is impossible), reusing `build_checkout_url_template()` via a new `$agent_source` parameter so URL *shape* still flows from a single source.
