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
		$this->assertSame( [ 'min' => 0, 'max' => 0, 'business_days' => [] ], WC_AI_Storefront_Handling_Time::sanitize( 'gibberish' ) );
	}

	public function test_null_returns_zero_pair(): void {
		$this->assertSame( [ 'min' => 0, 'max' => 0, 'business_days' => [] ], WC_AI_Storefront_Handling_Time::sanitize( null ) );
	}

	public function test_integer_returns_zero_pair(): void {
		$this->assertSame( [ 'min' => 0, 'max' => 0, 'business_days' => [] ], WC_AI_Storefront_Handling_Time::sanitize( 42 ) );
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
		$this->assertSame( [ 'min' => 0, 'max' => 0, 'business_days' => [] ], WC_AI_Storefront_Handling_Time::sanitize( [] ) );
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
		$result = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 0, 'max' => 0, 'business_days' => [] ] );
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

	// ------------------------------------------------------------------
	// business_days — which weekdays the store dispatches (#637)
	// ------------------------------------------------------------------

	public function test_days_are_the_schema_org_vocabulary(): void {
		// Anchors DAYS to the actual schema.org DayOfWeek tokens, spelled out.
		// Without this, changing 'Thursday' to 'Thurs' in BOTH DAYS and the
		// JS WEEKDAYS leaves every test green: the cross-check below pins the
		// two lists to each other, not to the vocabulary. The store would
		// publish ["Thurs"], which no consumer resolves — the same failure
		// the never-translate test guards, reached from a different direction.
		//
		// Five of the seven were previously unpinned, because
		// test_all_seven_days_are_kept feeds DAYS to itself.
		$this->assertSame(
			array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
			WC_AI_Storefront_Handling_Time::DAYS
		);
	}

	public function test_the_js_weekday_order_matches_the_php_constant(): void {
		// DAYS and the JS WEEKDAYS are independent literals in two languages
		// with no shared source. If they drift, a merchant's saved order stops
		// matching what the server canonicalises, and the settings screen shows
		// something different from what is published. Nothing else catches it,
		// so this parses the JS and compares.
		$js = file_get_contents(
			dirname( __DIR__, 3 ) . '/client/settings/ai-storefront/policies-tab.js'
		);
		$this->assertNotFalse( $js, 'Could not read policies-tab.js.' );

		preg_match( '/export const WEEKDAYS = \[(.*?)\];/s', $js, $block );
		$this->assertNotEmpty( $block, 'Could not locate the WEEKDAYS constant.' );

		preg_match_all( "/value: '([A-Za-z]+)'/", $block[1], $found );

		$this->assertSame(
			WC_AI_Storefront_Handling_Time::DAYS,
			$found[1],
			'client WEEKDAYS must match WC_AI_Storefront_Handling_Time::DAYS, in the same order.'
		);
	}

	public function test_business_days_are_canonical_and_week_ordered(): void {
		// Emission order must not follow click order, or the same
		// configuration produces different JSON on different stores and busts
		// caches for nothing.
		$out = WC_AI_Storefront_Handling_Time::sanitize(
			[
				'min'           => 1,
				'max'           => 2,
				'business_days' => [ 'Friday', 'Monday', 'Wednesday' ],
			]
		);

		$this->assertSame( [ 'Monday', 'Wednesday', 'Friday' ], $out['business_days'] );
	}

	public function test_unknown_days_are_dropped_not_stored(): void {
		// This array is published verbatim, so a typo would become a public
		// claim.
		$out = WC_AI_Storefront_Handling_Time::sanitize(
			[ 'business_days' => [ 'Monday', 'gibberish', 'Funday', '', 42, null ] ]
		);

		$this->assertSame( [ 'Monday' ], $out['business_days'] );
	}

	public function test_day_names_are_case_normalised(): void {
		// A hand-edited option or a REST caller can send any casing;
		// schema.org DayOfWeek tokens are capitalised.
		$out = WC_AI_Storefront_Handling_Time::sanitize(
			[ 'business_days' => [ 'monday', 'TUESDAY', 'wEdNeSdAy', ' friday ' ] ]
		);

		$this->assertSame( [ 'Monday', 'Tuesday', 'Wednesday', 'Friday' ], $out['business_days'] );
	}

	public function test_duplicate_days_collapse(): void {
		$out = WC_AI_Storefront_Handling_Time::sanitize(
			[ 'business_days' => [ 'Monday', 'Monday', 'monday' ] ]
		);

		$this->assertSame( [ 'Monday' ], $out['business_days'] );
	}

	public function test_missing_business_days_defaults_to_empty(): void {
		// Stores configured before #637 have { min, max } and no third key.
		// Reading one must not warn or produce null.
		$out = WC_AI_Storefront_Handling_Time::sanitize( [ 'min' => 1, 'max' => 2 ] );

		$this->assertSame( [], $out['business_days'] );
		$this->assertSame( 1, $out['min'] );
	}

	public function test_non_array_input_still_yields_the_days_key(): void {
		$out = WC_AI_Storefront_Handling_Time::sanitize( 'gibberish' );

		$this->assertSame( [], $out['business_days'] );
	}

	public function test_all_seven_days_are_kept(): void {
		// A store that genuinely dispatches every day is a real answer, not a
		// synonym for "unset".
		$out = WC_AI_Storefront_Handling_Time::sanitize(
			[ 'business_days' => WC_AI_Storefront_Handling_Time::DAYS ]
		);

		$this->assertCount( 7, $out['business_days'] );
		$this->assertSame( 'Monday', $out['business_days'][0] );
		$this->assertSame( 'Sunday', $out['business_days'][6] );
	}
}
