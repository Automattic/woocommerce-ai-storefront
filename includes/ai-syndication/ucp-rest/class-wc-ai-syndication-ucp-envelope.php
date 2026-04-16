<?php
/**
 * AI Syndication: UCP Response Envelope Builder
 *
 * Every UCP response has a standard `ucp` wrapper declaring the
 * protocol version, status, capabilities the response relates to,
 * and (for checkout responses) payment_handlers.
 *
 * This class centralizes the wrapper construction so each route's
 * handler doesn't duplicate the boilerplate and can't accidentally
 * diverge from the spec shape.
 *
 * See UCP spec: source/schemas/ucp.json ($defs/response_*_schema).
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `ucp` response wrapper for each UCP capability context.
 */
class WC_AI_Syndication_UCP_Envelope {

	/**
	 * Build the `ucp` wrapper for a catalog response.
	 *
	 * @param string $capability_name  Fully-qualified UCP capability name.
	 * @return array<string, mixed>    The `ucp` wrapper object.
	 */
	public static function catalog_envelope( string $capability_name ): array {
		// TODO (task 4): implement per response_catalog_schema.
		return [];
	}

	/**
	 * Build the `ucp` wrapper for a checkout response.
	 *
	 * @return array<string, mixed>    The `ucp` wrapper object.
	 */
	public static function checkout_envelope(): array {
		// TODO (task 4): implement per response_checkout_schema.
		// Required fields: version, status, capabilities, payment_handlers.
		// payment_handlers MUST serialize as {} (object), never [] (array).
		return [];
	}
}
