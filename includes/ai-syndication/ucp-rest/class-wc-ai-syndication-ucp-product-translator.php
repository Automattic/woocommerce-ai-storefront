<?php
/**
 * AI Syndication: WC Product → UCP Product Translator
 *
 * Converts a WooCommerce Store API product response (as returned by
 * `rest_do_request( GET /wc/store/v1/products/{id} )`) into a UCP
 * product object conforming to:
 *
 *     source/schemas/shopping/types/product.json
 *
 * Required UCP fields: id, title, description, price_range, variants.
 * Prices are integer minor units (read directly from WC's
 * `prices.price` — no float math). Variants is minItems 1; simple
 * products emit one default variant, variable products emit one
 * per WC variation.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates WooCommerce Store API product responses into UCP product objects.
 */
class WC_AI_Syndication_UCP_Product_Translator {

	/**
	 * UCP product ID prefix. `prod_{wc_id}` distinguishes product IDs
	 * from variant IDs (`var_{wc_variation_id}`) at the UCP layer,
	 * letting `/catalog/lookup` handle both types through one route.
	 */
	const PRODUCT_ID_PREFIX = 'prod_';

	/**
	 * Translate a single WC Store API product response into a UCP product.
	 *
	 * @param array<string, mixed> $wc_product Decoded Store API product response.
	 * @return array<string, mixed>            UCP product shape.
	 */
	public static function translate( array $wc_product ): array {
		// TODO (task 5): implement field mapping per product.json schema.
		// TODO (task 7): for variable products, fetch and translate each variation.
		return [];
	}
}
