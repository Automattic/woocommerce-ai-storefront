<?php
/**
 * Tests for WC_AI_Storefront_Admin_Controller::get_recent_orders().
 *
 * Structural / contract test: the frontend DataViews table depends
 * on specific keys in the response (id, number, date, date_display,
 * status, status_label, agent, total, currency, edit_url). An
 * accidental rename of any of those keys would silently blank
 * cells in the UI without breaking any other test — this file
 * locks the contract.
 *
 * Also pins the canonicalization behavior: legacy agent meta stored
 * as raw hostnames (e.g. `gemini.google.com`) must be mapped
 * through KNOWN_AGENT_HOSTS before landing in the response, so old
 * orders display as brand names in the AI Orders table even though
 * their stored meta still reads as the hostname.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AdminRecentOrdersTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Admin_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->controller = new WC_AI_Storefront_Admin_Controller();

		Functions\when( 'wc_get_order_statuses' )->justReturn(
			[
				'wc-pending'    => 'Pending payment',
				'wc-processing' => 'Processing',
				'wc-on-hold'    => 'On hold',
				'wc-completed'  => 'Completed',
				'wc-cancelled'  => 'Cancelled',
				'wc-refunded'   => 'Refunded',
				'wc-failed'     => 'Failed',
			]
		);

		Functions\when( 'wc_format_datetime' )->alias(
			static fn( $date ) => 'April 19, 2026'
		);

		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a request with the controller's expected per_page default.
	 */
	private function request( int $per_page = 10 ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'per_page', $per_page );
		return $req;
	}

	/**
	 * Build a synthetic order with canonical AI-attribution meta.
	 */
	private function make_order( int $id = 1, string $agent = 'Gemini' ): WC_Order {
		$order = new WC_Order();
		$order->set_test_id( $id );
		$order->set_test_number( (string) $id );
		$order->set_test_status( 'processing' );
		$order->set_test_total( '55.36' );
		$order->set_test_currency( 'USD' );
		$order->set_test_edit_url( "https://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id={$id}" );
		$order->set_test_date_created( new WC_DateTime_Stub() );
		$order->set_test_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY, $agent );
		return $order;
	}

	// ------------------------------------------------------------------
	// Contract: every expected key appears in the response row shape
	// ------------------------------------------------------------------

	public function test_response_row_has_all_keys_the_dataviews_table_renders(): void {
		// The frontend table's `fields` config references each of
		// these keys by name (see ai-orders-table.js). If any key
		// renames here, the corresponding cell blanks silently —
		// no exception, no test failure elsewhere. This assertion
		// locks the contract.
		Functions\when( 'wc_get_orders' )->justReturn( [ $this->make_order() ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'orders', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'currency', $data );

		$row = $data['orders'][0];
		$expected_keys = [
			'id',
			'number',
			'date',
			'date_display',
			'status',
			'status_label',
			'agent',
			'total',
			'currency',
			'edit_url',
		];
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$row,
				"Recent-orders row missing key `{$key}` — the AI Orders DataViews table will blank its corresponding cell."
			);
		}
	}

	public function test_status_label_comes_from_wc_get_order_statuses(): void {
		// The `status_label` field is the localized display text
		// WC itself uses on the native Orders list. Reading from
		// `wc_get_order_statuses()` keeps the labels consistent
		// across our table and WC's native screens.
		Functions\when( 'wc_get_orders' )->justReturn( [ $this->make_order() ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame( 'processing', $row['status'] );
		$this->assertSame( 'Processing', $row['status_label'] );
	}

	public function test_total_is_numeric_not_formatted(): void {
		// The frontend does locale-aware currency formatting via
		// Intl.NumberFormat — the REST response ships the raw
		// numeric total so the client controls presentation. A
		// change to pre-format on the server would break locale
		// fidelity for merchants on non-en-US stores.
		Functions\when( 'wc_get_orders' )->justReturn( [ $this->make_order() ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertIsFloat( $row['total'] );
		$this->assertSame( 55.36, $row['total'] );
	}

	// ------------------------------------------------------------------
	// Contract: legacy agent hostnames canonicalize at the response
	// ------------------------------------------------------------------

	public function test_legacy_hostname_agent_meta_canonicalizes_in_response(): void {
		// An order stored pre-1.6.7 will have `gemini.google.com` in
		// its `_wc_ai_storefront_agent` meta (the raw hostname from
		// the UCP-Agent header, before 1.6.7's canonicalization at
		// checkout-session time). Display-time canonicalization in
		// `get_recent_orders` must map it to `Gemini` so legacy
		// data looks consistent with new data in the AI Orders table.
		$order = $this->make_order( 42, 'gemini.google.com' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame( 'Gemini', $row['agent'] );
	}

	public function test_unknown_hostname_agent_meta_passes_through(): void {
		// Novel agents not in KNOWN_AGENT_HOSTS must not be blanked
		// or altered — they pass through verbatim so merchants
		// still see attribution for unmapped vendors. Same contract
		// as `canonicalize_host()` unit tests, pinned at the
		// response layer.
		$order = $this->make_order( 100, 'novel-agent.example.com' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame( 'novel-agent.example.com', $row['agent'] );
	}

	// ------------------------------------------------------------------
	// Contract: empty meta doesn't crash the response
	// ------------------------------------------------------------------

	public function test_empty_agent_meta_yields_empty_agent_string(): void {
		// Guard against the (unlikely) case where an order ended up
		// in `wc_get_orders()` results but lost its meta — the
		// handler shouldn't pass a blank string through
		// `canonicalize_host` (which would crash on empty input
		// per its guard).
		$order = new WC_Order();
		$order->set_test_id( 5 );
		$order->set_test_number( '5' );
		$order->set_test_status( 'processing' );
		$order->set_test_total( '10.00' );
		$order->set_test_currency( 'USD' );
		$order->set_test_edit_url( 'https://example.com/5' );
		$order->set_test_date_created( new WC_DateTime_Stub() );
		// No meta set — get_meta returns empty string.

		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame( '', $row['agent'] );
	}

	public function test_no_orders_returns_empty_array_not_null(): void {
		// The DataViews table distinguishes "not fetched yet" (null)
		// from "fetched, zero results" (empty array + total 0). The
		// server must never return null for `orders`.
		Functions\when( 'wc_get_orders' )->justReturn( [] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$data     = $response->get_data();

		$this->assertIsArray( $data['orders'] );
		$this->assertCount( 0, $data['orders'] );
		$this->assertSame( 0, $data['total'] );
	}
}
