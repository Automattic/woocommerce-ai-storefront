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
	 * Builds a mock WC_Product exposing the given attributes.
	 *
	 * Models real WooCommerce rather than what the resolver wants to see
	 * (#679 review). Two attribute kinds, and they behave differently:
	 *
	 * - TAXONOMY (`array( 'label' => 'Brand New', 'terms' => array( 'new' ) )`):
	 *   `get_attribute()` returns the term NAME — the merchant's display
	 *   label — because `WC_Product::get_attribute()` ends in
	 *   `wc_get_product_terms( ..., array( 'fields' => 'names' ) )`. The
	 *   SLUGS are reachable only through `wc_get_product_terms()`, stubbed
	 *   here to answer with them. Giving the label a different string from
	 *   the slug is the whole point: fixtures that returned `'new'` from
	 *   `get_attribute()` concealed a resolver matching display labels
	 *   against slugs.
	 * - CUSTOM (a plain string): free text with no term behind it, so the
	 *   raw value is all there is and `get_attribute()` returns it.
	 *
	 * @param array<string, string|array{label: string, terms: string[]}> $attributes Attribute slug => custom value, or taxonomy spec.
	 */
	private function make_product_with_attributes( array $attributes ): Mockery\MockInterface {
		$attribute_objects = array();
		$labels            = array();
		$terms             = array();
		foreach ( $attributes as $slug => $spec ) {
			$is_taxonomy     = is_array( $spec );
			$labels[ $slug ] = $is_taxonomy ? $spec['label'] : $spec;
			$terms[ $slug ]  = $is_taxonomy ? $spec['terms'] : array();
			$attr            = Mockery::mock();
			$attr->shouldReceive( 'get_visible' )->andReturn( true );
			$attr->shouldReceive( 'get_name' )->andReturn( $slug );
			$attr->shouldReceive( 'is_taxonomy' )->andReturn( $is_taxonomy );
			$attribute_objects[ $slug ] = $attr;
		}

		Monkey\Functions\when( 'wc_get_product_terms' )->alias(
			static fn( $product_id, $taxonomy, $args = array() ) => $terms[ $taxonomy ] ?? array()
		);

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 7 );
		$product->shouldReceive( 'get_attributes' )->andReturn( $attribute_objects );
		$product->shouldReceive( 'get_attribute' )->andReturnUsing(
			static fn( $slug ) => $labels[ $slug ] ?? ''
		);
		$product->shouldReceive( 'get_variation_attributes' )->andReturn( array() );

		return $product;
	}

	/**
	 * Shorthand for a taxonomy attribute spec.
	 *
	 * @param string   $label Term name the merchant sees.
	 * @param string[] $terms Term slugs behind it.
	 * @return array{label: string, terms: string[]}
	 */
	private function taxonomy_attribute( string $label, array $terms ): array {
		return array(
			'label' => $label,
			'terms' => $terms,
		);
	}

	public function test_absent_condition_attribute_resolves_to_empty_string(): void {
		$product = $this->make_product_with_attributes( array() );

		$this->assertSame( '', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_new_condition_resolves_to_new(): void {
		$product = $this->make_product_with_attributes(
			array( 'pa_condition' => $this->taxonomy_attribute( 'Brand New', array( 'new' ) ) )
		);

		$this->assertSame( 'new', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_refurbished_condition_resolves_to_refurbished(): void {
		$product = $this->make_product_with_attributes(
			array( 'pa_condition' => $this->taxonomy_attribute( 'Factory Refurbished', array( 'refurbished' ) ) )
		);

		$this->assertSame( 'refurbished', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_used_condition_resolves_to_used(): void {
		$product = $this->make_product_with_attributes(
			array( 'pa_condition' => $this->taxonomy_attribute( 'Pre-owned', array( 'used' ) ) )
		);

		$this->assertSame( 'used', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_non_english_condition_label_still_resolves(): void {
		// A German store's Condition terms read "Neu", "Generalüberholt",
		// "Gebraucht" while their slugs stay English. Matching the label
		// lost the condition on every product of every non-English store
		// (#679 review); matching the slug does not.
		$product = $this->make_product_with_attributes(
			array( 'pa_condition' => $this->taxonomy_attribute( 'Neu', array( 'new' ) ) )
		);

		$this->assertSame( 'new', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_unrecognised_condition_slug_resolves_to_empty_string(): void {
		// A merchant's own pre-existing pa_condition is not overwritten by
		// seeding, so terms like "B-grade" reach this code in the wild.
		// This is also the test that catches a removed/short-circuited
		// condition lookup: without it, an unrecognised value would type.
		$product = $this->make_product_with_attributes(
			array( 'pa_condition' => $this->taxonomy_attribute( 'B Grade', array( 'b-grade' ) ) )
		);

		$this->assertSame( '', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_recognised_label_over_an_unrecognised_slug_resolves_to_empty_string(): void {
		// The mirror image, and the one that pins WHICH half is matched:
		// a term slugged `b-grade` that the merchant relabelled "Used" is
		// not a `used` product. Reading the label would type it; reading
		// the slug does not.
		$product = $this->make_product_with_attributes(
			array( 'pa_condition' => $this->taxonomy_attribute( 'Used', array( 'b-grade' ) ) )
		);

		$this->assertSame( '', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_multi_term_condition_resolves_to_empty_string(): void {
		// Two terms on one attribute: WooCommerce joins them, and there is
		// no single honest claim to make from "new, used".
		$product = $this->make_product_with_attributes(
			array( 'pa_condition' => $this->taxonomy_attribute( 'Brand New, Pre-owned', array( 'new', 'used' ) ) )
		);

		$this->assertSame( '', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_custom_attribute_matches_its_raw_value(): void {
		// A non-taxonomy attribute stores free text with no term behind
		// it, so the raw value is the only value there is. That path is
		// unchanged by the slug fix and must stay that way.
		$product = $this->make_product_with_attributes( array( 'condition' => 'Used' ) );

		$this->assertSame( 'used', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_seeded_attribute_outranks_a_bare_custom_one(): void {
		// pa_condition is authoritative by construction; bare `condition`
		// is the compatibility fallback. Same precedence JSON-LD pins in
		// JsonLdConditionTest — asserted here too since priority ordering
		// lives in resolve_condition(), not in either emitter.
		$product = $this->make_product_with_attributes(
			array(
				'pa_condition' => $this->taxonomy_attribute( 'Factory Refurbished', array( 'refurbished' ) ),
				'condition'    => 'used',
			)
		);

		$this->assertSame( 'refurbished', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
	}

	public function test_bare_attribute_is_used_when_the_seeded_slug_cannot_type(): void {
		// A pa_ term whose SLUG does not type must not block the next
		// candidate — the fall-through has to survive the slug fix.
		$product = $this->make_product_with_attributes(
			array(
				'pa_condition' => $this->taxonomy_attribute( 'B Grade', array( 'b-grade' ) ),
				'condition'    => 'used',
			)
		);

		$this->assertSame( 'used', WC_AI_Storefront_Product_Facts::condition_slug( $product ) );
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
