<?php
/**
 * AI Syndication: Product Catalog REST API
 *
 * Public-facing REST API for AI agents to query products,
 * categories, store info, and prepare carts. Authenticated
 * via X-AI-Agent-Key header.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for AI agent product discovery.
 */
class WC_AI_Syndication_Catalog_Api {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'wc/v3/ai-syndication';

	/**
	 * Bot manager instance.
	 *
	 * @var WC_AI_Syndication_Bot_Manager
	 */
	private $bot_manager;

	/**
	 * Rate limiter instance.
	 *
	 * @var WC_AI_Syndication_Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param WC_AI_Syndication_Bot_Manager  $bot_manager  Bot manager.
	 * @param WC_AI_Syndication_Rate_Limiter $rate_limiter Rate limiter.
	 */
	public function __construct( $bot_manager, $rate_limiter ) {
		$this->bot_manager  = $bot_manager;
		$this->rate_limiter = $rate_limiter;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/products',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_products' ],
				'permission_callback' => [ $this, 'check_agent_permission' ],
				'args'                => $this->get_products_args(),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_product' ],
				'permission_callback' => [ $this, 'check_agent_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_categories' ],
				'permission_callback' => [ $this, 'check_agent_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/store',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_store_info' ],
				'permission_callback' => [ $this, 'check_agent_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/cart/prepare',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'prepare_cart' ],
				'permission_callback' => [ $this, 'check_agent_permission' ],
				'args'                => [
					'items' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'product_id'   => [ 'type' => 'integer', 'required' => true ],
								'quantity'     => [ 'type' => 'integer', 'default'  => 1 ],
								'variation_id' => [ 'type' => 'integer', 'default'  => 0 ],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Check if the requesting agent is authenticated and rate limited.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function check_agent_permission( $request ) {
		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return new WP_Error(
				'ai_syndication_disabled',
				__( 'AI syndication is not enabled.', 'woocommerce-ai-syndication' ),
				[ 'status' => 404 ]
			);
		}

		$bot_id = $this->bot_manager->authenticate( $request );
		if ( is_wp_error( $bot_id ) ) {
			return $bot_id;
		}

		$rate_check = $this->rate_limiter->check( $bot_id );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Store bot_id on the request for use in callbacks.
		$request->set_param( '_ai_bot_id', $bot_id );
		return true;
	}

	/**
	 * Get products endpoint.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_products( $request ) {
		$settings = WC_AI_Syndication::get_settings();

		$query_args = [
			'status'  => 'publish',
			'limit'   => min( absint( $request->get_param( 'per_page' ) ?: 20 ), 100 ),
			'page'    => max( absint( $request->get_param( 'page' ) ?: 1 ), 1 ),
			'orderby' => sanitize_key( $request->get_param( 'orderby' ) ?: 'popularity' ),
			'order'   => in_array( strtoupper( $request->get_param( 'order' ) ?? '' ), [ 'ASC', 'DESC' ], true )
				? strtoupper( $request->get_param( 'order' ) )
				: 'DESC',
		];

		// Search filter.
		$search = $request->get_param( 'search' );
		if ( $search ) {
			$query_args['s'] = sanitize_text_field( $search );
		}

		// Category filter.
		$category = $request->get_param( 'category' );
		if ( $category ) {
			$query_args['category'] = [ sanitize_text_field( $category ) ];
		}

		// Price range filter.
		$min_price = $request->get_param( 'min_price' );
		$max_price = $request->get_param( 'max_price' );
		if ( $min_price ) {
			$query_args['min_price'] = floatval( $min_price );
		}
		if ( $max_price ) {
			$query_args['max_price'] = floatval( $max_price );
		}

		// Apply product selection restrictions.
		$product_mode = $settings['product_selection_mode'] ?? 'all';
		if ( 'categories' === $product_mode && ! empty( $settings['selected_categories'] ) ) {
			$selected_cats = array_map( 'absint', $settings['selected_categories'] );
			$existing_cats = isset( $query_args['category'] ) ? $query_args['category'] : [];
			if ( ! empty( $existing_cats ) ) {
				// Intersect requested categories with allowed categories.
				$cat_terms     = get_terms( [
					'taxonomy' => 'product_cat',
					'slug'     => $existing_cats,
					'fields'   => 'ids',
				] );
				$query_args['category'] = array_intersect(
					is_wp_error( $cat_terms ) ? [] : $cat_terms,
					$selected_cats
				);
			} else {
				$query_args['tax_query'] = [
					[
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $selected_cats,
					],
				];
			}
		} elseif ( 'selected' === $product_mode && ! empty( $settings['selected_products'] ) ) {
			$query_args['include'] = array_map( 'absint', $settings['selected_products'] );
		}

		$products = wc_get_products( $query_args );
		$data     = [];

		foreach ( $products as $product ) {
			$data[] = $this->format_product( $product );
		}

		// Get total count for pagination.
		$count_args          = $query_args;
		$count_args['limit'] = -1;
		$count_args['return'] = 'ids';
		$total                = count( wc_get_products( $count_args ) );

		$response = new WP_REST_Response( $data );
		$response->header( 'X-WC-Total', $total );
		$response->header( 'X-WC-TotalPages', ceil( $total / $query_args['limit'] ) );

		return $response;
	}

	/**
	 * Get single product endpoint.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_product( $request ) {
		$product_id = absint( $request->get_param( 'id' ) );
		$product    = wc_get_product( $product_id );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error(
				'ai_syndication_product_not_found',
				__( 'Product not found.', 'woocommerce-ai-syndication' ),
				[ 'status' => 404 ]
			);
		}

		$settings = WC_AI_Syndication::get_settings();
		if ( ! WC_AI_Syndication::is_product_syndicated( $product, $settings ) ) {
			return new WP_Error(
				'ai_syndication_product_not_available',
				__( 'Product is not available for AI syndication.', 'woocommerce-ai-syndication' ),
				[ 'status' => 403 ]
			);
		}

		return new WP_REST_Response( $this->format_product( $product, true ) );
	}

	/**
	 * Get categories endpoint.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_categories( $request ) {
		$settings = WC_AI_Syndication::get_settings();

		$args = [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		];

		$product_mode = $settings['product_selection_mode'] ?? 'all';
		if ( 'categories' === $product_mode && ! empty( $settings['selected_categories'] ) ) {
			$args['include'] = array_map( 'absint', $settings['selected_categories'] );
		}

		$categories = get_terms( $args );
		if ( is_wp_error( $categories ) ) {
			return new WP_REST_Response( [] );
		}

		$data = [];
		foreach ( $categories as $category ) {
			$link = get_term_link( $category );
			$data[] = [
				'id'          => $category->term_id,
				'name'        => $category->name,
				'slug'        => $category->slug,
				'description' => $category->description,
				'count'       => $category->count,
				'parent'      => $category->parent,
				'url'         => is_wp_error( $link ) ? '' : $link,
				'image'       => $this->get_category_image( $category->term_id ),
			];
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Get store info endpoint.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_store_info( $request ) {
		$base_location = wc_get_base_location();

		$data = [
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url( '/' ),
			'shop_url'    => wc_get_page_permalink( 'shop' ),
			'currency'    => get_woocommerce_currency(),
			'currency_symbol' => get_woocommerce_currency_symbol(),
			'country'     => $base_location['country'] ?? '',
			'locale'      => get_locale(),
			'checkout'    => [
				'method' => 'web_redirect',
				'url'    => wc_get_checkout_url(),
			],
			'attribution' => [
				'system'     => 'woocommerce_order_attribution',
				'parameters' => [
					'utm_source'    => '{agent_id}',
					'utm_medium'    => 'ai_agent',
					'utm_campaign'  => '{campaign}',
					'ai_session_id' => '{session_id}',
				],
			],
		];

		return new WP_REST_Response( $data );
	}

	/**
	 * Prepare a cart with items for redirect checkout.
	 *
	 * Returns a redirect URL with items pre-loaded.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_cart( $request ) {
		$items  = $request->get_param( 'items' );
		$bot_id = $request->get_param( '_ai_bot_id' );

		if ( ! $this->bot_manager->has_permission( $bot_id, 'prepare_cart' ) ) {
			return new WP_Error(
				'ai_syndication_permission_denied',
				__( 'Cart preparation not permitted for this agent.', 'woocommerce-ai-syndication' ),
				[ 'status' => 403 ]
			);
		}

		$validated_items = [];
		foreach ( $items as $item ) {
			$product_id   = absint( $item['product_id'] ?? 0 );
			$quantity     = max( absint( $item['quantity'] ?? 1 ), 1 );
			$variation_id = absint( $item['variation_id'] ?? 0 );

			$product = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product || ! $product->is_purchasable() ) {
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				continue;
			}

			$validated_items[] = [
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
				'name'         => $product->get_name(),
				'price'        => $product->get_price(),
				'in_stock'     => true,
			];
		}

		if ( empty( $validated_items ) ) {
			return new WP_Error(
				'ai_syndication_empty_cart',
				__( 'No valid items for cart.', 'woocommerce-ai-syndication' ),
				[ 'status' => 400 ]
			);
		}

		// Build a redirect URL that adds all items to cart.
		// Uses WooCommerce's native add-to-cart mechanism.
		$base_url = wc_get_cart_url();
		$add_urls = [];

		foreach ( $validated_items as $item ) {
			$add_urls[] = add_query_arg(
				array_filter( [
					'add-to-cart'  => $item['product_id'],
					'quantity'     => $item['quantity'],
					'variation_id' => $item['variation_id'] ?: null,
				] ),
				home_url( '/' )
			);
		}

		// For a single item, use direct add-to-cart URL.
		// For multiple items, return the Store API batch endpoint info.
		$bots         = ( new WC_AI_Syndication_Bot_Manager() )->get_bots_for_display();
		$current_bot  = null;
		foreach ( $bots as $bot ) {
			if ( $bot['id'] === $bot_id ) {
				$current_bot = $bot;
				break;
			}
		}

		$response_data = [
			'items'        => $validated_items,
			'checkout_url' => add_query_arg(
				[
					'utm_source'    => $current_bot ? sanitize_title( $current_bot['name'] ) : 'ai_agent',
					'utm_medium'    => 'ai_agent',
					'ai_session_id' => $request->get_param( 'session_id' ) ?? '',
				],
				wc_get_checkout_url()
			),
			'cart_url'     => $base_url,
			'store_api'    => [
				'batch_endpoint' => rest_url( 'wc/store/v1/batch' ),
				'add_item'       => rest_url( 'wc/store/v1/cart/add-item' ),
				'instructions'   => 'Use the WooCommerce Store API to add items to cart, then redirect the customer to checkout_url.',
			],
		];

		if ( 1 === count( $validated_items ) ) {
			$item = $validated_items[0];
			$response_data['direct_add_url'] = add_query_arg(
				array_filter( [
					'add-to-cart'   => $item['product_id'],
					'quantity'      => $item['quantity'],
					'variation_id'  => $item['variation_id'] ?: null,
					'utm_source'    => $current_bot ? sanitize_title( $current_bot['name'] ) : 'ai_agent',
					'utm_medium'    => 'ai_agent',
					'ai_session_id' => $request->get_param( 'session_id' ) ?? '',
				] ),
				home_url( '/' )
			);
		}

		return new WP_REST_Response( $response_data );
	}

	/**
	 * Format a product for API output.
	 *
	 * @param WC_Product $product  The product.
	 * @param bool       $detailed Whether to include full details.
	 * @return array
	 */
	private function format_product( $product, $detailed = false ) {
		$data = [
			'id'               => $product->get_id(),
			'name'             => $product->get_name(),
			'slug'             => $product->get_slug(),
			'type'             => $product->get_type(),
			'url'              => $product->get_permalink(),
			'price'            => $product->get_price(),
			'regular_price'    => $product->get_regular_price(),
			'sale_price'       => $product->get_sale_price(),
			'price_html'       => wp_strip_all_tags( $product->get_price_html() ),
			'currency'         => get_woocommerce_currency(),
			'on_sale'          => $product->is_on_sale(),
			'in_stock'         => $product->is_in_stock(),
			'stock_status'     => $product->get_stock_status(),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'sku'              => $product->get_sku(),
			'image'            => $this->get_product_image( $product ),
			'categories'       => $this->get_product_category_names( $product ),
			'average_rating'   => $product->get_average_rating(),
			'review_count'     => $product->get_review_count(),
			'buy_url'          => add_query_arg(
				[
					'add-to-cart'   => $product->get_id(),
					'utm_source'    => '{agent_id}',
					'utm_medium'    => 'ai_agent',
					'ai_session_id' => '{session_id}',
				],
				$product->get_permalink()
			),
		];

		if ( $product->managing_stock() ) {
			$data['stock_quantity'] = $product->get_stock_quantity();
		}

		if ( $detailed ) {
			$data['description'] = wp_strip_all_tags( $product->get_description() );
			$data['weight']      = $product->get_weight();
			$data['dimensions']  = $product->has_dimensions() ? $product->get_dimensions( false ) : null;
			$data['attributes']  = $this->get_product_attributes( $product );
			$data['images']      = $this->get_product_images( $product );
			$data['tags']        = $this->get_product_tag_names( $product );

			if ( $product->is_type( 'variable' ) ) {
				$data['variations'] = $this->get_variations( $product );
			}
		}

		/**
		 * Filter the formatted product data for the AI catalog API.
		 *
		 * @since 1.0.0
		 * @param array      $data     The product data.
		 * @param WC_Product $product  The product.
		 * @param bool       $detailed Whether full details are included.
		 */
		return apply_filters( 'wc_ai_syndication_catalog_product', $data, $product, $detailed );
	}

	/**
	 * Get product main image URL.
	 *
	 * @param WC_Product $product The product.
	 * @return string
	 */
	private function get_product_image( $product ) {
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$image = wp_get_attachment_image_url( $image_id, 'woocommerce_single' );
			return $image ?: '';
		}
		return wc_placeholder_img_src( 'woocommerce_single' );
	}

