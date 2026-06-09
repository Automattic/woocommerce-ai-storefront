<?php
/**
 * Tests for `WC_AI_Storefront_JsonLd::output_archive_itemlist_jsonld()`.
 *
 * Verifies that the archive ItemList block is:
 *  - emitted on shop / category / tag / search pages when syndication is on.
 *  - skipped when the plugin is disabled.
 *  - skipped when the page context is not an archive (single product, homepage).
 *  - served from the transient cache on the second call.
 *  - empty when no syndicated products are found.
 *  - structurally correct (ItemList → ListItem → Product).
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdArchiveItemListTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->jsonld = new WC_AI_Storefront_JsonLd();

		// Plugin enabled by default; tests that exercise the disabled path
		// override WC_AI_Storefront::$test_settings directly.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		// Stub common WP/WC functions required by the method under test.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 0 ); // page 1
		Functions\when( 'get_option' )->justReturn( 12 ); // posts_per_page
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'wc_get_products' )->justReturn( [] );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		// Default: none of the archive conditionals fire.
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product_category' )->justReturn( false );
		Functions\when( 'is_product_tag' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_woocommerce' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helper: capture output from output_archive_itemlist_jsonld().
	// -------------------------------------------------------------------------

	private function capture(): string {
		ob_start();
		$this->jsonld->output_archive_itemlist_jsonld();
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Gating: plugin disabled / wrong page context.
	// -------------------------------------------------------------------------

	public function test_skips_when_plugin_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		$this->assertSame( '', $this->capture() );
	}

	public function test_skips_on_non_archive_page(): void {
		// All conditionals already return false in setUp().
		$this->assertSame( '', $this->capture() );
	}

	public function test_skips_on_front_page_even_if_is_shop_true(): void {
		// The shop page doubles as the homepage for some themes.
		// In that case is_shop() === true AND is_front_page() === true.
		// output_store_jsonld() covers the front page; archive block must not fire.
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( true );
		$this->assertSame( '', $this->capture() );
	}

	// -------------------------------------------------------------------------
	// Empty product list: no tag emitted even when on a valid page.
	// -------------------------------------------------------------------------

	public function test_no_output_when_product_list_is_empty(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		// wc_get_products() already returns [] from setUp().
		$this->assertSame( '', $this->capture() );
	}

	public function test_no_output_when_all_products_are_not_syndicated(): void {
		Functions\when( 'is_shop' )->justReturn( true );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 99 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Widget' );

		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		// 'selected' mode with no selected_products → every product is out-of-scope.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [],
		];

		$this->assertSame( '', $this->capture() );
	}

	// -------------------------------------------------------------------------
	// Happy path: correct ItemList structure on each page type.
	// -------------------------------------------------------------------------

	private function make_product( int $id, string $name, string $price, bool $in_stock ): object {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_name' )->andReturn( $name );
		$product->shouldReceive( 'get_price' )->andReturn( $price );
		$product->shouldReceive( 'is_in_stock' )->andReturn( $in_stock );
		$product->shouldReceive( 'get_image_id' )->andReturn( 0 ); // no image
		$product->shouldReceive( 'get_sku' )->andReturn( '' ); // no SKU
		return $product;
	}

	private function enable_shop_page(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );
		// 'all' mode → every published product is syndicated.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];
		// shop page: count query returns same products as page query in tests.
		// wc_get_products already stubs to [] by default; individual tests
		// override it — the count call returns ids (same stub, returns []).
	}

	public function test_itemlist_emitted_on_shop_page(): void {
		$this->enable_shop_page();

		$product = $this->make_product( 1, 'Hoodie', '49.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$this->assertStringContainsString( '<script type="application/ld+json">', $output );

		$data = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);

		$this->assertSame( 'https://schema.org', $data['@context'] );
		$this->assertSame( 'ItemList', $data['@type'] );
		$this->assertCount( 1, $data['itemListElement'] );
		// numberOfItems = total across all pages; stub returns 1 product for
		// both the count query and the page query, so total = 1.
		$this->assertSame( 1, $data['numberOfItems'] );
		$this->assertSame( 'Test Store', $data['name'] );

		$item = $data['itemListElement'][0];
		$this->assertSame( 'ListItem', $item['@type'] );
		$this->assertSame( 1, $item['position'] );
		$this->assertSame( 'Hoodie', $item['name'] );

		$stub = $item['item'];
		$this->assertSame( 'Product', $stub['@type'] );
		$this->assertSame( 'Hoodie', $stub['name'] );
		$this->assertSame( '49.00', $stub['offers']['price'] );
		$this->assertSame( 'USD', $stub['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $stub['offers']['availability'] );
	}

	public function test_itemlist_emitted_on_category_page(): void {
		$term           = new stdClass();
		$term->term_id  = 7;
		$term->slug     = 'hoodies';
		$term->name     = 'Hoodies';
		$term->count    = 42; // total products in category (stored in term row).

		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/product-category/hoodies/' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		$product = $this->make_product( 2, 'Zip Hoodie', '59.00', false );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$this->assertStringContainsString( 'ItemList', $output );

		$data = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);
		$this->assertSame( 'https://schema.org/OutOfStock', $data['itemListElement'][0]['item']['offers']['availability'] );
		// numberOfItems must be the term total (42), not just this page's count (1).
		$this->assertSame( 42, $data['numberOfItems'] );
		$this->assertSame( 'Hoodies', $data['name'] );
		$this->assertSame( 'https://example.com/product-category/hoodies/', $data['url'] );
	}

	public function test_itemlist_emitted_on_search_page(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'is_woocommerce' )->justReturn( true );
		Functions\when( 'get_search_query' )->justReturn( 'hoodie' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		$product = $this->make_product( 3, 'Classic Hoodie', '39.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);
		$this->assertSame( 'ItemList', $data['@type'] );
		$this->assertSame( 'hoodie', $data['name'] ); // list name = search query
		$this->assertStringContainsString( 'hoodie', $data['url'] );
		// count query + page query both hit stub returning [$product] → total = 1.
		$this->assertSame( 1, $data['numberOfItems'] );
	}

	// -------------------------------------------------------------------------
	// Caching: cache hit returns stored data without re-querying.
	// -------------------------------------------------------------------------

	public function test_cache_hit_returns_stored_data(): void {
		$cached = [
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'numberOfItems'   => 1,
			'itemListElement' => [
				[
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => 'Cached Hoodie',
					'url'      => 'https://example.com/?p=5',
					'item'     => [ '@type' => 'Product', 'name' => 'Cached Hoodie', 'url' => 'https://example.com/?p=5' ],
				],
			],
		];

		Functions\when( 'is_shop' )->justReturn( true );
		// Override setUp()'s get_transient stub: return cached data.
		Functions\when( 'get_transient' )->justReturn( $cached );

		// wc_get_products should never be called on cache hit.
		Functions\expect( 'wc_get_products' )->never();

		$output = $this->capture();
		$this->assertStringContainsString( 'Cached Hoodie', $output );
	}

	// -------------------------------------------------------------------------
	// Position counter advances across multiple products.
	// -------------------------------------------------------------------------

	public function test_positions_increment_for_each_product(): void {
		$this->enable_shop_page();

		$p1 = $this->make_product( 10, 'Alpha', '10.00', true );
		$p2 = $this->make_product( 11, 'Beta', '20.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $p1, $p2 ] );

		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);

		$this->assertSame( 1, $data['itemListElement'][0]['position'] );
		$this->assertSame( 2, $data['itemListElement'][1]['position'] );
	}

	// -------------------------------------------------------------------------
	// SKU and image included when present.
	// -------------------------------------------------------------------------

	public function test_sku_and_image_included_when_present(): void {
		$this->enable_shop_page();

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 20 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Tee' );
		$product->shouldReceive( 'get_price' )->andReturn( '25.00' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_image_id' )->andReturn( 77 );
		$product->shouldReceive( 'get_sku' )->andReturn( 'TEE-001' );

		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/tee.jpg' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);

		$stub = $data['itemListElement'][0]['item'];
		$this->assertSame( 'TEE-001', $stub['sku'] );
		$this->assertSame( 'https://example.com/tee.jpg', $stub['image'] );
	}
}
