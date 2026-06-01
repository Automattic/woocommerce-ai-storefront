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

	// ------------------------------------------------------------------
	// mcp_enabled sanitization — strict yes/no enum, default 'yes'
	// ------------------------------------------------------------------
	//
	// Same safety-net contract as allow_unknown_ucp_agents, but the
	// secure default flips: MCP is an opt-OUT toggle (default 'yes')
	// because the transport is only ever live when syndication itself
	// (`enabled`) is on. Any value that bypasses the REST enum schema
	// MUST resolve to the default 'yes' rather than silently disabling
	// the transport on a malformed write.

	public function test_mcp_enabled_present_in_defaults(): void {
		// With no overrides, get_settings() must surface the key — a
		// missing key would make the MCP gate read it as falsy and
		// silently disable the transport on fresh installs.
		WC_AI_Storefront::$test_settings = [];
		$this->assertArrayHasKey( 'mcp_enabled', WC_AI_Storefront::get_settings() );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['mcp_enabled'] );
	}

	public function test_mcp_enabled_yes_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'mcp_enabled' => 'yes' ] );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['mcp_enabled'] );
	}

	public function test_mcp_enabled_no_is_preserved(): void {
		WC_AI_Storefront::update_settings( [ 'mcp_enabled' => 'no' ] );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['mcp_enabled'] );
	}

	/**
	 * Every malformed value must fall back to the default `'yes'`.
	 * Mirrors the allow_unknown provider's strict-comparison guard,
	 * but the expected fallback is `'yes'` (the MCP default), not
	 * `'no'`.
	 *
	 * @dataProvider mcp_enabled_invalid_value_provider
	 */
	public function test_mcp_enabled_invalid_value_falls_back_to_yes( $value ): void {
		WC_AI_Storefront::update_settings( [ 'mcp_enabled' => $value ] );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['mcp_enabled'] );
	}

	public static function mcp_enabled_invalid_value_provider(): array {
		return [
			'arbitrary string' => [ 'maybe' ],
			'boolean true'     => [ true ],
			'integer 1'        => [ 1 ],
			'string 1'         => [ '1' ],
			'uppercase YES'    => [ 'YES' ],
			'truthy text'      => [ 'true' ],
			'explicit null'    => [ null ],
		];
	}
}
