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
		$this->limiter = new WC_AI_Storefront_Store_Api_Rate_Limiter();
	}

	protected function tearDown(): void {
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

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$result = $this->limiter->fingerprint_ai_bots( 'default_id' );

		$this->assertStringStartsWith( 'ai_bot_', $result );
		$this->assertNotEquals( 'default_id', $result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_returns_default_id_for_regular_user_agent(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$result = $this->limiter->fingerprint_ai_bots( 'default_id' );

		$this->assertEquals( 'default_id', $result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_fingerprints_claude_bot(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'ClaudeBot/1.0';

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$result = $this->limiter->fingerprint_ai_bots( 'default_id' );

		$this->assertStringStartsWith( 'ai_bot_', $result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_different_bots_get_different_fingerprints(): void {
		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'wp_unslash' )->andReturnFirstArg();

		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0';
		$gpt_result = $this->limiter->fingerprint_ai_bots( 'x' );

		$_SERVER['HTTP_USER_AGENT'] = 'ClaudeBot/1.0';
		$claude_result = $this->limiter->fingerprint_ai_bots( 'x' );

		$this->assertNotEquals( $gpt_result, $claude_result );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}
}
