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
		$labels            = array();
		$terms             = array();
		foreach ( $attributes as $slug => $spec ) {
			// A `pa_`-prefixed slug is a TAXONOMY attribute, so real
			// WooCommerce answers get_attribute() with the term NAME and
			// only wc_get_product_terms() reaches the slug (#679 review).
			// Pass array( 'label' => ..., 'terms' => array( ... ) ) to give
			// a term a display name that differs from its slug; a plain
			// string keeps both the same.
			$is_taxonomy     = 0 === strpos( $slug, 'pa_' );
			$labels[ $slug ] = is_array( $spec ) ? $spec['label'] : $spec;
			if ( is_array( $spec ) ) {
				$terms[ $slug ] = $spec['terms'];
			} else {
				$terms[ $slug ] = '' === $spec ? array() : array_map( 'trim', explode( ',', $spec ) );
			}

			$attr = Mockery::mock();
			$attr->shouldReceive( 'get_visible' )->andReturn( true );
			$attr->shouldReceive( 'get_name' )->andReturn( $slug );
			$attr->shouldReceive( 'is_taxonomy' )->andReturn( $is_taxonomy );
			$attribute_objects[ $slug ] = $attr;
		}

		Functions\when( 'wc_get_product_terms' )->alias(
			static fn( $product_id, $taxonomy, $args = array() ) => $terms[ $taxonomy ] ?? array()
		);

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_attributes' )->andReturn( $attribute_objects );
		$product->shouldReceive( 'get_attribute' )->andReturnUsing(
			static fn( $slug ) => $labels[ $slug ] ?? ''
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

	public function test_relabelled_taxonomy_term_types_from_its_slug(): void {
		// A `pa_condition` term slugged `new` but relabelled "Brand New" —
		// or "Neu" on a German store. get_attribute() answers with the
		// LABEL, so matching that against the three neutral slugs published
		// no itemCondition at all on any such store (#679 review, verified
		// live). Matching the slug does.
		$markup = $this->emit(
			array(
				'pa_condition' => array(
					'label' => 'Brand New',
					'terms' => array( 'new' ),
				),
			)
		);

		$this->assertSame(
			'https://schema.org/NewCondition',
			$markup['offers'][0]['itemCondition']
		);

		// And having typed, it drops out of additionalProperty exactly as a
		// label-equals-slug term does — the two halves of this feature read
		// the same value, so they cannot disagree about which attribute won.
		$names = array_column( $markup['additionalProperty'] ?? array(), 'name' );
		$this->assertNotContains( 'pa_condition', $names );
	}

	public function test_additional_property_keeps_the_merchant_label_not_the_slug(): void {
		// additionalProperty is shopper-visible, so it must go on showing
		// the merchant's own term name. Only the MATCHING moved to slugs
		// (#679 review). A term nobody can type is the case that makes the
		// distinction observable, since a typed one is deduplicated away.
		$markup = $this->emit(
			array(
				'pa_condition' => array(
					'label' => 'Mint Condition',
					'terms' => array( 'mint' ),
				),
			)
		);

		$this->assertArrayNotHasKey( 'itemCondition', $markup['offers'][0] );
		$values = array_column( $markup['additionalProperty'] ?? array(), 'value' );
		$this->assertContains( 'Mint Condition', $values );
		$this->assertNotContains( 'mint', $values );
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

	public function test_condition_value_map_keys_match_product_facts_condition_slugs(): void {
		// CONDITION_VALUE_MAP (here) and WC_AI_Storefront_Product_Facts::
		// CONDITION_SLUGS are two constant lists of the same three slugs,
		// maintained in two files with nothing tying them together. Add a
		// slug to one and not the other and resolve_condition() above does
		// self::CONDITION_VALUE_MAP[ $slug ] on a missing key — an
		// undefined-index warning, 'url' => null, and itemCondition
		// silently reaching the JSON-LD markup as null. Pin the two lists
		// to each other so drift fails here instead of showing up as a
		// malformed offer.
		$map = ( new \ReflectionClass( WC_AI_Storefront_JsonLd::class ) )
			->getConstant( 'CONDITION_VALUE_MAP' );

		// Compare as sets, not sequences — declaration order differing
		// between the two files is harmless; a missing or extra slug is
		// not.
		$map_keys = array_keys( $map );
		$slugs    = WC_AI_Storefront_Product_Facts::CONDITION_SLUGS;
		sort( $map_keys );
		sort( $slugs );

		$this->assertSame( $slugs, $map_keys );
	}
}
