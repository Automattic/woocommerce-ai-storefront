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

	public function test_strips_deprecated_crawler_ids_from_pre_v1_1_upgrades(): void {
		// Replays the exact scenario from the "13 of 12" bug: a store
		// that enabled syndication before v1.1.0 rotated the crawler
		// list has four deprecated IDs stored alongside current ones.
		$input = [
			'GPTBot',          // kept
			'ChatGPT-User',    // kept
			'Bytespider',      // dropped (removed in v1.1.0)
			'CCBot',           // dropped (removed in v1.1.0)
			'ClaudeBot',       // kept
			'anthropic-ai',    // dropped (removed in v1.1.0)
			'cohere-ai',       // dropped (removed in v1.1.0)
			'Claude-User',     // kept (added in v1.1.0 by merchant)
		];

		$result = WC_AI_Syndication_Robots::sanitize_allowed_crawlers( $input );

		$this->assertSame(
			[ 'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User' ],
			$result
		);
		$this->assertCount( 4, $result );
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
}
