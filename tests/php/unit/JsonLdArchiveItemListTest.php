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
		// Default: products carry no brand term (#507). The brand test
		// overrides this to return a WP_Term-shaped object.
		Functions\when( 'get_the_terms' )->justReturn( false );
		// Reviews enabled by default (#510); the disabled-reviews test
		// overrides to false. Products default to 0 ratings, so no
		// aggregateRating emits unless a test sets a rating count.
		Functions\when( 'wc_review_ratings_enabled' )->justReturn( true );

		// Default: none of the archive conditionals fire.
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_product_category' )->justReturn( false );
		Functions\when( 'is_product_tag' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_woocommerce' )->justReturn( false );
		// Stubs needed by description + hasMerchantReturnPolicy enrichment (#518).
		// do_shortcode: pass-through (description content has no shortcodes in tests).
		Functions\when( 'do_shortcode' )->returnArg( 1 );
		// wp_get_post_parent_id: default 0 (non-variation); policy tests override.
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		// wc_get_base_location: default US; policy tests with blank country override.
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'US' ] );
		// get_post_status / get_post_type: needed by resolve_merchant_return_link
		// when a page_id is configured (Option B). Default to 'publish'/'page' so
		// the helpers that stub page_id don't also need to re-stub these.
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		// get_post_meta: needed by WC_AI_Storefront_Product_Meta_Box::is_final_sale()
		// which build_return_policy_block() calls for per-product overrides.
		// Default '' → not final-sale; the test scenarios here are all regular products.
		Functions\when( 'get_post_meta' )->justReturn( '' );
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

	public function test_itemlist_emitted_on_front_page_shop(): void {
		// When the shop archive IS the site's front page (is_shop() === true AND
		// is_front_page() === true), the product ItemList must still emit — the
		// front page then carries BOTH the OnlineBusiness block (from
		// output_store_jsonld()) AND this ItemList, so agents fetching the root
		// get products + prices, not just navigational data.
		$this->enable_shop_page();
		Functions\when( 'is_front_page' )->justReturn( true );

		$product = $this->make_product( 1, 'Hoodie', '49.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$this->assertStringContainsString( '"@type":"ItemList"', $this->capture() );
	}

	public function test_skips_on_static_front_page(): void {
		// A static (non-shop) front page: is_front_page() === true, but the page
		// is NOT the shop archive — every archive predicate (is_shop / category /
		// tag / product-search) is false. The ItemList must NOT emit: the gate
		// keys on the archive predicates alone, never on is_front_page(). This is
		// the symmetric inverse of test_itemlist_emitted_on_front_page_shop and
		// guards against anyone re-introducing an is_front_page()-based positive
		// branch (the exact shape of the bug this fix removed). A product is made
		// available so the assertion pins the GATE, not an empty product list.
		Functions\when( 'is_front_page' )->justReturn( true );
		// All archive predicates remain false (setUp default).

		$product = $this->make_product( 1, 'Hoodie', '49.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

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

	public function test_no_output_when_json_encoding_fails(): void {
		// wp_json_encode() returns false on failure (e.g. malformed UTF-8 in a
		// product name). The method must suppress the block entirely rather than
		// emit an empty, invalid <script type="application/ld+json"></script>
		// island — matching output_website_jsonld()'s guard.
		$this->enable_shop_page();

		$product = $this->make_product( 80, 'Bad Encoding Hoodie', '49.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$this->assertSame( '', $this->capture() );
	}

	public function test_numberOfItems_omitted_when_selection_mode_is_not_all(): void {
		// In 'selected'/'by_taxonomy' mode the itemListElement is a syndication-
		// filtered subset, but the cheap total counts (term->count, found_posts,
		// wc_get_products) count ALL published products. Publishing that inflated
		// total would mislead agents paginating on numberOfItems and disclose the
		// count of non-syndicated products. There's no cheap correct count for a
		// filtered set, so numberOfItems is omitted entirely (an optional field).
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );

		$kept    = $this->make_product( 50, 'Kept Hoodie', '49.00', true );
		$dropped = $this->make_product( 99, 'Dropped Hoodie', '59.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $kept, $dropped ] );

		// 'selected' mode: only product 50 is in scope; 99 is filtered out.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 50 ],
		];

		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);

		// Only the syndicated product appears, at position 1. The product name
		// lives on the nested item (not the ListItem) per the all-in-one
		// carousel shape.
		$this->assertCount( 1, $data['itemListElement'] );
		$this->assertSame( 'Kept Hoodie', $data['itemListElement'][0]['item']['name'] );
		$this->assertSame( 1, $data['itemListElement'][0]['position'] );
		// The inflated total must NOT be published.
		$this->assertArrayNotHasKey( 'numberOfItems', $data );
	}

	public function test_numberOfItems_present_when_selection_mode_is_all(): void {
		// Sanity counterpart: in 'all' mode every product is syndicated, so the
		// catalog-wide count is accurate and numberOfItems IS emitted.
		$this->enable_shop_page();

		$product = $this->make_product( 60, 'All-Mode Hoodie', '49.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);

		$this->assertArrayHasKey( 'numberOfItems', $data );
		$this->assertSame( 1, $data['numberOfItems'] );
	}

	// -------------------------------------------------------------------------
	// Happy path: correct ItemList structure on each page type.
	// -------------------------------------------------------------------------

	private function make_product(
		int $id,
		string $name,
		string $price,
		bool $in_stock,
		int $rating_count = 0,
		string $average_rating = '0',
		int $review_count = 0,
		string $short = '',
		string $desc = ''
	): object {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_name' )->andReturn( $name );
		$product->shouldReceive( 'get_price' )->andReturn( $price );
		$product->shouldReceive( 'is_in_stock' )->andReturn( $in_stock );
		$product->shouldReceive( 'get_image_id' )->andReturn( 0 ); // no image
		$product->shouldReceive( 'get_sku' )->andReturn( '' ); // no SKU
		// Ratings (#510): default 0 → no aggregateRating; the rating test
		// passes a non-zero count + average + review count.
		$product->shouldReceive( 'get_rating_count' )->andReturn( $rating_count );
		$product->shouldReceive( 'get_average_rating' )->andReturn( $average_rating );
		$product->shouldReceive( 'get_review_count' )->andReturn( $review_count );
		// Description (#518): default empty → no description emitted; tests that
		// exercise description pass a non-empty $short or $desc.
		$product->shouldReceive( 'get_short_description' )->andReturn( $short );
		$product->shouldReceive( 'get_description' )->andReturn( $desc );
		return $product;
	}

	/**
	 * A real WC_Product subclass exposing get_global_unique_id() (absent on
	 * older WooCommerce releases), so `method_exists()` resolves true —
	 * Mockery's `shouldReceive` does not make `method_exists()` true. Mirrors
	 * StoreApiExtensionTest's approach. Rating getters return zero so the
	 * stub loop's aggregateRating gate (#510) stays false and nothing is
	 * emitted.
	 */
	private function make_gtin_product( string $gtin ): \WC_Product {
		$product            = new class() extends \WC_Product {
			public string $test_gtin = '';
			public function get_global_unique_id() {
				return $this->test_gtin;
			}
			public function get_rating_count(): int {
				return 0;
			}
			public function get_average_rating(): string {
				return '0';
			}
			public function get_review_count(): int {
				return 0;
			}
		};
		$product->test_gtin = $gtin;
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
		// All-in-one carousel shape: the ListItem carries ONLY position + the
		// nested item. A ListItem-level `name`/`url` would mix Google's summary
		// and all-in-one carousel patterns and trigger the "Unnamed item"
		// critical error in the Rich Results Test (#499).
		$this->assertArrayNotHasKey( 'name', $item );
		$this->assertArrayNotHasKey( 'url', $item );

		$stub = $item['item'];
		$this->assertSame( 'Product', $stub['@type'] );
		$this->assertSame( 'Hoodie', $stub['name'] );
		// The product URL moves to the nested item (item.url) — where Google
		// reads it for the all-in-one carousel — not the ListItem level.
		$this->assertSame( 'https://example.com/?p=1', $stub['url'] );
		$this->assertSame( '49.00', $stub['offers']['price'] );
		$this->assertSame( 'USD', $stub['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $stub['offers']['availability'] );
	}

	// -------------------------------------------------------------------------
	// brand + gtin enrichment (#507) — mirror the full product-page markup so
	// the homepage list isn't flagged for missing recommended merchant-listing
	// fields.
	// -------------------------------------------------------------------------

	private function first_stub( array $products ): array {
		Functions\when( 'wc_get_products' )->justReturn( $products );
		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);
		return $data['itemListElement'][0]['item'];
	}

	public function test_stub_includes_brand_when_product_has_brand_term(): void {
		$this->enable_shop_page();
		// First product_brand term, WP_Term-shaped (mirrors WC_Brands).
		Functions\when( 'get_the_terms' )->justReturn( [ (object) [ 'term_id' => 5, 'name' => 'Saltwarp' ] ] );

		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertSame( 'Brand', $stub['brand']['@type'] );
		$this->assertSame( 'Saltwarp', $stub['brand']['name'] );
	}

	public function test_stub_omits_brand_when_no_brand_term(): void {
		$this->enable_shop_page();
		// setUp default: get_the_terms returns false (no brand assigned).
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertArrayNotHasKey( 'brand', $stub );
	}

	public function test_stub_omits_brand_when_term_name_is_empty(): void {
		$this->enable_shop_page();
		// A brand term with an empty name must not emit an empty Brand block
		// (which would be its own Rich Results warning).
		Functions\when( 'get_the_terms' )->justReturn( [ (object) [ 'term_id' => 5, 'name' => '' ] ] );

		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertArrayNotHasKey( 'brand', $stub );
	}

	public function test_stub_includes_gtin_when_valid(): void {
		$this->enable_shop_page();
		// 12-digit value passes WC core's 8/12-14-digit validity check.
		$stub = $this->first_stub( [ $this->make_gtin_product( '012345678905' ) ] );

		$this->assertSame( '012345678905', $stub['gtin'] );
	}

	public function test_stub_normalizes_formatted_gtin_like_wc_core(): void {
		$this->enable_shop_page();
		// WC core's prepare_gtin() strips non-digits before validating; the
		// stub must do the same so a formatted value emits the bare digits,
		// matching the product page.
		$stub = $this->first_stub( [ $this->make_gtin_product( '0-12345-67890-5' ) ] );

		$this->assertSame( '012345678905', $stub['gtin'] );
	}

	public function test_stub_omits_gtin_when_invalid(): void {
		$this->enable_shop_page();
		// '123' is neither 8 nor 12-14 digits → not a valid GTIN, so omitted.
		$stub = $this->first_stub( [ $this->make_gtin_product( '123' ) ] );

		$this->assertArrayNotHasKey( 'gtin', $stub );
	}

	public function test_stub_omits_gtin_on_older_wc_without_the_method(): void {
		$this->enable_shop_page();
		// Older WooCommerce releases have no get_global_unique_id(); the
		// method_exists guard must fail safe and emit no gtin rather than
		// fatal. The Mockery mock of WC_Product (which lacks the method)
		// exercises that path.
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertArrayNotHasKey( 'gtin', $stub );
	}

	// -------------------------------------------------------------------------
	// aggregateRating enrichment (#510) — mirrors the product page only when
	// the product has real reviews. Never fabricated.
	// -------------------------------------------------------------------------

	public function test_stub_includes_aggregate_rating_when_product_has_reviews(): void {
		$this->enable_shop_page();
		// rating_count 12, average 4.50, review_count 8.
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true, 12, '4.50', 8 ) ] );

		$this->assertSame( 'AggregateRating', $stub['aggregateRating']['@type'] );
		$this->assertSame( '4.50', $stub['aggregateRating']['ratingValue'] );
		$this->assertSame( 8, $stub['aggregateRating']['reviewCount'] );
	}

	public function test_stub_omits_aggregate_rating_when_no_reviews(): void {
		$this->enable_shop_page();
		// make_product default: rating_count 0 → no aggregateRating.
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertArrayNotHasKey( 'aggregateRating', $stub );
	}

	public function test_stub_omits_aggregate_rating_when_reviews_disabled(): void {
		$this->enable_shop_page();
		// Ratings exist, but the store has product reviews turned off — match
		// WC core and emit nothing.
		Functions\when( 'wc_review_ratings_enabled' )->justReturn( false );
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true, 12, '4.50', 8 ) ] );

		$this->assertArrayNotHasKey( 'aggregateRating', $stub );
	}

	public function test_stub_omits_aggregate_rating_when_average_is_zero(): void {
		$this->enable_shop_page();
		// Defensive guard (stricter than WC core): a rating count with a zero
		// average must NOT emit an invalid ratingValue:0. Can't happen with
		// real 1-5 ratings, but protects against malformed data.
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true, 3, '0', 3 ) ] );

		$this->assertArrayNotHasKey( 'aggregateRating', $stub );
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

	public function test_itemlist_emitted_on_product_search_page(): void {
		// A product search page is `/?s=foo&post_type=product`. The gate keys on
		// get_query_var('post_type') === 'product' — NOT is_woocommerce(), which
		// WooCommerce defines as is_shop() || is_product_taxonomy() || is_product(),
		// all false on a search page. (A prior gate used is_woocommerce() and so
		// never fired on real search pages; this test pins the working condition.)
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->alias(
			static fn( $key, $default = '' ) => 'post_type' === $key ? 'product' : 0
		);
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

	public function test_search_page_is_not_cached_to_avoid_options_table_flooding(): void {
		// Search-key cardinality is bounded only by the distinct ?s= values an
		// unauthenticated visitor supplies, so caching each one would flood
		// wp_options. Search pages are cheap to recompute; the search branch
		// must neither read nor write the transient cache. (Shop/category/tag
		// keep caching — their key cardinality is bounded by catalog size.)
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->alias(
			static fn( $key, $default = '' ) => 'post_type' === $key ? 'product' : 0
		);
		Functions\when( 'get_search_query' )->justReturn( 'hoodie' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );

		// Spy on transient access: record every key read and written so we can
		// assert the search branch touches neither. (A bare ->never() does not
		// reliably override the setUp() when()-stub in Brain\Monkey, so capture
		// the calls explicitly.)
		$read_keys    = [];
		$written_keys = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$read_keys ) {
				$read_keys[] = $key;
				return false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$written_keys ) {
				$written_keys[] = $key;
				return true;
			}
		);

		$product = $this->make_product( 7, 'Search Hoodie', '39.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		// Still emits the block — it's just computed fresh every time.
		$this->assertStringContainsString( 'ItemList', $this->capture() );
		$this->assertSame( [], $written_keys, 'search page must not write a transient' );
		$this->assertSame( [], $read_keys, 'search page must not read a transient' );
	}

	public function test_itemlist_skipped_on_non_product_search_page(): void {
		// A plain search (no &post_type=product, e.g. a blog-post search) must
		// NOT emit the product ItemList — get_query_var('post_type') is empty.
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->alias(
			static fn( $key, $default = '' ) => 0 // post_type empty, paged 0
		);
		Functions\when( 'get_search_query' )->justReturn( 'hoodie' );

		$product = $this->make_product( 4, 'Should Not Appear', '39.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$this->assertSame( '', $this->capture() );
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

	public function test_numberOfItems_uses_found_posts_on_shop_page(): void {
		// On a real shop page WP's main query is populated, so the total comes
		// from $GLOBALS['wp_query']->found_posts (the middle fallback branch) —
		// not from a redundant full-ID count query. This branch had no coverage.
		$this->enable_shop_page();

		$prev_query                     = $GLOBALS['wp_query'] ?? null;
		$GLOBALS['wp_query']            = new \stdClass();
		$GLOBALS['wp_query']->found_posts = 137;

		try {
			$product = $this->make_product( 30, 'Found Hoodie', '49.00', true );
			Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

			$output = $this->capture();
			$data   = json_decode(
				trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
				true
			);

			// numberOfItems is the populated-query total (137), even though the
			// page itself rendered only one product.
			$this->assertSame( 137, $data['numberOfItems'] );
			$this->assertCount( 1, $data['itemListElement'] );
		} finally {
			if ( null === $prev_query ) {
				unset( $GLOBALS['wp_query'] );
			} else {
				$GLOBALS['wp_query'] = $prev_query;
			}
		}
	}

	public function test_positions_offset_by_page_on_paged_archive(): void {
		// On page 2 of a 12-per-page shop, the first item's position must be 13,
		// not 1: position = ((paged - 1) * effective_page) + 1. The page number
		// must also thread into the product query and the cache key. Pinning
		// this guards the pagination arithmetic (previously every test used page 1).
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );
		Functions\when( 'get_query_var' )->alias(
			static fn( $key, $default = '' ) => 'paged' === $key ? 2 : 0
		);
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		// Capture the page passed to wc_get_products and the cache key written.
		$query_page  = null;
		$written_key = null;
		Functions\when( 'wc_get_products' )->alias(
			static function ( $args ) use ( &$query_page ) {
				if ( isset( $args['page'] ) ) {
					$query_page = $args['page'];
				}
				$p = Mockery::mock( 'WC_Product' );
				$p->shouldReceive( 'get_id' )->andReturn( 40 );
				$p->shouldReceive( 'get_name' )->andReturn( 'Page2 Hoodie' );
				$p->shouldReceive( 'get_price' )->andReturn( '49.00' );
				$p->shouldReceive( 'is_in_stock' )->andReturn( true );
				$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
				$p->shouldReceive( 'get_sku' )->andReturn( '' );
				$p->shouldReceive( 'get_rating_count' )->andReturn( 0 );
				$p->shouldReceive( 'get_average_rating' )->andReturn( '0' );
				$p->shouldReceive( 'get_review_count' )->andReturn( 0 );
				$p->shouldReceive( 'get_short_description' )->andReturn( '' );
				$p->shouldReceive( 'get_description' )->andReturn( '' );
				return [ $p ];
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key ) use ( &$written_key ) {
				$written_key = $key;
				return true;
			}
		);

		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);

		$this->assertSame( 13, $data['itemListElement'][0]['position'] );
		$this->assertSame( 2, $query_page, 'page number must thread into the product query' );
		$this->assertStringEndsWith( '_2', (string) $written_key, 'cache key must carry the page number' );
	}

	public function test_positions_contiguous_when_a_product_is_filtered_out(): void {
		// Mixed syndication: 3 queried products, the middle one out of scope.
		// The two survivors must get contiguous positions 1 and 2 (not 1 and 3) —
		// i.e. position advances per RENDERED item, not per queried product.
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 1, 3 ], // product 2 is filtered out.
		];

		$p1 = $this->make_product( 1, 'First', '10.00', true );
		$p2 = $this->make_product( 2, 'Filtered Out', '20.00', true );
		$p3 = $this->make_product( 3, 'Third', '30.00', true );
		Functions\when( 'wc_get_products' )->justReturn( [ $p1, $p2, $p3 ] );

		$output = $this->capture();
		$data   = json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);

		$this->assertCount( 2, $data['itemListElement'] );
		$this->assertSame( 'First', $data['itemListElement'][0]['item']['name'] );
		$this->assertSame( 1, $data['itemListElement'][0]['position'] );
		$this->assertSame( 'Third', $data['itemListElement'][1]['item']['name'] );
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
		$product->shouldReceive( 'get_rating_count' )->andReturn( 0 );
		$product->shouldReceive( 'get_average_rating' )->andReturn( '0' );
		$product->shouldReceive( 'get_review_count' )->andReturn( 0 );
		$product->shouldReceive( 'get_short_description' )->andReturn( '' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );

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

	// -------------------------------------------------------------------------
	// description enrichment (#518) — mirrors the product-page short/long
	// description so the homepage ItemList isn't flagged for missing fields.
	// -------------------------------------------------------------------------

	public function test_stub_includes_description_from_short_description(): void {
		$this->enable_shop_page();
		// Product with a short description.
		$stub = $this->first_stub(
			[ $this->make_product( 1, 'Hoodie', '49.00', true, 0, '0', 0, 'A cosy zip-up hoodie.' ) ]
		);

		$this->assertArrayHasKey( 'description', $stub );
		$this->assertSame( 'A cosy zip-up hoodie.', $stub['description'] );
	}

	public function test_stub_falls_back_to_long_description_when_short_is_empty(): void {
		$this->enable_shop_page();
		// No short description but a long one: the stub falls back to get_description().
		$stub = $this->first_stub(
			[ $this->make_product( 1, 'Hoodie', '49.00', true, 0, '0', 0, '', 'Full description here.' ) ]
		);

		$this->assertArrayHasKey( 'description', $stub );
		$this->assertSame( 'Full description here.', $stub['description'] );
	}

	public function test_stub_omits_description_when_both_are_empty(): void {
		$this->enable_shop_page();
		// Default make_product: $short = '' and $desc = '' → no description key.
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertArrayNotHasKey( 'description', $stub );
	}

	public function test_stub_strips_html_tags_from_description(): void {
		$this->enable_shop_page();
		// HTML in the short description must be stripped (same as product page).
		$stub = $this->first_stub(
			[ $this->make_product( 1, 'Hoodie', '49.00', true, 0, '0', 0, '<p>Bold <strong>hoodie</strong>.</p>' ) ]
		);

		$this->assertSame( 'Bold hoodie.', $stub['description'] );
	}

	// -------------------------------------------------------------------------
	// hasMerchantReturnPolicy enrichment (#518) — mirrors the product page's
	// offer-level return policy block onto each stub's offer.
	// -------------------------------------------------------------------------

	public function test_stub_offer_includes_return_policy_link_when_configured(): void {
		$this->enable_shop_page();
		// Option B: a published return-policy page configured → merchantReturnLink.
		// get_post_status / get_post_type default to 'publish'/'page' from setUp().
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => [
				'mode'    => 'link',
				'page_id' => 99,
			],
		];
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );

		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertArrayHasKey( 'hasMerchantReturnPolicy', $stub['offers'] );
		$policy = $stub['offers']['hasMerchantReturnPolicy'];
		$this->assertSame( 'MerchantReturnPolicy', $policy['@type'] );
		$this->assertSame( 'https://example.com/?p=99', $policy['merchantReturnLink'] );
		// Option B is link-only — no inline detail keys.
		$this->assertArrayNotHasKey( 'returnPolicyCategory', $policy );
	}

	public function test_stub_offer_omits_return_policy_when_not_configured(): void {
		$this->enable_shop_page();
		// Default test settings: no return_policy key → unconfigured → no emission.
		$stub = $this->first_stub( [ $this->make_product( 1, 'Hoodie', '49.00', true ) ] );

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $stub['offers'] );
	}
}
