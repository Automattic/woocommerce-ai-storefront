<?php
/**
 * Tests for WC_AI_Syndication_Rate_Limiter.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class RateLimiterTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Rate_Limiter $rate_limiter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->rate_limiter = new WC_AI_Syndication_Rate_Limiter();
		$this->ensure_settings_class();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ensure the WC_AI_Syndication class stub exists with get_settings().
	 */
	private function ensure_settings_class(): void {
		// The rate limiter calls WC_AI_Syndication::get_settings() statically.
		// We create a minimal stub if it doesn't exist yet.
		if ( ! class_exists( 'WC_AI_Syndication', false ) ) {
			require_once __DIR__ . '/../stubs/class-wc-ai-syndication-stub.php';
		}
	}

	public function test_check_allows_request_under_limits(): void {
		WC_AI_Syndication::$test_settings = [ 'rate_limit_rpm' => 60, 'rate_limit_rph' => 1000 ];

		Functions\expect( 'wp_using_ext_object_cache' )->andReturn( false );
		Functions\expect( 'get_transient' )->andReturn( 0 );
		Functions\expect( 'set_transient' )->andReturn( true );

		$result = $this->rate_limiter->check( 'bot-1' );

		$this->assertTrue( $result );
	}

	public function test_check_returns_error_when_minute_limit_exceeded(): void {
		WC_AI_Syndication::$test_settings = [ 'rate_limit_rpm' => 5, 'rate_limit_rph' => 1000 ];

		Functions\expect( 'wp_using_ext_object_cache' )->andReturn( false );
		Functions\expect( '__' )->andReturnFirstArg();

		// Minute counter is already at the limit (after increment: 6 > 5).
		Functions\expect( 'get_transient' )->andReturn( 5 );
		Functions\expect( 'set_transient' )->andReturn( true );

		$result = $this->rate_limiter->check( 'bot-1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_rate_limited', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertEquals( 429, $data['status'] );
		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThanOrEqual( 1, $data['retry_after'] );
	}

	public function test_check_returns_error_when_hour_limit_exceeded(): void {
		WC_AI_Syndication::$test_settings = [ 'rate_limit_rpm' => 60, 'rate_limit_rph' => 3 ];

		Functions\expect( 'wp_using_ext_object_cache' )->andReturn( false );
		Functions\expect( '__' )->andReturnFirstArg();

		// Minute counter is fine (0 → 1), hour counter is at the limit (3 → 4 > 3).
		$call_count = 0;
		Functions\expect( 'get_transient' )
			->andReturnUsing( function () use ( &$call_count ) {
				$call_count++;
				return $call_count === 1 ? 0 : 3;
			} );

		Functions\expect( 'set_transient' )->andReturn( true );

		$result = $this->rate_limiter->check( 'bot-1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_rate_limited', $result->get_error_code() );
	}

	public function test_check_uses_wp_cache_incr_with_object_cache(): void {
		WC_AI_Syndication::$test_settings = [ 'rate_limit_rpm' => 60, 'rate_limit_rph' => 1000 ];

		Functions\expect( 'wp_using_ext_object_cache' )->andReturn( true );

		// wp_cache_incr returns the new count (1 after first increment).
		Functions\expect( 'wp_cache_incr' )->twice()->andReturn( 1 );

		$result = $this->rate_limiter->check( 'bot-1' );

		$this->assertTrue( $result );
	}

	public function test_check_initializes_cache_key_when_incr_returns_false(): void {
		WC_AI_Syndication::$test_settings = [ 'rate_limit_rpm' => 60, 'rate_limit_rph' => 1000 ];

		Functions\expect( 'wp_using_ext_object_cache' )->andReturn( true );

		$incr_calls = 0;
		Functions\expect( 'wp_cache_incr' )
			->twice()
			->andReturnUsing( function () use ( &$incr_calls ) {
				$incr_calls++;
				return $incr_calls === 1 ? false : 1;
			} );

		Functions\expect( 'wp_cache_set' )->once()->andReturn( true );

		$result = $this->rate_limiter->check( 'bot-1' );

		$this->assertTrue( $result );
	}

	public function test_different_bots_have_independent_counters(): void {
		WC_AI_Syndication::$test_settings = [ 'rate_limit_rpm' => 100, 'rate_limit_rph' => 10000 ];

		Functions\expect( 'wp_using_ext_object_cache' )->andReturn( false );

		$keys_set = [];
		Functions\expect( 'get_transient' )->andReturn( 0 );
		Functions\expect( 'set_transient' )
			->andReturnUsing( function ( $key ) use ( &$keys_set ) {
				$keys_set[] = $key;
				return true;
			} );

		$this->rate_limiter->check( 'bot-a' );
		$this->rate_limiter->check( 'bot-b' );

		// 4 different transient keys: minute + hour for each bot.
		$this->assertCount( 4, $keys_set );
		$this->assertCount( 4, array_unique( $keys_set ) );
	}
}
