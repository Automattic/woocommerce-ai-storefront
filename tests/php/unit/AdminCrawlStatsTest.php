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
}
