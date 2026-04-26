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
		return [
			'OpenAI ChatGPT'      => [ 'chatgpt.com', 'ChatGPT' ],
			'OpenAI corporate'    => [ 'openai.com', 'ChatGPT' ],
			'Anthropic Claude'    => [ 'claude.ai', 'Claude' ],
			'Anthropic corporate' => [ 'anthropic.com', 'Claude' ],
			'Google Gemini'       => [ 'gemini.google.com', 'Gemini' ],
			'Google DeepMind'     => [ 'deepmind.google', 'Gemini' ],
			'Microsoft Copilot'   => [ 'copilot.microsoft.com', 'Copilot' ],
			'Microsoft Bing'      => [ 'bing.com', 'Copilot' ],
			'Perplexity'          => [ 'perplexity.ai', 'Perplexity' ],
			'Apple Siri'          => [ 'siri.apple.com', 'Siri' ],
			'Amazon Rufus'        => [ 'rufus.amazon.com', 'Rufus' ],
			'Klarna'              => [ 'klarna.com', 'Klarna' ],
			'You.com'             => [ 'you.com', 'You' ],
			'Kagi'                => [ 'kagi.com', 'Kagi' ],
		];
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
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( '', [] )
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
				[]
			)
		);
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( 'You', [] )
		);
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed( 'Kagi', [] )
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
				[ 'ChatGPT-User' ]
			)
		);
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				[ 'OAI-SearchBot' ]
			)
		);
	}

	public function test_is_agent_allowed_known_brand_with_all_crawler_ids_present(): void {
		// Both ChatGPT crawler IDs in the allow-list — straightforward
		// allow.
		$this->assertTrue(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				[ 'ChatGPT-User', 'OAI-SearchBot' ]
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
				[ 'PerplexityBot', 'KlarnaBot' ]
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
				[]
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
				[ 'chatgpt-user' ] // wrong case
			)
		);
		$this->assertFalse(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'ChatGPT',
				[ ' ChatGPT-User ' ] // padded whitespace
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
				[ 'UCPPlayground' ]
			)
		);
		$this->assertFalse(
			WC_AI_Storefront_UCP_Agent_Header::is_agent_allowed(
				'UCPPlayground',
				[ 'ChatGPT-User' ]
			)
		);
	}
}
