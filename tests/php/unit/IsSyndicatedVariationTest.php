<?php
/**
 * Tests for the variation-redirect branch of WC_AI_Storefront::is_product_syndicated().
 *
 * Variations (`product_variation` post type) inherit their parent's
 * syndication status because the merchant's selection mechanisms —
 * `selected_products`, `selected_categories`, `selected_tags`,
 * `selected_brands` — all attach to PARENT product posts. Variation
 * posts carry no term memberships of their own and `selected_products`
 * stores parent IDs.
 *
 * Production bug pinned by these tests: in 0.3.1, `is_product_syndicated()`
 * checked the variation's own ID directly, which always failed for
 * `selected` and `by_taxonomy` modes (variations aren't in the merchant's
 * selected_products list and have no terms). UCP catalog/lookup's
 * per-variation pre-fetch path silently dropped every variation,
 * triggering the synthesized `var_{parent}_default` placeholder. Agents
 * couldn't distinguish Small / Medium / Large.
 *
 * Surfaced by a Gemini-3-Flash UCPPlayground test attempting to buy a
 * "Hoodie with Logo" in size Medium — the agent saw only `var_23_default`
 * and the requested size never reached the cart.
 *
 * Test mechanism: tests configure the variation → parent map directly
 * via `WC_AI_Storefront::$test_variations`. The stub uses that map
 * instead of WP's `get_post_type` + `wp_get_post_parent_id`. Production
 * uses the WP functions (gated by `function_exists`) — the stub diverges
 * here because Brain Monkey's WP preset makes `function_exists` return
 * true globally, which would force every unrelated test to mock both
 * names or crash with `MissingFunctionExpectations`. The map keeps
 * variation tests explicit and unrelated tests untouched.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class IsSyndicatedVariationTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings   = [];
		WC_AI_Storefront::$test_variations = [];
		Functions\when( 'taxonomy_exists' )->justReturn( true );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_variations = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Variation-redirect: selected mode
	// ------------------------------------------------------------------

	public function test_variation_inherits_parent_syndication_in_selected_mode(): void {
		// Pre-fix this returned false: the variation ID 1112 isn't in
		// `selected_products` (only the parent ID 23 is). After fix,
		// the variation redirects to the parent and the parent is in
		// the list, so syndicated.
		WC_AI_Storefront::$test_variations = [ 1112 => 23 ];

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 23 ],
		];

		$this->assertTrue( WC_AI_Storefront::is_product_syndicated( 1112 ) );
	}

	public function test_variation_blocked_when_parent_not_in_selected_list(): void {
		// Parent (23) is NOT in selected_products. Variation should
		// inherit that "not syndicated" verdict, not get its own
		// independent check.
		WC_AI_Storefront::$test_variations = [ 1112 => 23 ];

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 99 ],
		];

		$this->assertFalse( WC_AI_Storefront::is_product_syndicated( 1112 ) );
	}

	// ------------------------------------------------------------------
	// Variation-redirect: by_taxonomy mode
	// ------------------------------------------------------------------

	public function test_variation_inherits_parent_taxonomy_membership(): void {
		// Variation has no terms of its own. Parent (23) is in category
		// 5. With `by_taxonomy` + selected_categories=[5], the parent
		// is syndicated. The variation should inherit that verdict via
		// the redirect rather than failing its own (empty) term check.
		WC_AI_Storefront::$test_variations = [ 1112 => 23 ];
		// `wp_get_post_terms` is queried with the resolved parent ID
		// (23), not the variation ID. Stub returns the parent's terms.
		//
		// Closure signature accepts a third `$args = []` parameter
		// because the stub's by_taxonomy branch calls
		// `wp_get_post_terms( $id, $taxonomy, [ 'fields' => 'ids' ] )`
		// — a 2-arg closure works under Brain Monkey's lenient call
		// path today but a future PHP/runtime upgrade with strict
		// callable enforcement would reject it.
		Functions\when( 'wp_get_post_terms' )->alias(
			static fn( $id, $tax, $args = [] ) => 23 === $id && 'product_cat' === $tax
				? [ 5 ]
				: []
		);

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5 ],
			'selected_tags'          => [],
			'selected_brands'        => [],
		];

		$this->assertTrue( WC_AI_Storefront::is_product_syndicated( 1112 ) );
	}

	// ------------------------------------------------------------------
	// Variation-redirect: all mode
	// ------------------------------------------------------------------

	public function test_variation_passes_in_all_mode(): void {
		// `all` mode short-circuits AFTER the variation redirect runs.
		// Pinning the success path: a variation in `all` mode passes,
		// AND the redirect doesn't break the short-circuit.
		WC_AI_Storefront::$test_variations = [ 1112 => 23 ];

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'all',
		];

		$this->assertTrue( WC_AI_Storefront::is_product_syndicated( 1112 ) );
	}

	// ------------------------------------------------------------------
	// Edge cases
	// ------------------------------------------------------------------

	public function test_orphaned_variation_returns_false(): void {
		// Variation row exists but the parent ID is non-positive (parent
		// deleted but variation post lingers — degraded data). Without a
		// parent to inherit from, we treat as out-of-scope rather than
		// silently leak. Tested in `all` mode to prove the orphan check
		// fires BEFORE the all-short-circuit; otherwise this test would
		// pass for the wrong reason.
		WC_AI_Storefront::$test_variations = [ 1112 => 0 ];

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'all',
		];

		$this->assertFalse( WC_AI_Storefront::is_product_syndicated( 1112 ) );
	}

	public function test_non_variation_post_skips_redirect(): void {
		// Regression guard: a regular `product` post must NOT be
		// rewritten via the variation map. ID 23 is absent from the
		// map → no redirect → ID 23 checked directly against
		// selected_products and found.
		WC_AI_Storefront::$test_variations = [];

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 23 ],
		];

		$this->assertTrue( WC_AI_Storefront::is_product_syndicated( 23 ) );
	}
}
