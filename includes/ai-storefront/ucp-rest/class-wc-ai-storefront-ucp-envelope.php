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
 * @package WooCommerce_AI_Storefront
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `ucp` response wrapper for each UCP capability context.
 */
class WC_AI_Storefront_UCP_Envelope {

	/**
	 * Build the `ucp` wrapper for a catalog response.
	 *
	 * Schema: response_catalog_schema (ucp.json). Requires `version`,
	 * allows `capabilities`. We always include `capabilities` with
	 * the specific capability the response implements, so agents can
	 * verify they hit the right operation.
	 *
	 * @param string $capability_name  Fully-qualified UCP capability name
	 *                                 (e.g. "dev.ucp.shopping.catalog.search").
	 * @return array<string, mixed>    The `ucp` wrapper object.
	 */
	public static function catalog_envelope( string $capability_name ): array {
		return [
			'version'      => WC_AI_Storefront_Ucp::PROTOCOL_VERSION,
			'capabilities' => [
				$capability_name => [
					[ 'version' => WC_AI_Storefront_Ucp::PROTOCOL_VERSION ],
				],
			],
		];
	}

	/**
	 * Build the `ucp` wrapper for a checkout response.
	 *
	 * Schema: response_checkout_schema (ucp.json). Requires
	 * `payment_handlers` as a top-level key (even if empty — an
	 * empty object declares "zero payment handlers exposed").
	 *
	 * @return array<string, mixed>    The `ucp` wrapper object.
	 */
	public static function checkout_envelope(): array {
		return [
			'version'          => WC_AI_Storefront_Ucp::PROTOCOL_VERSION,
			'capabilities'     => [
				'dev.ucp.shopping.checkout' => [
					[ 'version' => WC_AI_Storefront_Ucp::PROTOCOL_VERSION ],
				],
			],

			// `(object) []` ensures JSON serialization as `{}` not `[]`.
			// UCP schema requires object shape here; an empty array
			// would fail validation.
			'payment_handlers' => (object) [],
		];
	}
}
