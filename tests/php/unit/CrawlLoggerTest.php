<?php
/**
 * Tests for WC_AI_Storefront_Crawl_Logger.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class CrawlLoggerTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * ReflectionProperty for private static $pending.
	 *
	 * @var \ReflectionProperty
	 */
	private static \ReflectionProperty $rp_pending;

	/**
	 * ReflectionProperty for private static $shutdown_registered.
	 *
	 * @var \ReflectionProperty
	 */
	private static \ReflectionProperty $rp_shutdown;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$ref               = new \ReflectionClass( WC_AI_Storefront_Crawl_Logger::class );
		self::$rp_pending  = $ref->getProperty( 'pending' );
		self::$rp_shutdown = $ref->getProperty( 'shutdown_registered' );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `add_action` is called from record(); let Brain Monkey intercept
		// it silently by default. Tests that want to assert the call count
		// set up Functions\expect() themselves before triggering record().
		Functions\when( 'current_time' )->justReturn( '2025-01-01 12:00:00' );
		Functions\when( 'delete_transient' )->justReturn( true );
		// Reset static state between tests.
		self::$rp_pending->setValue( null, [] );
		self::$rp_shutdown->setValue( null, false );
	}

	protected function tearDown(): void {
		// Drain any buffered events so they don't leak into the next test class.
		self::$rp_pending->setValue( null, [] );
		self::$rp_shutdown->setValue( null, false );
		global $wpdb;
		$wpdb = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helper: read the internal pending buffer via reflection.
	// ------------------------------------------------------------------

	private function pending(): array {
		return self::$rp_pending->getValue( null );
	}

	private function shutdown_registered(): bool {
		return self::$rp_shutdown->getValue( null );
	}

	// ------------------------------------------------------------------
	// record() — buffering behaviour
	// ------------------------------------------------------------------

	public function test_record_skips_empty_agent(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT,
			0,
			''
		);

		$this->assertEmpty( $this->pending() );
		$this->assertFalse( $this->shutdown_registered() );
	}

	public function test_record_buffers_event_with_known_agent(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT,
			0,
			'GPTBot'
		);

		$pending = $this->pending();
		$this->assertCount( 1, $pending );
		[ $product_id, $agent, $endpoint, $query, $throttled ] = $pending[0];
		$this->assertSame( 0, $product_id );
		$this->assertSame( 'ChatGPT', $agent ); // GPTBot canonicalised to brand name.
		$this->assertSame( WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT, $endpoint );
		$this->assertSame( '', $query );
		$this->assertSame( 0, $throttled );
	}

	public function test_record_canonicalises_raw_bot_tokens_to_brand_names(): void {
		$cases = array(
			'GPTBot'        => 'ChatGPT',
			'ChatGPT-User'  => 'ChatGPT',
			'OAI-SearchBot' => 'ChatGPT',
			'ClaudeBot'     => 'Claude',
			'Claude-User'   => 'Claude',
			'PerplexityBot' => 'Perplexity',
			'Perplexity-User' => 'Perplexity',
			'KlarnaBot'     => 'Klarna',
			'UnknownBot'    => 'UnknownBot', // Unknown tokens pass through unchanged.
		);

		foreach ( $cases as $raw => $expected ) {
			self::$rp_pending->setValue( null, [] );
			self::$rp_shutdown->setValue( null, false );
			WC_AI_Storefront_Crawl_Logger::record(
				WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP,
				0,
				$raw
			);
			$this->assertSame(
				$expected,
				$this->pending()[0][1],
				"'$raw' should be stored as '$expected'"
			);
		}
	}

	public function test_record_stores_query_string_when_non_empty(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH,
			0,
			'ClaudeBot',
			'red shoes'
		);

		$pending = $this->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( 'red shoes', $pending[0][3] );
	}

	public function test_record_stores_empty_string_query_when_not_provided(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH,
			0,
			'ClaudeBot',
			''
		);

		$pending = $this->pending();
		$this->assertSame( '', $pending[0][3], 'Empty query string should be stored as empty string (not NULL) to match DB write' );
	}

	public function test_record_stores_throttled_flag(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH,
			0,
			'GPTBot',
			'',
			true
		);

		$pending = $this->pending();
		$this->assertSame( 1, $pending[0][4] );
	}

	public function test_record_stores_product_id(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SINGLE,
			42,
			'PerplexityBot'
		);

		$pending = $this->pending();
		$this->assertSame( 42, $pending[0][0] );
	}

	public function test_record_accumulates_multiple_events(): void {
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT, 0, 'GPTBot' );
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'ClaudeBot' );
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SINGLE, 5, 'PerplexityBot' );

		$this->assertCount( 3, $this->pending() );
	}

	public function test_record_registers_shutdown_on_first_call(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'shutdown', [ WC_AI_Storefront_Crawl_Logger::class, 'flush' ] );

		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'GPTBot' );

		$this->assertTrue( $this->shutdown_registered() );
	}

	public function test_record_does_not_double_register_shutdown(): void {
		// add_action must be called only once even across multiple record() calls.
		Functions\expect( 'add_action' )->once();

		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'GPTBot' );
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT, 0, 'ClaudeBot' );
	}

	public function test_record_re_registers_shutdown_after_flush(): void {
		// First record → registers shutdown (1st add_action call).
		// flush() → clears the flag.
		// Second record → registers shutdown again (2nd add_action call).
		Functions\expect( 'add_action' )->twice();

		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'GPTBot' );

		// Flush: needs $wpdb stub so it doesn't fatal.
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		WC_AI_Storefront_Crawl_Logger::flush();

		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT, 0, 'GPTBot' );
	}

	// ------------------------------------------------------------------
	// flush() — write path
	// ------------------------------------------------------------------

	public function test_flush_is_noop_when_pending_is_empty(): void {
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'query' )->never();
		$wpdb->shouldReceive( 'prepare' )->never();

		WC_AI_Storefront_Crawl_Logger::flush();
	}

	public function test_flush_executes_insert_for_single_event(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT,
			0,
			'GPTBot'
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED INSERT SQL' );
		$wpdb->shouldReceive( 'query' )
			->once()
			->with( 'PREPARED INSERT SQL' )
			->andReturn( 1 );

		WC_AI_Storefront_Crawl_Logger::flush();
	}

	public function test_flush_inserts_all_pending_rows_in_single_query(): void {
		// Three records → prepare() called once with all three rows in the
		// VALUES clause, not three separate INSERT statements.
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT, 0, 'GPTBot' );
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'ClaudeBot' );
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SINGLE, 7, 'PerplexityBot' );

		$captured_sql    = '';
		$captured_values = [];

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				static function ( $sql, $values ) use ( &$captured_sql, &$captured_values ) {
					$captured_sql    = $sql;
					$captured_values = $values;
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 3 );

		WC_AI_Storefront_Crawl_Logger::flush();

		// All three row placeholders appear in the single VALUES clause.
		$this->assertSame(
			3,
			substr_count( $captured_sql, '(%d, %s, %s, %s, %d, %s)' ),
			'All three rows must be batched into one INSERT VALUES clause'
		);
		// 6 bindings per row × 3 rows = 18 total values.
		$this->assertCount(
			18,
			$captured_values,
			'Values array must contain 6 bindings × 3 rows = 18 entries'
		);
	}

	public function test_flush_clears_pending_after_write(): void {
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'GPTBot' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		WC_AI_Storefront_Crawl_Logger::flush();

		$this->assertEmpty( $this->pending(), 'Pending buffer must be empty after flush' );
	}

	public function test_flush_resets_shutdown_registered_flag(): void {
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'GPTBot' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		WC_AI_Storefront_Crawl_Logger::flush();

		$this->assertFalse(
			$this->shutdown_registered(),
			'shutdown_registered must be reset to false after flush so the next record() call can re-register'
		);
	}

	public function test_flush_second_call_is_noop(): void {
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT, 0, 'GPTBot' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		WC_AI_Storefront_Crawl_Logger::flush();
		// Second flush must not call query() again — pending is already empty.
		WC_AI_Storefront_Crawl_Logger::flush();
	}

	public function test_flush_uses_correct_table_name(): void {
		WC_AI_Storefront_Crawl_Logger::record( WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP, 0, 'GPTBot' );

		$captured_sql = '';

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'test_';
		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		WC_AI_Storefront_Crawl_Logger::flush();

		$expected_table = 'test_' . WC_AI_Storefront_Crawl_Logger::TABLE_LOG;
		$this->assertStringContainsString(
			$expected_table,
			$captured_sql,
			'flush() must INSERT into the prefixed crawl-log table'
		);
	}

	// ------------------------------------------------------------------
	// ENDPOINT_* constants
	// ------------------------------------------------------------------

	public function test_endpoint_constants_are_distinct(): void {
		$constants = [
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_PRODUCT_PAGE,
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SINGLE,
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH,
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT,
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_UCP,
		];

		$this->assertSame(
			count( $constants ),
			count( array_unique( $constants ) ),
			'All ENDPOINT_* constants must be distinct strings'
		);
	}

	// ------------------------------------------------------------------
	// prune_raw_log()
	// ------------------------------------------------------------------

	public function test_prune_raw_log_targets_correct_table(): void {
		$captured_sql = '';

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'test_';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		WC_AI_Storefront_Crawl_Logger::prune_raw_log();

		$this->assertStringContainsString(
			'test_' . WC_AI_Storefront_Crawl_Logger::TABLE_LOG,
			$captured_sql,
			'prune_raw_log() must DELETE from the prefixed raw log table'
		);
		$this->assertStringNotContainsString(
			WC_AI_Storefront_Crawl_Logger::TABLE_SUMMARY,
			$captured_sql,
			'prune_raw_log() must not touch the summary table'
		);
	}

	public function test_prune_raw_log_cutoff_matches_retention_days(): void {
		$captured_sql    = '';
		$captured_values = [];

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				static function ( $sql, $cutoff ) use ( &$captured_sql, &$captured_values ) {
					$captured_sql      = $sql;
					$captured_values[] = $cutoff;
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . WC_AI_Storefront_Crawl_Logger::RAW_RETENTION_DAYS . ' days' ) );
		WC_AI_Storefront_Crawl_Logger::prune_raw_log();
		$after = gmdate( 'Y-m-d H:i:s', strtotime( '-' . WC_AI_Storefront_Crawl_Logger::RAW_RETENTION_DAYS . ' days' ) );

		$cutoff = $captured_values[0];
		$this->assertGreaterThanOrEqual( $before, $cutoff );
		$this->assertLessThanOrEqual( $after, $cutoff );
	}

	// ------------------------------------------------------------------
	// prune_summary()
	// ------------------------------------------------------------------

	public function test_prune_summary_targets_correct_table(): void {
		$captured_sql = '';

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'test_';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		WC_AI_Storefront_Crawl_Logger::prune_summary();

		$this->assertStringContainsString(
			'test_' . WC_AI_Storefront_Crawl_Logger::TABLE_SUMMARY,
			$captured_sql,
			'prune_summary() must DELETE from the prefixed summary table'
		);
		$this->assertStringNotContainsString(
			WC_AI_Storefront_Crawl_Logger::TABLE_LOG,
			$captured_sql,
			'prune_summary() must not touch the raw log table'
		);
	}

	// ------------------------------------------------------------------
	// rollup()
	// ------------------------------------------------------------------

	public function test_rollup_prefix_is_interpolated_not_literal(): void {
		// Regression test for the single-quote interpolation bug:
		// the SQL template previously contained a single-quoted string
		// with `{$wpdb->prefix}` which PHP does not interpolate, causing
		// every nightly cron to fail with a MySQL parse error on `{`.
		// Capture only the first prepare() call (the INSERT); the second
		// is from prune_summary() which is an unrelated DELETE.
		$captured_sql  = '';
		$call_index    = 0;

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )
			->twice()
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql, &$call_index ) {
					if ( 0 === $call_index++ ) {
						$captured_sql = $sql;
					}
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		WC_AI_Storefront_Crawl_Logger::rollup();

		$this->assertStringNotContainsString(
			'{$wpdb->prefix}',
			$captured_sql,
			'rollup() SQL must not contain the literal string {$wpdb->prefix} — use double-quoted strings for interpolation'
		);
		$this->assertStringContainsString(
			'wp_' . WC_AI_Storefront_Crawl_Logger::TABLE_LOG,
			$captured_sql,
			'rollup() SELECT must reference the prefixed raw log table'
		);
		$this->assertStringContainsString(
			'wp_' . WC_AI_Storefront_Crawl_Logger::TABLE_SUMMARY,
			$captured_sql,
			'rollup() INSERT must reference the prefixed summary table'
		);
	}

	public function test_rollup_calls_prune_summary_on_success(): void {
		$query_call_count = 0;

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'query' )
			->twice()
			->andReturnUsing(
				static function () use ( &$query_call_count ) {
					return ++$query_call_count;
				}
			);

		WC_AI_Storefront_Crawl_Logger::rollup();

		// First query = INSERT INTO summary; second query = DELETE FROM summary (prune).
		$this->assertSame( 2, $query_call_count, 'rollup() must call query() twice: INSERT then prune DELETE' );
	}

	public function test_rollup_does_not_call_prune_summary_on_db_failure(): void {
		// If the INSERT fails, prune_summary() must not run — it would
		// delete summary rows that were never refreshed, destroying history.
		$query_call_count = 0;

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = 'simulated error';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'query' )
			->once()
			->andReturnUsing(
				static function () use ( &$query_call_count ) {
					++$query_call_count;
					return false; // simulate DB failure
				}
			);
		Functions\when( 'wc_get_logger' )->justReturn(
			Mockery::mock( [ 'error' => null ] )
		);

		WC_AI_Storefront_Crawl_Logger::rollup();

		$this->assertSame( 1, $query_call_count, 'rollup() must stop after the failed INSERT and not call prune_summary()' );
	}

	public function test_rollup_uses_yesterday_date(): void {
		// Pin $now to a fixed epoch so the test is immune to clock-tick
		// races at UTC midnight. rollup() accepts an optional $now param
		// specifically to enable this.
		$now            = gmmktime( 12, 0, 0, 6, 15, 2025 ); // 2025-06-15 12:00:00 UTC
		$expected_start = '2025-06-14 00:00:00';
		$expected_end   = '2025-06-16 00:00:00';

		$captured_args = [];
		$call_index    = 0;

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )
			->twice()
			->andReturnUsing(
				static function ( $sql, $range_start, $range_end = null ) use ( &$captured_args, &$call_index ) {
					if ( 0 === $call_index++ ) {
						$captured_args = [ $range_start, $range_end ];
					}
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		WC_AI_Storefront_Crawl_Logger::rollup( $now );

		$this->assertSame(
			$expected_start,
			$captured_args[0],
			'rollup() range must start at yesterday midnight'
		);
		$this->assertSame(
			$expected_end,
			$captured_args[1],
			'rollup() range must end at tomorrow midnight'
		);
	}

	// ------------------------------------------------------------------
	// schedule_crons() — interval and filter tests.
	//
	// schedule_crons() contains a static $scheduled guard that prevents
	// re-running in the same process. To keep tests independent we use
	// @runInSeparateProcess so each test starts with a fresh static state.
	// ------------------------------------------------------------------

	/**
	 * Default interval is hourly when the filter is not hooked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schedule_crons_uses_hourly_interval_by_default(): void {
		$scheduled_interval = null;
		$scheduled_timestamp = null;
		$before              = time();

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly'     => array(
					'interval' => HOUR_IN_SECONDS,
					'display'  => 'Once Hourly',
				),
				'twicedaily' => array(
					'interval' => 12 * HOUR_IN_SECONDS,
					'display'  => 'Twice Daily',
				),
				'daily'      => array(
					'interval' => DAY_IN_SECONDS,
					'display'  => 'Once Daily',
				),
			)
		);
		// Assert the hook tag AND the default argument so a regression that
		// renames the hook or drops the 'hourly' default is caught here.
		Functions\expect( 'apply_filters' )
			->with( 'wc_ai_storefront_rollup_interval', 'hourly' )
			->andReturn( 'hourly' );
		Functions\expect( 'wp_schedule_event' )
			->twice()
			->andReturnUsing(
				static function ( $timestamp, $recurrence, $hook ) use ( &$scheduled_interval, &$scheduled_timestamp ) {
					if ( 'wc_ai_storefront_rollup_crawl_log' === $hook ) {
						$scheduled_interval  = $recurrence;
						$scheduled_timestamp = $timestamp;
					}
					return true;
				}
			);

		WC_AI_Storefront_Crawl_Logger::schedule_crons();

		$after = time();

		$this->assertSame(
			'hourly',
			$scheduled_interval,
			'schedule_crons() must schedule rollup with hourly interval by default'
		);
		$this->assertGreaterThanOrEqual(
			$before,
			$scheduled_timestamp,
			'rollup cron must be scheduled to run immediately (at or after test start time)'
		);
		$this->assertLessThanOrEqual(
			$after + 5,
			$scheduled_timestamp,
			'rollup cron must be scheduled to run now, not at a distant future midnight'
		);
	}

	/**
	 * Filter wc_ai_storefront_rollup_interval overrides the default when the
	 * returned value is a registered WP-Cron schedule.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schedule_crons_respects_rollup_interval_filter(): void {
		$scheduled_interval = null;

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly'     => array(
					'interval' => HOUR_IN_SECONDS,
					'display'  => 'Once Hourly',
				),
				'twicedaily' => array(
					'interval' => 12 * HOUR_IN_SECONDS,
					'display'  => 'Twice Daily',
				),
				'daily'      => array(
					'interval' => DAY_IN_SECONDS,
					'display'  => 'Once Daily',
				),
			)
		);
		// Assert the hook tag AND the default argument so the filter contract
		// is locked down. The filter override returns 'twicedaily'.
		Functions\expect( 'apply_filters' )
			->with( 'wc_ai_storefront_rollup_interval', 'hourly' )
			->andReturn( 'twicedaily' );
		Functions\expect( 'wp_schedule_event' )
			->twice()
			->andReturnUsing(
				static function ( $_timestamp, $recurrence, $hook ) use ( &$scheduled_interval ) {
					if ( 'wc_ai_storefront_rollup_crawl_log' === $hook ) {
						$scheduled_interval = $recurrence;
					}
					return true;
				}
			);

		WC_AI_Storefront_Crawl_Logger::schedule_crons();

		$this->assertSame(
			'twicedaily',
			$scheduled_interval,
			'schedule_crons() must use the interval returned by wc_ai_storefront_rollup_interval filter'
		);
	}

	/**
	 * Filter wc_ai_storefront_rollup_interval returning an unregistered
	 * schedule name is silently discarded and falls back to hourly.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schedule_crons_falls_back_to_hourly_for_invalid_filter_value(): void {
		$scheduled_interval = null;

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly' => array(
					'interval' => HOUR_IN_SECONDS,
					'display'  => 'Once Hourly',
				),
				'daily'  => array(
					'interval' => DAY_IN_SECONDS,
					'display'  => 'Once Daily',
				),
			)
		);
		Functions\expect( 'apply_filters' )
			->with( 'wc_ai_storefront_rollup_interval', 'hourly' )
			->andReturn( 'gibberish' );
		Functions\expect( 'wp_schedule_event' )
			->twice()
			->andReturnUsing(
				static function ( $_timestamp, $recurrence, $hook ) use ( &$scheduled_interval ) {
					if ( 'wc_ai_storefront_rollup_crawl_log' === $hook ) {
						$scheduled_interval = $recurrence;
					}
					return true;
				}
			);

		WC_AI_Storefront_Crawl_Logger::schedule_crons();

		$this->assertSame(
			'hourly',
			$scheduled_interval,
			'schedule_crons() must fall back to hourly when filter returns an unregistered schedule'
		);
	}

	/**
	 * Filter wc_ai_storefront_rollup_interval returning a registered but
	 * too-slow cadence (e.g. 'weekly') is rejected. rollup() only covers
	 * a 2-day window, so anything slower than 'daily' would lose data
	 * between runs. The fallback is 'hourly'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schedule_crons_rejects_intervals_slower_than_daily(): void {
		$scheduled_interval = null;

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly' => array(
					'interval' => HOUR_IN_SECONDS,
					'display'  => 'Once Hourly',
				),
				'daily'  => array(
					'interval' => DAY_IN_SECONDS,
					'display'  => 'Once Daily',
				),
				'weekly' => array(
					'interval' => 7 * DAY_IN_SECONDS,
					'display'  => 'Once Weekly',
				),
			)
		);
		// 'weekly' is a registered schedule, but our allowlist rejects
		// anything slower than 'daily' because rollup() only materializes
		// rows from yesterday + today.
		Functions\expect( 'apply_filters' )
			->with( 'wc_ai_storefront_rollup_interval', 'hourly' )
			->andReturn( 'weekly' );
		Functions\expect( 'wp_schedule_event' )
			->twice()
			->andReturnUsing(
				static function ( $_timestamp, $recurrence, $hook ) use ( &$scheduled_interval ) {
					if ( 'wc_ai_storefront_rollup_crawl_log' === $hook ) {
						$scheduled_interval = $recurrence;
					}
					return true;
				}
			);

		WC_AI_Storefront_Crawl_Logger::schedule_crons();

		$this->assertSame(
			'hourly',
			$scheduled_interval,
			'schedule_crons() must reject intervals slower than daily and fall back to hourly'
		);
	}
}
