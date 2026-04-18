<?php
/**
 * Tests for WC_AI_Syndication_UCP_REST_Controller::handle_checkout_sessions_create.
 *
 * The checkout-sessions handler is the most distinctive of the three:
 * stateless, redirect-only, with per-line-item validation and a
 * Shareable Checkout URL composition. Covers:
 *
 *   - Input validation (missing/empty line_items → 400)
 *   - Happy path: simple + variation IDs → continue_url + 201
 *   - All items invalid → 200 with status=error, no continue_url
 *   - Mixed valid + invalid
 *   - Product type gates (variable/variable-subscription parent →
 *     variation_required; grouped/external/subscription/
 *     subscription_variation → product_type_unsupported)
 *   - Out-of-stock = error, excluded from continue_url/line_items
 *   - UTM source from UCP-Agent (happy + fallback)
 *   - Legal links with graceful degradation + warnings
 *   - Totals computation, currency, session-id format
 *   - Round-trip-preserving ucp_id echo in line_items
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpCheckoutSessionsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Per-ID canned Store API responses.
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
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.com' . $path
		);
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy' );
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn( string $page ): string => 'https://example.com/terms'
		);

		$api = &$this->fake_store_api;
		Functions\when( 'rest_do_request' )->alias(
			static function ( WP_REST_Request $request ) use ( &$api ) {
				$route = $request->get_route();
				if ( ! preg_match( '#^/wc/store/v1/products/(\d+)$#', $route, $m ) ) {
					return new WP_REST_Response( null, 500 );
				}
				$id = (int) $m[1];
				if ( ! array_key_exists( $id, $api ) || null === $api[ $id ] ) {
					return new WP_REST_Response( null, 404 );
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

	private function seed_simple_product( int $id, int $price_minor = 1000, bool $in_stock = true ): void {
		$this->fake_store_api[ $id ] = [
			'id'          => $id,
			'name'        => 'Simple #' . $id,
			'type'        => 'simple',
			'is_in_stock' => $in_stock,
			'prices'      => [
				'price'         => (string) $price_minor,
				'currency_code' => 'USD',
			],
		];
	}

	private function seed_variation( int $id, int $price_minor = 1500 ): void {
		$this->fake_store_api[ $id ] = [
			'id'          => $id,
			'name'        => 'T-Shirt',
			'type'        => 'variation',
			'is_in_stock' => true,
			'prices'      => [
				'price'         => (string) $price_minor,
				'currency_code' => 'USD',
			],
		];
	}

	private function seed_variable_parent( int $id ): void {
		$this->fake_store_api[ $id ] = [
			'id'          => $id,
			'name'        => 'T-Shirt',
			'type'        => 'variable',
			'is_in_stock' => true,
			'prices'      => [
				'price'         => '1000',
				'currency_code' => 'USD',
			],
		];
	}

	private function seed_grouped( int $id ): void {
		$this->fake_store_api[ $id ] = [
			'id'   => $id,
			'name' => 'Bundle',
			'type' => 'grouped',
		];
	}

	private function seed_external( int $id ): void {
		$this->fake_store_api[ $id ] = [
			'id'   => $id,
			'name' => 'Partner Product',
			'type' => 'external',
		];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function checkout_request( array $body, ?string $ucp_agent = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/checkout-sessions' );
		$request->set_json_params( $body );
		if ( null !== $ucp_agent ) {
			$request->set_header( 'UCP-Agent', $ucp_agent );
		}
		return $request;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{data: array<string, mixed>, status: int}
	 */
	private function call_handler( array $body, ?string $ucp_agent = null ): array {
		$controller = new WC_AI_Syndication_UCP_REST_Controller();
		$response   = $controller->handle_checkout_sessions_create(
			$this->checkout_request( $body, $ucp_agent )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );

		return [
			'data'   => $response->get_data(),
			'status' => $response->get_status(),
		];
	}

	// ------------------------------------------------------------------
	// Input validation
	// ------------------------------------------------------------------

	/**
	 * Assert a checkout response has UCP-envelope error shape.
	 *
	 * Beyond the HTTP status and error code, verifies every field
	 * required by the UCP checkout response schema — `ucp`, `id`,
	 * `status`, `currency`, `line_items`, `totals`, `links`, and
	 * `messages`. A regression dropping any of these would break
	 * strict UCP clients; this helper forces the failure to surface
	 * in every error-path test without having to individually
	 * re-verify each shape.
	 *
	 * @param array<string, mixed> $body
	 */
	private function assert_checkout_error( array $body, int $expected_status, string $expected_code ): void {
		$result = $this->call_handler( $body );

		$this->assertEquals( $expected_status, $result['status'] );

		$data = $result['data'];

		// Full UCP checkout schema field enumeration. Required per
		// shopping/checkout.json: `ucp`, `id`, `line_items`, `status`,
		// `currency`, `totals`, `links`. `messages` is required for
		// error responses specifically (otherwise how does the agent
		// learn what went wrong).
		foreach ( [ 'ucp', 'id', 'status', 'currency', 'line_items', 'totals', 'links', 'messages' ] as $field ) {
			$this->assertArrayHasKey(
				$field,
				$data,
				sprintf( 'Checkout error response missing required schema field: %s', $field )
			);
		}

		// `id` is a `chk_` + 16-hex correlation token, fresh per call.
		$this->assertMatchesRegularExpression( '/^chk_[a-f0-9]{16}$/', $data['id'] );

		// `status: error` is the only legitimate top-level value for
		// this code path (success responses use `requires_escalation`).
		$this->assertEquals( 'error', $data['status'] );

		// Empty shapes for non-success fields.
		$this->assertSame( [], $data['line_items'] );
		$this->assertSame( [], $data['links'] );

		// `totals` exists with a zeroed subtotal (not omitted, not
		// undefined — strict UCP clients validate the array).
		$this->assertCount( 1, $data['totals'] );
		$this->assertSame( 'subtotal', $data['totals'][0]['type'] );
		$this->assertSame( 0, $data['totals'][0]['amount'] );

		// The expected error code is present in messages.
		$codes = array_column( $data['messages'], 'code' );
		$this->assertContains(
			$expected_code,
			$codes,
			'Expected error code not in messages: ' . implode( ', ', $codes )
		);
	}

	public function test_missing_line_items_returns_400(): void {
		$this->assert_checkout_error( [], 400, 'invalid_input' );
	}

	public function test_empty_line_items_array_returns_400(): void {
		$this->assert_checkout_error( [ 'line_items' => [] ], 400, 'invalid_input' );
	}

	public function test_non_array_line_items_returns_400(): void {
		$this->assert_checkout_error(
			[ 'line_items' => 'not-an-array' ],
			400,
			'invalid_input'
		);
	}

	// ------------------------------------------------------------------
	// Happy path: simple product
	// ------------------------------------------------------------------

	public function test_single_simple_product_creates_escalation_response(): void {
		$this->seed_simple_product( 123, 2500 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_123' ], 'quantity' => 2 ],
				],
			]
		);

		$this->assertEquals( 201, $result['status'] );
		$this->assertEquals( 'requires_escalation', $result['data']['status'] );
		$this->assertArrayHasKey( 'continue_url', $result['data'] );
	}

	public function test_continue_url_uses_shareable_checkout_format(): void {
		$this->seed_simple_product( 123, 1000 );
		$this->seed_simple_product( 456, 2000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_123' ], 'quantity' => 2 ],
					[ 'item' => [ 'id' => 'prod_456' ], 'quantity' => 1 ],
				],
			]
		);

		// Format: /checkout-link/?products=ID:QTY,ID:QTY&utm_source=...&utm_medium=ai_agent
		$this->assertStringContainsString(
			'/checkout-link/?products=123:2,456:1',
			$result['data']['continue_url']
		);
	}

	public function test_response_id_has_chk_prefix_and_hex_suffix(): void {
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
		);

		// bin2hex(random_bytes(8)) = 16 hex chars.
		$this->assertMatchesRegularExpression( '/^chk_[a-f0-9]{16}$/', $result['data']['id'] );
	}

	public function test_session_ids_are_unique_across_requests(): void {
		$this->seed_simple_product( 1 );

		$a = $this->call_handler( [ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ] );
		$b = $this->call_handler( [ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ] );

		$this->assertNotEquals( $a['data']['id'], $b['data']['id'] );
	}

	// ------------------------------------------------------------------
	// Variation IDs
	// ------------------------------------------------------------------

	public function test_variation_id_resolves_and_becomes_url_product_id(): void {
		$this->seed_variation( 456, 1500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'var_456' ], 'quantity' => 1 ] ] ]
		);

		$this->assertStringContainsString( 'products=456:1', $result['data']['continue_url'] );
	}

	public function test_ucp_id_echoed_round_trip_preserving_original_form(): void {
		// If the agent sent `var_456`, we echo `var_456` back in the
		// response — not `prod_456`. Preserves semantic round-tripping
		// so agents can correlate request → response line items.
		$this->seed_variation( 456 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'var_456' ], 'quantity' => 1 ] ] ]
		);

		$this->assertEquals( 'var_456', $result['data']['line_items'][0]['item']['id'] );
	}

	// ------------------------------------------------------------------
	// Product type gating
	// ------------------------------------------------------------------

	public function test_variable_parent_rejected_with_variation_required(): void {
		// Agent sent `prod_N` where N is the parent of a variable product.
		// Shareable Checkout URLs can't add a parent to cart — need a
		// variation ID. Reject with a specific code + explanatory content.
		$this->seed_variable_parent( 789 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_789' ], 'quantity' => 1 ] ] ]
		);

		$this->assertEquals( 200, $result['status'] );
		$this->assertEquals( 'error', $result['data']['status'] );
		$this->assertArrayNotHasKey( 'continue_url', $result['data'] );
		$this->assertCount( 0, $result['data']['line_items'] );

		$messages = $result['data']['messages'];
		$codes    = array_column( $messages, 'code' );
		$this->assertContains( 'variation_required', $codes );
	}

	public function test_grouped_product_rejected_with_unsupported_type(): void {
		$this->seed_grouped( 555 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_555' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'product_type_unsupported', $codes );
	}

	public function test_external_product_rejected_with_unsupported_type(): void {
		$this->seed_external( 777 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_777' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'product_type_unsupported', $codes );
	}

	public function test_subscription_product_rejected_with_unsupported_type(): void {
		// WC Subscriptions extension's `subscription` type is for
		// recurring billing flows. The Shareable Checkout URL treats
		// every item as a one-off, so a subscription mis-routed
		// through it would produce an incorrect checkout page.
		// The manifest already declares subscription as unsupported;
		// this enforces the contract at the handler layer.
		$this->fake_store_api[ 888 ] = [
			'id'   => 888,
			'name' => 'Monthly Box',
			'type' => 'subscription',
		];

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_888' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'product_type_unsupported', $codes );
	}

	public function test_subscription_variation_rejected_with_unsupported_type(): void {
		// `subscription_variation` is the variation-level subscription
		// type — distinct from `variable-subscription` (the parent).
		// This one-line type gate is easy to drop in a refactor; the
		// test locks it in. A leaked subscription_variation would reach
		// the Shareable Checkout URL and be charged as a one-off,
		// silently breaking recurring billing on the merchant's side.
		$this->fake_store_api[ 890 ] = [
			'id'   => 890,
			'name' => 'Monthly Box — Annual plan',
			'type' => 'subscription_variation',
		];

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'var_890' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'product_type_unsupported', $codes );
	}

	public function test_variable_subscription_parent_rejected_as_variation_required(): void {
		// `variable-subscription` is the Subscriptions-extension
		// analogue of `variable`: a subscription with variations
		// (e.g. monthly/quarterly plans). Agent must send a specific
		// variation ID, not the parent.
		$this->fake_store_api[ 999 ] = [
			'id'   => 999,
			'name' => 'Magazine Subscription',
			'type' => 'variable-subscription',
		];

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_999' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'variation_required', $codes );
	}

	// ------------------------------------------------------------------
	// Stock handling: warning, not rejection
	// ------------------------------------------------------------------

	public function test_out_of_stock_item_rejected_outright(): void {
		// WC's `is_in_stock` already factors backorder settings — when
		// it returns false, WooCommerce has concluded the item isn't
		// purchasable right now. Letting it through to continue_url
		// would hand the user a checkout that then refuses the item,
		// which is worse UX than rejecting upfront with a clear error.
		$this->seed_simple_product( 111, 1000, false );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		// No valid items → status: error, no continue_url, 200 response.
		$this->assertEquals( 200, $result['status'] );
		$this->assertEquals( 'error', $result['data']['status'] );
		$this->assertArrayNotHasKey( 'continue_url', $result['data'] );
		$this->assertCount( 0, $result['data']['line_items'] );

		// The error message identifies the offending line item.
		$errors = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'out_of_stock' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $errors );
		$msg = array_values( $errors )[0];
		$this->assertEquals( 'error', $msg['type'] );
		$this->assertEquals( 'unrecoverable', $msg['severity'] );
	}

	public function test_mixed_cart_excludes_out_of_stock_from_totals_and_line_items(): void {
		// The single-item rejection test above can't catch a regression
		// where OOS items are correctly excluded from `continue_url`
		// but accidentally retained in `response.line_items` or
		// `totals.subtotal`. This test seeds two items (one in-stock,
		// one OOS), asserts the in-stock one flows through cleanly and
		// the OOS one is excluded from every output field that carries
		// purchasable items.
		$this->seed_simple_product( 100, 1500, true );   // $15, in-stock
		$this->seed_simple_product( 200, 2500, false );  // $25, OOS

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_100' ], 'quantity' => 2 ],  // $30
					[ 'item' => [ 'id' => 'prod_200' ], 'quantity' => 1 ],  // OOS — excluded
				],
			]
		);

		// At least one valid item → 201 Created with continue_url.
		$this->assertEquals( 201, $result['status'] );
		$this->assertEquals( 'requires_escalation', $result['data']['status'] );

		// continue_url contains only the in-stock item.
		$this->assertStringContainsString( '100:2', $result['data']['continue_url'] );
		$this->assertStringNotContainsString( '200', $result['data']['continue_url'] );

		// response.line_items has only the in-stock item.
		$this->assertCount( 1, $result['data']['line_items'] );
		$this->assertEquals( 'prod_100', $result['data']['line_items'][0]['item']['id'] );

		// Subtotal reflects ONLY the in-stock item (2 × $15 = 3000 cents),
		// not the OOS one. Integer-type assertion catches silent float
		// drift.
		$subtotal = $result['data']['totals'][0]['amount'];
		$this->assertIsInt( $subtotal );
		$this->assertSame( 3000, $subtotal );

		// The OOS item surfaces via a message with the exact request
		// index — agents know which input failed without parsing the
		// response body positionally.
		$oos_messages = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'out_of_stock' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $oos_messages );
		$msg = array_values( $oos_messages )[0];
		$this->assertEquals( '$.line_items[1].item.id', $msg['path'] );
	}

	// ------------------------------------------------------------------
	// Not found / malformed
	// ------------------------------------------------------------------

	public function test_unknown_product_id_produces_not_found_message(): void {
		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_9999' ], 'quantity' => 1 ] ] ]
		);

		$this->assertEquals( 'error', $result['data']['status'] );
		$this->assertCount( 0, $result['data']['line_items'] );

		$messages = $result['data']['messages'];
		$this->assertEquals( 'not_found', $messages[0]['code'] );
		$this->assertEquals( '$.line_items[0].item.id', $messages[0]['path'] );
	}

	public function test_zero_quantity_produces_invalid_quantity(): void {
		$this->seed_simple_product( 123 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_123' ], 'quantity' => 0 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_quantity', $codes );
	}

	public function test_negative_quantity_produces_invalid_quantity(): void {
		$this->seed_simple_product( 123 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_123' ], 'quantity' => -3 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_quantity', $codes );
	}

	public function test_non_array_line_item_produces_invalid_line_item(): void {
		$result = $this->call_handler(
			[ 'line_items' => [ 'this-is-not-an-object' ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_line_item', $codes );
	}

	public function test_non_array_item_field_does_not_crash_in_php8(): void {
		// Regression guard for a PHP 8 fatal error: the handler
		// previously did `$line_item['item']['id'] ?? null` which, if
		// `item` is a STRING (not an array), throws a fatal "cannot
		// access offset of type string on string" before any error-
		// response code runs. Locks in the defensive shape check that
		// emits `invalid_line_item` for this mis-shaped input.
		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => 'prod_123', 'quantity' => 1 ],  // string, not array
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_line_item', $codes );

		// Status should be error (no valid items), not a 500 from a
		// fatal — confirms the handler didn't actually crash.
		$this->assertEquals( 200, $result['status'] );
		$this->assertEquals( 'error', $result['data']['status'] );
	}

	public function test_missing_item_id_produces_invalid_line_item(): void {
		// `item.id` absent or non-string. Previously this reached
		// `parse_ucp_id_to_wc_int(null)` → 0 → `not_found` message,
		// which conflated shape errors with data errors. Now the
		// shape check runs first and emits the more accurate
		// `invalid_line_item` code with a JSONPath at the .id field.
		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [], 'quantity' => 1 ],  // empty item object, no id
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_line_item', $codes );

		// Verify the message path locates the missing field, not the
		// top-level line_item index.
		$messages = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'invalid_line_item' === ( $m['code'] ?? '' )
		);
		$this->assertEquals(
			'$.line_items[0].item.id',
			array_values( $messages )[0]['path']
		);
	}

	public function test_whitespace_only_item_id_produces_invalid_line_item(): void {
		// Defensive: a string that's all whitespace is not a legitimate
		// ID. `trim($raw_id) === ''` catches this before we try to
		// parse-to-int it.
		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => '   ' ], 'quantity' => 1 ],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_line_item', $codes );
	}

	// ------------------------------------------------------------------
	// Mixed outcomes
	// ------------------------------------------------------------------

	public function test_mixed_valid_and_invalid_produces_partial_continue_url(): void {
		// One valid + one invalid = continue_url contains only the
		// valid item, response line_items has only the valid item,
		// but messages enumerate every failure.
		$this->seed_simple_product( 100, 1000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_100' ], 'quantity' => 2 ],
					[ 'item' => [ 'id' => 'prod_999' ], 'quantity' => 1 ],  // missing
				],
			]
		);

		$this->assertEquals( 201, $result['status'] );
		$this->assertStringContainsString( 'products=100:2', $result['data']['continue_url'] );
		$this->assertStringNotContainsString( '999', $result['data']['continue_url'] );
		$this->assertCount( 1, $result['data']['line_items'] );

		// Failure message localized at the second line item index.
		$not_found = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'not_found' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $not_found );
		$first = array_values( $not_found )[0];
		$this->assertEquals( '$.line_items[1].item.id', $first['path'] );
	}

	// ------------------------------------------------------------------
	// UTM attribution
	// ------------------------------------------------------------------

	public function test_unknown_agent_hostname_passes_through_to_utm_source(): void {
		// Novel / unregistered agent hostname — not in KNOWN_AGENT_HOSTS.
		// Canonicalization should pass it through verbatim so merchants
		// still see something useful in the Origin column rather than
		// losing attribution entirely for unknown vendors.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'profile="https://agent.example.com/profile.json"'
		);

		$this->assertStringContainsString(
			'utm_source=agent.example.com',
			$result['data']['continue_url']
		);
		$this->assertStringContainsString(
			'utm_medium=ai_agent',
			$result['data']['continue_url']
		);
	}

	public function test_known_agent_hostname_is_canonicalized_in_utm_source(): void {
		// Integration check: a hostname in KNOWN_AGENT_HOSTS must be
		// mapped to its brand name BEFORE landing in utm_source, so
		// WC's Origin column reads "Source: Gemini" (not
		// "Source: gemini.google.com") once the order is captured.
		// The unit-level mapping is exhaustively covered in
		// UcpAgentHeaderTest; this test pins the end-to-end wiring.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'profile="https://gemini.google.com/profile.json"'
		);

		$this->assertStringContainsString(
			'utm_source=Gemini',
			$result['data']['continue_url']
		);
	}

	public function test_missing_ucp_agent_falls_back_to_sentinel_utm_source(): void {
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
			// No UCP-Agent header set.
		);

		$this->assertStringContainsString(
			'utm_source=' . WC_AI_Syndication_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url']
		);
	}

	public function test_malformed_ucp_agent_falls_back_to_sentinel_utm_source(): void {
		// Header present but malformed (no profile= value). Treat as missing.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'trust=anonymous'
		);

		$this->assertStringContainsString(
			'utm_source=' . WC_AI_Syndication_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url']
		);
	}

	// ------------------------------------------------------------------
	// Legal links
	// ------------------------------------------------------------------

	public function test_both_legal_links_emitted_when_merchant_has_pages_set(): void {
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
		);

		$types = array_column( $result['data']['links'], 'type' );
		$this->assertContains( 'privacy_policy', $types );
		$this->assertContains( 'terms_of_service', $types );
	}

	public function test_missing_privacy_policy_emits_advisory_warning(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );

		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
		);

		$types = array_column( $result['data']['links'], 'type' );
		$this->assertNotContains( 'privacy_policy', $types );

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'privacy_policy_unconfigured', $codes );
	}

	public function test_missing_terms_emits_advisory_warning(): void {
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn( string $page ): string => ''
		);

		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
		);

		$types = array_column( $result['data']['links'], 'type' );
		$this->assertNotContains( 'terms_of_service', $types );

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'terms_unconfigured', $codes );
	}

	// ------------------------------------------------------------------
	// Totals + currency
	// ------------------------------------------------------------------

	public function test_subtotal_is_sum_of_line_totals(): void {
		$this->seed_simple_product( 100, 1000 );  // $10 each
		$this->seed_simple_product( 200, 2500 );  // $25 each

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_100' ], 'quantity' => 3 ],  // $30
					[ 'item' => [ 'id' => 'prod_200' ], 'quantity' => 2 ],  // $50
				],
			]
		);

		// 3*1000 + 2*2500 = 8000 minor units = $80
		$this->assertSame(
			8000,
			$result['data']['totals'][0]['amount']
		);
		$this->assertEquals( 'subtotal', $result['data']['totals'][0]['type'] );
	}

	public function test_line_totals_included_in_response_line_items(): void {
		$this->seed_simple_product( 100, 1500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_100' ], 'quantity' => 3 ] ] ]
		);

		$line = $result['data']['line_items'][0];
		$this->assertSame( 1500, $line['unit_price']['amount'] );
		$this->assertSame( 4500, $line['line_total']['amount'] );
		$this->assertEquals( 'USD', $line['line_total']['currency'] );
	}

	public function test_currency_from_wc_setting_flows_through_everywhere(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$this->seed_simple_product( 1, 1000 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
		);

		$this->assertEquals( 'EUR', $result['data']['currency'] );
		$this->assertEquals( 'EUR', $result['data']['line_items'][0]['unit_price']['currency'] );
	}

	// ------------------------------------------------------------------
	// UCP envelope
	// ------------------------------------------------------------------

	public function test_response_wraps_content_in_checkout_envelope(): void {
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
		);

		$this->assertArrayHasKey( 'ucp', $result['data'] );
		$this->assertArrayHasKey( 'capabilities', $result['data']['ucp'] );
		$this->assertArrayHasKey(
			'dev.ucp.shopping.checkout',
			$result['data']['ucp']['capabilities']
		);
		// payment_handlers must be present even when empty.
		$this->assertArrayHasKey( 'payment_handlers', $result['data']['ucp'] );
	}

	public function test_buyer_handoff_message_present_when_continue_url_issued(): void {
		// Agents surface this `content` verbatim to end users before
		// redirecting — the phrasing is part of the UX contract.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ]
		);

		$handoff = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'buyer_handoff_required' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $handoff );
		$msg = array_values( $handoff )[0];
		$this->assertEquals( 'requires_buyer_input', $msg['severity'] );
	}

	public function test_buyer_handoff_message_omitted_when_no_continue_url(): void {
		// If every item failed, there's no redirect to signal — skip
		// the handoff message rather than confusing agents with a
		// "redirect the user" hint when there's nothing to redirect to.
		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_9999' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'buyer_handoff_required', $codes );
	}

	// ------------------------------------------------------------------
	// DoS caps + syndication-disabled gate
	// ------------------------------------------------------------------

	public function test_excessive_quantity_rejected_as_invalid(): void {
		// Quantity * unit_price_minor must fit in PHP_INT_MAX or the
		// multiplication silently promotes to float, which JSON-encodes
		// as scientific notation and violates UCP's integer constraint
		// on line_total.amount. The cap rejects quantities high enough
		// to risk overflow long before we approach that ceiling.
		$this->seed_simple_product( 1, 1000 );

		$cap    = WC_AI_Syndication_UCP_REST_Controller::MAX_QUANTITY_PER_LINE_ITEM;
		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => $cap + 1 ] ] ]
		);

		$this->assertEquals( 'error', $result['data']['status'] );
		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_quantity', $codes );
	}

	public function test_quantity_at_exactly_the_cap_is_accepted(): void {
		// Off-by-one: exactly MAX_QUANTITY_PER_LINE_ITEM should work.
		$this->seed_simple_product( 1, 100 );

		$cap    = WC_AI_Syndication_UCP_REST_Controller::MAX_QUANTITY_PER_LINE_ITEM;
		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => $cap ] ] ]
		);

		$this->assertEquals( 201, $result['status'] );
		// Line total should be an INTEGER, not a float (no silent overflow).
		$line_total = $result['data']['line_items'][0]['line_total']['amount'];
		$this->assertIsInt( $line_total );
		$this->assertSame( $cap * 100, $line_total );
	}

	public function test_rejects_line_items_array_exceeding_limit(): void {
		// Same DoS class as the ids cap on /catalog/lookup: each
		// line item drives internal product-validation dispatches.
		$cap   = WC_AI_Syndication_UCP_REST_Controller::MAX_LINE_ITEMS_PER_CHECKOUT;
		$items = [];
		for ( $i = 0; $i < $cap + 1; $i++ ) {
			$items[] = [ 'item' => [ 'id' => 'prod_' . $i ], 'quantity' => 1 ];
		}

		$this->assert_checkout_error( [ 'line_items' => $items ], 400, 'invalid_input' );
	}

	public function test_disabled_syndication_returns_503_ucp_disabled(): void {
		// Checkout is the highest-stakes handler to leave serving when
		// syndication is paused — lock in the gate.
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'no' ];

		$this->assert_checkout_error(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			503,
			'ucp_disabled'
		);
	}
}
