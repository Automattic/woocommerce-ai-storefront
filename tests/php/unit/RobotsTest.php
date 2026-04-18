<?php
/**
 * Tests for WC_AI_Syndication_Robots.
 *
 * Focuses on `sanitize_allowed_crawlers()` — the helper responsible for
 * purging stale crawler IDs that accumulate across plugin upgrades when
 * the canonical AI_CRAWLERS list rotates.
 *
 * @package WooCommerce_AI_Syndication
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

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ], $result );
	}

	public function test_accepts_full_canonical_list(): void {
		$input = WC_AI_Syndication_Robots::AI_CRAWLERS;

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( WC_AI_Syndication_Robots::AI_CRAWLERS, $result );
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

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

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

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 0, 1 ], array_keys( $result ) );
	}

	// ------------------------------------------------------------------
	// Malformed input
	// ------------------------------------------------------------------

	public function test_returns_empty_for_non_array_input(): void {
		$this->assertSame( [], WC_AI_Syndication_Robots::sanitize_allowed_crawlers( null ) );
		$this->assertSame( [], WC_AI_Syndication_Robots::sanitize_allowed_crawlers( 'GPTBot' ) );
		$this->assertSame( [], WC_AI_Syndication_Robots::sanitize_allowed_crawlers( 42 ) );
		$this->assertSame( [], WC_AI_Syndication_Robots::sanitize_allowed_crawlers( false ) );
	}

	public function test_strips_injected_garbage(): void {
		$input = [
			'GPTBot',
			'<script>alert(1)</script>',
			'../../etc/passwd',
			'ClaudeBot',
			'',
		];

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 'GPTBot', 'ClaudeBot' ], $result );
	}

	public function test_trims_whitespace_before_matching(): void {
		// Stored data could have trailing spaces from an older stringy
		// sanitizer or a hand-edited option. `sanitize_text_field`
		// trims, so these should still match the canonical constant.
		$input = [ '  GPTBot  ', "ClaudeBot\n", "\tPerplexityBot" ];

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame( [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ], $result );
	}

	public function test_empty_array_returns_empty_array(): void {
		// A merchant who unchecked everything ("block all crawlers") must
		// be able to persist that state — the sanitizer cannot quietly
		// refill with defaults.
		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( [] );

		$this->assertSame( [], $result );
	}

	public function test_duplicates_are_preserved_not_deduplicated(): void {
		// Intentional: de-duplication is the caller's responsibility.
		// `robots.txt` emits each entry as its own User-agent block, and
		// duplicates are benign (well-behaved crawlers ignore repeats).
		// Documenting this here so a future "helpful" dedupe doesn't
		// accidentally collapse a legitimate case we haven't foreseen.
		$input = [ 'GPTBot', 'GPTBot', 'ClaudeBot' ];

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

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
		WC_AI_Syndication::$test_settings = [
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

		return ( new WC_AI_Syndication_Robots() )->add_ai_crawler_rules( $base, true );
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

	public function test_crawl_delay_emitted_once_per_crawler(): void {
		// Crawl-delay is advisory; value is the CRAWL_DELAY_SECONDS
		// constant. Must appear exactly once per User-agent block —
		// one directive per bot, placed before the Allow/Disallow
		// rules so crawlers see the hint before they fetch anything.
		$output = $this->generate_robots_output();

		// GPTBot + ClaudeBot in the fixture = 2 Crawl-delay lines.
		$this->assertEquals(
			2,
			substr_count( $output, 'Crawl-delay: ' ),
			'Crawl-delay should appear once per allowed crawler'
		);
		$this->assertStringContainsString(
			'Crawl-delay: ' . WC_AI_Syndication_Robots::CRAWL_DELAY_SECONDS,
			$output
		);
	}

	public function test_crawl_delay_appears_before_allow_rules(): void {
		// Per robots.txt convention, directives that constrain
		// behavior (Crawl-delay, Disallow) are emitted alongside
		// the allowances so crawlers have the full picture in the
		// one User-agent block. Crawl-delay specifically should be
		// the first line after User-agent so a crawler parsing
		// top-down sees the rate hint before any fetch decision.
		$output = $this->generate_robots_output();

		$ua_pos       = strpos( $output, 'User-agent: GPTBot' );
		$delay_pos    = strpos( $output, 'Crawl-delay: ', $ua_pos );
		$first_allow  = strpos( $output, 'Allow:', $ua_pos );

		$this->assertNotFalse( $delay_pos );
		$this->assertNotFalse( $first_allow );
		$this->assertLessThan( $first_allow, $delay_pos, 'Crawl-delay must precede the first Allow' );
	}

	public function test_rules_skipped_when_syndication_disabled(): void {
		// Existing pre-1.3.0 invariant: when the merchant has paused
		// syndication, robots.txt doesn't advertise the endpoints at
		// all. Locks in the relationship between the enabled setting
		// and public discoverability.
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'no' ];

		$output = ( new WC_AI_Syndication_Robots() )->add_ai_crawler_rules(
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
		WC_AI_Syndication::$test_settings = [
			'enabled'          => 'yes',
			'allowed_crawlers' => [ 'GPTBot' ],
		];

		$output = ( new WC_AI_Syndication_Robots() )->add_ai_crawler_rules(
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
				'ChatGPT-User',
				'OAI-SearchBot',
				'Claude-User',
				'Claude-SearchBot',
				'PerplexityBot',
				'Perplexity-User',
				'Applebot',
				'AmazonBuyForMe',
				'KlarnaBot',
				'Storebot-Google',
				'ERNIEBot',
				'YiyanBot',
				'WRTNBot',
				'NaverBot',
				'PetalBot',
				'YandexBot',
			],
			WC_AI_Syndication_Robots::LIVE_BROWSING_AGENTS
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
				'GPTBot',
				'Google-Extended',
				'ClaudeBot',
				'Meta-ExternalAgent',
				'Amazonbot',
				'Applebot-Extended',
				'Bytespider',
				'CCBot',
				'cohere-ai',
			],
			WC_AI_Syndication_Robots::TRAINING_CRAWLERS
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
		$result = WC_AI_Syndication_Robots::resolve_allowed_crawlers( [] );

		$this->assertSame( WC_AI_Syndication_Robots::LIVE_BROWSING_AGENTS, $result );

		foreach ( WC_AI_Syndication_Robots::TRAINING_CRAWLERS as $training_bot ) {
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
		$result = WC_AI_Syndication_Robots::resolve_allowed_crawlers(
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

		$result = WC_AI_Syndication_Robots::resolve_allowed_crawlers(
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
		$result = WC_AI_Syndication_Robots::resolve_allowed_crawlers(
			[ 'allowed_crawlers' => 'not-an-array' ]
		);

		$this->assertSame( [], $result );
	}

	public function test_phantom_gemini_entry_is_removed(): void {
		// Regression guard: if a future refactor accidentally
		// resurrects the `Gemini` entry, this fires. The entry
		// never matched a real crawler; re-adding it would just
		// emit a useless robots.txt directive again.
		$this->assertNotContains( 'Gemini', WC_AI_Syndication_Robots::TRAINING_CRAWLERS );
		$this->assertNotContains( 'Gemini', WC_AI_Syndication_Robots::AI_CRAWLERS );
	}

	public function test_ai_crawlers_is_union_of_live_and_training(): void {
		// Backward compat: AI_CRAWLERS is the pre-1.5.0 public
		// constant that external callers and the sanitizer have
		// been consuming since 1.0.0. It must exactly equal the
		// concatenation of the two new category lists — otherwise
		// `sanitize_allowed_crawlers()` (which intersects against
		// AI_CRAWLERS) would reject valid category members.
		$expected = array_merge(
			WC_AI_Syndication_Robots::LIVE_BROWSING_AGENTS,
			WC_AI_Syndication_Robots::TRAINING_CRAWLERS
		);

		$this->assertSame(
			$expected,
			WC_AI_Syndication_Robots::AI_CRAWLERS,
			'AI_CRAWLERS must equal LIVE_BROWSING_AGENTS + TRAINING_CRAWLERS in order.'
		);
	}

	public function test_categories_are_disjoint(): void {
		// A crawler is either a live agent or a training crawler,
		// never both. If a future addition ends up in both lists,
		// the admin UI renders a duplicate checkbox (confusing) and
		// the render `filter` logic selects the first category only
		// (hiding the duplicate in the other group). Regression
		// catches both side effects.
		$intersection = array_intersect(
			WC_AI_Syndication_Robots::LIVE_BROWSING_AGENTS,
			WC_AI_Syndication_Robots::TRAINING_CRAWLERS
		);

		$this->assertSame( [], $intersection );
	}

	public function test_ai_crawlers_has_no_duplicates(): void {
		$this->assertSame(
			count( WC_AI_Syndication_Robots::AI_CRAWLERS ),
			count( array_unique( WC_AI_Syndication_Robots::AI_CRAWLERS ) ),
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

	public function test_sitemap_allow_emitted_inside_each_named_block(): void {
		// Base robots.txt with multiple Sitemap directives (mirroring
		// what a Yoast/Rank Math site looks like). Each is discovered
		// and translated to a path-level Allow inside every named
		// AI-bot block.
		$base = "Sitemap: https://example.com/sitemap.xml\n"
			. "Sitemap: https://example.com/news-sitemap.xml\n"
			. "User-agent: *\nDisallow: /wp-admin/\n";

		$output = $this->generate_robots_output( $base );

		// Two allowed bots (GPTBot + ClaudeBot per the fixture) × 2
		// sitemaps = 4 emissions. Counting by the path string avoids
		// false positives on the top-level Sitemap directives (which
		// use full URLs, not paths).
		$this->assertEquals(
			2,
			substr_count( $output, 'Allow: /sitemap.xml' ),
			'Sitemap path should be allowed inside each named block'
		);
		$this->assertEquals(
			2,
			substr_count( $output, 'Allow: /news-sitemap.xml' ),
			'Secondary sitemap path should also be allowed per block'
		);
	}

	public function test_sitemap_directive_reemitted_at_end_of_section(): void {
		// Industry convention + defense against ordering-sensitive
		// parsers — Sitemap declarations are duplicated at the end
		// of our appended section, not just left at the top.
		$base = "Sitemap: https://example.com/sitemap.xml\n"
			. "User-agent: *\nDisallow: /wp-admin/\n";

		$output = $this->generate_robots_output( $base );

		// Top-of-file + end-of-our-section = 2 occurrences of the
		// full URL form (`Sitemap: https://...`). The path-only
		// Allow emissions don't count.
		$this->assertEquals(
			2,
			substr_count( $output, 'Sitemap: https://example.com/sitemap.xml' ),
			'Sitemap URL should appear both at top (from input) and at bottom (re-emitted)'
		);
	}

	public function test_sitemap_allow_falls_back_to_wp_core_sitemap_when_none_in_input(): void {
		// Sites without Yoast/Rank Math/etc. rely on WP core's
		// `get_sitemap_url( 'index' )` which emits `/wp-sitemap.xml`.
		// The extract helper falls back to that when no `Sitemap:`
		// lines exist in the input robots.txt.
		$base = "User-agent: *\nDisallow: /wp-admin/\n"; // no Sitemap directive

		$output = $this->generate_robots_output( $base );

		$this->assertStringContainsString(
			'Allow: /wp-sitemap.xml',
			$output,
			'WP core fallback sitemap should be allowed per-block when no external sitemap is declared'
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
		WC_AI_Syndication::$test_settings = [
			'enabled'          => 'yes',
			'allowed_crawlers' => WC_AI_Syndication_Robots::AI_CRAWLERS,
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

		$output = ( new WC_AI_Syndication_Robots() )->add_ai_crawler_rules(
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
		WC_AI_Syndication::$test_settings = [
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

		$output = ( new WC_AI_Syndication_Robots() )->add_ai_crawler_rules(
			"User-agent: *\nDisallow: /wp-admin/\n",
			true
		);

		// Every AI bot appears exactly once in the opt-out group.
		foreach ( WC_AI_Syndication_Robots::AI_CRAWLERS as $bot ) {
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
		WC_AI_Syndication::$test_settings = [ 'enabled' => 'no' ];
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$base   = "Sitemap: https://example.com/sitemap.xml\nUser-agent: *\n";
		$output = ( new WC_AI_Syndication_Robots() )->add_ai_crawler_rules( $base, true );

		$this->assertStringNotContainsString( 'Allow: /sitemap.xml', $output );
		$this->assertStringNotContainsString( 'Explicit opt-out', $output );
	}

	// ------------------------------------------------------------------
	// CORS + nosniff headers on robots.txt (do_robotstxt hook)
	// ------------------------------------------------------------------

	public function test_common_sitemap_paths_always_emitted_even_without_discovery(): void {
		// Real-world case from pierorocca.com: Yoast SEO emits
		// Sitemap directives via `do_robotstxt` action (direct
		// echo) rather than the `robots_txt` filter, so our
		// regex discovery sees nothing. Without the hardcoded
		// fallback the merchant's `/sitemap.xml` URL never
		// makes it into an `Allow:` directive.
		//
		// With COMMON_SITEMAP_PATHS, `/sitemap.xml` is always
		// in each named block regardless of what we could or
		// couldn't discover — covering this common deployment.
		$base = "User-agent: *\nDisallow: /wp-admin/\n";

		$output = $this->generate_robots_output( $base );

		$this->assertStringContainsString( 'Allow: /sitemap.xml', $output );
		$this->assertStringContainsString( 'Allow: /sitemap_index.xml', $output );
		$this->assertStringContainsString( 'Allow: /wp-sitemap.xml', $output );
		$this->assertStringContainsString( 'Allow: /news-sitemap.xml', $output );
	}

	public function test_discovered_sitemap_paths_not_duplicated_with_hardcoded(): void {
		// Union + dedupe: when `/sitemap.xml` is both discovered
		// from the input AND in COMMON_SITEMAP_PATHS, it should
		// only appear once per named block — not twice.
		$base = "Sitemap: https://example.com/sitemap.xml\n"
			. "User-agent: *\nDisallow: /wp-admin/\n";

		$output = $this->generate_robots_output( $base );

		// Count per-block occurrences. Two allowed bots in the
		// fixture, so we expect exactly 2 instances of
		// `Allow: /sitemap.xml` — not 4 (which would indicate
		// discovered + hardcoded both emitted).
		$this->assertEquals(
			2,
			substr_count( $output, 'Allow: /sitemap.xml' ),
			'Duplicate /sitemap.xml from discovered + hardcoded should be deduped'
		);
	}

	public function test_cors_headers_method_is_hooked_on_do_robotstxt(): void {
		// Can't test the actual `header()` calls without process
		// isolation (PHP headers-sent state leaks between tests).
		// Lock in the method's existence + signature so a future
		// refactor that renames or removes it fires this test.
		$this->assertTrue(
			method_exists( WC_AI_Syndication_Robots::class, 'send_cors_headers' ),
			'send_cors_headers method should exist for the do_robotstxt hook'
		);

		$reflection = new ReflectionMethod( WC_AI_Syndication_Robots::class, 'send_cors_headers' );
		$this->assertTrue( $reflection->isPublic() );
		$this->assertSame(
			0,
			$reflection->getNumberOfParameters(),
			'Method hooks `do_robotstxt` action which passes no arguments'
		);
	}
}
