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
		WC_AI_Storefront::$test_settings = array();
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
		// IndexNow key now lives in a dedicated option. Stub get_option so
		// peek_key() (called by admin controller's get_settings()) works
		// without a real DB. Returns '' (no key generated yet), which is the
		// correct no-key-yet default for these return-policy tests.
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = '' ) => $default
		);
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
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
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 30,
					'fees'     => 'FreeReturn',
					'methods'  => array( 'ReturnByMail' ),
				),
			)
		);

		$response = $this->controller->get_settings();
		$policy   = $response->data['return_policy'];

		$this->assertSame( 'details', $policy['mode'] );
		$this->assertSame( 'returns_accepted', $policy['category'] );
		$this->assertSame( 30, $policy['days'] );
		$this->assertSame( 'FreeReturn', $policy['fees'] );
		$this->assertSame( array( 'ReturnByMail' ), $policy['methods'] );
	}

	// ------------------------------------------------------------------
	// POST persistence
	// ------------------------------------------------------------------

	public function test_post_persists_valid_mode(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 14,
					'fees'     => 'FreeReturn',
					'methods'  => array( 'ReturnByMail' ),
				),
			)
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'details', $persisted['mode'] );
		$this->assertSame( 'returns_accepted', $persisted['category'] );
	}

	public function test_post_rejects_invalid_mode(): void {
		// An out-of-enum mode falls through to the safe default
		// `unconfigured` (the sanitizer never persists garbage).
		$this->post_settings(
			array(
				'return_policy' => array( 'mode' => 'pirate_mode' ),
			)
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'unconfigured', $persisted['mode'] );
	}

	public function test_post_clamps_days_to_valid_range(): void {
		// Above max — clamp to 365.
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 9999,
					'fees'     => 'FreeReturn',
				),
			)
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
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => -5,
					'fees'     => 'FreeReturn',
				),
			)
		);
		$this->assertNull( WC_AI_Storefront::get_settings()['return_policy']['days'] );
	}

	public function test_post_dedupes_method_array(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 14,
					'fees'     => 'FreeReturn',
					'methods'  => array( 'ReturnByMail', 'ReturnByMail', 'ReturnInStore', 'ReturnByMail' ),
				),
			)
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy']['methods'];
		$this->assertSame( array( 'ReturnByMail', 'ReturnInStore' ), $persisted );
	}

	public function test_post_rejects_invalid_fee_enum(): void {
		// An out-of-enum fee falls through to the safe default
		// `FreeReturn`.
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 14,
					'fees'     => 'PayDoubleAtPickup',
				),
			)
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy']['fees'];
		$this->assertSame( 'FreeReturn', $persisted );
	}

	public function test_post_rejects_invalid_method_enum(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 14,
					'fees'     => 'FreeReturn',
					'methods'  => array( 'ReturnByCarrierPigeon', 'ReturnByMail' ),
				),
			)
		);

		$persisted = WC_AI_Storefront::get_settings()['return_policy']['methods'];
		// Invalid entries dropped, valid ones preserved.
		$this->assertSame( array( 'ReturnByMail' ), $persisted );
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
			array(
				'return_policy' => array(
					'mode'    => 'unconfigured',
					// Garbage values that must NOT survive sanitization:
					'page_id' => 99,
					'days'    => 30,
					'fees'    => 'RestockingFees',
					'methods' => array( 'ReturnByMail', 'ReturnInStore' ),
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( array( 'mode' => 'unconfigured' ), $persisted );
	}

	// ------------------------------------------------------------------
	// New mode: link (Task 1 — Option A/B separation)
	// ------------------------------------------------------------------

	public function test_link_mode_persists_only_mode_and_page_id(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'link',
					'page_id'  => 42,
					// These must be stripped — irrelevant for link mode.
					'category' => 'returns_accepted',
					'days'     => 30,
					'fees'     => 'FreeReturn',
					'methods'  => array( 'ReturnByMail' ),
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame(
			array(
				'mode'    => 'link',
				'page_id' => 42,
			),
			$persisted
		);
	}

	public function test_link_mode_with_zero_page_id_persists_page_id_zero(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'    => 'link',
					'page_id' => 0,
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame(
			array(
				'mode'    => 'link',
				'page_id' => 0,
			),
			$persisted
		);
	}

	public function test_link_mode_with_unpublished_page_resets_page_id_to_zero(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'    => 'link',
					'page_id' => 99,
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 0, $persisted['page_id'] );
	}

	// ------------------------------------------------------------------
	// New mode: details (Task 1 — Option A/B separation)
	// ------------------------------------------------------------------

	public function test_details_final_sale_persists_only_mode_and_category(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'final_sale',
					// These must be stripped — not meaningful for final_sale.
					'page_id'  => 17,
					'days'     => 30,
					'fees'     => 'FreeReturn',
					'methods'  => array( 'ReturnByMail' ),
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame(
			array(
				'mode'     => 'details',
				'category' => 'final_sale',
			),
			$persisted
		);
	}

	public function test_details_returns_accepted_persists_full_shape(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 30,
					'fees'     => 'FreeReturn',
					'methods'  => array( 'ReturnByMail', 'ReturnInStore' ),
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'details', $persisted['mode'] );
		$this->assertSame( 'returns_accepted', $persisted['category'] );
		$this->assertSame( 30, $persisted['days'] );
		$this->assertSame( 'FreeReturn', $persisted['fees'] );
		$this->assertSame( array( 'ReturnByMail', 'ReturnInStore' ), $persisted['methods'] );
	}

	public function test_details_returns_accepted_does_not_persist_page_id(): void {
		// page_id is only meaningful for mode='link'; details drops it.
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'page_id'  => 55,
					'days'     => 14,
					'fees'     => 'FreeReturn',
					'methods'  => array(),
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertArrayNotHasKey( 'page_id', $persisted );
	}

	public function test_details_with_invalid_category_falls_back_to_unconfigured(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'pirate_category',
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'unconfigured', $persisted['mode'] );
	}

	public function test_details_without_category_falls_back_to_unconfigured(): void {
		$this->post_settings(
			array(
				'return_policy' => array(
					'mode' => 'details',
					// No 'category' key at all.
					'days' => 14,
				),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'unconfigured', $persisted['mode'] );
	}

	// Verify old modes now fail closed (they are no longer in the allow-list).

	public function test_old_returns_accepted_mode_falls_back_to_unconfigured(): void {
		$this->post_settings(
			array(
				'return_policy' => array( 'mode' => 'returns_accepted' ),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'unconfigured', $persisted['mode'] );
	}

	public function test_old_final_sale_mode_falls_back_to_unconfigured(): void {
		$this->post_settings(
			array(
				'return_policy' => array( 'mode' => 'final_sale' ),
			)
		);
		$persisted = WC_AI_Storefront::get_settings()['return_policy'];
		$this->assertSame( 'unconfigured', $persisted['mode'] );
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
			array(
				'return_policy' => array(
					'mode'     => 'details',
					'category' => 'returns_accepted',
					'days'     => 14,
					'fees'     => 'OriginalShippingFees',
					'methods'  => array( 'ReturnByMail', 'ReturnAtKiosk' ),
				),
			)
		);

		$response = $this->controller->get_settings();
		$policy   = $response->data['return_policy'];

		$this->assertSame( 'details', $policy['mode'] );
		$this->assertSame( 'returns_accepted', $policy['category'] );
		$this->assertSame( 14, $policy['days'] );
		$this->assertSame( 'OriginalShippingFees', $policy['fees'] );
		$this->assertSame(
			array( 'ReturnByMail', 'ReturnAtKiosk' ),
			$policy['methods']
		);
	}

	// ------------------------------------------------------------------
	// IndexNow integration (Task 3)
	// ------------------------------------------------------------------

	public function test_get_settings_exposes_indexnow_last_result(): void {
		$response = $this->controller->get_settings();
		$data     = $response->get_data();
		$this->assertArrayHasKey( 'indexnow_last_result', $data );
		// With no flush yet, last_result() returns array() — pin that wire
		// value (PHP json_encodes it to [], which the JS status helper expects).
		$this->assertSame( array(), $data['indexnow_last_result'] );
	}

	public function test_get_settings_generates_key_when_indexnow_enabled(): void {
		// Auto-generate on enable (#546): get_key() mints + persists when the
		// dedicated KEY_OPTION is empty, so the card shows a key with no manual
		// "Generate" step. The setUp get_option stub returns '' (no key yet).
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'yes' ) );
		Functions\when( 'update_option' )->justReturn( true );
		$data = $this->controller->get_settings()->get_data();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $data['indexnow_key'] );
	}

	public function test_get_settings_does_not_mint_key_when_indexnow_disabled(): void {
		// IndexNow off (default): peek_key() is read-only — never mint a key
		// for a disabled feature.
		Functions\expect( 'update_option' )->never();
		$data = $this->controller->get_settings()->get_data();
		$this->assertSame( '', $data['indexnow_key'] );
	}

	// ------------------------------------------------------------------
	// Authorization (Finding #7 — wiring + capability)
	// ------------------------------------------------------------------

	public function test_unauthorized_request_rejected(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( $this->controller->check_admin_permission() );
	}

	// B2b — capability name: changing the cap from manage_woocommerce to anything else fails this test.
	public function test_check_admin_permission_uses_manage_woocommerce_cap(): void {
		$cap_checked = null;
		// Override the setUp `when` with a recording alias.
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$cap_checked ) {
				$cap_checked = $cap;
				return true;
			}
		);
		$this->assertTrue( $this->controller->check_admin_permission() );
		$this->assertSame( 'manage_woocommerce', $cap_checked );
	}

	// --- Task #540: indexnow-submit-all route ---

	public function test_indexnow_submit_all_route_is_registered_with_manage_woocommerce(): void {
		$registered = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
				return true;
			}
		);
		$controller = new WC_AI_Storefront_Admin_Controller();
		$controller->register_routes();

		$this->assertArrayHasKey( '/indexnow-submit-all', $registered );
		$handler = $registered['/indexnow-submit-all'];
		$this->assertSame( WP_REST_Server::CREATABLE, $handler['methods'] );
		$this->assertSame(
			array( $controller, 'check_admin_permission' ),
			$handler['permission_callback']
		);
	}

	public function test_indexnow_submit_all_returns_last_result(): void {
		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'indexnow_enabled'       => 'yes',
			'product_selection_mode' => 'all',
		);

		// Option store for pending + key + last_result.
		$store = array(
			'wc_ai_storefront_indexnow_key' => 'k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0k0',
		);
		Functions\when( 'get_option' )->alias(
			static function ( $n, $d = false ) use ( &$store ) {
				return $store[ $n ] ?? $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $n, $v ) use ( &$store ) {
				$store[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $n ) use ( &$store ) {
				unset( $store[ $n ] );
				return true;
			}
		);

		// home_url() needed by surface_urls() and flush().
		Functions\when( 'home_url' )->alias( static fn( $p = '/' ) => 'https://shop.test' . ( '' === $p ? '/' : $p ) );

		// all_product_urls() and all_category_urls() — empty for simplicity.
		Functions\when( 'wc_get_products' )->justReturn( array() );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );

		// flush() will POST the surfaces (home/llms/products.json).
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$response = $this->controller->indexnow_submit_all();

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->data;
		$this->assertArrayHasKey( 'indexnow_last_result', $data );
		// A successful flush records ok=true.
		$this->assertTrue( $data['indexnow_last_result']['ok'] );
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
		$registered = array();
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
				array( $controller, 'check_admin_permission' ),
				$handler['permission_callback']
			);
		}
	}
}
