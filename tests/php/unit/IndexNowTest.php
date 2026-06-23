<?php
/**
 * Tests for WC_AI_Storefront_IndexNow.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class IndexNowTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_IndexNow $indexnow;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes', 'indexnow_enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'home_url' )->alias( static fn( $p = '/' ) => 'https://shop.test' . ( '' === $p ? '/' : $p ) );
		$this->indexnow = new WC_AI_Storefront_IndexNow();
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
}
