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

		// invalidate() and deactivate() both run a wildcard $wpdb->query()
		// to purge host-keyed transient variants. Provide a minimal mock
		// so callers don't hit "Call to a member function query() on null".
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ) {
				return $query; // Return bare SQL — tests don't assert on it.
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( $text ) => addcslashes( (string) $text, '_%\\' )
		);
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = null; // Reset so other test classes don't pick up the mock.
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// invalidate()
	// ------------------------------------------------------------------

	public function test_invalidate_deletes_transients(): void {
		// llms.txt (host-keyed) + catalog_summary + sitemap_urls + UCP (no-op).
		Functions\expect( 'delete_transient' )
			->times( 4 );

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
			->with( 'wc_ai_storefront_catalog_summary' )
			->andReturn( true );
		Functions\expect( 'delete_transient' )
			->once()
			->with( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY )
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
		Functions\expect( 'delete_transient' )->times( 4 )->andReturn( true );

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
		Functions\expect( 'delete_transient' )->times( 4 )->andReturn( true );

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
		// llms.txt (host-keyed) + catalog_summary + sitemap_urls + UCP (bare, no-op).
		Functions\expect( 'delete_transient' )
			->times( 4 );

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
			->with( 'wc_ai_storefront_catalog_summary' )
			->andReturn( true );
		Functions\expect( 'delete_transient' )
			->once()
			->with( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY )
			->andReturn( true );
		Functions\expect( 'delete_transient' )
			->once()
			->with( WC_AI_Storefront_Ucp::CACHE_KEY )
			->andReturn( true );

		Functions\expect( 'wp_clear_scheduled_hook' )->once();

		WC_AI_Storefront_Cache_Invalidator::deactivate();
	}

	// ------------------------------------------------------------------
	// Wildcard DB delete for host-keyed transient variants (#152)
	// ------------------------------------------------------------------

	public function test_invalidate_runs_wildcard_db_delete_for_host_keyed_variants(): void {
		// Regression test for issue #152: invalidate() must issue a
		// $wpdb->query() call that deletes ALL host-keyed llms.txt
		// transient rows (not just the current-host key) so that alias
		// domains don't retain stale cache after a product update.
		//
		// Replace the setUp mock with a fresh one that has a strict
		// once() expectation on query().
		global $wpdb;
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $q ) => $q
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( $t ) => addcslashes( (string) $t, '_%\\' )
		);
		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 2 ); // Simulate 2 rows deleted (e.g. www + non-www).

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->invalidator->invalidate();

		// The Mockery expectation above asserts query() was called once.
	}

	public function test_deactivate_runs_wildcard_db_delete_for_host_keyed_variants(): void {
		// Same guarantee as the invalidate() variant, but for deactivate().
		global $wpdb;
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $q ) => $q
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( $t ) => addcslashes( (string) $t, '_%\\' )
		);
		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturn( 1 );

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );

		WC_AI_Storefront_Cache_Invalidator::deactivate();
	}

	public function test_invalidate_also_purges_catalog_summary_transient(): void {
		// Regression guard for issue #167: invalidate() must delete the
		// catalog-summary transient so the store-level JSON-LD is
		// refreshed on the next page load after a product/category update.
		$deleted = array();
		Functions\expect( 'delete_transient' )
			->times( 4 )
			->andReturnUsing(
				static function ( $key ) use ( &$deleted ) {
					$deleted[] = $key;
					return true;
				}
			);

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->invalidator->invalidate();

		$this->assertContains(
			'wc_ai_storefront_catalog_summary',
			$deleted,
			'invalidate() must delete the catalog_summary transient'
		);
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
