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
	 * Expects `$wc_variation` to be the JSON-decoded Store API response
	 * for a single variation (typically fetched via
	 * `rest_do_request(GET /wc/store/v1/products/{variation_id})`).
	 *
	 * @param array<string, mixed> $wc_variation Decoded Store API response.
	 * @return array<string, mixed>              UCP variant shape.
	 */
	public static function translate( array $wc_variation ): array {
		$id      = (int) ( $wc_variation['id'] ?? 0 );
		$variant = [
			'id'          => self::VARIANT_ID_PREFIX . $id,
			'title'       => self::extract_title( $wc_variation ),
			'description' => self::extract_description( $wc_variation ),
			'price'       => self::extract_price( $wc_variation ),
		];

		// Optional fields. Only emit when present in WC source.
		if ( ! empty( $wc_variation['sku'] ) ) {
			$variant['sku'] = $wc_variation['sku'];
		}

		$variant['availability'] = [
			'available' => (bool) ( $wc_variation['is_in_stock'] ?? true ),
		];

		return $variant;
	}

	/**
	 * Synthesize a default variant for a simple (non-variable) product.
	 *
	 * Simple WC products don't have variations, but UCP's schema requires
	 * every product to emit `variants[]` with minItems 1. We satisfy that
	 * by emitting one variant representing the product itself: same price,
	 * same availability, id suffixed with `_default` so it's distinguishable
	 * from a real variation.
	 *
	 * @param array<string, mixed> $wc_product Decoded Store API response.
	 * @return array<string, mixed>            UCP variant shape.
	 */
	public static function synthesize_default( array $wc_product ): array {
		$id = (int) ( $wc_product['id'] ?? 0 );

		$variant = [
			'id'           => self::VARIANT_ID_PREFIX . $id . self::DEFAULT_VARIANT_SUFFIX,
			'title'        => $wc_product['name'] ?? '',
			'description'  => [ 'plain' => '' ],
			'price'        => self::extract_price( $wc_product ),
			'availability' => [
				'available' => (bool) ( $wc_product['is_in_stock'] ?? true ),
			],
		];

		if ( ! empty( $wc_product['sku'] ) ) {
			$variant['sku'] = $wc_product['sku'];
		}

		return $variant;
	}

	/**
	 * Extract a human-readable variant title from the WC response.
	 *
	 * For a real WC variation, the `name` field is typically the parent
	 * product name; the meaningful title comes from the variation's
	 * attributes (e.g. "Blue / Large"). Fall back to `name` when
	 * attributes are absent.
	 *
	 * @param array<string, mixed> $wc_variation
	 */
	private static function extract_title( array $wc_variation ): string {
		$attributes = $wc_variation['attributes'] ?? [];
		$values     = [];

		foreach ( $attributes as $attribute ) {
			if ( ! empty( $attribute['value'] ) ) {
				$values[] = $attribute['value'];
			}
		}

		if ( ! empty( $values ) ) {
			return implode( ' / ', $values );
		}

		return $wc_variation['name'] ?? '';
	}

	/**
	 * Extract a UCP description object from the WC response.
	 *
	 * @param array<string, mixed> $wc
	 * @return array{plain: string}
	 */
	private static function extract_description( array $wc ): array {
		$raw = $wc['short_description'] ?? '';
		// wp_strip_all_tags() over native strip_tags(): the WordPress
		// helper also strips the CONTENT of <script> and <style> tags
		// (not just the tags themselves) and trims surrounding whitespace.
		// Both are safer defaults for content that might originate from a
		// rich-text editor. PHPCS flags native strip_tags in plugin code
		// for exactly this reason.
		$plain = html_entity_decode(
			wp_strip_all_tags( (string) $raw ),
			ENT_QUOTES,
			'UTF-8'
		);
		return [ 'plain' => $plain ];
	}

	/**
	 * Extract a UCP price object from the WC response.
	 *
	 * Critical: WC Store API returns `prices.price` as a STRING in
	 * integer minor units (e.g. "12000" = $120.00 for USD). No float
	 * conversion, no * 100 math. Just cast to int. Works for JPY (0
	 * decimals), BHD (3 decimals), USD/EUR (2 decimals) uniformly
	 * because WC already computed correctly.
	 *
	 * @param array<string, mixed> $wc
	 * @return array{amount: int, currency: string}
	 */
	private static function extract_price( array $wc ): array {
		$prices = $wc['prices'] ?? [];
		return [
			'amount'   => (int) ( $prices['price'] ?? 0 ),
			'currency' => $prices['currency_code'] ?? 'USD',
		];
	}
}
