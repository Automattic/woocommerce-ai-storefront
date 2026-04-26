<?php
/**
 * Tests for WC_AI_Storefront_UCP_REST_Controller::check_agent_access().
 *
 * The permission_callback wired to the UCP commerce routes
 * (catalog/search, catalog/lookup, checkout-sessions). Translates
 * the merchant's `allowed_crawlers` setting into a per-request
 * decision: allow + proceed to handler, or 403 + WP_Error.
 *
 * Behavioral contract:
 *   1. Missing or empty UCP-Agent header → ALLOW. Pre-UCP traffic
 *      and anonymous manifest fetches must still pass through.
 *   2. UCP-Agent present but unparseable (no profile= field, etc.) →
 *      ALLOW. The header is advisory; we don't penalize malformed
 *      clients at the gate.
 *   3. Parseable UCP-Agent that doesn't canonicalize to a known
 *      brand → ALLOW. "Other AI" and brands without crawler
 *      equivalents (You.com, Kagi) bypass the gate by design.
 *   4. Known canonical brand whose mapped crawler IDs are all
 *      missing from `allowed_crawlers` → DENY (WP_Error 403). This
 *      is the primary production bug fix: a merchant who turned
 *      ChatGPT off in the AI Crawlers UI should not see ChatGPT
 *      requests succeed at the UCP REST endpoint.
 *   5. Known canonical brand with at least one mapped crawler ID
 *      present in `allowed_crawlers` → ALLOW.
 *
 * The gate reads `WC_AI_Storefront::get_settings()` to source the
 * merchant's allowed_crawlers list — the test suite uses
 * `WC_AI_Storefront::$test_settings` to control the value without
 * touching the options table.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpAgentAccessGateTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_UCP_REST_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->controller = new WC_AI_Storefront_UCP_REST_Controller();

		// Logger::debug() runs every denied request through
		// `apply_filters( 'wc_ai_storefront_debug', false )` to check
		// the debug-enabled gate. Two pieces have to line up here:
		//
		//   1. Stub `apply_filters` to return false so the filter
		//      short-circuits to "logging off" — that skips the
		//      `error_log` call, which Brain Monkey can't intercept
		//      cleanly because it's a PHP internal.
		//
		//   2. Reset the Logger's static enabled-cache. The cache is
		//      populated on the FIRST `is_enabled()` call per process
		//      and persists across tests — if a prior test (in this
		//      file or any other) already triggered a `true` evaluation,
		//      our stub never runs, the gate proceeds to `error_log`,
		//      and we get unstubbed-call errors. Reset guarantees the
		//      first denied-path call in this test re-runs the filter
		//      and picks up our stub.
		WC_AI_Storefront_Logger::reset_cache();
		Functions\when( 'apply_filters' )->justReturn( false );

		// `__()` passthrough: the gate's WP_Error message uses i18n
		// but tests assert on the literal string content.
		Functions\when( '__' )->returnArg();

		// Reset between tests so leakage from earlier cases doesn't
		// influence the gate's reading of `allowed_crawlers`.
		WC_AI_Storefront::$test_settings = [];
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		// Reset the Logger cache on the way out too — leaving a
		// `false` cached value behind from this test's stub would
		// suppress legit debug logging in any subsequent test that
		// expects to capture log output.
		WC_AI_Storefront_Logger::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a WP_REST_Request with a UCP-Agent header pre-set.
	 * `null` means no header at all (request lacks the field).
	 */
	private function make_request( ?string $ucp_agent ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/catalog/search' );
		if ( null !== $ucp_agent ) {
			$req->set_header( 'ucp-agent', $ucp_agent );
		}
		return $req;
	}

	// ------------------------------------------------------------------
	// Outcome 1: missing header → allow
	// ------------------------------------------------------------------

	public function test_no_ucp_agent_header_passes(): void {
		// No header → pre-UCP traffic. Allow unconditionally so we
		// don't break Store API consumers, manifest crawlers, or any
		// non-UCP client.
		WC_AI_Storefront::$test_settings = [ 'allowed_crawlers' => [] ];

		$result = $this->controller->check_agent_access( $this->make_request( null ) );

		$this->assertTrue( $result );
	}

	public function test_empty_ucp_agent_header_passes(): void {
		// Header was set but to an empty string. Treat as missing.
		WC_AI_Storefront::$test_settings = [ 'allowed_crawlers' => [] ];

		$result = $this->controller->check_agent_access( $this->make_request( '' ) );

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Outcome 2: unparseable header → allow
	// ------------------------------------------------------------------

	public function test_unparseable_ucp_agent_header_passes(): void {
		// `profile=` field absent → extract_profile_hostname() returns
		// '', the gate short-circuits to allow. Don't penalize
		// malformed clients.
		WC_AI_Storefront::$test_settings = [ 'allowed_crawlers' => [] ];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'version="1"; tool="curl"' )
		);

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Outcome 3: unknown brand → allow ("Other AI" pass-through)
	// ------------------------------------------------------------------

	public function test_unknown_host_passes_as_other_ai(): void {
		// Hostname not in KNOWN_AGENT_HOSTS canonicalizes to
		// "Other AI". The open-spec wedge: any agent with a parseable
		// UCP-Agent header gets in unless we have an explicit
		// configuration surface to block its brand.
		WC_AI_Storefront::$test_settings = [ 'allowed_crawlers' => [] ];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://novel-vendor.example/agent.json"' )
		);

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Outcome 4: known brand, all crawler IDs missing → 403
	// ------------------------------------------------------------------

	public function test_known_brand_blocked_when_no_mapped_crawler_ids_in_allow_list(): void {
		// THE production bug: the merchant turned ChatGPT off (its
		// crawler IDs are absent from allowed_crawlers) yet a UCP
		// request from openai.com succeeded anyway. The fix: gate
		// returns WP_Error 403, WP REST short-circuits.
		WC_AI_Storefront::$test_settings = [
			'allowed_crawlers' => [ 'PerplexityBot', 'KlarnaBot' ],
		];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://openai.com/chatgpt-shopping.json"' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ucp_agent_blocked', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	public function test_block_message_includes_canonical_brand_name(): void {
		// Merchant-facing error context: the agent (or its log
		// pipeline) needs to know WHICH brand was blocked, not just
		// that a generic 403 happened. Pin the canonical name into
		// the message so support tickets are actionable.
		WC_AI_Storefront::$test_settings = [ 'allowed_crawlers' => [] ];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://openai.com/agent.json"' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString(
			'ChatGPT',
			$result->get_error_message(),
			'Block message should name the canonical brand the merchant disabled.'
		);
	}

	public function test_known_brand_blocked_when_allow_list_is_empty(): void {
		// Empty allow-list = merchant turned every crawler off. Don't
		// let any branded UCP request slip through.
		WC_AI_Storefront::$test_settings = [ 'allowed_crawlers' => [] ];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://anthropic.com/claude-shopping.json"' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_blocks_klarna_when_klarna_crawler_id_missing(): void {
		// Defense-in-depth: it's not just ChatGPT-vs-not-ChatGPT.
		// Every brand in the map must respect its own row in the
		// allow-list. Picking Klarna here because its KlarnaBot ID
		// is the SOLE crawler equivalent — if the gate had a bug
		// where it short-circuited on a single map entry, this test
		// would catch it.
		WC_AI_Storefront::$test_settings = [
			'allowed_crawlers' => [ 'ChatGPT-User', 'Claude-User' ],
		];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://klarna.com/shopping-agent.json"' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ------------------------------------------------------------------
	// Outcome 5: known brand, at least one ID present → allow
	// ------------------------------------------------------------------

	public function test_known_brand_passes_when_one_mapped_crawler_id_in_allow_list(): void {
		// Merchant has one of ChatGPT's two crawler IDs in their
		// allow-list — the gate treats that as "this brand is
		// approved" (OR-semantics across mapped IDs).
		WC_AI_Storefront::$test_settings = [
			'allowed_crawlers' => [ 'ChatGPT-User' ],
		];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://openai.com/chatgpt.json"' )
		);

		$this->assertTrue( $result );
	}

	public function test_known_brand_passes_when_all_mapped_crawler_ids_in_allow_list(): void {
		WC_AI_Storefront::$test_settings = [
			'allowed_crawlers' => [ 'ChatGPT-User', 'OAI-SearchBot' ],
		];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://openai.com/chatgpt.json"' )
		);

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Settings-source quirks
	// ------------------------------------------------------------------

	public function test_falls_back_to_live_browsing_default_when_setting_absent(): void {
		// `allowed_crawlers` key entirely missing from settings (older
		// installs that haven't been touched by the modern UI yet).
		// The gate falls back to LIVE_BROWSING_AGENTS — which contains
		// ChatGPT-User — so a fresh-install user with no UI changes
		// still sees ChatGPT pass through. Honors "secure-by-default"
		// for new brands but doesn't break existing default behavior.
		WC_AI_Storefront::$test_settings = []; // no allowed_crawlers key

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://openai.com/chatgpt.json"' )
		);

		$this->assertTrue( $result );
	}

	public function test_falls_back_to_live_browsing_default_when_setting_not_array(): void {
		// `allowed_crawlers` is the wrong type (e.g. someone pushed a
		// stringified value into options). The gate must not crash
		// or treat the value as a single-element list — fall back to
		// the LIVE_BROWSING_AGENTS default.
		WC_AI_Storefront::$test_settings = [ 'allowed_crawlers' => 'not-an-array' ];

		$result = $this->controller->check_agent_access(
			$this->make_request( 'profile="https://openai.com/chatgpt.json"' )
		);

		$this->assertTrue( $result );
	}
}
