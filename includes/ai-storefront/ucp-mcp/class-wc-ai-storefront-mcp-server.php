<?php
/**
 * MCP transport: Streamable-HTTP JSON-RPC server for UCP shopping tools.
 *
 * MIGRATION: this hand-rolled transport is intentionally minimal. The eventual
 * plan is to replace it with the official `wordpress/mcp-adapter` library
 * (WordPress Abilities API + MCP Adapter — the same stack WooCommerce core's
 * `mcp_integration` uses) once that library reaches 1.0 and the MCP-engagement
 * hypothesis is validated. The transport-neutral `run_*` cores on
 * WC_AI_Storefront_UCP_REST_Controller are the stable seam that keeps that
 * migration cheap: swap this server for a custom mcp-adapter server whose
 * tools wrap the same cores and whose transport permission_callback runs the
 * same UCP allow-list gate. Do not grow UCP business logic in here.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Streamable-HTTP JSON-RPC MCP server.
 *
 * Registers POST/GET at `wc/ucp/v1/mcp`, runs the feature gate + origin +
 * protocol + session + agent + rate-limit checks, then dispatches the
 * standard MCP methods (initialize / notifications/initialized / ping /
 * tools/list / tools/call) onto WC_AI_Storefront_MCP_Tools.
 */
class WC_AI_Storefront_MCP_Server {

	/**
	 * Protocol version advertised by `initialize`.
	 */
	const LATEST_PROTOCOL = '2025-06-18';

	/**
	 * Protocol version assumed when a post-handshake request omits the
	 * `MCP-Protocol-Version` header (spec backward-compat default).
	 */
	const FALLBACK_PROTOCOL = '2025-03-26';

	/**
	 * Protocol versions this server accepts on post-handshake requests.
	 */
	const SUPPORTED = array( '2025-06-18', '2025-03-26' );

