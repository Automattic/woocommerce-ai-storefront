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

		// Negative → clamped to 0 by absint().
		$this->post_settings(
			[
				'return_policy' => [
					'mode' => 'returns_accepted',
					'days' => -5,
					'fees' => 'FreeReturn',
				],
			]
		);
		$this->assertSame( 0, WC_AI_Storefront::get_settings()['return_policy']['days'] );
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
	// Authorization
	// ------------------------------------------------------------------

	public function test_unauthorized_request_rejected(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( $this->controller->check_admin_permission() );
	}
}
