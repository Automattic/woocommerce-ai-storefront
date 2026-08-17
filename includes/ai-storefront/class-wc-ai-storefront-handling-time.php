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
	 * Schema.org `DayOfWeek` tokens, in week order.
	 *
	 * These are IDENTIFIERS, not copy. Never wrap them in a translation
	 * function: a French store emitting "Lundi" publishes a value no consumer
	 * resolves, and the field silently stops working. The settings UI
	 * translates its own labels and stores these tokens.
	 *
	 * Bare names, not `https://schema.org/Monday` IRIs. Both forms appear in
	 * Google's docs — the per-type reference examples use the IRI, the complete
	 * worked `ShippingService` example uses bare names — and we follow the
	 * worked example. Recorded because the "identifiers, not copy" argument
	 * above points at the IRI form, so this looks like an oversight otherwise.
	 *
	 * Monday first so emission order is deterministic regardless of the order
	 * a merchant ticked the boxes — the same configuration must always produce
	 * byte-identical JSON, or cached pages churn for no reason.
	 */
	const DAYS = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	/**
	 * Sanitize a raw handling-time input.
	 *
	 * Field rules:
	 *   - `min`: non-negative integer, 0–365. 0 means "not set".
	 *   - `max`: non-negative integer, 0–365. Must be >= min. 0 means "not set".
	 *   - `business_days`: subset of DAYS. Unknown values dropped, duplicates
	 *     collapsed, result week-ordered.
	 *
	 * Emission contract: the two halves are INDEPENDENT. A `handlingTime`
	 * needs both min and max > 0, but `businessDays` stands on its own — "we
	 * dispatch Monday to Friday" is a complete claim with no duration. The
	 * emitters publish whichever halves are configured and omit the block only
	 * when neither is.
	 *
	 * @param mixed $input Raw handling-time input.
	 * @return array{min: int, max: int, business_days: string[]}
	 */
	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return array(
				'min'           => 0,
				'max'           => 0,
				'business_days' => array(),
			);
		}

		$min = isset( $input['min'] ) ? self::clamp( $input['min'] ) : 0;
		$max = isset( $input['max'] ) ? self::clamp( $input['max'] ) : 0;

		// max must be >= min when both are set; if not, reset max to min.
		if ( $max > 0 && $min > 0 && $max < $min ) {
			$max = $min;
		}

		return array(
			'min'           => $min,
			'max'           => $max,
			'business_days' => self::sanitize_days( $input['business_days'] ?? array() ),
		);
	}

	/**
	 * The store's dispatch days, sanitized at READ time.
	 *
	 * `WC_AI_Storefront::get_settings()` merges defaults with `wp_parse_args`,
	 * which is shallow — a stored `handling_time` sub-array is returned
	 * verbatim, and sanitizing happens only on write. Every write inside the
	 * plugin routes through `update_settings()`, but a direct
	 * `update_option()` does not: `wp option update`, a migration script, or a
	 * staging database copy can all seed arbitrary strings.
	 *
	 * This array is published verbatim, so it is re-sanitized here rather than
	 * trusted. That also makes the week-order guarantee unconditional instead
	 * of a property of the save path.
	 *
	 * @param array $settings Full plugin settings.
	 * @return string[]
	 */
	public static function business_days( array $settings ): array {
		return self::sanitize_days( $settings['handling_time']['business_days'] ?? array() );
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
			return array();
		}

		$lookup = array();
		foreach ( self::DAYS as $day ) {
			$lookup[ strtolower( $day ) ] = $day;
		}

		$selected = array();
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
