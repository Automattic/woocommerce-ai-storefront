<?php
/**
 * Tests for WC_AI_Storefront_UCP_REST_Controller::handle_catalog_lookup.
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
 * @package WooCommerce_AI_Storefront
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
	private array $fake_store_api = array();

	/**
	 * Per-product-id count of rest_do_request dispatches. Lets
	 * dedup tests assert that a duplicate-ID payload maps to a
	 * single Store API round-trip rather than N.
	 *
	 * @var array<int, int>
	 */
	private array $store_api_dispatch_counts = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset settings between tests so disabled-state tests don't
		// leak. Stub defaults to `enabled => yes`.
		WC_AI_Storefront::$test_settings = array();

		$this->fake_store_api            = array();
		$this->store_api_dispatch_counts = array();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( string $single, string $plural, int $number ): string => $number === 1 ? $single : $plural
		);

		// `get_woocommerce_currency` is called by the multi-currency
		// helper when computing the base currency. The product URL
		// stamping path (`stamp_currency_query`) reaches the helper
		// whenever the agent sends `context.currency`, even when the
		// hint is rejected as out-of-accepted-set. Stub once here so
		// all lookup tests can exercise that path without cross-test
		// cache contamination errors.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		// Stubs for the P-10 term/meta cache priming added in handle_catalog_lookup.
		// The priming calls fire before the product loop for any non-empty
		// validated ID list; they're no-ops in unit tests (no real WP DB).
		Functions\when( 'update_object_term_cache' )->justReturn( true );
		Functions\when( 'update_postmeta_cache' )->justReturn( true );

		// Minimal `add_query_arg()` stub for the TWO signatures the
		// controller exercises in this handler:
		//
		//   - add_query_arg( array $args, string $url )           — UTM attribution
		//   - add_query_arg( string $key, string $value, string $url ) — stamp_currency_query
		//
		// In both cases: parse out any existing query string on the URL,
		// merge in the new args (later args overwrite earlier on key
		// collision), and reassemble. The 3-arg form is exercised by
		// `WC_AI_Storefront_Multi_Currency::stamp_currency_query()`
		// which appends a single `currency=XXX` pair before the UTM
		// attribution call stamps utm_source/utm_id/etc on top.
		Functions\when( 'add_query_arg' )->alias(
			static function ( $arg1, $arg2 = null, $arg3 = null ): string {
				if ( is_array( $arg1 ) ) {
					$args = $arg1;
					$url  = (string) $arg2;
				} else {
					$args = array( (string) $arg1 => (string) $arg2 );
					$url  = (string) $arg3;
				}
				$parts    = wp_parse_url( $url );
				$existing = array();
				if ( isset( $parts['query'] ) ) {
					parse_str( $parts['query'], $existing );
				}
				$merged       = array_merge( $existing, $args );
				$query_string = http_build_query( $merged );
				$rebuilt      = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
					. ( $parts['host'] ?? '' )
					. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
					. ( $parts['path'] ?? '' );
				return '' !== $query_string ? $rebuilt . '?' . $query_string : $rebuilt;
			}
		);

		// Default stub for `seller.name` in the seller block every
		// product emits (see build_seller()). `wp_strip_all_tags` is
		// covered by the bootstrap polyfill; `html_entity_decode` is
		// a native PHP function that runs fine on the stubbed name.
		Functions\when( 'get_bloginfo' )->alias(
			static fn( string $key = '' ): string => 'name' === $key ? 'Example Store' : ''
		);

		// Stub the catalog_envelope dependency's PROTOCOL_VERSION
		// access — UcpEnvelope reads WC_AI_Storefront_Ucp::PROTOCOL_VERSION,
		// which is a const defined on the class and resolves fine at test
		// time since that class is loaded by the bootstrap.

		// Route rest_do_request through our fake_store_api map.
		// The controller only dispatches single-product requests:
		//   - GET /wc/store/v1/products/{id}
		//     Used for both parent products and variation IDs. The
		//     collection endpoint cannot be used for variations because
		//     WC Store API filters the collection to post_type='product',
		//     which excludes post_type='product_variation'. Per-ID fetches
		//     work for both types.
		$api    = &$this->fake_store_api;
		$counts = &$this->store_api_dispatch_counts;
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use ( &$api, &$counts ) {
				$route = $request->get_route();

				// Single-product route — handles both products and variations.
				if ( preg_match( '#/wc/store/v1/products/(\d+)$#', $route, $m ) ) {
					$id            = (int) $m[1];
					$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;

					if ( ! array_key_exists( $id, $api ) || null === $api[ $id ] ) {
						return new WP_REST_Response(
							array( 'code' => 'woocommerce_rest_product_invalid_id' ),
							404
						);
					}

					return new WP_REST_Response( $api[ $id ], 200 );
				}

				// Unexpected route — fail loudly.
				return new WP_REST_Response( null, 500 );
			}
		);
	}

	protected function tearDown(): void {
		// Reset the multi-currency helper's static cache so apply_filters
		// stubs from one test don't bleed accepted-currency state into
		// the next.
		WC_AI_Storefront_Multi_Currency::reset_cache();
		$GLOBALS['_mc_test_double']     = null;
		$GLOBALS['_mc_throw']           = false;
		$GLOBALS['_mc_feature_enabled'] = true;
		unset(
			$GLOBALS['_mc_initial_selected'],
			$GLOBALS['_mc_selected_currency'],
			$GLOBALS['_mc_update_calls']
		);
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
		$this->fake_store_api[ $id ] = array(
			'id'                => $id,
			'name'              => $name,
			'slug'              => strtolower( str_replace( ' ', '-', $name ) ),
			'permalink'         => 'https://example.com/product/' . $id,
			'type'              => 'simple',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => array(
				'price'               => '2500',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			),
		);
	}

	/**
	 * Seed a variable product + its variations at the given IDs.
	 *
	 * @param array<int, array{id: int, price: string, size: string, is_purchasable?: bool}> $variation_specs
	 */
	private function seed_variable_product(
		int $parent_id,
		string $name,
		array $variation_specs,
		string $type = 'variable'
	): void {
		$variation_refs = array();
		foreach ( $variation_specs as $spec ) {
			$variation_refs[] = array(
				'id'         => $spec['id'],
				'attributes' => array(
					array(
						'name'  => 'Size',
						'value' => $spec['size'],
					),
				),
			);

			$this->fake_store_api[ $spec['id'] ] = array(
				'id'                => $spec['id'],
				'name'              => $name,
				'short_description' => '',
				'is_in_stock'       => true,
				'is_purchasable'    => $spec['is_purchasable'] ?? true,
				'prices'            => array(
					'price'               => $spec['price'],
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				),
				'attributes'        => array(
					array(
						'name'  => 'Size',
						'value' => $spec['size'],
					),
				),
			);
		}

		$this->fake_store_api[ $parent_id ] = array(
			'id'                => $parent_id,
			'name'              => $name,
			'type'              => $type,
			'short_description' => '',
			'prices'            => array(
				'price'               => '1000',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
				'price_range'         => array(
					'min_amount' => '1000',
					'max_amount' => '2000',
				),
			),
			'variations'        => $variation_refs,
		);
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
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
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
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_catalog_lookup( $this->lookup_request( $body ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( $expected_status, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'ucp', $data, 'Error response must carry UCP envelope' );
		$this->assertSame( array(), $data['products'], 'Error response products array is empty' );
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
		$this->error_lookup( array(), 400, 'invalid_input' );
	}

	public function test_non_array_ids_returns_400(): void {
		$this->error_lookup( array( 'ids' => 'prod_123' ), 400, 'invalid_input' );
	}

	public function test_empty_ids_array_returns_400(): void {
		// Distinct from "missing ids" — the client sent the key but
		// with no IDs. Still malformed; 400 with UCP envelope.
		$this->error_lookup( array( 'ids' => array() ), 400, 'invalid_input' );
	}

	public function test_null_ids_returns_400(): void {
		// `{"ids": null}` is a common JSON-deserializer quirk —
		// explicit null vs. missing key. Some clients emit this when
		// they had no IDs to send. Handler must reject both.
		$this->error_lookup( array( 'ids' => null ), 400, 'invalid_input' );
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
		$this->error_lookup( array( 'ids' => new stdClass() ), 400, 'invalid_input' );

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
			array( 'ids' => array( 'first' => 'prod_1' ) )
		);
		// The one valid ID still resolved.
		$this->assertCount( 1, $body['products'] );
	}

	/**
	 * UCP conformance: a batch over the per-request limit MUST be rejected with
	 * `request_too_large` (HTTP 400), not generic invalid_input. MAX_IDS_PER_LOOKUP
	 * is 100; the validator runs on the raw ids before dedupe.
	 */
	public function test_post_lookup_over_cap_returns_request_too_large(): void {
		$ids = array_map( static fn( int $i ) => 'prod_' . $i, range( 1, 101 ) );
		$this->error_lookup( array( 'ids' => $ids ), 400, 'request_too_large' );
	}

	// ------------------------------------------------------------------
	// Happy path: simple products
	// ------------------------------------------------------------------

	public function test_single_simple_product_translates_and_returns(): void {
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_123' ) ) );

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
			array( 'ids' => array( 'prod_200', 'prod_100', 'prod_300' ) )
		);

		$this->assertEquals( 'Alpha', $body['products'][0]['title'] );
		$this->assertEquals( 'Beta', $body['products'][1]['title'] );
		$this->assertEquals( 'Gamma', $body['products'][2]['title'] );
	}

	public function test_response_wraps_products_in_catalog_envelope(): void {
		$this->seed_simple_product( 123 );

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_123' ) ) );

		$this->assertArrayHasKey( 'ucp', $body );
		$this->assertArrayHasKey( 'capabilities', $body['ucp'] );
		$this->assertArrayHasKey(
			'dev.ucp.shopping.catalog.lookup',
			$body['ucp']['capabilities']
		);
	}

	// ------------------------------------------------------------------
	// inputs[] echo + deduplication (PR K)
	// ------------------------------------------------------------------

	public function test_response_correlates_inputs_via_per_variant_inputs_array(): void {
		// UCP `catalog_lookup.json#/$defs/lookup_variant` (2026-04-08)
		// requires every emitted variant to carry `inputs[]` with at
		// least one `input_correlation` entry — `{id, match}` mapping
		// the request ID that resolved to this variant. We only
		// accept product IDs as lookup inputs; the resolution is
		// `featured` (server-selected representative).
		//
		// The pre-0.12.0 envelope-level `inputs` echo was a non-spec
		// extension and is now dropped (see commit 6 of #349).
		$this->seed_simple_product( 100, 'Alpha' );
		$this->seed_simple_product( 200, 'Beta' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_100', 'prod_200' ) )
		);

		$this->assertArrayNotHasKey( 'inputs', $body );

		$this->assertCount( 2, $body['products'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_100',
					'match' => 'featured',
				),
			),
			$body['products'][0]['variants'][0]['inputs']
		);
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_200',
					'match' => 'featured',
				),
			),
			$body['products'][1]['variants'][0]['inputs']
		);
	}

	public function test_duplicate_ids_are_deduplicated_to_single_fetch_and_product(): void {
		// `rest_do_request` is the expensive step — a repeated ID
		// should not cause us to dispatch the same Store API call
		// twice. Assert on both the observable output (1 product) AND
		// the internal dispatch count — the latter guards against a
		// future refactor that dedupes *after* fetching (which would
		// fix outputs while regressing the performance goal of
		// O(unique) not O(request)).
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_123', 'prod_123', 'prod_123' ) )
		);

		$this->assertCount( 1, $body['products'] );
		$this->assertEquals( 'prod_123', $body['products'][0]['id'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_123',
					'match' => 'featured',
				),
			),
			$body['products'][0]['variants'][0]['inputs']
		);
		$this->assertSame(
			1,
			$this->store_api_dispatch_counts[123] ?? 0,
			'Three identical IDs must produce exactly one Store API dispatch (O(unique), not O(request)).'
		);
	}

	public function test_prefixed_and_bare_ids_for_same_product_are_deduplicated(): void {
		// Both `prod_123` and bare `123` resolve to WC product 123
		// (see parse_ucp_id_to_wc_int — prefix-stripping is lenient).
		// The dedup key is the parsed int, so both forms collapse to
		// a single product. First-occurrence echo wins as the input
		// correlation `id`.
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_123', '123', 'var_123_default' ) )
		);

		$this->assertCount( 1, $body['products'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_123',
					'match' => 'featured',
				),
			),
			$body['products'][0]['variants'][0]['inputs']
		);
	}

	public function test_var_prefix_input_against_simple_product_correlates_as_featured(): void {
		// Subtle correctness case: `var_<product_id>` is a valid input
		// (parse_ucp_id_to_wc_int strips both `prod_` and `var_`), but
		// when the resolved product is simple, the variant translator
		// emits a synthetic default variant whose id is
		// `var_<product_id>_default` — not `var_<product_id>`. The two
		// strings differ, so the input did NOT directly identify the
		// emitted variant id and the correlation must be `featured`,
		// not `exact`. A prefix-only check ("input starts with `var_`")
		// would misclaim exact precision here.
		$this->seed_simple_product( 456, 'Widget' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'var_456' ) )
		);

		$this->assertCount( 1, $body['products'] );
		$this->assertSame( 'var_456_default', $body['products'][0]['variants'][0]['id'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'var_456',
					'match' => 'featured',
				),
			),
			$body['products'][0]['variants'][0]['inputs']
		);
	}

	public function test_input_correlates_as_exact_when_directly_matches_variant_id(): void {
		// `var_<id>_default` is the actual emitted id of a synthetic
		// default variant. When the agent submits that exact string,
		// the input directly identifies the variant — correlation
		// must be `exact`. This is the only simple-product input
		// shape that produces an exact match (the input echo and the
		// variant id are byte-equal).
		$this->seed_simple_product( 456, 'Widget' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'var_456_default' ) )
		);

		$this->assertCount( 1, $body['products'] );
		$this->assertSame( 'var_456_default', $body['products'][0]['variants'][0]['id'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'var_456_default',
					'match' => 'exact',
				),
			),
			$body['products'][0]['variants'][0]['inputs']
		);
	}

	public function test_prod_input_against_variable_product_features_first_variant_by_menu_order(): void {
		// Variable product, product-level input (`prod_<parent>`), no
		// merchant-set `_default_attributes`.
		//
		// Per #369's locked design (verified against `product.json`
		// verbatim — "First item is the featured variant for listings"):
		//   - Exactly ONE variant gets `match: featured` — the first
		//     variation by menu_order, which is `variants[0]` since the
		//     Store API returns variations in menu_order.
		//   - Sibling variants emit with `inputs: [{id: ...}]` (no
		//     `match` field — spec-clean per `input_correlation.json`
		//     where match is optional).
		//
		// Pre-#369 behavior featured EVERY variant indiscriminately —
		// spec-legal but goes against UCP's "one featured per product"
		// design expectation in the operations comparison table.
		$this->seed_variable_product(
			456,
			'Long Sleeve Tee',
			array(
				array(
					'id'    => 100,
					'price' => '2500',
					'size'  => 'S',
				),
				array(
					'id'    => 200,
					'price' => '2500',
					'size'  => 'M',
				),
			)
		);

		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_456' ) )
		);

		$this->assertCount( 1, $body['products'] );
		$this->assertCount( 2, $body['products'][0]['variants'] );

		// variants[0] is the featured one — match: featured plus
		// position 0 (the two signals must agree).
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_456',
					'match' => 'featured',
				),
			),
			$body['products'][0]['variants'][0]['inputs'],
			'variants[0] must carry the featured marker.'
		);
		// Sibling: id correlation present, no `match` field.
		$this->assertSame(
			array( array( 'id' => 'prod_456' ) ),
			$body['products'][0]['variants'][1]['inputs'],
			'Sibling variants must emit inputs[] with just `id`, no `match`.'
		);
	}

	public function test_inputs_stamping_skips_non_array_variants_from_filter(): void {
		// Resilience guard: `$final_product` flows through the
		// `wc_ai_storefront_ucp_product` filter before the inputs[]
		// stamping loop runs. A third-party callback could (by
		// accident or otherwise) leave non-arrays in the variants
		// list — string, null, stdClass — which would fatal in PHP
		// 8+ on `$variant['id']` access. The stamping loop must skip
		// non-array entries so a broken plugin doesn't take down
		// every catalog/lookup response.
		$this->seed_simple_product( 456, 'Widget' );

		// Hook in a filter that injects a malformed entry alongside
		// the legitimate variant. Mockery filter binding via Brain
		// Monkey's Filters API.
		\Brain\Monkey\Filters\expectApplied( 'wc_ai_storefront_ucp_product' )
			->andReturnUsing(
				static function ( $product ) {
					if ( isset( $product['variants'] ) && is_array( $product['variants'] ) ) {
						array_unshift( $product['variants'], 'string instead of variant array' );
					}
					return $product;
				}
			);

		// Should not fatal. Without the guard, `$variant['id']` on
		// the string entry would throw "Cannot access offset" in PHP 8+.
		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_456' ) )
		);

		$this->assertCount( 1, $body['products'] );
		// The malformed entry is preserved as-is (no inputs[] stamped);
		// the legitimate variant got its inputs[] correlation. After
		// #369 the featured-variant reordering normally moves the
		// featured variant to index 0 — but the malformed entry CAN'T
		// be reordered because its id can't be read, so position layout
		// depends on whether the legit variant happened to be featured.
		// Locate the legitimate variant rather than depending on order.
		$variants          = $body['products'][0]['variants'];
		$legit_variant     = null;
		$malformed_present = false;
		foreach ( $variants as $v ) {
			if ( is_array( $v ) ) {
				$legit_variant = $v;
			} elseif ( 'string instead of variant array' === $v ) {
				$malformed_present = true;
			}
		}
		$this->assertTrue( $malformed_present, 'Malformed entry should be preserved as-is.' );
		$this->assertIsArray( $legit_variant, 'Legitimate variant should remain in variants[].' );
		// The legit variant is the only purchasable entry — so it's
		// the featured one (sole variant feature path).
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_456',
					'match' => 'featured',
				),
			),
			$legit_variant['inputs']
		);
	}

	public function test_message_path_points_at_raw_request_index_not_deduped(): void {
		// Request: [found_A, found_A, missing, found_B]
		// Raw `ids[]` indices:       0,        1,        2,       3
		// Deduped work list:    [found_A, missing, found_B] at deduped
		// indices 0, 1, 2.
		//
		// `messages[].path` MUST reference the raw request index, not
		// the deduped one — agents cross-reference path against the
		// JSON body they sent. Pre-fix this emitted `$.ids[1]` (deduped
		// position of `missing`), which is wrong: deduped index 1
		// resolves against raw[1] = found_A in the agent's request,
		// pointing at the WRONG element. Post-fix it emits `$.ids[2]`
		// (raw index of the first `missing` occurrence).
		$this->seed_simple_product( 100, 'Alpha' );
		$this->seed_simple_product( 300, 'Gamma' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_100', 'prod_100', 'prod_missing', 'prod_300' ) )
		);

		$this->assertCount( 2, $body['products'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertEquals( '$.ids[2]', $body['messages'][0]['path'] );
		$this->assertEquals( 'not_found', $body['messages'][0]['code'] );
		$this->assertArrayHasKey( 'content', $body['messages'][0] );
	}

	public function test_message_path_uses_first_raw_index_for_repeated_missing_id(): void {
		// Request: [found_A, missing, found_B, missing]
		// Raw `ids[]` indices: 0, 1, 2, 3.
		// `missing` appears twice (raw 1 and raw 3) — dedup collapses
		// to one entry. The emitted path must point at the FIRST raw
		// occurrence (1), not the second (3) and not the deduped slot.
		$this->seed_simple_product( 100, 'Alpha' );
		$this->seed_simple_product( 200, 'Bravo' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_100', 'prod_missing', 'prod_200', 'prod_missing' ) )
		);

		$this->assertCount( 2, $body['products'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertEquals( '$.ids[1]', $body['messages'][0]['path'] );
	}

	public function test_repeated_malformed_ids_are_deduplicated(): void {
		// Malformed inputs dedupe by identical raw echo, so two
		// instances of the same garbage string collapse. Two
		// different garbage strings stay distinct. Each unresolvable
		// input emits one message; per-variant `inputs[]` doesn't
		// apply because no products were resolved.
		// Raw `ids[]` indices: [0=bogus, 1=bogus(dup), 2=other_bogus].
		// Paths point at the FIRST raw occurrence of each deduped
		// entry (raw 0 for `bogus`, raw 2 for `other_bogus`).
		$body = $this->successful_lookup(
			array( 'ids' => array( 'bogus', 'bogus', 'other_bogus' ) )
		);

		$this->assertEmpty( $body['products'] );
		$this->assertCount( 2, $body['messages'] );
		$this->assertEquals( '$.ids[0]', $body['messages'][0]['path'] );
		$this->assertEquals( '$.ids[2]', $body['messages'][1]['path'] );
	}

	public function test_boolean_ids_dedupe_by_distinct_echo_forms(): void {
		// Regression guard: `(string) false` is `""` in PHP, so
		// booleans echoed via naive string cast would collide with
		// an actual empty-string id in the dedup key. Internal echo
		// is "true"/"false" explicitly so each boolean stays
		// uniquely addressable for dedup. No products resolve from
		// booleans, so the assertion is on `messages` count.
		$body = $this->successful_lookup(
			array( 'ids' => array( false, true, false ) )
		);

		$this->assertEmpty( $body['products'] );
		// Two distinct booleans → two not-found messages, duplicate
		// `false` deduped against the first.
		$this->assertCount( 2, $body['messages'] );
	}

	public function test_non_scalar_ids_dedupe_by_stable_distinguishable_forms(): void {
		// Arrays/objects/null in ids[] can't resolve. Distinct
		// non-scalars must remain individually addressable so dedup
		// doesn't collapse them. Three distinct echo forms — null,
		// empty array, nested array — with the second null deduped
		// against the first → 3 unresolved messages.
		// Raw `ids[]` indices: [0=null, 1=[], 2=null(dup), 3=nested].
		// Nested-array message points at raw 3 (its actual position in
		// the request), NOT deduped slot 2.
		\Brain\Monkey\Functions\when( 'wp_json_encode' )->alias(
			static fn( $v ): string|false => json_encode( $v )
		);

		$body = $this->successful_lookup(
			array( 'ids' => array( null, array(), null, array( 'nested' => 'obj' ) ) )
		);

		$this->assertEmpty( $body['products'] );
		$this->assertCount( 3, $body['messages'] );
		$this->assertEquals( '$.ids[0]', $body['messages'][0]['path'] );
		$this->assertEquals( '$.ids[1]', $body['messages'][1]['path'] );
		$this->assertEquals( '$.ids[3]', $body['messages'][2]['path'] );
	}

	// ------------------------------------------------------------------
	// Not-found handling
	// ------------------------------------------------------------------

	public function test_missing_product_emits_not_found_message(): void {
		// No seeded product — store API returns 404.
		$body = $this->successful_lookup( array( 'ids' => array( 'prod_999' ) ) );

		$this->assertEquals( array(), $body['products'] );
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
			array( 'ids' => array( 'prod_100', 'prod_200', 'prod_300' ) )
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

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_123' ) ) );

		$this->assertArrayNotHasKey( 'messages', $body );
	}

	// ------------------------------------------------------------------
	// Malformed IDs
	// ------------------------------------------------------------------

	public function test_non_string_id_treated_as_not_found(): void {
		// If an agent sends a number instead of a string, don't
		// crash — just report it as not-found at the right path.
		$body = $this->successful_lookup( array( 'ids' => array( 123 ) ) );

		$this->assertEquals( array(), $body['products'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertEquals( '$.ids[0]', $body['messages'][0]['path'] );
	}

	public function test_id_string_with_no_numeric_portion_is_not_found(): void {
		// "prod_abc" → stripped to "abc" → (int) → 0 → treated as miss.
		$body = $this->successful_lookup( array( 'ids' => array( 'prod_abc' ) ) );

		$this->assertCount( 1, $body['messages'] );
		$this->assertEquals( 'not_found', $body['messages'][0]['code'] );
	}

	public function test_id_with_default_suffix_strips_to_parent_id(): void {
		// `var_123_default` = synthesized variant for simple product 123.
		// Lookup should resolve to product 123 (the parent), not 404.
		// PHP's (int) cast truncates at the first non-numeric char, so
		// "123_default" → 123.
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup( array( 'ids' => array( 'var_123_default' ) ) );

		$this->assertCount( 1, $body['products'] );
		$this->assertEquals( 'prod_123', $body['products'][0]['id'] );
	}

	public function test_bare_numeric_id_without_prefix_still_works(): void {
		// The prefix strip is a regex anchored with `^(prod_|var_)`;
		// without a prefix it's a no-op. "123" → 123 still resolves.
		// This matches the plan's "lenient" v1 posture.
		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup( array( 'ids' => array( '123' ) ) );

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
			array(
				array(
					'id'    => 101,
					'price' => '1000',
					'size'  => 'Small',
				),
				array(
					'id'    => 102,
					'price' => '1500',
					'size'  => 'Medium',
				),
				array(
					'id'    => 103,
					'price' => '2000',
					'size'  => 'Large',
				),
			)
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_789' ) ) );

		$this->assertCount( 1, $body['products'] );
		$variants = $body['products'][0]['variants'];

		$this->assertCount( 3, $variants );
		$this->assertEquals( 'var_101', $variants[0]['id'] );
		$this->assertEquals( 'Small', $variants[0]['title'] );
		$this->assertSame( 1000, $variants[0]['price']['amount'] );
		$this->assertSame( 2000, $variants[2]['price']['amount'] );
	}

	public function test_variable_product_with_default_attributes_features_resolved_variation(): void {
		// #369 Fix #3a: when the merchant has set `_default_attributes`
		// covering every variation axis, the resolved variation is
		// featured (both as `match: featured` in inputs[] AND as
		// `variants[0]` via reordering). Siblings emit with inputs[]
		// carrying just `id` and no `match` field.
		$this->seed_variable_product(
			456,
			'Long Sleeve Tee',
			array(
				array(
					'id'    => 100,
					'price' => '2500',
					'size'  => 'S',
				),
				array(
					'id'    => 200,
					'price' => '3000',
					'size'  => 'M',
				),
				array(
					'id'    => 300,
					'price' => '3500',
					'size'  => 'L',
				),
			)
		);
		// Mark "M" as the merchant default in the parent's attributes
		// (mirrors Store API's `default: true` on the term).
		$this->fake_store_api[456]['attributes'] = array(
			array(
				'name'           => 'Size',
				'taxonomy'       => 'pa_size',
				'has_variations' => true,
				'terms'          => array(
					array(
						'name'    => 'S',
						'slug'    => 'S',
						'default' => false,
					),
					array(
						'name'    => 'M',
						'slug'    => 'M',
						'default' => true,
					),
					array(
						'name'    => 'L',
						'slug'    => 'L',
						'default' => false,
					),
				),
			),
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_456' ) ) );

		$variants = $body['products'][0]['variants'];
		$this->assertCount( 3, $variants );
		// variants[0] is the M variant (id=200), not S (id=100, first
		// by menu_order). The merchant signal overrides the
		// menu_order fallback.
		$this->assertSame( 'var_200', $variants[0]['id'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_456',
					'match' => 'featured',
				),
			),
			$variants[0]['inputs']
		);
		// Siblings (S and L) emit with just `id` — no match field.
		$this->assertSame(
			array( array( 'id' => 'prod_456' ) ),
			$variants[1]['inputs']
		);
		$this->assertSame(
			array( array( 'id' => 'prod_456' ) ),
			$variants[2]['inputs']
		);
	}

	public function test_simple_product_emits_single_featured_variant(): void {
		// #369 regression guard: the featured-variant rewrite shouldn't
		// disturb simple products. They emit a single synthesized
		// default variant which IS the sole representative — `match:
		// featured` via the sole-variant fall-through path.
		$this->seed_simple_product( 600, 'Coffee Beans' );

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_600' ) ) );

		$variants = $body['products'][0]['variants'];
		$this->assertCount( 1, $variants );
		$this->assertSame( 'var_600_default', $variants[0]['id'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'prod_600',
					'match' => 'featured',
				),
			),
			$variants[0]['inputs']
		);
	}

	public function test_direct_variation_lookup_features_the_synthesized_default(): void {
		// Edge case: agent passes a variation ID directly (`var_520`).
		// The controller resolves `520` → the variation post as a
		// standalone Store API record. `synthesize_default` emits a
		// single variant with id `var_520_default` (the `_default`
		// suffix is the synthesis marker). Since the input string
		// `var_520` differs from the emitted id `var_520_default`, no
		// `exact` match fires — the synthesized variant is featured
		// instead (sole-variant fall-through).
		//
		// Pinned here because the input/output IDs differ in a subtle
		// way that's easy to mis-implement (e.g. stripping the `_default`
		// suffix or matching by prefix). A future refactor that
		// "normalized" the comparison could accidentally mark the
		// variant as `exact` when it shouldn't be — the agent didn't
		// directly identify this variant id, they identified the
		// underlying variation that we then re-emitted under a
		// synthesized default id.
		$this->seed_variable_product(
			500,
			'Long Sleeve Tee',
			array(
				array(
					'id'    => 510,
					'price' => '2500',
					'size'  => 'S',
				),
				array(
					'id'    => 520,
					'price' => '3000',
					'size'  => 'M',
				),
				array(
					'id'    => 530,
					'price' => '3500',
					'size'  => 'L',
				),
			)
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'var_520' ) ) );

		$variants = $body['products'][0]['variants'];
		$this->assertCount( 1, $variants );
		$this->assertSame( 'var_520_default', $variants[0]['id'] );
		// Featured (not exact) — input and emitted ID differ textually.
		$this->assertSame(
			array(
				array(
					'id'    => 'var_520',
					'match' => 'featured',
				),
			),
			$variants[0]['inputs']
		);
	}

	public function test_variable_subscription_variations_pre_fetched_and_expanded(): void {
		// #369 Fix #1: `fetch_variations_for()` was previously gated on
		// strict `'variable' === $type`, which excluded WC Subscriptions'
		// `variable-subscription` extension type. The result: subscription
		// variations silently collapsed to a single synthesized
		// `_default` placeholder, breaking the agent's ability to address
		// individual subscription terms by ID.
		//
		// After the widening, variable-subscription is treated as a
		// first-class variable type — same enumeration path, same shape.
		$this->seed_variable_product(
			890,
			'Subscription Plan',
			array(
				array(
					'id'    => 201,
					'price' => '1000',
					'size'  => '1 month',
				),
				array(
					'id'    => 202,
					'price' => '2500',
					'size'  => '3 months',
				),
				array(
					'id'    => 203,
					'price' => '5000',
					'size'  => '6 months',
				),
				array(
					'id'    => 204,
					'price' => '7500',
					'size'  => '1 year',
				),
			),
			'variable-subscription'
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_890' ) ) );

		$this->assertCount( 1, $body['products'] );
		$variants = $body['products'][0]['variants'];

		// Pre-fix: this would have been 1 (synthesized default).
		// Post-fix: real subscription variations enumerate identically
		// to plain variable products.
		$this->assertCount( 4, $variants );
		$this->assertEquals( 'var_201', $variants[0]['id'] );
		$this->assertEquals( '1 month', $variants[0]['title'] );
		$this->assertSame( 1000, $variants[0]['price']['amount'] );
		$this->assertSame( 7500, $variants[3]['price']['amount'] );
	}

	public function test_variable_product_skips_variations_that_fail_to_fetch(): void {
		// Partial variant lists are better than aborting the whole
		// product. Seed the parent + 3 variations, then null out one
		// to simulate a missing/deleted variation.
		$this->seed_variable_product(
			789,
			'T-Shirt',
			array(
				array(
					'id'    => 101,
					'price' => '1000',
					'size'  => 'Small',
				),
				array(
					'id'    => 102,
					'price' => '1500',
					'size'  => 'Medium',
				),
				array(
					'id'    => 103,
					'price' => '2000',
					'size'  => 'Large',
				),
			)
		);
		$this->fake_store_api[102] = null;  // simulate 404 for this variation

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_789' ) ) );

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
		// UCP `message_warning.json` (2026-04-08) does NOT define
		// `severity` — that's only on errors. Pre-0.12.0 we emitted
		// `severity: advisory` here as a non-spec extension; dropping
		// it keeps strict validators happy.
		$this->assertArrayNotHasKey( 'severity', $msg );
		$this->assertArrayHasKey( 'content', $msg );
	}

	public function test_unpurchasable_variations_are_filtered_from_variants_list(): void {
		// #373: a variation with `is_purchasable: false` (e.g. no price
		// set by the merchant) MUST NOT appear in the catalog response.
		// Handing its ID to an agent would lead to a checkout URL WC
		// refuses to add to cart. Filter happens in
		// `fetch_variations_for()` upstream of the product translator
		// so all three surfaces (catalog response, JSON-LD, checkout-
		// sessions) see the same purchasable-only set.
		$this->seed_variable_product(
			789,
			'T-Shirt',
			array(
				array(
					'id'    => 101,
					'price' => '1000',
					'size'  => 'Small',
				),
				array(
					'id'             => 102,
					'price'          => '0',
					'size'           => 'Medium',
					'is_purchasable' => false,
				),
				array(
					'id'    => 103,
					'price' => '2000',
					'size'  => 'Large',
				),
			)
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_789' ) ) );

		$variants = $body['products'][0]['variants'];
		$this->assertCount( 2, $variants, 'Unpurchasable variant 102 should be dropped.' );

		$emitted_ids = array_map( static fn( array $v ): string => $v['id'], $variants );
		$this->assertContains( 'var_101', $emitted_ids );
		$this->assertContains( 'var_103', $emitted_ids );
		$this->assertNotContains( 'var_102', $emitted_ids );

		// Unpurchasable filtering must NOT emit `partial_variants` —
		// that signal is reserved for genuine retrieval gaps (cap-
		// truncation, fetch failures, scope-filtering). Filtering an
		// unpurchasable variation is an intentional exclusion and the
		// emitted variants[] set is the complete-and-correct
		// purchasable set. (#373 review)
		$messages = $body['messages'] ?? array();
		$partial  = array_filter(
			$messages,
			static fn( array $m ): bool => 'partial_variants' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 0, $partial, 'partial_variants must not fire for purchasability-only filtering.' );
	}

	public function test_all_unpurchasable_variations_falls_through_to_synthesized_default(): void {
		// #373: when every variation is unpurchasable, the filter
		// produces an empty variations[] array, which trips
		// `extract_variants()`'s synthesize_default fallback. Agents
		// still see a single (degraded) variant rather than a
		// schema-invalid empty list — they can read the entry but
		// won't be handed a workable checkout URL via downstream
		// surfaces.
		$this->seed_variable_product(
			791,
			'Broken Tee',
			array(
				array(
					'id'             => 201,
					'price'          => '0',
					'size'           => 'Small',
					'is_purchasable' => false,
				),
				array(
					'id'             => 202,
					'price'          => '0',
					'size'           => 'Medium',
					'is_purchasable' => false,
				),
			)
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_791' ) ) );

		$variants = $body['products'][0]['variants'];
		$this->assertCount( 1, $variants, 'Synthesized default emitted as the sole variant.' );
		// The synthesized default carries the `_default` suffix per
		// `synthesize_default()`'s convention — distinct from a real
		// `var_<id>` shape so downstream consumers can tell it apart.
		$this->assertStringEndsWith( '_default', $variants[0]['id'] );

		// Even when EVERY variation is dropped, the synthesized-default
		// fallback fires WITHOUT triggering `partial_variants` — the
		// degraded shape is itself the signal, and a warning would be
		// redundant noise. (#373 review)
		$messages = $body['messages'] ?? array();
		$partial  = array_filter(
			$messages,
			static fn( array $m ): bool => 'partial_variants' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 0, $partial );
	}

	/**
	 * Seed a variable product with N perfectly-fetchable variations.
	 * Used by the cap tests to pack parent products with known counts.
	 */
	private function seed_variable_with_n_variations( int $parent_id, int $count ): void {
		$variation_refs = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$vid                          = $parent_id * 10 + $i;  // stable per-parent offset
			$variation_refs[]             = array(
				'id'         => $vid,
				'attributes' => array(
					array(
						'name'  => 'N',
						'value' => (string) $i,
					),
				),
			);
			$this->fake_store_api[ $vid ] = array(
				'id'                => $vid,
				'name'              => 'Base',
				'short_description' => '',
				'is_in_stock'       => true,
				'prices'            => array(
					'price'               => '100',
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				),
				'attributes'        => array(
					array(
						'name'  => 'N',
						'value' => (string) $i,
					),
				),
			);
		}

		$this->fake_store_api[ $parent_id ] = array(
			'id'                => $parent_id,
			'name'              => 'Base',
			'type'              => 'variable',
			'short_description' => '',
			'prices'            => array(
				'price'         => '100',
				'currency_code' => 'USD',
				'price_range'   => array(
					'min_amount' => '100',
					'max_amount' => '100',
				),
			),
			'variations'        => $variation_refs,
		);
	}

	public function test_variations_capped_at_max_per_product(): void {
		// Defensive against N+1 amplification: a variable product with
		// 200 variations would otherwise trigger 200 internal Store API
		// dispatches. The handler caps variant expansion at
		// MAX_VARIATIONS_PER_PRODUCT (currently 50) via array_slice on
		// the variations pointer list. Agents needing the full set can
		// fetch specific variations by ID via a follow-up lookup.
		$cap = WC_AI_Storefront_UCP_REST_Controller::MAX_VARIATIONS_PER_PRODUCT;
		$this->seed_variable_with_n_variations( 900, $cap + 10 );

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_900' ) ) );

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
		$cap = WC_AI_Storefront_UCP_REST_Controller::MAX_VARIATIONS_PER_PRODUCT;
		$this->seed_variable_with_n_variations( 901, $cap );

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_901' ) ) );

		$this->assertCount( $cap, $body['products'][0]['variants'] );

		// No messages at all — response is "clean" at the boundary.
		$partial = array_filter(
			$body['messages'] ?? array(),
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
		$cap = WC_AI_Storefront_UCP_REST_Controller::MAX_IDS_PER_LOOKUP;
		$ids = array();
		for ( $i = 0; $i < $cap + 1; $i++ ) {
			$ids[] = 'prod_' . ( 1000 + $i );
		}

		// UCP REST conformance: over-cap is request_too_large, not invalid_input.
		$this->error_lookup( array( 'ids' => $ids ), 400, 'request_too_large' );
	}

	public function test_accepts_ids_array_at_exactly_the_limit(): void {
		// Off-by-one check: exactly MAX_IDS_PER_LOOKUP should succeed.
		$cap = WC_AI_Storefront_UCP_REST_Controller::MAX_IDS_PER_LOOKUP;
		$ids = array();
		for ( $i = 0; $i < $cap; $i++ ) {
			$ids[] = 'prod_' . ( 5000 + $i );
		}

		$body = $this->successful_lookup( array( 'ids' => $ids ) );

		// None of the IDs are seeded, so all return not_found — but the
		// handler didn't reject the input shape, which is the assertion.
		$this->assertCount( $cap, $body['messages'] );
	}

	public function test_disabled_syndication_returns_503_ucp_disabled(): void {
		// Pausing syndication must cut off UCP catalog access. Routes
		// stay registered (rewrite-flush discipline); the handler
		// gates access here and returns a UCP-envelope error response.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );

		$this->error_lookup( array( 'ids' => array( 'prod_123' ) ), 503, 'ucp_disabled' );
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
		$prices                      = new stdClass();
		$prices->price               = '42400';
		$prices->regular_price       = '42400';
		$prices->currency_code       = 'EUR';
		$prices->currency_minor_unit = 2;
		$prices->price_range         = null;

		$this->fake_store_api[2963] = array(
			'id'                => 2963,
			'name'              => 'Deposit',
			'slug'              => 'deposit',
			'type'              => 'simple',
			'short_description' => '<p>A product that requires an up front deposit</p>',
			'is_in_stock'       => true,
			'prices'            => $prices,  // stdClass, not array — this is what triggers the bug
			'categories'        => array(),
			'images'            => array(),
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_2963' ) ) );

		// Handler didn't fatal AND the price fields came through.
		$this->assertCount( 1, $body['products'] );
		$product = $body['products'][0];

		$this->assertEquals( 'prod_2963', $product['id'] );
		$this->assertEquals( 'Deposit', $product['title'] );
		$this->assertSame( 42400, $product['price_range']['min']['amount'] );
		$this->assertEquals( 'EUR', $product['price_range']['min']['currency'] );

		// Variant price should also reflect the normalized stdClass.
		$variant = $product['variants'][0];
		$this->assertSame( 42400, $variant['price']['amount'] );
		$this->assertEquals( 'EUR', $variant['price']['currency'] );
	}

	// ------------------------------------------------------------------
	// Scope enforcement (0.1.7)
	// ------------------------------------------------------------------
	//
	// `fetch_store_api_product()` calls
	// `WC_AI_Storefront::is_product_syndicated()` BEFORE dispatching
	// the Store API request and returns null when the gate fails.
	// This pins the security regression: an agent supplying raw IDs
	// outside the merchant's `selected_*` scope must NOT receive
	// product data, AND we must not even dispatch a Store API
	// request for the out-of-scope ID (it would otherwise leak
	// metadata via response timing).

	public function test_lookup_returns_null_for_out_of_scope_product_id(): void {
		// `selected` mode: only product 1 is syndicated. Product 99
		// is not in `selected_products`, so the gate must reject it
		// at `fetch_store_api_product` BEFORE rest_do_request is
		// ever invoked for it.
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => array( 1 ),
		);

		// Seed both products in the fake Store API. If the gate
		// were missing, both would resolve to product fixtures.
		$this->seed_simple_product( 1, 'In-scope Widget' );
		$this->seed_simple_product( 99, 'Out-of-scope Widget' );

		$body = $this->successful_lookup(
			array( 'ids' => array( 'prod_1', 'prod_99' ) )
		);

		// In-scope product resolves.
		$this->assertCount( 1, $body['products'] );
		$this->assertEquals( 'prod_1', $body['products'][0]['id'] );
		$this->assertEquals( 'In-scope Widget', $body['products'][0]['title'] );

		// Out-of-scope product produces a not_found message at
		// inputs[1] (where prod_99 lives in the deduped list).
		$this->assertNotEmpty( $body['messages'] );
		$not_found = array_values(
			array_filter(
				$body['messages'],
				static fn( array $m ): bool => 'not_found' === ( $m['code'] ?? '' )
			)
		);
		$this->assertCount( 1, $not_found );
		$this->assertEquals( '$.ids[1]', $not_found[0]['path'] );

		// Crux of the regression test: rest_do_request was NOT
		// invoked for product 99. The dispatch counter only
		// increments for IDs that pass the syndication gate, so
		// product 99 must have zero dispatches even though the
		// fixture is seeded.
		$this->assertSame(
			1,
			$this->store_api_dispatch_counts[1] ?? 0,
			'In-scope product (id=1) should be dispatched exactly once.'
		);
		$this->assertSame(
			0,
			$this->store_api_dispatch_counts[99] ?? 0,
			'Out-of-scope product (id=99) must NOT be dispatched — gate runs before rest_do_request.'
		);
	}

	// ------------------------------------------------------------------
	// Variation fetch dispatch contract
	// ------------------------------------------------------------------

	public function test_variations_each_dispatched_once_via_single_product_route(): void {
		// Each variation must use the single-product route
		// (/wc/store/v1/products/{id}), not the collection endpoint.
		// The collection endpoint filters by post_type='product' and
		// silently excludes post_type='product_variation'.
		$this->seed_variable_product(
			100,
			'Shirt',
			array(
				array(
					'id'    => 101,
					'price' => '1000',
					'size'  => 'S',
				),
				array(
					'id'    => 102,
					'price' => '1500',
					'size'  => 'M',
				),
				array(
					'id'    => 103,
					'price' => '2000',
					'size'  => 'L',
				),
			)
		);

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_100' ) ) );

		$this->assertCount( 3, $body['products'][0]['variants'] );

		// Parent and each variation each dispatched exactly once.
		$this->assertSame(
			1,
			$this->store_api_dispatch_counts[100] ?? 0,
			'Parent product must use the single-product route.'
		);
		$this->assertSame( 1, $this->store_api_dispatch_counts[101] ?? 0 );
		$this->assertSame( 1, $this->store_api_dispatch_counts[102] ?? 0 );
		$this->assertSame( 1, $this->store_api_dispatch_counts[103] ?? 0 );
	}

	public function test_variation_source_order_preserved_after_batch(): void {
		// The batch response may return items in any order; the
		// assembler must re-sort to match the parent's variations list.
		$this->seed_variable_product(
			200,
			'Shoes',
			array(
				array(
					'id'    => 201,
					'price' => '5000',
					'size'  => 'S',
				),
				array(
					'id'    => 202,
					'price' => '5500',
					'size'  => 'M',
				),
				array(
					'id'    => 203,
					'price' => '6000',
					'size'  => 'L',
				),
			)
		);

		$body     = $this->successful_lookup( array( 'ids' => array( 'prod_200' ) ) );
		$variants = $body['products'][0]['variants'];

		$this->assertCount( 3, $variants );
		$this->assertEquals( 'var_201', $variants[0]['id'] );
		$this->assertEquals( 'var_202', $variants[1]['id'] );
		$this->assertEquals( 'var_203', $variants[2]['id'] );
	}

	public function test_all_capped_variations_returned_via_single_product_route(): void {
		// MAX_VARIATIONS_PER_PRODUCT variations must all be fetched via
		// per-ID single-product requests and all appear in the response.
		$cap = WC_AI_Storefront_UCP_REST_Controller::MAX_VARIATIONS_PER_PRODUCT;
		$this->seed_variable_with_n_variations( 300, $cap );

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_300' ) ) );

		$this->assertCount( $cap, $body['products'][0]['variants'] );

		// Parent dispatched once; all variation IDs dispatched exactly once
		// each (no duplicates, no batching via the collection route).
		// Total dispatch count = 1 parent + $cap variations.
		$this->assertSame(
			$cap + 1,
			array_sum( $this->store_api_dispatch_counts ),
			"Expected exactly $cap + 1 total dispatches (1 parent + $cap variations)."
		);
	}

	public function test_memo_cache_prevents_redundant_variation_dispatch_on_second_lookup(): void {
		// Two sequential lookup requests for the same variable product in the
		// same PHP request (reset_request_cache() clears the cache each time)
		// must each dispatch exactly once per variation — not accumulate.
		$this->seed_variable_product(
			400,
			'Hat',
			array(
				array(
					'id'    => 401,
					'price' => '800',
					'size'  => 'S',
				),
				array(
					'id'    => 402,
					'price' => '900',
					'size'  => 'M',
				),
			)
		);

		// First lookup.
		$body1 = $this->successful_lookup( array( 'ids' => array( 'prod_400' ) ) );
		$this->assertCount( 2, $body1['products'][0]['variants'] );
		$this->assertSame( 1, $this->store_api_dispatch_counts[401] ?? 0 );
		$this->assertSame( 1, $this->store_api_dispatch_counts[402] ?? 0 );

		// Second lookup — reset_request_cache() runs at the top of
		// handle_catalog_lookup(), so variations are re-fetched.
		// Each variation must be dispatched once more (total = 2), not N*2.
		$body2 = $this->successful_lookup( array( 'ids' => array( 'prod_400' ) ) );
		$this->assertCount( 2, $body2['products'][0]['variants'] );
		$this->assertSame( 2, $this->store_api_dispatch_counts[401] ?? 0 );
		$this->assertSame( 2, $this->store_api_dispatch_counts[402] ?? 0 );
	}

	// ------------------------------------------------------------------
	// Multi-currency: product `url` stamping (Task 6c, issue #404)
	// ------------------------------------------------------------------

	public function test_catalog_lookup_product_url_carries_currency_when_context_currency_in_accepted_set(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$extras ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR' );
				}
				return $value;
			}
		);

		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup(
			array(
				'ids'     => array( 'prod_123' ),
				'context' => array( 'currency' => 'EUR' ),
			)
		);

		$this->assertNotEmpty( $body['products'][0]['url'] ?? '' );
		$this->assertStringContainsString( 'currency=EUR', $body['products'][0]['url'] );
	}

	public function test_catalog_lookup_product_url_unchanged_when_context_currency_absent(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$extras ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD', 'EUR' );
				}
				return $value;
			}
		);

		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup( array( 'ids' => array( 'prod_123' ) ) );

		$this->assertNotEmpty( $body['products'][0]['url'] ?? '' );
		$this->assertStringNotContainsString( 'currency=', $body['products'][0]['url'] );
	}

	public function test_catalog_lookup_product_url_unchanged_when_context_currency_not_in_accepted_set(): void {
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$extras ) {
				if ( 'wc_ai_storefront_accepted_currencies' === $hook ) {
					return array( 'USD' );
				}
				return $value;
			}
		);

		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup(
			array(
				'ids'     => array( 'prod_123' ),
				'context' => array( 'currency' => 'JPY' ),
			)
		);

		$this->assertNotEmpty( $body['products'][0]['url'] ?? '' );
		$this->assertStringNotContainsString( 'currency=', $body['products'][0]['url'] );
	}

	public function test_handle_catalog_lookup_switches_currency_for_every_store_api_dispatch(): void {
		// Agent sends context.currency: EUR and 3 IDs. Mechanism B (issue
		// #517): every individual Store API dispatch (one per ID) runs inside
		// with_active_currency('EUR', ...) so WCPay's selected currency is EUR
		// at dispatch time. The ineffective `currency` request param is no
		// longer set.
		$mc = \Mockery::mock( '\WCPay\MultiCurrency\MultiCurrency' );
		$mc->shouldReceive( 'get_enabled_currencies' )->andReturn(
			array(
				'USD' => new \stdClass(),
				'EUR' => new \stdClass(),
			)
		);
		$GLOBALS['_mc_test_double']       = $mc;
		$GLOBALS['_mc_feature_enabled']   = true;
		$GLOBALS['_mc_initial_selected']  = 'USD';
		$GLOBALS['_mc_selected_currency'] = 'USD';
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$selected_during_dispatch = array();
		$captured_params          = array();
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $req ) use ( &$selected_during_dispatch, &$captured_params ) {
				$selected_during_dispatch[] = $GLOBALS['_mc_selected_currency'] ?? null;
				$captured_params[]          = $req->get_param( 'currency' );
				$response                   = new \WP_REST_Response(
					array(
						'id'     => 1,
						'name'   => 'Widget',
						'slug'   => 'widget',
						'type'   => 'simple',
						'prices' => array(
							'price'               => '1999',
							'currency_code'       => 'EUR',
							'currency_minor_unit' => 2,
						),
					)
				);
				$response->set_status( 200 );
				return $response;
			}
		);

		$request = new \WP_REST_Request( 'POST', '/wc/ucp/v1/catalog/lookup' );
		$request->set_body_params(
			array(
				'context' => array( 'currency' => 'EUR' ),
				'ids'     => array( 'prod_1', 'prod_2', 'prod_3' ),
			)
		);

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$controller->handle_catalog_lookup( $request );

		$this->assertCount( 3, $selected_during_dispatch, 'Three IDs must produce three Store API dispatches' );
		foreach ( $selected_during_dispatch as $i => $currency ) {
			$this->assertSame( 'EUR', $currency, "Dispatch #{$i} must run with WCPay selected currency EUR" );
		}
		foreach ( $captured_params as $i => $param ) {
			$this->assertNull( $param, "Dispatch #{$i} must not set the ineffective currency request param" );
		}
		$this->assertSame( 'USD', $GLOBALS['_mc_selected_currency'], 'selected currency restored after the handler' );
	}

	public function test_request_context_product_cache_key_includes_currency(): void {
		// The per-request product cache must not return a body fetched under
		// one currency for a lookup under another (issue #517).
		$ctx = new WC_AI_Storefront_UCP_Request_Context();

		$ctx->set_currency( 'CAD' );
		$ctx->set_product( 42, array( 'price' => 'cad-body' ) );

		// Same ID, different currency → cache miss (no cross-currency leak).
		$ctx->set_currency( 'USD' );
		$this->assertFalse( $ctx->has_product( 42 ), 'USD lookup must not hit the CAD-cached entry for the same ID' );

		// Back to CAD → original entry is still there.
		$ctx->set_currency( 'CAD' );
		$this->assertTrue( $ctx->has_product( 42 ) );
		$this->assertSame( array( 'price' => 'cad-body' ), $ctx->get_product( 42 ) );
	}

	public function test_handle_catalog_lookup_emits_unsupported_warning_for_unaccepted_currency(): void {
		// XYZ not accepted, non-base → prices stayed base → the lookup must
		// surface currency_conversion_unsupported (issue #517).
		$GLOBALS['_mc_test_double'] = null;
		WC_AI_Storefront_Multi_Currency::reset_cache();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$this->seed_simple_product( 123, 'Widget' );

		$body = $this->successful_lookup(
			array(
				'ids'     => array( 'prod_123' ),
				'context' => array( 'currency' => 'XYZ' ),
			)
		);

		$found = false;
		foreach ( $body['messages'] ?? array() as $msg ) {
			if ( WC_AI_Storefront_UCP_Error_Codes::CURRENCY_CONVERSION_UNSUPPORTED === ( $msg['code'] ?? null ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'unaccepted currency must emit currency_conversion_unsupported on lookup' );
	}
}
