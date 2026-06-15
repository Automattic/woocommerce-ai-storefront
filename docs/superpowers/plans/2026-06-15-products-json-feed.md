# Shopify-Compatible `/products.json` Catalog Feed — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve a Shopify-shaped catalog at `/products.json` (+ `/collections/all/products.json` alias) so AI agents trained to probe that endpoint find a WooCommerce store's catalog in a format they can parse zero-shot.

**Architecture:** A new `WC_AI_Storefront_Products_Feed` rewrite-endpoint class parallel to `WC_AI_Storefront_Llms_Txt`: rewrite rules → `serve_products_feed()` on `template_redirect` → gate → cached, paginated JSON built by a WC→Shopify `map_product()` mapper. Edge-cacheable (rewrite path + `discovery_cache_control()` + `Vary: Host`); cache invalidated by a versioned key prefix bumped on product/settings change. Non-UCP, additive.

**Tech Stack:** PHP 8.1+, WordPress/WooCommerce, Brain Monkey + PHPUnit + Mockery for unit tests, React (`@wordpress/components`) for the settings toggle.

**Spec:** [`docs/superpowers/specs/2026-06-15-products-json-feed-design.md`](../specs/2026-06-15-products-json-feed-design.md). **Issue:** #449. **Branch:** `feat/products-json-feed` (already created).

## File Structure

- **Create** `includes/ai-storefront/class-wc-ai-storefront-products-feed.php` — the feed class: rewrite rules, query var, `serve_products_feed()`, pagination, cache, and the static mapper (`map_product()`, `resolve_product_type()`, variant/options/images helpers).
- **Modify** `includes/autoload.php` — register the new class.
- **Modify** `includes/class-wc-ai-storefront.php` — defaults + `get_settings()` resolution + `update_settings()` sanitize for `products_json_enabled`; wire the feed class into `register_rewrite_rules()`.
- **Modify** `includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php` — bump `products_feed_version` on product save/delete + settings change.
- **Create** `tests/php/unit/ProductsFeedMapperTest.php` — mapper + `resolve_product_type()` unit tests.
- **Create** `tests/php/unit/ProductsFeedTest.php` — serve-handler, gating, pagination, cache tests.
- **Modify** `tests/php/unit/UpdateSettingsSanitizationTest.php` — the new setting.
- **Modify** `client/settings/ai-storefront/` (the Discovery tab) — the toggle.
- **Modify** docs + `CHANGELOG.md` + `readme.txt`.

---

### Task 1: Settings toggle `products_json_enabled`

**Files:**
- Modify: `includes/class-wc-ai-storefront.php` (defaults array near `'mcp_enabled' => 'yes'`; `get_settings()` resolution near the `$mcp_enabled` block; `update_settings()` `$clean` array)
- Test: `tests/php/unit/UpdateSettingsSanitizationTest.php`

- [ ] **Step 1: Write the failing test** — add to `UpdateSettingsSanitizationTest.php`:

```php
public function test_products_json_enabled_defaults_to_yes_and_validates(): void {
	$clean = WC_AI_Storefront::sanitize_settings_for_test( [ 'enabled' => 'yes' ] );
	$this->assertSame( 'yes', $clean['products_json_enabled'] );

	$clean = WC_AI_Storefront::sanitize_settings_for_test( [ 'enabled' => 'yes', 'products_json_enabled' => 'no' ] );
	$this->assertSame( 'no', $clean['products_json_enabled'] );

	$clean = WC_AI_Storefront::sanitize_settings_for_test( [ 'enabled' => 'yes', 'products_json_enabled' => 'gibberish' ] );
	$this->assertSame( 'yes', $clean['products_json_enabled'] );
}
```

> Note: this test calls the existing sanitize entry point the same way the sibling `mcp_enabled` test does. Read the top of `UpdateSettingsSanitizationTest.php` and mirror how it invokes sanitization (it may call `update_settings` with stubbed `update_option`, or a test helper). Match the existing pattern exactly rather than the placeholder helper name above.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/php/unit/UpdateSettingsSanitizationTest.php`
Expected: FAIL — `products_json_enabled` key missing.

- [ ] **Step 3: Add the default.** In `includes/class-wc-ai-storefront.php` defaults array, immediately after `'mcp_enabled' => 'yes',` add:

```php
			'products_json_enabled'    => 'yes',
