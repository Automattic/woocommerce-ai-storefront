<?php
/**
 * Tests for WC_AI_Syndication_UCP_REST_Controller::handle_catalog_lookup.
 *
 * The handler dispatches `rest_do_request` against the WC Store API
 * for each requested ID. Tests stub `rest_do_request` via Brain\Monkey
 * to return canned product responses (or 404s) and assert on the
 * resulting UCP catalog envelope.
 *
 * Covers:
 *   - Input validation (missing/empty/non-array ids → 400)
 *   - Happy path: all IDs resolve to simple products
 *   - Missing IDs: produce not_found messages with jsonpath
 *   - Mixed found/missing: both products and messages in response
 *   - Malformed IDs: non-string, empty after prefix, ID=0
 *   - `var_N` prefix stripped (lenient v1 behavior)
 *   - Variable product expansion: variations pre-fetched, real variants emitted
 *
 * Route-registration tests remain in UcpRestControllerTest — that's
 * about `register_rest_route()` wiring, this is about the handler body.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpCatalogLookupTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Map of WC ID → canned Store API response. The rest_do_request
	 * stub reads from here to simulate a live WC installation.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $fake_store_api = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->fake_store_api = [];

		Functions\when( '__' )->returnArg();

		// Stub the catalog_envelope dependency's PROTOCOL_VERSION
		// access — UcpEnvelope reads WC_AI_Syndication_Ucp::PROTOCOL_VERSION,
		// which is a const defined on the class and resolves fine at test
		// time since that class is loaded by the bootstrap.

		// Route rest_do_request through our fake_store_api map. The
		// controller only calls this for `GET /wc/store/v1/products/{id}`,
		// so parsing the route for the trailing int is sufficient.
		$api = &$this->fake_store_api;
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use ( &$api ) {
				$route = $request->get_route();
				if ( ! preg_match( '#/wc/store/v1/products/(\d+)$#', $route, $m ) ) {
					// Unexpected route — return a 500-ish response so the
					// test fails loudly rather than silently mis-reporting.
					return new WP_REST_Response( null, 500 );
				}

				$id = (int) $m[1];
				if ( ! array_key_exists( $id, $api ) || null === $api[ $id ] ) {
					return new WP_REST_Response(
						[ 'code' => 'woocommerce_rest_product_invalid_id' ],
						404
					);
				}

				return new WP_REST_Response( $api[ $id ], 200 );
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
	 * Seed a simple product fixture at the given WC ID.
	 */
	private function seed_simple_product( int $id, string $name = 'Widget' ): void {
		$this->fake_store_api[ $id ] = [
			'id'                => $id,
			'name'              => $name,
			'slug'              => strtolower( str_replace( ' ', '-', $name ) ),
			'permalink'         => 'https://example.com/product/' . $id,
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
	 * Seed a variable product + its variations at the given IDs.
	 *
	 * @param array<int, array{id: int, price: string, size: string}> $variation_specs
	 */
	private function seed_variable_product(
		int $parent_id,
		string $name,
		array $variation_specs
	): void {
		$variation_refs = [];
		foreach ( $variation_specs as $spec ) {
			$variation_refs[] = [
				'id'         => $spec['id'],
				'attributes' => [ [ 'name' => 'Size', 'value' => $spec['size'] ] ],
			];

			$this->fake_store_api[ $spec['id'] ] = [
				'id'                => $spec['id'],
				'name'              => $name,
				'short_description' => '',
				'is_in_stock'       => true,
				'prices'            => [
					'price'               => $spec['price'],
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				],
				'attributes'        => [ [ 'name' => 'Size', 'value' => $spec['size'] ] ],
			];
		}

		$this->fake_store_api[ $parent_id ] = [
			'id'                => $parent_id,
			'name'              => $name,
			'type'              => 'variable',
			'short_description' => '',
			'prices'            => [
				'price'               => '1000',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
				'price_range'         => [ 'min_amount' => '1000', 'max_amount' => '2000' ],
			],
			'variations'        => $variation_refs,
		];
	}

	/**
	 * Build a POST /catalog/lookup request with the given body.
	 *
	 * @param array<string, mixed> $body
	 */
	private function lookup_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/lookup' );
		$request->set_json_params( $body );
		return $request;
	}

	/**
	 * Invoke the handler and assert we got a 200 WP_REST_Response.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed> The response body.
	 */
	private function successful_lookup( array $body ): array {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_lookup( $this->lookup_request( $body ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		return $response->get_data();
	}

	// ------------------------------------------------------------------
	// Input validation
	// ------------------------------------------------------------------

	public function test_missing_ids_returns_400(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_lookup( $this->lookup_request( [] ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'ucp_invalid_input', $response->get_error_code() );
		$this->assertEquals( [ 'status' => 400 ], $response->get_error_data() );
	}

	public function test_non_array_ids_returns_400(): void {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_lookup(
			$this->lookup_request( [ 'ids' => 'prod_123' ] )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( [ 'status' => 400 ], $response->get_error_data() );
	}

	public function test_empty_ids_array_returns_400(): void {
		// Distinct from "missing ids" — the client sent the key but
		// with no IDs. Still a malformed request; 400 rather than
		// 200-with-empty-products keeps the contract clear.
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_lookup(
			$this->lookup_request( [ 'ids' => [] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( [ 'status' => 400 ], $response->get_error_data() );
	}

	// ------------------------------------------------------------------
	// Happy path: simple products
	// ------------------------------------------------------------------

	public function test_single_simple_product_translates_and_returns(): void {
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_123' ] ] );

		$this->assertCount( 1, $body['products'] );
		$this->assertEquals( 'prod_123', $body['products'][0]['id'] );
		$this->assertEquals( 'Widget', $body['products'][0]['title'] );
	}

	public function test_multiple_simple_products_returned_in_request_order(): void {
		// Order preservation matters: agents may correlate the response's
		// products[] positionally with their original ids[] list.
		$this->seed_simple_product( 200, 'Alpha' );
		$this->seed_simple_product( 100, 'Beta' );
		$this->seed_simple_product( 300, 'Gamma' );

		$body = $this->successful_lookup(
			[ 'ids' => [ 'prod_200', 'prod_100', 'prod_300' ] ]
		);

		$this->assertEquals( 'Alpha', $body['products'][0]['title'] );
		$this->assertEquals( 'Beta', $body['products'][1]['title'] );
		$this->assertEquals( 'Gamma', $body['products'][2]['title'] );
	}

	public function test_response_wraps_products_in_catalog_envelope(): void {
		$this->seed_simple_product( 123 );

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_123' ] ] );

		$this->assertArrayHasKey( 'ucp', $body );
		$this->assertArrayHasKey( 'capabilities', $body['ucp'] );
		$this->assertArrayHasKey(
			'dev.ucp.shopping.catalog.lookup',
			$body['ucp']['capabilities']
		);
	}

	// ------------------------------------------------------------------
	// Not-found handling
	// ------------------------------------------------------------------

	public function test_missing_product_emits_not_found_message(): void {
		// No seeded product — store API returns 404.
		$body = $this->successful_lookup( [ 'ids' => [ 'prod_999' ] ] );

		$this->assertEquals( [], $body['products'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertEquals( 'not_found', $body['messages'][0]['code'] );
		$this->assertEquals( '$.ids[0]', $body['messages'][0]['path'] );
		$this->assertEquals( 'unrecoverable', $body['messages'][0]['severity'] );
	}

	public function test_mixed_found_and_missing_returns_both(): void {
		// The handler should be tolerant — one bad ID shouldn't drop
		// the whole response. Valid products come through in their
		// positions; each missing ID emits its own jsonpath message.
		$this->seed_simple_product( 100, 'Alpha' );
		$this->seed_simple_product( 300, 'Gamma' );

		$body = $this->successful_lookup(
			[ 'ids' => [ 'prod_100', 'prod_200', 'prod_300' ] ]
		);

		$this->assertCount( 2, $body['products'] );
		$this->assertEquals( 'Alpha', $body['products'][0]['title'] );
		$this->assertEquals( 'Gamma', $body['products'][1]['title'] );

		$this->assertCount( 1, $body['messages'] );
		// The missing ID was at position 1 in the request — that's what
		// the jsonpath should reflect, not the product-array index.
		$this->assertEquals( '$.ids[1]', $body['messages'][0]['path'] );
	}

	public function test_messages_key_omitted_when_all_ids_found(): void {
		// Keep the response minimal: skip the `messages` array entirely
		// when there are no messages to report.
		$this->seed_simple_product( 123 );

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_123' ] ] );

		$this->assertArrayNotHasKey( 'messages', $body );
	}

	// ------------------------------------------------------------------
	// Malformed IDs
	// ------------------------------------------------------------------

	public function test_non_string_id_treated_as_not_found(): void {
		// If an agent sends a number instead of a string, don't
		// crash — just report it as not-found at the right path.
		$body = $this->successful_lookup( [ 'ids' => [ 123 ] ] );

		$this->assertEquals( [], $body['products'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertEquals( '$.ids[0]', $body['messages'][0]['path'] );
	}

	public function test_id_string_with_no_numeric_portion_is_not_found(): void {
		// "prod_abc" → stripped to "abc" → (int) → 0 → treated as miss.
		$body = $this->successful_lookup( [ 'ids' => [ 'prod_abc' ] ] );

		$this->assertCount( 1, $body['messages'] );
		$this->assertEquals( 'not_found', $body['messages'][0]['code'] );
	}

	public function test_id_with_default_suffix_strips_to_parent_id(): void {
		// `var_123_default` = synthesized variant for simple product 123.
		// Lookup should resolve to product 123 (the parent), not 404.
		// PHP's (int) cast truncates at the first non-numeric char, so
		// "123_default" → 123.
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup( [ 'ids' => [ 'var_123_default' ] ] );

		$this->assertCount( 1, $body['products'] );
		$this->assertEquals( 'prod_123', $body['products'][0]['id'] );
	}

	public function test_bare_numeric_id_without_prefix_still_works(): void {
		// The prefix strip is a regex anchored with `^(prod_|var_)`;
		// without a prefix it's a no-op. "123" → 123 still resolves.
		// This matches the plan's "lenient" v1 posture.
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup( [ 'ids' => [ '123' ] ] );

		$this->assertCount( 1, $body['products'] );
		$this->assertEquals( 'prod_123', $body['products'][0]['id'] );
	}

	// ------------------------------------------------------------------
	// Variable product expansion (integration with task 7)
	// ------------------------------------------------------------------

	public function test_variable_product_variations_pre_fetched_and_expanded(): void {
		// The controller must detect type=variable, iterate the
		// variations[] pointer list, fetch each variation's full
		// Store API response, and pass them to the product translator.
		// Without the pre-fetch, the translator would fall back to a
		// single synthesized default variant — losing the per-variation
		// prices and attribute titles.
		$this->seed_variable_product(
			789,
			'T-Shirt',
			[
				[ 'id' => 101, 'price' => '1000', 'size' => 'Small' ],
				[ 'id' => 102, 'price' => '1500', 'size' => 'Medium' ],
				[ 'id' => 103, 'price' => '2000', 'size' => 'Large' ],
			]
		);

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_789' ] ] );

		$this->assertCount( 1, $body['products'] );
		$variants = $body['products'][0]['variants'];

		$this->assertCount( 3, $variants );
		$this->assertEquals( 'var_101', $variants[0]['id'] );
		$this->assertEquals( 'Small', $variants[0]['title'] );
		$this->assertSame( 1000, $variants[0]['price']['amount'] );
		$this->assertSame( 2000, $variants[2]['price']['amount'] );
	}

	public function test_variable_product_skips_variations_that_fail_to_fetch(): void {
		// Partial variant lists are better than aborting the whole
		// product. Seed the parent + 3 variations, then null out one
		// to simulate a missing/deleted variation.
		$this->seed_variable_product(
			789,
			'T-Shirt',
			[
				[ 'id' => 101, 'price' => '1000', 'size' => 'Small' ],
				[ 'id' => 102, 'price' => '1500', 'size' => 'Medium' ],
				[ 'id' => 103, 'price' => '2000', 'size' => 'Large' ],
			]
		);
		$this->fake_store_api[ 102 ] = null;  // simulate 404 for this variation

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_789' ] ] );

		$variants = $body['products'][0]['variants'];
		$this->assertCount( 2, $variants );
		// Remaining variants are the ones that fetched successfully.
		$this->assertEquals( 'var_101', $variants[0]['id'] );
		$this->assertEquals( 'var_103', $variants[1]['id'] );
	}
}
