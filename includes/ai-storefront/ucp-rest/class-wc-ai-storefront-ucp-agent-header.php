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
 * @package WooCommerce_AI_Storefront
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses the UCP-Agent request header.
 */
class WC_AI_Storefront_UCP_Agent_Header {

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
	 * (`WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS`) but drops the
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
	 * When a hostname is NOT in this map, `canonicalize_host()` buckets
	 * it under `OTHER_AI_BUCKET` ("Other AI") rather than scattering
	 * one Origin-column row per novel hostname. The raw hostname is
	 * preserved separately on the order via the
	 * `_wc_ai_storefront_agent_host_raw` meta (see
	 * `WC_AI_Storefront_Attribution::AGENT_HOST_RAW_META_KEY`) so
	 * merchants who drill into an "Other AI" order still see who
	 * actually sent it. That meta also feeds aggregate review for
	 * graduating frequent unknown hostnames into this map with proper
	 * canonical names.
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

		// UCP Playground — third-party validation tool. Maps to a clean
		// canonical name so merchants who flip on the test crawler in
		// settings can recognize their own validation hits in stats.
		'ucpplayground.com'     => 'UCPPlayground',
	];

	/**
	 * Display label for unknown AI agents — agents whose UCP-Agent profile
	 * hostname isn't in `KNOWN_AGENT_HOSTS`. They're still allowed to
	 * transact (UCP is an open spec), but their attribution is bucketed
	 * under this label rather than scattering one Origin-column row per
	 * novel hostname.
	 *
	 * The raw hostname is preserved separately on the order
	 * (`_wc_ai_storefront_agent_host_raw` meta) for diagnostic/graduation
	 * purposes — when a particular unknown hostname becomes prominent
	 * across enough orders, it can be promoted into `KNOWN_AGENT_HOSTS`
	 * with a proper canonical name in a follow-up PR.
	 *
	 * Why bucket rather than scatter:
	 *   - Stats stay legible. The Top Agent card and per-agent breakdown
	 *     would otherwise show a long tail of one-off hostnames
	 *     (`agent.foo-startup.com`, `bot.experiment.dev`) that crowd
	 *     out the named brands the merchant cares about.
	 *   - Attribution still works. The order is correctly tagged as
	 *     "AI traffic" (utm_medium=ai_agent) just with a generic label.
	 *   - Provenance preserved. The raw hostname is stamped on the
	 *     order's `_wc_ai_storefront_agent_host_raw` meta whenever the
	 *     UCP-Agent header was parseable, AND a dedicated debug-log
	 *     line ("unknown AI agent bucketed as Other AI") is emitted
	 *     when the raw host AND the bucketed canonical land on the
	 *     same order — that is the conjunction that signals "novel
	 *     vendor seen". The general "attribution captured" debug line
	 *     also includes the raw host on every capture, so the merchant
	 *     can drill in either way.
	 */
	const OTHER_AI_BUCKET = 'Other AI';

	/**
	 * Canonicalize a hostname to a short brand name for merchant-facing
	 * attribution display (utm_source → Origin column).
	 *
	 * Returns:
	 *   - The mapped brand name when the hostname is in `KNOWN_AGENT_HOSTS`.
	 *   - `OTHER_AI_BUCKET` ("Other AI") when the hostname isn't mapped —
	 *     the unknown-agent bucket (see constant docblock for rationale).
	 *   - Empty string only when input is empty (no UCP-Agent header at all).
	 *
	 * NEVER returns the raw hostname directly — that would scatter the
	 * Origin column with one-off hostnames. Pre-this-change behavior
	 * was raw-hostname fall-through; preserved for diagnostics via the
	 * separate `_wc_ai_storefront_agent_host_raw` order meta + WP debug
	 * log emission at the controller layer.
	 *
	 * Separate from `extract_profile_hostname()` so the raw-hostname
	 * extraction stays pure (useful for logging/diagnostics) while
	 * display-layer canonicalization is explicit at the call site.
	 *
	 * @param string $host Hostname extracted from a UCP-Agent profile URL.
	 * @return string Short canonical brand name, `OTHER_AI_BUCKET` for
	 *                unknown hosts, or empty string when input is empty.
	 */
	public static function canonicalize_host( string $host ): string {
		if ( '' === $host ) {
			return '';
		}

		$lower = strtolower( $host );
		return self::KNOWN_AGENT_HOSTS[ $lower ] ?? self::OTHER_AI_BUCKET;
	}

	/**
	 * Idempotent canonicalization for already-stamped values.
	 *
	 * Use this at display time when reading a stored `utm_source` /
	 * `_wc_ai_storefront_agent` meta value, where the input may be:
	 *
	 *   - A raw hostname from a pre-1.6.7 order — needs canonicalization.
	 *   - An already-canonical brand name from a post-1.6.7 order — must
	 *     pass through unchanged. Re-running `canonicalize_host()` on a
	 *     branded value (e.g. `'Gemini'`, `'ChatGPT'`) would lower-case it,
	 *     find no entry in `KNOWN_AGENT_HOSTS` (whose keys are hostnames,
	 *     not brand names), and bucket it under `OTHER_AI_BUCKET`. Net
	 *     effect: every modern AI-attributed order would mis-display as
	 *     "Other AI" in the admin Recent Orders table.
	 *
	 * The fix: detect already-canonical values up front and short-circuit.
	 * Any value that appears in `KNOWN_AGENT_HOSTS`'s VALUE set, or equals
	 * `OTHER_AI_BUCKET`, is already canonical and passes through.
	 *
	 * Why a sibling helper rather than baking this into `canonicalize_host()`:
	 * the producer side (UCP request handlers building `utm_source` from a
	 * just-extracted hostname) wants the strict semantic — every input is
	 * a hostname, every output is canonical or the bucket. Mixing the
	 * idempotent fallback into the producer path would let attacker-forged
	 * UCP-Agent headers carrying brand-name strings (`profile="https://Gemini/..."`)
	 * silently round-trip a fake-canonical value into utm_source. Keep the
	 * strict check on the producer side; expose this leniency only at
	 * display-layer call sites.
	 *
	 * @param string $value Either a raw hostname (pre-1.6.7 legacy) or
	 *                      an already-canonical brand name (post-1.6.7).
	 * @return string Short canonical brand name, `OTHER_AI_BUCKET` for
	 *                unknown hosts, or the input unchanged when it's
	 *                already canonical. Empty input returns empty.
	 */
	public static function canonicalize_host_idempotent( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Already-canonical values pass through. The brand-name set is
		// the values of KNOWN_AGENT_HOSTS plus the OTHER_AI_BUCKET sentinel.
		// `array_values()` allocates a small array each call; static-cache
		// it as a property if this becomes a hot path (currently only hit
		// from admin-controller's per-order loop, ~10 orders/render).
		$canonical_names = array_values( self::KNOWN_AGENT_HOSTS );
		if ( in_array( $value, $canonical_names, true ) ) {
			return $value;
		}
		if ( self::OTHER_AI_BUCKET === $value ) {
			return $value;
		}

		return self::canonicalize_host( $value );
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