```

- [ ] **Step 4: Add resolution + sanitize.** In `get_settings()`/`update_settings()`, mirror the `mcp_enabled` block. Before the `$clean` array literal:

```php
		$products_json_enabled = $merged['products_json_enabled'] ?? 'yes';
		if ( ! in_array( $products_json_enabled, [ 'yes', 'no' ], true ) ) {
			$products_json_enabled = 'yes';
		}
```

Then inside the `$clean` array, after the `'mcp_enabled' => $mcp_enabled,` line:

```php
			'products_json_enabled'    => $products_json_enabled,
```

Also add the same `'products_json_enabled' => $products_json_enabled,` (or `?? 'yes'` fallback) to the `get_settings()` merge return if it builds a separate resolved array (mirror exactly how `mcp_enabled` appears in both `get_settings` and `update_settings`).

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/php/unit/UpdateSettingsSanitizationTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-wc-ai-storefront.php tests/php/unit/UpdateSettingsSanitizationTest.php
git commit -m "feat(products-feed): add products_json_enabled setting (default yes)"
```

---

### Task 2: `resolve_product_type()` — single product_type string from WC categories

**Files:**
- Create: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php` (start the class with just this static method + the class scaffold)
- Modify: `includes/autoload.php`
- Test: `tests/php/unit/ProductsFeedMapperTest.php` (create)

- [ ] **Step 1: Create the class scaffold.** New file `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`:

```php
<?php
/**
 * Shopify-compatible /products.json catalog feed.
 *
 * Serves the store catalog in Shopify's public products.json shape at the
 * endpoints AI agents are trained to probe (`/products.json` and the
 * `/collections/all/products.json` alias). NON-UCP, additive compatibility
 * surface — does not alter the UCP manifest, REST/MCP, llms.txt, or JSON-LD.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Shopify-compatible products.json rewrite endpoints and maps
 * WooCommerce products into the Shopify product JSON shape.
 */
class WC_AI_Storefront_Products_Feed {

	/**
	 * Resolve a single Shopify-style `product_type` string from a product's
	 * WooCommerce categories.
	 *
	 * Shopify's `product_type` is a single free-text type (e.g. "Hoodie"),
	 * distinct from collections. WC has no equivalent single field and allows
	 * many `product_cat` terms, so we synthesize one in priority order:
	 *   1. SEO-plugin primary category (Yoast / RankMath meta) — merchant intent.
	 *   2. Most-specific (deepest) assigned category — mimics Shopify's type.
	 *   3. First assigned category.
	 *   4. '' (Shopify always emits the key as a string).
	 *
	 * @param WC_Product $product The product.
	 * @return string Decoded plain-text type, or '' when uncategorized.
	 */
	public static function resolve_product_type( $product ): string {
		$product_id = (int) $product->get_id();

		// 1. SEO-plugin primary category.
		foreach ( [ '_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat' ] as $meta_key ) {
			$primary_id = (int) get_post_meta( $product_id, $meta_key, true );
			if ( $primary_id > 0 ) {
				$term = get_term( $primary_id, 'product_cat' );
				if ( $term instanceof WP_Term ) {
					return self::decode( $term->name );
				}
			}
		}

		$term_ids = $product->get_category_ids();
		if ( empty( $term_ids ) || ! is_array( $term_ids ) ) {
			return '';
		}

		$terms = array_filter(
			array_map(
				static function ( $id ) {
					return get_term( (int) $id, 'product_cat' );
				},
				$term_ids
			),
			static function ( $t ) {
				return $t instanceof WP_Term;
			}
		);
		if ( empty( $terms ) ) {
			return '';
		}

		// 2. Deepest (most-specific) term — greatest ancestor depth.
		usort(
			$terms,
			static function ( $a, $b ) {
				$depth_a = count( get_ancestors( $a->term_id, 'product_cat' ) );
				$depth_b = count( get_ancestors( $b->term_id, 'product_cat' ) );
				if ( $depth_a !== $depth_b ) {
					return $depth_b <=> $depth_a; // deeper first
				}
				return $a->term_id <=> $b->term_id; // stable tiebreak
			}
		);

		// 3. First (now: deepest, else first assigned) — usort leaves the best at [0].
		return self::decode( $terms[0]->name );
	}

