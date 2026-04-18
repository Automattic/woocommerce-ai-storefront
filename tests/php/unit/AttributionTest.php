<?php
/**
 * Tests for WC_AI_Syndication_Attribution.
 *
 * Covers capture_ai_attribution (meta detection, session/agent capture).
 * The custom "AI Agent" orders-list column was removed in 1.6.7 —
 * WooCommerce core's "Origin" column already displays the same data
 * sourced from `_wc_order_attribution_utm_source`.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AttributionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Attribution $attribution;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->attribution = new WC_AI_Syndication_Attribution();

		// Clear $_GET between tests.
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// capture_ai_attribution
	// ------------------------------------------------------------------

	public function test_capture_skips_non_ai_orders(): void {
		$order = new WC_Order();
		// No utm_medium meta, no $_GET params.

		Functions\expect( 'do_action' )->never();

		$this->attribution->capture_ai_attribution( $order );

		// Order should not have been saved.
		$this->assertFalse( $order->was_saved() );
	}

	public function test_capture_detects_ai_medium_from_order_meta(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'chatgpt' );

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		$this->assertEquals( 'chatgpt', $order->get_meta( '_wc_ai_syndication_agent' ) );
	}

	public function test_capture_detects_ai_medium_from_get_fallback(): void {
		$order = new WC_Order();
		// No meta, but $_GET has the params.
		$_GET['utm_medium']    = 'ai_agent';
		$_GET['utm_source']    = 'gemini';
		$_GET['ai_session_id'] = 'session-abc';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertTrue( $order->was_saved() );
		$this->assertEquals( 'gemini', $order->get_meta( '_wc_ai_syndication_agent' ) );
		$this->assertEquals( 'session-abc', $order->get_meta( '_wc_ai_syndication_session_id' ) );
	}

	public function test_capture_stores_session_id_when_present(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );

		$_GET['ai_session_id'] = 'sess-123';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();
		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals( 'sess-123', $order->get_meta( '_wc_ai_syndication_session_id' ) );
	}

	public function test_capture_does_not_store_empty_session_id(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_order_attribution_utm_medium', 'ai_agent' );
		$order->set_test_meta( '_wc_order_attribution_utm_source', 'claude' );
		// No ai_session_id in $_GET.

		Functions\expect( 'do_action' )->once();

		$this->attribution->capture_ai_attribution( $order );

		$this->assertEquals( '', $order->get_meta( '_wc_ai_syndication_session_id' ) );
		$this->assertEquals( 'claude', $order->get_meta( '_wc_ai_syndication_agent' ) );
	}

	// ------------------------------------------------------------------
	// No custom orders-list column since 1.6.7.
	//
	// Lock-in: if a future change reintroduces a custom column (or
	// filter dropdown) that duplicates WC core's "Origin" column,
	// these assertions fire and force a conscious design review.
	// ------------------------------------------------------------------

	public function test_init_does_not_register_custom_orders_list_column(): void {
		// Track every add_filter / add_action call during init() and
		// assert none of them touch the orders-list column or filter
		// hooks. This is a regression guard: the 1.6.7 removal wasn't
		// enforced by runtime code — it was enforced by not attaching
		// the hooks. If someone re-adds the hooks, this test fires.
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		$this->attribution->init();

		$forbidden = [
			'manage_woocommerce_page_wc-orders_columns',
			'manage_woocommerce_page_wc-orders_custom_column',
			'manage_edit-shop_order_columns',
			'manage_shop_order_posts_custom_column',
			'woocommerce_order_list_table_restrict_manage_orders',
			'restrict_manage_posts',
			'woocommerce_order_list_table_prepare_items_query_args',
			'pre_get_posts',
		];
		foreach ( $forbidden as $hook ) {
			$this->assertNotContains(
				$hook,
				$hooks,
				"Hook {$hook} reintroduces the orders-list column or filter removed in 1.6.7 — WC core's Origin column already shows this data"
			);
		}
	}

	public function test_attribution_class_exposes_no_column_rendering_methods(): void {
		// If a future maintainer reintroduces the column-rendering
		// methods (render_order_list_column, add_order_list_column,
		// render_agent_filter, filter_orders_by_agent), they must
		// also wire up the hooks — which the test above prevents.
		// Belt-and-braces: catch the method-level reintroduction too.
		$removed = [
			'add_order_list_column',
			'render_order_list_column',
			'render_agent_filter',
			'render_agent_filter_legacy',
			'filter_orders_by_agent',
			'filter_orders_by_agent_legacy',
		];
		foreach ( $removed as $method ) {
			$this->assertFalse(
				method_exists( $this->attribution, $method ),
				"Method {$method} was removed in 1.6.7; reintroduction duplicates WC core's Origin column"
			);
		}
	}
}
