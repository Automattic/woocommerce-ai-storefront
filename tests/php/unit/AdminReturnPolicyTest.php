<?php
/**
 * Tests for the `return_policy` field on the existing
 * `/admin/settings` REST endpoint (PR-C).
 *
 * Per the PR-C plan we extend the existing settings endpoint rather
 * than creating a new `/admin/return-policy` route — this matches the
 * Settings/Discovery/Overview tab pattern and keeps the
 * `useSelect(getSettings) / useDispatch(updateSettingsValues, saveSettings)`
 * client flow uniform across tabs.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class AdminReturnPolicyTest extends \PHPUnit\Framework\TestCase {

	private WC_AI_Storefront_Admin_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = [];
		$this->controller                = new WC_AI_Storefront_Admin_Controller();

		// Default page-existence stubs assume an existing published
		// page whenever a positive page_id is requested. Individual
		// tests override these for invalid-page scenarios.
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'absint' )->alias(
			static fn( $v ) => max( 0, (int) $v )
		);
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post_settings( array $payload ) {
		$req = new WP_REST_Request( 'POST', '/admin/settings' );
		foreach ( $payload as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->controller->update_settings( $req );
	}

	// ------------------------------------------------------------------
	// GET defaults
	// ------------------------------------------------------------------

	public function test_get_returns_default_unconfigured_when_unset(): void {
		$response = $this->controller->get_settings();
		$data     = $response->data;

		$this->assertArrayHasKey( 'return_policy', $data );
		$this->assertSame( 'unconfigured', $data['return_policy']['mode'] );
	}

	public function test_get_returns_persisted_settings(): void {
		WC_AI_Storefront::update_settings(
			[
				'return_policy' => [
					'mode'    => 'returns_accepted',
					'page_id' => 0,
					'days'    => 30,
					'fees'    => 'FreeReturn',
					'methods' => [ 'ReturnByMail' ],
				],
			]
		);

		$response = $this->controller->get_settings();
		$policy   = $response->data['return_policy'];

		$this->assertSame( 'returns_accepted', $policy['mode'] );
		$this->assertSame( 30, $policy['days'] );
		$this->assertSame( 'FreeReturn', $policy['fees'] );
		$this->assertSame( [ 'ReturnByMail' ], $policy['methods'] );
	}

	// ------------------------------------------------------------------
	// POST persistence
	// ------------------------------------------------------------------

	public function test_post_persists_valid_mode(): void {
		$this->post_settings(
			[
				'return_policy' => [
					'mode'    => 'returns_accepted',
					'days'    => 14,
					'fees'    => 'FreeReturn',
					'methods' => [ 'ReturnByMail' ],
				],
			]
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'returns_accepted', $persisted['mode'] );
	}

	public function test_post_rejects_invalid_mode(): void {
		// An out-of-enum mode falls through to the safe default
		// `unconfigured` (the sanitizer never persists garbage).
		$this->post_settings(
			[
				'return_policy' => [ 'mode' => 'pirate_mode' ],
			]
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'unconfigured', $persisted['mode'] );
	}

	public function test_post_validates_page_id_is_published(): void {
		// Stub a draft page → sanitizer must reset page_id to 0.
		Functions\when( 'get_post_status' )->justReturn( 'draft' );

		$this->post_settings(
			[
				'return_policy' => [
					'mode'    => 'returns_accepted',
					'page_id' => 555,
					'days'    => 14,
				],
			]
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 0, $persisted['page_id'] );
	}

	public function test_post_clamps_days_to_valid_range(): void {
		// Above max — clamp to 365.
		$this->post_settings(
			[
				'return_policy' => [
					'mode' => 'returns_accepted',
					'days' => 9999,
					'fees' => 'FreeReturn',
				],
			]
		);
		$this->assertSame( 365, WC_AI_Storefront::get_settings()['return_policy']['days'] );

		// Negative → absint produces 0 → mapped to null (the
		// "no window configured" sentinel; smart-degrades to
		// MerchantReturnUnspecified at emission time). 0 itself
		// has no semantic meaning under the post-Finding-#9
		// design — a finite-window claim with 0 days is structurally
		// invalid, so the sanitizer drops the field entirely rather
		// than carry a misleading value.
		$this->post_settings(
			[
				'return_policy' => [
					'mode' => 'returns_accepted',
					'days' => -5,
					'fees' => 'FreeReturn',
				],
			]
		);
		$this->assertNull( WC_AI_Storefront::get_settings()['return_policy']['days'] );
	}

	public function test_post_dedupes_method_array(): void {
		$this->post_settings(
			[
				'return_policy' => [
					'mode'    => 'returns_accepted',
					'days'    => 14,
					'fees'    => 'FreeReturn',
					'methods' => [ 'ReturnByMail', 'ReturnByMail', 'ReturnInStore', 'ReturnByMail' ],
				],
			]
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy']['methods'];
		$this->assertSame( [ 'ReturnByMail', 'ReturnInStore' ], $persisted );
	}

	public function test_post_rejects_invalid_fee_enum(): void {
		// An out-of-enum fee falls through to the safe default
		// `FreeReturn`.
		$this->post_settings(
			[
				'return_policy' => [
					'mode' => 'returns_accepted',
					'days' => 14,
					'fees' => 'PayDoubleAtPickup',
				],
			]
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy']['fees'];
		$this->assertSame( 'FreeReturn', $persisted );
	}

	public function test_post_rejects_invalid_method_enum(): void {
		$this->post_settings(
			[
				'return_policy' => [
					'mode'    => 'returns_accepted',
					'days'    => 14,
					'fees'    => 'FreeReturn',
					'methods' => [ 'ReturnByCarrierPigeon', 'ReturnByMail' ],
				],
			]
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy']['methods'];
		// Invalid entries dropped, valid ones preserved.
		$this->assertSame( [ 'ReturnByMail' ], $persisted );
	}

	// ------------------------------------------------------------------
	// Mode-aware sanitization (Finding #8)
	// ------------------------------------------------------------------

	public function test_unconfigured_mode_drops_all_subfields(): void {
		// Switching to `unconfigured` mode after previously
		// configuring a full return policy must NOT carry the
		// `page_id` / `days` / `fees` / `methods` ghost values
		// forward on disk. Mode-aware sanitization scrubs them so
		// a future "ghost field" bug can't read stale data.
		$this->post_settings(
			[
				'return_policy' => [
					'mode'    => 'unconfigured',
					// Garbage values that must NOT survive sanitization:
					'page_id' => 99,
					'days'    => 30,
					'fees'    => 'RestockingFees',
					'methods' => [ 'ReturnByMail', 'ReturnInStore' ],
				],
			]
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( [ 'mode' => 'unconfigured' ], $persisted );
	}

	public function test_final_sale_mode_drops_days_fees_methods(): void {
		// `final_sale` only consumes `mode` + `page_id` at emission.
		// `days` / `fees` / `methods` are nonsensical (returns aren't
		// permitted, so there's no window, fee, or method) — sanitizer
		// drops them so storage doesn't carry meaningless state.
		$this->post_settings(
			[
				'return_policy' => [
					'mode'    => 'final_sale',
					'page_id' => 17,
					'days'    => 30,
					'fees'    => 'FreeReturn',
					'methods' => [ 'ReturnByMail' ],
				],
			]
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame(
			[ 'mode' => 'final_sale', 'page_id' => 17 ],
			$persisted
		);
	}

	// ------------------------------------------------------------------
	// REST round-trip (Finding #6)
	// ------------------------------------------------------------------

	public function test_round_trip_persists_return_policy_through_rest(): void {
		// End-to-end: POST a complete return_policy via the REST
		// controller's update_settings → GET via get_settings →
		// assert the payload survived. Catches regressions where
		// the controller's $fields whitelist forgets `return_policy`
		// (a real risk: the whitelist is a hand-maintained array
		// at the top of update_settings(), trivially out of sync
		// with the args schema below it).
		$this->post_settings(
			[
				'return_policy' => [
					'mode'    => 'returns_accepted',
					'page_id' => 42,
					'days'    => 14,
					'fees'    => 'OriginalShippingFees',
					'methods' => [ 'ReturnByMail', 'ReturnAtKiosk' ],
				],
			]
		);

		$response = $this->controller->get_settings();
		$policy   = $response->data['return_policy'];

		$this->assertSame( 'returns_accepted', $policy['mode'] );
		$this->assertSame( 42, $policy['page_id'] );
		$this->assertSame( 14, $policy['days'] );
		$this->assertSame( 'OriginalShippingFees', $policy['fees'] );
		$this->assertSame(
			[ 'ReturnByMail', 'ReturnAtKiosk' ],
			$policy['methods']
		);
	}

	// ------------------------------------------------------------------
	// Authorization (Finding #7 — wiring + capability)
	// ------------------------------------------------------------------

	public function test_unauthorized_request_rejected(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( $this->controller->check_admin_permission() );
	}

	public function test_settings_route_wires_check_admin_permission_callback(): void {
		// Verifies the registered route's permission_callback is
		// actually our `check_admin_permission()` method, not the
		// dangerous default `__return_true`. A regression that swaps
		// the callback would let unauthenticated users update settings;
		// asserting the wiring catches that even when capability
		// behavior is otherwise correct.
		//
		// We stub the WP REST `register_rest_route` to record what
		// our controller registers, then assert the recorded args
		// reference the permission_callback we expect.
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
				return true;
			}
		);
		$controller = new WC_AI_Storefront_Admin_Controller();
		$controller->register_routes();

		$this->assertArrayHasKey( '/settings', $registered );
		// `/settings` registers an array of method handlers.
		$settings_handlers = $registered['/settings'];
		$this->assertIsArray( $settings_handlers );
		foreach ( $settings_handlers as $handler ) {
			$this->assertIsArray( $handler );
			$this->assertArrayHasKey( 'permission_callback', $handler );
			$this->assertSame(
				[ $controller, 'check_admin_permission' ],
				$handler['permission_callback']
			);
		}
	}
}
