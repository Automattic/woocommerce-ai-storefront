<?php
/**
 * Handling-time sanitizer.
 *
 * Stores the merchant's declared order handling time (min/max business days)
 * so the JSON-LD emitter can output a `ShippingDeliveryTime.handlingTime`
 * QuantitativeValue on the `OfferShippingDetails` block.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize the handling-time settings object.
 */
class WC_AI_Storefront_Handling_Time {

	/**
	 * Sanitize a raw handling-time input.
	 *
	 * Field rules:
	 *   - `min`: non-negative integer, 0–365. 0 means "not set" (no emission).
	 *   - `max`: non-negative integer, 0–365. Must be >= min. 0 means "not set".
	 *
	 * Emission contract: both min AND max must be > 0 for the `handlingTime`
	 * block to appear in JSON-LD. If either is 0 (or absent), the emitter
	 * skips the block entirely rather than publishing a partial claim.
	 *
	 * @param mixed $input Raw handling-time input.
	 * @return array<string, int> Normalized shape `{ min: int, max: int }`.
	 */
	/**
	 * Schema.org `DayOfWeek` tokens, in week order.
	 *
	 * These are IDENTIFIERS, not copy. Never wrap them in a translation
	 * function: a French store emitting "Lundi" publishes a value no consumer
	 * resolves, and the field silently stops working. The settings UI
	 * translates its own labels and stores these tokens.
	 *
	 * Monday first so emission order is deterministic regardless of the order
	 * a merchant ticked the boxes — the same configuration must always produce
	 * byte-identical JSON, or cached pages churn for no reason.
	 */
	const DAYS = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];

	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return [
				'min'           => 0,
				'max'           => 0,
				'business_days' => [],
			];
		}

		$min = isset( $input['min'] ) ? self::clamp( $input['min'] ) : 0;
		$max = isset( $input['max'] ) ? self::clamp( $input['max'] ) : 0;

		// max must be >= min when both are set; if not, reset max to min.
		if ( $max > 0 && $min > 0 && $max < $min ) {
			$max = $min;
		}

		return [
			'min'           => $min,
			'max'           => $max,
			'business_days' => self::sanitize_days( $input['business_days'] ?? [] ),
		];
	}

	/**
	 * Normalize the selected dispatch days.
	 *
	 * Unknown values are dropped rather than stored: this array is published
	 * verbatim, so a typo would become a public claim about the business.
	 *
	 * The result is rebuilt from DAYS, which deduplicates and week-orders in
	 * one step — the caller's order is deliberately not preserved.
	 *
	 * @param mixed $input Raw business-days input.
	 * @return string[]
	 */
	private static function sanitize_days( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$lookup = [];
		foreach ( self::DAYS as $day ) {
			$lookup[ strtolower( $day ) ] = $day;
		}

		$selected = [];
		foreach ( $input as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$key = strtolower( trim( $value ) );
			if ( isset( $lookup[ $key ] ) ) {
				$selected[ $lookup[ $key ] ] = true;
			}
		}

		return array_values(
			array_filter(
				self::DAYS,
				static function ( $day ) use ( $selected ) {
					return isset( $selected[ $day ] );
				}
			)
		);
	}

	/**
	 * Clamp a value to a non-negative integer in the 0–365 range.
	 *
	 * @param mixed $v Input value.
	 * @return int
	 */
	private static function clamp( $v ): int {
		$n = max( 0, (int) $v );
		return min( 365, $n );
	}
}
