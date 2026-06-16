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

	const PRODUCT_FILTER = 'wc_ai_storefront_products_feed_product';

	/**
	 * Map one WC product to the Shopify product JSON shape (pragmatic full —
	 * the fields a trained parser keys on; Shopify-internal fields omitted).
	 *
	 * @param WC_Product $product The product.
	 * @return array Shopify-shaped product.
	 */
	public static function map_product( $product ): array {
		$is_variable = method_exists( $product, 'is_type' ) && $product->is_type( 'variable' );

		$data = [
			'id'           => (int) $product->get_id(),
			'title'        => self::decode( (string) $product->get_name() ),
			'handle'       => (string) $product->get_slug(),
			'body_html'    => (string) $product->get_description(),
			'vendor'       => self::resolve_vendor( $product ),
			'product_type' => self::resolve_product_type( $product ),
			'tags'         => self::resolve_tags( $product ),
			'variants'     => $is_variable
				? self::build_variants( $product )
				: [ self::build_simple_variant( $product ) ],
			'images'       => self::build_images( $product ),
		];

		$options = $is_variable ? self::build_options( $product ) : [];
		if ( ! empty( $options ) ) {
			$data['options'] = $options;
		}

		/**
		 * Filter a single mapped Shopify-shaped product before it enters the
		 * /products.json feed. Mirrors `wc_ai_storefront_ucp_product`.
		 *
		 * @param array      $data    The mapped product.
		 * @param WC_Product $product The source product.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- PRODUCT_FILTER is the literal 'wc_ai_storefront_products_feed_product'; the sniff can't resolve the constant to see the prefix.
		$filtered = apply_filters( self::PRODUCT_FILTER, $data, $product );
		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Vendor = first product_brand term, else null (genuinely absent).
	 *
	 * @param WC_Product $product The product.
	 * @return string|null
	 */
	private static function resolve_vendor( $product ): ?string {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return null;
		}
		$brands = wp_get_post_terms( (int) $product->get_id(), 'product_brand', [ 'fields' => 'names' ] );
		if ( is_array( $brands ) && ! empty( $brands ) && is_string( $brands[0] ) ) {
			return self::decode( $brands[0] );
		}
		return null;
	}

	/**
	 * Tags = comma-joined product_tag names (Shopify emits a string).
	 *
	 * @param WC_Product $product The product.
	 * @return string
	 */
	private static function resolve_tags( $product ): string {
		$ids = $product->get_tag_ids();
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return '';
		}
		$names = array_filter(
			array_map(
				static function ( $id ) {
					$t = get_term( (int) $id, 'product_tag' );
					return $t instanceof WP_Term ? self::decode( $t->name ) : null;
				},
				$ids
			)
		);
		return implode( ', ', $names );
	}

	/**
	 * Build the single variant for a simple product.
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private static function build_simple_variant( $product ): array {
		return [
			'id'                => (int) $product->get_id(),
			'title'             => 'Default Title',
			'option1'           => 'Default Title',
			'option2'           => null,
			'option3'           => null,
			'sku'               => (string) $product->get_sku(),
			'price'             => self::money( $product->get_price() ),
			'compare_at_price'  => self::compare_at( $product ),
			'available'         => (bool) ( $product->is_in_stock() && $product->is_purchasable() ),
			'requires_shipping' => method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true,
		];
	}

	/**
	 * Format a price as a 2-decimal string (Shopify emits price as a string).
	 *
	 * @param mixed $price Raw WC price.
	 * @return string
	 */
	private static function money( $price ): string {
		return is_numeric( $price ) ? number_format( (float) $price, 2, '.', '' ) : '0.00';
	}

	/**
	 * compare_at_price = regular price when on sale, else null.
	 *
	 * @param WC_Product $product The product (or variation).
	 * @return string|null
	 */
	private static function compare_at( $product ): ?string {
		if ( method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
			$regular = $product->get_regular_price();
			if ( is_numeric( $regular ) ) {
				return self::money( $regular );
			}
		}
		return null;
	}

	/**
	 * Build images[] from the featured image + gallery (needed by both simple
	 * and variable products, so defined here with the simple-product path).
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private static function build_images( $product ): array {
		$ids    = array_filter( array_merge( [ (int) $product->get_image_id() ], array_map( 'intval', (array) $product->get_gallery_image_ids() ) ) );
		$images = [];
		foreach ( array_unique( $ids ) as $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( is_string( $src ) && '' !== $src ) {
				$images[] = [
					'id'  => $id,
					'src' => $src,
				];
			}
		}
		return $images;
	}

	/**
	 * Build variants[] for a variable product from its variation children.
	 *
	 * option1/2/3 are filled from the variation's attribute values in the
	 * same order as build_options(); unused slots are null.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private static function build_variants( $product ): array {
		// Attribute names in declared order, e.g. pa_size then pa_color.
		$attr_keys = array_keys( $product->get_variation_attributes() );
		$variants  = [];

		foreach ( $product->get_children() as $child_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $child_id ) : null;
			if ( ! $variation ) {
				continue;
			}
			// Selected values keyed by attribute_<slug>, e.g. attribute_pa_size => m.
			$attributes = $variation->get_variation_attributes();

			$options = [ null, null, null ];
			$i       = 0;
			foreach ( $attr_keys as $key ) {
				if ( $i > 2 ) {
					break; // Shopify supports exactly 3 option positions.
				}
				$value         = $attributes[ 'attribute_' . sanitize_title( $key ) ] ?? ( $attributes[ 'attribute_' . $key ] ?? '' );
				$options[ $i ] = '' !== $value ? self::decode( (string) $value ) : null;
				++$i;
			}

			$variants[] = [
				'id'                => (int) $variation->get_id(),
				'title'             => implode(
					' / ',
					array_filter(
						$options,
						static function ( $v ) {
							return null !== $v;
						}
					)
				),
				'option1'           => $options[0],
				'option2'           => $options[1],
				'option3'           => $options[2],
				'sku'               => (string) $variation->get_sku(),
				'price'             => self::money( $variation->get_price() ),
				'compare_at_price'  => self::compare_at( $variation ),
				'available'         => (bool) ( $variation->is_in_stock() && $variation->is_purchasable() ),
				'requires_shipping' => method_exists( $variation, 'needs_shipping' ) ? (bool) $variation->needs_shipping() : true,
			];
		}

		return $variants;
	}

	/**
	 * Build options[] (name, position, values) for a variable product.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private static function build_options( $product ): array {
		$options  = [];
		$position = 1;
		foreach ( $product->get_variation_attributes() as $name => $values ) {
			if ( $position > 3 ) {
				break;
			}
			$label     = wc_attribute_label( $name, $product );
			$options[] = [
				'name'     => self::decode( (string) $label ),
				'position' => $position,
				'values'   => array_values( array_map( [ self::class, 'decode' ], array_map( 'strval', (array) $values ) ) ),
			];
			++$position;
		}
		return $options;
	}

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
