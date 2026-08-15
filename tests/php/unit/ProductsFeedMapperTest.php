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
		// Brain Monkey registers a function suite-wide once ANY test mocks it,
		// so every test that reaches weight_grams() must have an expectation —
		// same trap this file already documents for wp_get_post_terms. Default
		// to a kg store; tests asserting conversion re-stub with their own unit.
		Functions\when( 'wc_get_weight' )->alias(
			static function ( $weight ) {
				return (float) $weight * 1000.0;
			}
		);
		// Attachment reads behind the Shopify image record (#627). In production
		// these are cache hits primed by wp_get_attachment_image_url(); here they
		// only need to exist. Tests asserting width/height/dates re-stub them.
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$variation->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$variation->shouldReceive( 'get_weight' )->andReturn( '' );
		$variation->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$variation->shouldReceive( 'get_parent_id' )->andReturn( 90 );
		// No image of its own — 'edit' context reports the raw prop, which
		// is what build_image_owner_map() asks for.
		$variation->shouldReceive( 'get_image_id' )->with( 'edit' )->andReturn( 0 );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
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
		// A variation points at its PARENT, unlike a simple product's
		// synthesized variant, which points at itself.
		$this->assertSame( 90, $v['product_id'] );
		// menu_order is 0 (WooCommerce's "unset"), so position falls through
		// to the 1-based loop index rather than emitting a 0.
		$this->assertSame( 1, $v['position'] );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$variation->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$variation->shouldReceive( 'get_weight' )->andReturn( '' );
		$variation->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$variation->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		// No image of its own — 'edit' context reports the raw prop, which
		// is what build_image_owner_map() asks for.
		$variation->shouldReceive( 'get_image_id' )->with( 'edit' )->andReturn( 0 );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
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
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
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
	// map_product() — compact list-feed images (>=1 image rule)
	// ------------------------------------------------------------------

	public function test_compact_emits_only_first_image_when_featured_set(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => "https://x/$id.jpg" );

		$p = $this->mappable_product_with_images( 11, [ 12, 13 ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p, true );

		$this->assertCount( 1, $out['images'] );
		$this->assertSame( 11, $out['images'][0]['id'] ); // the featured image
	}

	public function test_compact_falls_back_to_first_gallery_when_no_featured(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => "https://x/$id.jpg" );

		$p = $this->mappable_product_with_images( 0, [ 12, 13 ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p, true );

		$this->assertCount( 1, $out['images'] );           // the >=1 rule
		$this->assertSame( 12, $out['images'][0]['id'] );  // first gallery
	}

	public function test_full_mode_default_emits_all_images(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => "https://x/$id.jpg" );

		$p = $this->mappable_product_with_images( 11, [ 12, 13 ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p ); // default false

		$this->assertCount( 3, $out['images'] );
	}

	public function test_compact_emits_zero_images_when_none_resolvable(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => "https://x/$id.jpg" );

		$p = $this->mappable_product_with_images( 0, [] ); // no featured, no gallery

		$out = WC_AI_Storefront_Products_Feed::map_product( $p, true );

		$this->assertCount( 0, $out['images'] ); // nothing to emit
	}

	public function test_compact_skips_unresolvable_featured_and_uses_first_valid_gallery(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		// Featured (11) is unresolvable (''); the first gallery id (12) resolves.
		// Locks the "first VALID image only" contract — the break sits INSIDE the
		// non-empty-src guard, so a broken featured image is skipped, not counted.
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			fn( $id ) => 11 === $id ? '' : "https://x/$id.jpg"
		);

		$p = $this->mappable_product_with_images( 11, [ 12, 13 ] );

		$out = WC_AI_Storefront_Products_Feed::map_product( $p, true );

		$this->assertCount( 1, $out['images'] );          // the >=1 rule still holds
		$this->assertSame( 12, $out['images'][0]['id'] ); // first VALID = gallery 12, not the broken featured
	}

	/**
	 * Build a simple WC_Product mock fully wired for map_product() with the
	 * given featured-image id (0 = none) and gallery image ids. Mirrors
	 * mappable_simple_product() but exposes the image getters.
	 *
	 * @param int   $featured_id Featured image attachment id (0 for none).
	 * @param int[] $gallery_ids Gallery image attachment ids.
	 * @return \Mockery\MockInterface
	 */
	private function mappable_product_with_images( int $featured_id, array $gallery_ids ) {
		$p = \Mockery::mock( 'WC_Product' );
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_id' )->andReturn( 500 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Gadget' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'gadget' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( $featured_id );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( $gallery_ids );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$p->shouldReceive( 'get_sku' )->andReturn( 'GAD' );
		$p->shouldReceive( 'get_price' )->andReturn( '20' );
		$p->shouldReceive( 'get_regular_price' )->andReturn( '20' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( false );
		$p->shouldReceive( 'is_in_stock' )->andReturn( true );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'needs_shipping' )->andReturn( true );

		return $p;
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

	// ------------------------------------------------------------------
	// variant_ids — the reverse image relation, and the 'edit'-context trap
	// ------------------------------------------------------------------

	public function test_variation_without_its_own_image_owns_nothing(): void {
		// THE load-bearing test for #627. WC_Product_Variation::get_image_id()
		// falls back to the PARENT's image in 'view' context, so asking the
		// obvious way gets "yes, the parent's" from a photo-less variation.
		// That would list this variation under the featured image's
		// variant_ids — and the failure is invisible to a smoke test, because
		// the field comes back populated either way.
		//
		// The double answers 99 in 'view' (the parent fallback) and 0 in
		// 'edit' (the raw prop), exactly as WooCommerce does. A test whose
		// double ignored the context argument would pass against the bug.
		$out = $this->map_variable_product_with_variations(
			[
				[ 'id' => 501, 'edit_image' => 0, 'view_image' => 99 ],
			],
			99,
			[]
		);

		$this->assertSame( [ 99 ], array_column( $out['images'], 'id' ) );
		$this->assertSame(
			[],
			$out['images'][0]['variant_ids'],
			'A parent-fallback image must never be reported as variation-owned.'
		);
	}

	public function test_variant_ids_group_every_variation_sharing_an_image(): void {
		// One colourway photo covers several sizes. This many-to-many reverse
		// index is how an agent picks the right photo for a chosen colour;
		// WooCommerce only models the forward direction.
		$out = $this->map_variable_product_with_variations(
			[
				[ 'id' => 501, 'edit_image' => 77 ],
				[ 'id' => 502, 'edit_image' => 77 ],
				[ 'id' => 503, 'edit_image' => 88 ],
			],
			0,
			[]
		);

		$by_id = array_column( $out['images'], 'variant_ids', 'id' );
		$this->assertSame( [ 501, 502 ], $by_id[77] );
		$this->assertSame( [ 503 ], $by_id[88] );
	}

	public function test_variation_owned_images_join_the_product_gallery(): void {
		// WooCommerce keeps variation images OUT of the parent gallery, while
		// Shopify guarantees a variant's image is always one of the product's
		// images (110 of 110 on a live feed). Without this union variant_ids
		// would be empty on every image for a typical store, so the field
		// would do nothing at all.
		$out = $this->map_variable_product_with_variations(
			[
				[ 'id' => 501, 'edit_image' => 77 ],
			],
			11,
			[ 12 ]
		);

		$this->assertSame(
			[ 11, 12, 77 ],
			array_column( $out['images'], 'id' ),
			'Featured, then gallery, then variation-owned.'
		);
		$this->assertSame( [ 1, 2, 3 ], array_column( $out['images'], 'position' ) );
		$this->assertSame( [ 501 ], $out['images'][2]['variant_ids'] );
	}

	/**
	 * Map a variable product whose variations have the given ids and image
	 * ownership, plus a featured image and gallery on the parent.
	 *
	 * Each $variations entry takes `id`, `edit_image` and optionally
	 * `view_image` — the value get_image_id() returns in the default 'view'
	 * context, defaulting to the same as `edit_image`. Setting them apart is
	 * what lets a test prove the production code asks for 'edit'.
	 *
	 * @param array $variations  Variation specs.
	 * @param int   $featured_id Parent's featured image id (0 for none).
	 * @param int[] $gallery_ids Parent's gallery image ids.
	 * @return array The mapped product.
	 */
	private function map_variable_product_with_variations( array $variations, int $featured_id, array $gallery_ids ): array {
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static function ( $id ) {
				return "https://ex.test/{$id}.jpg";
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'sanitize_title' )->alias(
			static function ( $t ) {
				return strtolower( str_replace( ' ', '-', (string) $t ) );
			}
		);
		Functions\when( 'wc_attribute_label' )->justReturn( 'Size' );

		$doubles = [];
		foreach ( $variations as $spec ) {
			$v = \Mockery::mock( 'WC_Product' );
			$v->shouldReceive( 'get_id' )->andReturn( $spec['id'] );
			$v->shouldReceive( 'get_image_id' )->with( 'edit' )->andReturn( $spec['edit_image'] );
			$v->shouldReceive( 'get_image_id' )->withNoArgs()->andReturn( $spec['view_image'] ?? $spec['edit_image'] );
			$v->shouldReceive( 'get_variation_attributes' )->andReturn( [ 'attribute_pa_size' => 'm' ] );
			$v->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
			$v->shouldReceive( 'get_weight' )->andReturn( '' );
			$v->shouldReceive( 'get_menu_order' )->andReturn( 0 );
			$v->shouldReceive( 'get_parent_id' )->andReturn( 90 );
			$v->shouldReceive( 'get_sku' )->andReturn( 'SKU-' . $spec['id'] );
			$v->shouldReceive( 'get_price' )->andReturn( '10' );
			$v->shouldReceive( 'get_regular_price' )->andReturn( '10' );
			$v->shouldReceive( 'is_on_sale' )->andReturn( false );
			$v->shouldReceive( 'is_in_stock' )->andReturn( true );
			$v->shouldReceive( 'is_purchasable' )->andReturn( true );
			$v->shouldReceive( 'needs_shipping' )->andReturn( true );
			$doubles[ $spec['id'] ] = $v;
		}
		Functions\when( 'wc_get_product' )->alias(
			static function ( $id ) use ( $doubles ) {
				return $doubles[ $id ] ?? null;
			}
		);

		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( 90 );
		$p->shouldReceive( 'get_name' )->andReturn( 'Hoodie' );
		$p->shouldReceive( 'get_slug' )->andReturn( 'hoodie' );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( $featured_id );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( $gallery_ids );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( true );
		$p->shouldReceive( 'get_variation_attributes' )->andReturn( [ 'pa_size' => [ 'm' ] ] );
		$p->shouldReceive( 'get_children' )->andReturn( array_keys( $doubles ) );

		return WC_AI_Storefront_Products_Feed::map_product( $p );
	}

	// ------------------------------------------------------------------
	// Shopify image records — width/height/position/dates/alt (#627)
	// ------------------------------------------------------------------

	public function test_image_record_carries_the_full_shopify_field_set(): void {
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://ex.test/a.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'  => 4000,
				'height' => 2500,
			]
		);
		Functions\when( 'get_post' )->justReturn(
			(object) [
				'post_date_gmt'     => '2026-06-18 17:40:09',
				'post_modified_gmt' => '2026-06-18 17:40:12',
			]
		);
		Functions\when( 'get_post_meta' )->justReturn( 'A red mug' );

		$out = WC_AI_Storefront_Products_Feed::map_product( $this->mappable_product_with_images( 11, [] ) );

		// Key order mirrors Shopify's featured_image so a field-by-field diff
		// against a live feed reads cleanly.
		$this->assertSame(
			[ 'id', 'product_id', 'position', 'created_at', 'updated_at', 'alt', 'width', 'height', 'src', 'variant_ids' ],
			array_keys( $out['images'][0] )
		);
		$this->assertSame( 4000, $out['images'][0]['width'] );
		$this->assertSame( 2500, $out['images'][0]['height'] );
		$this->assertSame( '2026-06-18T17:40:09Z', $out['images'][0]['created_at'] );
		$this->assertSame( '2026-06-18T17:40:12Z', $out['images'][0]['updated_at'] );
		$this->assertSame( 'A red mug', $out['images'][0]['alt'] );
		$this->assertSame( 1, $out['images'][0]['position'] );
		$this->assertSame( 500, $out['images'][0]['product_id'] );
	}

	public function test_image_record_nulls_absent_metadata_rather_than_erroring(): void {
		// wp_get_attachment_metadata() returns FALSE (not []) when the meta row
		// is missing, and width/height are absent even from a real array for
		// SVGs and programmatically inserted attachments.
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://ex.test/a.svg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$out = WC_AI_Storefront_Products_Feed::map_product( $this->mappable_product_with_images( 11, [] ) );

		$this->assertNull( $out['images'][0]['width'] );
		$this->assertNull( $out['images'][0]['height'] );
		$this->assertNull( $out['images'][0]['created_at'] );
		$this->assertNull( $out['images'][0]['updated_at'] );
		$this->assertNull( $out['images'][0]['alt'] );
		$this->assertSame( 'https://ex.test/a.svg', $out['images'][0]['src'] );
	}

	public function test_image_dates_drop_the_zero_placeholder(): void {
		// WordPress writes '0000-00-00 00:00:00' for an unset date. Rendering
		// that as a year-zero timestamp would poison an agent's diff-sync
		// cursor, the same reason iso_date() drops epoch 0.
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://ex.test/a.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post' )->justReturn(
			(object) [
				'post_date_gmt'     => '0000-00-00 00:00:00',
				'post_modified_gmt' => '0000-00-00 00:00:00',
			]
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$out = WC_AI_Storefront_Products_Feed::map_product( $this->mappable_product_with_images( 11, [] ) );

		$this->assertNull( $out['images'][0]['created_at'] );
		$this->assertNull( $out['images'][0]['updated_at'] );
	}

	public function test_image_positions_are_dense_over_resolved_images_only(): void {
		// Image 12 fails to resolve, so 13 must be position 2 — the numbering
		// counts images that made it into the feed, not raw gallery slots.
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static function ( $id ) {
				return 12 === $id ? '' : "https://ex.test/{$id}.jpg";
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [] );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$out = WC_AI_Storefront_Products_Feed::map_product( $this->mappable_product_with_images( 11, [ 12, 13 ] ) );

		$this->assertSame( [ 11, 13 ], array_column( $out['images'], 'id' ) );
		$this->assertSame( [ 1, 2 ], array_column( $out['images'], 'position' ) );
	}

	public function test_compact_truncates_length_without_thinning_the_record(): void {
		// Compact mode truncates the array's LENGTH only — the one entry it
		// keeps must carry the complete field set, not fall back to {id, src}.
		//
		// Position is 1 here because the broken featured image never enters
		// the resolved list, so image 12 genuinely is first. Dense numbering
		// over emitted images is what Shopify does; the ordering rule itself
		// is pinned by test_image_positions_are_dense_over_resolved_images_only.
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static function ( $id ) {
				return 11 === $id ? '' : "https://ex.test/{$id}.jpg";
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 800, 'height' => 600 ] );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$out = WC_AI_Storefront_Products_Feed::map_product( $this->mappable_product_with_images( 11, [ 12, 13 ] ), true );

		$this->assertCount( 1, $out['images'] );
		$this->assertSame( 12, $out['images'][0]['id'] );
		$this->assertSame( 1, $out['images'][0]['position'] );
		// Truncating length must not truncate per-entry richness.
		$this->assertSame( 800, $out['images'][0]['width'] );
		$this->assertArrayHasKey( 'variant_ids', $out['images'][0] );
	}

	// ------------------------------------------------------------------
	// Shopify variant scalars — grams, taxable, position, product_id (#627)
	// ------------------------------------------------------------------

	public function test_grams_converts_from_the_stores_configured_weight_unit(): void {
		// The store is configured in lbs, so 2 lbs must emit as 907 g.
		// Assuming kilograms here would silently corrupt every non-metric
		// store, which is why wc_get_weight() is called without a $from_unit
		// and left to read `woocommerce_weight_unit` itself.
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wc_get_weight' )->alias(
			static function ( $weight, $to_unit, $from_unit = '' ) {
				return (float) $weight * 453.59237; // lbs -> g
			}
		);

		$out = WC_AI_Storefront_Products_Feed::map_product(
			$this->mappable_simple_product( [ 'weight' => '2' ] )
		);

		$this->assertSame( 907, $out['variants'][0]['grams'] );
	}

	public function test_grams_is_integer_zero_when_no_weight_is_recorded(): void {
		// Verified against a live Shopify feed: 6 of 413 variants carry
		// grams:0 and none carry null, three of those being physical goods
		// that simply have no weight recorded. So 0 — never null, never an
		// omitted key — is the faithful shape.
		$this->stub_empty_taxonomy_lookups();
		Functions\when( 'wc_get_weight' )->alias(
			static function ( $weight ) {
				return (float) $weight * 1000.0;
			}
		);

		$out = WC_AI_Storefront_Products_Feed::map_product(
			$this->mappable_simple_product( [ 'weight' => '' ] )
		);

		$this->assertArrayHasKey( 'grams', $out['variants'][0] );
		$this->assertSame( 0, $out['variants'][0]['grams'] );
	}

	public function test_taxable_reflects_the_products_tax_status(): void {
		$this->stub_empty_taxonomy_lookups();

		$taxable = WC_AI_Storefront_Products_Feed::map_product(
			$this->mappable_simple_product( [ 'tax_status' => 'taxable' ] )
		);
		$exempt  = WC_AI_Storefront_Products_Feed::map_product(
			$this->mappable_simple_product( [ 'tax_status' => 'none' ] )
		);

		$this->assertTrue( $taxable['variants'][0]['taxable'] );
		$this->assertFalse( $exempt['variants'][0]['taxable'] );
	}

	public function test_simple_variant_carries_product_id_and_position_one(): void {
		// A simple product's synthesized variant points at the product itself
		// and is always first, since there is exactly one of them.
		$this->stub_empty_taxonomy_lookups();

		$out = WC_AI_Storefront_Products_Feed::map_product(
			$this->mappable_simple_product( [ 'id' => 4242 ] )
		);

		$this->assertSame( 4242, $out['variants'][0]['product_id'] );
		$this->assertSame( 1, $out['variants'][0]['position'] );
	}

	/**
	 * Stub the taxonomy/meta lookups map_product() makes for vendor, tags and
	 * product_type so a fixture with no terms takes the empty branches. Brain
	 * Monkey demands an expectation for any function some other test in the
	 * suite has registered, so these must be present even when the assertions
	 * under test never touch them.
	 */
	private function stub_empty_taxonomy_lookups(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
	}

	/**
	 * Build a simple WC_Product mock fully wired for map_product(), with
	 * overridable price/stock/sale fields. Uncategorized + untagged + no
	 * images so the type/vendor/image paths take their empty branches unless
	 * the caller stubs otherwise.
	 *
	 * @param array $overrides price|regular_price|on_sale|in_stock|purchasable|weight|tax_status|id.
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
				'weight'        => '',
				'tax_status'    => 'taxable',
				'id'            => 500,
			],
			$overrides
		);

		$p = \Mockery::mock( 'WC_Product' );
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( $o['tax_status'] );
		$p->shouldReceive( 'get_weight' )->andReturn( $o['weight'] );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_id' )->andReturn( $o['id'] );
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

			public function get_image_id( string $context = 'view' ): int {
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
