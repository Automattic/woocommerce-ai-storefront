<?php
/**
 * Tests for WC_AI_Storefront_Handling_Time::sanitize().
 *
 * @package WooCommerce_AI_Storefront
 */

class HandlingTimeTest extends \PHPUnit\Framework\TestCase {

	// ------------------------------------------------------------------
	// Non-array input
	// ------------------------------------------------------------------

	public function test_non_array_returns_zero_pair(): void {
		$this->assertSame( [ 'min' => 0, 'max' => 0 ], WC_AI_Storefront_Handling_Time::sanitize( 'gibberish' ) );
	}

	public function test_null_returns_zero_pair(): void {
		$this->assertSame( [ 'min' => 0, 'max' => 0 ], WC_AI_Storefront_Handling_Time::sanitize( null ) );
	}

	public function test_integer_returns_zero_pair(): void {
		$this->assertSame( [ 'min' => 0, 'max' => 0 ], WC_AI_Storefront_Handling_Time::sanitize( 42 ) );
	}

	// ------------------------------------------------------------------
	// Missing keys
	// ------------------------------------------------------------------

	public function test_missing_min_defaults_to_zero(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'max' => 3 ] );
		$this->assertSame( 0, $result['min'] );
		$this->assertSame( 3, $result['max'] );
	}

	public function test_missing_max_defaults_to_zero(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 2 ] );
		$this->assertSame( 2, $result['min'] );
		$this->assertSame( 0, $result['max'] );
	}

	public function test_empty_array_returns_zero_pair(): void {
		$this->assertSame( [ 'min' => 0, 'max' => 0 ], WC_AI_Storefront_Handling_Time::sanitize( [] ) );
	}

	// ------------------------------------------------------------------
	// Negative value → 0
	// ------------------------------------------------------------------

	public function test_negative_min_clamped_to_zero(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => -5, 'max' => 3 ] );
		$this->assertSame( 0, $result['min'] );
	}

	public function test_negative_max_clamped_to_zero(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 1, 'max' => -3 ] );
		$this->assertSame( 0, $result['max'] );
	}

	// ------------------------------------------------------------------
	// Ceiling clamp at 365
	// ------------------------------------------------------------------

	public function test_min_above_365_clamped_to_365(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 400, 'max' => 400 ] );
		$this->assertSame( 365, $result['min'] );
	}

	public function test_max_above_365_clamped_to_365(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 1, 'max' => 999 ] );
		$this->assertSame( 365, $result['max'] );
	}

	public function test_exactly_365_is_accepted(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 1, 'max' => 365 ] );
		$this->assertSame( 365, $result['max'] );
	}

	// ------------------------------------------------------------------
	// max < min → max raised to min
	// ------------------------------------------------------------------

	public function test_max_below_min_is_raised_to_min(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 5, 'max' => 2 ] );
		$this->assertSame( 5, $result['min'] );
		$this->assertSame( 5, $result['max'] );
	}

	public function test_max_equal_to_min_is_accepted(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 3, 'max' => 3 ] );
		$this->assertSame( 3, $result['min'] );
		$this->assertSame( 3, $result['max'] );
	}

	public function test_max_below_min_when_min_is_zero_no_reset(): void {
		// max < min rule only triggers when both are > 0; min=0 means "not set".
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 0, 'max' => 0 ] );
		$this->assertSame( 0, $result['min'] );
		$this->assertSame( 0, $result['max'] );
	}

	// ------------------------------------------------------------------
	// Happy path
	// ------------------------------------------------------------------

	public function test_valid_pair_passes_through(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 1, 'max' => 3 ] );
		$this->assertSame( 1, $result['min'] );
		$this->assertSame( 3, $result['max'] );
	}

	public function test_string_numbers_are_cast(): void {
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => '2', 'max' => '7' ] );
		$this->assertSame( 2, $result['min'] );
		$this->assertSame( 7, $result['max'] );
	}
}
