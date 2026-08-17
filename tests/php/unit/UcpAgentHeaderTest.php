<?php
/**
 * Tests for WC_AI_Storefront_UCP_Agent_Header.
 *
 * Covers the v1 parser: extract a hostname from a UCP-Agent header
 * whose format follows the well-formed spec example:
 *
 *     UCP-Agent: profile="https://agent.example.com/profiles/shopping.json"
 *
 * Real RFC 8941 parsing is deferred — if we ever need other fields
 * from the header (`version`, etc.), add tests for those then.
 *
 * @package WooCommerce_AI_Storefront
 */

class UcpAgentHeaderTest extends \PHPUnit\Framework\TestCase {

	// ------------------------------------------------------------------
	// Happy path
	// ------------------------------------------------------------------

	public function test_extracts_hostname_from_well_formed_header(): void {
		$header = 'profile="https://agent.example.com/profiles/shopping.json"';

		$this->assertEquals(
			'agent.example.com',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_extracts_hostname_with_http_scheme(): void {
		// Less common but technically valid — agents testing locally
		// might send http://. Parser should still return the hostname.
		$header = 'profile="http://localhost:3000/profile.json"';

		$this->assertEquals(
			'localhost',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_extracts_hostname_from_subdomain_url(): void {
		$header = 'profile="https://shopping-agent.sub.openai.com/ucp.json"';

		$this->assertEquals(
			'shopping-agent.sub.openai.com',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	// ------------------------------------------------------------------
	// Missing / absent inputs
	// ------------------------------------------------------------------

	public function test_returns_empty_for_empty_string(): void {
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( '' )
		);
	}

	public function test_returns_empty_when_profile_field_absent(): void {
		// Header present but doesn't have a `profile=` field. Example:
		// a partially-populated header with only metadata fields.
		$header = 'version="2026-04-08"';

		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	// ------------------------------------------------------------------
	// Malformed inputs — parser must not crash or return garbage
	// ------------------------------------------------------------------

	public function test_returns_empty_for_unquoted_profile_value(): void {
		// RFC 8941 Dictionary Structured Fields require quoted strings
		// for string values. `profile=https://...` (without quotes) is
		// malformed. Accepting it would mean accepting a non-compliant
		// agent as compliant; better to return empty.
		$header = 'profile=https://agent.example.com/profile.json';

		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_returns_empty_for_malformed_url_in_profile(): void {
		// profile= is there, quoted, but the value isn't a valid URL.
		// parse_url returns something implementation-defined for junk;
		// our parser should return empty string to avoid passing junk
		// downstream to the utm_source builder.
		$header = 'profile="not-a-url"';

		$result = WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header );

		// The exact behavior depends on wp_parse_url; either empty or
		// the raw "not-a-url" string. We assert it's empty because
		// anything else would let garbage propagate to utm_source.
		$this->assertEquals( '', $result );
	}

	// ------------------------------------------------------------------
	// Composite headers — profile field among others
	// ------------------------------------------------------------------

	public function test_extracts_hostname_when_profile_is_first_field(): void {
		$header = 'profile="https://agent.example.com/profile.json", version="2026-04-08"';

		$this->assertEquals(
			'agent.example.com',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_extracts_hostname_when_profile_is_later_field(): void {
		// Agents may send other fields before `profile`. Parser must
		// still find it.
		$header = 'version="2026-04-08", profile="https://agent.example.com/profile.json"';

		$this->assertEquals(
			'agent.example.com',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_does_not_match_field_name_substring_profile(): void {
		// Regression guard: a header value like `notprofile="..."` or
		// `myprofile="..."` must NOT match. Without the
		// boundary-anchored regex (separator before `profile=`), an
		// attacker or buggy intermediary could craft a field name
		// ending in "profile" and have its quoted URL extracted as
		// the agent hostname. RFC 8941 Dictionary fields are
		// comma-separated, so we anchor `profile=` to the start of
		// the header value or after a separator (`\s`, `,`, `;`).
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( 'notprofile="https://evil.example/agent.json"' )
		);
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( 'myprofile="https://evil.example/agent.json"' )
		);
	}

	// ------------------------------------------------------------------
	// FALLBACK_SOURCE constant exists and has expected value
	// ------------------------------------------------------------------

	public function test_fallback_source_constant_is_stable(): void {
		// This constant is used by checkout-sessions to build the
		// utm_source when the header is missing. Changing it silently
		// re-attributes historical orders — lock it in place.
		$this->assertEquals(
			'ucp_unknown',
			WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE
		);
	}

	// ------------------------------------------------------------------
	// canonicalize_host() — hostname → short brand name
	// ------------------------------------------------------------------

	/**
	 * @dataProvider known_host_provider
	 */
	public function test_canonicalize_host_maps_known_hostnames( string $input, string $expected ): void {
		// Every entry in KNOWN_AGENT_HOSTS must resolve to its brand
		// name. Adding a new brand: add it to the constant AND add a
		// data-provider row here — the test doubles as the map's
		// spec so the two can't silently drift.
		$this->assertEquals(
			$expected,
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( $input )
		);
	}

	public static function known_host_provider(): array {
		return array(
			'OpenAI ChatGPT'      => array( 'chatgpt.com', 'ChatGPT' ),
			'OpenAI corporate'    => array( 'openai.com', 'ChatGPT' ),
			'Anthropic Claude'    => array( 'claude.ai', 'Claude' ),
			'Anthropic corporate' => array( 'anthropic.com', 'Claude' ),
			'Google Gemini'       => array( 'gemini.google.com', 'Gemini' ),
			'Google DeepMind'     => array( 'deepmind.google', 'Gemini' ),
			'Microsoft Copilot'   => array( 'copilot.microsoft.com', 'Copilot' ),
			'Microsoft Bing'      => array( 'bing.com', 'Copilot' ),
			'Perplexity'          => array( 'perplexity.ai', 'Perplexity' ),
			'Apple Siri'          => array( 'siri.apple.com', 'Siri' ),
			'Amazon Rufus'        => array( 'rufus.amazon.com', 'Rufus' ),
			'Klarna'              => array( 'klarna.com', 'Klarna' ),
			'You.com'             => array( 'you.com', 'You' ),
			'Kagi'                => array( 'kagi.com', 'Kagi' ),
		);
	}

	public function test_canonicalize_host_is_case_insensitive(): void {
		// DNS hostnames are case-insensitive by RFC, and some agents
		// send mixed-case (observed: `Gemini.google.com` from Google's
		// Gemini profile URL). Canonicalization must collapse case
		// before lookup or the mapping silently misses.
		$this->assertEquals(
			'Gemini',
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( 'Gemini.Google.COM' )
		);
	}

	public function test_canonicalize_host_buckets_unknown_to_other_ai(): void {
		// Unknown agents bucket under the "Other AI" label rather than
		// scattering one Origin-column row per novel hostname. The raw
		// hostname is preserved separately on the order
		// (`_wc_ai_storefront_agent_host_raw` meta) for diagnostic /
		// graduation purposes — see resolve_agent_host() docblock.
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( 'unknown-agent.example.com' )
		);
		$this->assertEquals(
			'Other AI',
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( 'unknown-agent.example.com' ),
			'OTHER_AI_BUCKET constant should be the literal string "Other AI" — locking the value here so a typo does not silently change every merchant\'s Origin column.'
		);
	}

	public function test_canonicalize_host_returns_empty_for_empty_input(): void {
		// Guards against `wp_parse_url()` returning an empty string for
		// a malformed profile URL. An empty input should pass through
		// untouched so `resolve_agent_host()` can detect the failure
		// and fall back to FALLBACK_SOURCE.
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( '' )
		);
	}

	public function test_canonicalize_host_does_not_subdomain_match(): void {
		// Deliberate non-feature: we do NOT glob-match subdomains.
		// `foo.openai.com` is NOT `openai.com`; an agent at that
		// subdomain might be a different product (training bot,
		// internal tool, partner integration) and collapsing it to
		// "ChatGPT" would misattribute. Unknown subdomains bucket
		// under "Other AI"; the raw hostname is preserved on the
		// order meta for graduation review.
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( 'foo.openai.com' )
		);
	}

	// ------------------------------------------------------------------
	// is_agent_allowed() — the gate behind the UCP REST endpoint
	// ------------------------------------------------------------------

	public function test_is_agent_allowed_empty_canonical_passes(): void {
		// Empty canonical means there was no UCP-Agent header (or it
		// was unparseable). We don't gate on header presence — pre-UCP
		// traffic uses standard WP auth + rate-limit layers. Closing
		// this would break manifest crawls and any non-UCP client.
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( '', array() )
		);
	}

	public function test_is_agent_allowed_unknown_brand_passes(): void {
		// "Other AI" + brands without a crawler equivalent (You.com,
		// Kagi) aren't in UCP_AGENT_CRAWLER_MAP. Pass-through is the
		// open-spec wedge: any agent with a parseable UCP-Agent header
		// gets in unless the merchant has an explicit way to block it.
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
				array()
			)
		);
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( 'You', array() )
		);
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( 'Kagi', array() )
		);
	}

