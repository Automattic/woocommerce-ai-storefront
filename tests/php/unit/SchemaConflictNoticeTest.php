<?php
/**
 * Tests for WC_AI_Storefront_Schema_Conflict_Notice gating.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SchemaConflictNoticeTest extends \PHPUnit\Framework\TestCase {

	private WC_AI_Storefront_Schema_Conflict_Notice $notice;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' ); // not dismissed
		$this->notice = new WC_AI_Storefront_Schema_Conflict_Notice();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_show_when_no_conflict(): void {
		// CI env: detector finds nothing.
		$this->assertFalse( $this->notice->should_show() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_show_when_conflict_and_enabled_and_capable_and_not_dismissed(): void {
		define( 'RANK_MATH_VERSION', '1.0' );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$this->assertTrue( ( new WC_AI_Storefront_Schema_Conflict_Notice() )->should_show() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_show_when_dismissed(): void {
		define( 'RANK_MATH_VERSION', '1.0' );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '1' ); // dismissed
		$this->assertFalse( ( new WC_AI_Storefront_Schema_Conflict_Notice() )->should_show() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_show_when_not_capable(): void {
		define( 'RANK_MATH_VERSION', '1.0' );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$this->assertFalse( ( new WC_AI_Storefront_Schema_Conflict_Notice() )->should_show() );
	}

	public function test_no_show_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		$this->assertFalse( $this->notice->should_show() );
	}

	public function test_handle_dismiss_persists_for_capable_user(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_send_json_success' )->justReturn( null );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 7, WC_AI_Storefront_Schema_Conflict_Notice::DISMISS_META, 1 );
		$this->notice->handle_dismiss();
		$this->addToAssertionCount( 1 ); // Brain Monkey expectation counted at tearDown.
	}

	public function test_handle_dismiss_rejects_incapable_user(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_send_json_error' )->justReturn( null );
		Functions\expect( 'update_user_meta' )->never();
		$this->notice->handle_dismiss();
		$this->addToAssertionCount( 1 ); // Brain Monkey expectation counted at tearDown.
	}
}
