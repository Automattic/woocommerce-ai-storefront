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

		// Reset and pre-register the canonical content-invalidation keys so every
		// test that calls invalidate() or deactivate() sees the same three keys that
		// WC_AI_Storefront::init_components() registers in production.
		WC_AI_Storefront_Cache_Invalidator::reset_registered_keys();
		WC_AI_Storefront_Cache_Invalidator::register(
			array( 'WC_AI_Storefront_Llms_Txt', 'host_cache_key' )
		);
		WC_AI_Storefront_Cache_Invalidator::register( 'wc_ai_storefront_catalog_summary' );
		WC_AI_Storefront_Cache_Invalidator::register( 'wc_ai_storefront_ucp' );

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

		// Default: single-site. Tests that exercise multisite paths will
		// override this stub with Functions\expect('is_multisite')->...
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = null; // Reset so other test classes don't pick up the mock.
		WC_AI_Storefront_Cache_Invalidator::reset_registered_keys();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// invalidate()
	// ------------------------------------------------------------------

	public function test_invalidate_deletes_transients(): void {
		// llms.txt (host-keyed) + catalog_summary + UCP (no-op).
		// Note: SITEMAP_CACHE_KEY is NOT deleted by invalidate() — sitemap
		// location depends on settings, not product data.
		Functions\expect( 'delete_transient' )
			->times( 3 );

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
			->with( 'wc_ai_storefront_ucp' )
			->andReturn( true );

		Functions\expect( 'wp_next_scheduled' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->andReturn( true );

		$this->invalidator->invalidate();
	}

	public function test_invalidate_schedules_warmup_when_none_pending(): void {
		Functions\expect( 'delete_transient' )->times( 3 )->andReturn( true );

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
		Functions\expect( 'delete_transient' )->times( 3 )->andReturn( true );

		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( WC_AI_Storefront_Cache_Invalidator::WARMUP_CRON_HOOK )
			->andReturn( time() + 15 ); // Already scheduled.

		// wp_schedule_single_event should NOT be called.
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->invalidator->invalidate();
	}

	// ------------------------------------------------------------------
	// invalidate_sitemap_cache()
	// ------------------------------------------------------------------

	public function test_invalidate_sitemap_cache_deletes_sitemap_key_only(): void {
		// Should delete SITEMAP_CACHE_KEY and nothing else (no llms.txt,
		// no catalog_summary, no UCP).
		Functions\expect( 'delete_transient' )
			->once()
			->with( WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY )
			->andReturn( true );

		Functions\expect( 'is_multisite' )->andReturn( false );

		$this->invalidator->invalidate_sitemap_cache();
	}

	public function test_invalidate_does_not_delete_sitemap_cache_on_product_edit(): void {
		// Regression guard: invalidate() must NOT touch SITEMAP_CACHE_KEY.
		// Sitemap location depends on settings, not product data.
		$sitemap_key_deleted = false;

		Functions\expect( 'delete_transient' )
			->times( 3 )
			->andReturnUsing(
				static function ( $key ) use ( &$sitemap_key_deleted ) {
					if ( $key === WC_AI_Storefront_Llms_Txt::SITEMAP_CACHE_KEY ) {
						$sitemap_key_deleted = true;
					}
					return true;
				}
			);

		Functions\expect( 'wp_next_scheduled' )->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->andReturn( true );

		$this->invalidator->invalidate();

		$this->assertFalse(
			$sitemap_key_deleted,
			'invalidate() must not delete SITEMAP_CACHE_KEY — sitemap location is not affected by product/category edits'
		);
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
			->with( 'wc_ai_storefront_ucp' )
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
			->times( 3 )
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
			->times( 11 ); // 4 product + 1 stock + 3 category + 1 settings + 1 sitemap-settings + 1 cron = 11.

		$this->invalidator->init();
	}

	// ------------------------------------------------------------------
	// Multisite: invalidate() purges every subsite (#P-12)
	// ------------------------------------------------------------------

	public function test_invalidate_skips_multisite_loop_on_single_site(): void {
		// On a single-site install is_multisite() returns false, so
		// get_sites() / switch_to_blog() must never be called.
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		Functions\expect( 'get_sites' )->never();
		Functions\expect( 'switch_to_blog' )->never();
		Functions\expect( 'restore_current_blog' )->never();

		$this->invalidator->invalidate();
	}

	public function test_invalidate_purges_sibling_sites_on_multisite(): void {
		// Two subsites beyond the current one: blog 2 and blog 3.
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );

		// switch_to_blog / restore_current_blog called once each for blogs 2 and 3.
		Functions\expect( 'switch_to_blog' )->times( 2 );
		Functions\expect( 'restore_current_blog' )->times( 2 );

		// delete_transient: 3 on current blog (llms_txt, catalog_summary, UCP)
		// + 2 x 2 for blogs 2 and 3 (catalog_summary, UCP per site).
		Functions\when( 'delete_transient' )->justReturn( true );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->invalidator->invalidate();
	}

	public function test_invalidate_skips_current_blog_in_multisite_loop(): void {
		// Blog 1 is both current and in the get_sites() result — it must
		// NOT trigger switch_to_blog / restore_current_blog.
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( array( 1 ) ); // Only the current blog.

		Functions\expect( 'switch_to_blog' )->never();
		Functions\expect( 'restore_current_blog' )->never();

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->invalidator->invalidate();
	}

	public function test_invalidate_multisite_runs_wildcard_query_per_site(): void {
		// Two sibling sites; each must trigger one $wpdb->query() in addition
		// to the main site's query. Total = 3 wildcard queries.
		global $wpdb;
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( $t ) => addcslashes( (string) $t, '_%\\' )
		);
		$wpdb->shouldReceive( 'query' )
			->times( 3 ) // 1 current + 2 siblings.
			->andReturn( 0 );

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );
		Functions\when( 'switch_to_blog' )->justReturn( true );
		Functions\when( 'restore_current_blog' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->invalidator->invalidate();
	}

	// ------------------------------------------------------------------
	// Multisite: deactivate() purges every subsite (#P-12)
	// ------------------------------------------------------------------

	public function test_deactivate_skips_multisite_loop_on_single_site(): void {
		Functions\expect( 'get_sites' )->never();
		Functions\expect( 'switch_to_blog' )->never();
		Functions\expect( 'restore_current_blog' )->never();

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );

		WC_AI_Storefront_Cache_Invalidator::deactivate();
	}

	public function test_deactivate_purges_sibling_sites_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );

		Functions\expect( 'switch_to_blog' )->times( 2 );
		Functions\expect( 'restore_current_blog' )->times( 2 );

		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );

		WC_AI_Storefront_Cache_Invalidator::deactivate();
	}

	public function test_deactivate_multisite_runs_wildcard_query_per_site(): void {
		global $wpdb;
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( $t ) => addcslashes( (string) $t, '_%\\' )
		);
		$wpdb->shouldReceive( 'query' )
			->times( 3 ) // 1 current + 2 siblings.
			->andReturn( 0 );

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );
		Functions\when( 'switch_to_blog' )->justReturn( true );
		Functions\when( 'restore_current_blog' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );

		WC_AI_Storefront_Cache_Invalidator::deactivate();
	}
}
