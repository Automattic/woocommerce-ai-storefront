<?php
/**
 * Builds Google's Organization-level shipping policy from WooCommerce zones.
 *
 * WooCommerce prices shipping by zone AND order value. Google's
 * `ShippingConditions` encodes exactly that pair, which product-level
 * `OfferShippingDetails.shippingRate` — a single `MonetaryAmount` — cannot.
 * On a store offering "free over $20, otherwise $20" there is no honest
 * product-level number: 0 lies to a $10 basket and 20 lies to a $30 one.
 * That is why this surface exists alongside the per-product one rather than
 * replacing it.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zones in, `hasShippingService` out.
 */
class WC_AI_Storefront_Shipping_Policy {

	/**
	 * Parse a WooCommerce shipping cost string into something publishable.
	 *
	 * `WC_Shipping_Flat_Rate::$cost` is an expression, not a number. It
	 * supports `[qty]`, `[cost]` and `[fee percent="10" min_fee="4"]`, all
	 * evaluated against a real cart at checkout time. Only two forms can be
	 * stated publicly without knowing the basket:
	 *
	 *   - a literal number, and
	 *   - a bare percentage of order value, which Google models as
	 *     `orderPercentage` (a fraction: 0.10 means 10%).
	 *
	 * Everything else returns null and the caller drops the condition.
	 * Publishing a guess would put a price in front of shoppers that
	 * checkout then contradicts, which costs more than saying nothing.
	 *
	 * @param string $cost Raw cost expression.
	 * @return array{type: string, value: float}|null
	 */
	public static function parse_cost( string $cost ): ?array {
		$cost = trim( $cost );
		if ( '' === $cost ) {
			return null;
		}

		if ( is_numeric( $cost ) ) {
			return array(
				'type'  => 'literal',
				'value' => (float) $cost,
			);
		}

		// A bare fee shortcode and nothing else. `min_fee`/`max_fee` are
		// deliberately ignored: they clamp the computed amount, which
		// `orderPercentage` cannot express, but their presence does not make
		// the percentage itself wrong — only less precise at the extremes.
		//
		// A percentage COMBINED with other terms (e.g. `5 + [fee percent="10"]`)
		// falls through to null on purpose. Google can express a flat rate or
		// a percentage, not their sum, so publishing either half alone would
		// misstate the real cost.
		if ( preg_match( '/^\[fee\s+[^\]]*percent=(["\'])([0-9.]+)\1[^\]]*\]$/', $cost, $matches ) ) {
			return array(
				'type'  => 'percent',
				'value' => (float) $matches[2] / 100,
			);
		}

		return null;
	}
}
