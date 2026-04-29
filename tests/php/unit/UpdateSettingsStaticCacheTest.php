<?php
/**
 * Regression tests for the static-cache reset in update_settings() (Fix #164).
 *
 * Pre-fix, update_settings() called wp_cache_delete() then get_settings()
 * without first nulling self::$settings_cache. Because get_settings() checks
 * the static cache first and returns early when it is non-null, the
 * wp_cache_delete() call had no practical effect — get_settings() returned
 * the stale in-process value rather than reading the freshly-persisted DB
 * value.
 *
 * Fix: update_settings() now sets self::$settings_cache = null before calling
 * wp_cache_delete() and get_settings(), so get_settings() always reads the
 * current persisted value from DB.
 *
 * Test harness note: the unit-test bootstrap loads the WC_AI_Storefront stub
 * before the production class file, so the real update_settings() cannot run
 * inside this harness. This file uses a small harness class that mirrors the
 * relevant portion of the production update_settings() logic — specifically
 * the sequence: null the static cache, delete the WP object cache entry, then
 * call get_settings(). Keep UpdateSettingsStaticCacheHarness in sync with
 * includes/class-wc-ai-storefront.php when modifying that sequence.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Mirrors the cache-reset sequence at the start of
 * WC_AI_Storefront::update_settings() in
 * includes/class-wc-ai-storefront.php.
 *
 * Tests focus on proving that a populated $settings_cache does NOT prevent
 * get_settings() from reading the current DB value when update_settings() is
 * called — the core invariant broken by the pre-fix code.
 */
class UpdateSettingsStaticCacheHarness {

	const SETTINGS_OPTION = 'wc_ai_storefront_settings';

	/**
	 * Simulates the static settings cache. Mirrors production
	 * WC_AI_Storefront::$settings_cache.
	 *
	 * @var array|null
	 */
	public static $settings_cache = null;

	/**
	 * Run the cache-reset-then-read sequence that starts update_settings(),
	 * returning the settings array that get_settings() produces after the
	 * cache is cleared.
	 *
	 * The harness intentionally does NOT complete the full update_settings()
	 * logic (the merge, sanitize, and update_option steps) — only the
	 * cache-clearing prefix that this test file is exercising.
	 *
	 * @return array Settings read from the (now-cleared) cache.
	 */
	public static function run_cache_reset_and_read(): array {
		// Mirror production: reset static cache first so the wp_cache_delete
		// + get_settings() call actually reads the current DB value.
		self::$settings_cache = null;
		wp_cache_delete( self::SETTINGS_OPTION, 'options' );
		return self::get_settings();
	}

	/**
	 * Simplified get_settings() that checks the static cache first.
	 * Mirrors the relevant portion of the production method.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$settings             = get_option( self::SETTINGS_OPTION, [] );
		self::$settings_cache = is_array( $settings ) ? $settings : [];
		return self::$settings_cache;
	}
}

class UpdateSettingsStaticCacheTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Tracks wp_cache_delete invocations.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public static array $cache_delete_calls = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		self::$cache_delete_calls                         = [];
		UpdateSettingsStaticCacheHarness::$settings_cache = null;

		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key, $group = '' ) {
				UpdateSettingsStaticCacheTest::$cache_delete_calls[] = [ $key, $group ];
				return true;
			}
		);
	}

	protected function tearDown(): void {
		UpdateSettingsStaticCacheHarness::$settings_cache = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Fix #164 regression: update_settings() must read fresh from DB
	// even when the static cache is populated with a stale value.
	// ------------------------------------------------------------------

	public function test_update_settings_reads_fresh_db_value_when_static_cache_is_stale(): void {
		// Pre-populate the static cache with a stale value. Pre-fix,
		// update_settings() would return this stale cached value from
		// get_settings() instead of the fresh DB value.
		UpdateSettingsStaticCacheHarness::$settings_cache = [
			'product_selection_mode' => 'all',
			'enabled'                => 'yes',
		];

		// The DB has a different (newer) value — simulates a concurrent
		// write or a value saved before the cache was primed.
		Functions\when( 'get_option' )->justReturn(
			[
				'product_selection_mode' => 'by_taxonomy',
				'enabled'                => 'no',
			]
		);

		$result = UpdateSettingsStaticCacheHarness::run_cache_reset_and_read();

		// After cache reset, get_settings() must read from DB, NOT from
		// the stale in-memory value.
		$this->assertSame( 'by_taxonomy', $result['product_selection_mode'] );
		$this->assertSame( 'no', $result['enabled'] );
	}

	public function test_update_settings_static_cache_is_null_before_get_settings_is_called(): void {
		// Verify that the cache-reset step actually nulls the cache so
		// a subsequent get_settings() cannot take the early-return path.
		UpdateSettingsStaticCacheHarness::$settings_cache = [
			'product_selection_mode' => 'selected',
		];

		Functions\when( 'get_option' )->justReturn(
			[ 'product_selection_mode' => 'all' ]
		);

		// After run_cache_reset_and_read() the cache is repopulated with
		// the DB value, not the stale in-memory value.
		$result = UpdateSettingsStaticCacheHarness::run_cache_reset_and_read();
		$this->assertSame( 'all', $result['product_selection_mode'] );
	}

	public function test_update_settings_calls_wp_cache_delete_before_reading(): void {
		// wp_cache_delete must be called so a persistent object cache
		// (Redis / Memcached) deployment doesn't serve a stale value.
		Functions\when( 'get_option' )->justReturn( [] );

		UpdateSettingsStaticCacheHarness::run_cache_reset_and_read();

		$this->assertCount( 1, self::$cache_delete_calls );
		$this->assertSame( UpdateSettingsStaticCacheHarness::SETTINGS_OPTION, self::$cache_delete_calls[0][0] );
		$this->assertSame( 'options', self::$cache_delete_calls[0][1] );
	}

	public function test_update_settings_handles_empty_db_option(): void {
		// When the DB has no stored option (fresh install), get_settings()
		// must return an empty array after the cache is cleared.
		UpdateSettingsStaticCacheHarness::$settings_cache = [
			'product_selection_mode' => 'by_taxonomy',
		];

		Functions\when( 'get_option' )->justReturn( [] );

		$result = UpdateSettingsStaticCacheHarness::run_cache_reset_and_read();
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}
}
