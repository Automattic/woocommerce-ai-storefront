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
	 * Map: UCP-Agent product-name token → canonical brand name.
	 *
	 * Some UCP clients send the header in `Product/Version` form
	 * (RFC 7231 §5.5.3 User-Agent style — e.g. `UCP-Playground/1.0`,
	 * `Curl/8.4.0`) rather than the `profile="<URL>"` form
	 * `extract_profile_hostname()` parses. The product token has no
	 * intrinsic hostname to canonicalize against, so we maintain a
	 * parallel lookup keyed by lowercased product name.
	 *
	 * Discovered when UCPPlayground reported their REST integration
	 * sends `UCP-Agent: UCP-Playground/1.0` on every request, which
	 * our profile-URL-only parser couldn't consume — every order
	 * routed through their playground attributed as `ucp_unknown`
	 * even though the agent was clearly identified.
	 *
	 * Keys are lowercased product names, values are the same canonical
	 * brand strings used in `KNOWN_AGENT_HOSTS`. Sharing the brand
	 * namespace means stats roll up the same regardless of which
	 * header format the agent used.
	 *
	 * Graduation: promote into this map when the same product token
	 * has been observed across enough distinct merchants' orders to
	 * warrant a stable canonical name. There's no hard threshold —
	 * `KNOWN_AGENT_HOSTS` doesn't have one either, both maps are
	 * curated by-hand from observed traffic. Single one-off
	 * appearances bucket as "Other AI" via the fallback in
	 * `canonicalize_product()`; the cohort hint is `_wc_ai_storefront_agent_host_raw`
	 * meta which preserves the raw token for graduation review.
	 */
	const KNOWN_AGENT_PRODUCT_NAMES = [
		// UCP Playground — sends `UCP-Playground/1.0` on every REST
		// request (both User-Agent and UCP-Agent headers). Mirrors the
		// `ucpplayground.com` host entry so attribution converges on
		// the same canonical brand whichever header format is sent.
		'ucp-playground' => 'UCPPlayground',
	];

	/**
	 * Map: UCP-Agent product-name token → canonical hostname.
	 *
	 * Parallel to `KNOWN_AGENT_PRODUCT_NAMES`. Used by the canonical
	 * UTM shape (added 0.5.0) where `utm_source` is a lowercase
	 * hostname rather than a canonical brand name.
	 *
	 * Why a separate map rather than extending `KNOWN_AGENT_PRODUCT_NAMES`'s
	 * value shape: the existing constant maps `product → brand_name`
	 * and is consumed by display-layer code (admin Recent Orders,
	 * stats breakdown). Changing its values to an associative array
	 * would ripple through every consumer. A sibling map keeps each
	 * lookup direction independently tunable and avoids that churn.
	 *
	 * Why hostnames rather than canonical names for `utm_source`:
	 * lowercase hostnames match the GA4 / Google Analytics
	 * `utm_source` convention (where examples are `google`,
	 * `facebook`, etc.) that WC's Origin column surfaces verbatim.
	 * They also converge with what bypass-path agents naturally
	 * stamp — UCPPlayground's playground harness sends
	 * `utm_source=ucpplayground.com` on orders that don't go
	 * through our `/checkout-sessions`. Stamping the hostname even
	 * for product-form requests means the same agent attributes to
	 * one consistent source string regardless of which path the
	 * order took.
	 *
	 * Add an entry here ALONGSIDE every `KNOWN_AGENT_PRODUCT_NAMES`
	 * entry. The product token must canonicalize to a brand AND to a
	 * stable hostname — gaps in this map mean the new utm_source
	 * shape falls back to the product token itself (e.g.
	 * `utm_source=ucp-playground`), which fragments stats against the
	 * profile-URL path (`utm_source=ucpplayground.com`).
	 */
	const PRODUCT_TO_HOSTNAME = [
		'ucp-playground' => 'ucpplayground.com',
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
	 *     AI traffic via the `utm_id=woo_ucp` flag (or legacy
	 *     `utm_medium=ai_agent` for pre-0.5.0 orders) — the bucket
	 *     just means the canonical brand is "Other AI", not that
	 *     the AI signal itself is missing.
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
	 * Map: UCP-Agent canonical brand name → matching crawler IDs in
	 * `WC_AI_Storefront_Robots::AI_CRAWLERS`.
	 *
	 * Two namespaces meet here:
	 *   - `KNOWN_AGENT_HOSTS` values are short brand names ("ChatGPT",
	 *     "Claude") used for merchant-facing attribution display.
	 *   - `AI_CRAWLERS` IDs are the literal user-agent tokens the
	 *     vendors use when crawling robots.txt ("ChatGPT-User",
	 *     "OAI-SearchBot"). The merchant's `allowed_crawlers` setting
	 *     stores these IDs.
	 *
	 * The merchant's mental model is "I want to allow / block this
	 * brand," not "I want to allow / block this user-agent string."
	 * So when an agent hits the UCP REST endpoint with a `UCP-Agent`
	 * header that resolves to canonical "ChatGPT," we need to know
	 * which crawler IDs (ChatGPT-User, OAI-SearchBot) collectively
	 * represent the merchant's intent for that brand. If the merchant
	 * has any of them in `allowed_crawlers`, the brand is allowed at
	 * the endpoint level; if all of them are missing, the brand is
	 * blocked.
	 *
	 * Brands with no crawler equivalent (You.com, Kagi) are NOT in
	 * this map — they don't crawl robots.txt today, so there's no
	 * configuration surface for the merchant to block them via the
	 * AI Crawlers list. Their requests pass through the access gate
	 * uncontested. If a merchant needs to block one of those agents
	 * while UCP is still exposed, that's a follow-up: introduce a
	 * separate "AI Agents" allow-list keyed by canonical name (an
	 * orthogonal axis to "AI Crawlers" which is keyed by user-agent).
	 *
	 * @var array<string, string[]>
	 */
	const UCP_AGENT_CRAWLER_MAP = [
		'ChatGPT'       => [ 'ChatGPT-User', 'OAI-SearchBot' ],
		'Claude'        => [ 'Claude-User', 'Claude-SearchBot' ],
		'Gemini'        => [ 'Storebot-Google' ],
		'Copilot'       => [ 'AdIdxBot' ],
		'Perplexity'    => [ 'PerplexityBot', 'Perplexity-User' ],
		'Siri'          => [ 'Applebot' ],
		'Rufus'         => [ 'AmazonBuyForMe' ],
		'Klarna'        => [ 'KlarnaBot' ],
		'UCPPlayground' => [ 'UCPPlayground' ],
	];

	/**
	 * Whether an agent (identified by canonical brand name) is allowed
	 * to access the UCP REST endpoint based on the merchant's
	 * `allowed_crawlers` setting.
	 *
	 * Decision logic:
	 *   - Canonical name not in `UCP_AGENT_CRAWLER_MAP` (e.g. unknown
	 *     hosts → "Other AI", or You / Kagi which have no crawler
	 *     equivalent today): ALLOW. The open-spec design admits any
	 *     agent that sends a parseable UCP-Agent header; we don't
	 *     have a configuration surface to block these brands today.
	 *   - Canonical name in the map and AT LEAST ONE of its mapped
	 *     crawler IDs is in `allowed_crawlers`: ALLOW. The merchant
	 *     has signaled "I'm OK with at least one face of this brand."
	 *   - Canonical name in the map but ALL of its mapped crawler
	 *     IDs are missing from `allowed_crawlers`: DENY. The merchant
	 *     has signaled "I don't want any traffic from this brand"
	 *     and we honor that consistently across robots.txt + UCP
	 *     endpoint.
	 *   - Empty canonical (no UCP-Agent header at all): ALLOW.
	 *     Pre-UCP traffic is already covered by WP's standard auth +
	 *     rate-limit layers; we don't gate on header presence.
	 *
	 * Pure function — caller passes both the canonical name + the
	 * allowed_crawlers list, no global state read. Makes the logic
	 * trivially testable and keeps the gate's settings dependency
	 * explicit at the call site.
	 *
	 * @param string   $canonical        Canonical brand name from
	 *                                   `canonicalize_host()` (or empty).
	 * @param string[] $allowed_crawlers Merchant's saved allow-list of
	 *                                   crawler IDs.
	 * @return bool True when allowed; false when blocked.
	 */
	public static function is_agent_allowed( string $canonical, array $allowed_crawlers ): bool {
		if ( '' === $canonical ) {
			return true;
		}
		if ( ! isset( self::UCP_AGENT_CRAWLER_MAP[ $canonical ] ) ) {
			return true;
		}
		$mapped_ids = self::UCP_AGENT_CRAWLER_MAP[ $canonical ];
		foreach ( $mapped_ids as $crawler_id ) {
			if ( in_array( $crawler_id, $allowed_crawlers, true ) ) {
				return true;
			}
		}
		return false;
	}

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
	 * Normalize a host-shaped string for `KNOWN_AGENT_HOSTS` lookup.
	 *
	 * The Order Attribution capture path reads `utm_source` directly
	 * from WooCommerce core — that value is whatever the agent (or
	 * upstream URL builder) put on the URL. Real-world variants we
	 * have to handle:
	 *
	 *   - `openai.com`              (bare, canonical)
	 *   - `OpenAI.COM`              (mixed case — DNS RFC 1035)
	 *   - `https://openai.com`      (full URL, often when an agent
	 *                                copies its profile URL into utm_source)
	 *   - `https://openai.com/`     (trailing slash)
	 *   - `https://openai.com/path` (URL with path)
	 *   - `openai.com:443`          (host:port)
	 *   - `openai.com.`             (FQDN trailing dot)
	 *   - ` openai.com `            (whitespace from URL-decoder edge cases)
	 *
	 * Every form above must collapse to the same `openai.com` so the
	 * downstream `KNOWN_AGENT_HOSTS` key lookup matches. Without this,
	 * the lenient host-match attribution gate would silently miss
	 * orders where the agent declared the same host in a different
	 * lexical form.
	 *
	 * Deliberate non-features:
	 *   - We do NOT strip a leading `www.`. `www.openai.com` and
	 *     `openai.com` are different DNS names; treating them as the
	 *     same would be a heuristic that could produce false matches.
	 *     If we want to recognize `www.openai.com`, the right move is
	 *     to add it as an explicit entry in `KNOWN_AGENT_HOSTS`.
	 *   - We do NOT subdomain-glob. Same rationale as
	 *     `canonicalize_host()` — a vendor's training-bot subdomain
	 *     might be a different product than the buying-bot subdomain.
	 *
	 * @param string $value Raw value (any of the forms above, or junk).
	 * @return string Normalized lowercase host, or empty string when
	 *                the input doesn't yield a parseable host.
	 */
	public static function normalize_host_string( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// Protocol-relative URL (`//openai.com/path`) — `wp_parse_url`
		// can handle these directly when given a hint scheme. Without
		// this branch the bare-host path below would `strpos($value, '/')`
		// at index 0 and substring to "" — silently dropping the host.
		if ( 0 === strpos( $value, '//' ) ) {
			$parsed_host = wp_parse_url( 'https:' . $value, PHP_URL_HOST );
			if ( ! is_string( $parsed_host ) || '' === $parsed_host ) {
				return '';
			}
			$value = $parsed_host;
		} elseif ( false !== strpos( $value, '://' ) ) {
			// Full URL with scheme — let `wp_parse_url` extract the host.
			// Returns null for malformed URLs.
			$parsed_host = wp_parse_url( $value, PHP_URL_HOST );
			if ( ! is_string( $parsed_host ) || '' === $parsed_host ) {
				return '';
			}
			$value = $parsed_host;
		} else {
			// Bare host — but it might have a path-like suffix
			// (`openai.com/foo`). Strip everything from the first `/`
			// onward.
			$slash_pos = strpos( $value, '/' );
			if ( false !== $slash_pos ) {
				$value = substr( $value, 0, $slash_pos );
			}
		}

		// Unwrap IPv6 literal brackets if present. `wp_parse_url`
		// returns the host of `http://[2001:db8::1]:443/` as
		// `[2001:db8::1]` — brackets included. Strip them so the
		// stored value is the canonical literal that a future
		// IPv6-only KNOWN_AGENT_HOSTS entry would match against.
		if ( '' !== $value && '[' === $value[0] && ']' === substr( $value, -1 ) ) {
			$value = substr( $value, 1, -1 );
		}

		// Strip an optional `:port` suffix. Three forms to handle:
		//
		//   - `openai.com:443`           → strip `:443`
		//   - `2001:db8::1`              → IPv6 literal, MUST NOT touch
		//   - `[2001:db8::1]:443`        → IPv6 + port (already
		//                                  unwrapped by wp_parse_url
		//                                  + the bracket strip above)
		//
		// IPv6 literals contain multiple colons. Match the `host:port`
		// shape strictly with a single trailing `:digits` sequence;
		// any value with more than one colon (an IPv6 literal) doesn't
		// match the pattern and passes through unchanged.
		if ( 1 === preg_match( '/^([^:\[\]]+):\d+$/', $value, $matches ) ) {
			$value = $matches[1];
		}

		// Strip a single trailing dot (FQDN form) and lowercase. Hosts
		// are case-insensitive per DNS RFC 1035; `KNOWN_AGENT_HOSTS`
		// keys are stored lowercase by convention.
		return strtolower( rtrim( $value, '.' ) );
	}

	/**
	 * Extract the agent's profile URL hostname from a UCP-Agent header value.
	 *
	 * Handles the RFC 8941 Dictionary Structured Field shape where the
	 * agent self-identifies via a `profile="..."` URL. This is one of
	 * two parser entry points on this class — see also
	 * `extract_agent_product()` for the RFC 7231 §5.5.3 Product/Version
	 * shape. Callers (`resolve_agent_host()` in the REST controller,
	 * `check_agent_access()` for the security gate) chain both parsers
	 * to cover both formats.
	 *
	 * Implementation finds the `profile=` field via regex, then parses
	 * the quoted URL's hostname. We do NOT implement full RFC 8941
	 * semantics (escape sequences, parameter lists, bare tokens) —
	 * only what's needed to pull the hostname for utm_source.
	 *
	 * @param string $header_value Raw header value, e.g. `profile="https://agent.example.com/..."`.
	 * @return string Hostname (e.g. "agent.example.com") or empty string if absent/malformed.
	 */
	public static function extract_profile_hostname( string $header_value ): string {
		if ( '' === $header_value ) {
			return '';
		}

		// Find the quoted URL that follows `profile=`. Three guards
		// here:
		//
		//   1. The `profile` token must appear at the start of the
		//      header value or after a whitespace / `,` / `;`
		//      separator. Otherwise a header value like
		//      `notprofile="https://evil.example/"` (or any field
		//      whose name happens to end in `profile`) would silently
		//      match and we'd extract `evil.example` as the agent
		//      hostname. RFC 8941 Dictionary fields are
		//      comma-separated, so requiring a separator before the
		//      key is the right defensive boundary.
		//   2. The value must be quoted. RFC 8941 Dictionary strings
		//      require quotes; accepting unquoted values would let
		//      non-compliant agents look compliant.
		//   3. We use an explicit `(?:^|[\s,;])` prefix guard rather
		//      than relying on a regex word-boundary, keeping the
		//      match aligned with RFC 8941 separators (comma is the
		//      canonical Dictionary separator; whitespace and
		//      semicolon are tolerated for real-world variants).
		if ( ! preg_match( '/(?:^|[\s,;])profile="([^"]+)"/', $header_value, $matches ) ) {
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

	/**
	 * Extract the agent's product-name token from a `Product/Version`
	 * format UCP-Agent header value.
	 *
	 * Companion to `extract_profile_hostname()` for the alternate UCP-Agent
	 * format some clients use:
	 *
	 *     UCP-Agent: UCP-Playground/1.0
	 *
	 * This is RFC 7231 §5.5.3 User-Agent style (a product, optionally
	 * followed by a version), not the RFC 8941 Dictionary structured
	 * form `profile="<URL>"`. Both shapes appear in the wild; we accept
	 * either.
	 *
	 * Returns the lowercased product name (e.g. `"ucp-playground"`)
	 * suitable as a key into `KNOWN_AGENT_PRODUCT_NAMES`. Empty string
	 * when the header is absent, malformed, or in `profile=` form
	 * (callers should try `extract_profile_hostname()` first or in
	 * parallel; the two formats are mutually exclusive in practice but
	 * the parser doesn't enforce that).
	 *
	 * Validation rules:
	 * - Match a leading product token of letters / digits / dashes /
	 *   underscores / dots, optionally followed by `/version`. The
	 *   character class permits dots primarily so the version
	 *   segment can carry semver values like `Mozilla/5.0`. The
	 *   product token also permits dots — uncommon in real
	 *   `KNOWN_AGENT_PRODUCT_NAMES` entries today but cheap to
	 *   accept.
	 * - Reject any header value containing `profile=` anywhere in
	 *   the string (slightly broader than "leading-anchored" — we
	 *   use `stripos` for simplicity since the false-positive risk
	 *   on real product names is negligible).
	 * - Reject empty input up front.
	 *
	 * Lowercasing happens here (not at the lookup site) so the produced
	 * value is always a stable lookup key regardless of how the agent
	 * cased its header. Mirrors the `strtolower()` in `canonicalize_host()`
	 * for the same reason.
	 *
	 * @param string $header_value Raw header value, e.g. `UCP-Playground/1.0`.
	 * @return string Lowercased product name (e.g. `"ucp-playground"`),
	 *                or empty string if absent/malformed/wrong format.
	 */
	public static function extract_agent_product( string $header_value ): string {
		if ( '' === $header_value ) {
			return '';
		}

		// Reject `profile="..."` — that's the structured-field shape
		// `extract_profile_hostname()` handles, not a product token.
		// `stripos` so case-variant values like `Profile=` also bail.
		if ( false !== stripos( $header_value, 'profile=' ) ) {
			return '';
		}

		// Match the leading product token. Anchored at the start
		// (^) so the token has to be the first thing in the header
		// (not a fragment buried inside some other syntax) and at
		// the end (`\s*$`) so trailing junk produces a no-match
		// rather than partial extraction. The version segment is
		// matched but NOT captured — its content doesn't affect the
		// canonical mapping. The version-segment quantifier is `+`
		// (not `*`) so a header ending in a bare slash like
		// `Agent/` doesn't parse as `agent` — RFC 7231's
		// `product/version` grammar requires the version token to
		// be non-empty when the slash is present. Note the regex
		// still constrains version characters to `[A-Za-z0-9._-]`
		// and rejects values with trailing parenthesized comments
		// (e.g. `Mozilla/5.0 (compatible; UCP-Bot)`), so non-trivial
		// User-Agent-style values won't parse.
		if ( ! preg_match( '#^([A-Za-z0-9._-]+)(?:/[A-Za-z0-9._-]+)?\s*$#', trim( $header_value ), $matches ) ) {
			return '';
		}

		return strtolower( $matches[1] );
	}

	/**
	 * Canonicalize a product-name token to a brand name.
	 *
	 * Sibling to `canonicalize_host()`, but for the
	 * `KNOWN_AGENT_PRODUCT_NAMES` lookup table. Returns the canonical
	 * brand string when the product token is recognized, the
	 * `OTHER_AI_BUCKET` sentinel when an unknown product was provided,
	 * and empty string when the input itself was empty.
	 *
	 * Empty-vs-bucket distinction matches `canonicalize_host()` — empty
	 * means "no signal at all"; bucket means "a valid signal we just
	 * don't recognize." Callers use the empty case to fall through to
	 * the next identification path (e.g. body field), and the bucket
	 * case to stamp `Other AI` and stop searching.
	 *
	 * @param string $product Lowercased product name token (output of
	 *                        `extract_agent_product()`).
	 * @return string Canonical brand name, `OTHER_AI_BUCKET`, or empty.
	 */
	public static function canonicalize_product( string $product ): string {
		if ( '' === $product ) {
			return '';
		}

		return self::KNOWN_AGENT_PRODUCT_NAMES[ $product ] ?? self::OTHER_AI_BUCKET;
	}
}
