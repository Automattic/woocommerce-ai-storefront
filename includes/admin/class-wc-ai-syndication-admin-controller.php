<?php
/**
 * AI Syndication: Admin REST Controller
 *
 * Provides REST API endpoints for the admin settings UI:
 * - GET/POST settings
 * - CRUD bots
 * - Get attribution stats
 * - Get categories/products for selection UI
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
	 * Bot manager instance.
	 *
	 * @var WC_AI_Syndication_Bot_Manager
	 */
	private $bot_manager;

	/**
	 * Constructor.
	 *
	 * @param WC_AI_Syndication_Bot_Manager $bot_manager Bot manager.
	 */
	public function __construct( $bot_manager ) {
		$this->bot_manager = $bot_manager;
	}

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
						'enabled'                => [ 'type' => 'string', 'enum' => [ 'yes', 'no' ] ],
						'product_selection_mode'  => [ 'type' => 'string', 'enum' => [ 'all', 'categories', 'selected' ] ],
						'selected_categories'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'selected_products'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'rate_limit_rpm'         => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ],
						'rate_limit_rph'         => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000 ],
					],
				],
			]
		);

		// Bots.
		register_rest_route(
			self::NAMESPACE,
			'/bots',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_bots' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_bot' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'name'        => [ 'type' => 'string', 'required' => true ],
						'permissions' => [ 'type' => 'object' ],
					],
				],
				'schema' => [ $this, 'get_bot_schema' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/bots/(?P<id>[a-f0-9-]+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_bot' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'name'        => [ 'type' => 'string' ],
						'permissions' => [ 'type' => 'object' ],
						'status'      => [ 'type' => 'string', 'enum' => [ 'active', 'revoked' ] ],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_bot' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/bots/(?P<id>[a-f0-9-]+)/regenerate-key',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'regenerate_bot_key' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
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
					'per_page' => [ 'type' => 'integer', 'default' => 20 ],
				],
			]
		);

		// Endpoints discovery info.
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

		$fields = [ 'enabled', 'product_selection_mode', 'selected_categories', 'selected_products', 'rate_limit_rpm', 'rate_limit_rph' ];
		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		$old_settings = WC_AI_Syndication::get_settings();
		WC_AI_Syndication::update_settings( $data );

		// Schedule a rewrite rule flush on the next page load when enabled changes.
		// REST API requests don't reliably trigger shutdown-hooked flushes, so we
		// use a transient flag that the main plugin class checks on init.
		if ( isset( $data['enabled'] ) && $data['enabled'] !== ( $old_settings['enabled'] ?? 'no' ) ) {
			set_transient( 'wc_ai_syndication_flush_rewrite', 1, HOUR_IN_SECONDS );

			// Eagerly generate and cache llms.txt + UCP manifest so they're
			// warm immediately after enabling — no waiting for the first request.
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
	 * Get registered bots.
	 *
	 * @return WP_REST_Response
	 */
	public function get_bots() {
		return new WP_REST_Response( $this->bot_manager->get_bots_for_display() );
	}

	/**
	 * Create a new bot.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function create_bot( $request ) {
		$name        = sanitize_text_field( $request->get_param( 'name' ) );
		$permissions = $request->get_param( 'permissions' ) ?: [];

		$result = $this->bot_manager->register_bot( $name, $permissions );

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Update a bot.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_bot( $request ) {
		$bot_id = $request->get_param( 'id' );
		$data   = [];

		foreach ( [ 'name', 'permissions', 'status' ] as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		$updated = $this->bot_manager->update_bot( $bot_id, $data );
		if ( ! $updated ) {
			return new WP_Error(
				'bot_not_found',
				__( 'Bot not found.', 'woocommerce-ai-syndication' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->bot_manager->get_bots_for_display() );
	}

	/**
	 * Delete a bot.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_bot( $request ) {
		$bot_id  = $request->get_param( 'id' );
		$deleted = $this->bot_manager->delete_bot( $bot_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'bot_not_found',
				__( 'Bot not found.', 'woocommerce-ai-syndication' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	/**
	 * Regenerate a bot's API key.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function regenerate_bot_key( $request ) {
		$bot_id = $request->get_param( 'id' );
		$result = $this->bot_manager->regenerate_key( $bot_id );

		if ( ! $result ) {
			return new WP_Error(
				'bot_not_found',
				__( 'Bot not found.', 'woocommerce-ai-syndication' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $result );
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
		$categories = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

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
		$per_page = min( absint( $request->get_param( 'per_page' ) ?: 20 ), 100 );

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
			$data[] = [
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'sku'   => $product->get_sku(),
				'price' => wp_strip_all_tags( $product->get_price_html() ),
				'image' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '',
			];
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Get endpoint discovery info for admin display.
	 *
	 * @return WP_REST_Response
	 */
	public function get_endpoints_info() {
		return new WP_REST_Response( [
			'llms_txt'    => home_url( '/llms.txt' ),
			'ucp'         => home_url( '/.well-known/ucp' ),
			'catalog_api' => rest_url( 'wc/v3/ai-syndication' ),
			'store_api'   => rest_url( 'wc/store/v1' ),
		] );
	}

	/**
	 * Get the JSON Schema for a bot response.
	 *
	 * @return array
	 */
	public function get_bot_schema() {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ai-syndication-bot',
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'string', 'format' => 'uuid', 'description' => 'Bot UUID.' ],
				'name'          => [ 'type' => 'string', 'description' => 'Bot display name.' ],
				'key_prefix'    => [ 'type' => 'string', 'description' => 'First 10 chars of API key for identification.' ],
				'permissions'   => [
					'type'       => 'object',
					'properties' => [
						'read_products'   => [ 'type' => 'boolean' ],
						'read_categories' => [ 'type' => 'boolean' ],
						'prepare_cart'    => [ 'type' => 'boolean' ],
						'check_inventory' => [ 'type' => 'boolean' ],
					],
					'description' => 'Bot permission flags.',
				],
				'status'        => [ 'type' => 'string', 'enum' => [ 'active', 'revoked' ], 'description' => 'Bot status.' ],
				'created_at'    => [ 'type' => 'string', 'format' => 'date-time', 'description' => 'Creation timestamp.' ],
				'last_access'   => [ 'type' => [ 'string', 'null' ], 'description' => 'Last API access timestamp.' ],
				'request_count' => [ 'type' => 'integer', 'description' => 'Total API requests made.' ],
			],
		];
	}
}
