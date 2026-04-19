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
	 * Map of UCP-Agent profile hostnames → short canonical brand names.
	 *
	 * The canonical name lands in the continue_url's `utm_source`
	 * parameter, which WooCommerce Order Attribution captures into
	 * `_wc_order_attribution_utm_source`. The Orders list then displays
	 * it in the built-in "Origin" column as `Source: {name}` — so this
	 * map directly controls what merchants see when an AI-sourced
	 * order appears in wp-admin.
	 *
	 * Naming convention: short, brand-clean, merchant-readable.
	 * Matches the spirit of the Discovery crawler list
	 * (`WC_AI_Syndication_Robots::LIVE_BROWSING_AGENTS`) but drops the
	 * UA-style suffixes (`-User`, `-SearchBot`) — those signal crawler
	 * *variant* in robots.txt, which is noise in an Origin column where
	 * the merchant just wants to know "which AI sent this order."
	 *
	 * Keys MUST be lower-case hostnames. Lookup is exact-match with a
	 * lower-case of the incoming hostname; no wildcard/subdomain logic.
	 * Subdomains that share a brand (e.g., `agents.openai.com`) can be
	 * added as additional keys when encountered. Over-matching (e.g.
	 * glob on `*.google.com`) would collapse distinct Google products
	 * — Gemini conversational, Storebot shopping overviews, Bard
	 * research — under a single label and lose valuable attribution
	 * detail, so keep the map literal.
	 *
	 * When a hostname is NOT in this map, the raw hostname is returned
	 * verbatim (see `canonicalize_host()`). That preserves traceability
	 * for novel agents while the map catches the 90% of known vendors.
	 *
	 * @var array<string, string>
	 */
	const KNOWN_AGENT_HOSTS = [
		// OpenAI.
		'chatgpt.com'           => 'ChatGPT',
		'openai.com'            => 'ChatGPT',

		// Anthropic.
		'claude.ai'             => 'Claude',
		'anthropic.com'         => 'Claude',

		// Google — Gemini is the conversational surface; keep
		// distinct from `Storebot-Google` which is the Shopping
		// Overviews crawler, a separate product.
		'gemini.google.com'     => 'Gemini',
		'deepmind.google'       => 'Gemini',

		// Microsoft. Note: `bing.com` → "Copilot" is a deliberate
		// but over-broad mapping. Bing.com hosts both the classic
		// Bing search surface and Copilot's web-chat experience;
		// UCP-Agent profile URLs from either product would land
		// on bing.com. Collapsing both to "Copilot" loses a
		// Search-vs-Chat distinction in the Origin column.
		// Acceptable today because Copilot is the AI-commerce
		// surface we care about attributing, and classic Bing
		// doesn't send UCP-Agent headers. If a separate
		// `search.bing.com` or similar subdomain emerges, split
		// the mapping then.
		'copilot.microsoft.com' => 'Copilot',
		'bing.com'              => 'Copilot',

		// Perplexity.
		'perplexity.ai'         => 'Perplexity',

		// Apple — Siri is the conversational assistant; distinct
		// from Applebot (search index) and Applebot-Extended (AI
		// training) which live in the Discovery crawler list.
		'siri.apple.com'        => 'Siri',

		// Amazon — Rufus is the conversational shopping assistant,
		// AmazonBuyForMe is its agentic-purchase crawler variant.
		'rufus.amazon.com'      => 'Rufus',

		// Klarna.
		'klarna.com'            => 'Klarna',

		// You.com.
		'you.com'               => 'You',

		// Kagi.
		'kagi.com'              => 'Kagi',
	];

	/**
	 * Canonicalize a hostname to a short brand name for merchant-facing
	 * attribution display (utm_source → Origin column).
	 *
	 * Returns the mapped brand name when the hostname is known, else
	 * the hostname itself. NEVER returns empty — callers can use the
	 * result directly as a utm_source value without a null-check.
	 *
	 * Separate from `extract_profile_hostname()` so the raw-hostname
	 * extraction stays pure (useful for logging/diagnostics) while
	 * display-layer canonicalization is explicit at the call site.
	 *
	 * @param string $host Hostname extracted from a UCP-Agent profile URL.
	 * @return string Short canonical brand name, or the hostname unchanged
	 *                when no mapping exists. Empty input returns empty.
	 */
	public static function canonicalize_host( string $host ): string {
		if ( '' === $host ) {
			return '';
		}

		$lower = strtolower( $host );
		return self::KNOWN_AGENT_HOSTS[ $lower ] ?? $host;
	}

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
