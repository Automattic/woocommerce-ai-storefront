<?php
/**
 * Shopify-compatible /products.json catalog feed.
 *
 * Serves the store catalog in Shopify's public products.json shape at the
 * endpoints AI agents are trained to probe (`/products.json` and the
 * `/collections/all/products.json` alias). NON-UCP, additive compatibility
 * surface — does not alter the UCP manifest, REST/MCP, llms.txt, or JSON-LD.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Shopify-compatible products.json rewrite endpoints and maps
 * WooCommerce products into the Shopify product JSON shape.
 */
class WC_AI_Storefront_Products_Feed {

	/**
	 * Resolve a single Shopify-style `product_type` string from a product's
	 * WooCommerce categories.
	 *
	 * Shopify's `product_type` is a single free-text type (e.g. "Hoodie"),
	 * distinct from collections. WC has no equivalent single field and allows
	 * many `product_cat` terms, so we synthesize one in priority order:
	 *   1. SEO-plugin primary category (Yoast / RankMath meta) — merchant intent.
	 *   2. Most-specific (deepest) assigned category — mimics Shopify's type.
	 *   3. First assigned category.
	 *   4. '' (Shopify always emits the key as a string).
	 *
	 * @param WC_Product $product The product.
	 * @return string Decoded plain-text type, or '' when uncategorized.
	 */
	public static function resolve_product_type( $product ): string {
		$product_id = (int) $product->get_id();

		// 1. SEO-plugin primary category.
		foreach ( [ '_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat' ] as $meta_key ) {
			$primary_id = (int) get_post_meta( $product_id, $meta_key, true );
			if ( $primary_id > 0 ) {
				$term = get_term( $primary_id, 'product_cat' );
				if ( $term instanceof WP_Term ) {
					return self::decode( $term->name );
				}
			}
		}

		$term_ids = $product->get_category_ids();
		if ( empty( $term_ids ) || ! is_array( $term_ids ) ) {
			return '';
		}

		$terms = array_filter(
			array_map(
				static function ( $id ) {
					return get_term( (int) $id, 'product_cat' );
				},
				$term_ids
			),
			static function ( $t ) {
				return $t instanceof WP_Term;
			}
		);
		if ( empty( $terms ) ) {
			return '';
		}

		// 2. Deepest (most-specific) term — greatest ancestor depth.
		usort(
			$terms,
			static function ( $a, $b ) {
				$depth_a = count( get_ancestors( $a->term_id, 'product_cat' ) );
				$depth_b = count( get_ancestors( $b->term_id, 'product_cat' ) );
				if ( $depth_a !== $depth_b ) {
					return $depth_b <=> $depth_a; // Deeper first.
				}
				return $a->term_id <=> $b->term_id; // Stable tiebreak.
			}
		);

		// 3. First (now: deepest, else first assigned) — usort leaves the best at [0].
		return self::decode( $terms[0]->name );
	}

	/**
	 * Decode HTML entities to plain UTF-8 (term/product names arrive encoded).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function decode( string $value ): string {
		return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
