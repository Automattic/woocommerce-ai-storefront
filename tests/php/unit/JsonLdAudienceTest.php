<?php
/**
 * Tests for the Product.audience / PeopleAudience block.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
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

	public function test_unrecognised_gender_is_dropped_not_guessed(): void {
		// "Womens" reads fine to a human and is not an accepted value.
		// Guessing wrong about a product's audience is worse than silence;
		// the caller still routes the raw value to additionalProperty.
		foreach ( array( 'Womens', 'ladies', 'M', 'n/a', '' ) as $value ) {
			$block = $this->build( $value, '' );
			$this->assertSame( array(), $block, "input: {$value}" );
		}
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
		// turns up. Do not guess a product's intended audience.
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
		$this->assertSame( array(), $this->build( '', '' ) );
		$this->assertSame( array(), $this->build( 'Womens', 'Grown-up' ) );
	}
}
