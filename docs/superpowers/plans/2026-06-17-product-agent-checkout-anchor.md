# Product-Page Agent Checkout Anchor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render the plugin's existing deterministic per-product checkout link as a visible `wp_footer` body anchor on single-product pages, so markdown-extraction agents (which strip the `<script>` JSON-LD BuyAction) can read it and hand the buyer a working checkout link.

**Architecture:** A new public method `render_product_checkout_links()` on `WC_AI_Storefront_JsonLd`, hooked on `wp_footer` and gated on `is_product()` + `enabled` + `is_product_syndicated()`. It reuses the existing `build_checkout_url_template()` (exposed via a thin public accessor) and the existing variation-enumeration pattern, so the body anchor emits URLs identical to the `<script>` BuyAction. Variable products with >4 variations get a construct-kit (URL template + a link to the uncapped `/products/{handle}.json`) instead of a flood of links.

**Tech Stack:** PHP 7.4+ / WordPress / WooCommerce; PHPUnit + Brain Monkey + Mockery.

---

## Background (verified — do not re-derive)

- Target class: `WC_AI_Storefront_JsonLd` in `includes/ai-storefront/class-wc-ai-storefront-jsonld.php`.
- `private static function build_checkout_url_template( $product ): string` (line ~354) builds the deterministic URL: for **simple/variable/variation** → `home_url('/checkout-link/') . '?products=' . $product->get_id() . ':1&utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld'`; for **bundle/grouped** → `$product->get_permalink() . '?utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld'`. It is **private static** — needs a public accessor.
- Variation enumeration pattern: `$children = $product->get_children();` (variation IDs) → `$this->resolve_variation( (int) $child_id )` (line ~1200, a `wc_get_product()` wrapper returning `WC_Product|null`). Each variation's purchasability: `! method_exists( $variation, 'is_purchasable' ) || $variation->is_purchasable()` (the #373 guard). A variation's human label is `$variation->get_name()` (WC formats it like `"Canvas Belt - S/M"`).
- Gating pattern (`enhance_product_data`, lines 214-221): `$settings = WC_AI_Storefront::get_settings(); if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) return; if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) return;`.
- The `products_json_enabled` setting gates `/products/{handle}.json`. The per-product feed URL is `home_url( '/products/' . $product->get_slug() . '.json' )`.
- #463 mirror pattern — `WC_AI_Storefront_Llms_Txt::render_discovery_link()` (a public method, `enabled`-gated, `printf` with `esc_url`, registered via `add_action( 'wp_footer', [ $llms_txt, 'render_discovery_link' ] )` in `includes/class-wc-ai-storefront.php`'s `register_rewrite_rules()`).
- Tests: `tests/php/unit/JsonLdTest.php` (Brain Monkey + Mockery) has `make_product()` (~line 242) and `make_variation()` (~778) helpers. PHPUnit 10.5 — do NOT pass `-v`. Run: `vendor/bin/phpunit tests/php/unit/<File>.php --filter 'NAME'`.

## File map

- `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` — new `public static function checkout_url_template()` accessor + new `public function render_product_checkout_links()`.
- `includes/class-wc-ai-storefront.php` — register the `wp_footer` hook.
- `tests/php/unit/JsonLdProductCheckoutLinksTest.php` — NEW focused test file.

---

## Task 1: Public accessor for the checkout-URL builder

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-jsonld.php`
- Test: `tests/php/unit/JsonLdProductCheckoutLinksTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `tests/php/unit/JsonLdProductCheckoutLinksTest.php`:

```php
<?php
/**
 * Tests for the product-page "agent checkout" anchor:
 * WC_AI_Storefront_JsonLd::checkout_url_template() + render_product_checkout_links().
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdProductCheckoutLinksTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->jsonld = new WC_AI_Storefront_JsonLd();

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'products_json_enabled'  => 'yes',
		];

		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$q = http_build_query( $args, '', '&' );
				return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . urldecode( $q );
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function simple_product( int $id, string $slug = 'a-product' ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $slug );
		$p->shouldReceive( 'get_name' )->andReturn( 'A Product' );
		$p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'simple' === $t );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'get_permalink' )->andReturn( "https://example.com/product/{$slug}/" );
		return $p;
	}

	public function test_checkout_url_template_simple_matches_buyaction_shape(): void {
		$url = WC_AI_Storefront_JsonLd::checkout_url_template( $this->simple_product( 42 ) );

		$this->assertStringContainsString( 'https://example.com/checkout-link/?products=42:1', $url );
		$this->assertStringContainsString( 'utm_source={agent_id}', $url );
		$this->assertStringContainsString( 'utm_id=woo_jsonld', $url );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/php/unit/JsonLdProductCheckoutLinksTest.php --filter 'checkout_url_template_simple'`
