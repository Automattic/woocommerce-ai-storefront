<?php
/**
 * Tests for WC_AI_Storefront_Admin_Controller::get_crawl_stats().
 *
 * Pins the top_queries SQL contract: the query must include a lower-bound
 * date filter (crawled_at >= $after) but must NOT include an upper-bound
 * (crawled_at < $today_start), so today's searches are always included.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AdminCrawlStatsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Admin_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->controller = new WC_AI_Storefront_Admin_Controller();

		Functions\when( '__' )->alias( static fn( $text ) => $text );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wc_get_logger' )->justReturn( null );
		// Shared schedule registry for get_effective_rollup_interval().
		// Individual tests mock apply_filters themselves since Brain Monkey
		// does not allow registering the same function twice per test.
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly'     => array( 'interval' => HOUR_IN_SECONDS ),
				'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS ),
				'daily'      => array( 'interval' => DAY_IN_SECONDS ),
			)
		);
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The top_queries SQL must use only a lower-bound date filter so that
	 * today's searches are included. A regression adding crawled_at < $today
	 * would silently exclude today's queries.
	 */
	public function test_top_queries_sql_has_no_upper_date_bound(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => $default );

		$captured_sqls = array();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function () use ( &$captured_sqls ) {
					$args            = func_get_args();
					$captured_sqls[] = $args[0];
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$req = new WP_REST_Request();
		$req->set_param( 'period', 'day' );

		$this->controller->get_crawl_stats( $req );

		$query_sql = '';
		foreach ( $captured_sqls as $sql ) {
			if ( strpos( $sql, 'query' ) !== false && strpos( $sql, 'crawled_at' ) !== false ) {
				$query_sql = $sql;
				break;
			}
		}

		$this->assertNotEmpty(
			$query_sql,
			'Expected to capture the top_queries SQL'
		);
		$this->assertStringContainsString(
			'crawled_at >= %s',
			$query_sql,
			'top_queries SQL must have a lower-bound date filter'
		);
		$this->assertStringNotContainsString(
			'crawled_at < %s',
			$query_sql,
			'top_queries SQL must NOT have an upper-bound date filter — today\'s searches must be included'
		);
	}

	/**
	 * For period=quarter (90d), top_queries reads from the raw log which is
	 * pruned at RAW_RETENTION_DAYS (30d). The lower-bound timestamp passed
	 * to the SQL must be clamped to ~30 days back, not 90, so the parameter
	 * reflects what the table actually contains. The response must also
	 * surface top_queries_window_days = 30 so the UI can label it.
	 */
	public function test_top_queries_window_clamps_to_raw_retention_for_quarter_period(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => $default );

		$captured_top_queries_arg = null;

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function () use ( &$captured_top_queries_arg ) {
					$args = func_get_args();
					$sql  = $args[0];
					// Identify the top_queries SQL by its unique GROUP_CONCAT shape
					// — it's the only query in get_crawl_stats() that uses it.
					if ( false !== strpos( $sql, 'GROUP_CONCAT' ) ) {
						// Args: (sql, $endpoint, $after_datetime). We want the date.
						$captured_top_queries_arg = $args[2] ?? null;
					}
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$req = new WP_REST_Request();
		$req->set_param( 'period', 'quarter' );

		$response       = $this->controller->get_crawl_stats( $req );
		$response_data  = $response->get_data();

		$this->assertNotNull(
			$captured_top_queries_arg,
			'Expected to capture the top_queries date argument'
		);

		// The clamped lookback should be ~30 days back, not 90. Compare the
		// captured timestamp's epoch to "now minus 30 days" with a tolerance
		// of a few seconds for the time drift between request and assertion.
		$captured_ts        = strtotime( $captured_top_queries_arg . ' UTC' );
		$expected_ts_30_day = time() - 30 * DAY_IN_SECONDS;
		$this->assertGreaterThanOrEqual(
			$expected_ts_30_day - DAY_IN_SECONDS,
			$captured_ts,
			'top_queries lower bound must not be more than 30 days back (raw log retention)'
		);
		$this->assertLessThanOrEqual(
			$expected_ts_30_day + DAY_IN_SECONDS,
			$captured_ts,
			'top_queries lower bound must be ~30 days back for quarter period (clamp)'
		);

		// The response must surface the effective window so the UI can label it.
		$this->assertSame(
			30,
			$response_data['top_queries_window_days'],
			'top_queries_window_days must be 30 when the period (quarter=90d) exceeds raw log retention'
		);
	}

	/**
	 * The response must include raw_event_count so the UI can distinguish
	 * "no activity ever" from "activity exists but not yet rolled up".
	 */
	public function test_get_crawl_stats_includes_raw_event_count(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => $default );

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$req = new WP_REST_Request();
		$req->set_param( 'period', 'day' );

		$response_data = $this->controller->get_crawl_stats( $req )->get_data();

		$this->assertArrayHasKey(
			'raw_event_count',
			$response_data,
			'Response must include raw_event_count'
		);
		$this->assertSame(
			0,
			$response_data['raw_event_count'],
			'raw_event_count must be 0 when DB returns 0'
		);
	}

	/**
	 * The response must include rollup_interval so the UI can render a
	 * specific "Updated X." subtitle rather than a generic fallback.
	 */
	public function test_get_crawl_stats_includes_rollup_interval(): void {
		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'PREPARED' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		Functions\expect( 'apply_filters' )
			->with( 'wc_ai_storefront_rollup_interval', 'hourly' )
			->andReturn( 'twicedaily' );
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly'     => array( 'interval' => HOUR_IN_SECONDS ),
				'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS ),
				'daily'      => array( 'interval' => DAY_IN_SECONDS ),
			)
		);

		$req = new WP_REST_Request();
		$req->set_param( 'period', 'week' );

		$response_data = $this->controller->get_crawl_stats( $req )->get_data();

		$this->assertArrayHasKey(
			'rollup_interval',
			$response_data,
			'Response must include rollup_interval'
		);
		$this->assertSame(
			'twicedaily',
			$response_data['rollup_interval'],
			'rollup_interval must reflect the effective filtered value'
		);
	}
}
