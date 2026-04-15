<?php
/**
 * Tests for WC_AI_Syndication_Attribution.
 *
 * Covers capture_ai_attribution (meta detection, session/agent capture)
 * and the column rendering.
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
	// render_order_list_column
	// ------------------------------------------------------------------

	public function test_render_column_shows_agent_name(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_ai_syndication_agent', 'perplexity' );

		Functions\expect( 'wc_get_order' )->andReturn( $order );
		Functions\expect( 'esc_html' )->andReturnFirstArg();

		ob_start();
		$this->attribution->render_order_list_column( 'ai_agent', 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'perplexity', $output );
	}

	public function test_render_column_shows_dash_for_non_ai_order(): void {
		$order = new WC_Order();
		// No agent meta.

		Functions\expect( 'wc_get_order' )->andReturn( $order );

		ob_start();
		$this->attribution->render_order_list_column( 'ai_agent', 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( '&mdash;', $output );
	}

	public function test_render_column_ignores_other_columns(): void {
		ob_start();
		$this->attribution->render_order_list_column( 'order_total', 1 );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_render_column_handles_wc_order_object(): void {
		$order = new WC_Order();
		$order->set_test_meta( '_wc_ai_syndication_agent', 'copilot' );

		Functions\expect( 'esc_html' )->andReturnFirstArg();

		// HPOS passes the WC_Order object directly.
		ob_start();
		$this->attribution->render_order_list_column( 'ai_agent', $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'copilot', $output );
	}
}
