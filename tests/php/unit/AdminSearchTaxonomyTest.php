<?php
/**
 * Tests for WC_AI_Storefront_Admin_Controller::search_tags() and
 * ::search_brands().
 *
 * Contract test: the frontend product-selection panel depends on the
 * `{ id, name, slug, count }` shape returned by these endpoints.
 * An accidental key rename would silently blank out the token list
 * in the UI without breaking any other test — this file locks the
 * contract.
 *
 * Brands also has a graceful-degradation requirement: when
 * `product_brand` is unregistered (pre-WC-9.5 or custom env), the
 * endpoint must return `[]`, not fatal on `get_terms()`. Admin UI
 * gates the Brands segment on the `supportsBrands` bootstrap flag
 * but the REST endpoint is still registered and reachable, so it
 * must behave correctly even if a client calls it directly.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AdminSearchTaxonomyTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Admin_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->controller = new WC_AI_Storefront_Admin_Controller();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// search_tags
	// ------------------------------------------------------------------

	public function test_search_tags_returns_shape_compatible_data(): void {
		// Build stdClass term objects mirroring what WP's get_terms()
		// returns. The controller treats the result as opaque and only
		// reads `term_id`, `name`, `slug`, `count` — so we only set
		// those, not the full WP_Term public surface.
		$term1        = new stdClass();
		$term1->term_id = 11;
		$term1->name    = 'Summer';
		$term1->slug    = 'summer';
		$term1->count   = 42;

		$term2        = new stdClass();
		$term2->term_id = 22;
		$term2->name    = 'Sale';
		$term2->slug    = 'sale';
		$term2->count   = 7;

		Functions\expect( 'get_terms' )
			->once()
			->with(
				Mockery::on(
					static function ( $args ) {
						return 'product_tag' === ( $args['taxonomy'] ?? null )
							&& false === ( $args['hide_empty'] ?? true )
							&& 'name' === ( $args['orderby'] ?? null );
					}
				)
			)
			->andReturn( [ $term1, $term2 ] );

		$response = $this->controller->search_tags();
		$data     = $response->get_data();

		$this->assertCount( 2, $data );
		$this->assertSame(
			[ 'id' => 11, 'name' => 'Summer', 'slug' => 'summer', 'count' => 42 ],
			$data[0]
		);
		$this->assertSame(
			[ 'id' => 22, 'name' => 'Sale', 'slug' => 'sale', 'count' => 7 ],
			$data[1]
		);
	}

	public function test_search_tags_returns_empty_array_on_wp_error(): void {
		// If get_terms() returns WP_Error (invalid taxonomy or transient
		// DB issue), the endpoint should degrade gracefully to an empty
		// list rather than surface a REST error to the admin UI.
		Functions\expect( 'get_terms' )
			->once()
			->andReturn( new WP_Error( 'invalid_taxonomy' ) );

		$response = $this->controller->search_tags();
		$this->assertSame( [], $response->get_data() );
	}

	// ------------------------------------------------------------------
	// search_brands
	// ------------------------------------------------------------------

	public function test_search_brands_returns_empty_array_when_taxonomy_not_registered(): void {
		// The graceful-degradation contract: older WC stores (< 9.5)
		// don't have `product_brand` registered. The admin UI hides
		// the Brands segment via the `supportsBrands` bootstrap flag,
		// but the REST endpoint stays mounted — a direct call must
		// return `[]` without touching get_terms().
		Functions\expect( 'taxonomy_exists' )
			->once()
			->with( 'product_brand' )
			->andReturn( false );

		// get_terms() must not be called when the taxonomy is missing.
		Functions\expect( 'get_terms' )->never();

		$response = $this->controller->search_brands();
		$this->assertSame( [], $response->get_data() );
	}

	public function test_search_brands_returns_shape_compatible_data_when_registered(): void {
		$term        = new stdClass();
		$term->term_id = 5;
		$term->name    = 'Adidas';
		$term->slug    = 'adidas';
		$term->count   = 18;

		Functions\expect( 'taxonomy_exists' )
			->once()
			->with( 'product_brand' )
			->andReturn( true );

		Functions\expect( 'get_terms' )
			->once()
			->with(
				Mockery::on(
					static function ( $args ) {
						return 'product_brand' === ( $args['taxonomy'] ?? null );
					}
				)
			)
			->andReturn( [ $term ] );

		$response = $this->controller->search_brands();
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame(
			[ 'id' => 5, 'name' => 'Adidas', 'slug' => 'adidas', 'count' => 18 ],
			$data[0]
		);
	}
}
