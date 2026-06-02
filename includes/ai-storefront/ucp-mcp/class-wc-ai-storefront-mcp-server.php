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
	const SUPPORTED = [ '2025-06-18', '2025-03-26' ];

	/**
	 * Register the MCP endpoint.
	 *
	 * Transport-level auth is open (`__return_true`): the real gate is the
	 * per-method session + UCP allow-list logic inside `handle()`, which can
	 * return UCP-shaped errors rather than a bare WP REST 401.
	 */
	public function register_routes(): void {
		register_rest_route(
			'wc/ucp/v1',
			'/mcp',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handle' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'handle_get' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * GET is not supported (no server-initiated SSE stream in this minimal
	 * transport). Respond 405 so clients fall back to POST-only.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get(): WP_REST_Response {
		return new WP_REST_Response( null, 405 );
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

		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && ! $this->origin_matches_site( $origin ) ) {
			return new WP_REST_Response( null, 403 );
		}

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
		$params = is_array( $rpc['params'] ?? null ) ? $rpc['params'] : [];

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
			return new WP_REST_Response( null, 400 );
		}

		$session_id = (string) $request->get_header( 'mcp-session-id' );
		if ( '' === $session_id ) {
			return new WP_REST_Response( null, 400 );
		}
		$client_name = WC_AI_Storefront_MCP_Session::client_name_for( $session_id );
		if ( null === $client_name ) {
			return new WP_REST_Response( null, 404 );
		}

		// Re-gate on every request: a merchant may have tightened the agent
		// allow-list after the session was minted.
		$regate = WC_AI_Storefront_MCP_Session::gate_client_name( $client_name, $settings );
		if ( is_wp_error( $regate ) ) {
			return new WP_REST_Response( null, 403 );
		}

		// Accepted notification (no `id`) → 202 ack, no body. Covers
		// notifications/initialized and any request-method (ping / tools.*)
		// a client sends without an id.
		if ( $is_notification ) {
			return new WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'notifications/initialized':
				return new WP_REST_Response( null, 202 );
			case 'ping':
				return $this->rpc_result( $id, (object) [] );
			case 'tools/list':
				return $this->rpc_result( $id, [ 'tools' => WC_AI_Storefront_MCP_Tools::definitions() ] );
			case 'tools/call':
				// A non-string `name` (e.g. an array) coerces to '' → unknown
				// tool → -32602, avoiding an "Array to string conversion" warning.
				$name   = is_string( $params['name'] ?? null ) ? $params['name'] : '';
				$args   = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];
				$result = WC_AI_Storefront_MCP_Tools::call( $name, $args, $client_name );
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
		$client_info = is_array( $params['clientInfo'] ?? null ) ? $params['clientInfo'] : [];
		$client_name = is_string( $client_info['name'] ?? null ) ? $client_info['name'] : '';
		$gated       = WC_AI_Storefront_MCP_Session::gate_client_name( $client_name, $settings );
		if ( is_wp_error( $gated ) ) {
			return new WP_REST_Response( null, 403 );
		}
		// Store the RAW handshake name (not the canonical $gated) so the
		// original agent identity is preserved for attribution. The server
		// re-canonicalizes it via gate_client_name() on every post-handshake
		// request, so the allow/deny decision is unaffected.
		$session_id = WC_AI_Storefront_MCP_Session::start( $client_name );
		$response   = $this->rpc_result(
			$id,
			[
				'protocolVersion' => self::LATEST_PROTOCOL,
				'capabilities'    => [ 'tools' => (object) [] ],
				'serverInfo'      => [
					'name'    => 'dev.ucp.shopping',
					'version' => defined( 'WC_AI_STOREFRONT_VERSION' ) ? WC_AI_STOREFRONT_VERSION : '0',
				],
			]
		);
		$response->header( 'Mcp-Session-Id', $session_id );
		return $response;
	}

	/**
	 * Whether an Origin header host matches the site host (DNS-rebinding
	 * defense for browser-originated requests).
	 *
	 * @param string $origin Origin header value.
	 * @return bool
	 */
	private function origin_matches_site( string $origin ): bool {
		// Deliberate EXACT host match (case-insensitive) as a DNS-rebinding
		// defense: no www/non-www or subdomain loosening. Relaxing this to
		// accept related hosts would re-open the rebinding vector that lets a
		// malicious page in a browser drive this local MCP endpoint. Do not
		// loosen — add a separate explicit allow-list if cross-host is ever
		// genuinely needed.
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $origin_host )
			&& is_string( $site_host )
			&& strtolower( $origin_host ) === strtolower( $site_host );
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
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			],
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
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => [
					'code'    => $code,
					'message' => $message,
				],
			],
			$status
		);
	}
}
