<?php
/**
 * Tests for WC_AI_Storefront::discovery_cache_control().
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class DiscoveryCacheControlTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_to_public_max_age_300(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => $default );

		$this->assertSame( 'public, max-age=300', WC_AI_Storefront::discovery_cache_control() );
	}

	public function test_respects_the_max_age_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $default ) => 'wc_ai_storefront_discovery_cache_max_age' === $hook ? 60 : $default
		);

		$this->assertSame( 'public, max-age=60', WC_AI_Storefront::discovery_cache_control() );
	}

	public function test_clamps_negative_max_age_to_zero(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => -5 );

		$this->assertSame( 'public, max-age=0', WC_AI_Storefront::discovery_cache_control() );
	}

	public function test_zero_max_age_passes_through(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => 0 );

		$this->assertSame( 'public, max-age=0', WC_AI_Storefront::discovery_cache_control() );
	}

	public function test_non_numeric_filter_falls_back_to_default_and_warns(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => 'gibberish' );
		$logger = Mockery::mock();
		$logger->shouldReceive( 'warning' )->once();
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$this->assertSame( 'public, max-age=300', WC_AI_Storefront::discovery_cache_control() );
	}

	public function test_oversized_max_age_clamped_to_one_day(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => 10 * DAY_IN_SECONDS );

		$this->assertSame( 'public, max-age=' . DAY_IN_SECONDS, WC_AI_Storefront::discovery_cache_control() );
	}

	public function test_production_method_matches_stub_logic(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-wc-ai-storefront.php' );
		$this->assertStringContainsString( "apply_filters( 'wc_ai_storefront_discovery_cache_max_age', 300 )", $source );
		$this->assertStringContainsString( 'is_numeric( $raw )', $source );
		$this->assertStringContainsString( 'max( 0, min( (int) $raw, DAY_IN_SECONDS ) )', $source );
		$this->assertStringContainsString( 'return \'public, max-age=\' . $max_age;', $source );
	}
}
