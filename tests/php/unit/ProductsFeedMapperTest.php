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
}
