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
	 * Implementation is a v1 shortcut — it finds the first occurrence of
	 * `profile="..."` via regex, then parses the quoted URL's hostname.
	 * It does NOT implement full RFC 8941 Dictionary Structured Field
	 * semantics (escape sequences, parameter lists, bare tokens). If we
	 * ever need other fields from the header we'll upgrade to a proper
	 * parser, but for "extract the hostname for utm_source" this is
	 * enough.
	 *
	 * @param string $header_value Raw header value, e.g. `profile="https://agent.example.com/..."`.
	 * @return string Hostname (e.g. "agent.example.com") or empty string if absent/malformed.
	 */
	public static function extract_profile_hostname( string $header_value ): string {
		if ( '' === $header_value ) {
			return '';
		}

		// Find the quoted URL that follows `profile=`. Rejecting
		// unquoted values intentionally — RFC 8941 Dictionary strings
		// must be quoted, and accepting an unquoted value would let
		// non-compliant agents look compliant.
		if ( ! preg_match( '/profile="([^"]+)"/', $header_value, $matches ) ) {
			return '';
		}

		$profile_url = $matches[1];
		$host        = wp_parse_url( $profile_url, PHP_URL_HOST );

		// `wp_parse_url` returns `null` for malformed URLs. Coerce to
		// empty string so callers get a predictable type and don't
		// accidentally pass null into string contexts (which PHP
		// silently converts to "" but deprecated in PHP 8.1+).
		return is_string( $host ) ? $host : '';
	}
}
