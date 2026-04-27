<?php
/**
 * Tests for the `mode=by_taxonomy` UNION branch of
 * WC_AI_Storefront::is_product_syndicated().
 *
 * 0.1.5 consolidated the three single-taxonomy modes (categories,
 * tags, brands) into a single `by_taxonomy` mode that ANY-matches
 * across the union of `selected_categories`, `selected_tags`, and
 * `selected_brands`. A product belonging to ANY one configured term
 * across the three arrays is syndicated.
 *
 * Sister file: `IsSyndicatedTagsBrandsTest.php` exercises the legacy
 * mode values, which now route through the same `by_taxonomy`
 * fallback at the top of `is_product_syndicated()`. This file
 * exercises the explicit `mode=by_taxonomy` path with various
 * `selected_*` combinations.
 *
 * Test harness note: bootstrap loads
 * `tests/php/stubs/class-wc-ai-storefront-stub.php`. The stub's
 * `is_product_syndicated()` mirrors production semantics with one
 * intentional difference — the category branch reads via
 * `wp_get_post_terms( ..., 'product_cat' )` rather than
 * `wc_get_product_cat_ids()` because the latter isn't stubbed in the
 * unit harness. These tests therefore stub `wp_get_post_terms` for
 * all three taxonomies and rely on the stub's $taxonomy argument to
 * route per-call returns.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class IsSyndicatedUnionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = [];
		// Defense in depth: reset the variation-redirect map. Static
		// properties on `WC_AI_Storefront` persist across PHPUnit
		// instances within one process, so a future variation test
		// that forgets its tearDown could otherwise pollute these
		// tests' product_id → parent_id resolution. Empty map = no
		// redirect, which matches what every test in this file
		// implicitly expects.
		WC_AI_Storefront::$test_variations = [];

		// Default: brands taxonomy registered. Tests exercising the
		// downgrade branch override with `justReturn( false )`.
		Functions\when( 'taxonomy_exists' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function make_product( int $id ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		return $product;
	}

	/**
	 * Stub `wp_get_post_terms()` to return per-taxonomy term IDs.
	 *
	 * The stub's by_taxonomy branch calls `wp_get_post_terms()` once
	 * per active taxonomy. Routing by the second-argument `$taxonomy`
	 * lets a single test configure all three returns at once.
	 *
	 * @param array<string, array<int>|WP_Error> $returns Per-taxonomy returns.
	 */
	private function stub_terms( array $returns ): void {
		Functions\when( 'wp_get_post_terms' )->alias(
			static function ( $product_id, $taxonomy, $args = [] ) use ( $returns ) {
				return $returns[ $taxonomy ] ?? [];
			}
		);
	}

	// ------------------------------------------------------------------
	// Single-taxonomy match within explicit `by_taxonomy` mode.
	// ------------------------------------------------------------------

	public function test_returns_true_when_product_matches_selected_categories_only(): void {
		// Only categories configured. Product carries category 7;
		// merchant selected [7]. UNION branch finds the match on the
		// categories dimension and returns true.
		$this->stub_terms(
			[
				'product_cat'   => [ 7 ],
				'product_tag'   => [],
				'product_brand' => [],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 7, 12 ],
				'selected_tags'          => [],
				'selected_brands'        => [],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_returns_true_when_product_matches_selected_tags_only(): void {
		// Tags-only configuration. Product is in tag 11; merchant
		// selected [11, 22]. Match on tags → true.
		$this->stub_terms(
			[
				'product_cat'   => [],
				'product_tag'   => [ 11 ],
				'product_brand' => [],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [],
				'selected_tags'          => [ 11, 22 ],
				'selected_brands'        => [],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_returns_true_when_product_matches_selected_brands_only(): void {
		// Brands-only configuration with the taxonomy registered.
		// Product carries brand 5; merchant selected [5]. Match → true.
		$this->stub_terms(
			[
				'product_cat'   => [],
				'product_tag'   => [],
				'product_brand' => [ 5 ],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [],
				'selected_tags'          => [],
				'selected_brands'        => [ 5, 9 ],
			]
		);

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Cross-taxonomy UNION match.
	// ------------------------------------------------------------------

	public function test_returns_true_when_product_matches_in_multiple_taxonomies(): void {
		// All three dimensions populated and product matches in each.
		// UNION semantics: any single match suffices, but verifying
		// the intersection-of-matches case pins the all-active path.
		$this->stub_terms(
			[
				'product_cat'   => [ 1 ],
				'product_tag'   => [ 11 ],
				'product_brand' => [ 5 ],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 1 ],
				'selected_tags'          => [ 11 ],
				'selected_brands'        => [ 5 ],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_returns_true_when_product_matches_only_one_of_multiple_dimensions(): void {
		// Product belongs to no selected category and no selected
		// brand, but has a matching tag. UNION fires on the tag
		// dimension alone → true. This pins that the gate doesn't
		// require ALL dimensions to match (which would be intersection,
		// not union).
		$this->stub_terms(
			[
				'product_cat'   => [ 999 ],
				'product_tag'   => [ 11 ],
				'product_brand' => [ 999 ],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 1 ],
				'selected_tags'          => [ 11 ],
				'selected_brands'        => [ 5 ],
			]
		);

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Negative cases: no match, fully-empty selection.
	// ------------------------------------------------------------------

	public function test_returns_false_when_product_matches_no_selected_taxonomy(): void {
		// All three dimensions populated but product matches nothing
		// in any of them.
		$this->stub_terms(
			[
				'product_cat'   => [ 999 ],
				'product_tag'   => [ 999 ],
				'product_brand' => [ 999 ],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 1 ],
				'selected_tags'          => [ 11 ],
				'selected_brands'        => [ 5 ],
			]
		);

		$this->assertFalse( $result );
	}

	public function test_returns_false_when_all_selected_arrays_empty(): void {
		// Empty-selection policy: nothing enforceable in any
		// dimension → hide the catalog. Mirrors the Store API
		// filter's `post__in = [0]` posture.
		// `wp_get_post_terms` should NOT be called — short-circuit
		// before the per-taxonomy lookups.
		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [],
				'selected_tags'          => [],
				'selected_brands'        => [],
			]
		);

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Brand-taxonomy downgrade exception.
	// ------------------------------------------------------------------

	public function test_returns_true_when_only_selected_brands_set_and_taxonomy_unregistered(): void {
		// Pre-WC-9.5 (or custom unregistration) and only brands
		// configured: stay symmetric with the legacy `brands` mode's
		// degradation behavior — show all rather than silently hide
		// the catalog. The merchant configured brands on a 9.5+ store;
		// downgrading shouldn't make their catalog vanish.
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [],
				'selected_tags'          => [],
				'selected_brands'        => [ 5, 9 ],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_returns_false_when_only_selected_brands_set_empty_and_taxonomy_unregistered(): void {
		// Downgrade exception is gated on `selected_brands` being
		// NON-EMPTY. Empty arrays mean "nothing configured" — there
		// is no prior intent to preserve, so the empty-selection
		// policy applies and the catalog is hidden.
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [],
				'selected_tags'          => [],
				'selected_brands'        => [],
			]
		);

		$this->assertFalse( $result );
	}

	public function test_downgrade_exception_does_not_fire_when_categories_also_configured(): void {
		// Only-brands precondition matters: with categories also
		// populated, the downgrade-show-all exception does NOT fire,
		// and enforcement falls through to the categories dimension.
		// Product matches no selected category → false.
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		$this->stub_terms(
			[
				'product_cat'   => [ 999 ],
				'product_tag'   => [],
				'product_brand' => [],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 1, 2 ],
				'selected_tags'          => [],
				'selected_brands'        => [ 5 ],
			]
		);

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Legacy-mode routing parity.
	// ------------------------------------------------------------------

	public function test_legacy_categories_mode_routes_through_by_taxonomy(): void {
		// The defensive legacy-mode fallback at the top of
		// is_product_syndicated() rewrites `categories` → `by_taxonomy`
		// before evaluation. Verify the rewrite produces the same
		// result as passing `by_taxonomy` directly.
		$this->stub_terms(
			[
				'product_cat'   => [ 7 ],
				'product_tag'   => [],
				'product_brand' => [],
			]
		);

		$legacy = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'categories',
				'selected_categories'    => [ 7 ],
				'selected_tags'          => [],
				'selected_brands'        => [],
			]
		);

		$canonical = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 7 ],
				'selected_tags'          => [],
				'selected_brands'        => [],
			]
		);

		$this->assertSame( $canonical, $legacy );
		$this->assertTrue( $legacy );
	}

	public function test_brands_dimension_skipped_when_taxonomy_unregistered_but_categories_match(): void {
		// `product_brand` is unregistered. `selected_brands` is
		// non-empty but the brands dimension is treated as inert
		// (has_brands=false). With categories also configured AND
		// matching, the gate returns true via the categories
		// dimension — brand inertness doesn't poison the union.
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		$this->stub_terms(
			[
				'product_cat'   => [ 1 ],
				'product_tag'   => [],
				'product_brand' => [],
			]
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 1 ],
				'selected_tags'          => [],
				'selected_brands'        => [ 5 ],
			]
		);

		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// WP_Error from per-taxonomy lookup falls through to other dimensions.
	// ------------------------------------------------------------------

	public function test_returns_true_when_one_taxonomy_errors_but_another_matches(): void {
		// `wp_get_post_terms` returns WP_Error for tags (e.g. taxonomy
		// misconfiguration) but the categories dimension matches.
		// The gate must NOT fail-closed on a single dimension's error
		// — fall through to the next dimension and return true on a
		// real match. This pins UNION resilience.
		Functions\when( 'wp_get_post_terms' )->alias(
			static function ( $product_id, $taxonomy, $args = [] ) {
				if ( 'product_tag' === $taxonomy ) {
					return new WP_Error( 'invalid_taxonomy', 'Boom.' );
				}
				if ( 'product_cat' === $taxonomy ) {
					return [ 1 ];
				}
				return [];
			}
		);

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 1 ],
				'selected_tags'          => [ 11 ],
				'selected_brands'        => [],
			]
		);

		$this->assertTrue( $result );
	}
}
