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
		// Brain Monkey registers a function suite-wide once ANY test mocks it,
		// so every test that reaches weight_grams() must have an expectation —
		// same trap this file already documents for wp_get_post_terms. Default
		// to a kg store; tests asserting conversion re-stub with their own unit.
		Functions\when( 'wc_get_weight' )->alias(
			static function ( $weight, $to_unit = 'g', $from_unit = '' ) {
				// Signature matches wc_get_weight() so the stub can't silently
				// ignore a unit the production call asks for. Store is kg.
				return 'g' === $to_unit ? (float) $weight * 1000.0 : (float) $weight;
			}
		);
		// Attachment reads behind the Shopify image record (#627). In production
		// these are cache hits primed by wp_get_attachment_image_url(); here they
		// only need to exist. Tests asserting width/height/dates re-stub them.
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
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
		Functions\when( 'get_page_by_path' )->justReturn( false ); // handle miss → null → 404.
		Functions\when( 'wc_get_product' )->justReturn( false );
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
		Functions\when( 'get_page_by_path' )->justReturn( $this->wp_post( 22 ) );
		Functions\when( 'wc_get_product' )->justReturn( $product );

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

	public function test_build_single_resolves_by_slug_via_get_page_by_path(): void {
		// REGRESSION (the live bug): the slug MUST be resolved via
		// get_page_by_path( handle, OBJECT, 'product' ), NOT
		// wc_get_products([ 'slug' => … ]). `slug` is not a supported
		// wc_get_products arg — it is silently ignored and the query returns
		// the FIRST product for EVERY handle. Assert the resolver is called
		// with the exact handle + 'product' post type. (Also asserts we do NOT
		// fall back to wc_get_products with a slug filter.)
		$captured = [];
		Functions\when( 'get_page_by_path' )->alias(
			static function ( $path, $output, $post_type ) use ( &$captured ) {
				$captured = [ 'path' => $path, 'post_type' => $post_type ];
				return false; // resolution miss — we only assert the call shape here.
			}
		);
		Functions\when( 'wc_get_product' )->justReturn( false ); // satisfy the top function_exists guard.
		Functions\when( 'wc_get_products' )->alias(
			static function () {
				throw new \LogicException( 'build_single_product_json must NOT call wc_get_products to resolve a slug.' );
			}
		);

		$this->invoke_private( 'build_single_product_json', 'day-hoodie' );

		$this->assertSame( 'day-hoodie', $captured['path'] ?? null );
		$this->assertSame( 'product', $captured['post_type'] ?? null );
	}

	public function test_build_single_returns_null_for_unknown_handle(): void {
		// get_page_by_path finds no product for the slug → 404 (null), not cached.
		Functions\when( 'get_page_by_path' )->justReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( false );

		$this->assertNull( $this->invoke_private( 'build_single_product_json', 'ghost' ) );
	}

	public function test_build_single_returns_null_for_non_published_product(): void {
		// A slug that resolves to a draft/private/pending product must 404 —
		// get_page_by_path returns posts of any status, so the publish check
		// is the guard.
		$post              = $this->wp_post( 5 );
		$post->post_status = 'draft';
		Functions\when( 'get_page_by_path' )->justReturn( $post );
		Functions\when( 'wc_get_product' )->justReturn( $this->simple_product( 5, 'draft-product' ) );

		$this->assertNull( $this->invoke_private( 'build_single_product_json', 'draft-product' ) );
	}

	public function test_build_single_404s_catalog_hidden_or_search_only_product(): void {
		// LEAK GUARD: get_page_by_path does NOT filter catalog visibility, so a
		// published product that is Hidden or Search-only must 404 via the
		// explicit get_catalog_visibility() check — never leak its body.
		foreach ( [ 'hidden', 'search' ] as $vis ) {
			$product = $this->simple_product( 9, 'stealth', $vis );
			Functions\when( 'get_page_by_path' )->justReturn( $this->wp_post( 9 ) );
			Functions\when( 'wc_get_product' )->justReturn( $product );

			$this->assertNull(
				$this->invoke_private( 'build_single_product_json', 'stealth' ),
				"catalog visibility '{$vis}' must 404"
			);
		}
	}

	public function test_build_single_returns_null_for_unsyndicated_product(): void {
		// LEAK GUARD: a slug that resolves to a real, catalog-visible product
		// the merchant has NOT syndicated must 404. Drive the real gate:
		// 'selected' mode without this product id selected.
		$product = $this->simple_product( 7, 'secret' );
		Functions\when( 'get_page_by_path' )->justReturn( $this->wp_post( 7 ) );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 999 ], // 7 not selected.
		];

		$this->assertNull( $this->invoke_private( 'build_single_product_json', 'secret' ) );
	}

	/**
	 * A product WP_Post double for get_page_by_path(). Published 'product' by
	 * default; tests override post_status for the non-published case.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post
	 */
	private function wp_post( int $id ): \WP_Post {
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_status = 'publish';
		$post->post_type   = 'product';
		return $post;
	}

	/**
	 * Mockery WC_Product fully wired for map_product() + the single-product
	 * resolver. The date-less WC_Product stub means method_exists(get_date_created)
	 * is false, so the timestamp fields resolve to null without stubbing.
	 *
	 * @param int    $id         Product id.
	 * @param string $handle     Slug.
	 * @param string $visibility Catalog visibility (visible|catalog|search|hidden).
	 * @return \Mockery\MockInterface
	 */
	private function simple_product( int $id, string $handle, string $visibility = 'visible' ) {
		$p = \Mockery::mock( 'WC_Product' );
		// Shopify variant scalars (#627); defaults unless a test overrides.
		$p->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$p->shouldReceive( 'get_weight' )->andReturn( '' );
		$p->shouldReceive( 'get_menu_order' )->andReturn( 0 );
		$p->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_catalog_visibility' )->andReturn( $visibility );
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