Expected: FAIL — `checkout_url_template()` does not exist.

- [ ] **Step 3: Add the public accessor**

In `class-wc-ai-storefront-jsonld.php`, immediately after `build_checkout_url_template()`, add:

```php
	/**
	 * Public accessor for the deterministic per-product checkout URL.
	 *
	 * Thin wrapper over {@see build_checkout_url_template()} so callers
	 * outside the JSON-LD assembly (e.g. the visible product-page checkout
	 * anchor) emit byte-identical URLs to the `<script>` BuyAction without
	 * duplicating the per-product-type branching.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return string The checkout URL with the `{agent_id}` placeholder.
	 */
	public static function checkout_url_template( $product ): string {
		return self::build_checkout_url_template( $product );
	}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/php/unit/JsonLdProductCheckoutLinksTest.php --filter 'checkout_url_template_simple'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-jsonld.php tests/php/unit/JsonLdProductCheckoutLinksTest.php
git commit -m "feat(jsonld): public accessor for build_checkout_url_template"
```

---

## Task 2: `render_product_checkout_links()` renderer

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-jsonld.php`
- Test: `tests/php/unit/JsonLdProductCheckoutLinksTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/php/unit/JsonLdProductCheckoutLinksTest.php`. Add a variable-product helper + tests:

```php
	private function variable_product( int $id, string $slug, array $variation_ids ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $slug );
		$p->shouldReceive( 'get_name' )->andReturn( ucfirst( $slug ) );
		$p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'get_children' )->andReturn( $variation_ids );
		$p->shouldReceive( 'get_permalink' )->andReturn( "https://example.com/product/{$slug}/" );
		return $p;
	}

	private function variation( int $id, string $label, bool $purchasable = true ) {
		$v = \Mockery::mock( 'WC_Product' );
		$v->shouldReceive( 'get_id' )->andReturn( $id );
		$v->shouldReceive( 'get_name' )->andReturn( $label );
		$v->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variation' === $t );
		$v->shouldReceive( 'is_purchasable' )->andReturn( $purchasable );
		return $v;
	}

	/** Render the footer block for $product as the current single-product page, return the HTML. */
	private function render_for( $product, array $variations = [] ): string {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( $product->get_id() );
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( $product, $variations ) {
				if ( (int) $id === $product->get_id() ) {
					return $product;
				}
				return $variations[ (int) $id ] ?? null;
			}
		);
		ob_start();
		$this->jsonld->render_product_checkout_links();
		return (string) ob_get_clean();
	}

	public function test_simple_product_renders_one_checkout_link(): void {
		$html = $this->render_for( $this->simple_product( 42, 'a-product' ) );

		$this->assertStringContainsString( 'wc-ai-storefront-agent-checkout', $html );
		$this->assertStringContainsString( 'https://example.com/checkout-link/?products=42:1', $html );
	}

	public function test_variable_small_renders_concrete_variant_links(): void {
		$vars = [ 101 => $this->variation( 101, 'Belt - S/M' ), 102 => $this->variation( 102, 'Belt - L/XL' ) ];
		$html = $this->render_for( $this->variable_product( 100, 'canvas-belt', [ 101, 102 ] ), $vars );

		$this->assertStringContainsString( 'products=101:1', $html );
		$this->assertStringContainsString( 'products=102:1', $html );
		$this->assertStringContainsString( 'Belt - S/M', $html );
		// Construct kit always present for variable products:
		$this->assertStringContainsString( 'https://example.com/products/canvas-belt.json', $html );
		$this->assertStringContainsString( 'products={variation_id}:1', $html );
	}

	public function test_variable_large_omits_concrete_links_keeps_construct_kit(): void {
		$ids  = range( 201, 207 ); // 7 variations > 4
		$vars = [];
		foreach ( $ids as $i => $vid ) {
			$vars[ $vid ] = $this->variation( $vid, "Opt {$i}" );
		}
		$html = $this->render_for( $this->variable_product( 200, 'big-shirt', $ids ), $vars );

		$this->assertStringNotContainsString( 'products=201:1', $html );           // no concrete links
		$this->assertStringContainsString( 'https://example.com/products/big-shirt.json', $html ); // construct kit
		$this->assertStringContainsString( 'products={variation_id}:1', $html );
	}

	public function test_unpurchasable_variation_skipped_from_concrete_links(): void {
		$vars = [
			301 => $this->variation( 301, 'Live', true ),
			302 => $this->variation( 302, 'Dead', false ),
		];
		$html = $this->render_for( $this->variable_product( 300, 'two-var', [ 301, 302 ] ), $vars );

		$this->assertStringContainsString( 'products=301:1', $html );
		$this->assertStringNotContainsString( 'products=302:1', $html );
	}

	public function test_renders_nothing_when_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		$html = $this->render_for( $this->simple_product( 42 ) );
		$this->assertSame( '', $html );
	}

	public function test_renders_nothing_when_not_product_page(): void {
		Functions\when( 'is_product' )->justReturn( false );
		ob_start();
		$this->jsonld->render_product_checkout_links();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_variable_omits_json_link_when_feed_disabled(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled' => 'yes', 'product_selection_mode' => 'all', 'products_json_enabled' => 'no',
		];
		$vars = [ 401 => $this->variation( 401, 'X' ) ];
		$html = $this->render_for( $this->variable_product( 400, 'nofeed', [ 401 ] ), $vars );

		$this->assertStringNotContainsString( '/products/nofeed.json', $html );
		$this->assertStringContainsString( 'products=401:1', $html ); // concrete link still emitted (<=4)
	}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit tests/php/unit/JsonLdProductCheckoutLinksTest.php --filter 'render|simple_product_renders|variable_'`
Expected: FAIL — `render_product_checkout_links()` does not exist.

- [ ] **Step 3: Implement the renderer**

In `class-wc-ai-storefront-jsonld.php`, add a class constant near the other private consts and the method (place the method near `enhance_product_data` / the product-page logic):

```php
	/**
	 * Max purchasable variations for which the product-page checkout anchor
	 * emits concrete per-variant links. Above this, it emits just the URL
	 * template + the `/products/{handle}.json` construct source (no flood).
	 */
	private const CHECKOUT_ANCHOR_VARIANT_MAX = 4;

	/**
	 * Print a visible per-product "agent checkout" block in the footer of
	 * single-product pages.
	 *
	 * Markdown-extraction fetch tools strip the `<script>` JSON-LD where the
	 * BuyAction lives, so the deterministic checkout link is unreachable to
	 * them. This renders the SAME URL (via {@see checkout_url_template()}) as
	 * a visible body anchor they can extract and hand to the buyer. The
	 * per-product counterpart to the `/llms.txt` footer anchor.
	 *
	 * Variable products get a construct kit (template + a link to the
	 * uncapped `/products/{handle}.json`), plus concrete labeled variant
	 * links when there are <= CHECKOUT_ANCHOR_VARIANT_MAX purchasable
	 * variations. Gated on `enabled` + `is_product_syndicated()`; skips
	 * non-purchasable variations (#373).
	 */
	public function render_product_checkout_links(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}
		if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
			return;
		}

		$lines = $this->build_checkout_anchor_lines( $product, $settings );
		if ( empty( $lines ) ) {
			return;
		}

		printf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line is built from esc_url/esc_html below.
			'<div class="wc-ai-storefront-agent-checkout" style="font-size:12px;opacity:0.55;margin:1.5em 0;">%s</div>',
			implode( '<br>', $lines )
		);
	}

	/**
	 * Build the inner HTML lines for the product-page checkout anchor.
	 *
	 * @param WC_Product $product  The current product.
	 * @param array      $settings Plugin settings.
	 * @return string[] Escaped HTML lines (may be empty when nothing is purchasable).
	 */
	private function build_checkout_anchor_lines( $product, array $settings ): array {
		$lines    = array();
		$feed_on  = 'yes' === ( $settings['products_json_enabled'] ?? 'no' );

		// Bundle / grouped: one permalink-based link.
		if ( $product->is_type( 'bundle' ) || $product->is_type( 'grouped' ) ) {
			$lines[] = 'Agent checkout: <a href="' . esc_url( self::checkout_url_template( $product ) ) . '">' . esc_html( $product->get_name() ) . '</a>';
			return $lines;
		}

		// Variable: construct kit + concrete links when small.
		if ( $product->is_type( 'variable' ) ) {
			$variations = array();
			foreach ( (array) $product->get_children() as $child_id ) {
				$variation = $this->resolve_variation( (int) $child_id );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}
				if ( method_exists( $variation, 'is_purchasable' ) && ! $variation->is_purchasable() ) {
					continue;
				}
				$variations[] = $variation;
			}
			if ( empty( $variations ) ) {
				return array();
			}

			$lines[] = 'Agent checkout (per variation): <code>' . esc_html( home_url( '/checkout-link/' ) . '?products={variation_id}:1&utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld' ) . '</code>';
			if ( $feed_on ) {
				$lines[] = 'All variations + ids: <a href="' . esc_url( home_url( '/products/' . $product->get_slug() . '.json' ) ) . '">' . esc_html( $product->get_slug() . '.json' ) . '</a>';
			}
			if ( count( $variations ) <= self::CHECKOUT_ANCHOR_VARIANT_MAX ) {
				foreach ( $variations as $variation ) {
					$lines[] = esc_html( $variation->get_name() ) . ': <a href="' . esc_url( self::checkout_url_template( $variation ) ) . '">checkout</a>';
				}
			}
			return $lines;
		}

		// Simple (and any other purchasable single SKU): one direct link.
		if ( method_exists( $product, 'is_purchasable' ) && ! $product->is_purchasable() ) {
			return array();
		}
		$lines[] = 'Agent checkout: <a href="' . esc_url( self::checkout_url_template( $product ) ) . '">buy this item</a>';
		return $lines;
	}
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/phpunit tests/php/unit/JsonLdProductCheckoutLinksTest.php`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-jsonld.php tests/php/unit/JsonLdProductCheckoutLinksTest.php
git commit -m "feat(jsonld): product-page agent checkout anchor (render_product_checkout_links)"
```

