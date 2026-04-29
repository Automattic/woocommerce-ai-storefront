<?php
/**
 * Tests for WC_AI_Storefront_Store_Api_Rate_Limiter.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class StoreApiRateLimiterTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Store_Api_Rate_Limiter $limiter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		// Sanitization helpers are called by both current_user_agent() and
		// current_request_ip(). Stub them globally so individual tests don't
		// need to repeat the boilerplate. Tests that need strict verification
		// can still override with Functions\expect().
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		// Provide a stable IP so fingerprint assertions are deterministic.
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->limiter          = new WC_AI_Storefront_Store_Api_Rate_Limiter();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// configure_rate_limits
	// ------------------------------------------------------------------

	public function test_enables_rate_limiting_when_syndication_active(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 50 ];

		$result = $this->limiter->configure_rate_limits( [ 'enabled' => false ] );

		$this->assertTrue( $result['enabled'] );
		$this->assertTrue( $result['proxy_support'] );
		$this->assertEquals( 50, $result['limit'] );
		$this->assertEquals( 60, $result['seconds'] );
	}

	public function test_returns_default_options_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$defaults = [ 'enabled' => false, 'limit' => 25 ];
		$result   = $this->limiter->configure_rate_limits( $defaults );

		$this->assertEquals( $defaults, $result );
	}

	public function test_uses_default_rpm_when_not_configured(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes' ];

		$result = $this->limiter->configure_rate_limits( [] );

		$this->assertEquals( 25, $result['limit'] );
	}

	// ------------------------------------------------------------------
	// fingerprint_ai_bots
	// ------------------------------------------------------------------

	public function test_fingerprints_known_ai_bot_by_user_agent(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0)';

		$result = $this->limiter->fingerprint_ai_bots( 'default_id' );

		$this->assertStringStartsWith( 'ai_bot_', $result );
		$this->assertNotEquals( 'default_id', $result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_returns_default_id_for_regular_user_agent(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';

		$result = $this->limiter->fingerprint_ai_bots( 'default_id' );

		$this->assertEquals( 'default_id', $result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_fingerprints_claude_bot(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'ClaudeBot/1.0';

		$result = $this->limiter->fingerprint_ai_bots( 'default_id' );

		$this->assertStringStartsWith( 'ai_bot_', $result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_different_bots_get_different_fingerprints(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';
		$gpt_result = $this->limiter->fingerprint_ai_bots( 'x' );

		$_SERVER['HTTP_USER_AGENT'] = 'ClaudeBot/1.0';
		$claude_result = $this->limiter->fingerprint_ai_bots( 'x' );

		$this->assertNotEquals( $gpt_result, $claude_result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_fingerprint_is_stable_across_ua_version_variants(): void {
		// GPTBot/1.0 and GPTBot/2.0 both match the 'GPTBot' crawler name.
		// The fingerprint must be identical for both (keyed on bot name +
		// IP, not the raw UA string) so version rotation cannot bypass the
		// rate-limit window.
		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';
		$v1 = $this->limiter->fingerprint_ai_bots( 'x' );

		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/2.0';
		$v2 = $this->limiter->fingerprint_ai_bots( 'x' );

		$this->assertSame( $v1, $v2, 'UA version rotation must not create a new rate-limit bucket' );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	// ------------------------------------------------------------------
	// configure_rate_limits — inner-dispatch suppression
	// ------------------------------------------------------------------

	public function test_configure_rate_limits_suppresses_inner_dispatch(): void {
		// When inside a UCP-controller dispatch (depth > 0), inner
		// Store API calls must NOT count against the rate-limit budget.
		// The outer request already consumed one slot via
		// check_outer_rate_limit(); counting inner calls as well would
		// drain 50 slots for a single /catalog/lookup with 50 IDs.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];

		WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$result = $this->limiter->configure_rate_limits( [] );
		} finally {
			WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}

		$this->assertFalse(
			$result['enabled'],
			'Rate limiting must be disabled for inner Store API dispatches inside a UCP handler.'
		);
	}

	public function test_configure_rate_limits_enables_outside_ucp_dispatch(): void {
		// Outside a UCP dispatch (depth = 0), direct Store API requests
		// from AI bots are rate-limited as usual.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];

		// Confirm depth is 0 (default state).
		$this->assertFalse( WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch() );

		$result = $this->limiter->configure_rate_limits( [] );

		$this->assertTrue( $result['enabled'] );
	}

	// ------------------------------------------------------------------
	// check_outer_rate_limit
	// ------------------------------------------------------------------

	public function test_outer_rate_limit_returns_true_when_plugin_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$result = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertTrue( $result );
	}

	public function test_outer_rate_limit_rate_limits_unknown_ua_on_first_request(): void {
		// Previously, a non-AI-bot UA returned `true` immediately with no
		// rate-limit check. After the FIND-S01 fix, unknown UAs reaching
		// check_outer_rate_limit() (e.g. allow_unknown_ucp_agents=yes) are
		// also counted against the per-IP budget.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 5 ];
		$_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 Chrome/120.0.0.0';

		// First request in the window — transient not yet set.
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->with(
			\Mockery::type( 'string' ),
			1,
			60
		);

		$result = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertTrue( $result );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_outer_rate_limit_blocks_unknown_ua_when_limit_reached(): void {
		// Unknown-UA requests (allow_unknown_ucp_agents=yes path) must be
		// blocked once the per-IP budget is exhausted, just like known bots.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 5 ];
		$_SERVER['HTTP_USER_AGENT']      = 'SomeUnknownAgent/1.0';

		Functions\expect( 'get_transient' )->once()->andReturn( '5' );
		Functions\expect( 'set_transient' )->never();

		$result = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 429, $result->get_error_data()['status'] );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_outer_rate_limit_allows_first_request_under_limit(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];
		$_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

		// No existing transient (first request in window).
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->with(
			\Mockery::type( 'string' ),
			1,
			60
		);

		$result = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertTrue( $result );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_outer_rate_limit_increments_counter_on_subsequent_requests(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];
		$_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

		// Existing count of 10 — well under the limit of 25.
		Functions\expect( 'get_transient' )->once()->andReturn( '10' );
		Functions\expect( 'set_transient' )->once()->with(
			\Mockery::type( 'string' ),
			11,
			60
		);

		$result = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertTrue( $result );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_outer_rate_limit_blocks_when_limit_reached(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];
		$_SERVER['HTTP_USER_AGENT']      = 'GPTBot/1.0';

		// Count is already at the limit — next request should be blocked.
		Functions\expect( 'get_transient' )->once()->andReturn( '25' );
		// set_transient must NOT be called when the request is blocked.
		Functions\expect( 'set_transient' )->never();

		$result = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( WC_AI_Storefront_UCP_Error_Codes::UCP_RATE_LIMIT_EXCEEDED, $result->get_error_code() );
		$this->assertEquals( 429, $result->get_error_data()['status'] );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_outer_rate_limit_transient_key_is_fingerprint_based(): void {
		// Two different AI bots from the same IP must get different transient
		// keys so their budgets are tracked independently.
		// Key format: OUTER_TRANSIENT_PREFIX + 'ai_bot_' + md5(bot_name + '_' + ip).
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];

		$recorded_keys = [];

		Functions\expect( 'get_transient' )->andReturn( false );
		Functions\expect( 'set_transient' )->andReturnUsing(
			static function ( $key ) use ( &$recorded_keys ) {
				$recorded_keys[] = $key;
			}
		);

		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';
		WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$_SERVER['HTTP_USER_AGENT'] = 'ClaudeBot/1.0';
		WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertCount( 2, $recorded_keys );
		$this->assertNotEquals( $recorded_keys[0], $recorded_keys[1] );
		$this->assertStringStartsWith(
			WC_AI_Storefront_Store_Api_Rate_Limiter::OUTER_TRANSIENT_PREFIX,
			$recorded_keys[0]
		);

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_outer_rate_limit_same_bot_different_ips_get_separate_buckets(): void {
		// Two IPs claiming to be the same bot must get separate rate-limit
		// windows. If keys were based on UA alone, IP-A could exhaust IP-B's
		// budget by spoofing the same user-agent.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];

		$recorded_keys = [];

		Functions\expect( 'get_transient' )->andReturn( false );
		Functions\expect( 'set_transient' )->andReturnUsing(
			static function ( $key ) use ( &$recorded_keys ) {
				$recorded_keys[] = $key;
			}
		);

		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';
		$_SERVER['REMOTE_ADDR']     = '1.2.3.4';
		WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$_SERVER['REMOTE_ADDR'] = '5.6.7.8';
		WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertCount( 2, $recorded_keys );
		$this->assertNotEquals(
			$recorded_keys[0],
			$recorded_keys[1],
			'Same bot from different IPs must use separate rate-limit buckets'
		);

		unset( $_SERVER['HTTP_USER_AGENT'] );
		// REMOTE_ADDR is unset in tearDown; setUp re-sets it to 127.0.0.1 for the next test.
	}

	public function test_outer_rate_limit_ua_rotation_uses_same_bucket(): void {
		// A single IP sending requests with minor UA variants of the same
		// bot (e.g. GPTBot/1.0 vs GPTBot/2.0) must share one bucket.
		// If they didn't, UA rotation would bypass the sliding window.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'yes', 'rate_limit_rpm' => 25 ];

		$recorded_keys = [];

		Functions\expect( 'get_transient' )->andReturn( false );
		Functions\expect( 'set_transient' )->andReturnUsing(
			static function ( $key ) use ( &$recorded_keys ) {
				$recorded_keys[] = $key;
			}
		);

		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';
		WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/2.0'; // Minor version change.
		WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();

		$this->assertCount( 2, $recorded_keys );
		$this->assertSame(
			$recorded_keys[0],
			$recorded_keys[1],
			'UA version rotation must not create a new rate-limit bucket'
		);

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}
}
