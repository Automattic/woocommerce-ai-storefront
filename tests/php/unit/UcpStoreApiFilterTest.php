<?php
/**
 * Tests for WC_AI_Storefront_UCP_Store_API_Filter.
 *
 * The filter injects the plugin's `product_selection_mode` setting
 * into every Store API product collection query. Tests exercise
 * `restrict_to_syndicated_products()` directly (no WP hook dispatch
 * needed — the method is pure with respect to $args and the stubbed
 * `WC_AI_Storefront::get_settings()`).
 *
 * The settings stub (tests/php/stubs/class-wc-ai-storefront-stub.php)
 * exposes `$test_settings` — each test seeds what it needs and the
 * filter reads through that mechanism.
 *
 * @package WooCommerce_AI_Storefront
 */

class UcpStoreApiFilterTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Reset the settings stub between tests so state doesn't leak.
	 *
	 * Calls `parent::setUp()` for consistency with the other PHPUnit
	 * test cases in this suite — if the base TestCase ever adds setup
	 * behavior (e.g. via Brain\Monkey integration, fixture loading),
	 * skipping the parent call would silently diverge initialization
	 * state across tests.
	 *
	 * Under the 0.1.5 UNION model, `apply_union_restriction()` calls
	 * `taxonomy_exists( 'product_brand' )` on every by_taxonomy
	 * invocation — not just brand-configured ones — so a default stub
	 * is provided here. Tests that need a different return value
	 * (e.g. brand-downgrade scenarios) override `taxonomy_exists`
	 * inline while relying on this shared `setUp()` / `tearDown()`
	 * Brain\Monkey lifecycle.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\Brain\Monkey\Functions\when( 'taxonomy_exists' )->justReturn( true );
		WC_AI_Storefront::$test_settings = [];

		// As of 0.1.7 the filter is gated to UCP-controller-
		// initiated dispatches via an `is_in_ucp_dispatch()`
		// counter check. Tests that exercise the enforcement
		// behavior need to enter that scope; tearDown exits it
		// so a leak from one test can't contaminate another.
		// The dedicated test
		// `test_filter_is_noop_outside_ucp_dispatch` exits before
		// asserting, then re-enters in its own finally so this
		// shared lifecycle stays consistent.
		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
	}

	protected function tearDown(): void {
		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// UCP-dispatch gate (0.1.7+)
	// ------------------------------------------------------------------

	public function test_filter_is_noop_outside_ucp_dispatch(): void {
		// 0.1.7 scoped the filter to UCP-controller-initiated
		// dispatches so unrelated Store API consumers (front-end
		// cart, block-theme Checkout, themes, third-party plugins)
		// aren't silently scoped to the merchant's AI settings.
		// The shared setUp() enters UCP scope; this test exits to
		// exercise the no-op path, then re-enters so tearDown's
		// pair stays balanced.
		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		try {
			WC_AI_Storefront::$test_settings = [
				'product_selection_mode' => 'by_taxonomy',
				'selected_categories'    => [ 5, 12 ],
			];

			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$input  = [ 'orderby' => 'date', 'posts_per_page' => 10 ];
			$result = $filter->restrict_to_syndicated_products( $input );

			// No tax_query, no post__in injection — the filter
			// short-circuited because we're outside UCP scope.
			$this->assertSame( $input, $result );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		}
	}

	public function test_dispatch_depth_counter_balances(): void {
		// Counter starts at 1 (set by shared setUp). Verify the
		// enter/exit pair is balanced so a nested dispatch
		// doesn't leak depth into subsequent tests.
		$this->assertTrue( \WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch() );

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		$this->assertTrue( \WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch() );

		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		$this->assertTrue( \WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch() );

		// Excessive exit_ucp_dispatch() is idempotent (clamps
		// to zero) so a buggy controller can't drive the counter
		// negative.
		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		$this->assertFalse( \WC_AI_Storefront_UCP_Store_API_Filter::is_in_ucp_dispatch() );

		// Restore so tearDown's exit_ucp_dispatch can decrement
		// to a clean state (zero) before the next test's setUp.
		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
	}

	// ------------------------------------------------------------------
	// Mode: all
	// ------------------------------------------------------------------

	public function test_all_mode_does_not_modify_query_args(): void {
		// Default mode "all" = no restriction. The filter is a passthrough,
		// preserving whatever the Store API / WC block-theme cart / etc.
		// originally asked for.
		WC_AI_Storefront::$test_settings = [ 'product_selection_mode' => 'all' ];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$input  = [ 'orderby' => 'date', 'posts_per_page' => 10 ];
		$result = $filter->restrict_to_syndicated_products( $input );

		$this->assertSame( $input, $result );
	}

	public function test_missing_mode_defaults_to_all_behavior(): void {
		// Defensive: settings with no product_selection_mode key should
		// not blow up. The filter treats missing as "all" (no-op).
		WC_AI_Storefront::$test_settings = [];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$input  = [ 'orderby' => 'date' ];
		$result = $filter->restrict_to_syndicated_products( $input );

		$this->assertSame( $input, $result );
	}

	// ------------------------------------------------------------------
	// Mode: by_taxonomy (0.1.5 UNION model)
	//
	// Under `by_taxonomy`, selections across `selected_categories`,
	// `selected_tags`, and `selected_brands` are combined with an OR
	// relation. The emitted `tax_query` is a single clause-set of the
	// shape `[ 'relation' => 'OR', <clause>, <clause>, ... ]`. The
	// tests below mirror the three pre-0.1.5 "single-taxonomy" modes
	// by exercising a by_taxonomy configuration with only one of the
	// three arrays populated — which produces a one-clause OR.
	// ------------------------------------------------------------------

	public function test_by_taxonomy_mode_categories_only_emits_single_clause_or(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5, 12 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayHasKey( 'tax_query', $result );
		$this->assertSame( 'OR', $result['tax_query']['relation'] );
		$this->assertEquals(
			[
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => [ 5, 12 ],
			],
			$result['tax_query'][0]
		);
		// No stray second clause.
		$this->assertArrayNotHasKey( 1, $result['tax_query'] );
	}

	public function test_by_taxonomy_mode_preserves_existing_tax_query_via_and_wrapper(): void {
		// When the caller already supplied a `tax_query` (e.g. Store
		// API's own `?category=X` filter), the new UNION shape can't
		// just append a clause — that would dilute "must be in both"
		// semantics. Instead the production code wraps both the
		// caller's clauses AND our UNION clause in an outer
		// `AND`-relation tax_query: "the caller's intent still holds
		// AND our UNION restriction also holds."
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 10 ],
		];

		$incoming_tax_query = [
			[
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => [ 'sale' ],
			],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products(
			[ 'tax_query' => $incoming_tax_query ]
		);

		$this->assertSame( 'AND', $result['tax_query']['relation'] );
		// Caller's tax_query preserved at index 0.
		$this->assertSame( $incoming_tax_query, $result['tax_query'][0] );
		// Our UNION clause lands at index 1 with OR relation + one clause.
		$this->assertSame( 'OR', $result['tax_query'][1]['relation'] );
		$this->assertEquals( 'term_id', $result['tax_query'][1][0]['field'] );
		$this->assertEquals( [ 10 ], $result['tax_query'][1][0]['terms'] );
	}

	public function test_by_taxonomy_mode_with_empty_selected_categories_forces_zero_matches(): void {
		// Merchant picked "by_taxonomy" but no categories/tags/brands
		// are selected. The enforcement policy is "hide all products"
		// to keep the Store API + llms.txt/JSON-LD gates in lockstep
		// (the latter also returns false in this state via
		// `WC_AI_Storefront::is_product_syndicated()`).
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [ 'orderby' => 'date' ] );

		// `post__in = [0]` is the same never-valid-ID sentinel the
		// `selected` branch uses for empty intersections.
		$this->assertSame( [ 0 ], $result['post__in'] );
	}

	public function test_by_taxonomy_mode_absints_stringy_ids_before_tax_query(): void {
		// Settings are normalized by get_settings(), but defensive double-
		// cast here guards against legacy stored options or filter
		// interception producing non-int IDs. WordPress `absint()` =
		// `abs((int) $v)`, so negatives flip to positive and strings
		// coerce numerically.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ '5', '12', -3 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertSame( [ 5, 12, 3 ], $result['tax_query'][0]['terms'] );
	}

	// ------------------------------------------------------------------
	// by_taxonomy: tags-only selection (one clause under UNION)
	// ------------------------------------------------------------------

	public function test_by_taxonomy_mode_tags_only_emits_single_clause_or(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_tags'          => [ 7, 21 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayHasKey( 'tax_query', $result );
		$this->assertSame( 'OR', $result['tax_query']['relation'] );
		$this->assertEquals(
			[
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => [ 7, 21 ],
			],
			$result['tax_query'][0]
		);
		$this->assertArrayNotHasKey( 1, $result['tax_query'] );
	}

	// ------------------------------------------------------------------
	// Mode: brands
	//
	// `product_brand` is a WC 9.5+ native taxonomy. The production
	// filter also guards with `taxonomy_exists( 'product_brand' )` to
	// avoid emitting a tax_query against an unregistered taxonomy. In
	// these unit tests `taxonomy_exists()` is mocked via Brain\Monkey
	// so we can exercise both registered and unregistered states
	// without a full WP bootstrap.
	// ------------------------------------------------------------------

	public function test_by_taxonomy_mode_brands_only_emits_single_clause_or_when_taxonomy_registered(): void {
		// `taxonomy_exists` default in setUp() returns true — matches
		// a store where `product_brand` is registered (WC 9.5+).
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_brands'        => [ 3, 14, 42 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayHasKey( 'tax_query', $result );
		$this->assertSame( 'OR', $result['tax_query']['relation'] );
		$this->assertEquals(
			[
				'taxonomy' => 'product_brand',
				'field'    => 'term_id',
				'terms'    => [ 3, 14, 42 ],
			],
			$result['tax_query'][0]
		);
		$this->assertArrayNotHasKey( 1, $result['tax_query'] );
	}

	public function test_by_taxonomy_brand_only_downgrade_is_noop_when_taxonomy_not_registered(): void {
		// Graceful degradation: on pre-WC-9.5 stores (or any env that
		// unregisters `product_brand`), the filter must not emit a
		// tax_query entry — doing so would fatal in WP_Query's
		// taxonomy resolution. Admin UI hides the Brands segment when
		// this is the case, so this code path is a defense in depth
		// for merchants who somehow persisted the mode before the
		// taxonomy disappeared (plugin deactivation, custom registration).
		//
		// Under the 0.1.5 UNION model the exception is narrower: it
		// fires only when brands is the ONLY configured taxonomy. A
		// stored but unenforceable brand selection alongside
		// categories or tags is simply dropped from the UNION (see
		// `test_union_mode_skips_brands_when_taxonomy_unregistered_but_cats_tags_set`).
		//
		// Unlike the empty-selection case, this is a genuine no-op
		// (args unchanged) — returning [0] here would hide the
		// catalog on a downgrade scenario the merchant didn't opt
		// into. Declining to act preserves the pre-downgrade view.
		\Brain\Monkey\Functions\when( 'taxonomy_exists' )->justReturn( false );

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_brands'        => [ 3, 14 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$input  = [ 'orderby' => 'date' ];
		$result = $filter->restrict_to_syndicated_products( $input );

		$this->assertSame( $input, $result );
	}

	public function test_by_taxonomy_mode_with_empty_selected_brands_forces_zero_matches(): void {
		// Parallel to categories + tags empty-selection policy.
		// `taxonomy_exists` default stub returns true (taxonomy is
		// registered); the merchant just hasn't picked any brands yet.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_brands'        => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [ 'orderby' => 'date' ] );

		$this->assertSame( [ 0 ], $result['post__in'] );
	}

	// ------------------------------------------------------------------
	// UNION semantics — the 0.1.5 core behavior
	// ------------------------------------------------------------------

	public function test_union_mode_combines_categories_tags_brands_with_or_relation(): void {
		// All three taxonomies have selections. The emitted tax_query
		// is a single-level OR-clause with three inner clauses — a
		// product matches if it's in cat 5, cat 12, tag 7, tag 21,
		// brand 3, OR brand 42.
		// `taxonomy_exists` default stub returns true (brands registered).
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5, 12 ],
			'selected_tags'          => [ 7, 21 ],
			'selected_brands'        => [ 3, 42 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayHasKey( 'tax_query', $result );
		$this->assertSame( 'OR', $result['tax_query']['relation'] );

		// Three clauses, one per taxonomy, at positional indices 0..2.
		$this->assertEquals(
			[
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => [ 5, 12 ],
			],
			$result['tax_query'][0]
		);
		$this->assertEquals(
			[
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => [ 7, 21 ],
			],
			$result['tax_query'][1]
		);
		$this->assertEquals(
			[
				'taxonomy' => 'product_brand',
				'field'    => 'term_id',
				'terms'    => [ 3, 42 ],
			],
			$result['tax_query'][2]
		);

		// No stray fourth clause.
		$this->assertArrayNotHasKey( 3, $result['tax_query'] );
	}

	public function test_union_mode_skips_brands_when_taxonomy_unregistered_but_cats_tags_set(): void {
		// Partial-downgrade scenario: the merchant configured all
		// three taxonomies on a WC 9.5+ store, then product_brand
		// disappeared (plugin deactivation, custom unregistration).
		// Because categories + tags are also configured, the outer
		// "brand-only downgrade no-op" doesn't apply; instead we
		// silently drop the unenforceable brand clause and emit the
		// remaining two-clause UNION.
		\Brain\Monkey\Functions\when( 'taxonomy_exists' )->justReturn( false );

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5 ],
			'selected_tags'          => [ 7 ],
			'selected_brands'        => [ 3 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayHasKey( 'tax_query', $result );
		$this->assertSame( 'OR', $result['tax_query']['relation'] );

		// Only two clauses: product_cat + product_tag. No product_brand.
		$this->assertEquals( 'product_cat', $result['tax_query'][0]['taxonomy'] );
		$this->assertEquals( 'product_tag', $result['tax_query'][1]['taxonomy'] );
		$this->assertArrayNotHasKey( 2, $result['tax_query'] );

		// Defense in depth: no product_brand clause anywhere.
		foreach ( $result['tax_query'] as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}
			$this->assertNotEquals( 'product_brand', $clause['taxonomy'] );
		}
	}

	public function test_legacy_categories_mode_routed_through_union_fallback(): void {
		// Defensive legacy-mode fallback: a caller that constructs
		// settings with the pre-0.1.5 `categories` mode (or an option
		// row that pre-dates the silent migration) still gets correct
		// UNION enforcement. The production code rewrites
		// `categories|tags|brands` → `by_taxonomy` before enforcement,
		// so the output must match a direct `by_taxonomy`
		// configuration with the same selection.
		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'categories',
			'selected_categories'    => [ 5 ],
		];
		$legacy_result = $filter->restrict_to_syndicated_products( [] );

		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5 ],
		];
		$canonical_result = $filter->restrict_to_syndicated_products( [] );

		$this->assertEquals( $canonical_result, $legacy_result );
	}

	public function test_union_mode_empty_all_three_arrays_forces_zero_matches(): void {
		// `by_taxonomy` with all three selection arrays empty → there's
		// nothing enforceable, so the filter forces `post__in = [0]`
		// (zero matches). Mirrors `is_product_syndicated()` returning
		// false in the same state so llms.txt / JSON-LD and the Store
		// API catalog stay in lockstep.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
			'selected_tags'          => [],
			'selected_brands'        => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [ 'orderby' => 'date' ] );

		$this->assertSame( [ 0 ], $result['post__in'] );
	}

	// ------------------------------------------------------------------
	// Mode: selected (post__in)
	// ------------------------------------------------------------------

	public function test_selected_mode_sets_post_in_when_no_existing_restriction(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 101, 202, 303 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertEquals( [ 101, 202, 303 ], $result['post__in'] );
	}

	public function test_selected_mode_intersects_with_incoming_post_in(): void {
		// Critical behavior: if an incoming request already narrowed to
		// [1, 2, 3] (e.g. block-theme cart fetching specific items), we
		// INTERSECT with the merchant's allow-list rather than replacing.
		// Replacing would let the agent see products the original
		// request explicitly excluded.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 2, 4, 6 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products(
			[ 'post__in' => [ 1, 2, 3 ] ]
		);

		$this->assertSame( [ 2 ], $result['post__in'] );
	}

	public function test_selected_mode_empty_intersection_forces_zero_matches(): void {
		// WP_Query gotcha: post__in = [] returns ALL posts. When the
		// intersection is empty (caller wants products the merchant
		// hasn't whitelisted), we substitute [0] — never a valid post
		// ID — to force zero results. Without this, an incompatible
		// filter combination would silently match everything, the
		// opposite of what the merchant configured.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 2, 4 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products(
			[ 'post__in' => [ 99, 100 ] ]
		);

		$this->assertSame( [ 0 ], $result['post__in'] );
	}

	public function test_selected_mode_with_empty_selected_products_forces_zero_matches(): void {
		// 0.1.7 semantic fix: empty `selected_products` under
		// `selected` mode now forces `post__in = [0]` instead of
		// returning args unchanged. The previous behavior let an
		// empty allow-list expose the entire catalog, contradicting
		// `is_product_syndicated()` (returns false in the same
		// state) and `get_product_count()` (returns 0). All three
		// gates now agree: empty allow-list => zero products.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$input  = [ 'post__in' => [ 1, 2, 3 ] ];
		$result = $filter->restrict_to_syndicated_products( $input );

		$this->assertSame( [ 0 ], $result['post__in'] );
	}

	public function test_selected_mode_ignores_malformed_incoming_post_in(): void {
		// If $args['post__in'] exists but isn't a non-empty array
		// (null, scalar, empty array), treat as "no incoming restriction"
		// and apply our allow-list directly. Defends against upstream
		// filters producing unexpected shapes.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 10, 20 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();

		$result_null   = $filter->restrict_to_syndicated_products( [ 'post__in' => null ] );
		$result_scalar = $filter->restrict_to_syndicated_products( [ 'post__in' => 42 ] );
		$result_empty  = $filter->restrict_to_syndicated_products( [ 'post__in' => [] ] );

		$this->assertSame( [ 10, 20 ], $result_null['post__in'] );
		$this->assertSame( [ 10, 20 ], $result_scalar['post__in'] );
		$this->assertSame( [ 10, 20 ], $result_empty['post__in'] );
	}

	// ------------------------------------------------------------------
	// Cross-mode: one mode's fields should not leak into another
	// ------------------------------------------------------------------

	public function test_by_taxonomy_mode_does_not_set_post_in(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5 ],
			'selected_products'      => [ 999 ],  // present but ignored in by_taxonomy mode
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayNotHasKey( 'post__in', $result );
	}

	public function test_selected_mode_does_not_set_tax_query(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 42 ],
			'selected_categories'    => [ 999 ],  // present but ignored in selected mode
			'selected_tags'          => [ 888 ],  // present but ignored in selected mode
			'selected_brands'        => [ 777 ],  // present but ignored in selected mode
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayNotHasKey( 'tax_query', $result );
	}
}
