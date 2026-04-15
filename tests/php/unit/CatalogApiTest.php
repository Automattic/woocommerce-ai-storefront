<?php
/**
 * Tests for WC_AI_Syndication_Catalog_Api.
 *
 * Covers the permission checking, route-to-permission mapping,
 * and Retry-After header logic.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class CatalogApiTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Catalog_Api $api;
	private $bot_manager;
	private $rate_limiter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->bot_manager  = \Mockery::mock( WC_AI_Syndication_Bot_Manager::class );
		$this->rate_limiter = \Mockery::mock( WC_AI_Syndication_Rate_Limiter::class );
		$this->api          = new WC_AI_Syndication_Catalog_Api( $this->bot_manager, $this->rate_limiter );

		// Reset settings cache.
		WC_AI_Syndication::$test_settings = [];
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// check_agent_permission
	// ------------------------------------------------------------------

	public function test_returns_error_when_syndication_disabled(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'no' ];

		Functions\expect( '__' )->andReturnFirstArg();

		$request = new WP_REST_Request();
		$result  = $this->api->check_agent_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_disabled', $result->get_error_code() );
	}

	public function test_returns_auth_error_when_key_missing(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];

		$auth_error = new WP_Error( 'ai_syndication_missing_key', 'Missing key', [ 'status' => 401 ] );
		$this->bot_manager->shouldReceive( 'authenticate' )->once()->andReturn( $auth_error );

		$request = new WP_REST_Request();
		$result  = $this->api->check_agent_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_missing_key', $result->get_error_code() );
	}

	public function test_returns_rate_limit_error(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];

		$this->bot_manager->shouldReceive( 'authenticate' )->andReturn( 'bot-1' );

		$rate_error = new WP_Error( 'ai_syndication_rate_limited', 'Rate limited', [ 'status' => 429, 'retry_after' => 30 ] );
		$this->rate_limiter->shouldReceive( 'check' )->with( 'bot-1' )->andReturn( $rate_error );

		$request = new WP_REST_Request();
		$result  = $this->api->check_agent_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_rate_limited', $result->get_error_code() );
	}

	public function test_returns_permission_denied_when_bot_lacks_route_permission(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];

		$this->bot_manager->shouldReceive( 'authenticate' )->andReturn( 'bot-1' );
		$this->rate_limiter->shouldReceive( 'check' )->andReturn( true );
		$this->bot_manager->shouldReceive( 'has_permission' )
			->with( 'bot-1', 'read_products' )
			->andReturn( false );

		Functions\expect( '__' )->andReturnFirstArg();

		$request = new WP_REST_Request();
		$request->set_route( '/wc/v3/ai-syndication/products' );
		$result = $this->api->check_agent_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_permission_denied', $result->get_error_code() );
	}

	public function test_returns_true_when_all_checks_pass(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];

		$this->bot_manager->shouldReceive( 'authenticate' )->andReturn( 'bot-1' );
		$this->rate_limiter->shouldReceive( 'check' )->andReturn( true );
		$this->bot_manager->shouldReceive( 'has_permission' )
			->with( 'bot-1', 'read_products' )
			->andReturn( true );

		$request = new WP_REST_Request();
		$request->set_route( '/wc/v3/ai-syndication/products' );
		$result = $this->api->check_agent_permission( $request );

		$this->assertTrue( $result );
		$this->assertEquals( 'bot-1', $request->get_param( '_ai_bot_id' ) );
	}

	public function test_store_route_requires_no_specific_permission(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];

		$this->bot_manager->shouldReceive( 'authenticate' )->andReturn( 'bot-1' );
		$this->rate_limiter->shouldReceive( 'check' )->andReturn( true );
		// has_permission should NOT be called for /store.

		$request = new WP_REST_Request();
		$request->set_route( '/wc/v3/ai-syndication/store' );
		$result = $this->api->check_agent_permission( $request );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Route-to-permission mapping
	// ------------------------------------------------------------------

	public function test_products_route_requires_read_products(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];
		$this->bot_manager->shouldReceive( 'authenticate' )->andReturn( 'bot-1' );
		$this->rate_limiter->shouldReceive( 'check' )->andReturn( true );
		$this->bot_manager->shouldReceive( 'has_permission' )
			->with( 'bot-1', 'read_products' )
			->once()
			->andReturn( true );

		$request = new WP_REST_Request();
		$request->set_route( '/wc/v3/ai-syndication/products/123' );
		$this->api->check_agent_permission( $request );
	}

	public function test_categories_route_requires_read_categories(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];
		$this->bot_manager->shouldReceive( 'authenticate' )->andReturn( 'bot-1' );
		$this->rate_limiter->shouldReceive( 'check' )->andReturn( true );
		$this->bot_manager->shouldReceive( 'has_permission' )
			->with( 'bot-1', 'read_categories' )
			->once()
			->andReturn( true );

		$request = new WP_REST_Request();
		$request->set_route( '/wc/v3/ai-syndication/categories' );
		$this->api->check_agent_permission( $request );
	}

	public function test_cart_prepare_route_requires_prepare_cart(): void {
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'yes' ];
		$this->bot_manager->shouldReceive( 'authenticate' )->andReturn( 'bot-1' );
		$this->rate_limiter->shouldReceive( 'check' )->andReturn( true );
		$this->bot_manager->shouldReceive( 'has_permission' )
			->with( 'bot-1', 'prepare_cart' )
			->once()
			->andReturn( true );

		$request = new WP_REST_Request();
		$request->set_route( '/wc/v3/ai-syndication/cart/prepare' );
		$this->api->check_agent_permission( $request );
	}
}
