<?php
/**
 * Tests for WC_AI_Storefront::update_settings() product_selection_mode sanitization.
 *
 * Guards the allowed-modes list against accidental omission. The
 * regression this covers: `by_taxonomy` was missing from the list in
 * `update_settings()`, so every admin save after the silent migration
 * coerced the stored mode back to `all`, silently breaking UNION
 * enforcement.
 *
 * Uses the stub's update_settings() which mirrors the production
 * sanitization. Keep both in sync when adding new mode values.
 *
 * @package WooCommerce_AI_Storefront
 */

class UpdateSettingsSanitizationTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		WC_AI_Storefront::$test_settings = [];
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Valid modes — must pass through unchanged
	// ------------------------------------------------------------------

	public function test_by_taxonomy_mode_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'by_taxonomy' ] );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_all_mode_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'all' ] );
		$this->assertSame( 'all', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_selected_mode_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'selected' ] );
		$this->assertSame( 'selected', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	// ------------------------------------------------------------------
	// Legacy modes — normalized to `by_taxonomy` at write time (Fix #156).
	// update_settings() now maps 'categories', 'tags', and 'brands' to
	// 'by_taxonomy' before saving, so the DB always stores a canonical
	// value. These tests pin that normalization contract.
	// ------------------------------------------------------------------

	public function test_legacy_categories_mode_is_normalized_to_by_taxonomy_on_write(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'categories' ] );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_legacy_tags_mode_is_normalized_to_by_taxonomy_on_write(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'tags' ] );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_legacy_brands_mode_is_normalized_to_by_taxonomy_on_write(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'brands' ] );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	// ------------------------------------------------------------------
	// Fix #156 regression: selected_* arrays must be preserved when a
	// legacy mode is normalized. The merchant's selections should survive
	// the rewrite so taxonomy enforcement still works after normalization.
	// ------------------------------------------------------------------

	public function test_legacy_mode_normalization_preserves_selected_categories(): void {
		WC_AI_Storefront::update_settings(
			[
				'product_selection_mode' => 'categories',
				'selected_categories'    => [ 3, 7 ],
			]
		);
		$result = WC_AI_Storefront::get_settings();
		$this->assertSame( 'by_taxonomy', $result['product_selection_mode'] );
		$this->assertSame( [ 3, 7 ], $result['selected_categories'] );
	}

	// ------------------------------------------------------------------
	// Invalid mode — must fall back to 'all'
	// ------------------------------------------------------------------

	public function test_unknown_mode_falls_back_to_all(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'invalid_mode' ] );
		$this->assertSame( 'all', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	// ------------------------------------------------------------------
	// Merge behaviour — unrelated keys are merged, not replaced
	// ------------------------------------------------------------------

	public function test_update_merges_with_existing_settings(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 3, 7 ],
		];

		WC_AI_Storefront::update_settings( [ 'selected_tags' => [ 5 ] ] );

		$result = WC_AI_Storefront::get_settings();
		$this->assertSame( 'by_taxonomy', $result['product_selection_mode'] );
		$this->assertSame( [ 3, 7 ], $result['selected_categories'] );
		$this->assertSame( [ 5 ], $result['selected_tags'] );
	}

	// ------------------------------------------------------------------
	// allow_unknown_ucp_agents sanitization — strict yes/no enum
	// ------------------------------------------------------------------
	//
	// The setting's REST schema declares `enum: ['yes', 'no']` so WP
	// REST 400s malformed values before they reach the sanitizer.
	// But the sanitizer is the safety net: any value that bypasses
	// the schema (legacy stored value, direct `update_option()` call,
	// future schema refactor that loosens the enum) MUST still
	// resolve to the secure default `'no'`. These tests pin that
	// safety-net contract.

	public function test_allow_unknown_ucp_agents_yes_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'allow_unknown_ucp_agents' => 'yes' ] );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['allow_unknown_ucp_agents'] );
	}

	public function test_allow_unknown_ucp_agents_no_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'allow_unknown_ucp_agents' => 'no' ] );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['allow_unknown_ucp_agents'] );
	}

	/**
	 * Every malformed value must fall back to the secure default
	 * `'no'`. The strict-mode `in_array(..., true)` is the load-bearing
	 * line; if a future "simplification" drops the strict flag, this
	 * data set will catch it (`true == 'yes'` and `1 == 'yes'` are
	 * both true under loose comparison — exactly the failure mode
	 * we don't want).
	 *
	 * @dataProvider allow_unknown_ucp_agents_invalid_value_provider
	 */
	public function test_allow_unknown_ucp_agents_invalid_value_falls_back_to_no( $value ): void {
		WC_AI_Storefront::update_settings( [ 'allow_unknown_ucp_agents' => $value ] );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['allow_unknown_ucp_agents'] );
	}

	public static function allow_unknown_ucp_agents_invalid_value_provider(): array {
		return [
			'arbitrary string' => [ 'maybe' ],
			'boolean true'     => [ true ],
			'integer 1'        => [ 1 ],
			'string 1'         => [ '1' ],
			'uppercase YES'    => [ 'YES' ],
			'truthy text'      => [ 'true' ],
			// Regression test for the bug Copilot caught in PR #100
			// review: when the key is explicit `null`, the earlier
			// inline `??` + ternary shape had a hole — the in_array
			// check ran against the coalesced `'no'` and PASSED, but
			// the true-branch returned the raw `null`. The current
			// shape (assign-coalesce-then-validate) closes the hole.
			// Pin it.
			'explicit null'    => [ null ],
		];
	}

	// ----------------------------------------------------------------------
	// allow_discount_codes (#376) — regression tests for the persistence
	// path. PR #380's first review caught a TWO-LAYER drop bug where the
	// admin controller's `$fields` whitelist AND the model's `$clean`
	// array both omitted this key, so the merchant toggle never reached
	// storage. These tests round-trip via the real `update_settings()`
	// + `get_settings()` flow (NOT `$test_settings`) so any future
	// regression of the same shape is caught at unit-test time.
	// ----------------------------------------------------------------------

	public function test_allow_discount_codes_yes_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'allow_discount_codes' => 'yes' ] );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['allow_discount_codes'] );
	}

	public function test_allow_discount_codes_no_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'allow_discount_codes' => 'no' ] );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['allow_discount_codes'] );
	}

	public function test_allow_discount_codes_defaults_to_yes_when_unset(): void {
		// Unlike `allow_unknown_ucp_agents` (secure-by-default `'no'`),
		// `allow_discount_codes` defaults to `'yes'` — the capability is
		// also gated upstream by `wc_coupons_enabled()`, so an opt-out
		// default would add friction with no security benefit. Pin the
		// default-state contract: after a save that doesn't touch this
		// key, the get returns `'yes'`.
		WC_AI_Storefront::update_settings( [ 'enabled' => 'yes' ] );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['allow_discount_codes'] );
	}

	/**
	 * Every malformed value must fall back to the documented default
	 * `'yes'`. Same `assign-coalesce-then-validate` shape as
	 * `$allow_unknown` (see provider docblock above) — the explicit
	 * `null` case especially proves the hole is closed.
	 *
	 * @dataProvider allow_discount_codes_invalid_value_provider
	 */
	public function test_allow_discount_codes_invalid_value_falls_back_to_yes( $value ): void {
		WC_AI_Storefront::update_settings( [ 'allow_discount_codes' => $value ] );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['allow_discount_codes'] );
	}

	public static function allow_discount_codes_invalid_value_provider(): array {
		return [
			'arbitrary string' => [ 'maybe' ],
			'boolean true'     => [ true ],
			'integer 1'        => [ 1 ],
			'string 1'         => [ '1' ],
			'uppercase YES'    => [ 'YES' ],
			'truthy text'      => [ 'true' ],
			// Explicit-null regression mirror — the same two-layer
			// in_array + coalesce hole that hit `$allow_unknown` could
			// silently re-emerge if anyone re-inlines this with `??` +
			// ternary. Pin it.
			'explicit null'    => [ null ],
		];
	}
}
