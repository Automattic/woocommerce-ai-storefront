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
}
