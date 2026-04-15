<?php
/**
 * Tests for WC_AI_Syndication_Bot_Manager.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class BotManagerTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Bot_Manager $bot_manager;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->bot_manager = new WC_AI_Syndication_Bot_Manager();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// register_bot
	// ------------------------------------------------------------------

	public function test_register_bot_returns_bot_data_with_plaintext_key(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'wc_ai_syndication_bots', [] )
			->andReturn( [] );

		Functions\expect( 'wp_generate_uuid4' )
			->once()
			->andReturn( 'test-uuid-1234' );

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 32, false )
			->andReturn( 'abcdefghijklmnopqrstuvwxyz123456' );

		Functions\expect( 'sanitize_text_field' )
			->once()
			->with( 'TestBot' )
			->andReturn( 'TestBot' );

		Functions\expect( 'wp_hash_password' )
			->once()
			->andReturn( '$P$BhashedValue' );

		Functions\expect( 'update_option' )
			->once()
			->andReturn( true );

		Functions\expect( 'current_time' )
			->once()
			->andReturn( '2026-01-01 00:00:00' );

		$result = $this->bot_manager->register_bot( 'TestBot' );

		$this->assertArrayHasKey( 'bot_id', $result );
		$this->assertArrayHasKey( 'api_key', $result );
		$this->assertEquals( 'test-uuid-1234', $result['bot_id'] );
		$this->assertStringStartsWith( 'wc_ai_', $result['api_key'] );
		$this->assertEquals( 'active', $result['status'] );
	}

	public function test_register_bot_hashes_key_before_storage(): void {
		Functions\expect( 'get_option' )->andReturn( [] );
		Functions\expect( 'wp_generate_uuid4' )->andReturn( 'uuid' );
		Functions\expect( 'wp_generate_password' )->andReturn( 'randomchars1234567890123456789012' );
		Functions\expect( 'sanitize_text_field' )->andReturn( 'Bot' );
		Functions\expect( 'current_time' )->andReturn( '2026-01-01 00:00:00' );

		$stored_bots = null;
		Functions\expect( 'wp_hash_password' )
			->once()
			->andReturn( '$2y$10$hashedvalue' );

		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing( function ( $key, $bots ) use ( &$stored_bots ) {
				$stored_bots = $bots;
				return true;
			} );

		$this->bot_manager->register_bot( 'Bot' );

		// The stored hash must NOT be the plaintext key.
		$bot = reset( $stored_bots );
		$this->assertStringStartsWith( '$2y$10$', $bot['key_hash'] );
		$this->assertStringContainsString( '...', $bot['key_prefix'] );
	}

	// ------------------------------------------------------------------
	// authenticate
	// ------------------------------------------------------------------

	public function test_authenticate_returns_error_when_no_key_provided(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-AI-Agent-Key' )->andReturn( null );

		Functions\expect( '__' )->andReturnFirstArg();

		$result = $this->bot_manager->authenticate( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_missing_key', $result->get_error_code() );
	}

	public function test_authenticate_returns_error_for_invalid_key(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-AI-Agent-Key' )->andReturn( 'bad_key' );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( '__' )->andReturnFirstArg();

		Functions\expect( 'get_option' )
			->with( 'wc_ai_syndication_bots', [] )
			->andReturn( [
				'bot-1' => [
					'key_hash' => '$2y$10$somehashedvalue',
					'status'   => 'active',
				],
			] );

		Functions\expect( 'wp_check_password' )
			->once()
			->with( 'bad_key', '$2y$10$somehashedvalue' )
			->andReturn( false );

		$result = $this->bot_manager->authenticate( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_invalid_key', $result->get_error_code() );
	}

	public function test_authenticate_returns_bot_id_for_valid_key(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-AI-Agent-Key' )->andReturn( 'valid_key' );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();

		Functions\expect( 'get_option' )
			->with( 'wc_ai_syndication_bots', [] )
			->andReturn( [
				'bot-1' => [
					'key_hash' => '$2y$10$hashedvalid',
					'status'   => 'active',
				],
			] );

		Functions\expect( 'wp_check_password' )
			->with( 'valid_key', '$2y$10$hashedvalid' )
			->andReturn( true );

		// log_access internals.
		Functions\expect( 'get_transient' )->andReturn( 0 );
		Functions\expect( 'set_transient' )->andReturn( true );
		Functions\expect( 'has_action' )->andReturn( true );

		$result = $this->bot_manager->authenticate( $request );

		$this->assertEquals( 'bot-1', $result );
	}

	public function test_authenticate_rejects_revoked_bot(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-AI-Agent-Key' )->andReturn( 'revoked_key' );

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( '__' )->andReturnFirstArg();

		Functions\expect( 'get_option' )
			->with( 'wc_ai_syndication_bots', [] )
			->andReturn( [
				'bot-1' => [
					'key_hash' => '$2y$10$revokedbot',
					'status'   => 'revoked',
				],
			] );

		// wp_check_password matches, but status check fails.
		Functions\expect( 'wp_check_password' )
			->with( 'revoked_key', '$2y$10$revokedbot' )
			->andReturn( true );

		$result = $this->bot_manager->authenticate( $request );

		// Authentication fails because the bot is revoked.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_invalid_key', $result->get_error_code() );
	}

	public function test_authenticate_requires_header_only(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-AI-Agent-Key' )->andReturn( null );

		Functions\expect( '__' )->andReturnFirstArg();

		$result = $this->bot_manager->authenticate( $request );

		// No query param fallback — header-only authentication.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ai_syndication_missing_key', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// has_permission
	// ------------------------------------------------------------------

	public function test_has_permission_returns_true_for_granted_permission(): void {
		Functions\expect( 'get_option' )
			->andReturn( [
				'bot-1' => [
					'permissions' => [ 'read_products' => true, 'prepare_cart' => false ],
				],
			] );

		$this->assertTrue( $this->bot_manager->has_permission( 'bot-1', 'read_products' ) );
	}

	public function test_has_permission_returns_false_for_denied_permission(): void {
		Functions\expect( 'get_option' )
			->andReturn( [
				'bot-1' => [
					'permissions' => [ 'read_products' => true, 'prepare_cart' => false ],
				],
			] );

		$this->assertFalse( $this->bot_manager->has_permission( 'bot-1', 'prepare_cart' ) );
	}

	public function test_has_permission_returns_false_for_nonexistent_bot(): void {
		Functions\expect( 'get_option' )->andReturn( [] );

		$this->assertFalse( $this->bot_manager->has_permission( 'nonexistent', 'read_products' ) );
	}

	// ------------------------------------------------------------------
	// update_bot (validation)
	// ------------------------------------------------------------------

	public function test_update_bot_validates_status_against_allowlist(): void {
		$stored_bots = null;

		Functions\expect( 'get_option' )
			->andReturn( [
				'bot-1' => [
					'name'        => 'Original',
					'status'      => 'active',
					'permissions' => [ 'read_products' => true ],
				],
			] );

		Functions\expect( 'update_option' )
			->andReturnUsing( function ( $key, $bots ) use ( &$stored_bots ) {
				$stored_bots = $bots;
				return true;
			} );

		// Try to set an invalid status.
		$this->bot_manager->update_bot( 'bot-1', [ 'status' => 'hacked' ] );

		// Status should remain 'active', not 'hacked'.
		$this->assertEquals( 'active', $stored_bots['bot-1']['status'] );
	}

	public function test_update_bot_validates_permissions_against_known_keys(): void {
		$stored_bots = null;

		Functions\expect( 'get_option' )
			->andReturn( [
				'bot-1' => [
					'name'        => 'Bot',
					'status'      => 'active',
					'permissions' => [ 'read_products' => true, 'read_categories' => true, 'prepare_cart' => true, 'check_inventory' => true ],
				],
			] );

		Functions\expect( 'update_option' )
			->andReturnUsing( function ( $key, $bots ) use ( &$stored_bots ) {
				$stored_bots = $bots;
				return true;
			} );

		// Try to inject an unknown permission key.
		$this->bot_manager->update_bot( 'bot-1', [
			'permissions' => [
				'read_products' => true,
				'admin_access'  => true, // Should be stripped.
				'prepare_cart'  => false,
			],
		] );

		$perms = $stored_bots['bot-1']['permissions'];
		$this->assertArrayNotHasKey( 'admin_access', $perms );
		$this->assertTrue( $perms['read_products'] );
		$this->assertFalse( $perms['prepare_cart'] );
	}

	// ------------------------------------------------------------------
	// revoke_bot / delete_bot
	// ------------------------------------------------------------------

	public function test_revoke_bot_sets_status_to_revoked(): void {
		$stored_bots = null;

		Functions\expect( 'get_option' )
			->andReturn( [ 'bot-1' => [ 'status' => 'active' ] ] );

		Functions\expect( 'update_option' )
			->andReturnUsing( function ( $key, $bots ) use ( &$stored_bots ) {
				$stored_bots = $bots;
				return true;
			} );

		$result = $this->bot_manager->revoke_bot( 'bot-1' );

		$this->assertTrue( $result );
		$this->assertEquals( 'revoked', $stored_bots['bot-1']['status'] );
	}

	public function test_revoke_bot_returns_false_for_nonexistent_bot(): void {
		Functions\expect( 'get_option' )->andReturn( [] );

		$this->assertFalse( $this->bot_manager->revoke_bot( 'nonexistent' ) );
	}

	public function test_delete_bot_removes_bot_entirely(): void {
		$stored_bots = null;

		Functions\expect( 'get_option' )
			->andReturn( [ 'bot-1' => [ 'name' => 'Bot' ] ] );

		Functions\expect( 'update_option' )
			->andReturnUsing( function ( $key, $bots ) use ( &$stored_bots ) {
				$stored_bots = $bots;
				return true;
			} );

		$result = $this->bot_manager->delete_bot( 'bot-1' );

		$this->assertTrue( $result );
		$this->assertArrayNotHasKey( 'bot-1', $stored_bots );
	}
}
