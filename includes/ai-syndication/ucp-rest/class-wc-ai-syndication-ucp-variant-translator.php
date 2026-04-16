<?php
/**
 * AI Syndication: WC Variation → UCP Variant Translator
 *
 * Converts a WooCommerce variation (either a product response for a
 * variable-product variation ID, or a synthesized default variant for
 * a simple product) into a UCP variant object conforming to:
 *
 *     source/schemas/shopping/types/variant.json
 *
 * Required UCP fields: id, title, description, price.
 * Variants also carry `options` (selected option values like
 * "Color: Blue, Size: Large"), `availability`, and optional
 * `sku`, `barcodes`, `media`.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates WC variations into UCP variant objects.
 */
class WC_AI_Syndication_UCP_Variant_Translator {

	/**
	 * UCP variant ID prefix. Distinguishes variant IDs from product
	 * IDs at the UCP layer.
	 */
	const VARIANT_ID_PREFIX = 'var_';

	/**
	 * Suffix for the synthesized default variant emitted for simple
	 * products. WC simple products don't have variations, but UCP
	 * requires at least one variant per product (schema minItems 1),
	 * so we emit one representing the product itself.
	 */
	const DEFAULT_VARIANT_SUFFIX = '_default';

	/**
	 * Translate a WC variation product response into a UCP variant.
	 *
	 * @param array<string, mixed> $wc_variation Decoded Store API response for the variation.
	 * @return array<string, mixed>              UCP variant shape.
	 */
	public static function translate( array $wc_variation ): array {
		// TODO (task 6): implement field mapping per variant.json schema.
		return [];
	}

	/**
	 * Synthesize a default variant for a simple (non-variable) product.
	 *
	 * @param array<string, mixed> $wc_product Decoded Store API response for a simple product.
	 * @return array<string, mixed>            UCP variant shape.
	 */
	public static function synthesize_default( array $wc_product ): array {
		// TODO (task 6): emit a one-variant-matching-the-product shape.
		return [];
	}
}
