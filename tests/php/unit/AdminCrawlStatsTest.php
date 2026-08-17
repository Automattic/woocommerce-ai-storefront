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

		$response      = $this->controller->get_crawl_stats( $req );
		$response_data = $response->get_data();

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

	/**
	 * The endpoint-aggregate SQL must exclude store_api_search_hit so
	 * per-result impression rows don't inflate Catalog queries /
	 * total_requests / by_endpoint counts. The same exclusion must apply
	 * to the by-agent aggregate so an agent that ran a few searches
	 * doesn't dominate the chart with its impression rows.
	 *
	 * Pins the SQL contract by capturing the prepared SQL strings and
	 * asserting the exclusion clauses are present in both aggregates.
	 */
	public function test_aggregate_sql_excludes_search_hit_endpoint(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $default ) => $default );

		$captured_sqls = array();
		$captured_args = array();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function () use ( &$captured_sqls, &$captured_args ) {
					$args            = func_get_args();
					$captured_sqls[] = $args[0];
					$captured_args[] = array_slice( $args, 1 );
					return 'PREPARED';
				}
			);
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$req = new WP_REST_Request();
		$req->set_param( 'period', 'day' );

		$this->controller->get_crawl_stats( $req );

		// Two SQL strings need the exclusion: the per-endpoint aggregate
		// (identified by `GROUP BY endpoint`) and the by-agent aggregate
		// (`GROUP BY agent`).
		$endpoint_sql = '';
		$agent_sql    = '';
		foreach ( $captured_sqls as $i => $sql ) {
			if ( str_contains( $sql, 'GROUP BY endpoint' ) ) {
				$endpoint_sql = $sql;
				$this->assertContains(
					WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH_HIT,
					$captured_args[ $i ],
					'Endpoint-aggregate prepare call must pass the search_hit constant as a bound arg.'
				);
			}
			if ( str_contains( $sql, 'GROUP BY agent' ) ) {
				$agent_sql = $sql;
				$this->assertContains(
					WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH_HIT,
					$captured_args[ $i ],
					'Agent-aggregate prepare call must pass the search_hit constant as a bound arg.'
				);
			}
		}

		$this->assertNotEmpty( $endpoint_sql, 'Captured the endpoint-aggregate SQL.' );
		$this->assertNotEmpty( $agent_sql, 'Captured the agent-aggregate SQL.' );

		// Both SQL strings must contain the exclusion clause. Using
		// `endpoint != %s` (not interpolated) so the parameter is bound.
		$this->assertStringContainsString(
			'endpoint != %s',
			$endpoint_sql,
			'Endpoint aggregate must exclude search_hit via prepared parameter.'
		);
		$this->assertStringContainsString(
			'endpoint != %s',
			$agent_sql,
			'Agent aggregate must exclude search_hit via prepared parameter.'
		);
	}

	/**
	 * `unique_products` reads from the summary table with
	 * `WHERE product_id > 0` and no endpoint filter, so per-result
	 * impression rows (endpoint=store_api_search_hit, product_id=N)
	 * naturally feed it. This test pins that contract — a regression
	 * that adds an endpoint filter here would break the whole point
	 * of the search_hit endpoint.
	 *
	 * Captures the unique_products SQL and asserts it does NOT carry
	 * an endpoint filter. The complementary fact (the SQL DOES have
	 * `product_id > 0`) is unchanged from prior behavior and locked
	 * in by the existing top_queries / period tests above.
	 */
	public function test_unique_products_sql_has_no_endpoint_filter(): void {
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

		$unique_products_sql = '';
		foreach ( $captured_sqls as $sql ) {
			if ( str_contains( $sql, 'COUNT(DISTINCT product_id)' ) ) {
				$unique_products_sql = $sql;
				break;
			}
		}

		$this->assertNotEmpty( $unique_products_sql, 'Captured the unique_products SQL.' );
		$this->assertStringContainsString(
			'product_id > 0',
			$unique_products_sql,
			'unique_products must filter product_id > 0.'
		);
		$this->assertStringNotContainsString(
			'endpoint',
			$unique_products_sql,
			'unique_products must NOT filter by endpoint — search_hit rows feed it intentionally.'
		);
	}

	/**
	 * The "UCP manifest hits" and "llms.txt hits" cards were sunset when those
	 * endpoints became edge-cacheable (a cache HIT never reaches PHP, so the
	 * count is unreliable). The API must no longer surface those fields.
	 */
	public function test_response_omits_sunset_manifest_and_llms_fields(): void {
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

		$data = $this->controller->get_crawl_stats( $req )->get_data();

		$this->assertArrayNotHasKey( 'ucp_hits', $data );
		$this->assertArrayNotHasKey( 'llms_txt_hits', $data );
		// Sibling fields are untouched.
		$this->assertArrayHasKey( 'store_api_queries', $data );
		$this->assertArrayHasKey( 'throttle_rate', $data );
	}
}