	/**
	 * Decode HTML entities to plain UTF-8 (term/product names arrive encoded).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function decode( string $value ): string {
		return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
```

- [ ] **Step 2: Register in autoload.** In `includes/autoload.php`, add an entry mirroring the existing class map (match the exact array/style used there):

```php
		'WC_AI_Storefront_Products_Feed'       => 'ai-storefront/class-wc-ai-storefront-products-feed.php',
```

- [ ] **Step 3: Write the failing test** — create `tests/php/unit/ProductsFeedMapperTest.php`:

```php
<?php

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductsFeedMapperTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_ancestors' )->justReturn( [] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function product( int $id, array $category_ids ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_category_ids' )->andReturn( $category_ids );
		return $p;
	}

	public function test_product_type_prefers_yoast_primary_category(): void {
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
			return '_yoast_wpseo_primary_product_cat' === $key ? 55 : '';
		} );
		Functions\when( 'get_term' )->alias( function ( $id ) {
			$t = \Mockery::mock( 'WP_Term' );
			$t->name = 55 === $id ? 'Hoodies' : 'Other';
			$t->term_id = $id;
			return $t;
		} );
		Functions\when( 'html_entity_decode' )->alias( 'html_entity_decode' );

		$type = WC_AI_Storefront_Products_Feed::resolve_product_type( $this->product( 1, [ 10, 55 ] ) );
		$this->assertSame( 'Hoodies', $type );
	}

	public function test_product_type_empty_string_when_uncategorized(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertSame( '', WC_AI_Storefront_Products_Feed::resolve_product_type( $this->product( 1, [] ) ) );
	}
}
```

> `WP_Term` must be `instanceof`-checkable — if the test bootstrap (`tests/php/stubs.php`) lacks a `WP_Term` class, add a minimal stub class there (mirror the existing `WC_Product` stub). Mockery's `mock('WP_Term')` only satisfies `instanceof WP_Term` when the class exists.

- [ ] **Step 4: Run to verify fail, then pass.**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php`
First run Expected: FAIL (class/method not found, or WP_Term stub missing) → fix the WP_Term stub if needed → PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php includes/autoload.php tests/php/stubs.php tests/php/unit/ProductsFeedMapperTest.php
git commit -m "feat(products-feed): resolve_product_type() from WC categories"
```

---

### Task 3: `map_product()` — WC product → Shopify shape

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`
- Test: `tests/php/unit/ProductsFeedMapperTest.php`

- [ ] **Step 1: Write failing tests** — add to `ProductsFeedMapperTest.php`:

```php
public function test_map_simple_product_emits_single_default_variant(): void {
	Functions\when( 'get_post_meta' )->justReturn( '' );
	Functions\when( 'html_entity_decode' )->alias( 'html_entity_decode' );
	Functions\when( 'get_term' )->justReturn( false );
	Functions\when( 'apply_filters' )->returnArg( 2 );

	$p = \Mockery::mock( 'WC_Product' );
	$p->shouldReceive( 'get_id' )->andReturn( 22 );
	$p->shouldReceive( 'get_name' )->andReturn( 'Day Hoodie' );
	$p->shouldReceive( 'get_slug' )->andReturn( 'day-hoodie' );
	$p->shouldReceive( 'get_description' )->andReturn( 'Heavyweight French terry.' );
	$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
	$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
	$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
	$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
	$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
	$p->shouldReceive( 'get_sku' )->andReturn( 'DH' );
	$p->shouldReceive( 'get_price' )->andReturn( '48' );
	$p->shouldReceive( 'get_regular_price' )->andReturn( '48' );
	$p->shouldReceive( 'is_on_sale' )->andReturn( false );
	$p->shouldReceive( 'is_in_stock' )->andReturn( true );
	$p->shouldReceive( 'is_purchasable' )->andReturn( true );
	$p->shouldReceive( 'needs_shipping' )->andReturn( true );

	$out = WC_AI_Storefront_Products_Feed::map_product( $p );

	$this->assertSame( 22, $out['id'] );
	$this->assertSame( 'day-hoodie', $out['handle'] );
	$this->assertNull( $out['vendor'] );                 // no brand -> null
	$this->assertSame( '', $out['product_type'] );       // uncategorized -> ''
	$this->assertCount( 1, $out['variants'] );
	$this->assertSame( 'Default Title', $out['variants'][0]['option1'] );
	$this->assertSame( '48.00', $out['variants'][0]['price'] );
	$this->assertNull( $out['variants'][0]['compare_at_price'] );
	$this->assertTrue( $out['variants'][0]['available'] );
	$this->assertArrayNotHasKey( 'options', $out );      // simple -> no options[]
}
```

- [ ] **Step 2: Run to verify it fails.**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php --filter test_map_simple_product`
Expected: FAIL — `map_product` not defined.

- [ ] **Step 3: Implement `map_product()` + helpers.** Add to the class:

```php
	const PRODUCT_FILTER = 'wc_ai_storefront_products_feed_product';

	/**
	 * Map one WC product to the Shopify product JSON shape (pragmatic full —
	 * the fields a trained parser keys on; Shopify-internal fields omitted).
	 *
	 * @param WC_Product $product The product.
	 * @return array Shopify-shaped product.
	 */
	public static function map_product( $product ): array {
		$is_variable = method_exists( $product, 'is_type' ) && $product->is_type( 'variable' );

		$data = [
			'id'           => (int) $product->get_id(),
			'title'        => self::decode( (string) $product->get_name() ),
			'handle'       => (string) $product->get_slug(),
			'body_html'    => (string) $product->get_description(),
			'vendor'       => self::resolve_vendor( $product ),
			'product_type' => self::resolve_product_type( $product ),
			'tags'         => self::resolve_tags( $product ),
			'variants'     => $is_variable
				? self::build_variants( $product )
				: [ self::build_simple_variant( $product ) ],
			'images'       => self::build_images( $product ),
		];

		$options = $is_variable ? self::build_options( $product ) : [];
		if ( ! empty( $options ) ) {
			$data['options'] = $options;
		}

		/**
		 * Filter a single mapped Shopify-shaped product before it enters the
		 * /products.json feed. Mirrors `wc_ai_storefront_ucp_product`.
		 *
		 * @param array      $data    The mapped product.
		 * @param WC_Product $product The source product.
		 */
		$filtered = apply_filters( self::PRODUCT_FILTER, $data, $product );
		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Vendor = first product_brand term, else null (genuinely absent).
	 *
	 * @param WC_Product $product The product.
	 * @return string|null
	 */
	private static function resolve_vendor( $product ): ?string {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return null;
		}
		$brands = wp_get_post_terms( (int) $product->get_id(), 'product_brand', [ 'fields' => 'names' ] );
		if ( is_array( $brands ) && ! empty( $brands ) && is_string( $brands[0] ) ) {
			return self::decode( $brands[0] );
		}
		return null;
	}

