<?php
/**
 * Tests for the Offer.itemCondition property.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdConditionTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a mock WC_Product exposing the given attributes.
	 *
	 * Mirrors JsonLdAudienceTest's helper of the same name — both drive
	 * emit_attributes() directly, which needs attribute objects answering
	 * get_visible()/get_name() plus a get_attribute() value lookup.
	 *
	 * @param array<string, string> $attributes Slug => value.
	 * @return Mockery\MockInterface
	 */
	private function make_product_with_attributes( array $attributes ) {
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
		$product->shouldReceive( 'is_type' )->andReturn( false );

		return $product;
	}

	/**
	 * Runs emit_attributes() against a product and returns the markup.
	 *
	 * Seeds an empty Offer by default, because itemCondition is written
	 * there rather than onto the Product. Pass $initial_markup to model a
	 * product WooCommerce gave no offers (no price) or one where an
	 * upstream filter already claimed the key.
	 *
	 * @param array<string, string> $attributes     Slug => value.
	 * @param array|null            $initial_markup Markup to start from.
	 * @return array
	 */
	private function emit( array $attributes, ?array $initial_markup = null ): array {
		Functions\when( 'wc_attribute_label' )->returnArg();

		$jsonld  = new WC_AI_Storefront_JsonLd();
		$markup  = $initial_markup ?? array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer' ) ),
		);
		$product = $this->make_product_with_attributes( $attributes );

		// Drives BOTH halves, in production order. add_item_condition()
		// runs above the syndication gate and does the writing;
		// emit_attributes() runs below it and decides additionalProperty.
		// A harness that called only the second would pass while the
		// property was never published at all.
		//
		// No setAccessible() calls: a no-op since PHP 8.1, which is this
		// plugin's floor, and deprecated from 8.5 — which local runs use
		// even though CI's matrix tops out at 8.4.
		$write = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'add_item_condition' );
		$write->invokeArgs( $jsonld, array( &$markup, $product ) );

		$pending = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'emit_attributes' );
		$pending->invokeArgs( $jsonld, array( &$markup, $product ) );

		return $markup;
	}

	public function test_refurbished_condition_emits_on_the_offer(): void {
		$markup = $this->emit( array( 'pa_condition' => 'refurbished' ) );

		$this->assertSame(
			'https://schema.org/RefurbishedCondition',
			$markup['offers'][0]['itemCondition']
		);
	}

	public function test_condition_is_not_emitted_on_the_product(): void {
		// Google documents itemCondition under "Offer details" and never
		// mentions it on the Product structured-data page. Every other
		// offer-scoped property here (shippingDetails,
		// hasMerchantReturnPolicy, inventoryLevel) is written the same way.
		// Deliberately unlike hasAdultConsideration, which Google DOES
		// document under "Product information".
		$markup = $this->emit( array( 'pa_condition' => 'used' ) );

		$this->assertArrayNotHasKey( 'itemCondition', $markup );
	}

	public function test_used_condition_maps_to_the_used_url(): void {
		$markup = $this->emit( array( 'pa_condition' => 'used' ) );

		$this->assertSame(
			'https://schema.org/UsedCondition',
			$markup['offers'][0]['itemCondition']
		);
	}

	public function test_explicit_new_is_published_not_discarded(): void {
		// Google treats new as its default, but silence and an explicit
		// "new" are different statements. In a resale catalogue an item
		// assessed as new differs from one nobody assessed, and that is
		// the catalogue this feature exists for.
		$markup = $this->emit( array( 'pa_condition' => 'new' ) );

		$this->assertSame(
			'https://schema.org/NewCondition',
			$markup['offers'][0]['itemCondition']
		);
	}

	public function test_absent_attribute_emits_nothing(): void {
		// Absence must never become an invented NewCondition. That would
		// be a claim the merchant did not make, on every offer.
		$markup = $this->emit( array() );

		$this->assertArrayNotHasKey( 'itemCondition', $markup['offers'][0] );
	}

	public function test_unrecognised_value_emits_nothing(): void {
		// A merchant's own pre-existing pa_condition is NOT overwritten by
		// seeding — create_attribute() skips an existing taxonomy — so
		// values like "B-grade" reach this code in the wild.
		$markup = $this->emit( array( 'pa_condition' => 'B-grade' ) );

		$this->assertArrayNotHasKey( 'itemCondition', $markup['offers'][0] );
	}

	public function test_multi_value_condition_emits_nothing(): void {
		// Google: "Don't specify more than one value." WC returns
		// multi-value attributes comma-joined, and there is no honest
		// single claim to make from "new, used".
		$markup = $this->emit( array( 'pa_condition' => 'new, used' ) );

		$this->assertArrayNotHasKey( 'itemCondition', $markup['offers'][0] );
	}

	public function test_value_matching_is_case_and_space_insensitive(): void {
		// Terms are seeded lowercase, but a merchant editing the term or
		// using their own attribute can produce "Refurbished".
		$markup = $this->emit( array( 'pa_condition' => '  Refurbished ' ) );

		$this->assertSame(
			'https://schema.org/RefurbishedCondition',
			$markup['offers'][0]['itemCondition']
		);
	}

	public function test_seeded_attribute_outranks_a_bare_custom_one(): void {
		// pa_condition is seeded with Google's accepted values, so it is
		// authoritative by construction; a bare `condition` is the
		// compatibility fallback. Same precedence as pa_gender vs gender.
		$markup = $this->emit(
			array(
				'pa_condition' => 'refurbished',
				'condition'    => 'used',
			)
		);

		$this->assertSame(
			'https://schema.org/RefurbishedCondition',
			$markup['offers'][0]['itemCondition']
		);
	}

	public function test_bare_attribute_is_used_when_the_seeded_one_cannot_type(): void {
		// A pa_ value that fails to type must not block the next
		// candidate. Mirrors how the audience fields resolve.
		$markup = $this->emit(
			array(
				'pa_condition' => 'B-grade',
				'condition'    => 'used',
			)
		);

		$this->assertSame(
			'https://schema.org/UsedCondition',
			$markup['offers'][0]['itemCondition']
		);
	}

	public function test_typed_condition_does_not_also_appear_as_additional_property(): void {
		// Same rule as the audience fields: an attribute that produced
		// typed output drops out of additionalProperty rather than being
		// stated twice in two vocabularies.
		$markup = $this->emit( array( 'pa_condition' => 'used' ) );

		$names = array_column( $markup['additionalProperty'] ?? array(), 'name' );
		$this->assertNotContains( 'pa_condition', $names );
	}

	public function test_untypable_condition_still_reaches_additional_property(): void {
		// The counterpart: a value that could not be typed must not
		// vanish. It falls through to additionalProperty so an agent can
		// still read it, exactly as an untypable gender does.
		$markup = $this->emit( array( 'pa_condition' => 'B-grade' ) );

		$values = array_column( $markup['additionalProperty'] ?? array(), 'value' );
		$this->assertContains( 'B-grade', $values );
	}

	public function test_price_less_product_keeps_the_value_in_additional_property(): void {
		// WooCommerce builds `offers` only inside `if ( '' !== get_price() )`,
		// so a resale listing awaiting appraisal has no offer to label.
		// The typed claim is correctly withheld, but the merchant's value
		// must still reach an agent — otherwise picking the CORRECT seeded
		// value loses data that an invalid one keeps.
		$markup = $this->emit(
			array( 'pa_condition' => 'used' ),
			array( '@type' => 'Product' )
		);

		$this->assertArrayNotHasKey( 'itemCondition', $markup );
		$values = array_column( $markup['additionalProperty'] ?? array(), 'value' );
		$this->assertContains( 'used', $values );
	}

	public function test_upstream_owned_key_leaves_our_value_in_additional_property(): void {
		// Another filter got there first. We do not overwrite it, and the
		// merchant's own value must not vanish in the process.
		$markup = $this->emit(
			array( 'pa_condition' => 'used' ),
			array(
				'@type'  => 'Product',
				'offers' => array(
					array(
						'@type'         => 'Offer',
						'itemCondition' => 'https://schema.org/NewCondition',
					),
				),
			)
		);

		$this->assertSame(
			'https://schema.org/NewCondition',
			$markup['offers'][0]['itemCondition'],
			'An upstream owner is not overwritten.'
		);
		$values = array_column( $markup['additionalProperty'] ?? array(), 'value' );
		$this->assertContains( 'used', $values );
	}

	public function test_only_googles_three_conditions_are_reachable(): void {
		// schema.org's OfferItemCondition has four members and Google
		// accepts three. Pin it so nobody "completes" the set with
		// DamagedCondition, which is valid schema.org that Google ignores
		// — the merchant would believe they had declared a condition and
		// would have declared nothing.
		//
		// Scanned over string LITERALS via token_get_all rather than the
		// raw source, so documenting the exclusion in a comment does not
		// fail a test about emission.
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/ai-storefront/class-wc-ai-storefront-jsonld.php'
		);
		$this->assertNotFalse( $source );

		$found = array();
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0]
				&& preg_match( '#https://schema\.org/\w+Condition#', $token[1], $matches ) ) {
				$found[] = $matches[0];
			}
		}

		$found = array_values( array_unique( $found ) );
		sort( $found );

		$this->assertSame(
			array(
				'https://schema.org/NewCondition',
				'https://schema.org/RefurbishedCondition',
				'https://schema.org/UsedCondition',
			),
			$found
		);
	}
}
