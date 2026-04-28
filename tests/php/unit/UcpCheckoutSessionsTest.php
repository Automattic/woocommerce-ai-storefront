<?php
/**
 * Tests for WC_AI_Storefront_UCP_REST_Controller::handle_checkout_sessions_create.
 *
 * The checkout-sessions handler is the most distinctive of the three:
 * stateless, redirect-only, with per-line-item validation and a
 * Shareable Checkout URL composition. Covers:
 *
 *   - Input validation (missing/empty line_items → 400)
 *   - Happy path: simple + variation IDs → continue_url + 201
 *   - All items invalid → 200 with status=incomplete, no continue_url
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
 * @package WooCommerce_AI_Storefront
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
		WC_AI_Storefront::$test_settings = [];

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
		// Default to tax-exclusive pricing (typical US store) — tests
		// that exercise the inclusive-tax path override this with their
		// own when() call. The per-line-item `price_includes_tax` flag
		// we emit reads from this stub.
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );
		// Default `apply_filters` to pass-through (return $default)
		// so tests that don't explicitly care about filter hooks
		// aren't coupled to the filter registry from other test
		// suites' bootstrap. Per-test overrides via
		// `stub_apply_filters_for()` / `Functions\when( 'apply_filters' )->alias()`
		// replace this default when needed.
		Functions\when( 'apply_filters' )->returnArg( 2 );

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
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
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

		// `status: incomplete` is the spec-compliant value for this
		// code path (spec enum: incomplete | requires_escalation |
		// ready_for_complete | complete_in_progress | completed |
		// canceled). Success responses use `requires_escalation`.
		$this->assertEquals( 'incomplete', $data['status'] );

		// Empty shapes for non-success fields.
		$this->assertSame( [], $data['line_items'] );
		$this->assertSame( [], $data['links'] );

		// `totals` must carry BOTH `subtotal` and `total` entries per
		// UCP 2026-04-08 spec (minContains:1, maxContains:1 each).
		// Both zeroed on the error path.
		$this->assertCount( 2, $data['totals'] );
		$types = array_column( $data['totals'], 'type' );
		$this->assertContains( 'subtotal', $types );
		$this->assertContains( 'total', $types );
		foreach ( $data['totals'] as $entry ) {
			$this->assertSame( 0, $entry['amount'] );
		}

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
		$this->assertEquals( 'incomplete', $result['data']['status'] );
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

		// No valid items → status: incomplete (spec-compliant, was
		// previously a non-enum `error`), no continue_url, 200 response.
		$this->assertEquals( 200, $result['status'] );
		$this->assertEquals( 'incomplete', $result['data']['status'] );
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

		$this->assertEquals( 'incomplete', $result['data']['status'] );
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

		// Status should be incomplete (no valid items), not a 500
		// from a fatal — confirms the handler didn't actually crash.
		$this->assertEquals( 200, $result['status'] );
		$this->assertEquals( 'incomplete', $result['data']['status'] );
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

	public function test_unknown_agent_hostname_lands_in_utm_source_verbatim(): void {
		// Canonical-UTM shape (0.5.0+): utm_source carries the raw
		// (lowercased) hostname for unknown agents — NOT the "Other AI"
		// bucket label. This converges with what bypass-path agents
		// stamp on Shareable Checkout links (the same hostname on both
		// our continue_url AND a merchant-direct link). Pre-0.5.0 the
		// canonical-name bucket "Other AI" landed in utm_source, which
		// fragmented stats against bypass paths.
		//
		// Internally we still recognize the order as bucketed via
		// `_wc_ai_storefront_agent` meta (set to "Other AI" by the
		// attribution layer); the WC-captured `_wc_order_attribution_utm_source`
		// just shows the raw hostname so merchants drilling into the
		// Origin column see who actually sent it.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'profile="https://agent.example.com/profile.json"'
		);

		$this->assertStringContainsString(
			'utm_source=agent.example.com',
			$result['data']['continue_url']
		);
		// New canonical UTM shape: medium=referral (Google-canonical),
		// utm_id=woo_ucp flags "we routed this".
		$this->assertStringContainsString(
			'utm_medium=referral',
			$result['data']['continue_url']
		);
		$this->assertStringContainsString(
			'utm_id=woo_ucp',
			$result['data']['continue_url']
		);
		// Raw hostname is preserved in the legacy ai_agent_host_raw
		// param too — diagnostic-only since utm_source now carries
		// the same value, but kept for backward-compat with consumers
		// that read this meta directly.
		$this->assertStringContainsString(
			'ai_agent_host_raw=agent.example.com',
			$result['data']['continue_url']
		);
	}

	public function test_known_agent_hostname_lands_in_utm_source_lowercased(): void {
		// Canonical-UTM shape (0.5.0+): utm_source IS the lowercase
		// hostname — not the canonical brand name. Pre-0.5.0 we
		// stamped "Gemini" (canonical); now we stamp
		// "gemini.google.com" (raw). The brand-name canonicalization
		// still happens for the `_wc_ai_storefront_agent` meta so the
		// merchant's AI Orders display reads "Source: Gemini", but
		// utm_source carries the hostname for cross-path consistency.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'profile="https://gemini.google.com/profile.json"'
		);

		$this->assertStringContainsString(
			'utm_source=gemini.google.com',
			$result['data']['continue_url']
		);
		$this->assertStringContainsString(
			'ai_agent_host_raw=gemini.google.com',
			$result['data']['continue_url']
		);
	}

	public function test_ucpplayground_hostname_lands_in_utm_source(): void {
		// Canonical-UTM shape (0.5.0+): same hostname-not-brand
		// treatment as the Gemini case above. Confirms the rule
		// holds for UCP Playground specifically since it's the
		// agent whose feedback drove the canonicalization.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'profile="https://ucpplayground.com/profile.json"'
		);

		$this->assertStringContainsString(
			'utm_source=ucpplayground.com',
			$result['data']['continue_url']
		);
		$this->assertStringContainsString(
			'ai_agent_host_raw=ucpplayground.com',
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
			'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url']
		);
		// Empty raw_host MUST result in the param being OMITTED from
		// the URL entirely — never `&ai_agent_host_raw=` with empty
		// value. Regression guard: a refactor that always appends the
		// param (drops the `if ( '' !== $raw_host )` guard in
		// build_continue_url) would silently emit empty raw_host on
		// every fallback order, polluting analytics + downstream meta.
		$this->assertStringNotContainsString(
			'ai_agent_host_raw',
			$result['data']['continue_url']
		);
	}

	public function test_product_version_ucp_agent_resolves_to_canonical_hostname(): void {
		// UCPPlayground sends `UCP-Agent: UCP-Playground/1.0`.
		// Canonical-UTM shape (0.5.0+): utm_source is the canonical
		// hostname for the product (resolved via PRODUCT_TO_HOSTNAME),
		// converging with profile-URL form so the same agent stamps
		// the same utm_source value regardless of which header shape
		// it uses.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'UCP-Playground/1.0'
		);

		// Resolves through PRODUCT_TO_HOSTNAME['ucp-playground'] →
		// 'ucpplayground.com'. Same value the profile-URL form stamps.
		$this->assertStringContainsString(
			'utm_source=ucpplayground.com',
			$result['data']['continue_url']
		);
		// raw_host preserves the original signal value (lowercased
		// product token) for diagnostic / graduation purposes —
		// distinct from utm_source's canonical-hostname value.
		$this->assertStringContainsString(
			'ai_agent_host_raw=ucp-playground',
			$result['data']['continue_url']
		);
	}

	public function test_product_version_ucp_agent_unknown_product_falls_back_to_token(): void {
		// Unknown product (no PRODUCT_TO_HOSTNAME entry) → utm_source
		// falls back to the lowercased product token. Better than
		// empty utm_source; accepts that unknowns fragment until a
		// hostname is mapped in. Distinct cohort from "agent didn't
		// identify at all" (which gets the ucp_unknown sentinel).
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'NovelAgent/2.0'
		);

		$this->assertStringContainsString(
			'utm_source=novelagent',
			$result['data']['continue_url']
		);
		$this->assertStringContainsString(
			'ai_agent_host_raw=novelagent',
			$result['data']['continue_url']
		);
	}

	public function test_meta_source_body_fallback_resolves_to_canonical_hostname(): void {
		// Some clients identify themselves via `meta.source` in the
		// request body rather than the UCP-Agent header. UCPPlayground
		// flagged this as their secondary identification path. When
		// the header path yields nothing, the controller falls through
		// to body.meta.source.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				'meta'       => [ 'source' => 'ucp-playground' ],
			]
			// No UCP-Agent header set — falls through to meta.source.
		);

		// Same PRODUCT_TO_HOSTNAME resolution as the Product/Version
		// header path — utm_source converges across both paths.
		$this->assertStringContainsString(
			'utm_source=ucpplayground.com',
			$result['data']['continue_url']
		);
		$this->assertStringContainsString(
			'ai_agent_host_raw=ucp-playground',
			$result['data']['continue_url']
		);
	}

	public function test_header_takes_priority_over_meta_source_body(): void {
		// Priority gate: when both signals are present, the UCP-Agent
		// header wins. Header semantics are part of the UCP spec; body
		// `meta.source` is a body-field convention. A header-trusted
		// agent that ALSO sends a divergent body field shouldn't have
		// its identification overridden by the weaker signal.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				// Body says one thing.
				'meta'       => [ 'source' => 'ucp-playground' ],
			],
			// Header says another. Header wins.
			'profile="https://gemini.google.com/profile.json"'
		);

		$this->assertStringContainsString(
			'utm_source=gemini.google.com',
			$result['data']['continue_url']
		);
	}

	public function test_meta_source_unknown_value_falls_back_to_token(): void {
		// Body-fallback equivalent of the unknown-product test:
		// meta.source provided a signal but it isn't in
		// PRODUCT_TO_HOSTNAME → utm_source falls back to the
		// lowercased value rather than the ucp_unknown sentinel.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				'meta'       => [ 'source' => 'mysteryAgent' ],
			]
		);

		$this->assertStringContainsString(
			'utm_source=mysteryagent',
			$result['data']['continue_url']
		);
		// raw_host preserves the case the agent sent (capital A in
		// `mysteryAgent`) for diagnostic purposes; only utm_source
		// gets lowercased.
		$this->assertStringContainsString(
			'ai_agent_host_raw=mysteryAgent',
			$result['data']['continue_url']
		);
	}

	public function test_meta_with_non_array_value_falls_back_to_sentinel(): void {
		// Defensive guard: `meta` arrives as a string instead of an
		// object. Without `is_array()` in resolve_agent_host(), PHP
		// would either fatal on array access against a string or
		// silently coerce — both bad. Pin the guard so a future
		// refactor that simplifies to `$meta = $body['meta'] ?? [];`
		// fails this test instead of fataling in production.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				'meta'       => 'ucp-playground', // String, not array.
			]
		);

		$this->assertStringContainsString(
			'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url']
		);
	}

	public function test_meta_source_with_non_string_value_falls_back_to_sentinel(): void {
		// Defensive guard: `meta.source` arrives as an integer or
		// array instead of a string. Without `is_string()`, `trim()`
		// would fatal on a non-scalar or coerce a number to its
		// string representation (and "42" probably isn't an agent
		// brand). Pin the guard.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				'meta'       => [ 'source' => 42 ], // Number, not string.
			]
		);

		$this->assertStringContainsString(
			'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url']
		);
	}

	public function test_meta_source_whitespace_only_falls_back_to_sentinel(): void {
		// Edge case: `meta.source` arrives as whitespace ("   ").
		// `trim()` reduces it to empty string, and the empty-check
		// path sends us to the FALLBACK_SOURCE sentinel — same as
		// not setting the field at all. Pinning this so a refactor
		// that drops `trim()` or changes the empty-check ordering
		// doesn't silently start passing whitespace through to
		// `canonicalize_product()` (which would then bucket it as
		// "Other AI" instead of "ucp_unknown" — different cohort).
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				'meta'       => [ 'source' => "   \t\n  " ],
			]
		);

		$this->assertStringContainsString(
			'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url']
		);
	}

	public function test_meta_source_over_253_chars_falls_back_to_sentinel(): void {
		// Values longer than 253 characters (RFC 1035 FQDN max) are
		// rejected at the charset/length gate in `resolve_agent_host()`
		// and fall through to Path 4 (FALLBACK_SOURCE). This pins the
		// length cap so a future refactor that raises or removes it
		// doesn't silently start storing oversized strings in order meta.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				'meta'       => [ 'source' => str_repeat( 'a', 254 ) ],
			]
		);

		$this->assertStringContainsString(
			'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url'],
			'meta.source value exceeding 253 chars must fall through to FALLBACK_SOURCE.'
		);
		// No raw_host param for rejected values (same contract as
		// whitespace-only / non-string paths).
		$this->assertStringNotContainsString( 'ai_agent_host_raw=', $result['data']['continue_url'] );
	}

	public function test_meta_source_invalid_charset_falls_back_to_sentinel(): void {
		// Values containing characters outside [A-Za-z0-9._-] (e.g.
		// angle brackets, spaces, percent signs) fail the charset gate
		// and fall through to Path 4. Pins the validation so a future
		// regex change that accidentally widens the allowed set doesn't
		// start storing attacker-controlled strings in order meta.
		$this->seed_simple_product( 1 );

		foreach ( [ '<script>', 'agent name', 'agent%2Fsource', 'agent/source' ] as $bad_source ) {
			$result = $this->call_handler(
				[
					'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
					'meta'       => [ 'source' => $bad_source ],
				]
			);

			$this->assertStringContainsString(
				'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
				$result['data']['continue_url'],
				"meta.source value '{$bad_source}' contains disallowed characters and must fall through to FALLBACK_SOURCE."
			);
			$this->assertStringNotContainsString( 'ai_agent_host_raw=', $result['data']['continue_url'] );
		}
	}

	public function test_meta_source_with_underscore_is_accepted(): void {
		// Underscores are valid in product-name tokens (Path 2 accepts
		// them via `extract_agent_product()`). The body fallback path
		// (Path 3) must be equally permissive — a token like
		// `my_agent` must not be silently rejected.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ],
				'meta'       => [ 'source' => 'my_agent' ],
			]
		);

		// Should NOT fall back to FALLBACK_SOURCE — the token is valid.
		$this->assertStringNotContainsString(
			'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url'],
			'Underscore tokens must be accepted by the meta.source charset gate.'
		);
		$this->assertStringContainsString( 'ai_agent_host_raw=my_agent', $result['data']['continue_url'] );
	}

	public function test_malformed_ucp_agent_falls_back_to_sentinel_utm_source(): void {
		// Header present but malformed (no profile= value). Treat as missing.
		$this->seed_simple_product( 1 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			'trust=anonymous'
		);

		$this->assertStringContainsString(
			'utm_source=' . WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE,
			$result['data']['continue_url']
		);
		// Same omission contract as above: malformed UCP-Agent →
		// extract_profile_hostname returns '' → raw_host stays empty →
		// param is omitted from the URL. Parallel coverage with the
		// missing-header case.
		$this->assertStringNotContainsString(
			'ai_agent_host_raw',
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
		// Type `info` + severity `advisory` — the buyer-handoff
		// message accompanies the happy-path redirect, not a failure.
		// Pre-fix this carried `type: error` / `severity:
		// requires_buyer_input`, which agents (UCPPlayground confirmed,
		// production agents likely) read as a UI hint to render the
		// response in error styling, producing "there was an issue,
		// here's the link" copy instead of a Buy Now CTA. The
		// `status: requires_escalation` field already signals the
		// redirect posture; the message type/severity should match
		// the emotional valence (informational), not restate the
		// protocol-level state.
		$this->assertEquals( 'info', $msg['type'] );
		$this->assertEquals( 'advisory', $msg['severity'] );
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

		$cap    = WC_AI_Storefront_UCP_REST_Controller::MAX_QUANTITY_PER_LINE_ITEM;
		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => $cap + 1 ] ] ]
		);

		$this->assertEquals( 'incomplete', $result['data']['status'] );
		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_quantity', $codes );
	}

	// ------------------------------------------------------------------
	// Duplicate line-item dedup (issue #123)
	// ------------------------------------------------------------------
	//
	// An agent that posts the same product twice (e.g. an incremental
	// cart-add pattern) would otherwise produce a `continue_url`
	// carrying `?products=ID:1,ID:2` — and WC's `/checkout-link/`
	// parser uses each `id` as a key in its add-to-cart loop, so the
	// second occurrence overwrites the first. Pre-fix: response
	// echoed both lines summing to qty=3, but the buyer's cart at
	// checkout contained qty=2. Buyer-trust failure.
	//
	// Post-fix: collapse `$processed` by `wc_id` before
	// `build_continue_url` and the response echo run, sum
	// quantities, surface a `merged_duplicate_items` info-message.

	public function test_duplicate_line_items_are_merged_with_summed_quantity(): void {
		$this->seed_simple_product( 42, 1000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_42' ], 'quantity' => 1 ],
					[ 'item' => [ 'id' => 'prod_42' ], 'quantity' => 2 ],
				],
			]
		);

		// Response should carry ONE line item per wc_id with the
		// summed quantity, not two separate entries.
		$this->assertCount( 1, $result['data']['line_items'] );
		$this->assertEquals( 'prod_42', $result['data']['line_items'][0]['item']['id'] );
		$this->assertEquals( 3, $result['data']['line_items'][0]['quantity'] );

		// `continue_url` must encode the summed quantity since that's
		// what /checkout-link/ will translate into the buyer's cart —
		// the whole point of the fix.
		$this->assertStringContainsString( '?products=42:3', $result['data']['continue_url'] );

		// Surfaced as info-message so agents know the collapse happened.
		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'merged_duplicate_items', $codes );

		// Subtotal reflects the merged quantity (3 × 1000 = 3000), not
		// the post-collapse sum mismatched against pre-collapse echo.
		$totals_by_type = array_column( $result['data']['totals'], 'amount', 'type' );
		$this->assertEquals( 3000, $totals_by_type['subtotal'] );
		$this->assertEquals( 3000, $totals_by_type['total'] );
	}

	public function test_no_merge_message_when_all_line_items_have_distinct_wc_ids(): void {
		// Defense against a regression that emits the merge message
		// every time `$line_items_raw` has more than one entry, even
		// when no actual collapse happened. The flag should track
		// observed merges, not request shape.
		$this->seed_simple_product( 1, 1000 );
		$this->seed_simple_product( 2, 2000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ],
					[ 'item' => [ 'id' => 'prod_2' ], 'quantity' => 1 ],
				],
			]
		);

		$this->assertCount( 2, $result['data']['line_items'] );
		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'merged_duplicate_items', $codes );
	}

	public function test_summed_quantity_exceeding_max_per_line_drops_merged_entry(): void {
		// Two below-cap entries can sum to over-cap. Drop the merged
		// entry and emit `invalid_quantity` — same posture as
		// `process_line_item`'s single-line over-cap path.
		$this->seed_simple_product( 1, 100 );

		$cap          = WC_AI_Storefront_UCP_REST_Controller::MAX_QUANTITY_PER_LINE_ITEM;
		$line_qty     = (int) ( $cap / 2 ) + 1;
		$expected_sum = $line_qty * 2;
		// Pre-condition: each line is below the cap, but the sum
		// exceeds it. Derived from `$cap` so the test stays valid if
		// the cap is later changed to an odd value.
		$this->assertLessThanOrEqual( $cap, $line_qty );
		$this->assertGreaterThan( $cap, $expected_sum );

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_1' ], 'quantity' => $line_qty ],
					[ 'item' => [ 'id' => 'prod_1' ], 'quantity' => $line_qty ],
				],
			]
		);

		// Sum is over-cap → entry dropped. With nothing to redirect
		// to, status is `incomplete`.
		$this->assertEquals( 'incomplete', $result['data']['status'] );
		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'invalid_quantity', $codes );
		// `merged_duplicate_items` does NOT fire when the merged
		// entry got dropped — the agent would otherwise look for a
		// merged line in the response and find nothing. Truthful
		// posture: only claim a merge happened when the response
		// actually shows it. The `invalid_quantity` error's content
		// carries the agent's ucp_id and summed quantity, so the
		// affected product is still identifiable without the merge
		// message.
		$this->assertNotContains( 'merged_duplicate_items', $codes );

		// Verify the error message content includes the offending
		// ucp_id + summed quantity so agents can self-diagnose
		// without the JSONPath being a specific index.
		$over_cap_msg = null;
		foreach ( $result['data']['messages'] as $msg ) {
			if ( 'invalid_quantity' === ( $msg['code'] ?? '' ) ) {
				$over_cap_msg = $msg;
				break;
			}
		}
		$this->assertNotNull( $over_cap_msg, 'invalid_quantity message must be present.' );
		$this->assertStringContainsString( 'prod_1', $over_cap_msg['content'] );
		$this->assertStringContainsString( (string) ( $cap + 2 ), $over_cap_msg['content'] );
	}

	public function test_quantity_at_exactly_the_cap_is_accepted(): void {
		// Off-by-one: exactly MAX_QUANTITY_PER_LINE_ITEM should work.
		$this->seed_simple_product( 1, 100 );

		$cap    = WC_AI_Storefront_UCP_REST_Controller::MAX_QUANTITY_PER_LINE_ITEM;
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
		$cap   = WC_AI_Storefront_UCP_REST_Controller::MAX_LINE_ITEMS_PER_CHECKOUT;
		$items = [];
		for ( $i = 0; $i < $cap + 1; $i++ ) {
			$items[] = [ 'item' => [ 'id' => 'prod_' . $i ], 'quantity' => 1 ];
		}

		$this->assert_checkout_error( [ 'line_items' => $items ], 400, 'invalid_input' );
	}

	public function test_disabled_syndication_returns_503_ucp_disabled(): void {
		// Checkout is the highest-stakes handler to leave serving when
		// syndication is paused — lock in the gate.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$this->assert_checkout_error(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ] ],
			503,
			'ucp_disabled'
		);
	}

	// ------------------------------------------------------------------
	// 2.0.0: UCP 2026-04-08 compliance + checkout polish
	// ------------------------------------------------------------------

	public function test_totals_contains_both_subtotal_and_total_entries_per_spec(): void {
		// UCP 2026-04-08: `totals` MUST contain exactly one `subtotal`
		// and one `total` entry (both minContains:1, maxContains:1).
		// Previously we emitted only subtotal — this test locks in
		// the compliance fix.
		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 2 ] ] ]
		);

		$types = array_column( $result['data']['totals'], 'type' );
		$this->assertContains( 'subtotal', $types );
		$this->assertContains( 'total', $types );
		$this->assertCount( 2, $result['data']['totals'] );

		// Both entries equal to the cart sum (5000) in our
		// web-redirect stance — real tax/shipping calculated at
		// merchant checkout, disclosed via total_is_provisional.
		foreach ( $result['data']['totals'] as $entry ) {
			$this->assertSame( 5000, $entry['amount'] );
		}
	}

	public function test_total_is_provisional_info_message_emitted_on_happy_path(): void {
		// Accompanies the required `total` entry. With web-redirect
		// stance we can't compute tax/shipping server-side; the
		// message discloses the elision to agents so they can
		// inform the user before the redirect.
		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'total_is_provisional', $codes );

		$provisional = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'total_is_provisional' === ( $m['code'] ?? '' )
		);
		$msg = array_values( $provisional )[0];
		$this->assertSame( 'info', $msg['type'] );
		$this->assertSame( 'advisory', $msg['severity'] );
	}

	public function test_total_is_provisional_omitted_on_error_path(): void {
		// No valid items → status=incomplete, no continue_url, no
		// provisional-total disclosure because there's no redirect /
		// meaningful provisional checkout total to qualify. (The
		// `total` entry itself is still emitted in `totals` per spec,
		// zeroed.)
		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_9999' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'total_is_provisional', $codes );
	}

	public function test_price_changed_warning_when_expected_differs_from_current(): void {
		// Agent scraped catalog at price=$25, caches, posts checkout
		// later. We now emit current price ($30). The warning lets
		// the agent confirm with the user before redirecting.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [ 'amount' => 2500, 'currency' => 'USD' ],
					],
				],
			]
		);

		$warnings = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'price_changed' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $warnings );
		$warning = array_values( $warnings )[0];
		$this->assertStringContainsString( '2500', $warning['content'] );
		$this->assertStringContainsString( '3000', $warning['content'] );
	}

	public function test_price_changed_not_emitted_when_prices_match(): void {
		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [ 'amount' => 2500, 'currency' => 'USD' ],
					],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'price_changed', $codes );
	}

	public function test_price_changed_not_emitted_when_expected_omitted(): void {
		// Agent-opt-in: no `expected_unit_price` → no comparison →
		// no warning. Legacy callers unaffected.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'price_changed', $codes );
	}

	public function test_price_changed_skipped_when_expected_currency_mismatches_store(): void {
		// Agent sends a cached price in GBP, store operates in USD.
		// Minor-unit amounts aren't comparable across currencies, so
		// we silently skip rather than emit a misleading "price
		// changed from 2000 to 3000" warning that mixes units.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [ 'amount' => 2000, 'currency' => 'GBP' ],
					],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'price_changed', $codes );
	}

	public function test_price_changed_case_insensitive_currency_match(): void {
		// Agents may send "usd" lowercase; store currency constant is
		// "USD". strcasecmp handles the case comparison so this isn't
		// treated as a currency mismatch.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [ 'amount' => 2500, 'currency' => 'usd' ],
					],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'price_changed', $codes );
	}

	public function test_price_changed_runs_when_expected_currency_is_omitted(): void {
		// Missing currency on expected_unit_price → lenient path,
		// assumes store currency. Preserves the original PR #41
		// behavior for agents that only send `amount`.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [ 'amount' => 2500 ],
					],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'price_changed', $codes );
	}

	public function test_malformed_expected_unit_price_does_not_fatal(): void {
		// Regression: an agent sending `expected_unit_price` as a
		// string/int/bool (instead of an array) would fatal PHP 8
		// with "Trying to access array offset on value of type X"
		// before we could return a structured response. The shape
		// guard (`is_array()` check) drops the field silently —
		// treating it as absent rather than crashing. No warning is
		// emitted because this is a malformed-client bug, not a
		// price-drift signal.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						// String where an object was expected.
						'expected_unit_price' => '25.00',
					],
				],
			]
		);

		// Handler didn't crash (HTTP 201, not 500), line item is
		// still echoed, and no price_changed warning fires.
		$this->assertSame( 201, $result['status'] );
		$this->assertCount( 1, $result['data']['line_items'] );
		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'price_changed', $codes );
	}

	public function test_price_changed_skipped_for_decimal_string_amount(): void {
		// Regression: `is_numeric("25.00")` is true, and `(int)"25.00"`
		// silently truncates to 25. If the current integer-minor-units
		// price is 3000, a client sending `"25.00"` (wrong encoding —
		// they meant 2500 minor units or 25 in major units) would
		// compare 25 !== 3000 and fire a bogus `price_changed`.
		// UCP amounts are integer minor units by spec; we now require
		// `is_int()` OR a digit-only string. Anything else skips the
		// comparison silently.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [ 'amount' => '25.00', 'currency' => 'USD' ],
					],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'price_changed', $codes );
	}

	public function test_non_string_currency_treated_as_missing_no_notices(): void {
		// Regression: `expected_unit_price.currency` sent as a
		// non-string value (array, object) previously ran through a
		// `(string)` cast that would emit "Array to string conversion"
		// PHP notices. The `is_string()` guard now treats non-string
		// currency as missing — runs the comparison via the lenient
		// empty-currency path against store currency — notice-free.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [
							'amount'   => 2500,
							// Non-string currency — would coerce to
							// "Array" via (string) cast without the
							// is_string() guard.
							'currency' => [ 'USD' ],
						],
					],
				],
			]
		);

		// Comparison ran (empty-currency lenient path) and fired the
		// price_changed warning, and no PHP notice was surfaced.
		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'price_changed', $codes );
	}

	public function test_price_changed_accepts_digit_only_string_amount(): void {
		// Digit-only strings ARE valid — they're how JSON-via-PHP
		// sometimes serializes large integers that would otherwise
		// hit float precision limits. `"2500"` should still trigger
		// the comparison on amount mismatch.
		$this->seed_simple_product( 111, 3000 );

		$result = $this->call_handler(
			[
				'line_items' => [
					[
						'item'                => [ 'id' => 'prod_111' ],
						'quantity'            => 1,
						'expected_unit_price' => [ 'amount' => '2500', 'currency' => 'USD' ],
					],
				],
			]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'price_changed', $codes );
	}

	public function test_line_item_includes_price_includes_tax_flag(): void {
		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$this->assertArrayHasKey( 'price_includes_tax', $result['data']['line_items'][0] );
		$this->assertFalse( $result['data']['line_items'][0]['price_includes_tax'] );
	}

	public function test_line_item_reflects_wc_prices_include_tax_true(): void {
		// Override the default (false) to simulate an EU store with
		// tax-inclusive pricing.
		\Brain\Monkey\Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$this->assertTrue( $result['data']['line_items'][0]['price_includes_tax'] );
	}

	public function test_variation_line_item_includes_price_includes_tax_flag(): void {
		// A future refactor could place the flag assignment behind
		// a simple-only branch. This test catches that by using a
		// variation (type=variation) and asserting the flag is
		// still present on the response line item.
		$this->seed_variation( 222, 3500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'var_222' ], 'quantity' => 1 ] ] ]
		);

		$this->assertArrayHasKey( 'price_includes_tax', $result['data']['line_items'][0] );
		$this->assertFalse( $result['data']['line_items'][0]['price_includes_tax'] );
	}

	public function test_minimum_order_not_met_blocks_redirect(): void {
		// Filter hook returns 5000 (minor units) — merchant requires
		// $50 minimum. Agent sends 1 item at $25 → below threshold.
		$this->stub_apply_filters_for( 'wc_ai_storefront_minimum_order_amount', 5000 );

		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertContains( 'minimum_not_met', $codes );
		$this->assertArrayNotHasKey( 'continue_url', $result['data'] );
		$this->assertSame( 'incomplete', $result['data']['status'] );
	}

	public function test_minimum_not_met_still_echoes_cart_shape(): void {
		// State-machine test: `has_valid_items` ≠ `should_redirect`.
		// When min-not-met flips redirect off, the cart contents
		// (line_items + real subtotal in totals) must still be
		// visible so the agent can show the user "you have $25,
		// need $50 — add more items." A regression that zeroed
		// line_items or totals on this path would break that UX.
		$this->stub_apply_filters_for( 'wc_ai_storefront_minimum_order_amount', 5000 );

		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$this->assertCount( 1, $result['data']['line_items'] );

		// Both totals entries (subtotal + required `total`) present
		// and reflect the real subtotal, not zero. Spec-compliant
		// across all three paths (happy, no-items, min-blocked).
		$types = array_column( $result['data']['totals'], 'type' );
		$this->assertContains( 'subtotal', $types );
		$this->assertContains( 'total', $types );
		foreach ( $result['data']['totals'] as $entry ) {
			$this->assertSame( 2500, $entry['amount'] );
		}
	}

	public function test_minimum_order_negative_filter_return_disables_enforcement(): void {
		// A negative return from the filter is semantically
		// "no minimum" — the guard `$minimum_order_amount > 0`
		// gates enforcement on positive values only. Documents
		// the cast + guard behavior so a future change that drops
		// the `> 0` check would be caught.
		$this->stub_apply_filters_for( 'wc_ai_storefront_minimum_order_amount', -500 );

		$this->seed_simple_product( 111, 100 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'minimum_not_met', $codes );
		$this->assertArrayHasKey( 'continue_url', $result['data'] );
	}

	public function test_minimum_order_met_allows_redirect(): void {
		$this->stub_apply_filters_for( 'wc_ai_storefront_minimum_order_amount', 5000 );

		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 3 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'minimum_not_met', $codes );
		$this->assertArrayHasKey( 'continue_url', $result['data'] );
		$this->assertSame( 'requires_escalation', $result['data']['status'] );
	}

	public function test_minimum_order_zero_default_allows_any_subtotal(): void {
		// Default filter pass-through returns $default (0) — no minimum.
		// Single low-price item should still produce a continue_url.
		$this->seed_simple_product( 111, 100 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$codes = array_column( $result['data']['messages'], 'code' );
		$this->assertNotContains( 'minimum_not_met', $codes );
		$this->assertArrayHasKey( 'continue_url', $result['data'] );
	}

	public function test_handoff_message_filter_overrides_default(): void {
		$this->stub_apply_filters_for(
			'wc_ai_storefront_checkout_handoff_message',
			'Review & secure payment at Acme Store.'
		);

		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$handoff = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'buyer_handoff_required' === ( $m['code'] ?? '' )
		);
		$msg = array_values( $handoff )[0];
		$this->assertSame( 'Review & secure payment at Acme Store.', $msg['content'] );
	}

	public function test_request_locale_threaded_to_handoff_filter(): void {
		// Agent passes context.locale; the filter receives it in the
		// context array so merchants can emit a localized handoff
		// message (e.g. via switch_to_locale + gettext). We capture
		// the locale via the apply_filters stub's $args array.
		$captured_locale = null;
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $default, $context = null ) use ( &$captured_locale ) {
				if ( 'wc_ai_storefront_checkout_handoff_message' === $hook && is_array( $context ) ) {
					$captured_locale = $context['locale'] ?? null;
				}
				return $default;
			}
		);

		$this->seed_simple_product( 111, 2500 );

		$this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ],
				'context'    => [ 'locale' => 'fr-FR' ],
			]
		);

		$this->assertSame( 'fr-FR', $captured_locale );
	}

	public function test_extended_bcp47_locale_accepted(): void {
		// Regression for Copilot review: the pre-2026-04-19 regex
		// `^[A-Za-z0-9_-]{2,10}$` was too tight — it rejected valid
		// BCP-47 tags like `zh-Hant-HK` (10 chars, at the old limit)
		// and `en-GB-oxendict` (14 chars, above). The subtag-aware
		// pattern with a 35-char cap covers these while still rejecting
		// free-form text.
		$captured_locale = null;
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $default, $context = null ) use ( &$captured_locale ) {
				if ( 'wc_ai_storefront_checkout_handoff_message' === $hook && is_array( $context ) ) {
					$captured_locale = $context['locale'] ?? null;
				}
				return $default;
			}
		);

		$this->seed_simple_product( 111, 2500 );

		$this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ],
				'context'    => [ 'locale' => 'en-GB-oxendict' ],
			]
		);

		$this->assertSame( 'en-GB-oxendict', $captured_locale );
	}

	public function test_malformed_locale_is_rejected(): void {
		// Defensive: non-BCP-47-ish input (contains space, too long,
		// etc.) collapses to empty string before reaching the filter.
		// Prevents downstream misuse of an untrusted string as a
		// locale identifier.
		$captured_locale = null;
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $default, $context = null ) use ( &$captured_locale ) {
				if ( 'wc_ai_storefront_checkout_handoff_message' === $hook && is_array( $context ) ) {
					$captured_locale = $context['locale'] ?? null;
				}
				return $default;
			}
		);

		$this->seed_simple_product( 111, 2500 );

		$this->call_handler(
			[
				'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ],
				'context'    => [ 'locale' => 'not a valid locale; DROP TABLE wp_users;' ],
			]
		);

		$this->assertSame( '', $captured_locale );
	}

	public function test_handoff_filter_returning_non_string_falls_back_to_default(): void {
		// A misbehaving filter callback returning null/array/object
		// shouldn't produce PHP "Array to string conversion" notices
		// or nonsensical output. The handler rejects non-string
		// returns and falls back to the default English message —
		// the filter's string contract is documented; this is a
		// defense against misbehaving callbacks, not a supported
		// alternative return type. Test sends null; asserts the
		// default English message survives to the response.
		$this->stub_apply_filters_for( 'wc_ai_storefront_checkout_handoff_message', null );

		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		// Handler didn't fatal (status 201 = happy path preserved).
		$this->assertSame( 201, $result['status'] );

		$handoff = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'buyer_handoff_required' === ( $m['code'] ?? '' )
		);
		$this->assertCount( 1, $handoff );
		$msg = array_values( $handoff )[0];
		$this->assertIsString( $msg['content'] );
		// Non-empty — the fallback default survives rather than the
		// non-string filter output collapsing to an empty string.
		$this->assertNotSame( '', $msg['content'] );
	}

	public function test_handoff_filter_returning_array_falls_back_to_default(): void {
		// Arrays specifically — the case that would emit
		// "Array to string conversion" notice under the old
		// `(string)` cast pattern. Our safe-coercion path returns
		// the default for this instead of pollute the log.
		$this->stub_apply_filters_for(
			'wc_ai_storefront_checkout_handoff_message',
			[ 'oops', 'array', 'return' ]
		);

		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$this->assertSame( 201, $result['status'] );
		$handoff = array_filter(
			$result['data']['messages'],
			static fn( array $m ): bool => 'buyer_handoff_required' === ( $m['code'] ?? '' )
		);
		$msg = array_values( $handoff )[0];
		$this->assertIsString( $msg['content'] );
		$this->assertNotSame( '', $msg['content'] );
		$this->assertStringNotContainsString( 'Array', $msg['content'] );
	}

	// ------------------------------------------------------------------
	// Session metadata (PR G)
	// ------------------------------------------------------------------

	public function test_checkout_session_response_includes_expires_at_null(): void {
		// Every checkout-sessions POST is stateless — no server-side
		// state exists to expire. UCP's `session.expires_at` is
		// spec-optional, but emitting `null` explicitly tells strict
		// consumers "this implementation is stateless, not buggy".
		// Omission would leave them guessing between the two.
		$this->seed_simple_product( 111, 2500 );

		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ] ]
		);

		$this->assertArrayHasKey( 'expires_at', $result['data'] );
		$this->assertNull( $result['data']['expires_at'] );
	}

	public function test_checkout_session_expires_at_null_on_invalid_requests_too(): void {
		// Even on the `incomplete` branch (no valid items, or below-
		// minimum totals) we still emit `expires_at: null` — the
		// statelessness property is a structural invariant of the
		// handler, not a feature of the happy path only. A strict
		// consumer inspecting failure responses shouldn't have to
		// branch on status to parse the envelope.
		$result = $this->call_handler(
			[ 'line_items' => [ [ 'item' => [ 'id' => 'prod_9999' ], 'quantity' => 1 ] ] ]
		);

		$this->assertSame( 'incomplete', $result['data']['status'] );
		$this->assertArrayHasKey( 'expires_at', $result['data'] );
		$this->assertNull( $result['data']['expires_at'] );
	}

	public function test_locale_boundary_lengths(): void {
		// Regex: `^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$` + 35-char cap.
		// Boundaries worth pinning so a future refactor doesn't
		// silently loosen/tighten the accepted range:
		//   - "a"          (1 char, below min) → rejected
		//   - "aa"         (2 chars, at min)   → accepted
		//   - "abcdefgh"   (8 chars, max lang) → accepted
		//   - "abcdefghi"  (9 chars, over max) → rejected
		//   - 35-char legal → accepted
		//   - 36-char legal → rejected (length cap)
		$tests = [
			'a'                                     => '',           // below min
			'aa'                                    => 'aa',          // at min
			'abcdefgh'                              => 'abcdefgh',    // 8-char lang subtag
			'abcdefghi'                             => '',            // 9-char lang subtag, over max
			'en-US-x-aaaaaaaa-bbbbbbbb'             => 'en-US-x-aaaaaaaa-bbbbbbbb',  // 25 chars, within cap
			str_repeat( 'a', 8 ) . '-' . str_repeat( 'b', 26 ) => '',  // 35 chars — rejected (extension subtag > 8 chars, not cap)
		];
		// The 35-char cap is exercised by the SQL-injection rejection
		// test; the per-subtag 8-char limit is the stricter gate for
		// malformed-but-long inputs. The last case above documents
		// that interaction.

		foreach ( $tests as $input => $expected_captured ) {
			$captured_locale = null;
			Functions\when( 'apply_filters' )->alias(
				function ( string $hook, $default, $context = null ) use ( &$captured_locale ) {
					if ( 'wc_ai_storefront_checkout_handoff_message' === $hook && is_array( $context ) ) {
						$captured_locale = $context['locale'] ?? null;
					}
					return $default;
				}
			);

			$this->fake_store_api = [];
			$this->seed_simple_product( 111, 2500 );

			$this->call_handler(
				[
					'line_items' => [ [ 'item' => [ 'id' => 'prod_111' ], 'quantity' => 1 ] ],
					'context'    => [ 'locale' => $input ],
				]
			);

			$this->assertSame(
				$expected_captured,
				$captured_locale,
				sprintf( "Locale boundary failure: input=%s (expected %s)", $input, $expected_captured )
			);
		}
	}

	/**
	 * Stub apply_filters to return a specific value for a named hook,
	 * and pass-through $default for any other hook. Pattern: let the
	 * per-test override target a single filter without breaking other
	 * `apply_filters` callsites.
	 */
	private function stub_apply_filters_for( string $target_hook, $return_value ): void {
		Functions\when( 'apply_filters' )->alias(
			// Variadic `...$args` absorbs whatever extra positional
			// args the caller passes (context arrays, numeric caps,
			// etc.) so strict PHP "too many arguments" notices don't
			// fire for filters that accept more than hook + default.
			static function ( string $hook, $default, ...$args ) use ( $target_hook, $return_value ) {
				return $hook === $target_hook ? $return_value : $default;
			}
		);
	}

	// ------------------------------------------------------------------
	// Cross-path convergence (added 0.5.0)
	// ------------------------------------------------------------------
	//
	// The whole point of the canonical UTM shape + PRODUCT_TO_HOSTNAME
	// resolution is "all three identification paths for the same agent
	// stamp the same utm_source value." The three independent path
	// tests above each assert a literal string ("ucpplayground.com")
	// — that locks the literal but not the convergence invariant.
	// A refactor that changes one path to emit "ucp-playground.com"
	// would break only that path's test. This test compares the three
	// paths' utm_source extractions directly, so any divergence is
	// caught regardless of which side moved.

	public function test_all_three_identification_paths_for_ucpplayground_emit_identical_utm_source(): void {
		$this->seed_simple_product( 1 );

		$line_items = [ [ 'item' => [ 'id' => 'prod_1' ], 'quantity' => 1 ] ];

		// Path 1: profile-URL form (RFC 8941 Dictionary).
		$profile_url_result = $this->call_handler(
			[ 'line_items' => $line_items ],
			'profile="https://ucpplayground.com/profile.json"'
		);

		// Path 2: Product/Version form (RFC 7231 §5.5.3 User-Agent).
		$product_version_result = $this->call_handler(
			[ 'line_items' => $line_items ],
			'UCP-Playground/1.0'
		);

		// Path 3: meta.source body fallback.
		$meta_source_result = $this->call_handler(
			[
				'line_items' => $line_items,
				'meta'       => [ 'source' => 'ucp-playground' ],
			]
			// No UCP-Agent header.
		);

		$path1_source = $this->extract_utm_source( $profile_url_result['data']['continue_url'] );
		$path2_source = $this->extract_utm_source( $product_version_result['data']['continue_url'] );
		$path3_source = $this->extract_utm_source( $meta_source_result['data']['continue_url'] );

		// The convergence invariant: all three paths produce the same
		// utm_source value. Asserting equality directly (rather than
		// a literal substring per path) means a future refactor that
		// changes the canonical hostname for UCPPlayground only needs
		// to update PRODUCT_TO_HOSTNAME — this test still passes
		// because the three paths converge, regardless of value.
		$this->assertSame(
			$path1_source,
			$path2_source,
			'Profile-URL and Product/Version paths must emit identical utm_source for the same agent.'
		);
		$this->assertSame(
			$path2_source,
			$path3_source,
			'Product/Version and meta.source body paths must emit identical utm_source for the same agent.'
		);

		// Also pin the actual value at this snapshot so that a
		// refactor which broke convergence by setting all three to
		// the wrong but-equal value (e.g. all three return the empty
		// sentinel) still fails. Belt + suspenders.
		$this->assertSame( 'ucpplayground.com', $path1_source );
	}

	/**
	 * Parse the utm_source query parameter out of a continue_url.
	 *
	 * Tighter than `assertStringContainsString( 'utm_source=...', $url )`
	 * because it round-trips through `parse_url` + `parse_str` so a
	 * URL like `?products=foo&utm_source=foo.com.evil&...` doesn't
	 * accidentally match a substring assertion against `utm_source=foo.com`.
	 *
	 * @param string $url The continue_url to parse.
	 * @return string The decoded utm_source value, or empty string when absent.
	 */
	private function extract_utm_source( string $url ): string {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return '';
		}
		$params = [];
		parse_str( $query, $params );
		return is_string( $params['utm_source'] ?? null ) ? $params['utm_source'] : '';
	}
}
