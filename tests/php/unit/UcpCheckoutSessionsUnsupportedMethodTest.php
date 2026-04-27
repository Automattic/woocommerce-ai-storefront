<?php
/**
 * Tests for the unsupported-method stub on /checkout-sessions/{id}
 * (`WC_AI_Storefront_UCP_REST_Controller::handle_checkout_sessions_unsupported_method`).
 *
 * The handler exists to give UCP agents a structured 405 response
 * when they try to read, replace, modify, or cancel a checkout
 * session — verbs that come naturally from a stateful-session
 * mental model (GET to look up status, PUT to replace, PATCH to
 * modify, DELETE to cancel). Without the route, those requests
 * land at WP REST's generic `rest_no_route` 404, which agents
 * misread as "the session doesn't exist" or "the API is broken."
 *
 * Our session model is stateless — every POST is a fresh
 * computation returning a one-shot Shareable Checkout redirect.
 * There's no session state for any of the four unsupported verbs
 * to act on. The handler returns the same 405 envelope regardless
 * of method, with a verb-agnostic explanatory message pointing the
 * agent back at the POST flow.
 *
 * What these tests pin:
 *   - HTTP 405 status (RFC 7231 "Method Not Allowed")
 *   - `Allow: POST` header — see the handler docblock for the
 *     deliberate ergonomic divergence from a strict RFC 7231 §6.5.5
 *     reading
 *   - Full UCP checkout-envelope shape — same fields as the success
 *     path so strict UCP clients can parse both through one pipeline
 *   - Error message: code=`unsupported_operation`,
 *     severity=`unrecoverable`, content explains the stateless model
 *     and points the agent at the POST flow
 *   - Session-id echo: the `id` from the URL is preserved in the
 *     response so the agent's correlation thread isn't broken
 *   - Empty line_items + empty links + zeroed subtotal/total — same
 *     defensive shape as the validation-error path on POST
 *   - Verb-agnostic behavior: GET, PUT, PATCH, and DELETE all
 *     produce the same response shape (proven via parameterized
 *     `verb_provider` tests below)
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpCheckoutSessionsUnsupportedMethodTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		WC_AI_Storefront::$test_settings = [];

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke the handler with a given HTTP verb. Default `PATCH` so
	 * tests written before the GET/PUT/DELETE expansion still pin
	 * the original surface; the verb-agnostic tests use
	 * `verb_provider()` to fan out across all four.
	 */
	private function call_patch( string $session_id, string $verb = 'PATCH' ): WP_REST_Response {
		$request = new WP_REST_Request( $verb, '/wc/ucp/v1/checkout-sessions/' . $session_id );
		$request->set_param( 'id', $session_id );

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_checkout_sessions_unsupported_method( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * Data provider for the four unsupported verbs. Each test that
	 * needs to prove verb-agnostic behavior consumes this provider
	 * so a future regression that special-cases one verb (e.g.
	 * starts handling DELETE statefully) fires here, not somewhere
	 * downstream.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function verb_provider(): array {
		return [
			'GET'    => [ 'GET' ],
			'PUT'    => [ 'PUT' ],
			'PATCH'  => [ 'PATCH' ],
			'DELETE' => [ 'DELETE' ],
		];
	}

	// ------------------------------------------------------------------
	// HTTP-level contract: 405 + Allow header
	// ------------------------------------------------------------------

	public function test_returns_http_405_method_not_allowed(): void {
		// 405 is the RFC 7231 status for "method exists on the server,
		// just not on this resource." A 400 or 501 would mis-describe
		// the situation and lead agents to give up the session
		// instead of retrying via POST.
		$response = $this->call_patch( 'chk_abcdef0123456789' );

		$this->assertSame( 405, $response->get_status() );
	}

	public function test_response_includes_allow_post_header(): void {
		// `Allow: POST` is an ergonomic hint pointing at the parent
		// collection's verb. See the handler docblock for why this
		// deliberately diverges from a strict RFC 7231 §6.5.5 reading
		// of `Allow` (which would say empty here, because
		// /checkout-sessions/{id} accepts no methods directly). Agents
		// that handle 405 generically can read this and retry with
		// the correct verb without re-fetching the UCP manifest.
		$response = $this->call_patch( 'chk_abcdef0123456789' );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Allow', $headers );
		$this->assertSame( 'POST', $headers['Allow'] );
	}

	/**
	 * Verb-agnostic behavior across the four unsupported methods.
	 * One test, four invocations — proves that GET, PUT, PATCH, and
	 * DELETE all collapse to the same 405 + envelope shape. A
	 * future regression that special-cases one verb (e.g. starts
	 * returning a 200 from DELETE thinking it's a "cancellation
	 * acknowledgment") fires here.
	 *
	 * @dataProvider verb_provider
	 */
	public function test_all_unsupported_verbs_return_same_405_envelope( string $verb ): void {
		$response = $this->call_patch( 'chk_abcdef0123456789', $verb );

		$this->assertSame( 405, $response->get_status(), "Verb {$verb} must return 405" );

		$data = $response->get_data();
		$this->assertSame( 'incomplete', $data['status'] );
		$this->assertSame( 'unsupported_operation', $data['messages'][0]['code'] );
		$this->assertSame( 'unrecoverable', $data['messages'][0]['severity'] );

		// Allow header pinned per-verb too — the ergonomic hint
		// must not regress to empty or to the verb itself for any
		// of the four.
		$this->assertSame( 'POST', $response->get_headers()['Allow'] );
	}

	// ------------------------------------------------------------------
	// UCP-envelope shape parity with the success/error paths
	// ------------------------------------------------------------------

	public function test_response_carries_full_checkout_envelope_shape(): void {
		// Strict UCP clients parse success and failure through a single
		// pipeline that expects every field in the checkout envelope.
		// A regression dropping any of these would break those
		// clients; enumerating them here forces the regression to
		// surface in this test rather than in field telemetry.
		$response = $this->call_patch( 'chk_abcdef0123456789' );
		$data     = $response->get_data();

		foreach ( [ 'ucp', 'id', 'status', 'currency', 'line_items', 'totals', 'links', 'messages' ] as $field ) {
			$this->assertArrayHasKey(
				$field,
				$data,
				"Missing required UCP checkout envelope field: {$field}"
			);
		}
	}

	public function test_status_is_incomplete(): void {
		// UCP 2026-04-08 status enum: incomplete | requires_escalation
		// | ready_for_complete | complete_in_progress | completed |
		// canceled. `incomplete` is the closest match for "no
		// successful action took place" — same value the validation-
		// error path on POST uses, keeping the failure-shape
		// consistent across the two unsupported entry points.
		$response = $this->call_patch( 'chk_abcdef0123456789' );

		$this->assertSame( 'incomplete', $response->get_data()['status'] );
	}

	public function test_currency_is_woocommerce_currency(): void {
		// Mirrors the success-path currency derivation so a strict
		// UCP client that asserts on `currency` doesn't have to
		// special-case the unsupported-operation path.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );

		$response = $this->call_patch( 'chk_abcdef0123456789' );

		$this->assertSame( 'EUR', $response->get_data()['currency'] );
	}

	// Coverage note: the `function_exists( 'get_woocommerce_currency' )`
	// false branch (USD fallback when WC is somehow not loaded — mid-
	// deploy ordering hazard, dev environments with WC disabled but
	// our plugin enabled) is structurally identical to the fallback
	// used in `ucp_checkout_error_response` and
	// `handle_checkout_sessions_create`. Brain Monkey's `Functions\when()`
	// works by routing function calls through Patchwork's
	// instrumentation; that instrumentation cannot redefine PHP
	// built-ins like `function_exists`, so a unit test that flips the
	// guard's outcome at runtime isn't expressible without forking
	// the test harness. Treating this as covered transitively by the
	// sister-handler tests rather than introducing a custom test
	// runner just for one defensive line.

	public function test_line_items_and_links_are_empty(): void {
		// No items processed, no legal links emitted — the resource
		// did nothing. Empty arrays (not null, not absent) match the
		// schema's required-but-empty contract that the validation-
		// error path also uses.
		$response = $this->call_patch( 'chk_abcdef0123456789' );
		$data     = $response->get_data();

		$this->assertSame( [], $data['line_items'] );
		$this->assertSame( [], $data['links'] );
	}

	public function test_totals_carry_zero_subtotal_and_zero_total(): void {
		// UCP spec: totals MUST contain exactly one `subtotal` AND one
		// `total` entry (both minContains:1, maxContains:1). Zero
		// amount on the unsupported-operation path; the message
		// carries the semantic that nothing was processed.
		$response = $this->call_patch( 'chk_abcdef0123456789' );
		$totals   = $response->get_data()['totals'];

		$this->assertCount( 2, $totals );

		$by_type = [];
		foreach ( $totals as $entry ) {
			$by_type[ $entry['type'] ] = $entry['amount'];
		}

		$this->assertArrayHasKey( 'subtotal', $by_type );
		$this->assertArrayHasKey( 'total', $by_type );
		$this->assertSame( 0, $by_type['subtotal'] );
		$this->assertSame( 0, $by_type['total'] );
	}

	// ------------------------------------------------------------------
	// Error-message contract
	// ------------------------------------------------------------------

	public function test_messages_contain_unsupported_operation_error(): void {
		// The message is the only place an agent learns WHY they got
		// 405. Pin code + severity + non-empty content so a regression
		// that drops any of those is loud.
		$response = $this->call_patch( 'chk_abcdef0123456789' );
		$messages = $response->get_data()['messages'];

		$this->assertCount( 1, $messages );

		$message = $messages[0];
		$this->assertSame( 'error', $message['type'] );
		$this->assertSame( 'unsupported_operation', $message['code'] );
		$this->assertSame( 'unrecoverable', $message['severity'] );
		$this->assertNotEmpty( $message['content'] );
	}

	public function test_message_content_points_at_post_flow(): void {
		// Content quality check: the merchant-/agent-facing message
		// must mention `POST` and `/checkout-sessions` so agents
		// that string-parse the message (instead of branching on
		// `code`) still get pointed at the right next step. This
		// test couples mildly to the wording but the value of
		// catching a content regression that omits the action
		// guidance outweighs the maintenance cost — if the wording
		// changes, both tokens should still be present.
		$response = $this->call_patch( 'chk_abcdef0123456789' );
		$content  = $response->get_data()['messages'][0]['content'];

		$this->assertStringContainsString( 'POST', $content );
		$this->assertStringContainsString( '/checkout-sessions', $content );
	}

	// ------------------------------------------------------------------
	// Session-ID handling
	// ------------------------------------------------------------------

	public function test_session_id_is_echoed_from_request(): void {
		// Preserves the agent's correlation thread. Even though we
		// hold no state, the agent uses the session ID for client-
		// side bookkeeping; rewriting it would break that.
		$response = $this->call_patch( 'chk_thecorrelationtoken' );

		$this->assertSame( 'chk_thecorrelationtoken', $response->get_data()['id'] );
	}

	public function test_session_id_synthesized_when_missing(): void {
		// Defense-in-depth: if a future regex relaxation lets the
		// route capture an empty `id`, the handler still produces a
		// schema-conformant `id` field rather than emitting `''`
		// (which strict UCP clients would reject — `id` is non-empty
		// per the schema). Synthesize the same `chk_<hex>` shape the
		// POST handler uses so the merchant-facing surface is
		// indistinguishable.
		$request = new WP_REST_Request( 'PATCH', '/wc/ucp/v1/checkout-sessions/' );
		// Don't set the `id` param — simulate the captured value
		// being empty/missing.
		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_checkout_sessions_unsupported_method( $request );
		$id         = $response->get_data()['id'];

		$this->assertIsString( $id );
		$this->assertMatchesRegularExpression( '/^chk_[a-f0-9]{16}$/', $id );
	}

	public function test_session_id_synthesized_when_non_string(): void {
		// Same defense-in-depth: a route param that arrives as a
		// non-string (filter chain mishandle, etc.) shouldn't crash.
		// `is_string()` guard kicks in, fresh `chk_<hex>` issued.
		$request = new WP_REST_Request( 'PATCH', '/wc/ucp/v1/checkout-sessions/' );
		$request->set_param( 'id', [ 'array', 'not', 'string' ] );

		$controller = new WC_AI_Storefront_UCP_REST_Controller();
		$response   = $controller->handle_checkout_sessions_unsupported_method( $request );
		$id         = $response->get_data()['id'];

		$this->assertIsString( $id );
		$this->assertMatchesRegularExpression( '/^chk_[a-f0-9]{16}$/', $id );
	}
}