	public function test_is_agent_allowed_known_brand_with_one_crawler_id_present(): void {
		// Merchant has ONE of ChatGPT's two crawler IDs in their
		// allowed list — that's enough. The merchant's mental model
		// is "I'm OK with this brand," not "I'm OK with each named
		// user-agent." OR-semantics across mapped IDs.
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				array( 'ChatGPT-User' )
			)
		);
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				array( 'OAI-SearchBot' )
			)
		);
	}

	public function test_is_agent_allowed_known_brand_with_all_crawler_ids_present(): void {
		// Both ChatGPT crawler IDs in the allow-list — straightforward
		// allow.
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				array( 'ChatGPT-User', 'OAI-SearchBot' )
			)
		);
	}

	public function test_is_agent_allowed_known_brand_with_no_mapped_crawler_ids_blocks(): void {
		// Merchant's allow-list contains OTHER vendors but none of
		// ChatGPT's mapped crawler IDs. The gate must deny — this is
		// the central behavior the production bug surfaced (a brand
		// the merchant turned off in robots.txt was still hitting UCP).
		$this->assertFalse(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				array( 'PerplexityBot', 'KlarnaBot' )
			)
		);
	}

	public function test_is_agent_allowed_known_brand_with_empty_allow_list_blocks(): void {
		// Empty allow-list = merchant has explicitly turned off every
		// crawler. Don't accept "ChatGPT" via the UCP endpoint when
		// they've turned every brand's crawlers off — that would let
		// UCP traffic in through a side door the merchant believed
		// was closed.
		$this->assertFalse(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				array()
			)
		);
	}

	public function test_is_agent_allowed_match_is_strict_string_compare(): void {
		// `in_array($id, $list, true)` — strict comparison. A
		// case-mismatched or whitespace-padded entry won't satisfy
		// the gate. Merchant settings sanitization happens upstream;
		// the gate trusts what's in the saved list verbatim.
		$this->assertFalse(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				array( 'chatgpt-user' ) // wrong case
			)
		);
		$this->assertFalse(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				array( ' ChatGPT-User ' ) // padded whitespace
			)
		);
	}

	public function test_is_agent_allowed_each_brand_in_map_has_at_least_one_id(): void {
		// Defensive structural check: every entry in
		// UCP_AGENT_CRAWLER_MAP must list at least one crawler ID.
		// An empty list would silently DENY that brand for any
		// merchant settings (because the foreach would never enter
		// the `in_array` branch and we'd fall through to `false`).
		// A future contributor adding a brand row must include at
		// least the brand's own bot ID.
		foreach (
			WC_AI_Storefront_UCP_Agent_Header::UCP_AGENT_CRAWLER_MAP
			as $brand => $ids
		) {
			$this->assertNotEmpty(
				$ids,
				sprintf( 'Brand "%s" has no mapped crawler IDs.', $brand )
			);
			$this->assertContainsOnly( 'string', $ids );
		}
	}

	public function test_is_agent_allowed_every_mapped_id_exists_in_ai_crawlers(): void {
		// Stricter structural check: every crawler ID listed in
		// UCP_AGENT_CRAWLER_MAP must actually appear in
		// `WC_AI_Storefront_Robots::AI_CRAWLERS`. The two sources have
		// to stay in lockstep because the merchant's UI saves IDs from
		// the AI_CRAWLERS list, and the gate looks them up via this map.
		// A typo in either side ("ChatGTP-User", "Gemin-Searchbot") would
		// produce an ID the merchant can never save through the UI —
		// that brand would be permanently blocked at the endpoint
		// regardless of merchant intent. This test catches the typo at
		// CI time, before a release ships with a silently-blocked brand.
		foreach (
			WC_AI_Storefront_UCP_Agent_Header::UCP_AGENT_CRAWLER_MAP
			as $brand => $ids
		) {
			foreach ( $ids as $id ) {
				$this->assertContains(
					$id,
					WC_AI_Storefront_Robots::AI_CRAWLERS,
					sprintf(
						'Brand "%s" maps to crawler ID "%s" which is NOT in WC_AI_Storefront_Robots::AI_CRAWLERS — the merchant UI would never offer that ID, so the brand is permanently blocked.',
						$brand,
						$id
					)
				);
			}
		}
	}

	public function test_is_agent_allowed_ucpplayground_self_referential(): void {
		// UCPPlayground is the dev/test crawler we ship with the
		// plugin. The map entry pairs canonical "UCPPlayground" with
		// crawler ID "UCPPlayground" so the merchant can flip it on
		// or off in the AI Crawlers list and have that flip honored
		// at the endpoint. Without this row UCPPlayground would fall
		// to "Other AI" and bypass the gate.
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'UCPPlayground',
				array( 'UCPPlayground' )
			)
		);
		$this->assertFalse(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'UCPPlayground',
				array( 'ChatGPT-User' )
			)
		);
	}

	// ------------------------------------------------------------------
	// normalize_host_string() — utm_source variant collapsing
	// ------------------------------------------------------------------
	//
	// Lenient host-match attribution reads `utm_source` directly from WC
	// core; agents send that value in many lexical forms. The normalizer
	// must collapse all of them to a single lookup key.

	/**
	 * @dataProvider host_normalization_provider
	 */
	public function test_normalize_host_string_collapses_variants( string $input, string $expected ): void {
		$this->assertEquals(
			$expected,
			WC_AI_Storefront_UCP_Agent_Header::normalize_host_string( $input )
		);
	}

	public static function host_normalization_provider(): array {
		return array(
			'bare hostname'                => array( 'openai.com', 'openai.com' ),
			'mixed case'                   => array( 'OpenAI.COM', 'openai.com' ),
			'https URL'                    => array( 'https://openai.com', 'openai.com' ),
			'https URL trailing slash'     => array( 'https://openai.com/', 'openai.com' ),
			'https URL with path'          => array( 'https://openai.com/foo/bar', 'openai.com' ),
			'http URL'                     => array( 'http://openai.com', 'openai.com' ),
			'host with port'               => array( 'openai.com:443', 'openai.com' ),
			'URL with port'                => array( 'https://openai.com:443/path', 'openai.com' ),
			'FQDN trailing dot'            => array( 'openai.com.', 'openai.com' ),
			'whitespace padding'           => array( '  openai.com  ', 'openai.com' ),
			'bare host with path'          => array( 'openai.com/', 'openai.com' ),
			'mixed case URL with path'     => array( 'HTTPS://OpenAI.COM/', 'openai.com' ),
			'empty input'                  => array( '', '' ),
			'whitespace-only input'        => array( '   ', '' ),
			'malformed URL'                => array( '://no-scheme', '' ),
			'subdomain preserved'          => array( 'shopping.openai.com', 'shopping.openai.com' ),
			// Non-feature: leading `www.` is NOT stripped. `www.openai.com`
			// is a different DNS name from `openai.com`. Recognizing it
			// requires an explicit `KNOWN_AGENT_HOSTS` entry.
			'www prefix preserved'         => array( 'www.openai.com', 'www.openai.com' ),
			// Protocol-relative URLs (`//host/path`). `wp_parse_url`
			// handles these when given a hint scheme; the bare-host
			// branch's `strpos($value, '/')` would otherwise match
			// at index 0 and silently drop the host.
			'protocol-relative URL'        => array( '//openai.com', 'openai.com' ),
			'protocol-relative with path'  => array( '//openai.com/agent.json', 'openai.com' ),
			'protocol-relative trailing /' => array( '//openai.com/', 'openai.com' ),
			// IPv6 literals contain multiple colons and must NOT have
			// any colon-stripping applied. The unbracketed form is
			// what `wp_parse_url` produces from the bracketed
			// `[2001:db8::1]:443` URL syntax. Pass through unchanged so
			// a future IPv6-only KNOWN_AGENT_HOSTS entry would match.
			'IPv6 literal'                 => array( '2001:db8::1', '2001:db8::1' ),
			'IPv6 with embedded :: shape'  => array( 'fe80::1', 'fe80::1' ),
			'IPv6 URL with port'           => array( 'http://[2001:db8::1]:443/', '2001:db8::1' ),
		);
	}

	// ------------------------------------------------------------------
	// extract_agent_product() — Product/Version format
	// ------------------------------------------------------------------

	public function test_extract_product_returns_lowercased_token_for_product_with_version(): void {
		// UCPPlayground's actual header format. The lowercased token
		// is what KNOWN_AGENT_PRODUCT_NAMES is keyed by.
		$this->assertEquals(
			'ucp-playground',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'UCP-Playground/1.0' )
		);
	}

	public function test_extract_product_returns_lowercased_token_for_bare_product(): void {
		// Spec-permitted but uncommon: product token without version.
		// Still extractable.
		$this->assertEquals(
			'someagent',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'SomeAgent' )
		);
	}

	public function test_extract_product_returns_empty_for_profile_form(): void {
		// Mutual-exclusion guard: a header in `profile="..."` shape
		// must NOT round-trip through the product parser as the literal
		// string "profile". `extract_profile_hostname()` is the right
		// parser for this shape.
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'profile="https://example.com/agent.json"' )
		);
	}

	public function test_extract_product_returns_empty_for_empty_input(): void {
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( '' )
		);
	}

	public function test_extract_product_handles_whitespace(): void {
		// Trim leading/trailing whitespace before regex match — some
		// HTTP intermediaries (and some clients) add whitespace.
		$this->assertEquals(
			'ucp-playground',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( '  UCP-Playground/1.0  ' )
		);
	}

	public function test_extract_product_returns_empty_for_malformed_input(): void {
		// A garbage value with no recognizable token shape returns
		// empty rather than partial data — keeps the contract clean
		// for the caller's "did this yield anything?" branch.
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( '{garbage} <not> [a] product/version' )
		);
	}

	public function test_extract_product_accepts_dotted_version(): void {
		// Real-world example: `Mozilla/5.0` style. Our regex
		// permits dots in the version segment.
		$this->assertEquals(
			'mozilla',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Mozilla/5.0' )
		);
	}

	public function test_extract_product_rejects_empty_version(): void {
		// RFC 7231's `product/version` grammar requires the version
		// to be non-empty when the slash is present. A header like
		// `Agent/` is malformed; we return empty rather than parse
		// it as `agent` (and silently absorb broken clients into
		// stats). Regression guard for the version-segment `+`
		// quantifier — if a future refactor weakens it to `*`,
		// `Agent/` would parse as `agent` and this test would fire.
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Agent/' )
		);
	}

	public function test_extract_product_tolerates_trailing_comment(): void {
		// The exact header from the live saltwarp.shop transcript (#588):
		// claude.ai sent `Claude/4.6 (Anthropic)`. RFC 7231 §5.5.3
		// permits a parenthesized comment; we take the leading product
		// token and discard the comment. Before this branch, the whole
		// match failed and the order attributed as `ucp_unknown`.
		$this->assertEquals(
			'claude',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Claude/4.6 (Anthropic)' )
		);
	}

	public function test_extract_product_tolerates_comment_without_version(): void {
		// Comment is legal on a bare product too (no `/version`).
		$this->assertEquals(
			'claude',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Claude (Anthropic)' )
		);
	}

	public function test_extract_product_takes_leading_token_only_for_browser_ua(): void {
		// The comment body is NOT scanned for a nested product token.
		// A browser-ish UA canonicalizes on its LEADING token
		// (`mozilla`, which isn't a KNOWN_AGENT_PRODUCT_NAME → "Other
		// AI"), never on a bot name buried in the comment. This keeps
		// the comment-tolerance from becoming a backdoor that lets any
		// UA string masquerade as a known agent.
		$this->assertEquals(
			'mozilla',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Mozilla/5.0 (compatible; SomeBot)' )
		);
	}

	public function test_extract_product_does_not_scan_comment_for_known_brand(): void {
		// The load-bearing security witness: a KNOWN brand token
		// (`Claude`) buried in the comment behind an unknown leading
		// token MUST NOT be extracted. This is the case that would
		// fail if a future refactor ever scanned the comment body —
		// the `Mozilla ... SomeBot` test above can't catch that
		// because `SomeBot` isn't a known brand and would bucket as
		// "Other AI" either way. Here, a leaked `claude` would
		// mis-attribute to a real brand, so pinning `mozilla` proves
		// the leading-token-only guarantee.
		$this->assertEquals(
			'mozilla',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Mozilla/5.0 (compatible; Claude)' )
		);
	}

	public function test_extract_product_requires_whitespace_before_comment(): void {
		// The no-space form `Claude/4.6(Anthropic)` is deliberately
		// rejected: RFC 7231 §5.5.3 requires RWS (required whitespace)
		// between a product and a following comment, so the `\s+`
		// before the paren group is intentional. A rejected value
		// falls through to `ucp_unknown` (honest) rather than being
		// mis-parsed. Pinning this documents the choice so a future
		// contributor doesn't silently relax `\s+` to `\s*` without
		// deciding to.
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Claude/4.6(Anthropic)' )
		);
	}

	public function test_extract_product_rejects_count_balanced_nested_comment(): void {
		// `\([^)]*\)` matches a non-nested group only. A count-balanced
		// nested comment closes the character class at the first `)`,
		// leaving a stray `)` that fails `\s*$` → no-match. Regression
		// guard: if someone "improves" comment support with a greedy
		// `\(.*\)`, this flips to a match and the test fires.
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Claude/4.6 (Anthropic (Inc))' )
		);
	}

	public function test_extract_product_rejects_junk_after_comment(): void {
		// The comment must be the ONLY trailing content. Trailing junk
		// after a `(...)` group still produces a no-match rather than
		// partial extraction, preserving the "did this yield anything?"
		// contract for the caller.
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::extract_agent_product( 'Claude/4.6 (Anthropic) {garbage}' )
		);
	}

	// ------------------------------------------------------------------
	// canonicalize_product() — product token → canonical brand
	// ------------------------------------------------------------------

	public function test_canonicalize_product_maps_known_token(): void {
		$this->assertEquals(
			'UCPPlayground',
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_product( 'ucp-playground' )
		);
	}

	public function test_canonicalize_product_buckets_unknown_to_other_ai(): void {
		// An identified-but-unrecognized product still produces a
		// non-empty canonical name (the OTHER_AI bucket) so the
		// caller can distinguish "we have a signal but don't know
		// the brand" from "no signal at all" (empty input).
		$this->assertEquals(
			WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET,
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_product( 'novel-agent' )
		);
	}

	public function test_canonicalize_product_returns_empty_for_empty_input(): void {
		// Empty input is the "no signal" case — distinct from bucketed
		// output because callers treat it as fall-through to the next
		// identification path (e.g. `meta.source` body field).
		$this->assertEquals(
			'',
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_product( '' )
		);
	}

	/**
	 * @dataProvider answer_agent_product_provider
	 */
	public function test_canonicalize_product_maps_answer_agents( string $product, string $brand, string $host ): void {
		// Answer-agent product tokens (#588) resolve to the same
		// canonical brand + hostname as their profile-URL form, so
		// product-form and profile-form orders roll up together.
		$this->assertEquals(
			$brand,
			WC_AI_Storefront_UCP_Agent_Header::canonicalize_product( $product )
		);
		$this->assertEquals(
			$host,
			WC_AI_Storefront_UCP_Agent_Header::PRODUCT_TO_HOSTNAME[ $product ]
		);
		// The resolved hostname MUST be a KNOWN_AGENT_HOSTS key so the
		// lenient bypass-path attribution gate recognizes the same
		// utm_source string. Without this, the product path and the
		// bypass path would fragment into separate stats rows.
		$this->assertArrayHasKey(
			$host,
			WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_HOSTS,
			sprintf( 'PRODUCT_TO_HOSTNAME maps %s → %s, which is not a KNOWN_AGENT_HOSTS key.', $product, $host )
		);
		$this->assertEquals(
			$brand,
			WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_HOSTS[ $host ],
			sprintf( 'Product token %s and host %s disagree on canonical brand.', $product, $host )
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function answer_agent_product_provider(): array {
		return array(
			// Brand-name product tokens.
			'claude'           => array( 'claude', 'Claude', 'claude.ai' ),
			'chatgpt'          => array( 'chatgpt', 'ChatGPT', 'chatgpt.com' ),
			'openai'           => array( 'openai', 'ChatGPT', 'openai.com' ),
			'gemini'           => array( 'gemini', 'Gemini', 'gemini.google.com' ),
			'perplexity'       => array( 'perplexity', 'Perplexity', 'perplexity.ai' ),
			'copilot'          => array( 'copilot', 'Copilot', 'copilot.microsoft.com' ),

			// Crawler user-agent tokens (mirror UA_AGENT_HOSTS) — a
			// client that puts its User-Agent value in UCP-Agent
			// resolves to the same brand + hostname as the brand token.
			'gptbot'           => array( 'gptbot', 'ChatGPT', 'chatgpt.com' ),
			'chatgpt-user'     => array( 'chatgpt-user', 'ChatGPT', 'chatgpt.com' ),
			'oai-searchbot'    => array( 'oai-searchbot', 'ChatGPT', 'chatgpt.com' ),
			'claudebot'        => array( 'claudebot', 'Claude', 'claude.ai' ),
			'claude-user'      => array( 'claude-user', 'Claude', 'claude.ai' ),
			'claude-searchbot' => array( 'claude-searchbot', 'Claude', 'claude.ai' ),
			'perplexitybot'    => array( 'perplexitybot', 'Perplexity', 'perplexity.ai' ),
			'perplexity-user'  => array( 'perplexity-user', 'Perplexity', 'perplexity.ai' ),
		);
	}

	// ------------------------------------------------------------------
	// PRODUCT_TO_HOSTNAME / KNOWN_AGENT_PRODUCT_NAMES key-set parity
	// ------------------------------------------------------------------

	public function test_product_to_hostname_keys_match_known_agent_product_names(): void {
		// Drift guard: every entry in `KNOWN_AGENT_PRODUCT_NAMES` MUST
		// have a corresponding entry in `PRODUCT_TO_HOSTNAME`. Without
		// this parity, a future contributor who adds a new product
		// token to KNOWN_AGENT_PRODUCT_NAMES (e.g. `'claude-cli' =>
		// 'Claude'`) without also adding the hostname mapping
		// (`'claude-cli' => 'claude.ai'`) would silently fragment
		// stats: the order's friendly display name becomes "Claude"
		// (correct) but utm_source becomes the bare product token
		// `claude-cli` instead of the canonical hostname `claude.ai`.
		// Two separate rows in WC's Origin column for what is
		// effectively the same agent.
		//
		// PRODUCT_TO_HOSTNAME may contain MORE keys than
		// KNOWN_AGENT_PRODUCT_NAMES (a hostname mapping for an unknown
		// product is harmless — it just never gets looked up), but it
		// MUST contain at least the same key set. Asserting symmetric
		// difference == [] catches the dominant drift direction.
		$names_keys = array_keys( WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_PRODUCT_NAMES );
		$hosts_keys = array_keys( WC_AI_Storefront_UCP_Agent_Header::PRODUCT_TO_HOSTNAME );

		$missing_hostname = array_diff( $names_keys, $hosts_keys );

		$this->assertEmpty(
			$missing_hostname,
			sprintf(
				'KNOWN_AGENT_PRODUCT_NAMES has %d entries that are missing from PRODUCT_TO_HOSTNAME (%s). '
					. 'Add a hostname mapping for each entry to prevent stats fragmentation.',
				count( $missing_hostname ),
				implode( ', ', $missing_hostname )
			)
		);
	}
}
