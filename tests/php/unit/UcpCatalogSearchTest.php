<?php
/**
 * Tests for WC_AI_Syndication_UCP_REST_Controller::handle_catalog_search.
 *
 * Focuses on UCP → WC Store API query-param mapping and the
 * surrounding response-assembly logic. Uses Brain\Monkey to stub:
 *
 *   - rest_do_request       — captures the dispatched Store API
 *                             request + returns canned product lists
 *   - get_term_by           — resolves category slug/name → ID
 *   - wc_get_price_decimals — drives minor→presentment conversion
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpCatalogSearchTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Captured params on the outgoing GET /wc/store/v1/products request.
	 * Populated by the rest_do_request stub so tests can assert how
	 * UCP fields mapped onto Store API query params.
	 *
	 * @var array<string, mixed>
	 */
	private array $captured_store_params = [];

	/**
	 * Canned list response for GET /wc/store/v1/products. Tests set
	 * this to the Store API product array they want the handler to
	 * process.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $fake_product_list = [];

	/**
	 * HTTP status to return from the Store API list dispatch. Tests
	 * set this to 500 to exercise the internal-error path.
	 */
	private int $fake_list_status = 200;

	/**
	 * Per-ID canned responses for individual product fetches (used
	 * when the handler pre-fetches variations for a variable product).
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $fake_store_api = [];

	/**
	 * Fake get_term_by store. Key = 'field:value' (e.g. 'slug:tops'),
	 * value = fake term object with term_id property.
	 *
	 * @var array<string, object>
	 */
	private array $fake_terms = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset settings between tests so disabled-state tests don't
		// leak into subsequent tests that assume enabled. The stub's
		// defaults include `enabled => yes`, so an empty array here
		// means the handler sees enabled syndication.
		WC_AI_Syndication::$test_settings = [];

		$this->captured_store_params = [];
		$this->fake_product_list     = [];
		$this->fake_list_status      = 200;
		$this->fake_store_api        = [];
		$this->fake_terms            = [];

		Functions\when( '__' )->returnArg();
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );

		$terms = &$this->fake_terms;
		Functions\when( 'get_term_by' )->alias(
			static function ( string $field, string $value, string $taxonomy ) use ( &$terms ) {
				if ( 'product_cat' !== $taxonomy ) {
					return false;
				}
				return $terms[ "{$field}:{$value}" ] ?? false;
			}
		);

		$captured_params = &$this->captured_store_params;
		$list            = &$this->fake_product_list;
		$list_status     = &$this->fake_list_status;
		$api             = &$this->fake_store_api;

		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use (
				&$captured_params,
				&$list,
				&$list_status,
				&$api
			) {
				$route = $request->get_route();

				if ( '/wc/store/v1/products' === $route ) {
					// List dispatch — capture the params so tests can
					// assert on the UCP→Store-API mapping, then return
					// the canned product list.
					foreach ( [ 'search', 'category', 'min_price', 'max_price' ] as $key ) {
						$val = $request->get_param( $key );
						if ( null !== $val ) {
							$captured_params[ $key ] = $val;
						}
					}
					return new WP_REST_Response( $list, $list_status );
				}

				if ( preg_match( '#^/wc/store/v1/products/(\d+)$#', $route, $m ) ) {
					$id = (int) $m[1];
					if ( ! array_key_exists( $id, $api ) || null === $api[ $id ] ) {
						return new WP_REST_Response( null, 404 );
					}
					return new WP_REST_Response( $api[ $id ], 200 );
				}

				return new WP_REST_Response( null, 500 );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Test helpers
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $body
	 */
	private function search_request( array $body = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/search' );
		$request->set_json_params( $body );
		return $request;
	}

	/**
	 * Build a minimal Store API product fixture for the list response.
	 *
	 * @return array<string, mixed>
	 */
	private function make_simple_product( int $id, string $name ): array {
		return [
			'id'                => $id,
			'name'              => $name,
			'type'              => 'simple',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [
				'price'               => '2500',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			],
		];
	}

	/**
	 * Register a fake term that `get_term_by` lookups will resolve.
	 */
	private function seed_term( int $term_id, string $slug, string $name ): void {
		$term                              = (object) [
			'term_id' => $term_id,
			'slug'    => $slug,
			'name'    => $name,
		];
		$this->fake_terms[ "slug:{$slug}" ] = $term;
		$this->fake_terms[ "name:{$name}" ] = $term;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function successful_search( array $body ): array {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_search( $this->search_request( $body ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		return $response->get_data();
	}

	// ------------------------------------------------------------------
	// Happy path + response shape
	// ------------------------------------------------------------------

	public function test_empty_body_returns_all_products_with_envelope(): void {
		$this->fake_product_list = [
			$this->make_simple_product( 1, 'Alpha' ),
			$this->make_simple_product( 2, 'Beta' ),
		];

		$body = $this->successful_search( [] );

		$this->assertCount( 2, $body['products'] );
		$this->assertEquals( 'prod_1', $body['products'][0]['id'] );
		$this->assertArrayHasKey(
			'dev.ucp.shopping.catalog.search',
			$body['ucp']['capabilities']
		);
	}

	public function test_empty_result_returns_200_with_empty_products_array(): void {
		// No canned products → Store API returns `[]` → response body
		// has products = []. Still 200 (no error), still has envelope.
		$this->fake_product_list = [];

		$body = $this->successful_search( [] );

		$this->assertEquals( [], $body['products'] );
		$this->assertArrayHasKey( 'ucp', $body );
	}

	// ------------------------------------------------------------------
	// Query mapping
	// ------------------------------------------------------------------

	public function test_query_field_maps_to_store_api_search_param(): void {
		$this->successful_search( [ 'query' => 'blue shirt' ] );

		$this->assertEquals( 'blue shirt', $this->captured_store_params['search'] );
	}

	public function test_empty_query_string_is_not_forwarded(): void {
		// An empty query isn't the same as "search for empty string" —
		// WC would treat "" as a no-op anyway, but keeping it out of
		// the param list is cleaner.
		$this->successful_search( [ 'query' => '' ] );

		$this->assertArrayNotHasKey( 'search', $this->captured_store_params );
	}

	public function test_non_string_query_is_ignored(): void {
		// Defensive: agents sending wrong types should not leak into
		// the Store API params.
		$this->successful_search( [ 'query' => 123 ] );

		$this->assertArrayNotHasKey( 'search', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// Category mapping
	// ------------------------------------------------------------------

	public function test_category_slug_resolves_to_term_id(): void {
		$this->seed_term( 42, 'tops', 'Tops' );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops' ] ] ]
		);

		$this->assertEquals( '42', $this->captured_store_params['category'] );
	}

	public function test_category_name_falls_back_when_slug_missing(): void {
		// The translator currently emits category names, so an agent
		// echoing back what it received should still work — we try slug
		// first, then name as a fallback.
		$this->seed_term( 99, 'clothing-tops', 'Tops' );
		// Wipe the slug index to simulate an agent using the name only.
		unset( $this->fake_terms['slug:clothing-tops'] );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'Tops' ] ] ]
		);

		$this->assertEquals( '99', $this->captured_store_params['category'] );
	}

	public function test_multiple_categories_join_as_comma_separated_ids(): void {
		$this->seed_term( 5, 'tops', 'Tops' );
		$this->seed_term( 7, 'bottoms', 'Bottoms' );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops', 'bottoms' ] ] ]
		);

		$this->assertEquals( '5,7', $this->captured_store_params['category'] );
	}

	public function test_unresolvable_categories_are_silently_dropped(): void {
		// A single unresolvable category + one resolvable = only the
		// resolvable one flows through. If ALL are unresolvable, no
		// category param is sent (Store API then returns the full
		// catalog, which may surprise agents — but matches v1's
		// lenient posture).
		$this->seed_term( 5, 'tops', 'Tops' );

		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'tops', 'nonexistent' ] ] ]
		);

		$this->assertEquals( '5', $this->captured_store_params['category'] );
	}

	public function test_all_unresolvable_categories_omits_param(): void {
		$this->successful_search(
			[ 'filters' => [ 'categories' => [ 'nope', 'also-nope' ] ] ]
		);

		$this->assertArrayNotHasKey( 'category', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// Price mapping (minor units → presentment units)
	// ------------------------------------------------------------------

	public function test_min_price_converts_minor_units_to_decimal_string(): void {
		// UCP 1000 minor units at 2 decimals → "10.00" presentment.
		// WC Store API expects the decimal-string format.
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 1000 ] ] ]
		);

		$this->assertEquals( '10.00', $this->captured_store_params['min_price'] );
	}

	public function test_max_price_converts_to_decimal_string(): void {
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'max' => 5000 ] ] ]
		);

		$this->assertEquals( '50.00', $this->captured_store_params['max_price'] );
	}

	public function test_price_range_with_min_and_max_forwards_both(): void {
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 1000, 'max' => 5000 ] ] ]
		);

		$this->assertEquals( '10.00', $this->captured_store_params['min_price'] );
		$this->assertEquals( '50.00', $this->captured_store_params['max_price'] );
	}

	public function test_zero_decimal_currency_produces_integer_string(): void {
		// JPY has 0 decimals. UCP 5000 at 0 decimals → "5000" (no
		// trailing decimal point). Verifies number_format() behaves
		// under zero-decimal currencies.
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );

		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 5000 ] ] ]
		);

		$this->assertEquals( '5000', $this->captured_store_params['min_price'] );
	}

	public function test_negative_prices_are_ignored(): void {
		// Defensive: a negative price is nonsense; ignore rather than
		// coercing to 0 (which would silently match all products) or
		// aborting with 400.
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => -100, 'max' => 5000 ] ] ]
		);

		$this->assertArrayNotHasKey( 'min_price', $this->captured_store_params );
		$this->assertEquals( '50.00', $this->captured_store_params['max_price'] );
	}

	public function test_non_numeric_price_is_ignored(): void {
		$this->successful_search(
			[ 'filters' => [ 'price' => [ 'min' => 'cheap' ] ] ]
		);

		$this->assertArrayNotHasKey( 'min_price', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// Malformed input is ignored, not an error
	// ------------------------------------------------------------------

	public function test_non_array_filters_is_ignored(): void {
		// filters as a string or scalar is garbled input — don't
		// 400 the request, just treat it as "no filters."
		$body = $this->successful_search( [ 'filters' => 'garbage' ] );

		$this->assertEmpty( $this->captured_store_params );
		$this->assertIsArray( $body['products'] );
	}

	public function test_non_array_categories_is_ignored(): void {
		$this->successful_search(
			[ 'filters' => [ 'categories' => 'tops' ] ]
		);

		$this->assertArrayNotHasKey( 'category', $this->captured_store_params );
	}

	// ------------------------------------------------------------------
	// Variable product expansion (integration with product translator + fetch_variations_for)
	// ------------------------------------------------------------------

	public function test_variable_products_in_search_results_get_variations_prefetched(): void {
		// Search returns a variable product. The handler must then
		// fire extra rest_do_request calls per variation so the
		// translator emits real variants, not synthesized defaults.
		$this->fake_product_list = [
			[
				'id'                => 789,
				'name'              => 'T-Shirt',
				'type'              => 'variable',
				'short_description' => '',
				'prices'            => [
					'price'         => '1000',
					'currency_code' => 'USD',
					'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '2000' ],
				],
				'variations'        => [
					[ 'id' => 101, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Small' ] ] ],
					[ 'id' => 102, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Large' ] ] ],
				],
			],
		];

		$this->fake_store_api[101] = [
			'id'                => 101,
			'name'              => 'T-Shirt',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [ 'price' => '1000', 'currency_code' => 'USD' ],
			'attributes'        => [ [ 'name' => 'Size', 'value' => 'Small' ] ],
		];
		$this->fake_store_api[102] = [
			'id'                => 102,
			'name'              => 'T-Shirt',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [ 'price' => '2000', 'currency_code' => 'USD' ],
			'attributes'        => [ [ 'name' => 'Size', 'value' => 'Large' ] ],
		];

		$body = $this->successful_search( [] );

		$this->assertCount( 1, $body['products'] );
		$variants = $body['products'][0]['variants'];
		$this->assertCount( 2, $variants );
		$this->assertEquals( 'var_101', $variants[0]['id'] );
		$this->assertEquals( 'Small', $variants[0]['title'] );
		$this->assertEquals( 'var_102', $variants[1]['id'] );
		$this->assertSame( 2000, $variants[1]['price']['amount'] );
	}

	// ------------------------------------------------------------------
	// Store API error handling
	// ------------------------------------------------------------------

	public function test_store_api_5xx_returns_ucp_internal_error(): void {
		// If WC's Store API blows up, surface it as a UCP internal_error
		// with 500 rather than proxying whatever WC returned. Agents
		// should see a consistent UCP error shape regardless of the
		// underlying failure mode.
		$this->fake_list_status = 500;

		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_search(
			$this->search_request( [] )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ucp_internal_error', $response->get_error_code() );
		$this->assertEquals( [ 'status' => 500 ], $response->get_error_data() );
	}

	public function test_store_api_4xx_treated_as_empty_result(): void {
		// A 4xx from Store API typically means an invalid filter value
		// (e.g., category=nonexistent). UCP treats this as a business
		// outcome: "no products match," not a protocol error.
		$this->fake_list_status = 404;

		$body = $this->successful_search( [] );

		$this->assertEquals( [], $body['products'] );
	}

	// ------------------------------------------------------------------
	// Syndication-disabled gate
	// ------------------------------------------------------------------

	public function test_disabled_syndication_returns_503_ucp_disabled(): void {
		// Merchant has paused syndication via the admin UI. Routes stay
		// registered (to avoid rewrite-flush churn on every toggle), but
		// the handler must refuse to serve catalog data — otherwise the
		// "pause" control silently fails open. Verified by all three
		// handler test files; this is the search path.
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'no' ];

		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_search(
			$this->search_request( [ 'query' => 'anything' ] )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ucp_disabled', $response->get_error_code() );
		$this->assertEquals( [ 'status' => 503 ], $response->get_error_data() );

		// Critical: must short-circuit BEFORE dispatching anything to
		// the Store API. If the disabled check is ordered wrong, we'd
		// still do internal dispatch work before rejecting.
		$this->assertEmpty(
			$this->captured_store_params,
			'Handler must short-circuit before dispatching when disabled'
		);
	}
}
