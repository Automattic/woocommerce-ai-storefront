<?php
/**
 * Tests for WC_AI_Storefront::discovery_cache_control().
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class DiscoveryCacheControlTest extends \PHPUnit\Framework\TestCase {

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
}
