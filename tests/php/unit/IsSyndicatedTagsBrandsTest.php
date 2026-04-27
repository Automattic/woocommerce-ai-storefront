<?php
/**
 * Tests for the tags and brands branches of WC_AI_Storefront::is_product_syndicated().
 *
 * `is_product_syndicated()` gates llms.txt, JSON-LD, and UCP catalog output
 * on a per-product basis. Tags and brands were added in PR #65 alongside
 * Store API enforcement (UcpStoreApiFilterTest covers that side); these tests
 * pin the per-product gate so the two surfaces can't silently diverge again.
 *
 * Brain\Monkey stubs the WP functions required by the tags/brands branches
 * (`wp_get_post_terms`, `taxonomy_exists`). Mockery provides WC_Product
 * doubles via the MockeryPHPUnitIntegration trait (auto-close on tearDown).
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class IsSyndicatedTagsBrandsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = [];
		// Defense in depth: reset the variation-redirect map even
		// though no test in this file uses it. Static properties
		// persist across PHPUnit instances within the same process,
		// so a future variation test elsewhere that forgets its
		// tearDown could otherwise pollute these tests' product_id
		// → parent_id resolution.
		WC_AI_Storefront::$test_variations = [];

		// Default: brands taxonomy registered. Under 0.1.5's UNION
		// gate, `taxonomy_exists('product_brand')` is consulted on
		// every `by_taxonomy` invocation (not just brand-configured
		// ones) to decide whether to treat `selected_brands` as
		// enforceable. Tests that exercise the unregistered path
		// override this with their own `Functions\when()->justReturn(false)`.
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

	// ------------------------------------------------------------------
	// Mode: tags — ANY-match
	// ------------------------------------------------------------------

	public function test_tags_mode_returns_true_when_product_has_a_matching_tag(): void {
		// Product carries tags [3, 7]; merchant selected [7, 12].
		// ANY-match: tag 7 is in both → syndicated.
		Functions\when( 'wp_get_post_terms' )->justReturn( [ 3, 7 ] );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'tags',
				'selected_tags'          => [ 7, 12 ],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_tags_mode_returns_false_when_product_has_no_matching_tag(): void {
		// Product carries tags [1, 2]; merchant selected [7, 12].
		// No overlap → not syndicated.
		Functions\when( 'wp_get_post_terms' )->justReturn( [ 1, 2 ] );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'tags',
				'selected_tags'          => [ 7, 12 ],
			]
		);

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Mode: tags — empty selection hides everything
	// ------------------------------------------------------------------

	public function test_tags_mode_with_empty_selected_tags_returns_false(): void {
		// Merchant enabled tags mode but hasn't picked any tags yet.
		// The `!empty(selected_tags)` guard short-circuits to false —
		// wp_get_post_terms is NOT called (no stub needed).
		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'tags',
				'selected_tags'          => [],
			]
		);

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Mode: brands — unregistered taxonomy (graceful degradation)
	// ------------------------------------------------------------------

	public function test_brands_mode_returns_true_when_taxonomy_not_registered(): void {
		// Pre-WC-9.5 or custom env: `product_brand` taxonomy absent.
		// Gate must return true regardless of `selected_brands` content
		// to stay symmetric with the Store API filter, which also skips
		// enforcement in this scenario. Hiding the catalog due to an
		// environment change the merchant didn't initiate would be
		// surprising and hard to recover from.
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'brands',
				'selected_brands'        => [ 5, 9 ],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_brands_mode_returns_false_when_taxonomy_not_registered_and_selected_brands_empty(): void {
		// 0.1.5 UNION semantics: when ALL three `selected_*` arrays
		// are empty, the empty-selection policy hides the catalog
		// regardless of taxonomy registration state. The downgrade-
		// safe "show all" exception is gated on `selected_brands`
		// being NON-EMPTY (preserving merchant intent across an
		// environment change) — empty arrays mean "nothing
		// configured" and have no prior intent to preserve.
		//
		// Pre-0.1.5 behavior: this scenario returned true because
		// the taxonomy-missing guard fired before any empty check.
		// Updated for the consolidated UNION model.
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'brands',
				'selected_brands'        => [],
			]
		);

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Mode: brands — registered taxonomy, empty selection hides everything
	// ------------------------------------------------------------------

	public function test_brands_mode_with_empty_selected_brands_returns_false(): void {
		// Taxonomy registered but no brands chosen: mirrors the Store
		// API filter's `post__in = [0]` posture for the same state.
		Functions\when( 'taxonomy_exists' )->justReturn( true );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'brands',
				'selected_brands'        => [],
			]
		);

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Mode: brands — ANY-match via wp_get_post_terms
	// ------------------------------------------------------------------

	public function test_brands_mode_returns_true_when_product_has_a_matching_brand(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ 5, 11 ] );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'brands',
				'selected_brands'        => [ 5, 20 ],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_brands_mode_returns_false_when_product_has_no_matching_brand(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ 3, 8 ] );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'brands',
				'selected_brands'        => [ 5, 20 ],
			]
		);

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// WP_Error fallback
	// ------------------------------------------------------------------

	public function test_tags_mode_returns_false_when_wp_get_post_terms_returns_wp_error(): void {
		// wp_get_post_terms can return WP_Error on taxonomy misconfiguration.
		// The gate must fail-closed (not syndicated) rather than open.
		Functions\when( 'wp_get_post_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'Taxonomy not found.' ) );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'tags',
				'selected_tags'          => [ 7 ],
			]
		);

		$this->assertFalse( $result );
	}

	public function test_brands_mode_returns_false_when_wp_get_post_terms_returns_wp_error(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'Taxonomy not found.' ) );

		$result = WC_AI_Storefront::is_product_syndicated(
			$this->make_product( 42 ),
			[
				'product_selection_mode' => 'brands',
				'selected_brands'        => [ 5 ],
			]
		);

		$this->assertFalse( $result );
	}
}
