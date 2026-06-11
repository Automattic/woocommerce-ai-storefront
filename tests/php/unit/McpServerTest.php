<?php
/**
 * Unit tests for WC_AI_Storefront_MCP_Server.
 *
 * Settings are driven through WC_AI_Storefront::$test_settings (the stub's
 * get_settings() lever) — NOT get_option(). See tests/php/stubs/class-wc-ai-storefront-stub.php.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class McpServerTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Shared transient store for set_transient/get_transient aliases.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Feature on + unknown agents allowed + block-all crawler list. With
		// allow_unknown on, an unrecognized client name ("Other AI") passes
		// the gate; is_agent_allowed( 'Other AI', [] ) is true (no crawler-map
		// entry), so the allowed path is deterministic.
		WC_AI_Storefront::$test_settings = [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => 'yes',
			'allowed_crawlers'         => [],
		];

		// Session transient round-trip.
		$this->transients = [];
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);

		// Origin check resolves home host. We don't send an Origin header in
		// these tests, so the check is skipped — but stub home_url anyway so
		// any incidental call is safe.
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );

		WC_AI_Storefront_Logger::reset_cache();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		WC_AI_Storefront_Logger::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a JSON-RPC POST request.
	 *
	 * @param array                 $rpc     JSON-RPC envelope.
	 * @param array<string, string> $headers Header name => value.
	 * @return WP_REST_Request
	 */
	private function rpc_request( array $rpc, array $headers = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/mcp' );
		$request->set_body( wp_json_encode( $rpc ) );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	public function test_register_routes_registers_mcp_route(): void {
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = [
					'namespace' => $namespace,
					'args'      => $args,
				];
				return true;
			}
		);

		( new WC_AI_Storefront_MCP_Server() )->register_routes();

		$this->assertArrayHasKey( '/mcp', $registered );
		$this->assertSame( 'wc/ucp/v1', $registered['/mcp']['namespace'] );

		// CORS support is wired so browser agents can read/send the MCP headers.
		$this->assertNotFalse(
			has_filter( 'rest_exposed_cors_headers' ),
			'Mcp-Session-Id must be exposed via CORS'
		);
		$this->assertNotFalse(
			has_filter( 'rest_allowed_cors_headers' ),
			'MCP request headers must be allowed via CORS'
		);
	}

	public function test_initialize_returns_session_header_and_server_info(): void {
		$request  = $this->rpc_request(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
			]
		);
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 200, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Mcp-Session-Id', $headers );
		$this->assertNotSame( '', $headers['Mcp-Session-Id'] );

		$data = $response->get_data();
		$this->assertSame( 'dev.ucp.shopping', $data['result']['serverInfo']['name'] );
	}

	public function test_tools_list_works_without_session(): void {
		// Discovery is session-free: a first-contact / stateless client can list
		// tools without establishing a session.
		$request  = $this->rpc_request(
			[
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
			],
			[ 'MCP-Protocol-Version' => '2025-06-18' ]
		);
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $response->get_data()['result']['tools'] );
	}

	public function test_tools_list_ignores_session_state(): void {
		// tools/list is ungated discovery — even an unknown session id is
		// ignored and the full tool list is returned.
		$request  = $this->rpc_request(
			[
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/list',
			],
			[
				'MCP-Protocol-Version' => '2025-06-18',
				'Mcp-Session-Id'       => 'no-such-session',
			]
		);
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $response->get_data()['result']['tools'] );
	}

	public function test_tools_list_with_valid_session_returns_tool_list(): void {
		// Mint a session via initialize, then reuse its id on tools/list.
		$server  = new WC_AI_Storefront_MCP_Server();
		$init     = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);
		$session  = $init->get_headers()['Mcp-Session-Id'];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 4,
					'method'  => 'tools/list',
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 3, $data['result']['tools'] );
	}

	public function test_feature_disabled_returns_404(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$request  = $this->rpc_request(
			[
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'initialize',
				'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
			]
		);
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_mcp_disabled_returns_404(): void {
		// enabled but mcp_enabled off → 404 (feature gate is the conjunction).
		WC_AI_Storefront::$test_settings = [
			'enabled'     => 'yes',
			'mcp_enabled' => 'no',
		];

		$request  = $this->rpc_request(
			[
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'initialize',
				'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
			]
		);
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_parse_error_returns_400(): void {
		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/mcp' );
		$request->set_body( 'not json at all' );

		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32700, $response->get_data()['error']['code'] );
	}

	public function test_get_handler_returns_405(): void {
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle_get();
		$this->assertSame( 405, $response->get_status() );
		// Body carries a human-readable hint (not blank) for browser visitors.
		$this->assertNotEmpty( $response->get_data()['error'] ?? '' );
	}

	public function test_invalid_request_without_method_returns_32600(): void {
		// Valid JSON, but not a well-formed JSON-RPC request (no `method`) —
		// distinct from an unparseable body (which is -32700).
		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/mcp' );
		$request->set_body( '{"jsonrpc":"2.0","id":1}' );

		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32600, $response->get_data()['error']['code'] );
	}

	public function test_non_string_method_returns_32600_without_warning(): void {
		// A non-string `method` (here an array) must be rejected as an invalid
		// request (-32600) rather than coerced via `(string)` — which would
		// emit an "Array to string conversion" PHP warning on a public,
		// unauthenticated endpoint (log spam). Promote warnings to exceptions
		// for this test so an accidental cast would fail loudly.
		$prev = set_error_handler(
			static function ( $errno, $errstr ) {
				throw new \RuntimeException( $errstr, $errno );
			},
			E_WARNING
		);

		try {
			$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/mcp' );
			$request->set_body( '{"jsonrpc":"2.0","id":1,"method":["x"]}' );

			$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( -32600, $response->get_data()['error']['code'] );
		} finally {
			if ( null !== $prev ) {
				set_error_handler( $prev );
			} else {
				restore_error_handler();
			}
		}
	}

	public function test_regate_403_when_allow_list_tightened_mid_session(): void {
		// Mint a session while unknown agents are allowed.
		$server  = new WC_AI_Storefront_MCP_Server();
		$init    = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);
		$session = $init->get_headers()['Mcp-Session-Id'];

		// Merchant tightens the allow-list AFTER the session was minted.
		WC_AI_Storefront::$test_settings = [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => 'no',
			'allowed_crawlers'         => [],
		];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'catalog_search', 'arguments' => [ 'query' => 'x' ] ],
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		// The per-call re-gate denies the previously-vetted session on tools/call
		// (discovery via tools/list is ungated; invoking a tool is not).
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_rate_limited_request_returns_429(): void {
		// Mint a valid session (initialize is not rate-limited).
		$server  = new WC_AI_Storefront_MCP_Server();
		$init    = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);
		$session = $init->get_headers()['Mcp-Session-Id'];

		// Force the outer rate limiter over budget: return a high count for any
		// rate-limit transient (prefix wc_ai_ucp_rl_), while still serving the
		// session transient. Key-agnostic, so we don't reproduce the UA/IP
		// fingerprint the limiter computes internally.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( str_starts_with( (string) $key, 'wc_ai_ucp_rl_' ) ) {
					return 999;
				}
				return $this->transients[ $key ] ?? false;
			}
		);

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 429, $response->get_status() );
	}

	public function test_tools_call_wraps_core_error_as_iserror(): void {
		// Full tools/call through the server: session validation → re-gate →
		// rate limit → dispatch → MCP_Tools::call → run_checkout_create →
		// core_result_to_mcp → rpc_result wrapping. checkout_create with empty
		// line_items is rejected with a 400 BEFORE any WooCommerce/Store-API
		// work, so no heavy stubbing is needed. The SUCCESS branch (status<400
		// → structuredContent) is unit-tested in McpToolsTest and live-tested
		// via the Task 3.8 smoke test against a running store.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$server  = new WC_AI_Storefront_MCP_Server();
		$init    = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);
		$session = $init->get_headers()['Mcp-Session-Id'];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 7,
					'method'  => 'tools/call',
					'params'  => [
						'name'      => 'checkout_create',
						'arguments' => [ 'line_items' => [] ],
					],
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$result = $response->get_data()['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertNotEmpty( $result['content'][0]['text'] );
	}

	public function test_tools_call_unknown_tool_returns_jsonrpc_error(): void {
		$server  = new WC_AI_Storefront_MCP_Server();
		$init    = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);
		$session = $init->get_headers()['Mcp-Session-Id'];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 8,
					'method'  => 'tools/call',
					'params'  => [
						'name'      => 'gibberish_tool',
						'arguments' => [],
					],
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( -32602, $response->get_data()['error']['code'] );
	}

	public function test_initialize_is_rate_limited(): void {
		// SECURITY: initialize must be rate-limited too — otherwise an
		// unauthenticated caller floods it and amplifies session-transient
		// writes. Force the outer limiter over budget for any rate-limit key.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( str_starts_with( (string) $key, 'wc_ai_ucp_rl_' ) ) {
					return 999;
				}
				return $this->transients[ $key ] ?? false;
			}
		);

		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);

		$this->assertSame( 429, $response->get_status() );
	}

	public function test_initialize_blank_client_name_blocked_when_unknown_disallowed(): void {
		// SECURITY: a blank clientInfo.name must not bypass the merchant's
		// "block unknown agents" gate.
		WC_AI_Storefront::$test_settings = [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => 'no',
			'allowed_crawlers'         => [],
		];

		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => '' ] ],
				]
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_rate_limit_applies_to_malformed_requests(): void {
		// Malformed JSON must be throttled too — the limiter runs BEFORE the
		// parse early-return, so a flood of garbage can't bypass it.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( str_starts_with( (string) $key, 'wc_ai_ucp_rl_' ) ) {
					return 999;
				}
				return $this->transients[ $key ] ?? false;
			}
		);

		$request = new WP_REST_Request( 'POST', '/wc/ucp/v1/mcp' );
		$request->set_body( 'not json at all' );
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle( $request );

		$this->assertSame( 429, $response->get_status() );
	}

	public function test_non_array_client_info_does_not_warn(): void {
		// A client may send clientInfo as a non-object; extracting name must
		// not warn on illegal array access. With unknown agents allowed
		// (setUp default), the empty name resolves to the "Other AI" bucket
		// and is admitted.
		set_error_handler(
			static function ( $errno, $errstr ) {
				throw new \RuntimeException( 'PHP warning: ' . $errstr );
			},
			E_WARNING
		);
		try {
			$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
				$this->rpc_request(
					[
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'initialize',
						'params'  => [ 'clientInfo' => 'not-an-object' ],
					]
				)
			);
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_catalog_lookup_missing_ids_distinct_from_empty(): void {
		// The adapter passes null (not []) for a missing ids argument so the
		// core's "missing array" error stays distinct from "empty array".
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$server  = new WC_AI_Storefront_MCP_Server();
		$session = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		)->get_headers()['Mcp-Session-Id'];

		$call = function ( array $arguments ) use ( $server, $session ) {
			return $server->handle(
				$this->rpc_request(
					[
						'jsonrpc' => '2.0',
						'id'      => 2,
						'method'  => 'tools/call',
						'params'  => [ 'name' => 'catalog_lookup', 'arguments' => $arguments ],
					],
					[
						'MCP-Protocol-Version' => '2025-06-18',
						'Mcp-Session-Id'       => $session,
					]
				)
			)->get_data()['result'];
		};

		$missing = $call( [] );
		$empty   = $call( [ 'ids' => [] ] );

		$this->assertTrue( $missing['isError'] );
		$this->assertTrue( $empty['isError'] );
		$this->assertNotSame(
			$missing['content'][0]['text'],
			$empty['content'][0]['text'],
			'missing-ids and empty-ids must yield distinct error messages'
		);
	}

	public function test_notification_without_id_acks_202_while_request_gets_result(): void {
		// A request WITHOUT an `id` member is a JSON-RPC notification → 202
		// ack with no body; the same method WITH an `id` returns a normal
		// JSON-RPC result.
		$server  = new WC_AI_Storefront_MCP_Server();
		$session = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		)->get_headers()['Mcp-Session-Id'];

		$headers = [
			'MCP-Protocol-Version' => '2025-06-18',
			'Mcp-Session-Id'       => $session,
		];

		$notification = $server->handle(
			$this->rpc_request( [ 'jsonrpc' => '2.0', 'method' => 'ping' ], $headers )
		);
		$this->assertSame( 202, $notification->get_status() );
		$this->assertNull( $notification->get_data() );

		$request = $server->handle(
			$this->rpc_request( [ 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping' ], $headers )
		);
		$this->assertSame( 200, $request->get_status() );
		$this->assertArrayHasKey( 'result', $request->get_data() );
	}

	public function test_tools_call_without_session_allowed_when_unknown_agents_allowed(): void {
		// Sessions are optional: with allow_unknown_ucp_agents on (setUp default),
		// a sessionless tools/call is admitted as the anonymous agent and the
		// tool runs (here checkout_create with empty line_items → a 400 business
		// error surfaced as isError, proving the caller passed the gate).
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'checkout_create', 'arguments' => [ 'line_items' => [] ] ],
				],
				[ 'MCP-Protocol-Version' => '2025-06-18' ]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['result']['isError'] );
	}

	public function test_tools_call_without_session_blocked_when_unknown_agents_disallowed(): void {
		// With allow_unknown_ucp_agents off, an anonymous (sessionless) caller is
		// blocked at the gate → 403, before any tool runs.
		WC_AI_Storefront::$test_settings = [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => 'no',
			'allowed_crawlers'         => [],
		];

		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'catalog_search', 'arguments' => [ 'query' => 'x' ] ],
				],
				[ 'MCP-Protocol-Version' => '2025-06-18' ]
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_tools_call_with_unknown_session_returns_404(): void {
		// A tools/call carrying an unknown/expired session id is told to
		// re-initialize (404) — distinct from the anonymous (no-header) path.
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'catalog_search', 'arguments' => [ 'query' => 'x' ] ],
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => 'no-such-session',
				]
			)
		);

		$this->assertSame( 404, $response->get_status() );
		// Must carry a JSON-RPC error envelope so the agent can distinguish
		// session-expired (-32001) from agent-blocked (-32000).
		$this->assertSame( -32001, $response->get_data()['error']['code'] );
	}

	public function test_tools_call_blocked_agent_returns_jsonrpc_error_envelope(): void {
		// When the per-call re-gate blocks a previously-vetted session (allow-
		// list tightened), the response must be a JSON-RPC error envelope so the
		// agent knows it is blocked (-32000) vs. session-expired (-32001).
		$server  = new WC_AI_Storefront_MCP_Server();
		$session = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		)->get_headers()['Mcp-Session-Id'];

		WC_AI_Storefront::$test_settings = [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => 'no',
			'allowed_crawlers'         => [],
		];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'catalog_search', 'arguments' => [ 'query' => 'x' ] ],
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( -32000, $response->get_data()['error']['code'] );
	}

	public function test_initialize_gate_rejection_returns_jsonrpc_error_envelope(): void {
		// When initialize is blocked, it must return a JSON-RPC error envelope
		// (not bare null), so the agent sees -32000 rather than a blank 403 body.
		WC_AI_Storefront::$test_settings = [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => 'no',
			'allowed_crawlers'         => [],
		];

		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( -32000, $response->get_data()['error']['code'] );
	}

	public function test_unsupported_protocol_version_returns_jsonrpc_error(): void {
		// A post-handshake request with an unrecognized MCP-Protocol-Version
		// must return a JSON-RPC error envelope (-32600 Invalid Request) so the
		// agent gets a parseable response, not a blank 400 body.
		$server  = new WC_AI_Storefront_MCP_Server();
		$session = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		)->get_headers()['Mcp-Session-Id'];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'ping',
				],
				[
					'MCP-Protocol-Version' => 'gibberish-version',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32600, $response->get_data()['error']['code'] );
	}

	public function test_explicit_fallback_protocol_version_is_accepted(): void {
		// Clients that explicitly send '2025-03-26' (the backward-compat version)
		// must be admitted — it is in SUPPORTED even though LATEST is 2025-06-18.
		$server  = new WC_AI_Storefront_MCP_Server();
		$session = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		)->get_headers()['Mcp-Session-Id'];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'ping',
				],
				[
					'MCP-Protocol-Version' => '2025-03-26',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $response->get_data() );
	}

	public function test_tools_list_still_succeeds_after_allow_list_tightens(): void {
		// tools/list is ungated discovery — even after a merchant tightens the
		// allow-list, an existing session (or no session) must still get the
		// tool list. Only tools/call triggers the per-call re-gate.
		$server  = new WC_AI_Storefront_MCP_Server();
		$session = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		)->get_headers()['Mcp-Session-Id'];

		WC_AI_Storefront::$test_settings = [
			'enabled'                  => 'yes',
			'mcp_enabled'              => 'yes',
			'allow_unknown_ucp_agents' => 'no',
			'allowed_crawlers'         => [],
		];

		$response = $server->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
				],
				[
					'MCP-Protocol-Version' => '2025-06-18',
					'Mcp-Session-Id'       => $session,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $response->get_data()['result']['tools'] );
	}

	public function test_initialize_echoes_supported_requested_protocol_version(): void {
		// Per the MCP lifecycle spec, when the client requests a protocol version
		// the server supports, the server MUST echo that same version — not blindly
		// return its own latest.
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [
						'protocolVersion' => '2025-03-26',
						'clientInfo'      => [ 'name' => 'gibberish-agent' ],
					],
				]
			)
		);

		$this->assertSame( '2025-03-26', $response->get_data()['result']['protocolVersion'] );
	}

	public function test_initialize_falls_back_to_latest_for_unsupported_version(): void {
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [
						'protocolVersion' => '1999-01-01',
						'clientInfo'      => [ 'name' => 'gibberish-agent' ],
					],
				]
			)
		);

		$this->assertSame( '2025-06-18', $response->get_data()['result']['protocolVersion'] );
	}

	public function test_initialize_defaults_to_latest_when_version_omitted(): void {
		$response = ( new WC_AI_Storefront_MCP_Server() )->handle(
			$this->rpc_request(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => [ 'clientInfo' => [ 'name' => 'gibberish-agent' ] ],
				]
			)
		);

		$this->assertSame( '2025-06-18', $response->get_data()['result']['protocolVersion'] );
	}
}
