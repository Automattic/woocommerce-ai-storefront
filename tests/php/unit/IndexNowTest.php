<?php
/**
 * Tests for WC_AI_Storefront_IndexNow.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

if ( ! class_exists( 'WC_AI_Storefront_IndexNow_Exit' ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test double
	class WC_AI_Storefront_IndexNow_Exit extends \RuntimeException {}
}

class IndexNowTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_IndexNow $indexnow;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'home_url' )->alias( static fn( $p = '/' ) => 'https://shop.test' . ( '' === $p ? '/' : $p ) );
		$this->indexnow = new class() extends WC_AI_Storefront_IndexNow {
			protected function terminate(): void {
				throw new \WC_AI_Storefront_IndexNow_Exit();
			}
		};
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_key_generates_and_persists_hex_key(): void {
		$stored = null;
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' ) );
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			function ( $name, $value ) use ( &$stored ) {
				$stored = $value['indexnow_key'] ?? null;
				return true;
			}
		);
		$key = $this->indexnow->get_key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, $stored );
	}

	public function test_get_key_returns_existing_without_regenerating(): void {
		WC_AI_Storefront::$test_settings['indexnow_key'] = 'abc123abc123abc123abc123abc12300';
		Functions\expect( 'update_option' )->never();
		$this->assertSame( 'abc123abc123abc123abc123abc12300', $this->indexnow->get_key() );
	}

	public function test_is_enabled_requires_both_flags(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'no' );
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no', 'indexnow_enabled' => 'yes' );
		$this->assertFalse( $this->indexnow->is_enabled() );
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		$this->assertTrue( $this->indexnow->is_enabled() );
	}

	public function test_serve_key_file_outputs_key_on_match(): void {
		WC_AI_Storefront::$test_settings['indexnow_key'] = 'abcabcabcabcabcabcabcabcabcabc99';
		Functions\when( 'get_option' )->justReturn(
			array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'abcabcabcabcabcabcabcabcabcabc99' )
		);
		Functions\when( 'get_query_var' )->justReturn( 'abcabcabcabcabcabcabcabcabcabc99' );
		Functions\expect( 'status_header' )->once()->with( 200 );
		ob_start();
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			// serve_key_file() calls $this->terminate() which throws in tests.
		}
		$this->assertSame( 'abcabcabcabcabcabcabcabcabcabc99', ob_get_clean() );
	}

	public function test_serve_key_file_404_on_mismatch(): void {
		WC_AI_Storefront::$test_settings['indexnow_key'] = 'realkeyrealkeyrealkeyrealkey0001';
		Functions\when( 'get_option' )->justReturn(
			array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes', 'indexnow_key' => 'realkeyrealkeyrealkeyrealkey0001' )
		);
		Functions\when( 'get_query_var' )->justReturn( 'gibberishgibberishgibberish00000' );
		Functions\expect( 'status_header' )->once()->with( 404 );
		try {
			$this->indexnow->serve_key_file();
		} catch ( \WC_AI_Storefront_IndexNow_Exit $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_serve_key_file_noop_when_no_query_var(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'status_header' )->never();
		$this->indexnow->serve_key_file(); // returns without terminating
		$this->addToAssertionCount( 1 );
	}
}
