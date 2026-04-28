<?php
/**
 * Tests for WC_AI_Storefront_Cache_Invalidator.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class CacheInvalidatorTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Cache_Invalidator $invalidator;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->invalidator = new WC_AI_Storefront_Cache_Invalidator();

		// host_cache_key() sanitizes $_SERVER['HTTP_HOST'] via these two
		// WP helpers. Stub them globally so every test that calls any method
		// which internally resolves a host-keyed transient key doesn't need
		// to re-declare them individually. Individual tests may still add
		// their own Functions\expect() stubs for strict verification.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// invalidate()
	// ------------------------------------------------------------------

	public function test_invalidate_deletes_transients(): void {
		Functions\expect( 'delete_transient' )
			->twice(); // llms.txt (host-keyed) + UCP caches.

		Functions\expect( 'wp_next_scheduled' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->andReturn( true );

		$this->invalidator->invalidate();
	}

	public function test_invalidate_uses_host_keyed_transient_for_llms_txt(): void {
		$_SERVER['HTTP_HOST'] = 'mystore.example.com';
		$expected_key         = WC_AI_Storefront_Llms_Txt::host_cache_key();

		Functions\expect( 'delete_transient' )
			->once()
			->with( $expected_key )
			->andReturn( true );
		Functions\expect( 'delete_transient' )
			->once()
			->with( WC_AI_Storefront_Ucp::CACHE_KEY )
			->andReturn( true );

		Functions\expect( 'wp_next_scheduled' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->andReturn( true );

		$this->invalidator->invalidate();
	}

	public function test_invalidate_schedules_warmup_when_none_pending(): void {
		Functions\expect( 'delete_transient' )->twice()->andReturn( true );

		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( WC_AI_Storefront_Cache_Invalidator::WARMUP_CRON_HOOK )
			->andReturn( false );

		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->andReturnUsing( function ( $timestamp, $hook ) {
				$this->assertEqualsWithDelta( time() + 30, $timestamp, 2 );
				$this->assertEquals( WC_AI_Storefront_Cache_Invalidator::WARMUP_CRON_HOOK, $hook );
				return true;
			} );

		$this->invalidator->invalidate();
	}

	public function test_invalidate_skips_scheduling_when_event_already_pending(): void {
		Functions\expect( 'delete_transient' )->twice()->andReturn( true );

		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( WC_AI_Storefront_Cache_Invalidator::WARMUP_CRON_HOOK )
			->andReturn( time() + 15 ); // Already scheduled.

		// wp_schedule_single_event should NOT be called.
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->invalidator->invalidate();
	}

	// ------------------------------------------------------------------
	// warm_cache()
	// ------------------------------------------------------------------

	public function test_warm_cache_exits_early_when_cache_exists(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( WC_AI_Storefront_Llms_Txt::host_cache_key() )
			->andReturn( '# Cached content' );

		// Should not attempt to generate or set transient.
		Functions\expect( 'set_transient' )->never();

		$this->invalidator->warm_cache();
	}

	public function test_warm_cache_exits_early_when_syndication_disabled(): void {
		Functions\expect( 'get_transient' )
			->with( WC_AI_Storefront_Llms_Txt::host_cache_key() )
			->andReturn( false );

		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		// Should not attempt to set transient.
		Functions\expect( 'set_transient' )->never();

		$this->invalidator->warm_cache();
	}

	// ------------------------------------------------------------------
	// deactivate()
	// ------------------------------------------------------------------

	public function test_deactivate_cleans_up_transient_and_cron(): void {
		Functions\expect( 'delete_transient' )
			->twice(); // llms.txt (host-keyed) + UCP (bare, no-op).

		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( WC_AI_Storefront_Cache_Invalidator::WARMUP_CRON_HOOK );

		WC_AI_Storefront_Cache_Invalidator::deactivate();
	}

	public function test_deactivate_uses_host_keyed_transient_for_llms_txt(): void {
		$_SERVER['HTTP_HOST'] = 'deactivate.example.com';
		$expected_key         = WC_AI_Storefront_Llms_Txt::host_cache_key();

		Functions\expect( 'delete_transient' )
			->once()
			->with( $expected_key )
			->andReturn( true );
		Functions\expect( 'delete_transient' )
			->once()
			->with( WC_AI_Storefront_Ucp::CACHE_KEY )
			->andReturn( true );

		Functions\expect( 'wp_clear_scheduled_hook' )->once();

		WC_AI_Storefront_Cache_Invalidator::deactivate();
	}

	// ------------------------------------------------------------------
	// init() hook registration
	// ------------------------------------------------------------------

	public function test_init_registers_all_expected_hooks(): void {
		Functions\expect( 'add_action' )
			->times( 10 ); // 4 product + 1 stock + 3 category + 1 settings + 1 cron = 10.

		$this->invalidator->init();
	}
}
