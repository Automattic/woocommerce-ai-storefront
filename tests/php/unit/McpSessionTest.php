<?php
/**
 * Unit tests for WC_AI_Storefront_MCP_Session.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class McpSessionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		// Reset the test-controllable settings so a `$test_settings`
		// override can't leak into other tests. Mirrors the discipline in
		// UcpNeutralCoresTest.
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Settings with `allow_unknown_ucp_agents` off and an empty allow-list.
	 *
	 * `resolve_allowed_crawlers()` returns `[]` for an explicit empty list
	 * (merchant's "block all" choice), which is the deterministic input the
	 * gate tests rely on. `is_agent_allowed( 'Other AI', [] )` still returns
	 * true because "Other AI" has no entry in UCP_AGENT_CRAWLER_MAP — so the
	 * unknown-agent deny in these tests is driven solely by the
	 * `allow_unknown_ucp_agents` flag, NOT by the crawler allow-list.
	 *
	 * @param string $allow_unknown 'yes' or 'no'.
	 * @return array<string, mixed>
	 */
	private function settings( string $allow_unknown ): array {
		return [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => $allow_unknown,
			'allowed_crawlers'         => [],
		];
	}

	public function test_gate_denies_unknown_agent_when_allow_unknown_off(): void {
		// 'gibberish' is not a known product token nor a known host, so it
		// canonicalizes to OTHER_AI_BUCKET. With allow_unknown off, the gate
		// must reject it with a 403 WP_Error.
		$result = WC_AI_Storefront_MCP_Session::gate_client_name(
			'gibberish',
			$this->settings( 'no' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'mcp_agent_unknown_blocked', $result->get_error_code() );
	}

	public function test_gate_allows_unknown_agent_when_allow_unknown_on(): void {
		// Same unknown agent, but allow_unknown on. is_agent_allowed( 'Other
		// AI', [] ) is true (no crawler-map entry), so the gate returns the
		// canonical bucket name as a plain string.
		$result = WC_AI_Storefront_MCP_Session::gate_client_name(
			'gibberish',
			$this->settings( 'yes' )
		);

		$this->assertIsString( $result );
		$this->assertSame( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET, $result );
	}

	public function test_gate_allows_known_host_agent_when_crawler_allowed(): void {
		// 'chatgpt.com' canonicalizes (via host) to 'ChatGPT'. ChatGPT maps
		// to crawler IDs ChatGPT-User / OAI-SearchBot. With OAI-SearchBot in
		// the allow-list, is_agent_allowed returns true and the gate yields
		// the canonical brand name.
		$settings                     = $this->settings( 'no' );
		$settings['allowed_crawlers'] = [ 'OAI-SearchBot' ];

		$result = WC_AI_Storefront_MCP_Session::gate_client_name( 'chatgpt.com', $settings );

		$this->assertSame( 'ChatGPT', $result );
	}

	public function test_gate_denies_known_host_agent_when_crawler_blocked(): void {
		// 'chatgpt.com' → 'ChatGPT', which IS in UCP_AGENT_CRAWLER_MAP. With
		// an empty allow-list none of its crawler IDs are present, so
		// is_agent_allowed returns false and the gate denies with a 403.
		$result = WC_AI_Storefront_MCP_Session::gate_client_name(
			'chatgpt.com',
			$this->settings( 'no' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'mcp_agent_blocked', $result->get_error_code() );
	}

	public function test_gate_denies_blank_client_name_when_allow_unknown_off(): void {
		// SECURITY: a blank/whitespace clientInfo.name canonicalizes to '',
		// which — without the empty→bucket coercion in gate_client_name —
		// would skip the unknown-agent block and be silently admitted
		// (is_agent_allowed( '', … ) is true). With allow_unknown off, every
		// blank form MUST be rejected with a 403.
		foreach ( [ '', '   ', "\t" ] as $blank ) {
			$result = WC_AI_Storefront_MCP_Session::gate_client_name(
				$blank,
				$this->settings( 'no' )
			);

			$this->assertInstanceOf( WP_Error::class, $result, 'blank name must be blocked' );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( 'mcp_agent_unknown_blocked', $result->get_error_code() );
		}
	}

	public function test_gate_treats_blank_client_name_as_unknown_when_allowed(): void {
		// With allow_unknown on, a blank name is admitted as the "Other AI"
		// bucket — consistent with any other unrecognized agent — rather than
		// as an empty-string identity.
		$result = WC_AI_Storefront_MCP_Session::gate_client_name(
			'',
			$this->settings( 'yes' )
		);

		$this->assertSame( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET, $result );
	}

	public function test_start_and_lookup_round_trip_via_transients(): void {
		$store = [];
		Functions\when( 'wp_generate_uuid4' )->justReturn( '11111111-2222-3333-4444-555555555555' );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);

		$session_id = WC_AI_Storefront_MCP_Session::start( 'ChatGPT' );

		$this->assertSame( '11111111-2222-3333-4444-555555555555', $session_id );
		$this->assertSame(
			'ChatGPT',
			WC_AI_Storefront_MCP_Session::client_name_for( $session_id )
		);
	}

	public function test_start_caps_stored_name_at_253_bytes(): void {
		// An untrusted handshake name is persisted to a transient (options
		// table); cap it so an oversized name can't bloat the DB.
		$store = [];
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'cap-test-session-id' );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);

		$session_id = WC_AI_Storefront_MCP_Session::start( str_repeat( 'a', 300 ) );

		$this->assertSame( 253, strlen( WC_AI_Storefront_MCP_Session::client_name_for( $session_id ) ) );
	}

	public function test_client_name_for_returns_null_for_empty_id(): void {
		// Empty session id must short-circuit before touching the transient
		// store.
		$this->assertNull( WC_AI_Storefront_MCP_Session::client_name_for( '' ) );
	}

	public function test_client_name_for_returns_null_for_expired_session(): void {
		// A missing/expired transient returns false; the helper must coerce
		// that to null so the caller treats it as "no session".
		Functions\when( 'get_transient' )->justReturn( false );

		$this->assertNull(
			WC_AI_Storefront_MCP_Session::client_name_for( 'does-not-exist' )
		);
	}
}
