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

		// `wp_unslash` is the inverse of WP's automatic magic-quotes
		// slashing on superglobals. In a real request the value has
		// already been slashed; in tests we set $_SERVER directly with
		// raw values, so an identity stub is correct.
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		// Reset between tests so a previous test's UA doesn't leak into
		// the next via the shared $_SERVER superglobal.
		unset( $_SERVER['HTTP_USER_AGENT'] );

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
		// The fixture is updated as the canonical list rotates across
		// releases — see git history for which release introduced or
		// retired each entry, since the comment-as-version-history
		// here would inevitably drift.
		//
		// The truly-stale entry merchants might have carried forward
		// today is `Gemini` (a phantom entry that never matched any
		// real crawler — Google's training bot is `Google-Extended`).
		//
		// Note: `anthropic-ai` was previously dropped as "Anthropic-
		// deprecated" but Anthropic continues to send it in real-world
		// traffic. Restored to the canonical list and is now a kept
		// entry, not stripped. `Bytespider`, `CCBot`, and `cohere-ai`
		// followed a similar drop-then-restore arc and remain kept.
		$input = [
			'GPTBot',          // kept
			'ChatGPT-User',    // kept
			'Gemini',          // dropped (phantom entry)
			'ClaudeBot',       // kept
			'anthropic-ai',    // kept (restored to canonical list)
			'Bytespider',      // kept (restored to canonical list)
			'CCBot',           // kept (restored to canonical list)
			'Claude-User',     // kept
		];

		$result = WC_AI_Storefront_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame(
			[ 'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'anthropic-ai', 'Bytespider', 'CCBot', 'Claude-User' ],
			$result
		);
		$this->assertCount( 7, $result );
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

	public function test_allows_ucp_rest_endpoint_in_consolidated_block(): void {
		// The UCP adapter endpoints at /wp-json/wc/ucp/ must be
		// explicitly allow-listed so well-behaved bots know to index
		// them. Without this line, strict crawlers obeying a wildcard
		// /wp-json/ disallow upstream in the file would skip our
		// catalog/search + checkout-sessions routes entirely.
		//
		// As of 0.8.8 the opt-in block emits a single consolidated rule
		// group for all allowed crawlers (RFC 9309 §2.2.1) rather than
		// one duplicated block per bot, so this allow appears exactly
		// once regardless of how many crawlers are allowed.
		$output = $this->generate_robots_output();

		$this->assertEquals(
			1,
			substr_count( $output, 'Allow: /wp-json/wc/ucp/' ),
			'UCP endpoint allow-list should be emitted exactly once for the grouped opt-in block'
		);
	}

	public function test_opt_in_block_uses_grouped_user_agent_form(): void {
		// Pin the consolidation: every allowed crawler appears as its
		// own `User-agent:` line followed by a single shared rule body,
		// not as a duplicated full block per bot. Pre-0.8.8 a default
		// install emitted ~200 lines here; the consolidated form drops
		// that to ~30 without changing the rules any crawler sees.
		$output = $this->generate_robots_output();

		// Both fixture bots present.
		$this->assertStringContainsString( 'User-agent: GPTBot', $output );
		$this->assertStringContainsString( 'User-agent: ClaudeBot', $output );

		// Allow rules appear exactly once for the whole group.
		$this->assertEquals(
			1,
			substr_count( $output, "Allow: /llms.txt\n" ),
			'Allow: /llms.txt appears once for the consolidated group'
		);
		$this->assertEquals(
			1,
			substr_count( $output, "Allow: /.well-known/ucp\n" ),
			'Allow: /.well-known/ucp appears once for the consolidated group'
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
		// Grouped: AI search & discovery (search-index crawlers,
		// dual-purpose crawlers, live user-session agents — all
		// alphabetical within each sub-flavour), then regional
		// Asia (alphabetical), then regional Europe.
		//
		// Regional bots are traditional search crawlers that also
		// power AI features in their markets — "live" covers both
		// user-initiated search and AI-agent fetching.
		//
		// KlarnaBot removed: no such user-agent token exists in the
		// wild. Klarna uses merchant feed-push for indexing; its
		// in-app browser sends `Klarna/YY.WW.BUILD` in a mobile
		// WebKit UA — a human session, not a crawler.
		// AmazonBuyForMe removed: represents checkout-in-AI model
		// this plugin does not support — routes purchases externally
		// rather than to the merchant's own checkout.
		// ClaudeBot + GPTBot moved here from TRAINING_CRAWLERS:
		// dual-purpose crawlers that both index-build and feed live
		// AI answer surfaces (Claude.ai, ChatGPT).
		// Amazonbot moved here from TRAINING_CRAWLERS: indexing
		// prerequisite for Amazon Rufus (live AI shopping surface).
		$this->assertSame(
			[
				// AI search & discovery (alphabetical).
				'Applebot',
				'Bingbot',
				'BraveBot',
				'ChatGPT-User',
				'Claude-SearchBot',
				'Claude-User',
				'ClaudeBot',
				'DuckAssistBot',
				'DuckDuckBot',
				'Googlebot',
				'GPTBot',
				'Mistralai-User',
				'Mojeekbot',
				'OAI-SearchBot',
				'Perplexity-User',
				'PerplexityBot',
				'Phindbot',
				'YouBot',
				'AdIdxBot',
				'Amazonbot',
				'Pinterestbot',
				'Storebot-Google',
			],
			WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS
		);
	}

	public function test_regional_crawlers_has_expected_members(): void {
		$this->assertSame(
			[
				// Asia — alphabetical.
				'Baiduspider',
				'coccocbot-web',
				'Daumoa',
				'ERNIEBot',
				'NaverBot',
				'PetalBot',
				'WRTNBot',
				'Yeti',
				'YiyanBot',
				// Europe — alphabetical.
				'Qwantify',
				'SeznamBot',
				'YandexBot',
			],
			WC_AI_Storefront_Robots::REGIONAL_CRAWLERS
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
				// Alphabetical (case-insensitive) for scannability.
				// anthropic-ai (Anthropic legacy crawler still seen in
				// real logs) and Diffbot (Knowledge Graph licensed by
				// LLM vendors as training input) are recent additions —
				// see git log for the introducing commit if you need
				// the version anchor.
				// Note: Amazonbot was here pre-#275 but moved to
				// LIVE_BROWSING_AGENTS as it is the indexing
				// prerequisite for Amazon Rufus (live AI shopping).
				// ClaudeBot + GPTBot moved to LIVE_BROWSING_AGENTS:
				// dual-purpose crawlers that feed live answer surfaces
				// as well as training corpora.
				'anthropic-ai',
				'Applebot-Extended',
				'Bytespider',
				'CCBot',
				'cohere-ai',
				'Diffbot',
				'Google-Extended',
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
		// default. Regional, training, and test crawlers must NOT be
		// present so merchants get the protection-by-default posture.
		$result = WC_AI_Storefront_Robots::resolve_allowed_crawlers( [] );

		$this->assertSame( WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS, $result );

		foreach ( WC_AI_Storefront_Robots::REGIONAL_CRAWLERS as $bot ) {
			$this->assertNotContains(
				$bot,
				$result,
				"Regional crawler $bot should NOT be in the fresh-install default"
			);
		}
		foreach ( WC_AI_Storefront_Robots::TRAINING_CRAWLERS as $bot ) {
			$this->assertNotContains(
				$bot,
				$result,
				"Training crawler $bot should NOT be in the fresh-install default"
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

	public function test_stored_allowed_crawlers_list_preserved_with_seo_bots_unioned_in(): void {
		// Existing installs' saved selections are preserved. Bingbot and
		// Googlebot are always force-unioned in so upgrading stores whose
		// saved list predates those IDs can't accidentally emit Disallow:/
		// for search indexing bots (SEO-deindex regression, Comment 4).
		$stored = array( 'GPTBot', 'ClaudeBot', 'Claude-User' );

		$result = WC_AI_Storefront_Robots::resolve_allowed_crawlers(
			array( 'allowed_crawlers' => $stored )
		);

		foreach ( $stored as $bot ) {
			$this->assertContains( $bot, $result, "Stored bot $bot must still be present" );
		}
		$this->assertContains( 'Bingbot', $result, 'Bingbot must be force-added to prevent search-indexing block' );
		$this->assertContains( 'Googlebot', $result, 'Googlebot must be force-added to prevent search-indexing block' );
	}

	public function test_upgrade_migration_adds_bingbot_and_googlebot_to_pre_existing_list(): void {
		// Upgrade scenario: a saved list from before Bingbot/Googlebot
		// were added. The resolver must inject them so robots.txt does not
		// emit `Disallow: /` for the store's primary search indexing bots.
		$pre_upgrade = array( 'GPTBot', 'ChatGPT-User', 'ClaudeBot' );

		$result = WC_AI_Storefront_Robots::resolve_allowed_crawlers(
			array( 'allowed_crawlers' => $pre_upgrade )
		);

		$this->assertContains( 'Bingbot', $result );
		$this->assertContains( 'Googlebot', $result );
		// No duplicates.
		$this->assertSame( array_unique( $result ), $result );
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

	// ------------------------------------------------------------------
	// JS ↔ PHP parity: KNOWN_CRAWLERS in endpoint-info.js must match
	// AI_CRAWLERS in robots.php (membership, order-independent).
	// ------------------------------------------------------------------

	public function test_known_crawlers_js_matches_ai_crawlers_php(): void {
		// PHP is the authoritative source — `sanitize_allowed_crawlers()`
		// intersects merchant input against AI_CRAWLERS server-side, so
		// drift creates two silent failure modes:
		//
		// 1. JS knows about a bot PHP doesn't: the merchant ticks the
		//    checkbox in the admin UI, settings save round-trips through
		//    `sanitize_allowed_crawlers()`, the unknown ID is stripped,
		//    and the merchant's choice silently disappears with no UI
		//    signal.
		// 2. PHP knows about a bot JS doesn't: the bot rule lands in
		//    robots.txt (driven server-side off AI_CRAWLERS) but the
		//    merchant has no checkbox to toggle it. Effectively
		//    permanently-on-or-off depending on default state, with no
		//    way for the merchant to change it.
		//
		// Both modes were caught only by author discipline pre-0.6.1.
		// This test enforces parity at PR time.
		//
		// Membership equality (order-independent): the JS list uses a
		// sub-grouped declaration order optimized for the admin UI scan
		// (general-purpose → agentic shopping → commerce search →
		// regional). The PHP union order is its own concatenation
		// (LIVE + TRAINING + TEST) — order overlap is incidental, not
		// contractual. Locking order would force the JS to track PHP's
		// union order even if a UX reorganization made sense.
		$js_path = WC_AI_STOREFRONT_PLUGIN_PATH . '/client/settings/ai-storefront/endpoint-info.js';
		$this->assertFileExists(
			$js_path,
			'endpoint-info.js must be present at the expected path; if this fails, either the file was moved or the parity check loses its source of truth.'
		);

		$contents = file_get_contents( $js_path );
		$this->assertNotFalse( $contents, 'Could not read endpoint-info.js.' );

		// Locate the KNOWN_CRAWLERS array body. The `\n];\n` terminator
		// is the established pattern in the file (every const-array
		// declaration in the project closes with that token sequence)
		// and it can't appear inside the array body because all string
		// literals are single-quoted. Anchored on `const` so we don't
		// match a partial-name like `KNOWN_CRAWLERS_FALLBACK` if one
		// gets added later.
		$start_marker = 'const KNOWN_CRAWLERS = [';
		$start        = strpos( $contents, $start_marker );
		$this->assertNotFalse(
			$start,
			'`const KNOWN_CRAWLERS = [` not found in endpoint-info.js — was it renamed?'
		);
		$end = strpos( $contents, "\n];\n", $start );
		$this->assertNotFalse(
			$end,
			'Could not find end of KNOWN_CRAWLERS array (`\n];\n` terminator missing).'
		);
		$block = substr( $contents, $start, $end - $start );

		// Extract `id: 'XXX'` occurrences. The pattern matches both
		// inline (`{ id: 'X', ... }`) and multi-line (`{\n\tid: 'X',\n}`)
		// object-literal styles. Single-quote-only is intentional: the
		// project's eslint config enforces single quotes for string
		// literals.
		$matched = preg_match_all( "/id:\s*'([^']+)'/", $block, $matches );
		$this->assertNotFalse( $matched, 'preg_match_all failed to scan KNOWN_CRAWLERS.' );
		$this->assertGreaterThan(
			0,
			$matched,
			'Zero crawler IDs extracted from KNOWN_CRAWLERS — pattern may have regressed.'
		);

		$js_ids = $matches[1];
		sort( $js_ids );

		$php_ids = WC_AI_Storefront_Robots::AI_CRAWLERS;
		sort( $php_ids );

		$this->assertSame(
			$php_ids,
			$js_ids,
			"KNOWN_CRAWLERS in endpoint-info.js must contain the same set of IDs as AI_CRAWLERS in robots.php. \n"
			. 'JS-only IDs are silently stripped on settings save; PHP-only IDs are bots merchants cannot toggle.'
		);
	}

	public function test_ai_crawlers_is_union_of_all_categories(): void {
		// AI_CRAWLERS is the pre-1.5.0 public constant that external
		// callers and the sanitizer have been consuming since 1.0.0.
		// It must exactly equal the concatenation of all category
		// lists in declaration order — otherwise
		// `sanitize_allowed_crawlers()` (which intersects against
		// AI_CRAWLERS) would reject valid category members.
		// Order: LIVE ++ REGIONAL ++ TRAINING ++ TEST.
		$expected = array_merge(
			WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS,
			WC_AI_Storefront_Robots::REGIONAL_CRAWLERS,
			WC_AI_Storefront_Robots::TRAINING_CRAWLERS,
			WC_AI_Storefront_Robots::TEST_CRAWLERS
		);

		$this->assertSame(
			$expected,
			WC_AI_Storefront_Robots::AI_CRAWLERS,
			'AI_CRAWLERS must equal LIVE_BROWSING_AGENTS + REGIONAL_CRAWLERS + TRAINING_CRAWLERS + TEST_CRAWLERS in order.'
		);
	}

	public function test_categories_are_disjoint(): void {
		// A crawler is in exactly one category — never two. If a future
		// addition ends up in multiple lists, the admin UI renders a
		// duplicate checkbox (confusing) and the render `filter` logic
		// selects the first category only (hiding the duplicate in the
		// other group). Regression catches all six pairs.
		$live     = WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS;
		$regional = WC_AI_Storefront_Robots::REGIONAL_CRAWLERS;
		$training = WC_AI_Storefront_Robots::TRAINING_CRAWLERS;
		$test     = WC_AI_Storefront_Robots::TEST_CRAWLERS;

		$this->assertSame( [], array_intersect( $live, $regional ),  'LIVE and REGIONAL must be disjoint.' );
		$this->assertSame( [], array_intersect( $live, $training ),  'LIVE and TRAINING must be disjoint.' );
		$this->assertSame( [], array_intersect( $live, $test ),      'LIVE and TEST must be disjoint.' );
		$this->assertSame( [], array_intersect( $regional, $training ), 'REGIONAL and TRAINING must be disjoint.' );
		$this->assertSame( [], array_intersect( $regional, $test ),  'REGIONAL and TEST must be disjoint.' );
		$this->assertSame( [], array_intersect( $training, $test ),  'TRAINING and TEST must be disjoint.' );
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

	// ------------------------------------------------------------------
	// detect_crawler_from_ua() — two-stage match
	//
	// Stage 1: substring search against AI_CRAWLERS, longest-first.
	// Stage 2: fall back to RFC 7231 product-token extraction so legitimate
	//          UCP-aware clients (UCPScanner, UCPCheckerBot) and any other
	//          identifying programmatic client are still recorded. Empty
	//          UA returns '' — call sites use that as the no-record signal.
	// ------------------------------------------------------------------

	public function test_detect_crawler_from_ua_returns_empty_when_ua_missing(): void {
		unset( $_SERVER['HTTP_USER_AGENT'] );

		$this->assertSame( '', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_returns_empty_when_ua_blank(): void {
		$_SERVER['HTTP_USER_AGENT'] = '';

		$this->assertSame( '', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_matches_known_crawler_in_realistic_ua(): void {
		// Real-world ClaudeBot UA — token sits inside a Mozilla preamble
		// and a comment block. Stage 1 stripos must find it regardless of
		// position, otherwise stage 2 would misidentify the request as
		// `Mozilla` (the leading product token).
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';

		$this->assertSame( 'ClaudeBot', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_prefers_longest_known_token(): void {
		// `Microsoft-BingBot-Extended` contains `Bingbot` as a substring;
		// longest-first sorting must prevent the shorter token from
		// shadowing the canonical one.
		$_SERVER['HTTP_USER_AGENT'] = 'Microsoft-BingBot-Extended/1.0 (+http://www.bing.com)';

		$this->assertSame( 'Microsoft-BingBot-Extended', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_extracts_product_token_for_ucp_scanner(): void {
		// The exact UA observed in production from a UCP discovery
		// scanner not in AI_CRAWLERS. Stage 1 misses; stage 2 must
		// return the product token so the analytics page records the
		// hit instead of silently dropping it.
		$_SERVER['HTTP_USER_AGENT'] = 'UCPScanner/1.0 (+https://ucpscanner.com)';

		$this->assertSame( 'UCPScanner', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_extracts_product_token_for_ucp_checker_bot(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'UCPCheckerBot/1.0 (+https://ucpchecker.com/methodology)';

		$this->assertSame( 'UCPCheckerBot', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_extracts_product_token_for_curl(): void {
		// curl with no -A flag sends `curl/8.x`. Developers and CI
		// scripts hitting the URL count as discovery events too, per
		// the "any hit is a hit" principle for public surfaces.
		$_SERVER['HTTP_USER_AGENT'] = 'curl/8.7.1';

		$this->assertSame( 'curl', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_extracts_mozilla_for_browser_visit(): void {
		// Real browser visits (a developer or merchant previewing the
		// URL) record as `Mozilla`. Intentional: low frequency, useful
		// forensic signal that a human is poking at the URL. Filtering
		// browser tokens would lose that visibility.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

		$this->assertSame( 'Mozilla', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_returns_empty_when_ua_starts_with_unparseable_chars(): void {
		// Defensive: a UA starting with a non-letter (digits, slash,
		// punctuation, space) doesn't match the leading-product-token
		// regex. Falls through to '' rather than recording garbage.
		$_SERVER['HTTP_USER_AGENT'] = '/1.0';

		$this->assertSame( '', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

	public function test_detect_crawler_from_ua_strips_version_from_extracted_token(): void {
		// Stage 2 returns just the token, not the version. Two version
		// variants of the same scanner must roll up under one analytics
		// row. Verified separately to lock the regex grouping behavior.
		$_SERVER['HTTP_USER_AGENT'] = 'UCPScanner/2.4.1-beta (+https://ucpscanner.com)';

		$this->assertSame( 'UCPScanner', WC_AI_Storefront_Robots::detect_crawler_from_ua() );
	}

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
