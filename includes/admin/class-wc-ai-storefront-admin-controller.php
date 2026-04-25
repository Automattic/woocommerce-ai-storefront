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
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin REST controller for AI syndication settings.
 */
class WC_AI_Storefront_Admin_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'wc/v3/ai-storefront/admin';

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
							'enum' => [ 'all', 'by_taxonomy', 'categories', 'tags', 'brands', 'selected' ],
						],
						'selected_categories'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'selected_tags'          => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'selected_brands'        => [
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
						'return_policy'          => [
							'type'       => 'object',
							'properties' => [
								'mode'    => [
									'type' => 'string',
									'enum' => [ 'unconfigured', 'returns_accepted', 'final_sale' ],
								],
								'page_id' => [
									'type'    => 'integer',
									'minimum' => 0,
								],
								'days'    => [
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => 365,
								],
								'fees'    => [
									'type' => 'string',
									'enum' => [ 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'OriginalShippingFees', 'RestockingFees' ],
								],
								'methods' => [
									'type'  => 'array',
									'items' => [
										'type' => 'string',
										'enum' => [ 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' ],
									],
								],
							],
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

		// Recent AI-attributed orders. Feeds the Overview tab's
		// AI Orders DataViews table — one row per order with the
		// columns that match WC's native Orders list (Order, Date,
		// Status, Agent, Total). Scoped to orders with our
		// `_wc_ai_storefront_agent` meta set so we don't scan the
		// full order table; `per_page` is clamped to a sane max.
		register_rest_route(
			self::NAMESPACE,
			'/recent-orders',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_recent_orders' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'per_page' => [
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 50,
					],
				],
			]
		);

		// Syndicated product count for display surfaces (Overview tab's
		// "Products Exposed" card, Products tab's by_taxonomy row count
		// pill). Runs the same UNION query the Store API filter would
		// apply, returning a single count. Purely a display metric —
		// no per-row data crosses the wire.
		//
		// Optional query params let the caller override the merchant's
		// CURRENTLY-SAVED settings to preview a hypothetical count.
		// Used by the Products tab to show a live count for the
		// merchant's IN-PROGRESS taxonomy selection (before they save).
		// Without overrides, the endpoint reads from saved settings —
		// matching what the Store API filter actually enforces today.
		register_rest_route(
			self::NAMESPACE,
			'/product-count',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_product_count' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'mode'                => [
						'type'              => 'string',
						'enum'              => [ 'all', 'by_taxonomy', 'selected' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'selected_categories' => [
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => static fn( $v ) => array_map( 'absint', (array) $v ),
					],
					'selected_tags'       => [
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => static fn( $v ) => array_map( 'absint', (array) $v ),
					],
					'selected_brands'     => [
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => static fn( $v ) => array_map( 'absint', (array) $v ),
					],
					'selected_products'   => [
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => static fn( $v ) => array_map( 'absint', (array) $v ),
					],
				],
			]
		);

		// Product/category/tag/brand search for selection UI.
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
			'/search/tags',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_tags' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/brands',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_brands' ],
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
		return new WP_REST_Response( WC_AI_Storefront::get_settings() );
	}

	/**
	 * Update settings.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$data = [];

		$fields = [ 'enabled', 'product_selection_mode', 'selected_categories', 'selected_tags', 'selected_brands', 'selected_products', 'rate_limit_rpm', 'allowed_crawlers', 'return_policy' ];
		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		$old_settings = WC_AI_Storefront::get_settings();
		WC_AI_Storefront::update_settings( $data );

		// Schedule a rewrite rule flush when enabled state changes.
		if ( isset( $data['enabled'] ) && $data['enabled'] !== ( $old_settings['enabled'] ?? 'no' ) ) {
			set_transient( 'wc_ai_storefront_flush_rewrite', 1, HOUR_IN_SECONDS );

			// Eagerly generate and cache llms.txt + UCP manifest.
			if ( 'yes' === $data['enabled'] ) {
				$llms_txt = new WC_AI_Storefront_Llms_Txt();
				$content  = $llms_txt->generate();
				set_transient( WC_AI_Storefront_Llms_Txt::CACHE_KEY, $content, HOUR_IN_SECONDS );

				$ucp      = new WC_AI_Storefront_Ucp();
				$manifest = wp_json_encode( $ucp->generate_manifest( WC_AI_Storefront::get_settings() ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
				set_transient( WC_AI_Storefront_Ucp::CACHE_KEY, $manifest, HOUR_IN_SECONDS );
			}
		}

		return new WP_REST_Response( WC_AI_Storefront::get_settings() );
	}

	/**
	 * Get attribution stats.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_stats( $request ) {
		$period = $request->get_param( 'period' );
		$stats  = WC_AI_Storefront_Attribution::get_stats( $period );

		return new WP_REST_Response( $stats );
	}

	/**
	 * Get the count of products exposed to AI via the current scoping.
	 *
	 * Runs the same resolution as the Store API filter and
	 * `WC_AI_Storefront::is_product_syndicated()` so the Overview
	 * "Products Exposed" card reflects what agents would actually see:
	 *
	 *   - `all`          → count of published products
	 *   - `selected`     → count of published products in
	 *                      `selected_products`
	 *   - `by_taxonomy`  → UNION count across
	 *                      `selected_categories ∪ selected_tags ∪
	 *                      selected_brands`
	 *
	 * Uses `WP_Query` with `posts_per_page=1` + `no_found_rows=false`
	 * so only the found-rows count trip hits the DB — no full
	 * iteration of matching product rows.
	 *
	 * Brand-downgrade and empty-selection policies mirror the Store
	 * API filter and per-product gate: only-brands-configured on an
	 * unregistered taxonomy returns the total published count
	 * (show-all); fully-empty selection returns 0.
	 *
	 * @param WP_REST_Request|null $request Optional request. When
	 *                                      provided, query params
	 *                                      (`mode`, `selected_categories`,
	 *                                      `selected_tags`, `selected_brands`,
	 *                                      `selected_products`) override
	 *                                      the merchant's saved settings
	 *                                      for the count computation —
	 *                                      used by the Products tab's
	 *                                      by_taxonomy row to preview
	 *                                      the count for the in-progress
	 *                                      UI selection before save.
	 *                                      `null` (or omitted entirely
	 *                                      when called outside a REST
	 *                                      context) reads from saved
	 *                                      settings — used by the
	 *                                      Overview tab's "Products
	 *                                      Exposed" card.
	 * @return WP_REST_Response|WP_Error    { count: int } on success;
	 *                                      `WP_Error` if the resolved
	 *                                      `product_selection_mode` is
	 *                                      not one of the recognized
	 *                                      enum values (shouldn't happen
	 *                                      in practice — silent migration
	 *                                      + defensive legacy fallback
	 *                                      normalize stored values, and
	 *                                      param overrides go through
	 *                                      sanitize-callback enum
	 *                                      validation).
	 */
	public function get_product_count( $request = null ) {
		$settings = WC_AI_Storefront::get_settings();

		// Optional param overrides — let the caller preview a count
		// for hypothetical settings (used by the Products tab's
		// by_taxonomy row pill to reflect IN-PROGRESS UI state before
		// the merchant saves). Without overrides, the endpoint reads
		// from saved settings — what the Store API filter actually
		// enforces today (used by the Overview tab's
		// "Products Exposed" card).
		if ( null !== $request ) {
			$param_mode = $request->get_param( 'mode' );
			if ( null !== $param_mode ) {
				$settings['product_selection_mode'] = $param_mode;
			}
			foreach ( [ 'selected_categories', 'selected_tags', 'selected_brands', 'selected_products' ] as $key ) {
				$param = $request->get_param( $key );
				if ( null !== $param ) {
					$settings[ $key ] = $param;
				}
			}
		}

		$mode = $settings['product_selection_mode'] ?? 'all';

		// Legacy mode values — defensive. Silent migration in
		// get_settings() normally prevents these from reaching here.
		if ( 'categories' === $mode || 'tags' === $mode || 'brands' === $mode ) {
			$mode = 'by_taxonomy';
		}

		if ( 'all' === $mode ) {
			$counts = wp_count_posts( 'product' );
			return new WP_REST_Response(
				[ 'count' => (int) ( $counts->publish ?? 0 ) ]
			);
		}

		if ( 'selected' === $mode ) {
			$ids = array_map( 'absint', $settings['selected_products'] ?? [] );
			if ( empty( $ids ) ) {
				return new WP_REST_Response( [ 'count' => 0 ] );
			}
			// Count only published products in the allow-list — a
			// deleted or drafted product shouldn't inflate the card.
			$query = new WP_Query(
				[
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				]
			);
			return new WP_REST_Response(
				[ 'count' => (int) $query->found_posts ]
			);
		}

		if ( 'by_taxonomy' === $mode ) {
			$base_args  = [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			];
			$filter     = new WC_AI_Storefront_UCP_Store_API_Filter();
			$query_args = $filter->apply_union_restriction( $base_args, $settings );

			// Brand-downgrade: only brands configured but taxonomy missing →
			// apply_union_restriction() returns args unchanged (no tax_query /
			// post__in added). Count must match the "show all" enforcement.
			if ( ! isset( $query_args['tax_query'] ) && ! isset( $query_args['post__in'] ) ) {
				$counts = wp_count_posts( 'product' );
				return new WP_REST_Response(
					[ 'count' => (int) ( $counts->publish ?? 0 ) ]
				);
			}

			// Empty-selection: apply_union_restriction() sets post__in = [0].
			if ( isset( $query_args['post__in'] ) && [ 0 ] === $query_args['post__in'] ) {
				return new WP_REST_Response( [ 'count' => 0 ] );
			}

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$query = new WP_Query( $query_args );
			return new WP_REST_Response(
				[ 'count' => (int) $query->found_posts ]
			);
		}

		// Unknown mode — shouldn't happen after silent migration +
		// the defensive fallback above, but return a `WP_Error`
		// rather than a silent `count: 0` so a future enum addition
		// that forgets to update this method fails loudly instead
		// of serving a misleading zero.
		return new WP_Error(
			'wc_ai_storefront_unknown_product_selection_mode',
			sprintf(
				/* translators: %s: the unrecognized product_selection_mode enum value */
				__( 'Unrecognized product_selection_mode: %s', 'woocommerce-ai-storefront' ),
				esc_html( (string) $mode )
			),
			[ 'status' => 500 ]
		);
	}

	/**
	 * Get recent AI-attributed orders for the Overview tab table.
	 *
	 * Returns a normalized row shape that matches what the frontend
	 * DataViews table renders — no display logic on the client besides
	 * status-pill coloring + currency formatting. Specifically:
	 *
	 *   - `agent` is already canonicalized through KNOWN_AGENT_HOSTS,
	 *     so legacy orders captured with the raw hostname
	 *     (`gemini.google.com`) come back as the brand name
	 *     (`Gemini`). Non-destructive: the database meta stays
	 *     untouched; the canonicalization is display-only.
	 *   - `status` is the machine status (`processing`, `completed`,
	 *     etc.) — the frontend maps it to a colored pill. `status_label`
	 *     is the localized display text (`Processing`, `Completed`).
	 *   - `edit_url` is HPOS-aware: admin.php?page=wc-orders on
	 *     HPOS stores, post.php otherwise.
	 *   - `total` is the raw numeric string; the client formats with
	 *     Intl.NumberFormat so locale handling matches the rest of
	 *     the admin UI.
	 *
	 * Query scope: orders that have our AGENT_META_KEY set. That
	 * bounds the scan — we never touch orders that aren't
	 * AI-attributed. wc_get_orders() hides the HPOS/legacy split.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_recent_orders( $request ) {
		$per_page = (int) $request->get_param( 'per_page' );

		$orders = wc_get_orders(
			[
				'limit'    => $per_page,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'meta_key' => WC_AI_Storefront_Attribution::AGENT_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'status'   => array_keys( wc_get_order_statuses() ),
				'return'   => 'objects',
			]
		);

		$statuses = wc_get_order_statuses();
		$rows     = [];

		foreach ( $orders as $order ) {
			$raw_agent = (string) $order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY );
			$agent     = '' !== $raw_agent
				? WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( $raw_agent )
				: '';

			$date_created = $order->get_date_created();
			$status_key   = 'wc-' . $order->get_status();

			$rows[] = [
				'id'           => $order->get_id(),
				'number'       => $order->get_order_number(),
				'date'         => $date_created ? $date_created->format( 'c' ) : '',
				'date_display' => $date_created ? wc_format_datetime( $date_created ) : '',
				'status'       => $order->get_status(),
				'status_label' => $statuses[ $status_key ] ?? ucfirst( $order->get_status() ),
				'agent'        => $agent,
				'total'        => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
				'edit_url'     => $order->get_edit_order_url(),
			];
		}

		return new WP_REST_Response(
			[
				'orders'   => $rows,
				'total'    => count( $rows ),
				'currency' => get_woocommerce_currency(),
			]
		);
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
	 * Search tags for the selection UI.
	 *
	 * Returns all `product_tag` terms. Unlike products (which need
	 * search + pagination due to potentially thousands of entries),
	 * tags are typically small enough to return in one payload. If a
	 * store has an unusually large tag vocabulary the client falls
	 * back to in-memory filter on the full list — same pattern as
	 * categories.
	 *
	 * @return WP_REST_Response
	 */
	public function search_tags() {
		return self::fetch_flat_taxonomy_terms( 'product_tag' );
	}

	/**
	 * Search brands for the selection UI.
	 *
	 * `product_brand` is a native WooCommerce taxonomy introduced in
	 * WC 9.5. On older versions (or any environment that unregisters
	 * it) we return an empty array — the admin UI gates the Brands
	 * segment on the `supportsBrands` bootstrap flag and won't call
	 * this endpoint, but the guard is here for defense in depth.
	 *
	 * @return WP_REST_Response
	 */
	public function search_brands() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return new WP_REST_Response( [] );
		}
		return self::fetch_flat_taxonomy_terms( 'product_brand' );
	}

	/**
	 * Shared callback body for flat-taxonomy search endpoints.
	 *
	 * Tags + brands are flat taxonomies (no parent/child hierarchy);
	 * their search endpoints differ only by taxonomy slug + the
	 * `parent` field categories need for tree display. Extracting
	 * this helper keeps the `{ id, name, slug, count }` response
	 * contract in one place so the two endpoints can't drift.
	 *
	 * `search_categories()` is intentionally NOT refactored through
	 * this helper — categories carry an additional `parent` field
	 * the frontend uses for tree rendering, and forcing that field
	 * through the flat helper would either bloat the tags/brands
	 * payload with a useless always-zero key or introduce a flag
	 * that confuses the shared code path.
	 *
	 * @param string $taxonomy WP taxonomy slug (e.g. 'product_tag').
	 * @return WP_REST_Response
	 */
	private static function fetch_flat_taxonomy_terms( string $taxonomy ): WP_REST_Response {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( [] );
		}

		$data = [];
		foreach ( $terms as $term ) {
			$data[] = [
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
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
				'llms_txt' => home_url( '/llms.txt' ),
				'ucp'      => home_url( '/.well-known/ucp' ),
				// UCP API: the structured commerce surface AI agents
				// actually call (catalog search, lookup, checkout
				// sessions). Replaced the prior `store_api` row in
				// the Discovery tab — Store API is the underlying
				// transport our UCP wrapper dispatches through, but
				// it's not the AI commerce surface. Naming the row
				// "Store API" forced merchants to reason about an
				// implementation layer that has nothing to do with
				// what AI agents see.
				'ucp_api'  => rest_url( 'wc/ucp/v1' ),
				// robots.txt is always reachable (WordPress serves it
				// unconditionally), but our plugin appends the AI-crawler
				// allow-list + Allow directives when syndication is
				// enabled. Surfacing it here gives merchants a direct
				// view of what the plugin publishes to bots.
				'robots'   => home_url( '/robots.txt' ),
			]
		);
	}
}
