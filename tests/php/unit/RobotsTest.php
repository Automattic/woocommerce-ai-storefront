<?php
/**
 * Tests for WC_AI_Storefront_Robots.
 *
 * Focuses on `sanitize_allowed_crawlers()` — the helper responsible for
 * purging stale crawler IDs that accumulate across plugin upgrades when
 * the canonical AI_CRAWLERS list rotates.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class RobotsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `sanitize_text_field` is a WordPress function — stub it to a
		// trim-and-passthrough so we can exercise the sanitizer without a
		// live WP environment. The real function also strips tags / control
		// chars, but `array_intersect` with the AI_CRAWLERS constant
		// rejects anything malformed after trimming regardless.
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Happy path
	// ------------------------------------------------------------------

	public function test_passes_through_known_crawlers(): void {
		$input = [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ];

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ], $result );
	}

	public function test_accepts_full_canonical_list(): void {
		$input = WC_AI_Storefront_Robots::AI_CRAWLERS;

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( WC_AI_Storefront_Robots::AI_CRAWLERS, $result );
	}

	// ------------------------------------------------------------------
	// Stale IDs (the bug that triggered this helper)
	// ------------------------------------------------------------------

	public function test_strips_deprecated_crawler_ids_from_legacy_upgrades(): void {
		// Sanitizer behavior on upgrade paths where the stored
		// allow-list contains entries no longer in AI_CRAWLERS.
		// Originally shipped to cover the "13 of 12" bug around
		// v1.1.0; the fixture has been updated as the canonical
		// list evolved across 1.1.x → 1.6.0.
		//
		// As of 1.6.0 the truly-stale entries merchants might
		// have carried forward:
		//   - `Gemini`: removed in 1.6.0 (phantom entry, never
		//     matched any real crawler)
		//   - `anthropic-ai`: Anthropic-deprecated; replaced by
		//     `ClaudeBot` + `Claude-User` + `Claude-SearchBot`
		//     and never added back
		//
		// Note: `Bytespider`, `CCBot`, and `cohere-ai` were in
		// the pre-v1.1.0 list, briefly removed, and restored in
		// 1.6.0's re-audit. They are now kept, not stripped.
		$input = [
			'GPTBot',          // kept
			'ChatGPT-User',    // kept
			'Gemini',          // dropped (removed in 1.6.0)
			'ClaudeBot',       // kept
			'anthropic-ai',    // dropped (Anthropic-deprecated)
			'Bytespider',      // kept (restored in 1.6.0 re-audit)
			'CCBot',           // kept (restored in 1.6.0 re-audit)
			'Claude-User',     // kept
		];

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame(
			[ 'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'Bytespider', 'CCBot', 'Claude-User' ],
			$result
		);
		$this->assertCount( 6, $result );
	}

	public function test_returns_sequentially_indexed_array(): void {
		// `array_intersect` preserves source keys — if we don't re-index,
		// the REST response JSON-encodes as an object, which breaks the
		// reducer's `.filter()` / `.includes()` calls in the admin UI.
		$input = [ 'Unknown', 'GPTBot', 'Unknown2', 'ClaudeBot' ];

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 0, 1 ], array_keys( $result ) );
	}

	// ------------------------------------------------------------------
	// Malformed input
	// ------------------------------------------------------------------

	public function test_returns_empty_for_non_array_input(): void {
		$this->assertSame( [], WC_AI_Storefront_Robots::sanitize_allowed_crawlers( null ) );
		$this->assertSame( [], WC_AI_Storefront_Robots::sanitize_allowed_crawlers( 'GPTBot' ) );
		$this->assertSame( [], WC_AI_Storefront_Robots::sanitize_allowed_crawlers( 42 ) );
		$this->assertSame( [], WC_AI_Storefront_Robots::sanitize_allowed_crawlers( false ) );
	}

	public function test_strips_injected_garbage(): void {
		$input = [
			'GPTBot',
			'<script>alert(1)</script>',
			'../../etc/passwd',
			'ClaudeBot',
			'',
		];

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 'GPTBot', 'ClaudeBot' ], $result );
	}

	public function test_trims_whitespace_before_matching(): void {
		// Stored data could have trailing spaces from an older stringy
		// sanitizer or a hand-edited option. `sanitize_text_field`
		// trims, so these should still match the canonical constant.
		$input = [ '  GPTBot  ', "ClaudeBot\n", "\tPerplexityBot" ];

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ], $result );
	}

	public function test_empty_array_returns_empty_array(): void {
		// A merchant who unchecked everything ("block all crawlers") must
		// be able to persist that state — the sanitizer cannot quietly
		// refill with defaults.
		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( [] );

		$this->assertSame( [], $result );
	}

	public function test_duplicates_are_preserved_not_deduplicated(): void {
		// Intentional: de-duplication is the caller's responsibility.
		// `robots.txt` emits each entry as its own User-agent block, and
		// duplicates are benign (well-behaved crawlers ignore repeats).
		// Documenting this here so a future "helpful" dedupe doesn't
		// accidentally collapse a legitimate case we haven't foreseen.
		$input = [ 'GPTBot', 'GPTBot', 'ClaudeBot' ];

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 'GPTBot', 'GPTBot', 'ClaudeBot' ], $result );
	}

	// ------------------------------------------------------------------
	// robots.txt rules generation (the `robots_txt` filter callback)
	// ------------------------------------------------------------------

	/**
	 * Stub WordPress/WooCommerce URL/option helpers the generator calls,
	 * and seed enabled syndication with the full crawler roster. Returns
	 * the generated robots.txt content with base WP output passed through.
	 */
	private function generate_robots_output( string $base = "User-agent: *\nDisallow: /wp-admin/\n" ): string {
		WC_AI_Storefront::$test_settings = [
			'enabled'          => 'yes',
			'allowed_crawlers' => [ 'GPTBot', 'ClaudeBot' ],
		];

		Functions\when( 'wc_get_page_permalink' )->alias(
			static function ( string $page ): string {
				$map = [
					'shop'      => 'https://example.com/shop/',
					'cart'      => 'https://example.com/cart/',
					'checkout'  => 'https://example.com/checkout/',
					'myaccount' => 'https://example.com/my-account/',
				];
				return $map[ $page ] ?? '';
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = [] ) {
				if ( 'woocommerce_permalinks' === $key ) {
					return [
						'product_base'  => 'product',
						'category_base' => 'product-category',
					];
				}
				return $default;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Fallback stub for sitemap discovery when the base input
		// has no `Sitemap:` directive. Tests that want to exercise
		// the fallback path leave this as-is; tests passing a base
		// with Sitemap lines never reach this fallback.
		Functions\when( 'get_sitemap_url' )->alias(
			static fn( string $name = 'index' ): string =>
				'https://example.com/wp-sitemap.xml'
		);

		return ( new WC_AI_Storefront_Robots() )->add_ai_crawler_rules( $base, true );
	}

	public function test_allows_ucp_rest_endpoint_for_every_crawler(): void {
		// The UCP adapter endpoints at /wp-json/wc/ucp/ must be
		// explicitly allow-listed per crawler so well-behaved bots know
		// to index them. Without this line, strict crawlers obeying a
		// wildcard /wp-json/ disallow upstream in the file would skip
		// our catalog/search + checkout-sessions routes entirely.
		$output = $this->generate_robots_output();

		// Appears once per allowed crawler (GPTBot + ClaudeBot = 2).
		$this->assertEquals(
			2,
			substr_count( $output, 'Allow: /wp-json/wc/ucp/' ),
			'UCP endpoint allow-list should be emitted once per crawler'
		);
	}

	public function test_ucp_allow_appears_next_to_store_api_allow(): void {
		// Visual grouping matters for merchants reading the generated
		// robots.txt — both are JSON REST surfaces and should sit
		// together to make the "these are machine-readable endpoints"
		// pairing obvious.
		$output = $this->generate_robots_output();

		$store_pos = strpos( $output, 'Allow: /wp-json/wc/store/' );
		$ucp_pos   = strpos( $output, 'Allow: /wp-json/wc/ucp/' );

		$this->assertNotFalse( $store_pos );
		$this->assertNotFalse( $ucp_pos );
		$this->assertGreaterThan( $store_pos, $ucp_pos );

		// And nothing in between the two lines — they're adjacent.
		$between = substr( $output, $store_pos, $ucp_pos - $store_pos );
		$this->assertStringContainsString( "Allow: /wp-json/wc/store/\n", $between );
		$lines_between = substr_count( $between, "\n" );
		$this->assertEquals( 1, $lines_between, 'Store and UCP allows should be adjacent' );
	}

	public function test_crawl_delay_directive_not_emitted(): void {
		// Pre-0.1.9 each per-bot block included `Crawl-delay: 2` as
		// a polite advisory rate hint. Removed in 0.1.9 because:
		//   - Google explicitly doesn't support the directive and
		//     Search Console's robots.txt tester flags it as
		//     "ignored" globally, creating merchant-facing noise.
		//   - Bing's compliance is inconsistent in practice.
		//   - Major AI crawlers (OpenAI, Anthropic, Perplexity)
		//     don't publish their stance on `Crawl-delay`.
		// Hard rate enforcement remains via the plugin's Store API
		// rate limiter (429 + Retry-After), which every well-behaved
		// crawler honors more reliably than the polite advisory.
		//
		// This test locks the regression: any reintroduction of
		// `Crawl-delay` in the AI-bot section must fail tests so
		// the trade-off above is reconsidered explicitly.
		$output = $this->generate_robots_output();

		$this->assertSame(
			0,
			substr_count( $output, 'Crawl-delay:' ),
			'Crawl-delay directive should not appear in robots.txt output'
		);
	}

	public function test_rules_skipped_when_syndication_disabled(): void {
		// Existing pre-1.3.0 invariant: when the merchant has paused
		// syndication, robots.txt doesn't advertise the endpoints at
		// all. Locks in the relationship between the enabled setting
		// and public discoverability.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$output = ( new WC_AI_Storefront_Robots() )->add_ai_crawler_rules(
			"User-agent: *\nDisallow: /wp-admin/\n",
			true
		);

		$this->assertStringNotContainsString( 'Allow: /wp-json/wc/ucp/', $output );
		$this->assertStringNotContainsString( 'WooCommerce AI Syndication', $output );
	}

	public function test_rules_skipped_when_site_is_private(): void {
		// A merchant who flipped Reading → "Discourage search engines"
		// doesn't want AI crawlers pointed at the catalog either.
		// Tested via the $is_public parameter WP passes to the filter.
		WC_AI_Storefront::$test_settings = [
			'enabled'          => 'yes',
			'allowed_crawlers' => [ 'GPTBot' ],
		];

		$output = ( new WC_AI_Storefront_Robots() )->add_ai_crawler_rules(
			"User-agent: *\nDisallow: /wp-admin/\n",
			false  // $is_public
		);

		$this->assertStringNotContainsString( 'Allow: /wp-json/wc/ucp/', $output );
	}

	// ------------------------------------------------------------------
	// 1.5.0: live-browsing vs training-crawler split
	// ------------------------------------------------------------------
	//
	// The classification split is a merchant-facing UX cue — live
	// agents route revenue, training crawlers risk stale answers.
	// These tests lock in the invariants the split relies on:
	// category membership, backward-compatibility of the combined
	// AI_CRAWLERS constant, no duplicates between categories, and
	// disjoint category membership (a crawler can be live OR
	// training but not both).

	public function test_live_browsing_agents_has_expected_members(): void {
		// Order matters (it's how they render in the admin UI).
		// Grouped by ecosystem: foundation models (OpenAI,
		// Anthropic, Perplexity, Apple), then agentic shopping
		// (Amazon Rufus, Klarna), then Google Shopping, then
		// regional search+AI (Asia first, then Europe).
		//
		// The regional bots (ERNIEBot, YiyanBot, WRTNBot,
		// NaverBot, PetalBot, YandexBot) are traditional search
		// crawlers that ALSO power AI features in their markets —
		// "live" covers both user-initiated search and AI-agent
		// fetching, so the classification fits even though these
		// bots predate the modern AI-agent taxonomy.
		$this->assertSame(
			[
				// General-purpose AI assistants (alphabetical).
				'Applebot',
				'ChatGPT-User',
				'Claude-SearchBot',
				'Claude-User',
				'DuckAssistBot',
				'OAI-SearchBot',
				'Perplexity-User',
				'PerplexityBot',
				// Agentic shopping (alphabetical).
				'AmazonBuyForMe',
				'KlarnaBot',
				// Commerce search engines (alphabetical).
				'AdIdxBot',
				'Storebot-Google',
				// Regional — Asia (alphabetical).
				'ERNIEBot',
				'NaverBot',
				'PetalBot',
				'WRTNBot',
				'YiyanBot',
				// Regional — Europe.
				'YandexBot',
			],
			WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS
		);
	}

	public function test_training_crawlers_has_expected_members(): void {
		// Note: the pre-1.6.0 list included a "Gemini" entry that
		// did not correspond to any documented Google user-agent
		// (Google's training bot for Gemini is `Google-Extended`;
		// there's no bot literally named `Gemini`). 1.6.0 dropped
		// it as dead weight — robots.txt had been emitting a
		// `User-agent: Gemini` directive since 1.0.0 that no real
		// crawler ever matched.
		//
		// 1.6.0 also added Bytespider (ByteDance/TikTok), CCBot
		// (CommonCrawl — feeds most open-source LLM corpora), and
		// cohere-ai (Cohere). These are widely-encountered training
		// crawlers merchants need to consciously allow or block.
		$this->assertSame(
			[
				// Alphabetical (case-insensitive). Reordered in 0.6.1
				// for scannability.
				'Amazonbot',
				'Applebot-Extended',
				'Bytespider',
				'CCBot',
				'ClaudeBot',
				'cohere-ai',
				'Google-Extended',
				'GPTBot',
				'Meta-ExternalAgent',
				'Microsoft-BingBot-Extended',
			],
			WC_AI_Storefront_Robots::TRAINING_CRAWLERS
		);
	}

	// ------------------------------------------------------------------
	// Fresh-install default vs. preserved opt-out (1.6.0 review fix)
	// ------------------------------------------------------------------
	//
	// `resolve_allowed_crawlers()` encodes the core policy: commerce-
	// safe default for new installs, full preservation of merchant
	// choices on upgrades. These tests lock in the distinction
	// between "never configured" and "explicitly configured to
	// block all" — the pre-fix code treated them identically via
	// `! empty()`, silently reverting a merchant's explicit opt-out
	// on every subsequent request.

	public function test_fresh_install_returns_live_browsing_only_default(): void {
		// Empty settings array → no prior configuration → commerce-safe
		// default. Training crawlers must NOT be present so merchants
		// get the protection-by-default posture out of the box.
		$result = WC_AI_Storefront_Robots::resolve_allowed_crawlers( [] );

		$this->assertSame( WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS, $result );

		foreach ( WC_AI_Storefront_Robots::TRAINING_CRAWLERS as $training_bot ) {
			$this->assertNotContains(
				$training_bot,
				$result,
				"Training crawler $training_bot should NOT be in the fresh-install default"
			);
		}
	}

	public function test_explicit_empty_allowed_crawlers_is_preserved(): void {
		// This is the consent-regression guard. A merchant who clicks
		// "Clear selection" in the admin UI saves `[]`. The resolver
		// must return `[]`, not silently revert to the fresh-install
		// default. Pre-fix code used `! empty()` which treated empty
		// array identically to "key missing."
		$result = WC_AI_Storefront_Robots::resolve_allowed_crawlers(
			[ 'allowed_crawlers' => [] ]
		);

		$this->assertSame(
			[],
			$result,
			'Explicit empty array (merchant opt-out) must be preserved, not reverted to defaults'
		);
	}

	public function test_stored_allowed_crawlers_list_is_preserved(): void {
		// Happy path for existing installs with saved selections —
		// the resolver must return the stored list verbatim.
		$stored = [ 'GPTBot', 'ClaudeBot', 'Claude-User' ];

		$result = WC_AI_Storefront_Robots::resolve_allowed_crawlers(
			[ 'allowed_crawlers' => $stored ]
		);

		$this->assertSame( $stored, $result );
	}

	public function test_non_array_stored_value_degrades_to_empty_list(): void {
		// Defensive: if the stored option value somehow corrupts to
		// a non-array (DB migration glitch, manual SQL edit), treat
		// as "no crawlers" rather than crashing or filling with the
		// fresh-install default (which would be wrong — the key IS
		// present, it's just garbled).
		$result = WC_AI_Storefront_Robots::resolve_allowed_crawlers(
			[ 'allowed_crawlers' => 'not-an-array' ]
		);

		$this->assertSame( [], $result );
	}

	public function test_phantom_gemini_entry_is_removed(): void {
		// Regression guard: if a future refactor accidentally
		// resurrects the `Gemini` entry, this fires. The entry
		// never matched a real crawler; re-adding it would just
		// emit a useless robots.txt directive again.
		$this->assertNotContains( 'Gemini', WC_AI_Storefront_Robots::TRAINING_CRAWLERS );
		$this->assertNotContains( 'Gemini', WC_AI_Storefront_Robots::AI_CRAWLERS );
	}

	public function test_ai_crawlers_is_union_of_live_training_and_test(): void {
		// Backward compat: AI_CRAWLERS is the pre-1.5.0 public
		// constant that external callers and the sanitizer have
		// been consuming since 1.0.0. It must exactly equal the
		// concatenation of all category lists in declaration order —
		// otherwise `sanitize_allowed_crawlers()` (which intersects
		// against AI_CRAWLERS) would reject valid category members.
		// TEST_CRAWLERS was added in the 0.2.x series for validation
		// tools (e.g. UCPPlayground) and follows training in the
		// concatenation order.
		$expected = array_merge(
			WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS,
			WC_AI_Storefront_Robots::TRAINING_CRAWLERS,
			WC_AI_Storefront_Robots::TEST_CRAWLERS
		);

		$this->assertSame(
			$expected,
			WC_AI_Storefront_Robots::AI_CRAWLERS,
			'AI_CRAWLERS must equal LIVE_BROWSING_AGENTS + TRAINING_CRAWLERS + TEST_CRAWLERS in order.'
		);
	}

	public function test_categories_are_disjoint(): void {
		// A crawler is in exactly one category — never two. If a future
		// addition ends up in multiple lists, the admin UI renders a
		// duplicate checkbox (confusing) and the render `filter` logic
		// selects the first category only (hiding the duplicate in the
		// other group). Regression catches both side effects across all
		// three pairs (live∩training, live∩test, training∩test).
		$this->assertSame(
			[],
			array_intersect(
				WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS,
				WC_AI_Storefront_Robots::TRAINING_CRAWLERS
			),
			'LIVE_BROWSING_AGENTS and TRAINING_CRAWLERS must be disjoint.'
		);
		$this->assertSame(
			[],
			array_intersect(
				WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS,
				WC_AI_Storefront_Robots::TEST_CRAWLERS
			),
			'LIVE_BROWSING_AGENTS and TEST_CRAWLERS must be disjoint.'
		);
		$this->assertSame(
			[],
			array_intersect(
				WC_AI_Storefront_Robots::TRAINING_CRAWLERS,
				WC_AI_Storefront_Robots::TEST_CRAWLERS
			),
			'TRAINING_CRAWLERS and TEST_CRAWLERS must be disjoint.'
		);
	}

	public function test_ai_crawlers_has_no_duplicates(): void {
		$this->assertSame(
			count( WC_AI_Storefront_Robots::AI_CRAWLERS ),
			count( array_unique( WC_AI_Storefront_Robots::AI_CRAWLERS ) ),
			'Duplicate crawler IDs would emit redundant User-agent rules in robots.txt.'
		);
	}

	// ------------------------------------------------------------------
	// 1.6.1: sitemap visibility, explicit opt-out, CORS headers
	// ------------------------------------------------------------------
	//
	// Three defensive additions prompted by cross-agent review:
	//   1. Sitemap `Allow:` inside each named block (defense against
	//      crawlers that over-scope their User-agent parsing)
	//   2. Explicit `Disallow: /` block for opted-out AI bots
	//      (converts implicit "silent, fall through to *" into
	//      explicit merchant intent — matters most for the training-
	//      default-off policy where 9 training crawlers are unchecked)
	//   3. Sitemap re-emitted at end of section (accommodates parsers
	//      that expect sitemap declarations at the bottom)
	//
	// Plus CORS/nosniff headers on the robots.txt response itself
	// (confirmed blocker for Perplexity's browsing tool, same fix
	// family as llms.txt in 1.4.1).

	public function test_sitemap_paths_not_emitted_as_per_bot_allow_rules(): void {
		// Pre-0.1.9, this method emitted `Allow: /sitemap.xml` (and
		// related paths) inside every per-bot block, justified as
		// "defense against crawlers that only parse directives within
		// their own User-agent group." The defense was misdirected:
		// `Allow:` only matters when there's a `Disallow:` that would
		// otherwise block the path, and none of the per-bot
		// `Disallow:` rules touch sitemap paths. With every bot in
		// `LIVE_BROWSING_AGENTS` × 4 sitemap paths in
		// `COMMON_SITEMAP_PATHS`, the result was dozens of redundant
		// lines on a typical merchant's robots.txt (observed on a
		// merchant's test deployment).
		//
		// 0.1.9 dropped the per-block sitemap Allows. Sitemap
		// discovery still works via the top-level `Sitemap:`
		// directives emitted by WP core / Jetpack / SEO plugins
		// above this section. (Pre-0.1.13 we also re-emitted those
		// directives at the bottom of our section; that re-emission
		// was removed in 0.1.13 — see the deletion-rationale block
		// in `class-wc-ai-storefront-robots.php`.) This test locks
		// the regression: per-bot `Allow: <sitemap-path>` lines must
		// not reappear without a deliberate design discussion.
		//
		// Tightened from a 4-string deny-list to a regex match —
		// catches reintroduction at a non-canonical path too (e.g.
		// `/custom-sitemap.xml` from a future SEO plugin's
		// hardcoded list). The four canonical paths are still
		// asserted explicitly for diagnostic clarity.
		$base = "Sitemap: https://example.com/sitemap.xml\n"
			. "Sitemap: https://example.com/news-sitemap.xml\n"
			. "User-agent: *\nDisallow: /wp-admin/\n";

		$output = $this->generate_robots_output( $base );

		// Per-bot `Allow:` rules that include "sitemap" anywhere in
		// the path indicate the redundant emission has returned.
		// The regex matches `Allow: <whitespace> <anything>sitemap<anything>`
		// at line start, multiline-mode, anchored end-of-line.
		$this->assertSame(
			0,
			preg_match_all( '/^Allow:\s+\S*sitemap\S*$/m', $output ),
			'No per-bot Allow rule should reference any sitemap-shaped path'
		);

		// Spot-check the four canonical paths the previous
		// implementation emitted, for diagnostic clarity if the
		// regex assertion ever fires.
		$this->assertStringNotContainsString( 'Allow: /sitemap.xml', $output );
		$this->assertStringNotContainsString( 'Allow: /news-sitemap.xml', $output );
		$this->assertStringNotContainsString( 'Allow: /sitemap_index.xml', $output );
		$this->assertStringNotContainsString( 'Allow: /wp-sitemap.xml', $output );
	}

	public function test_no_bottom_of_section_sitemap_reemission(): void {
		// Pre-0.1.13 our plugin re-emitted top-level `Sitemap:`
		// directives at the bottom of our AI section, justified as
		// "defense against ordering-sensitive parsers." Two failure
		// modes drove the deletion in 0.1.13:
		//
		//   1. The fallback to `get_sitemap_url('index')` fired when
		//      the input had no `Sitemap:` directive at filter-time
		//      (because Jetpack et al. emit theirs via the
		//      `do_robotstxt` action, AFTER our `robots_txt` filter
		//      runs). On `pierorocca.com` that produced a fictional
		//      `wp-sitemap.xml` URL when WP-core sitemap was
		//      disabled by Jetpack — pointing crawlers at a 404.
		//
		//   2. RFC 9309 specifies `Sitemap:` as a top-level directive
		//      whose position is not order-sensitive; the
		//      "ordering defense" was theoretical, not load-bearing.
		//
		// This test locks the regression: any future re-introduction
		// of bottom-of-section `Sitemap:` emission must fail tests
		// so the trade-off is reconsidered explicitly. It pins the
		// case where the input has Jetpack-style top-of-file
		// directives — those should appear once (at the top, as the
		// input had them) and not be duplicated at the bottom.
		$base = "Sitemap: https://example.com/sitemap.xml\n"
			. "User-agent: *\nDisallow: /wp-admin/\n";

		$output = $this->generate_robots_output( $base );

		$this->assertEquals(
			1,
			substr_count( $output, 'Sitemap: https://example.com/sitemap.xml' ),
			'Sitemap URL should appear exactly once (at the top from input), not duplicated at the bottom of our AI section'
		);
	}

	public function test_no_sitemap_directive_emitted_when_input_has_none(): void {
		// Companion to the test above: covers the OTHER failure mode
		// the 0.1.13 deletion fixed. Pre-0.1.13, when the input
		// robots.txt had no `Sitemap:` directive at filter-time
		// (because Jetpack et al. emit via `do_robotstxt` AFTER our
		// filter runs), the bottom-of-section emit fell back to
		// `get_sitemap_url('index')` and produced a fictional
		// `wp-sitemap.xml` URL. On `pierorocca.com` that pointed
		// crawlers at a 404 because Jetpack disables WP-core's
		// sitemap. 0.1.13 dropped the entire fallback path.
		//
		// This test seeds an empty (no Sitemap directive) base and
		// asserts the output ALSO contains no `Sitemap:` directive
		// from our AI section. Our plugin neither emits nor
		// fabricates a sitemap URL when the input doesn't already
		// declare one — Jetpack's `do_robotstxt` emission still
		// flows through to crawlers, just not visible to our
		// filter at this point.
		$base = "User-agent: *\nDisallow: /wp-admin/\n"; // no Sitemap directive

		$output = $this->generate_robots_output( $base );

		$this->assertSame(
			0,
			substr_count( $output, 'Sitemap:' ),
			'No Sitemap: directive should be emitted by our AI section when the input had none'
		);
	}

	public function test_opted_out_bots_get_explicit_disallow_block(): void {
		// The fixture has `allowed_crawlers = [GPTBot, ClaudeBot]`.
		// Every other bot in AI_CRAWLERS should be opted out.
		$output = $this->generate_robots_output();

		// Spot-check: training crawlers not in the allowed list.
		$this->assertStringContainsString( 'User-agent: Bytespider', $output );
		$this->assertStringContainsString( 'User-agent: CCBot', $output );

		// Live bots not in the allowed list.
		$this->assertStringContainsString( 'User-agent: ChatGPT-User', $output );
		$this->assertStringContainsString( 'User-agent: PerplexityBot', $output );

		// One `Disallow: /` line covers the whole group (RFC 9309
		// §2.2.1 allows multiple User-agent lines per rule group).
		$this->assertMatchesRegularExpression(
			'/User-agent:.*\n.*User-agent:.*\n.*Disallow: \/\n/s',
			$output,
			'Opt-out block should use grouped User-agent lines with one Disallow'
		);
	}

	public function test_no_opt_out_block_when_all_bots_allowed(): void {
		// If the merchant has every known crawler checked, there's
		// nothing to opt out — the opt-out block must not appear.
		WC_AI_Storefront::$test_settings = [
			'enabled'          => 'yes',
			'allowed_crawlers' => WC_AI_Storefront_Robots::AI_CRAWLERS,
		];
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn( string $page ): string => 'https://example.com/' . $page . '/'
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = [] ): mixed =>
				'woocommerce_permalinks' === $key
					? [ 'product_base' => 'product', 'category_base' => 'product-category' ]
					: $default
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_sitemap_url' )->justReturn( '' );

		$output = ( new WC_AI_Storefront_Robots() )->add_ai_crawler_rules(
			"User-agent: *\nDisallow: /wp-admin/\n",
			true
		);

		$this->assertStringNotContainsString(
			'Explicit opt-out for AI bots',
			$output,
			'No opt-out comment/block when zero bots are opted out'
		);
	}

	public function test_empty_allowed_crawlers_opts_out_every_ai_bot(): void {
		// "Clear selection" merchant path: zero allowed crawlers.
		// Every AI bot in AI_CRAWLERS should appear in the explicit
		// opt-out block — strongest possible "no AI" signal.
		WC_AI_Storefront::$test_settings = [
			'enabled'          => 'yes',
			'allowed_crawlers' => [],
		];
		Functions\when( 'wc_get_page_permalink' )->alias(
			static fn( string $page ): string => 'https://example.com/' . $page . '/'
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = [] ): mixed =>
				'woocommerce_permalinks' === $key
					? [ 'product_base' => 'product', 'category_base' => 'product-category' ]
					: $default
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_sitemap_url' )->justReturn( '' );

		$output = ( new WC_AI_Storefront_Robots() )->add_ai_crawler_rules(
			"User-agent: *\nDisallow: /wp-admin/\n",
			true
		);

		// Every AI bot appears exactly once in the opt-out group.
		foreach ( WC_AI_Storefront_Robots::AI_CRAWLERS as $bot ) {
			$this->assertStringContainsString(
				"User-agent: {$bot}",
				$output,
				"Opted-out bot $bot should appear in the Disallow block"
			);
		}

		// Exactly one `Disallow: /` terminates the block (not one
		// per bot — grouped syntax).
		$this->assertEquals(
			1,
			substr_count( $output, "Disallow: /\n" ),
			'Single Disallow: / for the grouped opt-out block'
		);
	}

	public function test_sitemap_allow_not_emitted_when_syndication_disabled(): void {
		// Sanity: the gates for syndication-disabled / site-private
		// cases already bail before the Allow directives. Same gate
		// covers Sitemap Allow / opt-out blocks.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$base   = "Sitemap: https://example.com/sitemap.xml\nUser-agent: *\n";
		$output = ( new WC_AI_Storefront_Robots() )->add_ai_crawler_rules( $base, true );

		$this->assertStringNotContainsString( 'Allow: /sitemap.xml', $output );
		$this->assertStringNotContainsString( 'Explicit opt-out', $output );
	}

	// ------------------------------------------------------------------
	// CORS + nosniff headers on robots.txt (do_robotstxt hook)
	// ------------------------------------------------------------------

	// Note: pre-0.1.9 there were two tests here covering robots.txt behavior
	// around `COMMON_SITEMAP_PATHS` — specifically the per-block `Allow:`
	// emission of every entry in that constant, and dedupe with discovered
	// paths. Both deleted when robots.txt stopped emitting per-block sitemap
	// allows in 0.1.9. The `COMMON_SITEMAP_PATHS` constant itself remains —
	// it's still used by `WC_AI_Storefront_Llms_Txt::discover_sitemap_urls()`
	// for HEAD-probing candidate paths to list in llms.txt — it's just no
	// longer consumed by robots.txt. See
	// `test_sitemap_paths_not_emitted_as_per_bot_allow_rules` above for the
	// regression guard that locks the new robots.txt behavior.

	public function test_cors_headers_method_is_hooked_on_do_robotstxt(): void {
		// Can't test the actual `header()` calls without process
		// isolation (PHP headers-sent state leaks between tests).
		// Lock in the method's existence + signature so a future
		// refactor that renames or removes it fires this test.
		$this->assertTrue(
			method_exists( WC_AI_Storefront_Robots::class, 'send_cors_headers' ),
			'send_cors_headers method should exist for the do_robotstxt hook'
		);

		$reflection = new ReflectionMethod( WC_AI_Storefront_Robots::class, 'send_cors_headers' );
		$this->assertTrue( $reflection->isPublic() );
		$this->assertSame(
			0,
			$reflection->getNumberOfParameters(),
			'Method hooks `do_robotstxt` action which passes no arguments'
		);
	}
}
