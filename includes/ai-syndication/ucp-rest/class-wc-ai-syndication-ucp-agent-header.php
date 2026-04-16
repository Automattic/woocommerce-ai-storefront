<?php
/**
 * AI Syndication: UCP-Agent Header Parser
 *
 * Parses the `UCP-Agent` HTTP header sent by UCP-compliant agents
 * when calling our REST endpoints. The header format (RFC 8941
 * Dictionary Structured Field) is:
 *
 *     UCP-Agent: profile="https://agent.example.com/profiles/shopping.json"
 *
 * The `profile` value is a URL pointing at the agent's own UCP
 * profile. Merchants can fetch that URL to learn about the agent
 * (name, capabilities, signing keys).
 *
 * For v1, we only extract the hostname from the profile URL —
 * used as `utm_source` on checkout redirect URLs for attribution.
 * Full RFC 8941 Dictionary parsing is deferred until we need
 * additional fields from the header.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses the UCP-Agent request header.
 */
class WC_AI_Syndication_UCP_Agent_Header {

	/**
	 * Value used as utm_source when no UCP-Agent header is present
	 * or the header can't be parsed. Distinguishes "UCP flow without
	 * identifiable agent" from legitimate agents like OpenAI.
	 */
	const FALLBACK_SOURCE = 'ucp_unknown';

	/**
	 * Extract the agent's profile URL hostname from a UCP-Agent header value.
	 *
	 * @param string $header_value Raw header value, e.g. `profile="https://agent.example.com/..."`.
	 * @return string Hostname (e.g. "agent.example.com") or empty string if absent/malformed.
	 */
	public static function extract_profile_hostname( string $header_value ): string {
		// TODO (task 3): implement regex extraction of the `profile` field
		// and hostname parsing. Return empty string for missing or malformed
		// input.
		return '';
	}
}
