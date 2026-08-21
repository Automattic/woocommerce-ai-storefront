<?php
/**
 * Tests for WC_AI_Storefront_Product_Facts.
 *
 * Covers `stock_state()` and the `collect_condition_candidates()` /
 * `resolve_condition()` / `condition_slug()` trio — the shared resolvers
 * extracted from `WC_AI_Storefront_JsonLd` (#679) so the Open Graph /
 * meta-tags emitter can reach the same stock and Condition facts JSON-LD
 * does, without a second implementation to drift out of sync.
 *
 * `JsonLdTest.php` and `JsonLdConditionTest.php` continue to pin the
 * schema.org-facing behaviour (availability URLs, itemCondition) end to
 * end; this file pins the neutral facts underneath them.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ProductFactsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// stock_state()
	// ------------------------------------------------------------------

	/**
	 * @param array{in_stock?: bool, stock_status?: string} $overrides
	 */
	private function make_product_with_stock( array $overrides = array() ): Mockery\MockInterface {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( $overrides['in_stock'] ?? true );
		$product->shouldReceive( 'get_stock_status' )->andReturn( $overrides['stock_status'] ?? 'instock' );
		return $product;
	}

	public function test_in_stock_resolves_to_instock(): void {
		$product = $this->make_product_with_stock(
			array(
				'in_stock'     => true,
				'stock_status' => 'instock',
			)
		);

		$this->assertSame( 'instock', WC_AI_Storefront_Product_Facts::stock_state( $product ) );
	}

	public function test_out_of_stock_resolves_to_outofstock(): void {
		$product = $this->make_product_with_stock( array( 'in_stock' => false ) );

		$this->assertSame( 'outofstock', WC_AI_Storefront_Product_Facts::stock_state( $product ) );
	}

	public function test_backorder_resolves_to_onbackorder(): void {
		// `is_in_stock()` is TRUE for 'onbackorder' — WC collapses three
		// states to a bool. Branching on the bool alone would report this
		// as plain in-stock; see stock_state()'s own docblock.
		$product = $this->make_product_with_stock(
			array(
				'in_stock'     => true,
				'stock_status' => 'onbackorder',
			)
		);

		$this->assertSame( 'onbackorder', WC_AI_Storefront_Product_Facts::stock_state( $product ) );
	}

	public function test_out_of_stock_wins_over_backorder_status(): void {
		// Reachable on a live site: `is_in_stock()` runs through the
		// `woocommerce_product_is_in_stock` filter, so a multi-warehouse
		// or role-based-catalog plugin can force it false while
		// `stock_status` still reads 'onbackorder'. The out-of-stock
		// branch must win outright — this is the test that pins branch
		// ORDER and catches an inverted or reordered ternary.
		$product = $this->make_product_with_stock(
			array(
				'in_stock'     => false,
				'stock_status' => 'onbackorder',
			)
		);

		$this->assertSame( 'outofstock', WC_AI_Storefront_Product_Facts::stock_state( $product ) );
	}

	// ------------------------------------------------------------------
	// collect_condition_candidates() / resolve_condition() / condition_slug()
	// ------------------------------------------------------------------

	/**
	 * Builds a mock WC_Product exposing the given attributes, mirroring
	 * JsonLdConditionTest's helper of the same name.
	 *
	 * @param array<string, string> $attributes Slug => value.
	 */
	private function make_product_with_attributes( array $attributes ): Mockery\MockInterface {
		$attribute_objects = array();
		foreach ( array_keys( $attributes ) as $slug ) {
			$attr = Mockery::mock();
			$attr->shouldReceive( 'get_visible' )->andReturn( true );
			$attr->shouldReceive( 'get_name' )->andReturn( $slug );
			$attribute_objects[ $slug ] = $attr;
		}

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_attributes' )->andReturn( $attribute_objects );
		$product->shouldReceive( 'get_attribute' )->andReturnUsing(
			static fn( $slug ) => $attributes[ $slug ] ?? ''
		);
		$product->shouldReceive( 'get_variation_attributes' )->andReturn( array() );

		return $product;
	}

	public function test_absent_condition_attribute_resolves_to_empty_string(): void {
		$product = $this->make_product_with_attributes( array() );

		$this->assertSame( '', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_new_condition_resolves_to_new(): void {
		$product = $this->make_product_with_attributes( array( 'pa_condition' => 'new' ) );

		$this->assertSame( 'new', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_refurbished_condition_resolves_to_refurbished(): void {
		$product = $this->make_product_with_attributes( array( 'pa_condition' => 'refurbished' ) );

		$this->assertSame( 'refurbished', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_used_condition_resolves_to_used(): void {
		$product = $this->make_product_with_attributes( array( 'pa_condition' => 'used' ) );

		$this->assertSame( 'used', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_unrecognised_condition_value_resolves_to_empty_string(): void {
		// A merchant's own pre-existing pa_condition is not overwritten by
		// seeding, so values like "B-grade" reach this code in the wild.
		// This is also the test that catches a removed/short-circuited
		// condition lookup: without it, an unrecognised value would type.
		$product = $this->make_product_with_attributes( array( 'pa_condition' => 'B-grade' ) );

		$this->assertSame( '', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_seeded_attribute_outranks_a_bare_custom_one(): void {
		// pa_condition is authoritative by construction; bare `condition`
		// is the compatibility fallback. Same precedence JSON-LD pins in
		// JsonLdConditionTest — asserted here too since priority ordering
		// lives in resolve_condition(), not in either emitter.
		$product = $this->make_product_with_attributes(
			array(
				'pa_condition' => 'refurbished',
				'condition'    => 'used',
			)
		);

		$this->assertSame( 'refurbished', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_resolve_condition_reports_the_winning_attribute_slug(): void {
		// WC_AI_Storefront_JsonLd::resolve_condition() needs this half of
		// the return value (not just the neutral condition value) to
		// decide whether the winning attribute also belongs in
		// additionalProperty. Pin the shape directly since condition_slug()
		// discards it.
		$product = $this->make_product_with_attributes( array( 'condition' => 'used' ) );

		$resolved = WC_AI_Storefront_Product_Facts::resolve_condition(
			WC_AI_Storefront_Product_Facts::collect_condition_candidates( $product )
		);

		$this->assertSame(
			array(
				'slug'      => 'condition',
				'condition' => 'used',
			),
			$resolved
		);
	}
}
