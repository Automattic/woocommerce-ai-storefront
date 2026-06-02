<?php
/**
 * MCP transport: ephemeral session + handshake-identity gate.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mints and resolves short-lived MCP sessions, and gates the free-form
 * handshake client name through the same UCP allow-list the REST transport
 * uses. A session simply binds a vetted canonical agent name to an opaque
 * id (the `Mcp-Session-Id` header) for the duration of the conversation.
 */
class WC_AI_Storefront_MCP_Session {

	/**
	 * Transient key prefix for MCP session id → canonical client name.
	 */
	const TRANSIENT_PREFIX = 'wc_ai_mcp_sess_';

	/**
	 * Session lifetime in seconds (15 minutes). Sliding TTL is not needed:
	 * agents re-initialize cheaply, and a stale id resolves to null which
	 * the server maps to a 404 so the agent re-handshakes.
	 */
	const TTL_SECONDS = 900;

	/**
	 * Resolve + gate a free-form handshake client name against settings.
	 *
	 * Mirrors the REST transport's agent gate: canonicalize the client name
	 * (first as a product token, then as a host), apply the
	 * `allow_unknown_ucp_agents` policy to the "Other AI" bucket, and finally
	 * run the per-brand crawler allow-list check. Returns the canonical name
	 * on allow so the caller can bind it to the session.
	 *
	 * @param string $client_name MCP `initialize` clientInfo.name.
	 * @param array  $settings    WC_AI_Storefront::get_settings().
	 * @return string|WP_Error Canonical name on allow; WP_Error(403) on deny.
	 */
	public static function gate_client_name( string $client_name, array $settings ) {
		$normalized = strtolower( trim( $client_name ) );
		$canonical  = WC_AI_Storefront_UCP_Agent_Header::canonicalize_product( $normalized );
		if ( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET === $canonical ) {
			$canonical = WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( $normalized );
		}

		// A BLANK (empty-after-trim) handshake name canonicalizes to '' (empty)
		// — an unrecognized non-blank name canonicalizes to OTHER_AI_BUCKET
		// directly, not to ''. For the blank case, both the unknown-agent
		// policy below and is_agent_allowed( '', … ) would PASS it — that
		// empty-means-allow rule is correct for header-less REST browser
		// traffic, but on MCP it lets a client skip the gate entirely by
		// sending an empty clientInfo.name. Coerce empty to the "Other AI"
		// bucket so a nameless agent is governed by the allow_unknown_ucp_agents
		// policy, never silently admitted.
		if ( '' === $canonical ) {
			$canonical = WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET;
		}

		if ( WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET === $canonical ) {
			$allow_unknown = isset( $settings['allow_unknown_ucp_agents'] )
				&& 'yes' === $settings['allow_unknown_ucp_agents'];
			if ( ! $allow_unknown ) {
				return new WP_Error(
					'mcp_agent_unknown_blocked',
					__( 'Unknown agent blocked.', 'woocommerce-ai-storefront' ),
					[ 'status' => 403 ]
				);
			}
		}

		$allowed = WC_AI_Storefront_Robots::resolve_allowed_crawlers( $settings );
		if ( ! WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( $canonical, $allowed ) ) {
			return new WP_Error(
				'mcp_agent_blocked',
				__( 'This agent is not allowed.', 'woocommerce-ai-storefront' ),
				[ 'status' => 403 ]
			);
		}

		return $canonical;
	}

	/**
	 * Mint a session for a vetted client name.
	 *
	 * Stores the RAW handshake name (the `initialize` clientInfo.name as the
	 * agent sent it), NOT the canonical gate output. The server re-canonicalizes
	 * it via gate_client_name() on every post-handshake request, so the
	 * allow/deny decision is re-derived each time and storing raw is safe.
	 * Preserving the original identity lets the tool layer resolve the same
	 * WC Order Attribution triple (utm_source) the REST transport produces.
	 *
	 * @param string $client_name Raw MCP `initialize` clientInfo.name. Already
	 *                            gated by the caller; stored verbatim.
	 * @return string The new Mcp-Session-Id.
	 */
	public static function start( string $client_name ): string {
		$id = (string) wp_generate_uuid4();
		// Cap the stored name at 253 bytes (RFC-1035 FQDN max, matching the
		// attribution resolver's cap). The name is an untrusted handshake
		// value persisted to the options table via a transient; capping
		// prevents a malicious client from bloating the DB with an oversized
		// name even within the rate limit.
		set_transient( self::TRANSIENT_PREFIX . $id, substr( $client_name, 0, 253 ), self::TTL_SECONDS );
		return $id;
	}

	/**
	 * Look up the raw client name behind a session id.
	 *
	 * Returns the raw handshake name stored by start() (the original
	 * clientInfo.name); the server re-gates it on each request.
	 *
	 * @param string $session_id Mcp-Session-Id header value.
	 * @return string|null Client name, or null when absent/expired.
	 */
	public static function client_name_for( string $session_id ): ?string {
		if ( '' === $session_id ) {
			return null;
		}
		$value = get_transient( self::TRANSIENT_PREFIX . $session_id );
		return is_string( $value ) ? $value : null;
	}
}
