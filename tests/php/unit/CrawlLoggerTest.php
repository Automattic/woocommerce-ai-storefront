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
		$this->assertSame( 'GPTBot', $agent );
		$this->assertSame( WC_AI_Storefront_Crawl_Logger::ENDPOINT_LLMS_TXT, $endpoint );
		$this->assertNull( $query );
		$this->assertSame( 0, $throttled );
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

	public function test_record_stores_null_query_when_empty_string(): void {
		WC_AI_Storefront_Crawl_Logger::record(
			WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH,
			0,
			'ClaudeBot',
			''
		);

		$pending = $this->pending();
		$this->assertNull( $pending[0][3], 'Empty query string should be stored as NULL' );
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

		$captured_sql = '';

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing(
				static function ( $sql ) use ( &$captured_sql ) {
					$captured_sql = $sql;
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
}
