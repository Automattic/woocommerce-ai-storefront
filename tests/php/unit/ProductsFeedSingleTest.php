<?php
/**
 * Tests for the v2 single-product endpoint /products/{handle}.json in
 * WC_AI_Storefront_Products_Feed.
 *
 * Covers:
 *  - rewrite rule + query var + canonical-redirect wiring for the new
 *    QUERY_VAR_PRODUCT.
 *  - serve_single_product() gate (404 disabled/feed-off), OPTIONS 204, and
 *    early-return branches (throw-sentinel on status_header so the
 *    exit()-terminated branches are asserted without reaching real exit()).
 *  - build_single_product_json() body shape (SINGULAR `product` object key),
 *    the handle-miss 404, and the two leak paths (unsyndicated slug 404,
 *    visibility => 'catalog' on the resolve query).
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class ProductsFeedSingleTest extends \PHPUnit\Framework\TestCase {

	private WC_AI_Storefront_Products_Feed $feed;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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

	// ------------------------------------------------------------------
	// Wiring: rewrite rule + query var + canonical suppression
	// ------------------------------------------------------------------

	public function test_query_var_product_constant_defined(): void {
		$this->assertSame( 'wc_ai_storefront_product_json', WC_AI_Storefront_Products_Feed::QUERY_VAR_PRODUCT );
	}

	public function test_single_product_rewrite_rule_registered(): void {
		$rules = [];
		Functions\when( 'add_rewrite_rule' )->alias(
			static function ( $regex, $query, $after ) use ( &$rules ) {
				$rules[ $regex ] = [ 'query' => $query, 'after' => $after ];
			}
		);

		$this->feed->add_rewrite_rules();

		$this->assertArrayHasKey( '^products/([^/]+)\.json$', $rules );
		$this->assertSame( 'top', $rules['^products/([^/]+)\.json$']['after'] );
		$this->assertStringContainsString(
			WC_AI_Storefront_Products_Feed::QUERY_VAR_PRODUCT . '=$matches[1]',
			$rules['^products/([^/]+)\.json$']['query']
		);
		// The bulk /products.json rule must still be present and distinct.
		$this->assertArrayHasKey( '^products\.json$', $rules );
	}

	public function test_single_product_query_var_registered(): void {
		$vars = $this->feed->add_query_vars( [] );
		$this->assertContains( WC_AI_Storefront_Products_Feed::QUERY_VAR_PRODUCT, $vars );
		// Additive: the bulk var is still registered.
		$this->assertContains( WC_AI_Storefront_Products_Feed::QUERY_VAR, $vars );
	}

	public function test_canonical_redirect_suppressed_for_product_query_var(): void {
		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => WC_AI_Storefront_Products_Feed::QUERY_VAR_PRODUCT === $var ? 'day-hoodie' : 0
		);

		$this->assertFalse(
			$this->feed->suppress_canonical_redirect( 'https://example.com/products/day-hoodie.json/' )
		);
	}

	// ------------------------------------------------------------------
	// serve_single_product() — early-return + gate/OPTIONS branches
	// ------------------------------------------------------------------

	public function test_serve_single_returns_early_when_query_var_empty(): void {
		// No handle in the query var → no-op (no output, no exit).
		Functions\when( 'get_query_var' )->justReturn( '' );

		ob_start();
		$this->feed->serve_single_product();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_single_returns_404_when_plugin_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 'day-hoodie' );
		WC_AI_Storefront::$test_settings['enabled'] = 'no';
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_single_product();
			$this->fail( 'Expected serve_single_product() to 404 on a disabled store.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:404', $e->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_single_returns_404_when_feed_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 'day-hoodie' );
		WC_AI_Storefront::$test_settings['products_json_enabled'] = 'no';
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_single_product();
			$this->fail( 'Expected serve_single_product() to 404 when the feed toggle is off.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:404', $e->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_single_returns_204_on_options_request(): void {
		Functions\when( 'get_query_var' )->justReturn( 'day-hoodie' );
		$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_single_product();
			$this->fail( 'Expected serve_single_product() to 204 on an OPTIONS preflight.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:204', $e->getMessage() );
		} finally {
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_single_404s_on_handle_miss_after_headers(): void {
		// The serve-level seam: a slug resolving to nothing reaches the
		// POST-header 404 (status_header(404) after send_feed_headers()). This
		// is distinct from the gate-404 — the feed is enabled here; the 404
		// comes from the miss, and the resolve happens after headers by design.
		Functions\when( 'get_query_var' )->justReturn( 'ghost' );
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wc_get_products' )->justReturn( [] ); // handle miss → null → 404.
		Functions\when( 'status_header' )->alias(
			static function ( $code ) {
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_single_product();
			$this->fail( 'Expected serve_single_product() to 404 on a handle miss.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'status_header:404', $e->getMessage() );
		}
	}

	// ------------------------------------------------------------------
	// build_single_product_json() — shape, 404, and leak paths
	// ------------------------------------------------------------------

	public function test_build_single_emits_singular_product_object(): void {
		$product = $this->simple_product( 22, 'day-hoodie' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );

		$json    = $this->invoke_private( 'build_single_product_json', 'day-hoodie' );
		$decoded = json_decode( (string) $json, true );

		// SINGULAR `product` key holding an OBJECT (associative map), NOT the
		// bulk `{ "products": [array] }`.
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'product', $decoded );
		$this->assertArrayNotHasKey( 'products', $decoded );
		$this->assertSame( 22, $decoded['product']['id'] );
		$this->assertSame( 'day-hoodie', $decoded['product']['handle'] );
		// Associative (object), not a list.
		$this->assertArrayHasKey( 'title', $decoded['product'] );
	}

	public function test_build_single_returns_null_for_unknown_handle(): void {
		// wc_get_products finds nothing for the slug → 404 (null), not cached.
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$this->assertNull( $this->invoke_private( 'build_single_product_json', 'ghost' ) );
	}

	public function test_build_single_returns_null_for_unsyndicated_product(): void {
		// LEAK GUARD: a slug that resolves to a real product the merchant has
		// NOT syndicated must 404, never 200 with the body. Drive the real
		// gate: 'selected' mode without this product id selected.
		$product = $this->simple_product( 7, 'secret' );
		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 999 ], // 7 not selected.
		];

		$this->assertNull( $this->invoke_private( 'build_single_product_json', 'secret' ) );
	}

	public function test_build_single_resolve_query_restricts_to_catalog_visibility(): void {
		// LEAK GUARD: the resolve query MUST carry visibility => 'catalog' and
		// status => 'publish' so WC drops Hidden / Search-only / unpublished
		// products at the source — a hidden product's slug must never 200.
		$captured = null;
		Functions\when( 'wc_get_products' )->alias(
			static function ( $query ) use ( &$captured ) {
				$captured = $query;
				return [];
			}
		);

		$this->invoke_private( 'build_single_product_json', 'maybe-hidden' );

		$this->assertIsArray( $captured );
		$this->assertSame( 'catalog', $captured['visibility'] ?? null );
		$this->assertSame( 'publish', $captured['status'] ?? null );
		$this->assertSame( 'maybe-hidden', $captured['slug'] ?? null );
		$this->assertSame( 1, $captured['limit'] ?? null );
	}

	/**
	 * Mockery WC_Product fully wired for map_product(). The date-less
	 * WC_Product stub means method_exists(get_date_created) is false, so the
	 * timestamp fields resolve to null without stubbing.
	 *
	 * @param int    $id     Product id.
	 * @param string $handle Slug.
	 * @return \Mockery\MockInterface
	 */
	private function simple_product( int $id, string $handle ) {
		$p = \Mockery::mock( 'WC_Product' );
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
