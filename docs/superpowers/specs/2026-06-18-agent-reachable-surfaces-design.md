# Agent-Reachable Surfaces — Design

**Date:** 2026-06-18
**Status:** Approved (design); pending implementation plan
**Issues:** #479 (front-page shop ItemList), #477 (checkout anchor reachability), #478 (bulk feed image bloat), #480 (steer agents to the feeds for images)
**Branch:** `fix/agent-reachable-surfaces` (planning); each fix lands as its own PR

## Context

Live shopping tests (Claude, ChatGPT, Perplexity) against saltwarp.shop surfaced a cluster of reachability problems sharing one root cause: **markdown-extraction fetch agents strip `<script>` JSON-LD and `<head>` links, drop `<a>` hrefs (keeping only the link text), and truncate long pages** — so only visible body text near the top survives. Three concrete failures, each filed as an issue:

- **#479** — On the root domain (which *is* the WooCommerce shop archive on saltwarp), agents get navigational JSON-LD only (`OnlineBusiness`/`WebSite`) and **zero product/price structured data**, while `/shop/` carries the full `ItemList` + `Product` blocks with prices. Agents "do fine on /shop, struggle on the root."
- **#477** — The 0.22.0 product-page checkout anchor renders in `wp_footer` (~59% down a 242 KB page), past where Claude's `web_fetch` truncated; and its concrete `<a href="…">checkout</a>` links lose their hrefs in extraction (only the `<code>` URL template survived).
- **#478** — The bulk `/products.json` truncates for agents before reaching deep products (ChatGPT never reached the ~26th-of-30 Canvas Belt).
- **#480** — Agents never surface product **images**: the page's `<img>` tags and JSON-LD `image` are stripped by extraction, so the image URL is only reachable in the `*.json` feeds' `images[].src` — but `/llms.txt` lists those feeds without saying images are there, so agents try (and fail on) the page HTML.

These are four independent fixes across three files; they share the theme and ship as **separate PRs**, one per issue. #478 and #480 are the two halves of "agents get product images" — #478 ensures the feeds *carry* an image; #480 *points* agents at the feed for it.

> **Related, specced separately:** two more reachability fixes ship in the same release bundle but are tracked outside this spec — #481 (relabel the Discovery admin card to "AI shopping-API activity"; PR #487) and #489 (move the `/llms.txt` discovery anchor from `wp_footer` to `wp_body_open` so it survives truncation on the front-page shop; folded into the #477 PR because it shares that PR's registration method). Both surfaced during review, after this design was approved.

## Goal

Make the agent-facing surfaces reachable to markdown-extraction agents: products + prices on the root, the checkout link high-on-page and extractable, and the bulk catalog small enough to read.

## Non-goals

- No change to the visible human experience beyond a small muted agent-facing block moving to the top of product pages.
- No change to currency behavior (0.22.1 settled that) or the UCP API. The only `/llms.txt` change is #480's single additive image-steering line in the existing read-only-browsing section — existing sections and content are untouched.
- No new merchant settings.

## Decisions (locked with user)

- **#477 placement → `wp_body_open`** (gated `is_product()`): renders at the top of `<body>` on all themes (block *and* classic — a WP core hook), guaranteeing it's inside the extraction window. Chosen over `woocommerce_single_product_summary` (a classic-template hook that pure block themes — like saltwarp's — may not fire) and over `the_content` (mid-page).
- **#477 URL rendering → `<code>` text**: every actionable checkout URL renders as visible monospace text, not as an `<a href>` with short link text. Consistent with the construct-kit template that already survives extraction; the agent extracts the URL and relays a clickable link to the buyer in chat, so on-page clickability isn't the flow.
- **#478 → compact images on the *list* feeds, one image minimum**: the multi-product list endpoints (bulk `/products.json` and per-collection `/collections/{handle}/products.json`) emit **one** image per product — the **first valid** image (the featured image if set, else the first valid gallery image; zero only when the product has no resolvable image at all). The single-product `/products/{handle}.json` keeps **all** images. *"Always at least one image if the product has any, even with no featured image set."*

## Design

### #479 — front-page shop emits the product `ItemList`

`output_archive_itemlist_jsonld()` (hooked on `wp_head` priority 6) currently computes its gate as:

```php
$on_shop = function_exists( 'is_shop' ) && is_shop() && ! is_front_page();   // line ~3521
```

The `! is_front_page()` clause excludes the shop when it is the site's front page — exactly saltwarp's configuration — so the root gets no `ItemList`. **Fix:** drop the exclusion:

```php
$on_shop = function_exists( 'is_shop' ) && is_shop();
```

Now the `ItemList` emits whenever the shop archive renders, including when it is the front page. The front-page shop then carries **both** the `OnlineBusiness` block (priority 5, gated `is_front_page() || is_shop()`) and the `ItemList` (priority 6). Static, non-shop front pages are unaffected — `is_shop()` is false there, so still no `ItemList`. The other archive gates (category/tag/search) are unchanged.

### #477 — checkout anchor: reachable placement + extractable URLs

Two changes in the JSON-LD class + bootstrap:

1. **Hook move.** In `includes/class-wc-ai-storefront.php`, change the registration (line 187) from `add_action( 'wp_footer', [ $jsonld, 'render_product_checkout_links' ] )` to `add_action( 'wp_body_open', [ $jsonld, 'render_product_checkout_links' ] )`. `render_product_checkout_links()` already self-gates on `is_product()` + enabled + syndication, so it stays a no-op on non-product pages and renders nothing extra at the top of those.

