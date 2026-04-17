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
		// Group by vendor: OpenAI, Anthropic, Perplexity, Apple.
		// Every entry must have vendor documentation confirming
		// live-query / user-initiated semantics — the `-User` and
		// `-SearchBot` suffixes follow vendor conventions. Plain
		// `Applebot` is an exception (predates the `-Extended`
		// convention) but is still live per Apple's docs.
		$this->assertSame(
			[
				'ChatGPT-User',
				'OAI-SearchBot',
				'Claude-User',
				'Claude-SearchBot',
				'PerplexityBot',
				'Perplexity-User',
				'Applebot',
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
}
