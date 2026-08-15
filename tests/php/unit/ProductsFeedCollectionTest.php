<?php
/**
 * Tests for the v2 per-collection endpoint /collections/{handle}/products.json
 * in WC_AI_Storefront_Products_Feed.
 *
 * Covers the rewrite/query-var/canonical wiring, the serve gate/OPTIONS/
 * early-return branches, and build_collection_products_json()'s body shape,
 * the unknown-category → empty-200 rule, and the catalog-visibility leak
 * guard. The single most important test here is the PRECEDENCE regression:
 * /collections/all/products.json must keep resolving to the BULK feed, never
 * the per-collection handler.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductsFeedCollectionTest extends \PHPUnit\Framework\TestCase {

	private WC_AI_Storefront_Products_Feed $feed;

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
		$this->feed = new WC_AI_Storefront_Products_Feed();

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'all',
		];

		$_GET = [];
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $method  Private method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, ...$args ) {
		$reflection = new ReflectionClass( $this->feed );
		$m          = $reflection->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $this->feed, ...$args );
	}

	/**
	 * Capture the rewrite rules add_rewrite_rules() registers.
	 *
	 * @return array<string, array{query:string, after:string}>
	 */
	private function captured_rules(): array {
		$rules = [];
		Functions\when( 'add_rewrite_rule' )->alias(
			static function ( $regex, $query, $after ) use ( &$rules ) {
				$rules[ $regex ] = [ 'query' => $query, 'after' => $after ];
			}
		);
		$this->feed->add_rewrite_rules();
		return $rules;
	}

	// ------------------------------------------------------------------
	// Wiring
	// ------------------------------------------------------------------

	public function test_query_var_collection_constant_defined(): void {
		$this->assertSame( 'wc_ai_storefront_collection_json', WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTION );
	}

	public function test_collection_rewrite_rule_registered_with_lookahead(): void {
		$rules = $this->captured_rules();

		$this->assertArrayHasKey( '^collections/(?!all/)([^/]+)/products\.json$', $rules );
		$rule = $rules['^collections/(?!all/)([^/]+)/products\.json$'];
		$this->assertSame( 'top', $rule['after'] );
		$this->assertStringContainsString(
			WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTION . '=$matches[1]',
			$rule['query']
		);
	}

	public function test_collection_query_var_registered(): void {
		$vars = $this->feed->add_query_vars( [] );
		$this->assertContains( WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTION, $vars );
	}

	public function test_canonical_redirect_suppressed_for_collection_query_var(): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => WC_AI_Storefront_Products_Feed::QUERY_VAR_COLLECTION === $var ? 'hoodies' : 0
		);

		$this->assertFalse(
			$this->feed->suppress_canonical_redirect( 'https://example.com/collections/hoodies/products.json/' )
		);
	}

	// ------------------------------------------------------------------
	// PRECEDENCE: /collections/all/products.json must stay the BULK feed
	// ------------------------------------------------------------------

	public function test_collections_all_precedence_bulk_wins(): void {
		$rules = $this->captured_rules();

		// The literal bulk-alias rule still maps to the BULK query var.
		$this->assertArrayHasKey( '^collections/all/products\.json$', $rules );
		$this->assertStringContainsString(
			WC_AI_Storefront_Products_Feed::QUERY_VAR . '=1',
			$rules['^collections/all/products\.json$']['query']
		);

		// Behavioural proof: the registered per-collection regex MUST NOT match
		// `collections/all/products.json` (the lookahead excludes it), so it
		// can never steal the bulk path — regardless of WP rule ordering.
		$regex   = '^collections/(?!all/)([^/]+)/products\.json$';
		$this->assertArrayHasKey( $regex, $rules, 'per-collection regex must be registered verbatim' );
		$pattern = '#' . $regex . '#';

		$this->assertSame( 0, preg_match( $pattern, 'collections/all/products.json' ), '/collections/all must NOT match per-collection rule' );

		// A real category slug matches and captures cleanly.
		$this->assertSame( 1, preg_match( $pattern, 'collections/hoodies/products.json', $m ) );
		$this->assertSame( 'hoodies', $m[1] );

		// A slug that merely STARTS with "all" is unaffected by the lookahead.
		$this->assertSame( 1, preg_match( $pattern, 'collections/all-weather/products.json', $m2 ) );
		$this->assertSame( 'all-weather', $m2[1] );
	}

	// ------------------------------------------------------------------
	// serve_collection_products() — gate/OPTIONS/early-return
	// ------------------------------------------------------------------

	public function test_serve_collection_returns_early_when_query_var_empty(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );

		ob_start();
		$this->feed->serve_collection_products();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_collection_returns_404_when_feed_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 'hoodies' );
		WC_AI_Storefront::$test_settings['products_json_enabled'] = 'no';
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_collection_products();
			$this->fail( 'Expected serve_collection_products() to 404 when the feed toggle is off.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:404', $e->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_collection_returns_204_on_options_request(): void {
		Functions\when( 'get_query_var' )->justReturn( 'hoodies' );
		$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_collection_products();
			$this->fail( 'Expected serve_collection_products() to 204 on an OPTIONS preflight.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:204', $e->getMessage() );
		} finally {
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	}

	// ------------------------------------------------------------------
	// build_collection_products_json() — shape, unknown→empty, leak guard
	// ------------------------------------------------------------------

	public function test_build_collection_unknown_category_returns_empty_products(): void {
		// Unknown slug → 200 with empty products (NOT 404); never leaks which
		// category slugs exist.
		Functions\when( 'get_term_by' )->justReturn( false );

		$json    = $this->invoke_private( 'build_collection_products_json', 'ghost', 30, 1 );
		$decoded = json_decode( (string) $json, true );

		$this->assertSame( [ 'products' => [] ], $decoded );
	}

	public function test_build_collection_emits_syndicated_products(): void {
		Functions\when( 'get_term_by' )->justReturn( $this->term( 'hoodies' ) );
		Functions\when( 'wc_get_products' )->justReturn( [ $this->simple_product( 5, 'day-hoodie' ) ] );

		$json    = $this->invoke_private( 'build_collection_products_json', 'hoodies', 30, 1 );
		$decoded = json_decode( (string) $json, true );

		$this->assertArrayHasKey( 'products', $decoded );
		$this->assertCount( 1, $decoded['products'] );
		$this->assertSame( 'day-hoodie', $decoded['products'][0]['handle'] );
	}

	public function test_build_collection_query_restricts_to_catalog_visibility_and_category(): void {
		// LEAK GUARD: the query MUST carry visibility => 'catalog', status =>
		// 'publish', and category => [slug] so a hidden product assigned to a
		// visible category never appears via the collection path.
		Functions\when( 'get_term_by' )->justReturn( $this->term( 'hoodies' ) );
		$captured = null;
		Functions\when( 'wc_get_products' )->alias(
			static function ( $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			}
		);

		$this->invoke_private( 'build_collection_products_json', 'hoodies', 24, 3 );

		$this->assertIsArray( $captured );
		$this->assertSame( 'catalog', $captured['visibility'] ?? null );
		$this->assertSame( 'publish', $captured['status'] ?? null );
		$this->assertSame( [ 'hoodies' ], $captured['category'] ?? null );
		$this->assertSame( 24, $captured['limit'] ?? null );
		$this->assertSame( 3, $captured['page'] ?? null );
	}

	public function test_build_collection_excludes_unsyndicated_products(): void {
		// A category that contains a product the merchant has NOT syndicated
		// must omit it ('selected' mode without that id).
		Functions\when( 'get_term_by' )->justReturn( $this->term( 'hoodies' ) );
		Functions\when( 'wc_get_products' )->justReturn(
			[ $this->simple_product( 1, 'kept' ), $this->simple_product( 2, 'dropped' ) ]
		);
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 1 ],
		];

		$json    = $this->invoke_private( 'build_collection_products_json', 'hoodies', 30, 1 );
		$decoded = json_decode( (string) $json, true );

		$this->assertCount( 1, $decoded['products'] );
		$this->assertSame( 'kept', $decoded['products'][0]['handle'] );
	}

	/**
	 * A product_cat WP_Term with the given slug.
	 *
	 * @param string $slug Term slug.
	 * @return \WP_Term
	 */
	private function term( string $slug ): \WP_Term {
		$t          = new \WP_Term();
		$t->term_id = 99;
		$t->slug    = $slug;
		$t->name    = ucfirst( $slug );
		return $t;
	}

	/**
	 * @param int    $id     Product id.
	 * @param string $handle Slug.
	 * @return \Mockery\MockInterface
	 */
	private function simple_product( int $id, string $handle ) {
		$p = \Mockery::mock( 'WC_Product' );
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_name' )->andReturn( 'Product ' . $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $handle );
		$p->shouldReceive( 'get_description' )->andReturn( '' );
		$p->shouldReceive( 'get_category_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_tag_ids' )->andReturn( [] );
		$p->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_gallery_image_ids' )->andReturn( [] );
		$p->shouldReceive( 'is_type' )->with( 'variable' )->andReturn( false );
		$p->shouldReceive( 'get_sku' )->andReturn( '' );
		$p->shouldReceive( 'get_price' )->andReturn( '10' );
		$p->shouldReceive( 'get_regular_price' )->andReturn( '10' );
		$p->shouldReceive( 'is_on_sale' )->andReturn( false );
		$p->shouldReceive( 'is_in_stock' )->andReturn( true );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'needs_shipping' )->andReturn( true );

		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		return $p;
	}
}
