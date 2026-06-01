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
	 * Mint a session for a vetted canonical client name.
	 *
	 * @param string $canonical_name Canonical client name (gate output).
	 * @return string The new Mcp-Session-Id.
	 */
	public static function start( string $canonical_name ): string {
		$id = (string) wp_generate_uuid4();
		set_transient( self::TRANSIENT_PREFIX . $id, $canonical_name, self::TTL_SECONDS );
		return $id;
	}

	/**
	 * Look up the canonical client name behind a session id.
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