---

## Task 3: Register the `wp_footer` hook

**Files:**
- Modify: `includes/class-wc-ai-storefront.php`
- Test: covered by Task 2 (the renderer self-gates); verify wiring via grep in Task 4.

- [ ] **Step 1: Register the hook**

In `includes/class-wc-ai-storefront.php`, in `register_rewrite_rules()`, find where the JSON-LD instance is constructed and the existing `wp_head`/`wp_footer` JSON-LD hooks are registered (the `WC_AI_Storefront_JsonLd` object — search for `new WC_AI_Storefront_JsonLd` or where `output_wc_structured_data` is wired). Add, alongside the existing JSON-LD `wp_footer` registration:

```php
		// Visible per-product checkout anchor (body counterpart to the
		// <script> JSON-LD BuyAction) so markdown-extraction agents can read
		// the deterministic checkout link. Self-gates on is_product() +
		// enabled + syndication.
		add_action( 'wp_footer', [ $jsonld, 'render_product_checkout_links' ] );
```

(Use the same `$jsonld`/instance variable the existing JSON-LD hooks use. If the JSON-LD class is instantiated only inside another method, mirror that instantiation here or reuse the shared instance — do NOT create a second competing instance that double-registers `output_wc_structured_data`.)

- [ ] **Step 2: Run the full JsonLd suites**

