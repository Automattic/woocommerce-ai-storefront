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

		// count_ai_orders() calls $wpdb->prepare() + $wpdb->get_var() for
		// the pagination COUNT(DISTINCT id). Default to '0' so existing tests
		// that don't care about the total field don't need to set up $wpdb.
		$this->make_wpdb_mock();

		$this->controller = new WC_AI_Storefront_Admin_Controller();

		Functions\when( 'sanitize_key' )->alias(
			static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) )
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $v ) => trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) )
		);

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
		Functions\when( '__' )->alias( static fn( $text ) => $text );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url = '' ) {
				if ( is_array( $args ) ) {
					return $url . '?' . http_build_query( $args );
				}
				return $url;
			}
		);
		Functions\when( 'human_time_diff' )->justReturn( '5 minutes' );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = null;
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
	 * Set up a global $wpdb Mockery mock for count_ai_orders() callers.
	 *
	 * @param string|null $get_var_return Value returned by get_var(). Defaults to '0' (DB string).
	 */
	private function make_wpdb_mock( ?string $get_var_return = '0' ): void {
		global $wpdb;
		$wpdb           = \Mockery::mock( 'wpdb' );
		$wpdb->prefix   = 'wp_';
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'esc_like' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( $get_var_return );
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
			'customer',
			'customer_url',
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

	// ------------------------------------------------------------------
	// Contract: customer field + customer_url
	// ------------------------------------------------------------------

	public function test_registered_customer_order_sets_customer_url(): void {
		// Registered customers (customer_id > 0) must produce a
		// customer_url pointing to the WooCommerce orders list filtered
		// by that customer (?page=wc-orders&_customer_user={id}&status=all)
		// so the DataViews Customer column can render a clickable link.
		$order = $this->make_order();
		$order->set_test_customer_id( 5 );
		$order->set_test_billing_first_name( 'Jane' );
		$order->set_test_billing_last_name( 'Doe' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame( 'Jane Doe', $row['customer'] );
		$this->assertStringContainsString( 'page=wc-orders', $row['customer_url'] );
		$this->assertStringContainsString( '_customer_user=5', $row['customer_url'] );
		$this->assertStringContainsString( 'status=all', $row['customer_url'] );
	}

	public function test_guest_order_has_empty_customer_url(): void {
		// Guest checkouts have no WP user account (customer_id = 0).
		// The customer_url must be an empty string so the JS
		// safeHref() guard returns null and the name renders as
		// plain text rather than a broken link.
		$order = $this->make_order();
		// customer_id stays 0 (stub default — no registered account).
		$order->set_test_billing_first_name( 'John' );
		$order->set_test_billing_last_name( 'Guest' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame( 'John Guest', $row['customer'] );
		$this->assertSame( '', $row['customer_url'] );
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

	public function test_unknown_hostname_agent_meta_buckets_to_other_ai(): void {
		// Novel agents not in KNOWN_AGENT_HOSTS bucket under the
		// "Other AI" label rather than scattering one Origin row per
		// novel hostname. The raw hostname stamped on the order
		// (`_wc_ai_storefront_agent_host_raw`) preserves provenance for
		// graduation review — see resolve_agent_host() docblock. Same
		// contract as the canonicalize_host() unit test, pinned at the
		// response layer.
		//
		// IMPORTANT: this assertion guards against a regression where
		// the admin Recent Orders endpoint would surface raw hostnames
		// in the agent column, which:
		//   (a) clutters merchant stats with one-off vendor names
		//   (b) erodes the "Top Agent" card's signal as novel hostnames
		//       proliferate
		//   (c) leaks internal hostnames to the merchant when a partner
		//       AI experiment is in flight
		// Bucketing into "Other AI" is the documented contract; this
		// test is the regression guard.
		$order = $this->make_order( 100, 'novel-agent.example.com' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			$row['agent']
		);
	}

	// ------------------------------------------------------------------
	// Regression guard: canonical brand names round-trip unchanged
	// ------------------------------------------------------------------

	/**
	 * @dataProvider canonical_brand_names_provider
	 */
	public function test_canonical_brand_names_pass_through_in_response( string $brand_name ): void {
		// Post-1.6.7 orders stamp `_wc_ai_storefront_agent` with the
		// CANONICAL brand name (e.g. "Gemini") rather than the raw
		// hostname. The display-time canonicalization in
		// `get_recent_orders` MUST treat already-canonical values as
		// already-canonical and pass them through unchanged.
		//
		// Pre-`canonicalize_host_idempotent`, the response handler
		// re-canonicalized via `canonicalize_host()`, which lower-cased
		// and looked up the brand string in `KNOWN_AGENT_HOSTS` (whose
		// keys are *hostnames* like `gemini.google.com`) — found
		// nothing — and bucketed every modern AI order as "Other AI".
		// This data-provider locks in the regression guard for the
		// full canonical roster.
		$order = $this->make_order( 7, $brand_name );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$row      = $response->get_data()['orders'][0];

		$this->assertSame( $brand_name, $row['agent'] );
	}

	public static function canonical_brand_names_provider(): array {
		return [
			'OpenAI ChatGPT'    => [ 'ChatGPT' ],
			'Anthropic Claude'  => [ 'Claude' ],
			'Google Gemini'     => [ 'Gemini' ],
			'Microsoft Copilot' => [ 'Copilot' ],
			'Perplexity'        => [ 'Perplexity' ],
			'Apple Siri'        => [ 'Siri' ],
			'Amazon Rufus'      => [ 'Rufus' ],
			'Klarna'            => [ 'Klarna' ],
			'You.com'           => [ 'You' ],
			'Kagi'              => [ 'Kagi' ],
			'UCPPlayground'     => [ 'UCPPlayground' ],
			'Other AI bucket'   => [ 'Other AI' ],
		];
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

	// ------------------------------------------------------------------
	// Regression guard: WP_Error from wc_get_orders() returns 200 +
	// empty payload instead of a PHP fatal (TypeError: not iterable)
	// ------------------------------------------------------------------

	public function test_wc_get_orders_wp_error_returns_empty_orders_payload(): void {
		// wc_get_orders() can return WP_Error on DB failure. Without the
		// is_wp_error() guard, iterating the WP_Error object would throw a
		// TypeError in PHP 8 ("WP_Error is not iterable"), producing a
		// 500 / fatal in the admin panel. With the guard, the endpoint
		// returns status 200 with an empty orders array so the admin UI
		// shows "no orders" instead of a stack trace.
		Functions\when( 'wc_get_orders' )->justReturn(
			new WP_Error( 'db_query_error', 'DB connection lost.' )
		);

		$response = $this->controller->get_recent_orders( $this->request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data['orders'] );
		$this->assertCount( 0, $data['orders'] );
		$this->assertSame( 0, $data['total'] );
		$this->assertArrayHasKey( 'currency', $data );
	}

	// ------------------------------------------------------------------
	// count_ai_orders() — the COUNT(*) path used for pagination totals
	// ------------------------------------------------------------------

	public function test_total_in_response_comes_from_count_ai_orders_not_full_id_fetch(): void {
		// Verifies that `total` in the REST response is the integer from the
		// COUNT(*) SQL path, not the length of the orders array. Override
		// $wpdb so get_var returns 42 while wc_get_orders returns 1 order:
		// the two values are independent.
		$this->make_wpdb_mock( '42' );

		Functions\when( 'wc_get_orders' )->justReturn( [ $this->make_order() ] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$data     = $response->get_data();

		$this->assertSame( 42, $data['total'] );
		$this->assertCount( 1, $data['orders'] );
	}

	public function test_count_ai_orders_returns_zero_when_db_returns_null(): void {
		// For this COUNT(*) query, "no matches" should still produce a row
		// with 0. A null from $wpdb->get_var() therefore represents an
		// error/failed query, and count_ai_orders() must cast null → 0
		// so the JSON response has a valid integer in the `total` field.
		$this->make_wpdb_mock( null );

		Functions\when( 'wc_get_orders' )->justReturn( [] );

		$response = $this->controller->get_recent_orders( $this->request() );
		$data     = $response->get_data();

		$this->assertSame( 0, $data['total'] );
	}
}
