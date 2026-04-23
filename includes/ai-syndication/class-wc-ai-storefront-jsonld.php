<?php
/**
 * AI Syndication: Enhanced JSON-LD
 *
 * Outputs deep semantic Schema.org Product markup on product pages
 * so AI agents can recommend products for specific use cases.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enhances WooCommerce JSON-LD output with AI-optimized structured data.
 */
class WC_AI_Storefront_JsonLd {

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_structured_data_product', [ $this, 'enhance_product_data' ], 20, 2 );
		add_action( 'wp_head', [ $this, 'output_store_jsonld' ], 5 );
	}

	/**
	 * Enhance WooCommerce product JSON-LD with AI-optimized fields.
	 *
	 * @param array      $markup  Existing product structured data.
	 * @param WC_Product $product The product object.
	 * @return array Enhanced markup.
	 */
	public function enhance_product_data( $markup, $product ) {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $markup;
		}

		if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
			return $markup;
		}

		// Add purchase action pointing to store checkout with attribution placeholders.
		$markup['potentialAction'] = [
			'@type'  => 'BuyAction',
			'target' => [
				'@type'          => 'EntryPoint',
				'urlTemplate'    => add_query_arg(
					[
						'add-to-cart'   => $product->get_id(),
						'utm_source'    => '{agent_id}',
						'utm_medium'    => 'ai_agent',
						'ai_session_id' => '{session_id}',
					],
					$product->get_permalink()
				),
				'actionPlatform' => [
					'https://schema.org/DesktopWebPlatform',
					'https://schema.org/MobileWebPlatform',
				],
			],
		];

		// Enhanced availability with inventory detail.
		if ( $product->managing_stock() ) {
			$stock_qty = $product->get_stock_quantity();
			if ( null !== $stock_qty ) {
				$markup['offers']['inventoryLevel'] = [
					'@type' => 'QuantitativeValue',
					'value' => $stock_qty,
				];
			}
		}

		// Add category breadcrumb path.
		$categories = wc_get_product_cat_ids( $product->get_id() );
		if ( ! empty( $categories ) ) {
			$cat_paths = [];
			foreach ( $categories as $cat_id ) {
				$ancestors = get_ancestors( $cat_id, 'product_cat', 'taxonomy' );
				$path      = [];
				foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, 'product_cat' );
					if ( $ancestor && ! is_wp_error( $ancestor ) ) {
						$path[] = $ancestor->name;
					}
				}
				$term = get_term( $cat_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$path[]      = $term->name;
					$cat_paths[] = implode( ' > ', $path );
				}
			}
			if ( ! empty( $cat_paths ) ) {
				$markup['category'] = $cat_paths[0];
			}
		}

		// Weight and dimensions using store-configured units.
		if ( $product->has_weight() ) {
			$markup['weight'] = [
				'@type'    => 'QuantitativeValue',
				'value'    => $product->get_weight(),
				'unitCode' => $this->get_weight_unit_code(),
			];
		}

		if ( $product->has_dimensions() ) {
			$dimensions       = $product->get_dimensions( false );
			$dimension_unit   = $this->get_dimension_unit_code();
			$markup['depth']  = [
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['length'],
				'unitCode' => $dimension_unit,
			];
			$markup['width']  = [
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['width'],
				'unitCode' => $dimension_unit,
			];
			$markup['height'] = [
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['height'],
				'unitCode' => $dimension_unit,
			];
		}

		// Product attributes as additionalProperty for semantic matching.
		$attributes = $product->get_attributes();
		if ( ! empty( $attributes ) ) {
			$additional_properties = [];
			foreach ( $attributes as $attribute ) {
				if ( ! $attribute->get_visible() ) {
					continue;
				}

				$name  = wc_attribute_label( $attribute->get_name(), $product );
				$value = $product->get_attribute( $attribute->get_name() );

				if ( $value ) {
					$additional_properties[] = [
						'@type' => 'PropertyValue',
						'name'  => $name,
						'value' => $value,
					];
				}
			}
			if ( ! empty( $additional_properties ) ) {
				$markup['additionalProperty'] = $additional_properties;
			}
		}

		// Shipping information.
		$base_location = wc_get_base_location();
		$country       = $base_location['country'] ?? '';

		if ( $country ) {
			$markup['shippingDetails'] = [
				'@type'               => 'OfferShippingDetails',
				'shippingDestination' => [
					'@type'          => 'DefinedRegion',
					'addressCountry' => $country,
				],
			];

			$markup['hasMerchantReturnPolicy'] = [
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			];
		}

		/**
		 * Filter the enhanced JSON-LD product data.
		 *
		 * @since 1.0.0
		 * @param array      $markup   The enhanced product structured data.
		 * @param WC_Product $product  The product.
		 * @param array      $settings The AI syndication settings.
		 */
		return apply_filters( 'wc_ai_storefront_jsonld_product', $markup, $product, $settings );
	}

	/**
	 * Output store-level JSON-LD on the homepage/shop page.
	 */
	public function output_store_jsonld() {
		if ( ! is_front_page() && ! is_shop() ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}

		$store_data = [
			'@context'           => 'https://schema.org',
			'@type'              => 'Store',
			'name'               => get_bloginfo( 'name' ),
			'description'        => get_bloginfo( 'description' ),
			'url'                => home_url( '/' ),
			'currenciesAccepted' => get_woocommerce_currency(),
			'potentialAction'    => [
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term}&post_type=product&utm_source={agent_id}&utm_medium=ai_agent' ),
				],
				'query-input' => 'required name=search_term',
			],
			'hasOfferCatalog'    => [
				'@type'           => 'OfferCatalog',
				'name'            => __( 'Products', 'woocommerce-ai-storefront' ),
				'itemListElement' => $this->get_catalog_summary(),
			],
		];

		/**
		 * Filter the store-level JSON-LD data.
		 *
		 * @since 1.0.0
		 * @param array $store_data The store structured data.
		 * @param array $settings   The AI syndication settings.
		 */
		$store_data = apply_filters( 'wc_ai_storefront_jsonld_store', $store_data, $settings );

		echo '<script type="application/ld+json">' . wp_json_encode( $store_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/**
	 * Get a catalog summary for JSON-LD.
	 *
	 * @return array
	 */
	private function get_catalog_summary() {
		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'number'     => 10,
				'orderby'    => 'count',
				'order'      => 'DESC',
			]
		);

		if ( is_wp_error( $categories ) ) {
			return [];
		}

		$items = [];
		foreach ( $categories as $category ) {
			$link = get_term_link( $category );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items[] = [
				'@type'         => 'OfferCatalog',
				'name'          => $category->name,
				'numberOfItems' => $category->count,
				'url'           => $link,
			];
		}

		return $items;
	}

	/**
	 * Map WooCommerce weight unit to UN/CEFACT unit code.
	 *
	 * @return string
	 */
	private function get_weight_unit_code() {
		$unit_map = [
			'kg'  => 'KGM',
			'g'   => 'GRM',
			'lbs' => 'LBR',
			'oz'  => 'ONZ',
		];
		$wc_unit  = get_option( 'woocommerce_weight_unit', 'kg' );
		return $unit_map[ $wc_unit ] ?? 'KGM';
	}

	/**
	 * Map WooCommerce dimension unit to UN/CEFACT unit code.
	 *
	 * @return string
	 */
	private function get_dimension_unit_code() {
		$unit_map = [
			'cm' => 'CMT',
			'm'  => 'MTR',
			'mm' => 'MMT',
			'in' => 'INH',
			'yd' => 'YRD',
		];
		$wc_unit  = get_option( 'woocommerce_dimension_unit', 'cm' );
		return $unit_map[ $wc_unit ] ?? 'CMT';
	}
}
