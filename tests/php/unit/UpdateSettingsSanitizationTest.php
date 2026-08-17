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
		WC_AI_Storefront::$test_settings = array();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Valid modes — must pass through unchanged
	// ------------------------------------------------------------------

	public function test_by_taxonomy_mode_is_preserved(): void {
		WC_AI_Storefront::update_settings( array( 'product_selection_mode' => 'by_taxonomy' ) );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_all_mode_is_preserved(): void {
		WC_AI_Storefront::update_settings( array( 'product_selection_mode' => 'all' ) );
		$this->assertSame( 'all', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_selected_mode_is_preserved(): void {
		WC_AI_Storefront::update_settings( array( 'product_selection_mode' => 'selected' ) );
		$this->assertSame( 'selected', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	// ------------------------------------------------------------------
	// Legacy modes — normalized to `by_taxonomy` at write time (Fix #156).
	// update_settings() now maps 'categories', 'tags', and 'brands' to
	// 'by_taxonomy' before saving, so the DB always stores a canonical
	// value. These tests pin that normalization contract.
	// ------------------------------------------------------------------

	public function test_legacy_categories_mode_is_normalized_to_by_taxonomy_on_write(): void {
		WC_AI_Storefront::update_settings( array( 'product_selection_mode' => 'categories' ) );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_legacy_tags_mode_is_normalized_to_by_taxonomy_on_write(): void {
		WC_AI_Storefront::update_settings( array( 'product_selection_mode' => 'tags' ) );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_legacy_brands_mode_is_normalized_to_by_taxonomy_on_write(): void {
		WC_AI_Storefront::update_settings( array( 'product_selection_mode' => 'brands' ) );
		$this->assertSame( 'by_taxonomy', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	// ------------------------------------------------------------------
	// Fix #156 regression: selected_* arrays must be preserved when a
	// legacy mode is normalized. The merchant's selections should survive
	// the rewrite so taxonomy enforcement still works after normalization.
	// ------------------------------------------------------------------

	public function test_legacy_mode_normalization_preserves_selected_categories(): void {
		WC_AI_Storefront::update_settings(
			array(
				'product_selection_mode' => 'categories',
				'selected_categories'    => array( 3, 7 ),
			)
		);
		$result = WC_AI_Storefront::get_settings();
		$this->assertSame( 'by_taxonomy', $result['product_selection_mode'] );
		$this->assertSame( array( 3, 7 ), $result['selected_categories'] );
	}

	// ------------------------------------------------------------------
	// Invalid mode — must fall back to 'all'
	// ------------------------------------------------------------------

	public function test_unknown_mode_falls_back_to_all(): void {
		WC_AI_Storefront::update_settings( array( 'product_selection_mode' => 'invalid_mode' ) );
		$this->assertSame( 'all', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	// ------------------------------------------------------------------
	// Merge behaviour — unrelated keys are merged, not replaced
	// ------------------------------------------------------------------

	public function test_update_merges_with_existing_settings(): void {
		WC_AI_Storefront::$test_settings = array(
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => array( 3, 7 ),
		);

		WC_AI_Storefront::update_settings( array( 'selected_tags' => array( 5 ) ) );

		$result = WC_AI_Storefront::get_settings();
		$this->assertSame( 'by_taxonomy', $result['product_selection_mode'] );
		$this->assertSame( array( 3, 7 ), $result['selected_categories'] );
		$this->assertSame( array( 5 ), $result['selected_tags'] );
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
		WC_AI_Storefront::update_settings( array( 'allow_unknown_ucp_agents' => 'yes' ) );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['allow_unknown_ucp_agents'] );
	}

	public function test_allow_unknown_ucp_agents_no_is_preserved(): void {
		WC_AI_Storefront::update_settings( array( 'allow_unknown_ucp_agents' => 'no' ) );
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
		WC_AI_Storefront::update_settings( array( 'allow_unknown_ucp_agents' => $value ) );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['allow_unknown_ucp_agents'] );
	}

	public static function allow_unknown_ucp_agents_invalid_value_provider(): array {
		return array(
			'arbitrary string' => array( 'maybe' ),
			'boolean true'     => array( true ),
			'integer 1'        => array( 1 ),
			'string 1'         => array( '1' ),
			'uppercase YES'    => array( 'YES' ),
			'truthy text'      => array( 'true' ),
			// Regression test for the bug Copilot caught in PR #100
			// review: when the key is explicit `null`, the earlier
			// inline `??` + ternary shape had a hole — the in_array
			// check ran against the coalesced `'no'` and PASSED, but
			// the true-branch returned the raw `null`. The current
			// shape (assign-coalesce-then-validate) closes the hole.
			// Pin it.
			'explicit null'    => array( null ),
		);
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
		WC_AI_Storefront::$test_settings = array();
		$this->assertArrayHasKey( 'mcp_enabled', WC_AI_Storefront::get_settings() );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['mcp_enabled'] );
	}

	public function test_mcp_enabled_yes_is_preserved(): void {
		WC_AI_Storefront::update_settings( array( 'mcp_enabled' => 'yes' ) );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['mcp_enabled'] );
	}

	public function test_mcp_enabled_no_is_preserved(): void {
		WC_AI_Storefront::update_settings( array( 'mcp_enabled' => 'no' ) );
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
		WC_AI_Storefront::update_settings( array( 'mcp_enabled' => $value ) );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['mcp_enabled'] );
	}

	public static function mcp_enabled_invalid_value_provider(): array {
		return array(
			'arbitrary string' => array( 'maybe' ),
			'boolean true'     => array( true ),
			'integer 1'        => array( 1 ),
			'string 1'         => array( '1' ),
			'uppercase YES'    => array( 'YES' ),
			'truthy text'      => array( 'true' ),
			'explicit null'    => array( null ),
		);
	}

	// ------------------------------------------------------------------
	// products_json_enabled sanitization — strict yes/no enum, default
	// 'yes'.
	// ------------------------------------------------------------------
	//
	// Same safety-net contract as mcp_enabled: the Shopify-compatible
	// /products.json feed is an opt-OUT toggle (default 'yes') gated by
	// syndication (`enabled`), so the serve handler only emits the feed
	// once syndication itself is on. Any value that bypasses the REST
	// enum schema MUST resolve to the default 'yes' rather than silently
	// disabling the feed on a malformed write.

	public function test_products_json_enabled_present_in_defaults(): void {
		// With no overrides, get_settings() must surface the key — a
		// missing key would make the feed gate read it as falsy and
		// silently 404 the endpoint on fresh installs.
		WC_AI_Storefront::$test_settings = array();
		$this->assertArrayHasKey( 'products_json_enabled', WC_AI_Storefront::get_settings() );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['products_json_enabled'] );
	}

	public function test_products_json_enabled_defaults_to_yes_and_validates(): void {
		WC_AI_Storefront::update_settings( array( 'enabled' => 'yes' ) );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['products_json_enabled'] );

		WC_AI_Storefront::update_settings( array( 'products_json_enabled' => 'no' ) );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['products_json_enabled'] );

		WC_AI_Storefront::update_settings( array( 'products_json_enabled' => 'gibberish' ) );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['products_json_enabled'] );
	}

	/**
	 * Every malformed value must fall back to the default `'yes'`.
	 * Mirrors the mcp_enabled provider's strict-comparison guard.
	 *
	 * @dataProvider products_json_enabled_invalid_value_provider
	 */
	public function test_products_json_enabled_invalid_value_falls_back_to_yes( $value ): void {
		WC_AI_Storefront::update_settings( array( 'products_json_enabled' => $value ) );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['products_json_enabled'] );
	}

	public static function products_json_enabled_invalid_value_provider(): array {
		return array(
			'arbitrary string' => array( 'maybe' ),
			'boolean true'     => array( true ),
			'integer 1'        => array( 1 ),
			'string 1'         => array( '1' ),
			'uppercase YES'    => array( 'YES' ),
			'truthy text'      => array( 'true' ),
			'explicit null'    => array( null ),
		);
	}

	// ------------------------------------------------------------------
	// indexnow_enabled sanitization — strict yes/no enum, default 'no'
	// (deferred from Task 1, closed by Task 6).
	// ------------------------------------------------------------------
	//
	// Same safety-net contract as mcp_enabled / products_json_enabled:
	// IndexNow is an opt-IN toggle (default 'no') gated by syndication.
	// The round-trip test below is the specific regression pin that was
	// deferred from Task 1 — it proves that setting indexnow_enabled='no'
	// round-trips through update_settings() without being silently stripped
	// from the persisted $clean array.

	public function test_indexnow_enabled_present_in_defaults(): void {
		WC_AI_Storefront::$test_settings = array();
		$this->assertArrayHasKey( 'indexnow_enabled', WC_AI_Storefront::get_settings() );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['indexnow_enabled'] );
	}

	public function test_indexnow_enabled_round_trips_no(): void {
		// Regression pin (deferred Task-1 finding): indexnow_enabled='no' must
		// survive the production settings-update path unchanged. Prior to
		// adding indexnow_enabled to the $clean array in update_settings(), a
		// save would silently revert it to the get_settings() default 'yes'.
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'no' ) );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['indexnow_enabled'] );
	}

	public function test_indexnow_enabled_yes_is_preserved(): void {
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => 'yes' ) );
		$this->assertSame( 'yes', WC_AI_Storefront::get_settings()['indexnow_enabled'] );
	}

	/**
	 * Every malformed value must fall back to the default `'no'`.
	 * Mirrors the mcp_enabled / products_json_enabled provider.
	 *
	 * @dataProvider indexnow_enabled_invalid_value_provider
	 */
	public function test_indexnow_enabled_invalid_value_falls_back_to_no( $value ): void {
		WC_AI_Storefront::update_settings( array( 'indexnow_enabled' => $value ) );
		$this->assertSame( 'no', WC_AI_Storefront::get_settings()['indexnow_enabled'] );
	}

	public static function indexnow_enabled_invalid_value_provider(): array {
		return array(
			'arbitrary string' => array( 'gibberish' ),
			'boolean true'     => array( true ),
			'integer 1'        => array( 1 ),
			'string 1'         => array( '1' ),
			'uppercase YES'    => array( 'YES' ),
			'truthy text'      => array( 'true' ),
			'explicit null'    => array( null ),
		);
	}

	// ------------------------------------------------------------------
	// indexnow_key isolation — update_settings() must NOT write the key
	// ------------------------------------------------------------------
	//
	// The IndexNow key now lives in its own dedicated option
	// (wc_ai_storefront_indexnow_key), not in SETTINGS_OPTION. Consequently
	// update_settings() must not touch that option at all. This test asserts
	// the isolation: calling update_settings([]) must leave the key option
	// untouched (the stub's $test_settings never gains an indexnow_key key).

	public function test_update_settings_does_not_write_indexnow_key(): void {
		WC_AI_Storefront::$test_settings = array();
		WC_AI_Storefront::update_settings( array() );
		$result = WC_AI_Storefront::get_settings();
		$this->assertArrayNotHasKey( 'indexnow_key', $result );
	}
}