	/**
	 * Register the MCP endpoint.
	 *
	 * Transport-level auth is open (`__return_true`): the real gate is the
	 * per-method session + UCP allow-list logic inside `handle()`, which can
	 * return UCP-shaped errors rather than a bare WP REST 401.
	 */
	public function register_routes(): void {
		$this->add_cors_support();

		register_rest_route(
			'wc/ucp/v1',
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Expose/allow the MCP HTTP headers in WordPress's REST CORS responses.
	 *
	 * This is a PUBLIC endpoint reached cross-origin by agent web UIs. WP's
	 * default REST CORS already echoes the request Origin (so any origin is
	 * permitted), but it does NOT expose the `Mcp-Session-Id` response header
	 * nor allow the `Mcp-Session-Id` / `MCP-Protocol-Version` request headers —
	 * without which a browser client cannot read the session id from the
	 * `initialize` response or send it (and the protocol version) back on
	 * subsequent requests. These filters are global to the REST API but only
	 * matter to MCP traffic; other routes ignore the extra header names.
	 */
	private function add_cors_support(): void {
		add_filter(
			'rest_exposed_cors_headers',
			static function ( $headers ) {
				$headers[] = 'Mcp-Session-Id';
				return $headers;
			}
		);
		add_filter(
			'rest_allowed_cors_headers',
			static function ( $headers ) {
				$headers[] = 'Mcp-Session-Id';
				$headers[] = 'MCP-Protocol-Version';
				return $headers;
			}
		);
	}

	/**
	 * GET is not supported (no server-initiated SSE stream in this minimal
	 * transport). Respond 405 so MCP clients fall back to POST-only — but with a
	 * short human-readable hint in the body, so a person who opens the URL in a
	 * browser sees what this endpoint is instead of a blank 405 page.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error' => __(
					'This is an MCP (Model Context Protocol) JSON-RPC endpoint. Send a POST request with a JSON-RPC body, e.g. {"jsonrpc":"2.0","id":1,"method":"initialize"}. GET is reserved for SSE streams, which this endpoint does not provide.',
					'woocommerce-ai-storefront'
				),
			),
			405
		);
	}

	/**
	 * Handle a JSON-RPC POST.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) || 'yes' !== ( $settings['mcp_enabled'] ?? 'no' ) ) {
			return new WP_REST_Response( null, 404 );
		}

		// No Origin-based 403. This is a PUBLIC endpoint reached cross-origin by
		// agent web UIs; the exact-host DNS-rebinding defense is a localhost
		// concern (per the MCP spec security note) and would block legitimate
		// cross-origin browser agents. Access is governed by the UCP allow-list
		// gate (below) and CORS (see add_cors_support()), not the Origin header.

		// Rate-limit EVERY request — BEFORE JSON parsing — so a caller can't
		// bypass throttling by flooding malformed JSON / invalid JSON-RPC
		// envelopes (whose parse / invalid-request early returns happen just
		// below). check_outer_rate_limit() no-ops when the feature is off and
		// keys on UA+IP, so even unparseable or unsessioned requests spend the
		// caller's per-minute budget. `initialize` is covered too — each call
		// mints a short-TTL session transient, which this throttles.
		$rate_limit = WC_AI_Storefront_Store_Api_Rate_Limiter::check_outer_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return new WP_REST_Response( null, 429 );
		}

		$rpc = json_decode( (string) $request->get_body(), true );
		if ( null === $rpc && JSON_ERROR_NONE !== json_last_error() ) {
			// Body was not parseable JSON at all.
			return $this->rpc_error( null, -32700, 'Parse error', 400 );
		}
		if ( ! is_array( $rpc ) || ! isset( $rpc['method'] ) || ! is_string( $rpc['method'] ) ) {
			// Parsed fine but isn't a well-formed JSON-RPC request (e.g. a bare
			// value, an object with no `method`, or a non-string `method` such
			// as an array — the latter would trigger an "Array to string
			// conversion" warning on the (string) cast below).
			return $this->rpc_error( null, -32600, 'Invalid Request', 400 );
		}
		$id     = $rpc['id'] ?? null;
		$method = (string) $rpc['method'];
		$params = is_array( $rpc['params'] ?? null ) ? $rpc['params'] : array();

		// A JSON-RPC request without an `id` MEMBER is a notification (the
		// absence of the key, not `id: null`). Notifications receive no
		// response body — only an HTTP 202 ack — per JSON-RPC 2.0 and MCP
		// Streamable HTTP. Enforced after the gate chain below, so an
		// unaccepted notification still returns the appropriate HTTP error.
		$is_notification = ! array_key_exists( 'id', $rpc );

		if ( 'initialize' === $method ) {
			return $this->do_initialize( $id, $params, $settings );
		}

		$version = (string) $request->get_header( 'mcp-protocol-version' );
		if ( '' === $version ) {
			$version = self::FALLBACK_PROTOCOL;
		}
		if ( ! in_array( $version, self::SUPPORTED, true ) ) {
			return $this->rpc_error(
				$id,
				-32600,
				sprintf(
					/* translators: 1: unsupported MCP protocol version, 2: comma-separated list of supported versions. */
					__( 'Unsupported MCP-Protocol-Version: %1$s. Supported: %2$s.', 'woocommerce-ai-storefront' ),
					$version,
					implode( ', ', self::SUPPORTED )
				),
				400
			);
		}

		// Accepted notification (no `id`) → 202 ack, no body (e.g.
		// notifications/initialized, or any method sent without an id).
		if ( $is_notification ) {
			return new WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'notifications/initialized':
				return new WP_REST_Response( null, 202 );

			case 'ping':
				// Liveness — session-free.
				return $this->rpc_result( $id, (object) array() );

			case 'tools/list':
				// Public discovery — session-free and ungated. The tool list is
				// public API surface (also advertised via the UCP manifest and
				// llms.txt), so first-contact and stateless clients can discover
				// the tools before establishing identity. Invoking a tool (below)
				// still requires passing the agent gate.
				return $this->rpc_result( $id, array( 'tools' => WC_AI_Storefront_MCP_Tools::definitions() ) );

			case 'tools/call':
				// Identity IS required to invoke a tool. Sessions are OPTIONAL: a
				// valid Mcp-Session-Id supplies the vetted identity; without one
				// the caller is anonymous, admitted only if the merchant allows
				// unknown agents (allow_unknown_ucp_agents).
				$caller = $this->resolve_caller( $request, $settings );
				if ( is_wp_error( $caller ) ) {
					$http = (int) ( $caller->get_error_data()['status'] ?? 403 );
					// Map HTTP status to an application-defined JSON-RPC code so
					// agents can distinguish session-expired (re-initialize) from
					// agent-blocked (permanent). -32001 = session error; -32000 = blocked.
					$rpc_code = 404 === $http ? -32001 : -32000;
					return $this->rpc_error( $id, $rpc_code, $caller->get_error_message(), $http );
				}
				// A non-string `name` coerces to '' → unknown tool → -32602.
				$name = is_string( $params['name'] ?? null ) ? $params['name'] : '';
				$args = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
				WC_AI_Storefront_Logger::debug( 'MCP tools/call: tool=%s client=%s', $name, $caller );
				$result = WC_AI_Storefront_MCP_Tools::call( $name, $args, $caller );
				if ( is_wp_error( $result ) ) {
					return $this->rpc_error( $id, -32602, $result->get_error_message(), 200 );
				}
				return $this->rpc_result( $id, $result );

			default:
				return $this->rpc_error( $id, -32601, 'Method not found', 200 );
		}
	}

	/**
	 * Handle the `initialize` handshake: gate the client name, mint a
	 * session, and return server capabilities + the Mcp-Session-Id header.
	 *
	 * @param mixed $id       JSON-RPC request id.
	 * @param array $params   JSON-RPC params (clientInfo, etc.).
	 * @param array $settings Resolved plugin settings.
	 * @return WP_REST_Response
	 */
	private function do_initialize( $id, array $params, array $settings ): WP_REST_Response {
		// Extract clientInfo.name defensively: a client may send `clientInfo`
		// as a non-object (e.g. a string), which would warn on array access.
		$client_info = is_array( $params['clientInfo'] ?? null ) ? $params['clientInfo'] : array();
		$client_name = is_string( $client_info['name'] ?? null ) ? $client_info['name'] : '';
		$gated       = WC_AI_Storefront_MCP_Session::gate_client_name( $client_name, $settings );
		if ( is_wp_error( $gated ) ) {
			WC_AI_Storefront_Logger::debug(
				'MCP initialize blocked: %s — client: %s',
				$gated->get_error_code(),
				$client_name
			);
			return $this->rpc_error( $id, -32000, $gated->get_error_message(), 403 );
		}
		WC_AI_Storefront_Logger::debug( 'MCP initialize: client=%s', $client_name );

		// Echo the client's requested protocol version when we support it; only
		// fall back to our latest when the request is absent or unsupported. Per
		// the MCP lifecycle spec the server MUST respond with the same version
		// the client asked for if it can — blindly returning LATEST can make a
		// strict 2025-03-26 client disconnect on a version it never requested.
		$requested = is_string( $params['protocolVersion'] ?? null ) ? $params['protocolVersion'] : '';
		$protocol  = in_array( $requested, self::SUPPORTED, true ) ? $requested : self::LATEST_PROTOCOL;

		// Store the RAW handshake name (not the canonical $gated) so the
		// original agent identity is preserved for attribution. The server
		// re-canonicalizes it via gate_client_name() on every post-handshake
		// request, so the allow/deny decision is unaffected.
		$session_id = WC_AI_Storefront_MCP_Session::start( $client_name );
		$response   = $this->rpc_result(
			$id,
			array(
				'protocolVersion' => $protocol,
				'capabilities'    => array( 'tools' => (object) array() ),
				'serverInfo'      => array(
					'name'    => 'dev.ucp.shopping',
					'version' => defined( 'WC_AI_STOREFRONT_VERSION' ) ? WC_AI_STOREFRONT_VERSION : '0',
				),
			)
		);
		$response->header( 'Mcp-Session-Id', $session_id );
		return $response;
	}

	/**
	 * Resolve the calling agent's identity for a gated request (tools/call).
	 *
	 * Sessions are OPTIONAL. A valid Mcp-Session-Id supplies the identity the
	 * agent established at `initialize` (re-gated here in case the allow-list
	 * tightened since). Without a session the caller is anonymous and is
	 * admitted only when the merchant allows unknown agents.
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param array           $settings Resolved plugin settings.
	 * @return string|WP_Error Agent name to attribute the call to ('' for an
	 *                         allowed anonymous caller); WP_Error carrying the
	 *                         HTTP status (404 unknown/expired session, 403
	 *                         blocked agent) otherwise.
	 */
	private function resolve_caller( WP_REST_Request $request, array $settings ) {
		$session_id = (string) $request->get_header( 'mcp-session-id' );

		if ( '' !== $session_id ) {
			$client_name = WC_AI_Storefront_MCP_Session::client_name_for( $session_id );
			if ( null === $client_name ) {
				WC_AI_Storefront_Logger::debug( 'MCP resolve_caller: unknown/expired session=%s', $session_id );
				return new WP_Error( 'mcp_session_unknown', __( 'Unknown or expired session.', 'woocommerce-ai-storefront' ), array( 'status' => 404 ) );
			}
			// Re-gate the stored identity (the allow-list may have tightened).
			if ( is_wp_error( WC_AI_Storefront_MCP_Session::gate_client_name( $client_name, $settings ) ) ) {
				WC_AI_Storefront_Logger::debug( 'MCP resolve_caller: re-gate blocked client=%s', $client_name );
				return new WP_Error( 'mcp_agent_blocked', __( 'Agent is not allowed.', 'woocommerce-ai-storefront' ), array( 'status' => 403 ) );
			}
			return $client_name;
		}

		// No session → anonymous caller. Admitted only if unknown agents are
		// allowed; attribute the call to the anonymous fallback ('').
		if ( is_wp_error( WC_AI_Storefront_MCP_Session::gate_client_name( '', $settings ) ) ) {
			return new WP_Error( 'mcp_agent_blocked', __( 'Anonymous agents are not allowed.', 'woocommerce-ai-storefront' ), array( 'status' => 403 ) );
		}
		return '';
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @param mixed $id     JSON-RPC request id.
	 * @param mixed $result Result payload.
	 * @return WP_REST_Response
	 */
	private function rpc_result( $id, $result ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param mixed  $id      JSON-RPC request id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status to attach.
	 * @return WP_REST_Response
	 */
	private function rpc_error( $id, int $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}
}