	/**
	 * Tags = comma-joined product_tag names (Shopify emits a string).
	 *
	 * @param WC_Product $product The product.
	 * @return string
	 */
	private static function resolve_tags( $product ): string {
		$ids = $product->get_tag_ids();
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return '';
		}
		$names = array_filter(
			array_map(
				static function ( $id ) {
					$t = get_term( (int) $id, 'product_tag' );
					return $t instanceof WP_Term ? self::decode( $t->name ) : null;
				},
				$ids
			)
		);
		return implode( ', ', $names );
	}

	/**
	 * Build the single variant for a simple product.
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private static function build_simple_variant( $product ): array {
		return [
			'id'               => (int) $product->get_id(),
			'title'            => 'Default Title',
			'option1'          => 'Default Title',
			'option2'          => null,
			'option3'          => null,
			'sku'              => (string) $product->get_sku(),
			'price'            => self::money( $product->get_price() ),
			'compare_at_price' => self::compare_at( $product ),
			'available'        => (bool) ( $product->is_in_stock() && $product->is_purchasable() ),
			'requires_shipping' => method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true,
		];
	}

	/**
	 * Format a price as a 2-decimal string (Shopify emits price as a string).
	 *
	 * @param mixed $price Raw WC price.
	 * @return string
	 */
	private static function money( $price ): string {
		return is_numeric( $price ) ? number_format( (float) $price, 2, '.', '' ) : '0.00';
	}

	/**
	 * compare_at_price = regular price when on sale, else null.
	 *
	 * @param WC_Product $product The product (or variation).
	 * @return string|null
	 */
	private static function compare_at( $product ): ?string {
		if ( method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
			$regular = $product->get_regular_price();
			if ( is_numeric( $regular ) ) {
				return self::money( $regular );
			}
		}
		return null;
	}

	/**
	 * Build images[] from the featured image + gallery (needed by both simple
	 * and variable products, so defined here with the simple-product path).
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private static function build_images( $product ): array {
		$ids    = array_filter( array_merge( [ (int) $product->get_image_id() ], array_map( 'intval', (array) $product->get_gallery_image_ids() ) ) );
		$images = [];
		foreach ( array_unique( $ids ) as $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( is_string( $src ) && '' !== $src ) {
				$images[] = [ 'id' => $id, 'src' => $src ];
			}
		}
		return $images;
	}
```

- [ ] **Step 4: Run to verify pass.**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php`
Expected: PASS (simple-product test).

- [ ] **Step 5: Add variable-product helpers + test.** Add these methods:

```php
	/**
	 * Build variants[] for a variable product from its variation children.
	 *
	 * option1/2/3 are filled from the variation's attribute values in the
	 * same order as build_options(); unused slots are null.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private static function build_variants( $product ): array {
		$attr_keys = array_keys( $product->get_variation_attributes() ); // e.g. ['pa_size','pa_color']
		$variants  = [];

		foreach ( $product->get_children() as $child_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $child_id ) : null;
			if ( ! $variation ) {
				continue;
			}
			$attributes = $variation->get_variation_attributes(); // ['attribute_pa_size' => 'm', ...]

			$options = [ null, null, null ];
			$i       = 0;
			foreach ( $attr_keys as $key ) {
				if ( $i > 2 ) {
					break; // Shopify supports exactly 3 option positions.
				}
				$value         = $attributes[ 'attribute_' . sanitize_title( $key ) ] ?? ( $attributes[ 'attribute_' . $key ] ?? '' );
				$options[ $i ] = '' !== $value ? self::decode( (string) $value ) : null;
				$i++;
			}

			$variants[] = [
				'id'                => (int) $variation->get_id(),
				'title'             => implode( ' / ', array_filter( $options, static function ( $v ) { return null !== $v; } ) ),
				'option1'           => $options[0],
				'option2'           => $options[1],
				'option3'           => $options[2],
				'sku'               => (string) $variation->get_sku(),
				'price'             => self::money( $variation->get_price() ),
				'compare_at_price'  => self::compare_at( $variation ),
				'available'         => (bool) ( $variation->is_in_stock() && $variation->is_purchasable() ),
				'requires_shipping' => method_exists( $variation, 'needs_shipping' ) ? (bool) $variation->needs_shipping() : true,
			];
		}

		return $variants;
	}

	/**
	 * Build options[] (name, position, values) for a variable product.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private static function build_options( $product ): array {
		$options  = [];
		$position = 1;
		foreach ( $product->get_variation_attributes() as $name => $values ) {
			if ( $position > 3 ) {
				break;
			}
			$label     = wc_attribute_label( $name, $product );
			$options[] = [
				'name'     => self::decode( (string) $label ),
				'position' => $position,
				'values'   => array_values( array_map( [ self::class, 'decode' ], array_map( 'strval', (array) $values ) ) ),
			];
			$position++;
		}
		return $options;
	}
```

Add a variable-product test (mock `is_type('variable')` true, `get_variation_attributes()`, `get_children()`, and a variation via `wc_get_product` stub) asserting `option1/2/3` order and the `options[]` array. Stub `wc_attribute_label`, `sanitize_title`, `wc_get_product`, `wp_get_attachment_image_url`, `wp_get_post_terms` via Brain Monkey.

- [ ] **Step 6: Run + commit**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedMapperTest.php` → PASS.
```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php tests/php/unit/ProductsFeedMapperTest.php
git commit -m "feat(products-feed): map_product() WC->Shopify shape (simple + variable)"
```

---

### Task 4: Endpoint — rewrite rules, query var, uncached serve handler, wiring

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`
- Modify: `includes/class-wc-ai-storefront.php:230` (`register_rewrite_rules()`)
- Test: `tests/php/unit/ProductsFeedTest.php` (create)

- [ ] **Step 1: Add registration + serve methods** to the feed class (mirror `WC_AI_Storefront_Llms_Txt`):

```php
	const QUERY_VAR = 'wc_ai_storefront_products_json';

	/**
	 * Register the /products.json and /collections/all/products.json rewrites.
	 * Both resolve to the same all-products feed.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^products\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^collections/all/products\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the Shopify-compatible products.json feed.
	 */
	public function serve_products_feed(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) || 'yes' !== ( $settings['products_json_enabled'] ?? 'no' ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: ' . WC_AI_Storefront::discovery_cache_control() );
		header( 'Vary: Host' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a constant.
			status_header( 204 );
			exit;
		}

		echo $this->get_feed_json( $this->request_limit(), $this->request_page() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded JSON.
		exit;
	}

	/**
	 * Build the JSON body for one page (uncached for now; cache added in a
	 * later task). Only syndicated/visible products are included.
	 *
	 * @param int $limit Per-page count.
	 * @param int $page  1-based page.
	 * @return string JSON.
	 */
	private function get_feed_json( int $limit, int $page ): string {
		$query = [
			'status'   => 'publish',
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => false,
			'return'   => 'objects',
		];
		$products = function_exists( 'wc_get_products' ) ? wc_get_products( $query ) : [];

		$mapped = [];
		foreach ( (array) $products as $product ) {
			if ( ! WC_AI_Storefront::is_product_syndicated( $product, WC_AI_Storefront::get_settings() ) ) {
				continue;
			}
			$mapped[] = self::map_product( $product );
		}

		return (string) wp_json_encode( [ 'products' => $mapped ] );
	}

	/**
	 * Resolve ?limit (default 30, max 250, Shopify-style).
	 *
	 * @return int
	 */
	private function request_limit(): int {
		$raw = isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read endpoint.
		if ( $raw < 1 ) {
			return 30;
		}
		return min( $raw, 250 );
	}

	/**
	 * Resolve ?page (1-based, default 1).
	 *
	 * @return int
	 */
	private function request_page(): int {
		$raw = isset( $_GET['page'] ) ? absint( wp_unslash( $_GET['page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read endpoint.
		return max( 1, $raw );
	}
```

> Verify `WC_AI_Storefront::is_product_syndicated()` is public/static and its signature `( $product, array $settings )`. If it is `private`/instance, use the same visibility path UCP uses, or call the existing public wrapper. Grep `function is_product_syndicated` and match exactly.

- [ ] **Step 2: Wire into `register_rewrite_rules()`.** In `includes/class-wc-ai-storefront.php` after the `$ucp = new WC_AI_Storefront_Ucp();` line:

```php
		$products_feed = new WC_AI_Storefront_Products_Feed();
```

and after the `serve_opensearch_xml` registration:

```php
		add_action( 'init', [ $products_feed, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $products_feed, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $products_feed, 'serve_products_feed' ] );
```

- [ ] **Step 3: Write the failing serve test** — create `tests/php/unit/ProductsFeedTest.php`, mirroring the gate/headers pattern in `LlmsTxtTest.php` (read it for the `status_header` throw-sentinel + `@runInSeparateProcess` approach used for `serve_agents_md`). Cover: 404 when `enabled='no'`; 404 when `products_json_enabled='no'`; a 200 path that asserts the body is `{"products":[...]}` JSON (stub `wc_get_products` + `is_product_syndicated` + `get_settings`). One test asserts the alias query var routes through the same handler (it's the same handler, so a query-var-set test suffices).

- [ ] **Step 4: Run fail → pass; verify rewrite registration.**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php includes/class-wc-ai-storefront.php tests/php/unit/ProductsFeedTest.php
git commit -m "feat(products-feed): /products.json endpoint + all-products alias (uncached)"
```

---

### Task 5: Caching (versioned key) + invalidation

**Files:**
- Modify: `includes/ai-storefront/class-wc-ai-storefront-products-feed.php`
- Modify: `includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php`
- Test: `tests/php/unit/ProductsFeedTest.php`

- [ ] **Step 1: Add cache around `get_feed_json`.** Introduce a versioned, host-scoped transient:

```php
	const VERSION_OPTION = 'wc_ai_storefront_products_feed_version';
	const CACHE_TTL      = HOUR_IN_SECONDS;

	/**
	 * Cached page body. Key = host + feed version + limit + page, so a
	 * version bump (on product/settings change) orphans every page at once.
	 *
	 * @param int $limit Per-page count.
	 * @param int $page  1-based page.
	 * @return string JSON.
	 */
	private function get_cached_feed_json( int $limit, int $page ): string {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only inside an md5 cache key.
		$key     = 'wc_ai_sf_pjson_' . md5( $host . "|{$version}|{$limit}|{$page}" );

		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$json = $this->get_feed_json( $limit, $page );
		set_transient( $key, $json, self::CACHE_TTL );
		return $json;
	}
```

Change `serve_products_feed()` to call `$this->get_cached_feed_json(...)` instead of `$this->get_feed_json(...)`.

- [ ] **Step 2: Add the invalidator bump.** In `class-wc-ai-storefront-cache-invalidator.php`, register hooks (mirror the existing `update_option_<SETTINGS>` registration) and add the method:

```php
		add_action( 'save_post_product', [ $this, 'bump_products_feed_version' ] );
		add_action( 'woocommerce_update_product', [ $this, 'bump_products_feed_version' ] );
		add_action( 'woocommerce_delete_product', [ $this, 'bump_products_feed_version' ] );
		add_action( 'update_option_' . WC_AI_Storefront::SETTINGS_OPTION, [ $this, 'bump_products_feed_version' ] );
```

```php
	/**
	 * Bump the products.json feed cache version, orphaning all cached pages.
	 */
	public function bump_products_feed_version(): void {
		$current = (int) get_option( WC_AI_Storefront_Products_Feed::VERSION_OPTION, 1 );
		update_option( WC_AI_Storefront_Products_Feed::VERSION_OPTION, $current + 1, false );
	}
```

- [ ] **Step 3: Tests.** Add to `ProductsFeedTest.php`: a cache-hit test (first call computes, second returns the cached value without re-querying — assert `wc_get_products` called once via a counter stub); and an invalidator test asserting `bump_products_feed_version()` increments the option (stub `get_option`/`update_option`).

- [ ] **Step 4: Run + commit**

Run: `vendor/bin/phpunit tests/php/unit/ProductsFeedTest.php` → PASS.
```bash
git add includes/ai-storefront/class-wc-ai-storefront-products-feed.php includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php tests/php/unit/ProductsFeedTest.php
git commit -m "feat(products-feed): versioned-key cache + product/settings invalidation"
```

---

### Task 6: Discovery-tab toggle (React)

**Files:**
- Modify: the Discovery-tab settings component under `client/settings/ai-storefront/` (grep for where `mcp_enabled` / a ToggleControl is rendered and mirror it)
- Modify: build output via `npm run build`

- [ ] **Step 1: Find the pattern.** `grep -rn "mcp_enabled\|ToggleControl" client/settings/ai-storefront/` to locate how an existing boolean setting is rendered + saved (`settings`/`setSettings` shape, the `'yes'`/`'no'` convention).

- [ ] **Step 2: Add the toggle.** In the Discovery tab, add a `ToggleControl` bound to `products_json_enabled` (checked when `=== 'yes'`, onChange sets `'yes'`/`'no'`), label **"Serve a Shopify-compatible /products.json catalog feed"**, help text: "Lets AI assistants that probe the common `/products.json` endpoint find your catalog. The data is already public via your storefront." Mirror the exact JSX + state wiring of the neighboring `mcp_enabled` toggle.

- [ ] **Step 3: Build + lint.**

Run: `npm run lint:js && npm run build`
Expected: clean; `build/` regenerated.

- [ ] **Step 4: Commit**

```bash
git add client/settings/ai-storefront build/
git commit -m "feat(products-feed): Discovery-tab toggle for products.json feed"
```

---

### Task 7: Documentation

**Files:** `docs/engineering/API-REFERENCE.md`, `ARCHITECTURE.md`, `DATA-MODEL.md`, `HOOKS.md`, `KNOWN-GAPS.md`, `docs/user-guide/USER-GUIDE.md`

- [ ] **Step 1: API-REFERENCE.md** — add a section documenting `GET /products.json` and `GET /collections/all/products.json`: params (`limit` default 30/max 250, `page`), the Shopify response shape (link the spec), gating (`enabled` + `products_json_enabled` + syndication), caching (versioned key + edge). State clearly it's a **non-UCP Shopify-compat surface**.
- [ ] **Step 2: ARCHITECTURE.md** — add the `WC_AI_Storefront_Products_Feed` component and add `/products.json` to the discovery-surfaces list (alongside `/llms.txt`, `/.well-known/ucp`, `/agents.md`, `/opensearch.xml`).
- [ ] **Step 3: DATA-MODEL.md** — document the `products_json_enabled` setting and the `wc_ai_storefront_products_feed_version` option.
- [ ] **Step 4: HOOKS.md** — document the `wc_ai_storefront_products_feed_product` filter (per-product override; mirror the `wc_ai_storefront_ucp_product` entry).
- [ ] **Step 5: KNOWN-GAPS.md** — record the deferred v2 endpoints (`/collections.json`, `/products/{handle}.json`, `/collections/{handle}/products.json`) and the proprietary-format-tracking caveat.
- [ ] **Step 6: USER-GUIDE.md** — document the Discovery-tab toggle (what it does, why an agent benefits), in the merchant-facing voice (no em-dashes).
- [ ] **Step 7: Commit**

```bash
git add docs/engineering docs/user-guide
git commit -m "docs(products-feed): document the products.json feed surface (#449)"
```

---

### Task 8: CHANGELOG + readme + final verification

**Files:** `CHANGELOG.md`, `readme.txt`

- [ ] **Step 1: CHANGELOG `[Unreleased]` `### Features`** — bold-headline + nested bullets, ending `Closes #449.` (describe the endpoints, the Shopify shape, the default-ON toggle, edge-cacheability).
- [ ] **Step 2: readme `= Unreleased =` `**New**`** — one line, no em-dashes.
- [ ] **Step 3: Full gate.**

```bash
composer test
vendor/bin/phpcs includes/ai-storefront/class-wc-ai-storefront-products-feed.php includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php includes/class-wc-ai-storefront.php
vendor/bin/phpstan analyse --memory-limit=1G includes/ai-storefront/class-wc-ai-storefront-products-feed.php
npm run lint:js && npm run build
./bin/make-pot.sh
```
Expected: all green; `.pot`/`build` diffs (if any) committed.

- [ ] **Step 4: Live smoke (post-merge/release).** After this ships and the plugin is updated on a test store, `curl -s 'https://<store>/products.json?limit=2' | python3 -m json.tool` and confirm the Shopify shape + that the `/collections/all/products.json` alias returns the same body.
- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md readme.txt
git commit -m "docs(products-feed): changelog + readme for products.json feed"
```

---

## Notes for the implementer
- **Mirror, don't invent:** `serve_products_feed()` mirrors `serve_llms_txt()`/`serve_agents_md()`; the settings toggle mirrors `mcp_enabled`; the per-product filter mirrors `wc_ai_storefront_ucp_product`. Read those first.
- **phpstan** OOMs at 512M here — always `--memory-limit=1G`.
- **Test serve handlers** with the `LlmsTxtTest` throw-sentinel-on-`status_header` + `@runInSeparateProcess` pattern (handlers call `exit`).
- **Verify before relying on:** `WC_AI_Storefront::is_product_syndicated()` visibility/signature; the `product_brand` taxonomy name; the `WP_Term` test stub.
