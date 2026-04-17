<?php
/**
 * AI Syndication: Admin REST Controller
 *
 * Provides REST API endpoints for the admin settings UI:
 * - GET/POST settings
 * - Get attribution stats
 * - Get categories/products for selection UI
 * - Get discovery endpoint URLs
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin REST controller for AI syndication settings.
 */
class WC_AI_Syndication_Admin_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'wc/v3/ai-syndication/admin';

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Settings.
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'enabled'                => [
							'type' => 'string',
							'enum' => [ 'yes', 'no' ],
						],
						'product_selection_mode' => [
							'type' => 'string',
							'enum' => [ 'all', 'categories', 'selected' ],
						],
						'selected_categories'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'selected_products'      => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'rate_limit_rpm'         => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 1000,
						],
						'allowed_crawlers'       => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
				],
			]
		);

		// Attribution stats.
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'period' => [
						'type'    => 'string',
						'default' => 'month',
						'enum'    => [ 'day', 'week', 'month', 'year' ],
					],
				],
			]
		);

		// Product/category search for selection UI.
		register_rest_route(
			self::NAMESPACE,
			'/search/categories',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_categories' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/products',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_products' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'search'   => [ 'type' => 'string' ],
					'per_page' => [
						'type'    => 'integer',
						'default' => 20,
					],
				],
			]
		);

		// Discovery endpoint URLs.
		register_rest_route(
			self::NAMESPACE,
			'/endpoints',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_endpoints_info' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);
	}

	/**
	 * Check admin permission.
	 *
	 * @return bool
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return new WP_REST_Response( WC_AI_Syndication::get_settings() );
	}

	/**
	 * Update settings.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$data = [];

		$fields = [ 'enabled', 'product_selection_mode', 'selected_categories', 'selected_products', 'rate_limit_rpm', 'allowed_crawlers' ];
		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		$old_settings = WC_AI_Syndication::get_settings();
		WC_AI_Syndication::update_settings( $data );

		// Schedule a rewrite rule flush when enabled state changes.
		if ( isset( $data['enabled'] ) && $data['enabled'] !== ( $old_settings['enabled'] ?? 'no' ) ) {
			set_transient( 'wc_ai_syndication_flush_rewrite', 1, HOUR_IN_SECONDS );

			// Eagerly generate and cache llms.txt + UCP manifest.
			if ( 'yes' === $data['enabled'] ) {
				$llms_txt = new WC_AI_Syndication_Llms_Txt();
				$content  = $llms_txt->generate();
				set_transient( WC_AI_Syndication_Llms_Txt::CACHE_KEY, $content, HOUR_IN_SECONDS );

				$ucp      = new WC_AI_Syndication_Ucp();
				$manifest = wp_json_encode( $ucp->generate_manifest( WC_AI_Syndication::get_settings() ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
				set_transient( WC_AI_Syndication_Ucp::CACHE_KEY, $manifest, HOUR_IN_SECONDS );
			}
		}

		return new WP_REST_Response( WC_AI_Syndication::get_settings() );
	}

	/**
	 * Get attribution stats.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_stats( $request ) {
		$period = $request->get_param( 'period' );
		$stats  = WC_AI_Syndication_Attribution::get_stats( $period );

		return new WP_REST_Response( $stats );
	}

	/**
	 * Search categories for the selection UI.
	 *
	 * @return WP_REST_Response
	 */
	public function search_categories() {
		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $categories ) ) {
			return new WP_REST_Response( [] );
		}

		$data = [];
		foreach ( $categories as $category ) {
			$data[] = [
				'id'     => $category->term_id,
				'name'   => $category->name,
				'slug'   => $category->slug,
				'count'  => $category->count,
				'parent' => $category->parent,
			];
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Search products for the selection UI.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function search_products( $request ) {
		$search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$per_page = min( absint( $request->get_param( 'per_page' ) ?? 20 ), 100 );

		$args = [
			'status' => 'publish',
			'limit'  => $per_page,
			'type'   => [ 'simple', 'variable' ],
		];

		if ( $search ) {
			$args['s'] = $search;
		}

		$products = wc_get_products( $args );
		$data     = [];

		foreach ( $products as $product ) {
			// `wp_get_attachment_image_url` returns false for products with
			// no image; normalize to empty string for JSON consumers.
			$image_url = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
			$data[]    = [
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'sku'   => $product->get_sku(),
				'price' => wp_strip_all_tags( $product->get_price_html() ),
				'image' => $image_url ? $image_url : '',
			];
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Get discovery endpoint URLs for admin display.
	 *
	 * @return WP_REST_Response
	 */
	public function get_endpoints_info() {
		return new WP_REST_Response(
			[
				'llms_txt'  => home_url( '/llms.txt' ),
				'ucp'       => home_url( '/.well-known/ucp' ),
				'store_api' => rest_url( 'wc/store/v1' ),
				// robots.txt is always reachable (WordPress serves it
				// unconditionally), but our plugin appends the AI-crawler
				// allow-list + Allow directives when syndication is
				// enabled. Surfacing it here gives merchants a direct
				// view of what the plugin publishes to bots.
				'robots'    => home_url( '/robots.txt' ),
			]
		);
	}
}
