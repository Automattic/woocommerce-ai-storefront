<?php
/**
 * Unit tests for WC_AI_Storefront_Products_Feed's WC->Shopify mapper.
 *
 * Covers `resolve_product_type()` (single product_type synthesis from WC
 * categories) and `map_product()` (the full Shopify product shape for both
 * simple and variable products). WP/WC functions are mocked via Brain
 * Monkey; term doubles use the `WP_Term` stub so `instanceof` guards
 * narrow correctly.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductsFeedMapperTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// NOTE: `wp_strip_all_tags` is a real function defined in
		// tests/php/stubs.php (loaded before Patchwork), so it CANNOT be
		// redefined via Brain Monkey — attempting `Functions\when()` on it
		// throws Patchwork's DefinedTooEarly. The stub is WP-faithful, so
		// `decode()` already behaves correctly without stubbing it here.
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
			$t          = \Mockery::mock( 'WP_Term' );
			$t->name    = 55 === $id ? 'Hoodies' : 'Other';
			$t->term_id = $id;
			return $t;
		} );
		// NOTE: `html_entity_decode` is a native PHP function — left
		// unstubbed (the codebase convention; Patchwork can't redefine
		// internals without a patchwork.json allow-list anyway).

		$type = WC_AI_Storefront_Products_Feed::resolve_product_type( $this->product( 1, [ 10, 55 ] ) );
		$this->assertSame( 'Hoodies', $type );
	}

	public function test_product_type_empty_string_when_uncategorized(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertSame( '', WC_AI_Storefront_Products_Feed::resolve_product_type( $this->product( 1, [] ) ) );
	}

	public function test_map_simple_product_emits_single_default_variant(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// No brand assigned -> resolve_vendor() sees an empty term list and
		// returns null. Must be stubbed explicitly: Brain Monkey registers
		// `wp_get_post_terms` as a mockable function once ANY test in the
		// suite expects it, and then demands an expectation here too (it
		// returns null only when no test has touched it — i.e. in isolation).
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

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
		$this->assertNull( $out['vendor'] );                 // No brand -> null.
		$this->assertSame( '', $out['product_type'] );       // Uncategorized -> ''.
		$this->assertCount( 1, $out['variants'] );
		$this->assertSame( 'Default Title', $out['variants'][0]['option1'] );
		$this->assertSame( '48.00', $out['variants'][0]['price'] );
		$this->assertNull( $out['variants'][0]['compare_at_price'] );
		$this->assertTrue( $out['variants'][0]['available'] );
		$this->assertArrayNotHasKey( 'options', $out );      // Simple -> no options[].
	}

	public function test_map_variable_product_emits_options_and_positional_variants(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// See the simple-product test: stub the brand lookup so the suite-wide
		// Brain Monkey registration of wp_get_post_terms is satisfied here.
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'sanitize_title' )->alias(
			function ( $t ) {
				return strtolower( str_replace( ' ', '-', (string) $t ) );
			}
		);
		Functions\when( 'wc_attribute_label' )->alias(
			function ( $name ) {
				// pa_size -> "Size", pa_color -> "Color".
				return ucfirst( str_replace( 'pa_', '', (string) $name ) );
			}
		);

		// One variation: Medium / Red. Attribute keys are namespaced with
		// the `attribute_` prefix, mirroring WC's get_variation_attributes().
		$variation = \Mockery::mock( 'WC_Product' );
		$variation->shouldReceive( 'get_id' )->andReturn( 101 );
		$variation->shouldReceive( 'get_variation_attributes' )->andReturn(
			[
				'attribute_pa_size'  => 'm',
				'attribute_pa_color' => 'red',
			]
		);
		$variation->shouldReceive( 'get_sku' )->andReturn( 'HD-M-RED' );
		$variation->shouldReceive( 'get_price' )->andReturn( '52' );
		$variation->shouldReceive( 'is_on_sale' )->andReturn( false );
		$variation->shouldReceive( 'get_regular_price' )->andReturn( '52' );
		$variation->shouldReceive( 'is_in_stock' )->andReturn( true );
		$variation->shouldReceive( 'is_purchasable' )->andReturn( true );
		$variation->shouldReceive( 'needs_shipping' )->andReturn( true );

		Functions\when( 'wc_get_product' )->justReturn( $variation );

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 90 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Range Hoodie' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'range-hoodie' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( true );
		// Order here defines option1/option2 positions: size first, color second.
		$p->shouldReceive( 'get_variation_attributes' )->andReturn(
			[
				'pa_size'  => [ 's', 'm' ],
				'pa_color' => [ 'red', 'blue' ],
			]
		);
		$p->shouldReceive( 'get_children' )->andReturn( [ 101 ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		// options[] present, ordered, positional.
		$this->assertArrayHasKey( 'options', $out );
		$this->assertCount( 2, $out['options'] );
		$this->assertSame( 'Size', $out['options'][0]['name'] );
		$this->assertSame( 1, $out['options'][0]['position'] );
		$this->assertSame( [ 's', 'm' ], $out['options'][0]['values'] );
		$this->assertSame( 'Color', $out['options'][1]['name'] );
		$this->assertSame( 2, $out['options'][1]['position'] );

		// variants[] map attribute values into the matching option slot.
		$this->assertCount( 1, $out['variants'] );
		$v = $out['variants'][0];
		$this->assertSame( 101, $v['id'] );
		$this->assertSame( 'm', $v['option1'] );    // pa_size position.
		$this->assertSame( 'red', $v['option2'] );  // pa_color position.
		$this->assertNull( $v['option3'] );
		$this->assertSame( 'm / red', $v['title'] );
		$this->assertSame( '52.00', $v['price'] );
		$this->assertSame( 'HD-M-RED', $v['sku'] );
		$this->assertTrue( $v['available'] );
	}

	// ------------------------------------------------------------------
	// map_product() — variant pricing + availability edge cases
	// ------------------------------------------------------------------

	public function test_map_simple_product_on_sale_sets_compare_at_price(): void {
		// On sale: compare_at_price is the (higher) regular price, money-formatted;
		// price is the current (sale) price.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$p = $this->mappable_simple_product(
			[
				'price'         => '40',
				'regular_price' => '60',
				'on_sale'       => true,
			]
		);

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( '40.00', $out['variants'][0]['price'] );
		$this->assertSame( '60.00', $out['variants'][0]['compare_at_price'] );
	}

	public function test_map_simple_product_out_of_stock_is_unavailable(): void {
		// Out of stock -> available:false even if purchasable, because
		// available = is_in_stock() && is_purchasable().
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$p = $this->mappable_simple_product( [ 'in_stock' => false ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertFalse( $out['variants'][0]['available'] );
	}

	public function test_map_simple_product_resolves_vendor_from_brand_term(): void {
		// First product_brand term name becomes the Shopify `vendor`.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ 'Gizmonic' ] );

		$p = $this->mappable_simple_product();

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( 'Gizmonic', $out['vendor'] );
	}

	public function test_map_simple_product_emits_base_currency_not_presentment(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 26 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'canvas-belt' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$p->shouldReceive( 'get_sku' )->andReturn( 'BELT' );
		// Multi-currency presentment: 'view' (default) returns the converted
		// CAD price; 'edit' returns the stored USD base. The feed must emit base.
		$p->shouldReceive( 'get_price' )->with( 'edit' )->andReturn( '45.99' );
		$p->shouldReceive( 'get_price' )->with( 'view' )->andReturn( '64.99' );
		$p->shouldReceive( 'get_price' )->withNoArgs()->andReturn( '64.99' );
		$p->shouldReceive( 'get_regular_price' )->andReturn( '45.99' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( false );
		$p->shouldReceive( 'is_in_stock' )->andReturn( true );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'needs_shipping' )->andReturn( true );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( '45.99', $out['variants'][0]['price'] );
	}

	public function test_map_variation_emits_base_currency_not_presentment(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'sanitize_title' )->alias(
			function ( $t ) {
				return strtolower( str_replace( ' ', '-', (string) $t ) );
			}
		);
		Functions\when( 'wc_attribute_label' )->alias(
			function ( $name ) {
				return ucfirst( str_replace( 'pa_', '', (string) $name ) );
			}
		);

		$variation = \Mockery::mock( 'WC_Product' );
		$variation->shouldReceive( 'get_id' )->andReturn( 3890 );
		$variation->shouldReceive( 'get_variation_attributes' )->andReturn(
			[ 'attribute_pa_size' => 'sm' ]
		);
		$variation->shouldReceive( 'get_sku' )->andReturn( 'BELT-SM' );
		// Multi-currency presentment vs stored base, same shape as the simple test.
		$variation->shouldReceive( 'get_price' )->with( 'edit' )->andReturn( '45.99' );
		$variation->shouldReceive( 'get_price' )->with( 'view' )->andReturn( '64.99' );
		$variation->shouldReceive( 'get_price' )->withNoArgs()->andReturn( '64.99' );
		$variation->shouldReceive( 'is_on_sale' )->andReturn( false );
		$variation->shouldReceive( 'get_regular_price' )->andReturn( '45.99' );
		$variation->shouldReceive( 'is_in_stock' )->andReturn( true );
		$variation->shouldReceive( 'is_purchasable' )->andReturn( true );
		$variation->shouldReceive( 'needs_shipping' )->andReturn( true );

		Functions\when( 'wc_get_product' )->justReturn( $variation );

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 26 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'canvas-belt' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( true );
		$p->shouldReceive( 'get_variation_attributes' )->andReturn(
			[ 'pa_size' => [ 'sm', 'lxl' ] ]
		);
		$p->shouldReceive( 'get_children' )->andReturn( [ 3890 ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( '45.99', $out['variants'][0]['price'] );
	}

	public function test_compare_at_uses_base_regular_price_not_presentment(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 26 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'canvas-belt' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$p->shouldReceive( 'get_sku' )->andReturn( 'BELT' );
		$p->shouldReceive( 'get_price' )->with( 'edit' )->andReturn( '34.99' ); // base sale
		$p->shouldReceive( 'get_price' )->withNoArgs()->andReturn( '49.99' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( true );
		// Multi-currency presentment vs stored base for the REGULAR price.
		$p->shouldReceive( 'get_regular_price' )->with( 'edit' )->andReturn( '45.99' );
		$p->shouldReceive( 'get_regular_price' )->withNoArgs()->andReturn( '64.99' );
		$p->shouldReceive( 'is_in_stock' )->andReturn( true );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'needs_shipping' )->andReturn( true );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p );

		$this->assertSame( '34.99', $out['variants'][0]['price'] );            // base sale price
		$this->assertSame( '45.99', $out['variants'][0]['compare_at_price'] ); // base regular, not 64.99
	}

	// ------------------------------------------------------------------
	// resolve_product_type() — deepest-category + RankMath + stale meta
	// ------------------------------------------------------------------

	public function test_product_type_prefers_deepest_assigned_category(): void {
		// No SEO primary meta -> fall through to the deepest (most-specific)
		// assigned category. get_ancestors returns a longer chain for the
		// deeper term so the usort depth comparator actually runs.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				$names      = [
					10 => 'Apparel',     // Shallow (no ancestors).
					20 => 'Pullovers',   // Deepest (two ancestors).
					30 => 'Tops',        // Mid (one ancestor).
				];
				$t          = \Mockery::mock( 'WP_Term' );
				$t->name    = $names[ $id ] ?? 'Unknown';
				$t->term_id = $id;
				return $t;
			}
		);
		Functions\when( 'get_ancestors' )->alias(
			static function ( $term_id ) {
				$depth = [
					10 => [],            // 0 ancestors.
					20 => [ 30, 10 ],    // 2 ancestors -> deepest.
					30 => [ 10 ],        // 1 ancestor.
				];
				return $depth[ $term_id ] ?? [];
			}
		);

		$type = WC_AI_Storefront_Products_Feed::resolve_product_type( $this->product( 1, [ 10, 20, 30 ] ) );
		$this->assertSame( 'Pullovers', $type );
	}

	public function test_product_type_uses_rank_math_primary_when_assigned(): void {
		// Yoast meta absent; RankMath primary set AND assigned (per the
		// stale-meta cross-check) -> that term wins over the category fallback.
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				return 'rank_math_primary_product_cat' === $key ? 77 : '';
			}
		);
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				$t          = \Mockery::mock( 'WP_Term' );
				$t->name    = 77 === $id ? 'Outerwear' : 'Other';
				$t->term_id = $id;
				return $t;
			}
		);

		$type = WC_AI_Storefront_Products_Feed::resolve_product_type( $this->product( 1, [ 12, 77 ] ) );
		$this->assertSame( 'Outerwear', $type );
	}

	public function test_product_type_ignores_stale_unassigned_primary_meta(): void {
		// Regression guard for the stale-SEO-meta fix: the primary-category id
		// points at a term the product is NO LONGER assigned to (99 not in the
		// assigned list). It must be ignored, falling through to the assigned
		// category instead of emitting the wrong type.
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				return '_yoast_wpseo_primary_product_cat' === $key ? 99 : '';
			}
		);
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				$names      = [
					42 => 'Accessories', // The one the product IS assigned to.
					99 => 'Footwear',    // Stale primary — product no longer in it.
				];
				$t          = \Mockery::mock( 'WP_Term' );
				$t->name    = $names[ $id ] ?? 'Unknown';
				$t->term_id = $id;
				return $t;
			}
		);

		$type = WC_AI_Storefront_Products_Feed::resolve_product_type( $this->product( 1, [ 42 ] ) );
		$this->assertSame( 'Accessories', $type );
		$this->assertNotSame( 'Footwear', $type );
	}

	/**
	 * Build a simple WC_Product mock fully wired for map_product(), with
	 * overridable price/stock/sale fields. Uncategorized + untagged + no
	 * images so the type/vendor/image paths take their empty branches unless
	 * the caller stubs otherwise.
	 *
	 * @param array $overrides price|regular_price|on_sale|in_stock|purchasable.
	 * @return \Mockery\MockInterface
	 */
	private function mappable_simple_product( array $overrides = [] ) {
		$o = array_merge(
			[
				'price'         => '20',
				'regular_price' => '20',
				'on_sale'       => false,
				'in_stock'      => true,
				'purchasable'   => true,
			],
			$overrides
		);

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 500 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Gadget' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'gadget' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$p->shouldReceive( 'get_sku' )->andReturn( 'GAD' );
		$p->shouldReceive( 'get_price' )->andReturn( $o['price'] );
		$p->shouldReceive( 'get_regular_price' )->andReturn( $o['regular_price'] );
		$p->shouldReceive( 'is_on_sale' )->andReturn( $o['on_sale'] );
		$p->shouldReceive( 'is_in_stock' )->andReturn( $o['in_stock'] );
		$p->shouldReceive( 'is_purchasable' )->andReturn( $o['purchasable'] );
		$p->shouldReceive( 'needs_shipping' )->andReturn( true );

		return $p;
	}

	// ------------------------------------------------------------------
	// map_product() — timestamp fields (published_at/created_at/updated_at)
	// ------------------------------------------------------------------

	public function test_map_product_emits_rfc3339_utc_timestamps_when_present(): void {
		// Shopify's shape always carries published_at/created_at/updated_at
		// as RFC 3339 UTC (`Z`) strings. WC has only created/modified, so
		// published_at and created_at both map to created; updated_at maps
		// to modified. A trained parser keys on these for freshness/sort.
		$created  = new \DateTimeImmutable( '@1737017400' ); // 2025-01-16T08:50:00Z
		$modified = new \DateTimeImmutable( '@1740000000' ); // 2025-02-19T21:20:00Z

		$out = WC_AI_Storefront_Products_Feed::map_product(
			$this->product_with_dates( $created, $modified )
		);

		$this->assertSame( '2025-01-16T08:50:00Z', $out['published_at'] );
		$this->assertSame( '2025-01-16T08:50:00Z', $out['created_at'] );
		$this->assertSame( '2025-02-19T21:20:00Z', $out['updated_at'] );
	}

	public function test_map_product_emits_null_timestamps_when_getters_absent(): void {
		// A product object lacking the date getters (the method_exists guard
		// fails) must still emit the keys — Shopify always emits them — as
		// null, never omitted and never fabricated.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $this->mappable_simple_product() );

		$this->assertArrayHasKey( 'published_at', $out );
		$this->assertArrayHasKey( 'created_at', $out );
		$this->assertArrayHasKey( 'updated_at', $out );
		$this->assertNull( $out['published_at'] );
		$this->assertNull( $out['created_at'] );
		$this->assertNull( $out['updated_at'] );
	}

	public function test_map_product_treats_epoch_zero_date_as_null(): void {
		// getTimestamp() returns 0 for an uninitialized WC_DateTime. Emitting
		// "1970-01-01" would poison agent diff-sync cursors (always older than
		// any real sync), so the $ts > 0 guard must drop it to null.
		$epoch = new \DateTimeImmutable( '@0' );

		$out = WC_AI_Storefront_Products_Feed::map_product(
			$this->product_with_dates( $epoch, $epoch )
		);

		$this->assertNull( $out['published_at'] );
		$this->assertNull( $out['created_at'] );
		$this->assertNull( $out['updated_at'] );
	}

	/**
	 * A WC_Product whose get_date_created()/get_date_modified() resolve via
	 * method_exists (a real subclass, not a Mockery double — Mockery doubles
	 * of the date-less WC_Product stub fail method_exists). All other getters
	 * return benign empty/default values so map_product's vendor/type/tags/
	 * image paths take their empty branches.
	 *
	 * @param ?\DateTimeInterface $created  Created date (or null).
	 * @param ?\DateTimeInterface $modified Modified date (or null).
	 * @return \WC_Product
	 */
	private function product_with_dates( ?\DateTimeInterface $created, ?\DateTimeInterface $modified ): \WC_Product {
		// Uncategorized/untagged so resolve_* take empty branches without
		// needing get_post_meta/wp_get_post_terms function stubs.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );

		return new class( $created, $modified ) extends \WC_Product {
			private ?\DateTimeInterface $created;
			private ?\DateTimeInterface $modified;

			public function __construct( ?\DateTimeInterface $created, ?\DateTimeInterface $modified ) {
				$this->created  = $created;
				$this->modified = $modified;
			}

			public function get_date_created() {
				return $this->created;
			}

			public function get_date_modified() {
				return $this->modified;
			}

			public function get_slug(): string {
				return 'dated-product';
			}

			/** @return int[] */
			public function get_category_ids(): array {
				return [];
			}

			/** @return int[] */
			public function get_tag_ids(): array {
				return [];
			}

			public function get_image_id(): int {
				return 0;
			}

			/** @return int[] */
			public function get_gallery_image_ids(): array {
				return [];
			}

			public function get_sku(): string {
				return 'DATED';
			}

			public function get_price( string $context = 'view' ): string {
				return '20';
			}

			public function get_regular_price( string $context = 'view' ): string {
				return '20';
			}

			public function is_on_sale(): bool {
				return false;
			}

			public function needs_shipping(): bool {
				return true;
			}
		};
	}
}
