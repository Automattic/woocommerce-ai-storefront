<?php
/**
 * Tests for WC_AI_Syndication_UCP_Agent_Header.
 *
 * Covers the v1 parser: extract a hostname from a UCP-Agent header
 * whose format follows the well-formed spec example:
 *
 *     UCP-Agent: profile="https://agent.example.com/profiles/shopping.json"
 *
 * Real RFC 8941 parsing is deferred — if we ever need other fields
 * from the header (`version`, etc.), add tests for those then.
 *
 * @package WooCommerce_AI_Syndication
 */

class UcpAgentHeaderTest extends \PHPUnit\Framework\TestCase {

	// ------------------------------------------------------------------
	// Happy path
	// ------------------------------------------------------------------

	public function test_extracts_hostname_from_well_formed_header(): void {
		$header = 'profile="https://agent.example.com/profiles/shopping.json"';

		$this->assertEquals(
			'agent.example.com',
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_extracts_hostname_with_http_scheme(): void {
		// Less common but technically valid — agents testing locally
		// might send http://. Parser should still return the hostname.
		$header = 'profile="http://localhost:3000/profile.json"';

		$this->assertEquals(
			'localhost',
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_extracts_hostname_from_subdomain_url(): void {
		$header = 'profile="https://shopping-agent.sub.openai.com/ucp.json"';

		$this->assertEquals(
			'shopping-agent.sub.openai.com',
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	// ------------------------------------------------------------------
	// Missing / absent inputs
	// ------------------------------------------------------------------

	public function test_returns_empty_for_empty_string(): void {
		$this->assertEquals(
			'',
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( '' )
		);
	}

	public function test_returns_empty_when_profile_field_absent(): void {
		// Header present but doesn't have a `profile=` field. Example:
		// a partially-populated header with only metadata fields.
		$header = 'version="2026-04-08"';

		$this->assertEquals(
			'',
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header )
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
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_returns_empty_for_malformed_url_in_profile(): void {
		// profile= is there, quoted, but the value isn't a valid URL.
		// parse_url returns something implementation-defined for junk;
		// our parser should return empty string to avoid passing junk
		// downstream to the utm_source builder.
		$header = 'profile="not-a-url"';

		$result = WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header );

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
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header )
		);
	}

	public function test_extracts_hostname_when_profile_is_later_field(): void {
		// Agents may send other fields before `profile`. Parser must
		// still find it.
		$header = 'version="2026-04-08", profile="https://agent.example.com/profile.json"';

		$this->assertEquals(
			'agent.example.com',
			WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header )
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
			WC_AI_Syndication_UCP_Agent_Header::FALLBACK_SOURCE
		);
	}
}