Run: `vendor/bin/phpunit tests/php/unit/JsonLdProductCheckoutLinksTest.php && vendor/bin/phpunit tests/php/unit/JsonLdTest.php`
Expected: all PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/class-wc-ai-storefront.php
git commit -m "feat(discovery): wire product-page checkout anchor on wp_footer"
```

---

## Task 4: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Full unit suite + standards**

Run: `composer test`
Then: `vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-jsonld.php includes/class-wc-ai-storefront.php tests/php/unit/JsonLdProductCheckoutLinksTest.php` (run `phpcbf` on those paths if needed, re-run).
Then: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: all green.

- [ ] **Step 2: Docs-untouched guard**

Run: `git diff --name-only main...HEAD | grep -iE 'CHANGELOG|readme|USER-GUIDE' || echo "none ✓"`
Expected: `none ✓` (docs handled in the pre-release pass).

- [ ] **Step 3: Manual smoke on local wp-env (localhost:8030)**

```bash
# simple product: a direct checkout link in the rendered body
curl -s http://localhost:8030/product/day-hoodie/ | grep -o 'wc-ai-storefront-agent-checkout.\{0,200\}' | head -1
# variable product (the belt): construct kit + concrete S/M, L/XL links + the products/{handle}.json link
curl -s http://localhost:8030/product/canvas-belt/ | grep -oE 'products=[0-9]+:1|/products/canvas-belt\.json|products=\{variation_id\}' | head -6
```
Expected: the simple product shows one `/checkout-link/?products=<id>:1` anchor; the belt shows per-variation `products=<id>:1` links + a `/products/canvas-belt.json` link + the `{variation_id}` template.

## Notes

- **agents.md / llms.txt unaffected** — this is product-page-only.
- **Docs deferred** to the pre-release pass.
- **No new setting** — `enabled`-gated; the `.json` construct link additionally respects `products_json_enabled`.

## Self-review

- **Spec coverage:** public accessor → Task 1. Renderer with simple/variable/bundle-grouped branching, construct kit, ≤4 concrete-link cap, #373 unpurchasable skip, gating → Task 2. `wp_footer` wiring → Task 3. Verification + live smoke → Task 4. All spec sections covered.
- **Placeholder scan:** every step has complete code/commands; the one implementer judgment ("reuse the shared `$jsonld` instance") is explicitly called out with the failure mode to avoid (double-registering `output_wc_structured_data`), not a vague placeholder.
- **Type/name consistency:** `checkout_url_template()` (Task 1) is called by `build_checkout_anchor_lines()` (Task 2); `render_product_checkout_links()` (Task 2) matches the hook registration (Task 3) and the tests; `CHECKOUT_ANCHOR_VARIANT_MAX = 4` matches the spec's ≤4 rule; `resolve_variation`/`get_children`/`is_purchasable`/`is_product_syndicated`/`build_checkout_url_template` match the verified source.
