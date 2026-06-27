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
 *  - structurally correct (ItemList → summary-page ListItem entries).
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
		Functions\when( 'wc_get_products' )->justReturn( [] );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/?p={$id}" );

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
	// Helper: decode the JSON-LD script tag output.
	// -------------------------------------------------------------------------

	private function decode_output( string $output ): array {
		return (array) json_decode(
			trim( substr( $output, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' . "\n" ) ) ),
			true
		);
	}

	// -------------------------------------------------------------------------
	// Helper: make a minimal WC_Product mock.
	// -------------------------------------------------------------------------

	private function make_product( int $id, string $name ): object {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_name' )->andReturn( $name );
		return $product;
	}

	// -------------------------------------------------------------------------
	// Helper: configure a shop page context in 'all' mode.
	// -------------------------------------------------------------------------

	private function enable_shop_page(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];
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
		// get product names + links, not just navigational data.
		$this->enable_shop_page();
		Functions\when( 'is_front_page' )->justReturn( true );

		$product = $this->make_product( 1, 'Hoodie' );
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

		$product = $this->make_product( 1, 'Hoodie' );
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

		$product = $this->make_product( 99, 'Widget' );
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

		$product = $this->make_product( 80, 'Bad Encoding Hoodie' );
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

		$kept    = $this->make_product( 50, 'Kept Hoodie' );
		$dropped = $this->make_product( 99, 'Dropped Hoodie' );
		Functions\when( 'wc_get_products' )->justReturn( [ $kept, $dropped ] );

		// 'selected' mode: only product 50 is in scope; 99 is filtered out.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 50 ],
		];

		$output = $this->capture();
		$data   = $this->decode_output( $output );

		// Only the syndicated product appears, at position 1.
		$this->assertCount( 1, $data['itemListElement'] );
		$this->assertSame( 'Kept Hoodie', $data['itemListElement'][0]['name'] );
		$this->assertSame( 1, $data['itemListElement'][0]['position'] );
		// The inflated total must NOT be published.
		$this->assertArrayNotHasKey( 'numberOfItems', $data );
	}

	public function test_numberOfItems_present_when_selection_mode_is_all(): void {
		// Sanity counterpart: in 'all' mode every product is syndicated, so the
		// catalog-wide count is accurate and numberOfItems IS emitted.
		$this->enable_shop_page();

		$product = $this->make_product( 60, 'All-Mode Hoodie' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$data   = $this->decode_output( $output );

		$this->assertArrayHasKey( 'numberOfItems', $data );
		$this->assertSame( 1, $data['numberOfItems'] );
	}

	// -------------------------------------------------------------------------
	// Happy path: correct ItemList / summary-pointer structure on each page type.
	// -------------------------------------------------------------------------

	public function test_shop_itemlist_entries_are_summary_pointers(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => "https://example.com/product/{$id}/" );
		Functions\when( 'wc_get_products' )->justReturn( [ $this->make_product( 101, 'Field Boot' ) ] );

		$output = $this->capture();
		$data   = $this->decode_output( $output );
		$entry  = $data['itemListElement'][0];

		$this->assertSame( 'ListItem', $entry['@type'] );
		$this->assertSame( 1, $entry['position'] );
		$this->assertSame( 'Field Boot', $entry['name'] );
		$this->assertSame( 'https://example.com/product/101/', $entry['url'] );
		$this->assertArrayNotHasKey( 'item', $entry );
		$this->assertArrayNotHasKey( 'offers', $entry );
		// Wrapper unchanged:
		$this->assertSame( 'ItemList', $data['@type'] );
		$this->assertArrayHasKey( 'name', $data );
	}

	public function test_product_without_permalink_is_skipped(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( '' ); // unresolvable
		Functions\when( 'wc_get_products' )->justReturn( [ $this->make_product( 102, 'No Link' ) ] );
		$this->assertSame( '', $this->capture() ); // empty $items → no ItemList
	}

	public function test_itemlist_emitted_on_shop_page(): void {
		$this->enable_shop_page();

		$product = $this->make_product( 1, 'Hoodie' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$this->assertStringContainsString( '<script type="application/ld+json">', $output );

		$data = $this->decode_output( $output );

		$this->assertSame( 'https://schema.org', $data['@context'] );
		$this->assertSame( 'ItemList', $data['@type'] );
		$this->assertCount( 1, $data['itemListElement'] );
		// numberOfItems = total across all pages; stub returns 1 product for
		// both the count query and the page query, so total = 1.
		$this->assertSame( 1, $data['numberOfItems'] );
		$this->assertSame( 'Test Store', $data['name'] );

		$entry = $data['itemListElement'][0];
		$this->assertSame( 'ListItem', $entry['@type'] );
		$this->assertSame( 1, $entry['position'] );
		// Summary-page pointer shape: name + url on the ListItem, no nested item.
		$this->assertSame( 'Hoodie', $entry['name'] );
		$this->assertSame( 'https://example.com/?p=1', $entry['url'] );
		$this->assertArrayNotHasKey( 'item', $entry );
		$this->assertArrayNotHasKey( 'offers', $entry );
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
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		$product = $this->make_product( 2, 'Zip Hoodie' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$this->assertStringContainsString( 'ItemList', $output );

		$data = $this->decode_output( $output );
		// Summary-pointer entry: no nested item or offers.
		$entry = $data['itemListElement'][0];
		$this->assertSame( 'ListItem', $entry['@type'] );
		$this->assertSame( 'Zip Hoodie', $entry['name'] );
		$this->assertSame( 'https://example.com/?p=2', $entry['url'] );
		$this->assertArrayNotHasKey( 'item', $entry );
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
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		$product = $this->make_product( 3, 'Classic Hoodie' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$data   = $this->decode_output( $output );
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

		$product = $this->make_product( 7, 'Search Hoodie' );
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

		$product = $this->make_product( 4, 'Should Not Appear' );
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
					'name'     => 'Cached Hoodie',
					'url'      => 'https://example.com/?p=5',
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

		$p1 = $this->make_product( 10, 'Alpha' );
		$p2 = $this->make_product( 11, 'Beta' );
		Functions\when( 'wc_get_products' )->justReturn( [ $p1, $p2 ] );

		$output = $this->capture();
		$data   = $this->decode_output( $output );

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
			$product = $this->make_product( 30, 'Found Hoodie' );
			Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

			$output = $this->capture();
			$data   = $this->decode_output( $output );

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

	public function test_itemlist_lists_main_query_rendered_products(): void {
		// The visible page's products come from WP's main query (e.g. a block
		// theme's Product Collection block rendering 12), which can differ from
		// the posts_per_page option. The ItemList must follow the main query's
		// posts, not a separate posts_per_page query (#559).
		$this->enable_shop_page();
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => "https://example.com/product/{$id}/"
		);

		$rendered_a = $this->make_product( 201, 'Rendered A' );
		$rendered_b = $this->make_product( 202, 'Rendered B' );
		$post_a     = (object) array( 'ID' => 201 );
		$post_b     = (object) array( 'ID' => 202 );

		$mq              = Mockery::mock();
		$mq->posts       = array( $post_a, $post_b );
		$mq->found_posts = 38;
		$mq->shouldReceive( 'get' )->with( 'posts_per_page' )->andReturn( 12 );

		Functions\when( 'wc_get_product' )->alias(
			static fn( $post ) => 201 === $post->ID ? $rendered_a : ( 202 === $post->ID ? $rendered_b : false )
		);
		// A separate posts_per_page query would surface this instead - it must not.
		Functions\when( 'wc_get_products' )->justReturn( array( $this->make_product( 999, 'Should Not Appear' ) ) );

		$prev_query          = $GLOBALS['wp_query'] ?? null;
		$GLOBALS['wp_query'] = $mq;
		try {
			$data = $this->decode_output( $this->capture() );
		} finally {
			if ( null === $prev_query ) {
				unset( $GLOBALS['wp_query'] );
			} else {
				$GLOBALS['wp_query'] = $prev_query;
			}
		}

		$names = array_column( $data['itemListElement'], 'name' );
		$this->assertSame( array( 'Rendered A', 'Rendered B' ), $names );
		$this->assertCount( 2, $data['itemListElement'] );
		$this->assertSame( 38, $data['numberOfItems'] );
	}

	public function test_positions_offset_by_page_on_paged_archive(): void {
		// On page 2 of a 12-per-page shop, the first item's position must be 13,
		// not 1: position = ((paged - 1) * effective_page) + 1. The page number
		// must also thread into the product query and the cache key. Pinning
		// this guards the pagination arithmetic (previously every test used page 1).
		Functions\when( 'is_shop' )->justReturn( true );
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
			function ( $args ) use ( &$query_page ) {
				if ( isset( $args['page'] ) ) {
					$query_page = $args['page'];
				}
				return [ $this->make_product( 40, 'Page2 Hoodie' ) ];
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key ) use ( &$written_key ) {
				$written_key = $key;
				return true;
			}
		);

		$output = $this->capture();
		$data   = $this->decode_output( $output );

		$this->assertSame( 13, $data['itemListElement'][0]['position'] );
		$this->assertSame( 2, $query_page, 'page number must thread into the product query' );
		$this->assertStringEndsWith( '_2', (string) $written_key, 'cache key must carry the page number' );
	}

	// -------------------------------------------------------------------------
	// Tag page: symmetric twin of the category-page test.
	// -------------------------------------------------------------------------

	public function test_itemlist_emitted_on_product_tag_page(): void {
		$term          = new stdClass();
		$term->term_id = 9;
		$term->slug    = 'sale';
		$term->name    = 'Sale';
		$term->count   = 18; // total products with this tag (stored in term row).

		Functions\when( 'is_product_tag' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/product-tag/sale/' );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		$product = $this->make_product( 5, 'Sale Hoodie' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$output = $this->capture();
		$this->assertStringContainsString( 'ItemList', $output );

		$data = $this->decode_output( $output );
		// Summary-pointer entry: no nested item or offers.
		$entry = $data['itemListElement'][0];
		$this->assertSame( 'ListItem', $entry['@type'] );
		$this->assertSame( 'Sale Hoodie', $entry['name'] );
		$this->assertSame( 'https://example.com/?p=5', $entry['url'] );
		$this->assertArrayNotHasKey( 'item', $entry );
		// numberOfItems must be the term total (18), not just this page's count (1).
		$this->assertSame( 18, $data['numberOfItems'] );
		$this->assertSame( 'Sale', $data['name'] );
		$this->assertSame( 'https://example.com/product-tag/sale/', $data['url'] );
	}

	// -------------------------------------------------------------------------
	// Cache-hit path: encode failure on the cached array suppresses output.
	// -------------------------------------------------------------------------

	public function test_no_output_when_cache_hit_json_encoding_fails(): void {
		// When get_transient() returns a valid cached array but wp_json_encode()
		// subsequently returns false (e.g. the cached data contains malformed
		// UTF-8), the cache-hit path must suppress the block entirely rather than
		// emit an empty, invalid <script type="application/ld+json"></script> tag.
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
				],
			],
		];

		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( $cached );
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$this->assertSame( '', $this->capture() );
	}

	// -------------------------------------------------------------------------
	// Per-product skip: empty name is dropped (symmetric to empty-url test).
	// -------------------------------------------------------------------------

	public function test_product_without_name_is_skipped(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/nameless/' );
		Functions\when( 'wc_get_products' )->justReturn( [ $this->make_product( 103, '' ) ] );
		$this->assertSame( '', $this->capture() ); // empty $items → no ItemList
	}

	public function test_positions_contiguous_when_a_product_is_filtered_out(): void {
		// Mixed syndication: 3 queried products, the middle one out of scope.
		// The two survivors must get contiguous positions 1 and 2 (not 1 and 3) —
		// i.e. position advances per RENDERED item, not per queried product.
		Functions\when( 'is_shop' )->justReturn( true );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 1, 3 ], // product 2 is filtered out.
		];

		$p1 = $this->make_product( 1, 'First' );
		$p2 = $this->make_product( 2, 'Filtered Out' );
		$p3 = $this->make_product( 3, 'Third' );
		Functions\when( 'wc_get_products' )->justReturn( [ $p1, $p2, $p3 ] );

		$output = $this->capture();
		$data   = $this->decode_output( $output );

		$this->assertCount( 2, $data['itemListElement'] );
		$this->assertSame( 'First', $data['itemListElement'][0]['name'] );
		$this->assertSame( 1, $data['itemListElement'][0]['position'] );
		$this->assertSame( 'Third', $data['itemListElement'][1]['name'] );
		$this->assertSame( 2, $data['itemListElement'][1]['position'] );
	}
}
