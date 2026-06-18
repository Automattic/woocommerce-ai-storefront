# Agent-Reachable Surfaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make agent-facing surfaces reachable to markdown-extraction agents — products+prices on the root, an extractable checkout link high on product pages, ≥1 image per product in the list feeds, a steering line for images, and an honestly-scoped Discovery-tab label.

**Architecture:** Five independent fixes, each its **own branch + PR closing its issue**, built **in parallel** (isolated worktrees). Four touch the PHP plugin (JSON-LD, feed, llms.txt); one is JS-only (the admin React card).

**Tech Stack:** PHP 8.1+ / WordPress / WooCommerce; PHPUnit 10.5 + Brain Monkey + Mockery; `@wordpress/scripts` (Jest) for JS.

---

## Parallel-execution notes (read first)

- Each task = one **branch off `main`** → its own PR → **wait for the user's explicit merge** (do NOT self-merge).
- **#477 and #479 both edit `class-wc-ai-storefront-jsonld.php`** but in different methods (`build_checkout_anchor_lines` / nothing-else vs `output_archive_itemlist_jsonld`) — non-overlapping line ranges, so parallel branches off `main` won't conflict on the code.
- **`.pot` caveat:** tasks that shift translatable-string line refs (#477, #479 in jsonld.php; #481 in JS) each regenerate + commit `languages/woocommerce-ai-storefront.pot` so their PR passes the i18n gate. Because they regenerate against different diffs, the `.pot` **will conflict at merge** — merge the PRs **sequentially**, and after each merge run `./bin/make-pot.sh` to resolve (never hand-pick sides; per repo convention). The controller handles this at merge time.
- PHPUnit 10.5: do NOT pass `-v`. Single file: `vendor/bin/phpunit tests/php/unit/<File>.php`.

## File map

- `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` — #479 (`output_archive_itemlist_jsonld` gate), #477 (`build_checkout_anchor_lines` URLs).
- `includes/class-wc-ai-storefront.php` — #477 (`wp_body_open` registration, line 187).
- `includes/ai-storefront/class-wc-ai-storefront-products-feed.php` — #478 (`build_images` + `map_product` `$compact`).
- `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` — #480 (image-steering line).
- `client/settings/ai-storefront/endpoint-info.js` — #481 (CrawlerActivityCard copy).
- Tests: `JsonLdTest.php`, `JsonLdProductCheckoutLinksTest.php`, `ProductsFeedMapperTest.php`, `LlmsTxtTest.php`, `client/.../__tests__/endpoint-info.test.js`.

---

## Task A — #479: front-page shop emits the product ItemList

**Branch:** `fix/front-page-shop-itemlist` (off `main`). **Closes #479.**
**Files:** Modify `includes/ai-storefront/class-wc-ai-storefront-jsonld.php`; Test `tests/php/unit/JsonLdTest.php`.

- [ ] **Step 1 — failing test.** In `JsonLdTest.php`, mirror the existing `output_archive_itemlist_jsonld` test that covers `/shop` (find it: it stubs `is_shop`→true, products, and asserts an `ItemList` is printed). Add a case where the shop **is** the front page:

```php
public function test_itemlist_emitted_on_front_page_shop(): void {
    // Mirror the existing shop-archive ItemList test's setup (settings enabled,
    // is_shop() true, a couple of products in the loop), but with is_front_page() TRUE.
    Functions\when( 'is_shop' )->justReturn( true );
    Functions\when( 'is_front_page' )->justReturn( true );
    Functions\when( 'is_product_category' )->justReturn( false );
    Functions\when( 'is_product_tag' )->justReturn( false );
    Functions\when( 'is_search' )->justReturn( false );
    // ... (same product-loop + settings stubs the existing shop test uses) ...

    ob_start();
    $this->jsonld->output_archive_itemlist_jsonld();
    $out = ob_get_clean();

    $this->assertStringContainsString( '"@type":"ItemList"', $out ); // emitted even though is_front_page()
}
```

- [ ] **Step 2 — run, expect FAIL.** `vendor/bin/phpunit tests/php/unit/JsonLdTest.php --filter 'front_page_shop'` → FAIL (no ItemList: the `! is_front_page()` clause suppresses it).

- [ ] **Step 3 — implement.** In `output_archive_itemlist_jsonld()` (line 3521) change:
```php
		$on_shop     = function_exists( 'is_shop' ) && is_shop() && ! is_front_page();
```
to:
```php
		$on_shop     = function_exists( 'is_shop' ) && is_shop();
```
And update the comment at lines 3519-3520 (it currently says "Homepage is excluded…") to: `// Shop archive (incl. when the shop IS the front page): emit the product ItemList alongside the front page's OnlineBusiness block, so agents fetching the root get products + prices, not just navigational data.`

- [ ] **Step 4 — run, expect PASS.** `vendor/bin/phpunit tests/php/unit/JsonLdTest.php` → all green (the new test + the existing `/shop`, category, tag, search, and non-shop-front-page cases — a static front page still has `is_shop()` false, so no ItemList).

- [ ] **Step 5 — standards + pot + commit.**
```bash
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-jsonld.php tests/php/unit/JsonLdTest.php
vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-jsonld.php tests/php/unit/JsonLdTest.php
./bin/make-pot.sh
git add includes/ai-storefront/class-wc-ai-storefront-jsonld.php tests/php/unit/JsonLdTest.php languages/woocommerce-ai-storefront.pot
git commit -m "fix(jsonld): emit product ItemList on the front-page shop (closes #479)"
```

---

## Task B — #477: checkout anchor placement + extractable URLs

**Branch:** `fix/checkout-anchor-reachability` (off `main`). **Closes #477.**
**Files:** Modify `includes/class-wc-ai-storefront.php` (line 187) + `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` (`build_checkout_anchor_lines`); Test `tests/php/unit/JsonLdProductCheckoutLinksTest.php`.

- [ ] **Step 1 — failing tests.** In `JsonLdProductCheckoutLinksTest.php`, update/add assertions that the URLs render as `<code>` text (not `<a href>`). Note `esc_html`/`esc_url` are stubbed passthroughs in that suite, so the rendered URL equals the accessor output. Examples:

```php
// Simple product: URL is <code> text, not an <a href>.
public function test_simple_product_renders_url_as_code_text(): void {
    $product = $this->simple_product( 42, 'a-product' );
    $html    = $this->render_for( $product );
    $url     = WC_AI_Storefront_JsonLd::checkout_url_template( $product, WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE );
    $this->assertStringContainsString( 'Agent checkout: <code>' . $url . '</code>', $html );
    $this->assertStringNotContainsString( '>buy this item</a>', $html ); // no clickable-label form
}

// Concrete variant: "Label: <code>url</code>".
public function test_concrete_variant_renders_url_as_code_text(): void {
    $variation = $this->variation( 901, 'Tall' );
    $html      = $this->render_for( $this->variable_product( 900, 'one-var', [ 901 ] ), [ 901 => $variation ] );
    $url       = WC_AI_Storefront_JsonLd::checkout_url_template( $variation, WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE );
    $this->assertStringContainsString( 'Tall: <code>' . $url . '</code>', $html );
    $this->assertStringNotContainsString( '>checkout</a>', $html );
}
```
Also update the existing byte-identity tests (`test_concrete_variant_href_is_byte_identical_to_accessor`, simple, bundle/grouped) to extract from `<code>…</code>` instead of `<a href="…">`, asserting `assertSame` against the accessor (ready-made = `FALLBACK_SOURCE`). Update the bootstrap-hook test to expect `wp_body_open` (see Step 4).

- [ ] **Step 2 — run, expect FAIL.** `vendor/bin/phpunit tests/php/unit/JsonLdProductCheckoutLinksTest.php` → FAIL (URLs still render as `<a href>`).

- [ ] **Step 3 — implement (URLs → `<code>`).** In `build_checkout_anchor_lines()`:

  - Replace the comment block at lines 488-495 with:
    ```php
		// Checkout URLs render as visible `<code>` text (not `<a href>`): markdown-
		// extraction fetch tools drop href attributes (keeping only the link text),
		// so an `<a href>` URL is unreachable to agents, while `<code>` text survives.
		// Ready-made URLs (simple, bundle/grouped, concrete variants) carry the
		// no-identity `ucp_unknown` source — used as-is, no substitution. The
		// construct-kit `{variation_id}` template below keeps `{agent_id}` for agents
		// to substitute (the faithful `<script>` BuyAction urlTemplate mirror).
		$click_source = WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE;
    ```
  - Bundle/grouped (line 500):
    ```php
			$lines[] = 'Agent checkout (' . esc_html( $product->get_name() ) . '): <code>' . esc_html( self::checkout_url_template( $product, $click_source ) ) . '</code>';
    ```
  - Concrete per-variant (line 561):
    ```php
				$lines[] = esc_html( $variation->get_name() ) . ': <code>' . esc_html( self::checkout_url_template( $variation, $click_source ) ) . '</code>';
    ```
  - Simple branch link (the `'Agent checkout: <a href="' … '">buy this item</a>'` line, ~line 573):
    ```php
		$lines[] = 'Agent checkout: <code>' . esc_html( self::checkout_url_template( $product, $click_source ) ) . '</code>';
    ```
  - **Leave unchanged:** the construct-kit template (line 555, already `<code>` with `{agent_id}`) and the `/products/{handle}.json` link (line 557, `<a>` whose text is the recognizable handle — out of scope; #480 separately steers agents to that feed).
  - The `printf` wrapper's `phpcs:ignore WordPress.Security.EscapeOutput` stays valid (every interpolated value is still `esc_html`'d).

- [ ] **Step 4 — implement (placement).** In `includes/class-wc-ai-storefront.php` line 187 change:
```php
		add_action( 'wp_footer', [ $jsonld, 'render_product_checkout_links' ] );
```
to:
```php
		add_action( 'wp_body_open', [ $jsonld, 'render_product_checkout_links' ] );
```

- [ ] **Step 5 — run, expect PASS.** `vendor/bin/phpunit tests/php/unit/JsonLdProductCheckoutLinksTest.php` → all green.

- [ ] **Step 6 — standards + pot + commit.**
```bash
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-jsonld.php includes/class-wc-ai-storefront.php tests/php/unit/JsonLdProductCheckoutLinksTest.php
vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-jsonld.php includes/class-wc-ai-storefront.php tests/php/unit/JsonLdProductCheckoutLinksTest.php
./bin/make-pot.sh
git add includes/ai-storefront/class-wc-ai-storefront-jsonld.php includes/class-wc-ai-storefront.php tests/php/unit/JsonLdProductCheckoutLinksTest.php languages/woocommerce-ai-storefront.pot
git commit -m "fix(jsonld): checkout anchor at wp_body_open with code-text URLs (closes #477)"
```

---

## Task C — #478: compact list-feed images (≥1 image rule)

**Branch:** `fix/bulk-feed-compact-images` (off `main`). **Closes #478.**
**Files:** Modify `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`; Test `tests/php/unit/ProductsFeedMapperTest.php`.

- [ ] **Step 1 — failing test.** In `ProductsFeedMapperTest.php`:

```php
public function test_compact_emits_only_first_image_when_featured_set(): void {
    Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => "https://x/$id.jpg" );
    $p = $this->mappable_simple_product( [] ); // helper that sets get_image_id()=11, gallery=[12,13]
    // (If the helper doesn't expose image ids, build the mock explicitly:
    //  get_image_id()->andReturn(11); get_gallery_image_ids()->andReturn([12,13]); plus the
    //  other map_product getters as in test_map_simple_product_emits_single_default_variant.)
    $out = WC_AI_Storefront_Products_Feed::map_product( $p, true );
    $this->assertCount( 1, $out['images'] );
    $this->assertSame( 11, $out['images'][0]['id'] ); // the featured image
}

public function test_compact_falls_back_to_first_gallery_when_no_featured(): void {
    Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => "https://x/$id.jpg" );
    // get_image_id()->andReturn(0); get_gallery_image_ids()->andReturn([12,13]);
    $p   = /* mock with no featured, gallery [12,13] */;
    $out = WC_AI_Storefront_Products_Feed::map_product( $p, true );
    $this->assertCount( 1, $out['images'] );   // the ≥1 rule
    $this->assertSame( 12, $out['images'][0]['id'] ); // first gallery
}

public function test_full_mode_default_emits_all_images(): void {
    Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => "https://x/$id.jpg" );
    // get_image_id()=11, gallery=[12,13]
    $p   = /* mock */;
    $out = WC_AI_Storefront_Products_Feed::map_product( $p ); // default false
    $this->assertCount( 3, $out['images'] );
}
```

- [ ] **Step 2 — run, expect FAIL.** `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php --filter 'compact|full_mode'` → FAIL (`map_product`/`build_images` take no `$compact`).

- [ ] **Step 3 — implement.** Change `build_images()` to:
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
Change `map_product()` signature to `public static function map_product( $product, bool $compact = false ): array` and its images line (807) to `'images' => self::build_images( $product, $compact ),`. Then, at the two **list** call-sites — the bulk loop (`$mapped[] = self::map_product( $product );` ~line 469) and the collection loop (~line 644) — pass `true`: `$mapped[] = self::map_product( $product, true );`. **Leave** the single-product serve (`map_product( $product )` in the `[ 'product' => … ]` wrapper, ~line 394) at the default.

- [ ] **Step 4 — run, expect PASS.** `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php` → green (new tests + all existing, which call `map_product($p)` → default full).

- [ ] **Step 5 — standards + commit.** (Feed has no translatable strings — no `.pot` change expected; run make-pot and only `git add` the `.pot` if it shows a non-timestamp diff.)
```bash
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-products-feed.php tests/php/unit/ProductsFeedMapperTest.php
vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-products-feed.php tests/php/unit/ProductsFeedMapperTest.php
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php tests/php/unit/ProductsFeedMapperTest.php
git commit -m "fix(feed): one image per product on the list feeds, >=1 when any exist (closes #478)"
```

---

## Task D — #480: steer agents to the feeds for images

**Branch:** `fix/llms-txt-image-steering` (off `main`). **Closes #480.**
**Files:** Modify `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php`; Test `tests/php/unit/LlmsTxtTest.php`.

- [ ] **Step 1 — failing test.** In `LlmsTxtTest.php` (mirror the existing read-only-browsing tests that assert the `*.json` bullets are present when `products_json_enabled`):

```php
public function test_read_only_browsing_steers_to_feeds_for_images(): void {
    // products_json_enabled = yes
    $out = $this->generate_with( [ 'enabled' => 'yes', 'products_json_enabled' => 'yes' ] );
    $this->assertStringContainsString( 'images[].src', $out );
    $this->assertStringContainsString( 'Read-only browsing', $out );
}
public function test_image_steering_absent_when_feed_off(): void {
    $out = $this->generate_with( [ 'enabled' => 'yes', 'products_json_enabled' => 'no' ] );
    $this->assertStringNotContainsString( 'images[].src', $out );
}
```
(Use the suite's existing generate-with-settings helper; mirror how the existing `*.json`-bullet tests drive it.)

- [ ] **Step 2 — run, expect FAIL.** `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php --filter 'image'` → FAIL.

- [ ] **Step 3 — implement.** In the `## Read-only browsing` section, inside the same `products_json_enabled` conditional that emits the `*.json` bullets (after line 800, the "Collection JSON" bullet), add:
```php
			$lines[] = "- **Product images** — image URLs are in the `*.json` feeds above (`images[].src`). Page `<img>` tags and JSON-LD `image` are stripped by markdown-extraction fetch tools, so read product photos from the feeds, not the page HTML.";
```
This flows to the byte-identical `/agents.md` mirror automatically.

- [ ] **Step 4 — run, expect PASS.** `vendor/bin/phpunit tests/php/unit/LlmsTxtTest.php` → green (new tests + the existing section-order/content tests; if a section-order or line-count assertion counts bullets, update it to include the new line).

- [ ] **Step 5 — standards + pot + commit.**
```bash
vendor/bin/phpcbf includes/ai-storefront/class-wc-ai-storefront-llms-txt.php tests/php/unit/LlmsTxtTest.php
vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-llms-txt.php tests/php/unit/LlmsTxtTest.php
./bin/make-pot.sh
git add includes/ai-storefront/class-wc-ai-storefront-llms-txt.php tests/php/unit/LlmsTxtTest.php languages/woocommerce-ai-storefront.pot
git commit -m "feat(llms-txt): steer agents to the .json feeds for product images (closes #480)"
```

---

## Task E — #481: relabel the Discovery-tab activity card

**Branch:** `fix/discovery-tab-relabel` (off `main`). **Closes #481.**
**Files:** Modify `client/settings/ai-storefront/endpoint-info.js`; Test `client/settings/ai-storefront/__tests__/endpoint-info.test.js`.

- [ ] **Step 1 — failing test.** In `endpoint-info.test.js`, update/add an assertion that the card title reads "AI shopping-API activity" and the scope clarifier is present:
```js
expect( screen.getByText( 'AI shopping-API activity' ) ).toBeInTheDocument();
expect(
    screen.getByText( /Catalog searches & lookups through the UCP shopping API/ )
).toBeInTheDocument();
```
(Find and update any existing assertion that matched the old "AI agent activity" title / "No AI agent activity recorded…" empty state.)

- [ ] **Step 2 — run, expect FAIL.** `npm run test:js -- endpoint-info` → FAIL.

- [ ] **Step 3 — implement.** In `CrawlerActivityCard` (`endpoint-info.js`):
  - The `<h3>` title (~line 960): `'AI agent activity'` → `'AI shopping-API activity'`.
  - Immediately after the `<h3>`, add an always-visible clarifier `<p>` (muted, 12px), independent of the data-present rollup `<p>`:
    ```jsx
    <p style={ { margin: '4px 0 0', fontSize: '12px', color: colors.textMuted } }>
        { __(
            'Catalog searches & lookups through the UCP shopping API. Page, feed, and llms.txt fetches aren’t counted — most are served from cache and never reach your server.',
            'woocommerce-ai-storefront'
        ) }
    </p>
    ```
  - Update for consistency: the empty state (~1565) `'No AI agent activity recorded…'` → `'No AI shopping-API activity recorded for this period. Stats appear here after the first AI agent uses your shopping API.'`; keep `'By AI agent'` (~1124) and `'Search queries from AI agents…'` (~1531) as-is (those describe the *agents*, not the activity scope — accurate).

- [ ] **Step 4 — run, expect PASS + lint.** `npm run test:js -- endpoint-info` (green) and `npm run lint:js` (clean).

- [ ] **Step 5 — pot + commit.**
```bash
./bin/make-pot.sh
git add client/settings/ai-storefront/endpoint-info.js client/settings/ai-storefront/__tests__/endpoint-info.test.js languages/woocommerce-ai-storefront.pot
git commit -m "fix(admin): scope Discovery card to 'AI shopping-API activity' (closes #481)"
```

---

## After all five (controller)

- Push each branch; open **5 PRs**, each body referencing `Closes #<issue>`; do not auto-request Copilot review.
- **Do NOT self-merge** — present the 5 green PRs and wait for the user to merge.
- Merge **sequentially**; after each merge that touched the `.pot`, run `./bin/make-pot.sh` on the next branch (or on main) to resolve `.pot` conflicts per repo convention.
- Docs (CHANGELOG/readme/USER-GUIDE) handled in the pre-release pass; these ride the next release.

## Self-review

- **Spec coverage:** #479 (Task A), #477 (Task B), #478 (Task C), #480 (Task D) all map to spec sections; #481 (Task E) is the settled relabel. Every spec requirement has a task.
- **Placeholder scan:** implementation steps carry exact code. Test steps that depend on a suite helper (`mappable_simple_product`, the llms.txt generate-helper, the existing archive-ItemList test) name the helper and give the explicit fallback mock — the implementer reads the harness; the two-stage review confirms alignment. No "TBD"/"similar to".
- **Name consistency:** `build_images($product,$compact)` / `map_product($product,$compact)` consistent across Task C; `checkout_url_template(..., $click_source=FALLBACK_SOURCE)` consistent in Task B; `output_archive_itemlist_jsonld` gate matches Task A.
