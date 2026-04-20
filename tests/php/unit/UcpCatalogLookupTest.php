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

		// Reset settings between tests so disabled-state tests don't
		// leak. Stub defaults to `enabled => yes`.
		WC_AI_Syndication::$test_settings = [];

		$this->fake_store_api = [];

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( string $single, string $plural, int $number ): string => $number === 1 ? $single : $plural
		);

		// Default stub for `seller.name` in the seller block every
		// product emits (see build_seller()). `wp_strip_all_tags` is
		// covered by the bootstrap polyfill; `html_entity_decode` is
		// a native PHP function that runs fine on the stubbed name.
		Functions\when( 'get_bloginfo' )->alias(
			static fn( string $key = '' ): string => 'name' === $key ? 'Example Store' : ''
		);

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

	/**
	 * Invoke the handler expecting an error response. Asserts the
	 * UCP-envelope error shape: a WP_REST_Response with the expected
	 * HTTP status + a message carrying the expected error code.
	 *
	 * Validation errors return UCP-shaped bodies (not WP_Error) so
	 * agents see the same envelope on success vs failure.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed> The response body.
	 */
	private function error_lookup( array $body, int $expected_status, string $expected_code ): array {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_catalog_lookup( $this->lookup_request( $body ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( $expected_status, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'ucp', $data, 'Error response must carry UCP envelope' );
		$this->assertSame( [], $data['products'], 'Error response products array is empty' );
		$this->assertArrayHasKey( 'messages', $data );

		$codes = array_column( $data['messages'], 'code' );
		$this->assertContains(
			$expected_code,
			$codes,
			'Expected error code not in messages: ' . implode( ', ', $codes )
		);

		return $data;
	}

	// ------------------------------------------------------------------
	// Input validation
	// ------------------------------------------------------------------

	public function test_missing_ids_returns_400(): void {
		$this->error_lookup( [], 400, 'invalid_input' );
	}

	public function test_non_array_ids_returns_400(): void {
		$this->error_lookup( [ 'ids' => 'prod_123' ], 400, 'invalid_input' );
	}

	public function test_empty_ids_array_returns_400(): void {
		// Distinct from "missing ids" — the client sent the key but
		// with no IDs. Still malformed; 400 with UCP envelope.
		$this->error_lookup( [ 'ids' => [] ], 400, 'invalid_input' );
	}

	public function test_null_ids_returns_400(): void {
		// `{"ids": null}` is a common JSON-deserializer quirk —
		// explicit null vs. missing key. Some clients emit this when
		// they had no IDs to send. Handler must reject both.
		$this->error_lookup( [ 'ids' => null ], 400, 'invalid_input' );
	}

	public function test_object_ids_returns_400(): void {
		// `{"ids": {}}` — client mis-typed the field as an object
		// instead of an array. Empty stdClass fails `is_array()` so
		// the same path rejects it. The test covers both the object
		// case and the nested-structure case (a dict with keys).
		//
		// Brain\Monkey's get_json_params + PHP JSON decode default
		// (associative = true) would actually turn `{}` into `[]`,
		// but clients using different decoders or non-JSON content
		// types could still deliver stdClass here.
		$this->error_lookup( [ 'ids' => new stdClass() ], 400, 'invalid_input' );

		// Nested-dict: `{"ids": {"first": "prod_1"}}` — agent treating
		// `ids` as a map instead of an array. Array keys are strings,
		// not sequential ints, but `is_array()` IS true here. The
		// handler's foreach would then iterate the values. This is
		// an edge case where `is_array` passes but the semantic is
		// wrong — we still process the values, treating them as if
		// they were a sequential list. Document the current behavior:
		// the values flow through `parse_ucp_id_to_wc_int`, which is
		// defensive against malformed input anyway.
		//
		// Not asserted as a failure path; this test just documents
		// that the dict-keyed case doesn't crash.
		$this->seed_simple_product( 1, 100 );
		$body = $this->successful_lookup(
			[ 'ids' => [ 'first' => 'prod_1' ] ]
		);
		// The one valid ID still resolved.
		$this->assertCount( 1, $body['products'] );
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
	// Variable product expansion (integration with product translator + fetch_variations_for)
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
		$this->assertSame( 1000, $variants[0]['list_price']['amount'] );
		$this->assertSame( 2000, $variants[2]['list_price']['amount'] );
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

		// Agents must see a `partial_variants` warning so they can
		// distrust the variants list — otherwise price_range (computed
		// by WC from ALL variations) would disagree with variants[]
		// (reduced by our failed fetch) with no signal.
		$this->assertArrayHasKey( 'messages', $body );
		$partial = array_filter(
			$body['messages'],
			static fn( array $m ): bool => 'partial_variants' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $partial );
		$msg = array_values( $partial )[0];
		$this->assertEquals( 'warning', $msg['type'] );
		$this->assertEquals( 'advisory', $msg['severity'] );
	}

	/**
	 * Seed a variable product with N perfectly-fetchable variations.
	 * Used by the cap tests to pack parent products with known counts.
	 */
	private function seed_variable_with_n_variations( int $parent_id, int $count ): void {
		$variation_refs = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$vid              = $parent_id * 10 + $i;  // stable per-parent offset
			$variation_refs[] = [
				'id'         => $vid,
				'attributes' => [ [ 'name' => 'N', 'value' => (string) $i ] ],
			];
			$this->fake_store_api[ $vid ] = [
				'id'                => $vid,
				'name'              => 'Base',
				'short_description' => '',
				'is_in_stock'       => true,
				'prices'            => [
					'price'               => '100',
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				],
				'attributes'        => [ [ 'name' => 'N', 'value' => (string) $i ] ],
			];
		}

		$this->fake_store_api[ $parent_id ] = [
			'id'                => $parent_id,
			'name'              => 'Base',
			'type'              => 'variable',
			'short_description' => '',
			'prices'            => [
				'price'         => '100',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '100', 'max_amount' => '100' ],
			],
			'variations'        => $variation_refs,
		];
	}

	public function test_variations_capped_at_max_per_product(): void {
		// Defensive against N+1 amplification: a variable product with
		// 200 variations would otherwise trigger 200 internal Store API
		// dispatches. The handler caps variant expansion at
		// MAX_VARIATIONS_PER_PRODUCT (currently 50) via array_slice on
		// the variations pointer list. Agents needing the full set can
		// fetch specific variations by ID via a follow-up lookup.
		$cap = WC_AI_Syndication_UCP_REST_Controller::MAX_VARIATIONS_PER_PRODUCT;
		$this->seed_variable_with_n_variations( 900, $cap + 10 );

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_900' ] ] );

		// Handler emits the first N variations in the order the parent
		// product listed them — not all cap+10.
		$this->assertCount( $cap, $body['products'][0]['variants'] );
		$this->assertEquals( 'var_9000', $body['products'][0]['variants'][0]['id'] );

		// Cap overage MUST surface as a `partial_variants` warning so
		// agents don't silently receive a short list. Without this,
		// a product with 200 variations would look the same to the
		// agent as a product with 50 — silent data loss.
		$this->assertArrayHasKey( 'messages', $body );
		$partial = array_filter(
			$body['messages'],
			static fn( array $m ): bool => 'partial_variants' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $partial );
	}

	public function test_variations_at_exactly_the_cap_with_zero_failures_emits_no_warning(): void {
		// Off-by-one: exactly MAX_VARIATIONS_PER_PRODUCT with all
		// fetches succeeding = skipped count of zero = no
		// `partial_variants` warning emitted. This is the boundary
		// that separates "full set" from "partial set."
		$cap = WC_AI_Syndication_UCP_REST_Controller::MAX_VARIATIONS_PER_PRODUCT;
		$this->seed_variable_with_n_variations( 901, $cap );

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_901' ] ] );

		$this->assertCount( $cap, $body['products'][0]['variants'] );

		// No messages at all — response is "clean" at the boundary.
		$partial = array_filter(
			$body['messages'] ?? [],
			static fn( array $m ): bool => 'partial_variants' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 0, $partial );
	}

	// ------------------------------------------------------------------
	// DoS caps + syndication-disabled gate
	// ------------------------------------------------------------------

	public function test_rejects_ids_array_exceeding_limit(): void {
		// Defensive against unauthenticated callers amplifying one
		// request into thousands of internal dispatches. Each ID in
		// the lookup array drives a GET /wc/store/v1/products/{id}
		// dispatch — cap it at MAX_IDS_PER_LOOKUP.
		$cap = WC_AI_Syndication_UCP_REST_Controller::MAX_IDS_PER_LOOKUP;
		$ids = [];
		for ( $i = 0; $i < $cap + 1; $i++ ) {
			$ids[] = 'prod_' . ( 1000 + $i );
		}

		$this->error_lookup( [ 'ids' => $ids ], 400, 'invalid_input' );
	}

	public function test_accepts_ids_array_at_exactly_the_limit(): void {
		// Off-by-one check: exactly MAX_IDS_PER_LOOKUP should succeed.
		$cap = WC_AI_Syndication_UCP_REST_Controller::MAX_IDS_PER_LOOKUP;
		$ids = [];
		for ( $i = 0; $i < $cap; $i++ ) {
			$ids[] = 'prod_' . ( 5000 + $i );
		}

		$body = $this->successful_lookup( [ 'ids' => $ids ] );

		// None of the IDs are seeded, so all return not_found — but the
		// handler didn't reject the input shape, which is the assertion.
		$this->assertCount( $cap, $body['messages'] );
	}

	public function test_disabled_syndication_returns_503_ucp_disabled(): void {
		// Pausing syndication must cut off UCP catalog access. Routes
		// stay registered (rewrite-flush discipline); the handler
		// gates access here and returns a UCP-envelope error response.
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'no' ];

		$this->error_lookup( [ 'ids' => [ 'prod_123' ] ], 503, 'ucp_disabled' );
	}

	// ------------------------------------------------------------------
	// Regression: WC Store API internal-dispatch returns nested stdClass
	// ------------------------------------------------------------------

	public function test_stdclass_nested_product_data_is_normalized_to_array(): void {
		// In production, `rest_do_request` returns WC Store API data
		// with nested structures (prices, attributes, categories) as
		// `stdClass` objects — NOT associative arrays. The translator
		// would fatal with "Cannot use object of type stdClass as
		// array" on `$prices['currency_code']` style access.
		//
		// Tests never exercised this because the fake always returned
		// pre-shaped assoc arrays. This test seeds a product with
		// nested stdClass (matching real Store API internal behavior)
		// and asserts the handler's normalize step converts it before
		// the translator sees it.
		//
		// Root bug was observed on pierorocca.com with 1.3.0 — every
		// real-product lookup 500'd until the normalize step was added
		// in 1.3.1.
		$prices = new stdClass();
		$prices->price               = '42400';
		$prices->regular_price       = '42400';
		$prices->currency_code       = 'EUR';
		$prices->currency_minor_unit = 2;
		$prices->price_range         = null;

		$this->fake_store_api[ 2963 ] = [
			'id'                => 2963,
			'name'              => 'Deposit',
			'slug'              => 'deposit',
			'type'              => 'simple',
			'short_description' => '<p>A product that requires an up front deposit</p>',
			'is_in_stock'       => true,
			'prices'            => $prices,  // stdClass, not array — this is what triggers the bug
			'categories'        => [],
			'images'            => [],
		];

		$body = $this->successful_lookup( [ 'ids' => [ 'prod_2963' ] ] );

		// Handler didn't fatal AND the price fields came through.
		$this->assertCount( 1, $body['products'] );
		$product = $body['products'][0];

		$this->assertEquals( 'prod_2963', $product['id'] );
		$this->assertEquals( 'Deposit', $product['title'] );
		$this->assertSame( 42400, $product['price_range']['min']['amount'] );
		$this->assertEquals( 'EUR', $product['price_range']['min']['currency'] );

		// Variant price should also reflect the normalized stdClass.
		$variant = $product['variants'][0];
		$this->assertSame( 42400, $variant['list_price']['amount'] );
		$this->assertEquals( 'EUR', $variant['list_price']['currency'] );
	}
}