	/**
	 * Get all product images.
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private function get_product_images( $product ) {
		$images    = [];
		$image_ids = $product->get_gallery_image_ids();
		$main_id   = $product->get_image_id();

		if ( $main_id ) {
			array_unshift( $image_ids, $main_id );
		}

		foreach ( array_slice( array_unique( $image_ids ), 0, 10 ) as $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'woocommerce_single' );
			if ( $url ) {
				$images[] = $url;
			}
		}

		return $images;
	}

	/**
	 * Get product category names.
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private function get_product_category_names( $product ) {
		$category_ids = $product->get_category_ids();
		$names        = [];
		foreach ( $category_ids as $cat_id ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}

	/**
	 * Get product tag names.
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private function get_product_tag_names( $product ) {
		$tag_ids = $product->get_tag_ids();
		$names   = [];
		foreach ( $tag_ids as $tag_id ) {
			$term = get_term( $tag_id, 'product_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}

	/**
	 * Get product visible attributes.
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private function get_product_attributes( $product ) {
		$result     = [];
		$attributes = $product->get_attributes();

		foreach ( $attributes as $attribute ) {
			if ( ! $attribute->get_visible() ) {
				continue;
			}

			$name  = wc_attribute_label( $attribute->get_name(), $product );
			$value = $product->get_attribute( $attribute->get_name() );

			if ( $value ) {
				$result[] = [
					'name'  => $name,
					'value' => $value,
				];
			}
		}

		return $result;
	}

	/**
	 * Get variations for a variable product.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private function get_variations( $product ) {
		$variations = [];
		$children   = $product->get_children();

		foreach ( array_slice( $children, 0, 50 ) as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! $variation->is_purchasable() ) {
				continue;
			}

			$variations[] = [
				'id'            => $variation->get_id(),
				'sku'           => $variation->get_sku(),
				'price'         => $variation->get_price(),
				'regular_price' => $variation->get_regular_price(),
				'sale_price'    => $variation->get_sale_price(),
				'in_stock'      => $variation->is_in_stock(),
				'stock_quantity' => $variation->managing_stock() ? $variation->get_stock_quantity() : null,
				'attributes'    => $variation->get_attributes(),
				'image'         => $this->get_product_image( $variation ),
			];
		}

		return $variations;
	}

	/**
	 * Get category thumbnail image URL.
	 *
	 * @param int $category_id Category term ID.
	 * @return string
	 */
	private function get_category_image( $category_id ) {
		$thumbnail_id = get_term_meta( $category_id, 'thumbnail_id', true );
		if ( $thumbnail_id ) {
			$url = wp_get_attachment_image_url( $thumbnail_id, 'woocommerce_thumbnail' );
			return $url ?: '';
		}
		return '';
	}

	/**
	 * Get product search/filter arguments schema.
	 *
	 * @return array
	 */
	private function get_products_args() {
		return [
			'search'    => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'category'  => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'per_page'  => [
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => 'rest_validate_request_arg',
			],
			'page'      => [
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
			],
			'orderby'   => [
				'type'    => 'string',
				'default' => 'popularity',
				'enum'    => [ 'popularity', 'price', 'date', 'rating', 'title' ],
			],
			'order'     => [
				'type'    => 'string',
				'default' => 'DESC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'min_price' => [
				'type' => 'number',
			],
			'max_price' => [
				'type' => 'number',
			],
		];
	}
}
