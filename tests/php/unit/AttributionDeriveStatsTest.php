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

// `derive_stats()` is a pure function — no WP globals, no filters,
// no `$wpdb` — so it doesn't need Brain Monkey or Mockery. Plain
// PHPUnit assertions are sufficient.

class AttributionDeriveStatsTest extends \PHPUnit\Framework\TestCase {

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

	public function test_top_agent_tiebreak_resolves_sub_dollar_revenue_differences(): void {
		// Both agents at 2 orders, revenues differ only in cents.
		// What this actually verifies: a subtraction-based comparator
		// (`return $b['revenue'] - $a['revenue']`) would lose this
		// tie because `usort` casts the comparator's RETURN value to
		// `int`, so `0.25` truncates to `0` and the agents stay
		// indistinguishable. The spaceship operator returns clean
		// `-1/0/1` regardless of float magnitude, so cents-only
		// revenue differences resolve correctly. (Earlier versions
		// of this test were named "uses_spaceship_not_subtraction",
		// but the assertion itself catches both failure modes —
		// subtraction-based AND any other comparator that truncates
		// sub-dollar precision — so the rename reflects what's
		// actually pinned.)
		$by_agent = [
			'chatgpt' => [ 'orders' => 2, 'revenue' => 300.25 ],
			'gemini'  => [ 'orders' => 2, 'revenue' => 300.50 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 4, 600.75, $by_agent );

		$this->assertSame( 'gemini', $result['top_agent']['name'] );
	}

	// ------------------------------------------------------------------
	// Defensive branches
	// ------------------------------------------------------------------

	public function test_returns_empty_state_when_total_orders_zero_with_nonempty_by_agent(): void {
		// Inconsistent input: the helper's contract assumes
		// `$total_orders == sum(by_agent[*].orders)`, but the early-
		// exit guard handles a caller-bug scenario where they
		// disagree. Returns the empty-state shape rather than
		// silently producing a "winner with `share_percent = 0`"
		// that would render a populated card with meaningless
		// zero share. Currently unreachable from `get_stats()`
		// (both totals come from the same SQL row loop) but
		// `derive_stats()` is `public static` and may grow other
		// callers in PR-B.
		$by_agent = [
			'chatgpt' => [ 'orders' => 5, 'revenue' => 250.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 0, 250.00, $by_agent );

		$this->assertSame( 0.0, $result['ai_aov'] );
		$this->assertNull( $result['top_agent'] );
	}

	public function test_returns_empty_state_when_total_orders_negative(): void {
		// `int $total_orders` accepts negatives; the early-exit
		// guard catches them. Same rationale as the zero-total
		// test: silently dividing by a negative would yield a
		// negative AOV that would render as "$-50.00" — silently-
		// wrong is worse than empty-state.
		$result = WC_AI_Storefront_Attribution::derive_stats( -1, 100.00, [] );

		$this->assertSame( 0.0, $result['ai_aov'] );
		$this->assertNull( $result['top_agent'] );
	}

	public function test_skips_empty_string_agent_names(): void {
		// SQL filters `meta_value <> ''` in `get_stats()` so this
		// scenario shouldn't arise from production data, but the
		// helper is public-static and any future caller passing
		// pre-aggregated data needs the same protection. An empty
		// agent name in the winner slot would render an empty
		// value cell on the React side with a populated subvalue —
		// looks like a render bug. Better to skip and fall through
		// to the next-best agent.
		$by_agent = [
			''        => [ 'orders' => 10, 'revenue' => 500.00 ],
			'chatgpt' => [ 'orders' => 3, 'revenue' => 150.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 13, 650.00, $by_agent );

		// Empty-name agent is filtered out; chatgpt becomes the winner
		// despite having fewer orders.
		$this->assertSame( 'chatgpt', $result['top_agent']['name'] );
	}

	public function test_returns_null_top_agent_when_only_empty_string_agent_present(): void {
		// All `by_agent` rows have empty names → after skipping,
		// `$ranked` is empty → no winner.
		$by_agent = [
			'' => [ 'orders' => 5, 'revenue' => 250.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 5, 250.00, $by_agent );

		$this->assertNull( $result['top_agent'] );
	}

	public function test_truncates_long_agent_name_to_max_length(): void {
		// `_wc_ai_storefront_agent` meta is populated from
		// `utm_source`, which is merchant-uncontrolled inbound URL
		// content. An abnormally long name would push the StatCard
		// width past its layout slot. The helper caps at
		// TOP_AGENT_NAME_MAX_LENGTH (64 chars).
		$long_name = str_repeat( 'a', 200 );
		$by_agent  = [
			$long_name => [ 'orders' => 1, 'revenue' => 50.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 1, 50.00, $by_agent );

		$this->assertSame( 64, strlen( $result['top_agent']['name'] ) );
		$this->assertSame( WC_AI_Storefront_Attribution::TOP_AGENT_NAME_MAX_LENGTH, strlen( $result['top_agent']['name'] ) );
	}

	public function test_share_percent_returns_float_in_zero_branch(): void {
		// Type drift guard. The early-exit returns `top_agent => null`,
		// so this test exercises a happy-path zero (single agent with
		// 0 orders — possible if revenue accumulated but the row's
		// order_count cast to 0 for some reason). NOTE: this is a
		// hypothetical edge case; the SQL `COUNT(DISTINCT ...)` won't
		// produce 0 from a row that JOINed successfully. Keeping the
		// test for the type contract only.
		//
		// More importantly: the happy-path `share_percent` must always
		// be `float`, not `int`. `round()` returns `float`, so we just
		// verify that here on a normal case.
		$by_agent = [
			'chatgpt' => [ 'orders' => 1, 'revenue' => 50.00 ],
		];

		$result = WC_AI_Storefront_Attribution::derive_stats( 1, 50.00, $by_agent );

		$this->assertIsFloat( $result['top_agent']['share_percent'] );
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
