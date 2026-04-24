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
	// Legacy modes — sanitization accepts them (does NOT coerce to
	// 'all'). The stub's update_settings() does not run the silent
	// migration that production's get_settings() performs on the next
	// read, so these tests pin only the ACCEPTANCE behavior — not the
	// post-migration storage. In production the value gets rewritten
	// to `by_taxonomy` on the next get_settings() call (see
	// SettingsMigrationTest).
	// ------------------------------------------------------------------

	public function test_legacy_categories_mode_is_accepted_by_sanitization(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'categories' ] );
		$this->assertNotSame( 'all', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_legacy_tags_mode_is_accepted_by_sanitization(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'tags' ] );
		$this->assertNotSame( 'all', WC_AI_Storefront::get_settings()['product_selection_mode'] );
	}

	public function test_legacy_brands_mode_is_accepted_by_sanitization(): void {
		WC_AI_Storefront::update_settings( [ 'product_selection_mode' => 'brands' ] );
		$this->assertNotSame( 'all', WC_AI_Storefront::get_settings()['product_selection_mode'] );
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
}
