<?php
/**
 * Tests for WC_AI_Storefront_Admin_Controller::get_product_count().
 *
 * Pins the count semantics for every mode so the Overview "Products
 * Exposed" card can't silently drift if enforcement logic changes:
 *
 *   - `all`         → published product count via wp_count_posts
 *   - `selected`    → 0 when list is empty; WP_Query found_posts otherwise
 *   - `by_taxonomy` → 0 when all arrays empty; WP_Query UNION count
 *                     when at least one taxonomy is populated; full
 *                     count (show-all) when the brand-downgrade
 *                     exception fires
 *   - legacy modes  → coerced to by_taxonomy via the defensive fallback
 *
 * Brain\Monkey stubs `wp_count_posts` and `taxonomy_exists`.
 * WP_Query is covered by the WP_Query stub class in stubs.php —
 * tests set `WP_Query::$test_found_posts` before calling the endpoint.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class AdminProductCountTest extends \PHPUnit\Framework\TestCase {

	private WC_AI_Storefront_Admin_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		WC_AI_Storefront::$test_settings = [];
		WP_Query::$test_found_posts      = 0;
		$this->controller                = new WC_AI_Storefront_Admin_Controller();
	}

	protected function tearDown(): void {
		WP_Query::$test_found_posts = 0;
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Mode: all
	// ------------------------------------------------------------------

	public function test_all_mode_returns_published_product_count(): void {
		WC_AI_Storefront::$test_settings = [ 'product_selection_mode' => 'all' ];

		$counts          = new stdClass();
		$counts->publish = 57;
		Functions\when( 'wp_count_posts' )->justReturn( $counts );

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 57 ], $response->data );
	}

	public function test_all_mode_returns_zero_when_publish_property_missing(): void {
		WC_AI_Storefront::$test_settings = [ 'product_selection_mode' => 'all' ];

		Functions\when( 'wp_count_posts' )->justReturn( new stdClass() );

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 0 ], $response->data );
	}

	// ------------------------------------------------------------------
	// Mode: selected
	// ------------------------------------------------------------------

	public function test_selected_mode_with_empty_list_returns_zero(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [],
		];

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 0 ], $response->data );
	}

	public function test_selected_mode_returns_wp_query_found_posts(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'selected',
			'selected_products'      => [ 10, 20, 30 ],
		];
		WP_Query::$test_found_posts = 3;

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 3 ], $response->data );
	}

	// ------------------------------------------------------------------
	// Mode: by_taxonomy — empty selection
	// ------------------------------------------------------------------

	public function test_by_taxonomy_with_all_empty_arrays_returns_zero(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
			'selected_tags'          => [],
			'selected_brands'        => [],
		];

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 0 ], $response->data );
	}

	// ------------------------------------------------------------------
	// Mode: by_taxonomy — UNION query
	// ------------------------------------------------------------------

	public function test_by_taxonomy_returns_union_found_posts(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [ 3, 7 ],
			'selected_tags'          => [ 5 ],
			'selected_brands'        => [],
		];
		WP_Query::$test_found_posts = 12;

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 12 ], $response->data );
	}

	public function test_by_taxonomy_with_only_brands_returns_query_count(): void {
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
			'selected_tags'          => [],
			'selected_brands'        => [ 9 ],
		];
		WP_Query::$test_found_posts = 8;
		// taxonomy_exists returns true from setUp — brands are enforced.

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 8 ], $response->data );
	}

	// ------------------------------------------------------------------
	// Mode: by_taxonomy — brand-downgrade show-all exception
	// ------------------------------------------------------------------

	public function test_by_taxonomy_brand_downgrade_returns_full_published_count(): void {
		// Only brands selected, but product_brand taxonomy is unregistered
		// (pre-WC-9.5 or custom env). Server enforces show-all; count
		// must match to avoid a misleading "0 products" on the card.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
			'selected_tags'          => [],
			'selected_brands'        => [ 12 ],
		];
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$counts          = new stdClass();
		$counts->publish = 100;
		Functions\when( 'wp_count_posts' )->justReturn( $counts );

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 100 ], $response->data );
	}

	// ------------------------------------------------------------------
	// Legacy mode fallback (defensive coercion to by_taxonomy)
	// ------------------------------------------------------------------

	public function test_legacy_tags_mode_is_coerced_to_by_taxonomy(): void {
		// Pre-0.1.5 stored mode 'tags' — defensive fallback in
		// get_product_count() must treat it as by_taxonomy. With
		// selected_tags non-empty and the taxonomy registered, the
		// UNION query runs and found_posts is returned.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'tags',
			'selected_categories'    => [],
			'selected_tags'          => [ 7, 8 ],
			'selected_brands'        => [],
		];
		WP_Query::$test_found_posts = 5;

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 5 ], $response->data );
	}

	// ------------------------------------------------------------------
	// Request parameter overrides (live-preview path for the Products
	// tab's by_taxonomy row count pill — saved settings stay untouched
	// while the merchant explores selections).
	// ------------------------------------------------------------------

	public function test_request_param_mode_overrides_saved_settings(): void {
		// Saved mode is `all`; the request-time `mode=by_taxonomy`
		// override flips the path so the by_taxonomy branch runs
		// against the merchant's configured (saved) selections —
		// letting the merchant preview "what would happen if I
		// switched to by_taxonomy" without committing.
		//
		// `wp_count_posts` is intentionally NOT stubbed here: under
		// the override the by_taxonomy/WP_Query path runs, and
		// reaching `wp_count_posts` would mean the override didn't
		// take effect (the saved `all` mode leaked through). If the
		// override regresses the test will fail loudly with an
		// undefined-function error rather than silently returning a
		// stale count.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'all',
			'selected_categories'    => [ 3 ],
			'selected_tags'          => [],
			'selected_brands'        => [],
		];
		WP_Query::$test_found_posts = 12;

		$request = new WP_REST_Request();
		$request->set_param( 'mode', 'by_taxonomy' );

		$response = $this->controller->get_product_count( $request );

		$this->assertSame( [ 'count' => 12 ], $response->data );
	}

	public function test_request_param_selected_categories_overrides_saved_settings(): void {
		// Saved selected_categories is empty — would return 0 under
		// by_taxonomy with empty selections. The request-time
		// override `selected_categories=[5,9]` previews a count
		// against the hypothetical selection without persisting.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'by_taxonomy',
			'selected_categories'    => [],
			'selected_tags'          => [],
			'selected_brands'        => [],
		];
		WP_Query::$test_found_posts = 17;

		$request = new WP_REST_Request();
		$request->set_param( 'selected_categories', [ 5, 9 ] );

		$response = $this->controller->get_product_count( $request );

		// With overridden selection populated, by_taxonomy runs the
		// UNION query and returns found_posts.
		$this->assertSame( [ 'count' => 17 ], $response->data );
	}

	public function test_no_request_falls_back_to_saved_settings(): void {
		// Backward compat: callers passing no request (e.g. unit
		// tests that pre-date the param-override branch, or the
		// Overview tab's own client which deliberately reads saved
		// state) get the persisted-settings count.
		WC_AI_Storefront::$test_settings = [
			'product_selection_mode' => 'all',
		];
		$counts          = new stdClass();
		$counts->publish = 33;
		Functions\when( 'wp_count_posts' )->justReturn( $counts );

		$response = $this->controller->get_product_count();

		$this->assertSame( [ 'count' => 33 ], $response->data );
	}
}
