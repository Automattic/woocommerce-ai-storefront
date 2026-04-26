<?php
/**
 * Tests for the `pre_get_posts` hook layer added in 0.1.15.
 *
 * Prior to 0.1.15, `WC_AI_Storefront_UCP_Store_API_Filter::init()`
 * registered against `woocommerce_store_api_product_collection_query_args`
 * — a hook name that does not exist in WooCommerce core, so the
 * scoping callback never ran in production. The fix re-registers
 * against `pre_get_posts` (a real WP-level hook) with a threefold
 * gate: UCP-dispatch depth, post_type === 'product', and the
 * existing per-mode logic.
 *
 * These tests exercise the hook layer specifically — gating, mode
 * dispatch, and the bridge between live `WP_Query` objects and the
 * pure args->args mutator. The args-shape mutation contract itself
 * is exercised by `UcpStoreApiFilterTest`; covering it again here
 * would just shadow that suite.
 *
 * @package WooCommerce_AI_Storefront
 */

class UcpStoreApiPreGetPostsTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\Brain\Monkey\Functions\when( 'taxonomy_exists' )->justReturn( true );
		WC_AI_Storefront::$test_settings = [];
	}

	protected function tearDown(): void {
		// Some tests enter UCP scope; defensively drain any depth
		// the test left behind so a failure in mid-test doesn't
		// leak state to the next test. Loop bound matches the
		// largest plausible nesting (current code never nests).
		for ( $i = 0; $i < 5; $i++ ) {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Gate 1: UCP-dispatch depth
	// ------------------------------------------------------------------

	public function test_pre_get_posts_no_op_outside_ucp_dispatch(): void {
		// Front-end Cart, block-theme Checkout, themes, third-party
		// Store API consumers — they all run `WP_Query` outside the
		// UCP dispatch scope. The hook must NOT mutate them, even if
		// the merchant configured aggressive scoping.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5, 12 ],
		];

		$query = new WP_Query( [ 'post_type' => 'product' ] );

		$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$filter->on_pre_get_posts( $query );

		$this->assertSame( '', $query->get( 'tax_query' ) );
		$this->assertSame( '', $query->get( 'post__in' ) );
	}

	// ------------------------------------------------------------------
	// Gate 2: post_type === 'product'
	// ------------------------------------------------------------------

	public function test_pre_get_posts_no_op_for_non_product_post_type(): void {
		// `pre_get_posts` fires for menus, widgets, related-posts
		// queries, etc. Mutating those would silently break unrelated
		// parts of the site even inside UCP scope. Only product
		// queries get touched.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5 ],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query  = new WP_Query( [ 'post_type' => 'post' ] );
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$this->assertSame( '', $query->get( 'tax_query' ) );
			$this->assertSame( '', $query->get( 'post__in' ) );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	public function test_pre_get_posts_no_op_for_post_type_array_without_product(): void {
		// Multi-type queries (e.g. cross-CPT search) also fire through
		// pre_get_posts. If `product` isn't in the array, leave the
		// query alone — same defense as the string case above.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 42 ],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query  = new WP_Query( [ 'post_type' => [ 'post', 'page' ] ] );
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$this->assertSame( '', $query->get( 'tax_query' ) );
			$this->assertSame( '', $query->get( 'post__in' ) );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	public function test_pre_get_posts_applies_when_post_type_array_contains_product(): void {
		// `post_type = ['product', 'product_variation']` is a real
		// shape that WC sometimes emits internally. The gate must
		// admit it.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 42 ],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query  = new WP_Query( [ 'post_type' => [ 'product', 'product_variation' ] ] );
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$this->assertSame( [ 42 ], $query->get( 'post__in' ) );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	// ------------------------------------------------------------------
	// Gate 3: per-mode dispatch
	// ------------------------------------------------------------------

	public function test_pre_get_posts_applies_union_for_by_taxonomy_mode(): void {
		// The full UNION decision matrix lives in
		// `UcpStoreApiFilterTest`. Here we just confirm the bridge
		// reads/writes the right key on the WP_Query.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 5, 12 ],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query  = new WP_Query( [ 'post_type' => 'product' ] );
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$tax_query = $query->get( 'tax_query' );
			$this->assertIsArray( $tax_query );
			$this->assertSame( 'OR', $tax_query['relation'] );
			$this->assertSame( 'product_cat', $tax_query[0]['taxonomy'] );
			$this->assertSame( [ 5, 12 ], $tax_query[0]['terms'] );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	public function test_pre_get_posts_applies_post_in_for_selected_mode(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 101, 202, 303 ],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query  = new WP_Query( [ 'post_type' => 'product' ] );
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$this->assertSame( [ 101, 202, 303 ], $query->get( 'post__in' ) );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	public function test_pre_get_posts_no_op_for_all_mode(): void {
		// `all` mode should leave the query untouched even when
		// gates 1+2 pass.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'all',
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query  = new WP_Query( [ 'post_type' => 'product' ] );
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$this->assertSame( '', $query->get( 'tax_query' ) );
			$this->assertSame( '', $query->get( 'post__in' ) );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	// ------------------------------------------------------------------
	// Bridge contract: tax_query merge / post__in intersect / sentinel
	// ------------------------------------------------------------------

	public function test_pre_get_posts_merges_incoming_tax_query_via_outer_and(): void {
		// A theme or another plugin running inside UCP dispatch may
		// already have constrained the WP_Query via its own tax_query
		// (e.g. "products tagged #featured"). The bridge must wrap the
		// caller's tax_query AND our UNION clause in an outer
		// AND-relation so both filters stay in effect — overriding the
		// caller would silently undo their intent.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 7 ],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$incoming_tax_query = [
				[
					'taxonomy' => 'product_tag',
					'field'    => 'slug',
					'terms'    => [ 'featured' ],
				],
			];
			$query = new WP_Query(
				[
					'post_type' => 'product',
					'tax_query' => $incoming_tax_query,
				]
			);
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$result = $query->get( 'tax_query' );
			$this->assertIsArray( $result );
			$this->assertSame( 'AND', $result['relation'] );
			// First slot = caller's clause preserved verbatim.
			$this->assertSame( $incoming_tax_query, $result[0] );
			// Second slot = our UNION block (relation OR + selected_categories).
			$this->assertSame( 'OR', $result[1]['relation'] );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	public function test_pre_get_posts_intersects_incoming_post_in_for_selected_mode(): void {
		// `selected` mode + an incoming `post__in` (e.g. theme limiting
		// to a related-products list) → intersection, not override.
		// Override would either expose the merchant's full allow-list
		// (ignoring the caller) or hide everything (ignoring the
		// merchant). Intersection respects both.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 10, 20, 30 ],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query = new WP_Query(
				[
					'post_type' => 'product',
					'post__in'  => [ 20, 30, 40 ],
				]
			);
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			// 20 and 30 in both → intersection. 40 in caller only,
			// 10 in merchant only → both excluded.
			$this->assertSame( [ 20, 30 ], $query->get( 'post__in' ) );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	public function test_pre_get_posts_preserves_post_in_zero_sentinel_for_empty_selection(): void {
		// `selected` mode with empty allow-list (or `by_taxonomy`
		// with no enforceable terms) forces `post__in = [0]` — a
		// sentinel that matches no real product ID, producing zero
		// results. WP_Query treats raw `[]` as "no filter applied",
		// which would expose the entire catalog — exactly inverted
		// from the merchant's "nothing picked yet" intent.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [],
		];

		\WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$query  = new WP_Query( [ 'post_type' => 'product' ] );
			$filter = new WC_AI_Storefront_UCP_Store_API_Filter();
			$filter->on_pre_get_posts( $query );

			$this->assertSame( [ 0 ], $query->get( 'post__in' ) );
		} finally {
			\WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}
	}

	// ------------------------------------------------------------------
	// Hook registration: regression guard against the dead hook
	// ------------------------------------------------------------------

	public function test_init_registers_against_pre_get_posts_not_legacy_hook(): void {
		// Brain Monkey lets us spy on add_action / add_filter. This
		// test asserts both halves of the contract:
		//   1. add_action('pre_get_posts', ...) is called exactly once.
		//   2. add_filter('woocommerce_store_api_product_collection_query_args', ...)
		//      is NEVER called — that hook is fictitious. If a future
		//      refactor accidentally re-registers it, this test fails
		//      loudly.
		\Brain\Monkey\Actions\expectAdded( 'pre_get_posts' )
			->once()
			->with( \Mockery::on(
				static function ( $callback ): bool {
					return is_array( $callback )
						&& $callback[0] instanceof \WC_AI_Storefront_UCP_Store_API_Filter
						&& 'on_pre_get_posts' === $callback[1];
				}
			) );

		\Brain\Monkey\Filters\expectAdded(
			'woocommerce_store_api_product_collection_query_args'
		)->never();

		( new WC_AI_Storefront_UCP_Store_API_Filter() )->init();

		// Brain Monkey's expectations get verified during tearDown
		// but PHPUnit doesn't count those as native assertions, so
		// the test would otherwise be flagged "risky" — bumping the
		// count explicitly is the canonical way to acknowledge
		// non-native assertions without a misleading assertTrue(true).
		$this->addToAssertionCount( 2 );
	}
}