2. **URLs as `<code>` text.** In `build_checkout_anchor_lines()`, replace the `<a href="…">label</a>` constructions with visible `<code>` URLs (the URL is the visible text), keeping the existing labels:
   - **Simple:** `Agent checkout: <code>{accessor-url}</code>`
   - **Bundle / grouped:** `Agent checkout (<name>): <code>{accessor-url}</code>`
   - **Concrete per-variant (≤4):** `{variation label}: <code>{accessor-url}</code>`
   - The construct-kit `{variation_id}` template and the `/products/{handle}.json` link are unchanged (the template is already `<code>`; the feed link stays an `<a>` since its short text *is* the URL handle).

   URLs still come exclusively from `checkout_url_template()` (the cardinal byte-identity invariant holds). With `<code>`, `esc_html()` is the correct escaper for the URL-as-text (the `esc_url`-strips-`{}` concern from the clickable links no longer applies — these are no longer hrefs; the clickable links carried `ucp_unknown`, the `<code>` text keeps whatever the accessor emits, which for these concrete URLs is `ucp_unknown` as before).

### #478 — compact images on the list feeds

Add a `$compact` parameter threaded from the list serve methods through `map_product()` to `build_images()`:

```php
private static function build_images( $product, bool $compact = false ): array {
    $ids    = array_unique( array_filter( array_merge(
        [ (int) $product->get_image_id() ],
        array_map( 'intval', (array) $product->get_gallery_image_ids() )
    ) ) );
    $images = [];
    foreach ( $ids as $id ) {
        $src = wp_get_attachment_image_url( $id, 'full' );
        if ( is_string( $src ) && '' !== $src ) {
            $images[] = [ 'id' => $id, 'src' => $src ];
            if ( $compact ) {
                break; // first VALID image only: featured if set, else first valid gallery
            }
        }
    }
    return $images;
}
```

`map_product( $product, bool $compact = false )` passes `$compact` to `build_images()`. The **list** serve paths (the bulk `/products.json` loop and the per-collection `/collections/{handle}/products.json` loop — the two `$mapped[] = self::map_product( $product )` sites) pass `true`; the **single** `/products/{handle}.json` path (`map_product( $product )` in the `{ "product": … }` wrapper) keeps the default `false`. Default-false preserves every existing caller and test.

The ≥1-image rule is not only about feed size — it's what lets an agent show *a* photo for every product (the supply side of #480).

### #480 — steer agents to the feeds for images

Add one line to the `## Read-only browsing` section of the `/llms.txt` generator (`class-wc-ai-storefront-llms-txt.php`), where the `*.json` feeds are already listed (flows automatically to the byte-identical `/agents.md` mirror), stating that **product image URLs are in the `*.json` feeds** (`images[].src`) because page `<img>` tags and `<script>` JSON-LD are stripped by markdown-extraction fetch tools. Gated on `products_json_enabled` like the other `*.json` lines. No code path changes — this is a content addition to a doc surface agents read, closing the steering gap that left Perplexity trying (and failing on) the page HTML.

## Testing

- **#479** (`JsonLdTest.php` or the archive-ItemList test): with `is_shop()` true **and** `is_front_page()` true, `output_archive_itemlist_jsonld()` emits the `ItemList` (previously suppressed). Existing `/shop/` (is_shop, not front page) and category/tag/search cases stay green; a static non-shop front page emits no `ItemList`.
- **#477** (`JsonLdProductCheckoutLinksTest.php`): the rendered block contains the checkout URLs as **visible text** (`<code>…/checkout-link/?products=…</code>`) for simple, bundle/grouped, and concrete-variant cases — and *not* as `<a href>` with a bare "checkout"/"buy this item" label that drops the URL. Byte-identity against `checkout_url_template()` preserved. Hook-registration test updated to `wp_body_open`.
- **#478** (`ProductsFeedMapperTest.php`): `map_product( $p, true )` emits exactly **one** image (the featured) when a featured image is set; **one** image (the first gallery) when no featured image but gallery images exist (the ≥1 rule); **zero** when the product has no resolvable image. `map_product( $p, false )` (and the default) emits all images unchanged.
- **#480** (`LlmsTxtTest.php`): the generated `/llms.txt` contains the image-steering line in the read-only-browsing section when `products_json_enabled`, and omits it when the feed is off; `/agents.md` mirrors it.

## Verification (live, post-deploy)

- Root `saltwarp.shop` now carries an `ItemList` + `Product` blocks with prices in JSON-LD (matching `/shop/`).
- A product page's checkout block appears near the **top** of the fetched markdown with the URLs as readable text.
- `/products.json` is materially smaller (one image per product); `/products/{handle}.json` still has all images; both still currency-correct (0.22.1).
- Re-run a fresh-agent shopping test: it finds products + prices on the root and a usable checkout URL on the product page.

## Files

- `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` — #479 (`output_archive_itemlist_jsonld` gate) + #477 (`build_checkout_anchor_lines` `<code>` URLs).
- `includes/class-wc-ai-storefront.php` — #477 (`wp_body_open` registration).
- `includes/ai-storefront/class-wc-ai-storefront-products-feed.php` — #478 (`build_images` + `map_product` `$compact`; list serve call-sites).
- `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` — #480 (image-steering line in the read-only-browsing section).
- Tests: `JsonLdArchiveItemListTest.php` (#479), `JsonLdProductCheckoutLinksTest.php` (#477), `ProductsFeedMapperTest.php` (#478), `LlmsTxtTest.php` (#480).

## Sequencing

Four independent PRs, each closing its issue (in priority order):
1. **#479** — front-page shop `ItemList` (smallest, highest discovery value).
2. **#477** — checkout anchor placement + `<code>` URLs.
3. **#478** — compact list-feed images (supply side of product images).
4. **#480** — `/llms.txt` image-steering line (demand side of product images; pairs with #478).

Each: branch → PR referencing the issue → user merge. Bundle into the next release.
