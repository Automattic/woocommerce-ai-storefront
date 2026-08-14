<?php
/**
 * Tests for the Product.audience / PeopleAudience block.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdAudienceTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Calls the private static builder.
	 *
	 * @param string $gender    Raw gender attribute value.
	 * @param string $age_group Raw age-group attribute value.
	 * @return array
	 */
	private function build( string $gender, string $age_group ): array {
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'build_audience_block' );
		$method->setAccessible( true );
		return $method->invoke( null, $gender, $age_group );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_gender_passes_through_for_each_accepted_value(): void {
		foreach ( array( 'male', 'female', 'unisex' ) as $value ) {
			$block = $this->build( $value, '' );
			$this->assertSame( 'PeopleAudience', $block['@type'] );
			$this->assertSame( $value, $block['suggestedGender'] );
			$this->assertArrayNotHasKey( 'suggestedAge', $block );
		}
	}

	public function test_gender_matching_is_case_insensitive_and_normalises_to_lowercase(): void {
		// Google requires the value in English; a merchant typing "Female"
		// is unambiguous, so accept it and emit the canonical casing.
		foreach ( array( 'Female', 'FEMALE', ' female ' ) as $value ) {
			$block = $this->build( $value, '' );
			$this->assertSame( 'female', $block['suggestedGender'], "input: {$value}" );
		}
	}

	public function test_unrecognised_gender_passes_through_verbatim(): void {
		// "Womens" reads fine to a human and is not one of Google's three
		// accepted values, but `schema:suggestedGender` is Text-ranged —
		// there is no structural reason to reject it. Google's own
		// Merchant Center / Search Console diagnostics are the intended
		// place to flag it to the merchant, not silent validation here
		// (see build_audience_block()'s docblock for the full rationale).
		// This supersedes the old contract, where these same inputs
		// asserted an EMPTY block (dropped, not guessed).
		foreach ( array( 'Womens', 'ladies', 'M', 'n/a' ) as $value ) {
			$block = $this->build( $value, '' );
			$this->assertSame( 'PeopleAudience', $block['@type'], "input: {$value}" );
			$this->assertSame( $value, $block['suggestedGender'], "input: {$value}" );
			$this->assertArrayNotHasKey( 'suggestedAge', $block, "input: {$value}" );
		}
	}

	public function test_unrecognised_gender_is_trimmed(): void {
		// Normalisation still applies to an unrecognised value: trim,
		// just not lowercase-and-validate.
		$block = $this->build( '  Womens  ', '' );
		$this->assertSame( 'Womens', $block['suggestedGender'] );
	}

	/**
	 * @dataProvider age_group_provider
	 */
	public function test_age_group_maps_to_google_documented_quantitative_value(
		string $input,
		float $min,
		?float $max,
		string $unit
	): void {
		$block = $this->build( '', $input );

		$this->assertSame( 'PeopleAudience', $block['@type'] );
		$this->assertSame( 'QuantitativeValue', $block['suggestedAge']['@type'] );
		$this->assertSame( $min, $block['suggestedAge']['minValue'] );
		$this->assertSame( $unit, $block['suggestedAge']['unitCode'] );

		if ( null === $max ) {
			$this->assertArrayNotHasKey(
				'maxValue',
				$block['suggestedAge'],
				'adult has no upper bound in Google\'s own example'
			);
		} else {
			$this->assertSame( $max, $block['suggestedAge']['maxValue'] );
		}
	}

	public static function age_group_provider(): array {
		return array(
			// Months for the sub-1 buckets, which is what lets newborn and
			// infant stay distinct without fractional years.
			'newborn' => array( 'newborn', 0.0, 3.0, 'MON' ),
			'infant'  => array( 'infant', 3.0, 12.0, 'MON' ),
			'toddler' => array( 'toddler', 1.0, 5.0, 'ANN' ),
			'kids'    => array( 'kids', 5.0, 13.0, 'ANN' ),
			'adult'   => array( 'adult', 13.0, null, 'ANN' ),
		);
	}

	public function test_age_group_matching_is_case_insensitive(): void {
		$block = $this->build( '', 'Adult' );
		$this->assertSame( 13.0, $block['suggestedAge']['minValue'] );
	}

	public function test_unrecognised_age_group_is_dropped_not_guessed(): void {
		// "Children" looks mappable to kids until "Youth" or "Junior"
		// turns up. Do not guess a product's intended audience. Unlike
		// gender, this is a data-model constraint, not a policy choice:
		// `suggestedAge` is a QuantitativeValue and there is no honest
		// numeric fallback for an unmapped bucket — see
		// build_audience_block()'s docblock for the full asymmetry
		// rationale.
		foreach ( array( 'Children', 'Youth', 'Baby', 'Grown-up', '' ) as $value ) {
			$this->assertSame( array(), $this->build( '', $value ), "input: {$value}" );
		}
	}

	public function test_both_values_emit_in_one_block(): void {
		$block = $this->build( 'unisex', 'adult' );

		$this->assertSame(
			array(
				'@type'           => 'PeopleAudience',
				'suggestedGender' => 'unisex',
				'suggestedAge'    => array(
					'@type'    => 'QuantitativeValue',
					'minValue' => 13.0,
					'unitCode' => 'ANN',
				),
			),
			$block,
			'Shape must match Google\'s documented example exactly.'
		);
	}

	public function test_no_usable_values_returns_empty_array(): void {
		// The only remaining "nothing to report" case: gender is now
		// always "usable" once non-empty (see build_audience_block()),
		// even when Google wouldn't recognise it — only a truly empty
		// gender AND an unmapped age group yield an empty block.
		$this->assertSame( array(), $this->build( '', '' ) );
	}

	/**
	 * Builds a mock WC_Product exposing the given attributes.
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
	 * @param array<string, string> $attributes    Slug => value.
	 * @param array                 $initial_markup Pre-existing markup — e.g. as if an
	 *                                              upstream `woocommerce_structured_data_product`
	 *                                              filter already ran at an earlier priority.
	 * @return array
	 */
	private function emit( array $attributes, array $initial_markup = array() ): array {
		Functions\when( 'wc_attribute_label' )->returnArg();

		$jsonld = new WC_AI_Storefront_JsonLd();
		$markup = $initial_markup;
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'emit_attributes' );
		$method->setAccessible( true );
		$method->invokeArgs( $jsonld, array( &$markup, $this->make_product_with_attributes( $attributes ) ) );

		return $markup;
	}

	public function test_audience_emitted_from_seeded_global_attributes(): void {
		$markup = $this->emit(
			array(
				'pa_gender'    => 'female',
				'pa_age_group' => 'adult',
			)
		);

		$this->assertSame( 'PeopleAudience', $markup['audience']['@type'] );
		$this->assertSame( 'female', $markup['audience']['suggestedGender'] );
		$this->assertSame( 13.0, $markup['audience']['suggestedAge']['minValue'] );
	}

	public function test_audience_emitted_from_bare_custom_attribute_slugs(): void {
		$markup = $this->emit(
			array(
				'gender'    => 'unisex',
				'age_group' => 'kids',
			)
		);

		$this->assertSame( 'unisex', $markup['audience']['suggestedGender'] );
		$this->assertSame( 5.0, $markup['audience']['suggestedAge']['minValue'] );
	}

	public function test_two_word_custom_attribute_label_is_matched(): void {
		// A custom (non-global) attribute carries the merchant's own label,
		// so "Age group" arrives lowercased as "age group" with a space.
		// Colour and size never exposed this because they are one word.
		$markup = $this->emit( array( 'Age group' => 'toddler' ) );

		$this->assertArrayHasKey( 'audience', $markup );
		$this->assertSame( 1.0, $markup['audience']['suggestedAge']['minValue'] );
	}

	public function test_no_audience_key_when_neither_attribute_present(): void {
		$markup = $this->emit( array( 'pa_color' => 'Black' ) );

		$this->assertArrayNotHasKey( 'audience', $markup );
	}

	public function test_pa_gender_takes_precedence_over_bare_gender(): void {
		// The plugin seeds pa_gender with exactly Google's accepted
		// values, so it is authoritative; a bare `gender` attribute is a
		// merchant's own pre-existing custom attribute and is the
		// fallback. When both are present, pa_gender wins — and the
		// outranked bare value still reaches additionalProperty rather
		// than vanishing.
		$markup = $this->emit(
			array(
				'pa_gender' => 'female',
				'gender'    => 'unisex',
			)
		);

		$this->assertSame( 'female', $markup['audience']['suggestedGender'] );
		$by_name = array_column( $markup['additionalProperty'], 'value', 'name' );
		$this->assertSame( 'unisex', $by_name['gender'] );
	}

	public function test_pa_age_group_takes_precedence_over_bare_age_group(): void {
		$markup = $this->emit(
			array(
				'pa_age_group' => 'adult',
				'age_group'    => 'kids',
			)
		);

		$this->assertSame( 13.0, $markup['audience']['suggestedAge']['minValue'] );
		$by_name = array_column( $markup['additionalProperty'], 'value', 'name' );
		$this->assertSame( 'kids', $by_name['age_group'] );
	}

	public function test_unmappable_winner_falls_through_to_mappable_bare_attribute(): void {
		// #618 review finding: pa_age_group wins on priority (0 < 1), but
		// "Grown-up" doesn't map to any AUDIENCE_AGE_GROUPS bucket.
		// Previously this blocked suggestedAge entirely even though a
		// canonical bare `age_group` value sat right there on the same
		// product. The fall-through now tries the next-priority candidate.
		$markup = $this->emit(
			array(
				'pa_age_group' => 'Grown-up',
				'age_group'    => 'adult',
			)
		);

		$this->assertSame( 13.0, $markup['audience']['suggestedAge']['minValue'] );
		// The candidate that actually typed — the bare `age_group` — is
		// the one excluded from additionalProperty; the unmappable
		// pa_age_group still reaches agents there, untyped.
		$names = array_column( $markup['additionalProperty'], 'name' );
		$this->assertContains( 'pa_age_group', $names );
		$this->assertNotContains( 'age_group', $names );
	}

	public function test_precedence_unchanged_when_winner_is_mappable(): void {
		// Guard against the fall-through becoming the default path: when
		// pa_age_group DOES map, it must still win outright over a
		// mappable bare age_group, exactly as before this fix.
		$markup = $this->emit(
			array(
				'pa_age_group' => 'kids',
				'age_group'    => 'adult',
			)
		);

		$this->assertSame( 5.0, $markup['audience']['suggestedAge']['minValue'] );
		$names = array_column( $markup['additionalProperty'], 'name' );
		$this->assertContains( 'age_group', $names );
		$this->assertNotContains( 'pa_age_group', $names );
	}

	public function test_gender_never_uses_fall_through(): void {
		// Gender always types for any non-empty value (see
		// build_gender_sub_property()), so even when the higher-priority
		// pa_gender value isn't one of Google's three canonical strings,
		// it still "types" verbatim and must win outright — the
		// fall-through added for age group must never activate for
		// gender.
		$markup = $this->emit(
			array(
				'pa_gender' => 'Womens',
				'gender'    => 'male',
			)
		);

		$this->assertSame( 'Womens', $markup['audience']['suggestedGender'] );
		$by_name = array_column( $markup['additionalProperty'], 'value', 'name' );
		$this->assertSame( 'male', $by_name['gender'] );
	}

	public function test_upstream_audience_is_merged_not_overwritten(): void {
		// #618 review finding: a plugin hooking
		// woocommerce_structured_data_product before priority 20 may
		// already have set `audience` (e.g. with its own audienceType).
		// emit_attributes() must merge our sub-properties in rather than
		// replacing the block wholesale — the same upstream_owns
		// convention the core typed properties already honor.
		$markup = $this->emit(
			array(
				'pa_gender'    => 'female',
				'pa_age_group' => 'adult',
			),
			array(
				'audience' => array(
					'@type'        => 'PeopleAudience',
					'audienceType' => 'gift shoppers',
				),
			)
		);

		$this->assertSame( 'gift shoppers', $markup['audience']['audienceType'], 'Upstream key must survive untouched.' );
		$this->assertSame( 'female', $markup['audience']['suggestedGender'] );
		$this->assertSame( 13.0, $markup['audience']['suggestedAge']['minValue'] );
	}

	public function test_upstream_owned_sub_property_is_not_overwritten(): void {
		$markup = $this->emit(
			array(
				'pa_gender'    => 'female',
				'pa_age_group' => 'adult',
			),
			array(
				'audience' => array(
					'@type'           => 'PeopleAudience',
					'suggestedGender' => 'male',
				),
			)
		);

		// Upstream's suggestedGender wins; ours is deferred and reaches
		// additionalProperty instead, same as the existing upstream_owns
		// convention for color/size/material/pattern.
		$this->assertSame( 'male', $markup['audience']['suggestedGender'] );
		$this->assertSame( 13.0, $markup['audience']['suggestedAge']['minValue'] );
		$names = array_column( $markup['additionalProperty'], 'name' );
		$this->assertContains( 'pa_gender', $names );
	}

	public function test_non_array_upstream_audience_is_left_untouched(): void {
		$markup = $this->emit(
			array( 'pa_gender' => 'female' ),
			array( 'audience' => 'not-an-array' )
		);

		$this->assertSame( 'not-an-array', $markup['audience'] );
		$names = array_column( $markup['additionalProperty'], 'name' );
		$this->assertContains( 'pa_gender', $names );
	}

	public function test_unrecognised_gender_emits_typed_not_additional_property(): void {
		// Gender no longer gatekeeps: "Womens" is structurally valid
		// `suggestedGender` markup, so it types and does NOT duplicate
		// into additionalProperty. This supersedes the old contract
		// (formerly test_unrecognised_values_still_reach_additional_property),
		// which asserted the opposite for this exact input.
		$markup = $this->emit( array( 'pa_gender' => 'Womens' ) );

		$this->assertSame( 'Womens', $markup['audience']['suggestedGender'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $markup );
	}

	public function test_unrecognised_age_group_still_reaches_additional_property(): void {
		// Age group keeps the old gate: `suggestedAge` needs real
		// numbers, and "Grown-up" has none, so it falls back to
		// additionalProperty exactly like any other unmapped attribute.
		// Paired with a recognised gender so the presence of `audience`
		// itself isn't the only thing being checked.
		$markup = $this->emit(
			array(
				'gender'       => 'female',
				'pa_age_group' => 'Grown-up',
			)
		);

		$this->assertSame( 'female', $markup['audience']['suggestedGender'] );
		$this->assertArrayNotHasKey( 'suggestedAge', $markup['audience'] );
		$names = array_column( $markup['additionalProperty'], 'name' );
		$this->assertContains( 'pa_age_group', $names );
	}

	public function test_unrecognised_gender_and_recognised_age_group_both_emit_correctly(): void {
		// Supersedes the old test_no_audience_key_when_values_are_unrecognised,
		// which asserted NO audience key for this same gender value — that
		// was the old gatekept contract. Gender now always types, so the
		// block is present with both fields correctly populated and
		// nothing duplicated to additionalProperty.
		$markup = $this->emit(
			array(
				'pa_gender'    => 'Womens',
				'pa_age_group' => 'adult',
			)
		);

		$this->assertSame( 'PeopleAudience', $markup['audience']['@type'] );
		$this->assertSame( 'Womens', $markup['audience']['suggestedGender'] );
		$this->assertSame( 13.0, $markup['audience']['suggestedAge']['minValue'] );
		$this->assertArrayNotHasKey( 'additionalProperty', $markup );
	}

	public function test_recognised_values_do_not_also_emit_additional_property(): void {
		// Typed emission wins; a duplicate untyped copy would be noise.
		$markup = $this->emit( array( 'pa_gender' => 'female' ) );

		$this->assertArrayNotHasKey( 'additionalProperty', $markup );
	}

	public function test_hidden_attribute_is_ignored(): void {
		$attr = Mockery::mock();
		$attr->shouldReceive( 'get_visible' )->andReturn( false );
		$attr->shouldReceive( 'get_name' )->andReturn( 'pa_gender' );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_attributes' )->andReturn( array( 'pa_gender' => $attr ) );
		$product->shouldReceive( 'get_attribute' )->andReturn( 'female' );
		$product->shouldReceive( 'get_variation_attributes' )->andReturn( array() );
		$product->shouldReceive( 'is_type' )->andReturn( false );

		Functions\when( 'wc_attribute_label' )->returnArg();

		$jsonld = new WC_AI_Storefront_JsonLd();
		$markup = array();
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'emit_attributes' );
		$method->setAccessible( true );
		$method->invokeArgs( $jsonld, array( &$markup, $product ) );

		$this->assertArrayNotHasKey( 'audience', $markup );
	}

	// ------------------------------------------------------------------
	// detect_varies_by() — audience axes map to Schema.org URLs, not
	// plain text labels, in `variesBy` (Task 3, #618).
	// ------------------------------------------------------------------

	public function test_varies_by_maps_audience_axes_to_schema_org_urls(): void {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_variation_attributes' )->andReturn(
			array(
				'pa_age_group' => array( 'kids', 'adult' ),
			)
		);
		$product->shouldReceive( 'get_children' )->andReturn( array() );

		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'detect_varies_by' );
		$method->setAccessible( true );
		$varies = $method->invoke( null, $product );

		$this->assertContains( 'https://schema.org/suggestedAge', $varies );
	}

	public function test_varies_by_maps_gender_axis_to_schema_org_url(): void {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_variation_attributes' )->andReturn(
			array(
				'pa_gender' => array( 'male', 'female' ),
			)
		);
		$product->shouldReceive( 'get_children' )->andReturn( array() );

		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'detect_varies_by' );
		$method->setAccessible( true );
		$varies = $method->invoke( null, $product );

		$this->assertContains( 'https://schema.org/suggestedGender', $varies );
	}

	public function test_uniform_audience_axis_is_not_advertised_as_varying(): void {
		// Every variation shares one value, so it is not a dimension the
		// buyer chooses between.
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_variation_attributes' )->andReturn(
			array(
				'pa_gender' => array( 'unisex' ),
			)
		);
		$product->shouldReceive( 'get_children' )->andReturn( array() );

		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'detect_varies_by' );
		$method->setAccessible( true );

		$this->assertNotContains( 'https://schema.org/suggestedGender', $method->invoke( null, $product ) );
	}
}
