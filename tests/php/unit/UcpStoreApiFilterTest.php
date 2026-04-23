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

	public function test_categories_mode_with_empty_selected_categories_is_noop(): void {
		// Merchant has picked "categories" mode but not chosen any yet.
		// An empty terms array would hide everything — match the existing
		// llms.txt / JSON-LD behavior and treat this as no-op.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'categories',
			'selected_categories'    => [],
		];

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$input  = [ 'orderby' => 'date' ];
		$result = $filter->restrict_to_syndicated_products( $input );

		$this->assertSame( $input, $result );
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
