<?php
/**
 * Tests for the Shopify-compatible /products.json endpoint in
 * WC_AI_Storefront_Products_Feed.
 *
 * Covers:
 *  - QUERY_VAR constant + add_rewrite_rules() (both /products.json and the
 *    /collections/all/products.json alias resolve to the same query var)
 *  - add_query_vars() registration
 *  - serve_products_feed() gate/404/204 branches (throw-sentinel on
 *    status_header so the exit()-terminated branches are asserted in a
 *    forked process, mirroring LlmsTxtTest's serve_agents_md tests)
 *  - serve_products_feed() edge-cache header wiring (source inspection,
 *    mirroring test_agents_md_serve_mirrors_llms_txt_exactly)
 *  - get_feed_json() body shape + the is_product_syndicated gate
 *  - request_limit()/request_page() clamping
 *  - get_cached_feed_json() cache-hit semantics (Task 5)
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ProductsFeedTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Products_Feed $feed;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->feed = new WC_AI_Storefront_Products_Feed();

		// Default: plugin + feed both enabled. Disabled-store / feed-off
		// tests override this.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'all',
		];

		// $_GET / $_SERVER pass-throughs used by request_limit()/page() and
		// get_cached_feed_json(). Reset $_GET so request parsers see no
		// params unless a test sets them.
		$_GET = [];
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			static fn( $v ) => abs( (int) $v )
		);
		// wp_json_encode -> native json_encode for body-shape assertions.
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		// discovery_cache_control() (called inside serve) fires apply_filters
		// + wc_get_logger; the OPTIONS/disabled tests short-circuit before
		// the header block, but stub them defensively for any test that
		// reaches the header emission.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke a private method on the feed instance via reflection.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed Return value.
	 */
	private function invoke_private( string $method, ...$args ) {
		$reflection = new ReflectionClass( $this->feed );
		$m          = $reflection->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $this->feed, ...$args );
	}

	// ------------------------------------------------------------------
	// Constant + rewrite/query-var wiring
	// ------------------------------------------------------------------

	public function test_query_var_constant_defined(): void {
		$this->assertSame( 'wc_ai_storefront_products_json', WC_AI_Storefront_Products_Feed::QUERY_VAR );
	}

	public function test_rewrite_rules_registered_for_both_paths(): void {
		$rules = [];
		Functions\when( 'add_rewrite_rule' )->alias(
			static function ( $regex, $query, $after ) use ( &$rules ) {
				$rules[ $regex ] = [ 'query' => $query, 'after' => $after ];
			}
		);

		$this->feed->add_rewrite_rules();

		// Canonical /products.json.
		$this->assertArrayHasKey( '^products\.json$', $rules );
		$this->assertSame( 'top', $rules['^products\.json$']['after'] );
		$this->assertStringContainsString(
			WC_AI_Storefront_Products_Feed::QUERY_VAR . '=1',
			$rules['^products\.json$']['query']
		);

		// /collections/all/products.json alias resolves to the SAME query var.
		$this->assertArrayHasKey( '^collections/all/products\.json$', $rules );
		$this->assertStringContainsString(
			WC_AI_Storefront_Products_Feed::QUERY_VAR . '=1',
			$rules['^collections/all/products\.json$']['query']
		);
	}

	public function test_query_var_registered(): void {
		$vars = $this->feed->add_query_vars( [] );
		$this->assertContains( WC_AI_Storefront_Products_Feed::QUERY_VAR, $vars );
	}

	// ------------------------------------------------------------------
	// suppress_canonical_redirect() — trailing-slash 301 defense
	// ------------------------------------------------------------------

	public function test_canonical_redirect_suppressed_for_feed_query_var(): void {
		// WP would otherwise 301 `/products.json` to a trailing-slash variant
		// that no longer matches the rewrite rule, 404ing the request. The
		// guard must return false when the feed query var is set.
		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => WC_AI_Storefront_Products_Feed::QUERY_VAR === $var ? 1 : 0
		);

		$this->assertFalse(
			$this->feed->suppress_canonical_redirect( 'https://example.com/products.json/' )
		);
	}

	public function test_canonical_redirect_untouched_when_query_var_not_set(): void {
		// Canonical behaviour elsewhere on the site must be preserved: when
		// the feed query var is absent, the candidate URL is returned unchanged.
		Functions\when( 'get_query_var' )->justReturn( 0 );

		$this->assertSame(
			'https://example.com/some-page/',
			$this->feed->suppress_canonical_redirect( 'https://example.com/some-page/' )
		);
	}

	// ------------------------------------------------------------------
	// serve_products_feed() — early-return + gate branches
	// ------------------------------------------------------------------

	public function test_serve_returns_early_when_query_var_not_set(): void {
		// No query var → no-op (no output, no exit) so the handler doesn't
		// hijack unrelated requests.
		Functions\when( 'get_query_var' )->justReturn( 0 );

		ob_start();
		$this->feed->serve_products_feed();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Disabled store (enabled=no) → 404 then exit. Throw-sentinel on
	 * status_header so the branch is asserted without reaching the real
	 * exit(); separate process so a stray exit() ends only the forked child.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_returns_404_when_plugin_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 );
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$captured_status = null;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$captured_status ) {
				$captured_status = $code;
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_products_feed();
			$this->fail( 'Expected serve_products_feed() to emit a 404 on a disabled store.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 404, $captured_status );
			$this->assertSame( 'status_header:404', $e->getMessage() );
		}
	}

	/**
	 * Feed toggle off (products_json_enabled=no) while the plugin is enabled
	 * → 404 then exit. Proves the feed-specific gate, not just the global one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_returns_404_when_feed_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 );
		WC_AI_Storefront::$test_settings = [
			'enabled'               => 'yes',
			'products_json_enabled' => 'no',
		];

		$captured_status = null;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$captured_status ) {
				$captured_status = $code;
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_products_feed();
			$this->fail( 'Expected serve_products_feed() to emit a 404 when the feed toggle is off.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 404, $captured_status );
		}
	}

	/**
	 * OPTIONS preflight → 204 then exit, after the CORS headers. Same
	 * throw-sentinel + separate-process technique.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_returns_204_on_options_request(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 );
		$_SERVER['REQUEST_METHOD'] = 'OPTIONS';

		$captured_status = null;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$captured_status ) {
				$captured_status = $code;
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->feed->serve_products_feed();
			$this->fail( 'Expected serve_products_feed() to emit a 204 on an OPTIONS preflight.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 204, $captured_status );
		} finally {
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	}

	/**
	 * Source-inspection guard for the serve handler's edge-cache + CORS
	 * wiring. serve_products_feed() emits headers + exit(), so (per the
	 * established suite pattern, see LlmsTxtTest's
	 * test_agents_md_serve_mirrors_llms_txt_exactly) the header wiring is
	 * pinned at source level rather than by capturing output across exit().
	 */
	public function test_serve_emits_discovery_cache_and_cors_headers(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/ai-storefront/class-wc-ai-storefront-products-feed.php' );

		$start = strpos( $source, 'function serve_products_feed(' );
		$this->assertNotFalse( $start, 'serve_products_feed() must exist.' );
		$body = substr( $source, $start );

		// Content type is JSON.
		$this->assertStringContainsString( 'application/json; charset=utf-8', $body );
		// Edge-cache: shared discovery cache-control + Vary: Host so the CDN
		// keys per virtual host (the point of the feed being edge-cacheable).
		$this->assertStringContainsString( 'WC_AI_Storefront::discovery_cache_control()', $body );
		$this->assertStringContainsString( "header( 'Vary: Host' )", $body );
		$this->assertStringContainsString( "header( 'X-Content-Type-Options: nosniff' )", $body );
		// Machine surface: keep it out of search indexes, matching /opensearch.xml.
		$this->assertStringContainsString( "header( 'X-Robots-Tag: noindex' )", $body );
		$this->assertStringContainsString( "header( 'Access-Control-Allow-Origin: *' )", $body );
		// Must not regress the edge-cache rate-limit fix with a no-store.
		$this->assertStringNotContainsString( 'Cache-Control: no-store', $body );
		// The cached path is used (Task 5) — not the raw builder.
		$this->assertStringContainsString( 'get_cached_feed_json(', $body );
	}

	// ------------------------------------------------------------------
	// get_feed_json() — body shape + syndication gate
	// ------------------------------------------------------------------

	public function test_get_feed_json_emits_products_envelope(): void {
		$product = $this->simple_product( 22, 'day-hoodie' );

		Functions\when( 'wc_get_products' )->justReturn( [ $product ] );
		// Default fixture is product_selection_mode='all', so every product
		// with a positive ID is syndicated (the stub mirrors production).

		$json = $this->invoke_private( 'get_feed_json', 30, 1 );

		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'products', $decoded );
		$this->assertCount( 1, $decoded['products'] );
		$this->assertSame( 22, $decoded['products'][0]['id'] );
		$this->assertSame( 'day-hoodie', $decoded['products'][0]['handle'] );
	}

	public function test_get_feed_json_skips_unsyndicated_products(): void {
		$kept    = $this->simple_product( 1, 'kept' );
		$skipped = $this->simple_product( 2, 'skipped' );

		Functions\when( 'wc_get_products' )->justReturn( [ $kept, $skipped ] );
		// Drive the real syndication gate: 'selected' mode with only
		// product 1 chosen, so product 2 is filtered out of the feed.
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'products_json_enabled'  => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 1 ],
		];

		$json    = $this->invoke_private( 'get_feed_json', 30, 1 );
		$decoded = json_decode( $json, true );

		$this->assertCount( 1, $decoded['products'] );
		$this->assertSame( 1, $decoded['products'][0]['id'] );
	}

	public function test_get_feed_json_empty_when_no_products(): void {
		Functions\when( 'wc_get_products' )->justReturn( [] );

		$json    = $this->invoke_private( 'get_feed_json', 30, 1 );
		$decoded = json_decode( $json, true );

		$this->assertSame( [ 'products' => [] ], $decoded );
	}

	public function test_get_feed_json_query_restricts_to_catalog_visibility(): void {
		// The product query MUST carry visibility => 'catalog' so WC excludes
		// "Hidden" and "Search results only" products at the source. The
		// syndication gate only scopes WHICH products the merchant opted into,
		// not catalog visibility — without this arg a Hidden product leaks
		// into the public feed (the CRITICAL review finding).
		$captured_query = null;
		Functions\when( 'wc_get_products' )->alias(
			static function ( $query ) use ( &$captured_query ) {
				$captured_query = $query;
				return [];
			}
		);

		$this->invoke_private( 'get_feed_json', 30, 1 );

		$this->assertIsArray( $captured_query );
		$this->assertArrayHasKey( 'visibility', $captured_query );
		$this->assertSame( 'catalog', $captured_query['visibility'] );
	}

	public function test_get_feed_json_excludes_catalog_hidden_product(): void {
		// Behavioural proof of the leak fix: a product the syndication gate
		// would pass ('all' mode) but that WC marks catalog-hidden must NOT
		// appear. Drive it through a wc_get_products stub that honours the
		// visibility arg the way WC core does — returning only the visible
		// product when visibility => 'catalog' is requested.
		$visible = $this->simple_product( 1, 'visible' );
		$hidden  = $this->simple_product( 2, 'hidden' );

		Functions\when( 'wc_get_products' )->alias(
			static function ( $query ) use ( $visible, $hidden ) {
				$all = [ 1 => $visible, 2 => $hidden ];
				if ( isset( $query['visibility'] ) && 'catalog' === $query['visibility'] ) {
					// Product 2 is catalog-hidden, so WC drops it.
					unset( $all[2] );
				}
				return array_values( $all );
			}
		);

		$json    = $this->invoke_private( 'get_feed_json', 30, 1 );
		$decoded = json_decode( $json, true );

		$this->assertCount( 1, $decoded['products'], 'A catalog-hidden product must not appear in the feed.' );
		$this->assertSame( 1, $decoded['products'][0]['id'] );
		$handles = array_column( $decoded['products'], 'handle' );
		$this->assertNotContains( 'hidden', $handles );
	}

	// ------------------------------------------------------------------
	// request_limit() / request_page() clamping
	// ------------------------------------------------------------------

	public function test_request_limit_defaults_to_30(): void {
		unset( $_GET['limit'] );
		$this->assertSame( 30, $this->invoke_private( 'request_limit' ) );
	}

	public function test_request_limit_clamps_to_250_max(): void {
		$_GET['limit'] = '5000';
		$this->assertSame( 250, $this->invoke_private( 'request_limit' ) );
	}

	public function test_request_limit_honours_in_range_value(): void {
		$_GET['limit'] = '50';
		$this->assertSame( 50, $this->invoke_private( 'request_limit' ) );
	}

	public function test_request_limit_floors_zero_to_default(): void {
		$_GET['limit'] = '0';
		$this->assertSame( 30, $this->invoke_private( 'request_limit' ) );
	}

	public function test_request_page_defaults_to_1(): void {
		unset( $_GET['page'] );
		$this->assertSame( 1, $this->invoke_private( 'request_page' ) );
	}

	public function test_request_page_honours_value(): void {
		$_GET['page'] = '3';
		$this->assertSame( 3, $this->invoke_private( 'request_page' ) );
	}

	public function test_request_page_floors_to_1(): void {
		$_GET['page'] = '0';
		$this->assertSame( 1, $this->invoke_private( 'request_page' ) );
	}

	// ------------------------------------------------------------------
	// get_cached_feed_json() — versioned-key cache (Task 5)
	// ------------------------------------------------------------------

	public function test_version_option_and_ttl_constants_defined(): void {
		$this->assertSame(
			'wc_ai_storefront_products_feed_version',
			WC_AI_Storefront_Products_Feed::VERSION_OPTION
		);
		$this->assertSame( HOUR_IN_SECONDS, WC_AI_Storefront_Products_Feed::CACHE_TTL );
	}

	public function test_cached_feed_computes_once_then_serves_from_cache(): void {
		$_SERVER['HTTP_HOST'] = 'shop.example.com';

		$product = $this->simple_product( 7, 'lucky-seven' );

		// Counter stub: prove the expensive query runs exactly once across
		// two get_cached_feed_json() calls — the second call must hit cache.
		$query_calls = 0;
		Functions\when( 'wc_get_products' )->alias(
			static function () use ( &$query_calls, $product ) {
				++$query_calls;
				return [ $product ];
			}
		);

		// In-memory transient store driving the cache round-trip.
		$store = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		// Stable version → both calls compute the same cache key.
		Functions\when( 'get_option' )->justReturn( 1 );

		$first  = $this->invoke_private( 'get_cached_feed_json', 30, 1 );
		$second = $this->invoke_private( 'get_cached_feed_json', 30, 1 );

		$this->assertSame( 1, $query_calls, 'wc_get_products must run once; the 2nd call is a cache hit.' );
		$this->assertSame( $first, $second, 'Cache hit must return the byte-identical first body.' );
		$this->assertStringContainsString( '"handle":"lucky-seven"', $first );

		unset( $_SERVER['HTTP_HOST'] );
	}

	public function test_cached_feed_version_bump_orphans_old_page(): void {
		$_SERVER['HTTP_HOST'] = 'shop.example.com';

		$product = $this->simple_product( 7, 'lucky-seven' );
		$query_calls = 0;
		Functions\when( 'wc_get_products' )->alias(
			static function () use ( &$query_calls, $product ) {
				++$query_calls;
				return [ $product ];
			}
		);

		$store = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		// Version flips from 1 to 2 between the two calls: the second call
		// computes a NEW cache key (the old page is orphaned), so the query
		// runs again. This is how the invalidator's bump busts every page.
		$version = 1;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$version ) {
				return $version;
			}
		);

		$this->invoke_private( 'get_cached_feed_json', 30, 1 );
		$version = 2;
		$this->invoke_private( 'get_cached_feed_json', 30, 1 );

		$this->assertSame( 2, $query_calls, 'A version bump must orphan the cached page and force a recompute.' );

		unset( $_SERVER['HTTP_HOST'] );
	}

	public function test_cached_feed_keys_separately_per_page(): void {
		$_SERVER['HTTP_HOST'] = 'shop.example.com';

		$product = $this->simple_product( 7, 'lucky-seven' );
		$query_calls = 0;
		Functions\when( 'wc_get_products' )->alias(
			static function () use ( &$query_calls, $product ) {
				++$query_calls;
				return [ $product ];
			}
		);

		$store = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( 1 );

		// Page 1 and page 2 are distinct cache keys → two computes.
		$this->invoke_private( 'get_cached_feed_json', 30, 1 );
		$this->invoke_private( 'get_cached_feed_json', 30, 2 );

		$this->assertSame( 2, $query_calls, 'Each page must key separately so paginated bodies do not collide.' );

		unset( $_SERVER['HTTP_HOST'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * A minimal simple-product mock sufficient for map_product().
	 *
	 * @param int    $id     Product ID.
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

		// map_product() resolves vendor via wp_get_post_terms + product_type
		// via get_post_meta/get_term. Stub them to the "absent" path.
		Functions\when( 'wp_get_post_terms' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );

		return $p;
	}
}
