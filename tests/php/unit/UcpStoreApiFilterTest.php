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
	 */
	protected function setUp(): void {
		parent::setUp();
		WC_AI_Storefront::$test_settings = [];
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
	// Mode: categories
	// ------------------------------------------------------------------

	public function test_categories_mode_appends_tax_query_entry(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'categories',
			'selected_categories'    => [ 5, 12 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayHasKey( 'tax_query', $result );
		$this->assertCount( 1, $result['tax_query'] );
		$this->assertEquals(
			[
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => [ 5, 12 ],
			],
			$result['tax_query'][0]
		);
	}

	public function test_categories_mode_preserves_existing_tax_query_entries(): void {
		// WP_Query defaults to AND relation between tax_query entries,
		// so appending our restriction alongside an existing one (e.g.
		// Store API's own ?category=X filter) yields "both must match"
		// semantics — products in BOTH the incoming filter AND the
		// merchant's allow-list.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'categories',
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

		$this->assertCount( 2, $result['tax_query'] );
		// Incoming entry preserved at index 0.
		$this->assertEquals( 'slug', $result['tax_query'][0]['field'] );
		$this->assertEquals( [ 'sale' ], $result['tax_query'][0]['terms'] );
		// Our restriction appended at index 1.
		$this->assertEquals( 'term_id', $result['tax_query'][1]['field'] );
		$this->assertEquals( [ 10 ], $result['tax_query'][1]['terms'] );
	}

	public function test_categories_mode_with_empty_selected_categories_forces_zero_matches(): void {
		// Merchant has picked "categories" mode but not chosen any yet.
		// The enforcement policy is "hide all products" to keep the
		// Store API + llms.txt/JSON-LD gates in lockstep (the latter
		// also returns false in this state via
		// `WC_AI_Storefront::is_product_syndicated()`).
		//
		// Before the PR that added tags + brands, this branch was a
		// no-op — the Store API exposed the full catalog while
		// llms.txt hid everything. The divergence was a silent
		// enforcement inconsistency; aligning both gates to the same
		// (safer) "hide all" posture eliminates the surprise and
		// extends the identical policy across all three taxonomy
		// modes.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'categories',
			'selected_categories'    => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [ 'orderby' => 'date' ] );

		// `post__in = [0]` is the same never-valid-ID sentinel the
		// `selected` branch uses for empty intersections.
		$this->assertSame( [ 0 ], $result['post__in'] );
	}

	public function test_categories_mode_absints_stringy_ids_before_tax_query(): void {
		// Settings are normalized by get_settings(), but defensive double-
		// cast here guards against legacy stored options or filter
		// interception producing non-int IDs. WordPress `absint()` =
		// `abs((int) $v)`, so negatives flip to positive and strings
		// coerce numerically.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'categories',
			'selected_categories'    => [ '5', '12', -3 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertSame( [ 5, 12, 3 ], $result['tax_query'][0]['terms'] );
	}

	// ------------------------------------------------------------------
	// Mode: tags
	// ------------------------------------------------------------------

	public function test_tags_mode_appends_tax_query_entry(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'tags',
			'selected_tags'          => [ 7, 21 ],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayHasKey( 'tax_query', $result );
		$this->assertCount( 1, $result['tax_query'] );
		$this->assertEquals(
			[
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => [ 7, 21 ],
			],
			$result['tax_query'][0]
		);
	}

	public function test_tags_mode_with_empty_selected_tags_forces_zero_matches(): void {
		// Parallel to the categories empty-selection policy: hide all
		// products via `post__in = [0]` so the Store API agent view
		// matches what llms.txt/JSON-LD emit for the same state.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'tags',
			'selected_tags'          => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [ 'orderby' => 'date' ] );

		$this->assertSame( [ 0 ], $result['post__in'] );
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

	public function test_brands_mode_appends_tax_query_entry_when_taxonomy_registered(): void {
		\Brain\Monkey\setUp();
		try {
			\Brain\Monkey\Functions\when( 'taxonomy_exists' )->justReturn( true );

			WC_AI_Storefront::$test_settings = [
				'product_selection_mode' => 'brands',
				'selected_brands'        => [ 3, 14, 42 ],
			];

			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$result = $filter->restrict_to_syndicated_products( [] );

			$this->assertArrayHasKey( 'tax_query', $result );
			$this->assertCount( 1, $result['tax_query'] );
			$this->assertEquals(
				[
					'taxonomy' => 'product_brand',
					'field'    => 'term_id',
					'terms'    => [ 3, 14, 42 ],
				],
				$result['tax_query'][0]
			);
		} finally {
			\Brain\Monkey\tearDown();
		}
	}

	public function test_brands_mode_is_noop_when_taxonomy_not_registered(): void {
		// Graceful degradation: on pre-WC-9.5 stores (or any env that
		// unregisters `product_brand`), the filter must not emit a
		// tax_query entry — doing so would fatal in WP_Query's
		// taxonomy resolution. Admin UI hides the Brands segment when
		// this is the case, so this code path is a defense in depth
		// for merchants who somehow persisted the mode before the
		// taxonomy disappeared (plugin deactivation, custom registration).
		//
		// Unlike the empty-selection case, this is a genuine no-op
		// (args unchanged) — returning [0] here would hide the
		// catalog on a downgrade scenario the merchant didn't opt
		// into. Declining to act preserves the pre-downgrade view.
		\Brain\Monkey\setUp();
		try {
			\Brain\Monkey\Functions\when( 'taxonomy_exists' )->justReturn( false );

			WC_AI_Storefront::$test_settings = [
				'product_selection_mode' => 'brands',
				'selected_brands'        => [ 3, 14 ],
			];

			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$input  = [ 'orderby' => 'date' ];
			$result = $filter->restrict_to_syndicated_products( $input );

			$this->assertSame( $input, $result );
		} finally {
			\Brain\Monkey\tearDown();
		}
	}

	public function test_brands_mode_with_empty_selected_brands_forces_zero_matches(): void {
		// Parallel to categories + tags empty-selection policy.
		// `taxonomy_exists` returns true (taxonomy is registered);
		// the merchant just hasn't picked any brands yet.
		\Brain\Monkey\setUp();
		try {
			\Brain\Monkey\Functions\when( 'taxonomy_exists' )->justReturn( true );

			WC_AI_Storefront::$test_settings = [
				'product_selection_mode' => 'brands',
				'selected_brands'        => [],
			];

			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$result = $filter->restrict_to_syndicated_products( [ 'orderby' => 'date' ] );

			$this->assertSame( [ 0 ], $result['post__in'] );
		} finally {
			\Brain\Monkey\tearDown();
		}
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

	public function test_selected_mode_with_empty_selected_products_is_noop(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$input  = [ 'post__in' => [ 1, 2, 3 ] ];
		$result = $filter->restrict_to_syndicated_products( $input );

		// Incoming post__in unchanged — empty allow-list means the
		// merchant hasn't configured the mode yet; don't apply an
		// empty restriction.
		$this->assertSame( [ 1, 2, 3 ], $result['post__in'] );
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

	public function test_categories_mode_does_not_set_post_in(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'categories',
			'selected_categories'    => [ 5 ],
			'selected_products'      => [ 999 ],  // present but ignored in categories mode
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
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$result = $filter->restrict_to_syndicated_products( [] );

		$this->assertArrayNotHasKey( 'tax_query', $result );
	}
}
