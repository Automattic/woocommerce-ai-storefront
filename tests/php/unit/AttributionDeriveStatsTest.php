<?php
/**
 * Tests for WC_AI_Storefront_Attribution::derive_stats().
 *
 * The helper takes pre-aggregated totals + a per-agent breakdown
 * (the shape returned by the `$wpdb->get_results()` loop inside
 * `get_stats()`) and returns the AOV + top-agent fields that the
 * React Overview tab consumes. Extracted as a static helper so the
 * math is unit-testable without mocking `$wpdb` — the SQL query
 * itself stays integration-tested via manual smoke and CI.
 *
 * What this file locks:
 *
 * - AOV is computed from totals, not averaged from per-agent AOVs
 *   (the unweighted-mean-of-weighted-means trap). Two tests pin this:
 *   one with equal-volume agents (where both methods agree) and one
 *   with unequal volumes (where they diverge — naive averaging gives
 *   the wrong answer).
 *
 * - Top-agent tie-break is `orders DESC, revenue DESC`. The dedicated
 *   tie-break test pins both branches of the spaceship operator: the
 *   primary sort and the revenue secondary.
 *
 * - `share_percent` denominator is the AI-orders total, not the
 *   all-store total. The card's subvalue reads "X% of AI orders" —
 *   if this denominator drifted to all_orders, the card would still
 *   render but the percentage would mean something completely
 *   different and silently mislead the merchant.
 *
 * - Empty `by_agent` returns `top_agent === null` so the React side
 *   renders an em-dash, matching every other card's empty-state.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AttributionDeriveStatsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// AOV
	// ------------------------------------------------------------------

	public function test_aov_is_zero_when_no_orders(): void {
		$result = WC_AI_Storefront_Attribution::derive_stats( 0, 0.0, [] );

		// Strict equality on float zero — `===` distinguishes 0.0
		// from `null`, which would also be a "no AOV" signal but
		// would change the JSON shape downstream.
		$this->assertSame( 0.0, $result['ai_aov'] );
	}

	public function test_aov_divides_revenue_by_orders(): void {
		$by_agent = [
			'chatgpt' => [ 'orders' => 2, 'revenue' => 50.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 2, 50.00, $by_agent );

		$this->assertSame( 25.00, $result['ai_aov'] );
	}

	public function test_aov_uses_totals_not_per_agent_averages(): void {
		// Two agents, very different volumes. Per-agent AOVs:
		//   chatgpt: $1000 / 1 order = $1000
		//   gemini:  $90 / 9 orders  = $10
		// Naive (wrong) average of per-agent AOVs: ($1000 + $10) / 2 = $505
		// True (correct) weighted AOV: $1090 / 10 orders = $109
		//
		// This test fires if anyone refactors derive_stats() to
		// average per-agent AOVs — a tempting "cleaner" rewrite that
		// produces the wrong answer for any store with uneven agent
		// distribution (i.e., almost every real store).
		$by_agent = [
			'chatgpt' => [ 'orders' => 1, 'revenue' => 1000.00 ],
			'gemini'  => [ 'orders' => 9, 'revenue' => 90.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 10, 1090.00, $by_agent );

		$this->assertSame( 109.00, $result['ai_aov'] );
	}

	public function test_aov_rounds_to_two_decimals(): void {
		// $100 / 3 orders = $33.333... → $33.33 (round-half-up)
		$by_agent = [
			'chatgpt' => [ 'orders' => 3, 'revenue' => 100.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 3, 100.00, $by_agent );

		$this->assertSame( 33.33, $result['ai_aov'] );
	}

	// ------------------------------------------------------------------
	// Top agent: presence + winner
	// ------------------------------------------------------------------

	public function test_top_agent_is_null_when_by_agent_empty(): void {
		$result = WC_AI_Storefront_Attribution::derive_stats( 0, 0.0, [] );

		// `null` (not an empty array) — the React side does
		// `stats?.top_agent?.name ?? '—'` and an empty array
		// would also render the em-dash but would change the JSON
		// shape contract.
		$this->assertNull( $result['top_agent'] );
	}

	public function test_top_agent_picks_highest_order_count(): void {
		// chatgpt has more orders despite gemini having far higher
		// revenue. "Top agent" is defined as primary-driver-by-volume,
		// not primary-driver-by-money — pinning that decision here so
		// a future "switch to revenue-primary" refactor can't sneak in.
		$by_agent = [
			'chatgpt' => [ 'orders' => 3, 'revenue' => 90.00 ],
			'gemini'  => [ 'orders' => 1, 'revenue' => 1000.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 4, 1090.00, $by_agent );

		$this->assertSame( 'chatgpt', $result['top_agent']['name'] );
		$this->assertSame( 3, $result['top_agent']['orders'] );
		$this->assertSame( 90.00, $result['top_agent']['revenue'] );
	}

	// ------------------------------------------------------------------
	// Top agent: tie-break behavior
	// ------------------------------------------------------------------

	public function test_top_agent_revenue_tiebreaks_when_orders_equal(): void {
		// Both agents at 2 orders. Revenue secondary kicks in:
		// gemini ($200) > chatgpt ($50), so gemini wins.
		$by_agent = [
			'chatgpt' => [ 'orders' => 2, 'revenue' => 50.00 ],
			'gemini'  => [ 'orders' => 2, 'revenue' => 200.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 4, 250.00, $by_agent );

		$this->assertSame( 'gemini', $result['top_agent']['name'] );
	}

	public function test_top_agent_tiebreak_uses_spaceship_not_subtraction(): void {
		// `<=>` returns -1/0/1 regardless of magnitude; `-` would
		// silently cast floats to int. With revenues that differ
		// only in the cents column, subtraction-based comparators
		// truncate to 0 and the tie isn't resolved (you get
		// whichever happened to be first in the input — non-deterministic
		// across PHP versions). This test would fail if anyone "simplified"
		// the comparator to `$b['revenue'] - $a['revenue']`.
		$by_agent = [
			'chatgpt' => [ 'orders' => 2, 'revenue' => 300.25 ],
			'gemini'  => [ 'orders' => 2, 'revenue' => 300.50 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 4, 600.75, $by_agent );

		$this->assertSame( 'gemini', $result['top_agent']['name'] );
	}

	// ------------------------------------------------------------------
	// share_percent denominator
	// ------------------------------------------------------------------

	public function test_top_agent_share_percent_uses_ai_orders_total(): void {
		// Top agent has 4 of 10 AI orders → 40.0%.
		// The card label is "Top agent" and the subvalue reads
		// "%d orders | %s%% of AI orders" — locking the denominator
		// here so a future refactor can't quietly switch it to
		// all_orders (which would change the merchant's mental
		// model: "what fraction of AI traffic does this agent
		// drive?" vs "what fraction of TOTAL store orders did
		// this AI agent drive?" — the latter is a much smaller
		// number for most stores).
		$by_agent = [
			'chatgpt' => [ 'orders' => 4, 'revenue' => 400.00 ],
			'gemini'  => [ 'orders' => 3, 'revenue' => 300.00 ],
			'claude'  => [ 'orders' => 3, 'revenue' => 300.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 10, 1000.00, $by_agent );

		$this->assertSame( 40.0, $result['top_agent']['share_percent'] );
	}

	public function test_top_agent_share_percent_rounds_to_one_decimal(): void {
		// Top agent has 1 of 3 → 33.333...% → 33.3% (one decimal).
		$by_agent = [
			'chatgpt' => [ 'orders' => 1, 'revenue' => 50.00 ],
			'gemini'  => [ 'orders' => 1, 'revenue' => 30.00 ],
			'claude'  => [ 'orders' => 1, 'revenue' => 20.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 3, 100.00, $by_agent );

		$this->assertSame( 33.3, $result['top_agent']['share_percent'] );
	}

	// ------------------------------------------------------------------
	// Return shape
	// ------------------------------------------------------------------

	public function test_returns_only_ai_aov_and_top_agent_keys(): void {
		// Locks the contract — derive_stats() is the helper, not
		// the full response shape. Adding fields here without
		// updating get_stats() would silently expand the contract.
		$result = WC_AI_Storefront_Attribution::derive_stats( 0, 0.0, [] );

		$this->assertSame( [ 'ai_aov', 'top_agent' ], array_keys( $result ) );
	}

	public function test_top_agent_shape_when_present(): void {
		$by_agent = [
			'chatgpt' => [ 'orders' => 1, 'revenue' => 50.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 1, 50.00, $by_agent );

		$this->assertSame(
			[ 'name', 'orders', 'revenue', 'share_percent' ],
			array_keys( $result['top_agent'] )
		);
	}
}
