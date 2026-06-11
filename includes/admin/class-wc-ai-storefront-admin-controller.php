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
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'enabled'                  => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						'product_selection_mode'   => array(
							'type' => 'string',
							'enum' => array( 'all', 'by_taxonomy', 'categories', 'tags', 'brands', 'selected' ),
						),
						'selected_categories'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'selected_tags'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'selected_brands'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'selected_products'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'rate_limit_rpm'           => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 1000,
						),
						'allowed_crawlers'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						// UCP REST gate for unknown-host AI agents.
						// Strict enum here so REST 400s on a malformed
						// value before the sanitizer runs — there's
						// only two valid states for a yes/no toggle and
						// no normalization to do.
						'allow_unknown_ucp_agents' => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						// MCP transport toggle. Yes/no enum mirroring
						// `allow_unknown_ucp_agents`. Sanitization lives in
						// `WC_AI_Storefront::update_settings()`.
						'mcp_enabled'              => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						// Return policy schema is intentionally type-only:
						// no `enum`, no `minimum/maximum`. The canonical
						// validation/normalization rules live in
						// `WC_AI_Storefront_Return_Policy::sanitize()`,
						// which accepts unknown values and normalizes
						// them to safe defaults rather than rejecting.
						// If we declared `enum` here, WP REST would 400
						// out-of-enum values BEFORE the sanitizer ran —
						// that contradicts the sanitizer's "accept then
						// normalize" contract and would surprise
						// integration tests that exercise the full
						// REST flow. Type checking still catches gross
						// shape errors (string where integer expected,
						// etc.) at the boundary.
						'return_policy'            => array(
							'type'       => 'object',
							'properties' => array(
								'mode'    => array(
									'type' => 'string',
								),
								'page_id' => array(
									'type' => 'integer',
								),
								// `days` accepts integer OR null (the
								// "no window configured" sentinel
								// returned by the sanitizer). Without
								// `'null'` in the type list, sending
								// `days: null` would 400 even though
								// it's a canonical sanitizer output.
								'days'    => array(
									'type' => array( 'integer', 'null' ),
								),
								'fees'    => array(
									'type' => 'string',
								),
								'methods' => array(
									'type'  => 'array',
									'items' => array(
										'type' => 'string',
									),
								),
							),
						),
						// Handling time: min/max business days. Both 0 = not
						// configured (emitter skips handlingTime block).
						'handling_time'            => array(
							'type'       => 'object',
							'properties' => array(
								'min' => array(
									'type' => 'integer',
								),
								'max' => array(
									'type' => 'integer',
								),
							),
						),
					),
				),
			)
		);

		// Attribution stats.
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'default' => 'month',
						'enum'    => array( 'day', 'week', 'month', 'quarter', 'year' ),
					),
				),
			)
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
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recent_orders' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'orderby'  => array(
						'type'    => 'string',
						'default' => 'date',
						'enum'    => array( 'date', 'total', 'status', 'id' ),
					),
					'order'    => array(
						'type'    => 'string',
						'default' => 'DESC',
						'enum'    => array( 'ASC', 'DESC' ),
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'agent'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
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
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product_count' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'mode'                => array(
						'type'              => 'string',
						'enum'              => array( 'all', 'by_taxonomy', 'selected' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'selected_categories' => array(
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					),
					'selected_tags'       => array(
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					),
					'selected_brands'     => array(
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					),
					'selected_products'   => array(
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					),
				),
			)
		);

		// Pages suitable for linking from the Policies tab — excludes
		// WC system pages (Cart, Checkout, My Account, Shop) which are
		// never the merchant's policy page. Privacy / Terms / Refund
		// pages are kept, since merchants may legitimately link them.
		// Returns the same shape `/wp/v2/pages` does (id, title, link)
		// for drop-in replacement at the JS call site.
		register_rest_route(
			self::NAMESPACE,
			'/policy-pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_policy_pages' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Product/category/tag/brand search for selection UI.
		register_rest_route(
			self::NAMESPACE,
			'/search/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_categories' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_tags' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/brands',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_brands' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_products' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'search'   => array( 'type' => 'string' ),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			)
		);

		// Discovery endpoint URLs.
		register_rest_route(
			self::NAMESPACE,
			'/endpoints',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_endpoints_info' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Crawler-visibility stats for the Discovery tab.
		// Aggregated from the daily summary table (wc_ai_storefront_crawl_summary).
		// Data reflects activity up to the end of yesterday — today's events
		// are in the raw log and roll into the summary on the nightly cron.
		register_rest_route(
			self::NAMESPACE,
			'/crawl-stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_crawl_stats' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'default' => 'month',
						'enum'    => array( 'day', 'week', 'month', 'quarter' ),
					),
				),
			)
		);
	}

	/**
	 * Sanitize an ID array input — cast each element to a non-negative integer.
	 *
	 * @param mixed $value Raw input, expected to be an array of IDs.
	 * @return array<int> Array of absint-sanitized IDs.
	 */
	public static function sanitize_id_array( $value ): array {
		return array_map( 'absint', (array) $value );
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
		$data = array();

		$fields = array( 'enabled', 'product_selection_mode', 'selected_categories', 'selected_tags', 'selected_brands', 'selected_products', 'rate_limit_rpm', 'allowed_crawlers', 'allow_unknown_ucp_agents', 'mcp_enabled', 'return_policy', 'handling_time' );
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
				set_transient( WC_AI_Storefront_Llms_Txt::host_cache_key(), $content, HOUR_IN_SECONDS );

				$ucp = new WC_AI_Storefront_Ucp();
				// HEX-escape flag set prevents script-tag breakout and adjacent
				// injection vectors. See serve_manifest() for the full rationale.
				$manifest = wp_json_encode( $ucp->generate_manifest( WC_AI_Storefront::get_settings() ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
				// Legacy key — retained here for clean uninstall of pre-1.0 installs.
				set_transient( 'wc_ai_storefront_ucp', $manifest, HOUR_IN_SECONDS );
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
			foreach ( array( 'selected_categories', 'selected_tags', 'selected_brands', 'selected_products' ) as $key ) {
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
				array( 'count' => (int) ( $counts->publish ?? 0 ) )
			);
		}

		if ( 'selected' === $mode ) {
			$ids = array_map( 'absint', $settings['selected_products'] ?? array() );
			if ( empty( $ids ) ) {
				return new WP_REST_Response( array( 'count' => 0 ) );
			}
			// Count only published products in the allow-list — a
			// deleted or drafted product shouldn't inflate the card.
			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);
			return new WP_REST_Response(
				array( 'count' => (int) $query->found_posts )
			);
		}

		if ( 'by_taxonomy' === $mode ) {
			$base_args  = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			);
			$filter     = new WC_AI_Storefront_UCP_Store_API_Filter();
			$query_args = $filter->apply_union_restriction( $base_args, $settings );

			// Brand-downgrade: only brands configured but taxonomy missing →
			// apply_union_restriction() returns args unchanged (no tax_query /
			// post__in added). Count must match the "show all" enforcement.
			if ( ! isset( $query_args['tax_query'] ) && ! isset( $query_args['post__in'] ) ) {
				$counts = wp_count_posts( 'product' );
				return new WP_REST_Response(
					array( 'count' => (int) ( $counts->publish ?? 0 ) )
				);
			}

			// Empty-selection: apply_union_restriction() sets post__in = [0].
			if ( isset( $query_args['post__in'] ) && array( 0 ) === $query_args['post__in'] ) {
				return new WP_REST_Response( array( 'count' => 0 ) );
			}

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$query = new WP_Query( $query_args );
			return new WP_REST_Response(
				array( 'count' => (int) $query->found_posts )
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
			array( 'status' => 500 )
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
		$per_page      = (int) $request->get_param( 'per_page' );
		$page          = max( 1, (int) $request->get_param( 'page' ) );
		$orderby_raw   = sanitize_key( $request->get_param( 'orderby' ) );
		$order_dir     = strtoupper( sanitize_key( $request->get_param( 'order' ) ) ) === 'ASC' ? 'ASC' : 'DESC';
		$search        = sanitize_text_field( $request->get_param( 'search' ) );
		$agent_filter  = sanitize_text_field( $request->get_param( 'agent' ) );
		$status_filter = sanitize_text_field( $request->get_param( 'status' ) );

		// Map DataViews field IDs to wc_get_orders orderby keys.
		$orderby_map = array(
			'date'   => 'date',
			'total'  => 'total',
			'status' => 'status',
			'id'     => 'ID',
		);
		$orderby     = $orderby_map[ $orderby_raw ] ?? 'date';

		// Restrict to commercially relevant statuses only, not all 12+ WC order
		// statuses. Passing every status via `wc_get_order_statuses()` forces the
		// DB to scan rows with statuses like `wc-cancelled`, `wc-failed`, and
		// `wc-trash` that never appear in the AI-orders overview table and inflate
		// the IN clause with no benefit. On stores with 100k+ orders the
		// over-broad IN can prevent index use (P-16).
		$allowed_statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'refunded' );
		$status_arg       = $status_filter && in_array( $status_filter, $allowed_statuses, true )
			? array( $status_filter )
			: $allowed_statuses;

		// Restrict to AI-attributed orders via meta_query existence check.
		$meta_query = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => WC_AI_Storefront_Attribution::AGENT_META_KEY,
				'compare' => 'EXISTS',
			),
		);

		if ( $agent_filter ) {
			$meta_query[] = array(
				'key'     => WC_AI_Storefront_Attribution::AGENT_META_KEY,
				'value'   => $agent_filter,
				'compare' => '=',
			);
		}

		$query_args = array(
			'limit'      => $per_page,
			'paged'      => $page,
			'orderby'    => $orderby,
			'order'      => $order_dir,
			'status'     => $status_arg,
			'return'     => 'objects',
			'meta_query' => $meta_query,
		);

		if ( $search ) {
			$query_args['s'] = $search;
		}

		$orders = wc_get_orders( $query_args );

		// DB error — return an empty result set so the admin UI shows
		// "no orders" rather than a fatal.
		if ( is_wp_error( $orders ) ) {
			return new WP_REST_Response(
				array(
					'orders'   => array(),
					'total'    => 0,
					'currency' => get_woocommerce_currency(),
				)
			);
		}

		// Get the true total for pagination via a direct COUNT(DISTINCT id) — far cheaper
		// than fetching all matching IDs with wc_get_orders( limit=-1 ).
		$total_orders = $this->count_ai_orders( $status_arg, $agent_filter, $search );

		$statuses = wc_get_order_statuses();
		$rows     = array();

		foreach ( $orders as $order ) {
			$raw_agent = (string) $order->get_meta( WC_AI_Storefront_Attribution::AGENT_META_KEY );
			// Use the idempotent variant: post-1.6.7 orders stamp the
			// canonical brand name (e.g. "Gemini") directly into the
			// meta, while pre-1.6.7 orders carry the raw hostname
			// (e.g. "gemini.google.com"). Plain `canonicalize_host()`
			// would treat the canonical "Gemini" string as an unknown
			// hostname and bucket it as "Other AI" — see the helper's
			// docblock for the trap and rationale.
			$agent = '' !== $raw_agent
				? WC_AI_Storefront_UCP_Agent_Header::canonicalize_host_idempotent( $raw_agent )
				: '';

			$date_created = $order->get_date_created();
			$status_key   = 'wc-' . $order->get_status();

			$first_name   = $order->get_billing_first_name();
			$last_name    = $order->get_billing_last_name();
			$customer     = trim( "$first_name $last_name" );
			$customer_id  = $order->get_customer_id();
			$customer_url = $customer_id
				? add_query_arg(
					array(
						'page'           => 'wc-orders',
						'_customer_user' => $customer_id,
						'status'         => 'all',
					),
					admin_url( 'admin.php' )
				)
				: '';

			$item_names = array_map(
				fn( $item ) => $item->get_name(),
				array_values( $order->get_items() )
			);

			$rows[] = array(
				'id'           => $order->get_id(),
				'number'       => $order->get_order_number(),
				'customer'     => $customer,
				'customer_url' => $customer_url,
				'items'        => $item_names,
				'date'         => $date_created ? $date_created->format( 'c' ) : '',
				'date_display' => $date_created
				? sprintf(
				/* translators: %s: human-readable time difference, e.g. "5 minutes" */
					__( '%s ago', 'woocommerce-ai-storefront' ),
					human_time_diff( $date_created->getTimestamp(), time() )
				)
				: '',
				'status'       => $order->get_status(),
				'status_label' => $statuses[ $status_key ] ?? ucfirst( $order->get_status() ),
				'agent'        => $agent,
				'total'        => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
				'edit_url'     => $order->get_edit_order_url(),
			);
		}

		return new WP_REST_Response(
			array(
				'orders'   => $rows,
				'total'    => $total_orders,
				'currency' => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Count AI-attributed orders matching the given filters via a direct
	 * COUNT(DISTINCT id) query — HPOS or legacy, whichever the store uses.
	 *
	 * This is the efficient counterpart to the main `wc_get_orders()` call
	 * in `get_recent_orders()`. Fetching all matching IDs with
	 * `wc_get_orders( limit=-1, return='ids' )` loads potentially thousands
	 * of integers into PHP memory just to call `count()` on the array. A
	 * single `SELECT COUNT(DISTINCT id)` returns one integer from the DB
	 * and costs almost nothing at any order volume. `DISTINCT` is required
	 * because the INNER JOIN on the meta table can produce multiple rows
	 * per order when an order has more than one matching meta row.
	 *
	 * The method mirrors the same WHERE conditions as the main query so
	 * the count is always in sync with the result set:
	 *   - AGENT_META_KEY must exist (inner join guarantees it)
	 *   - status IN ( $status_arg ) — prefixed with `wc-` for DB storage
	 *   - optional agent value equality filter (passed directly from caller)
	 *   - optional search via LIKE on billing name + email, or order ID
	 *
	 * HPOS path:   `wc_orders` JOIN `wc_orders_meta` JOIN `wc_order_addresses`
	 * Legacy path: `wp_posts`  JOIN `wp_postmeta` (correlated EXISTS per field)
	 *
	 * @param array  $status_arg   Bare status slugs (e.g. ['processing','completed']).
	 * @param string $agent_filter Optional agent equality filter value ('' = all agents).
	 * @param string $search       Optional search term.
	 * @return int Total count of matching orders.
	 */
	private function count_ai_orders( array $status_arg, string $agent_filter, string $search ): int {
		global $wpdb;

		// Prefix bare status slugs for DB storage (both HPOS and legacy store
		// order statuses as `wc-{slug}` — e.g. `wc-processing`, `wc-on-hold`).
		$db_statuses = array_map( fn( $s ) => 'wc-' . $s, $status_arg );

		// Build a safe IN-clause placeholder string (one %s per status).
		$status_placeholders = implode( ', ', array_fill( 0, count( $db_statuses ), '%s' ) );

		$agent_key = WC_AI_Storefront_Attribution::AGENT_META_KEY;

		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {

			// HPOS path — wc_orders + wc_orders_meta + wc_order_addresses.
			// Table names are derived from $wpdb->prefix (admin-controlled, not
			// user input) and hard-coded WC HPOS suffixes. Interpolation is the
			// canonical WordPress pattern — $wpdb->prepare() cannot parameterize
			// table names.
			$orders_table    = $wpdb->prefix . 'wc_orders';
			$meta_table      = $wpdb->prefix . 'wc_orders_meta';
			$addresses_table = $wpdb->prefix . 'wc_order_addresses';

			$sql    = "SELECT COUNT(DISTINCT o.id)
			           FROM {$orders_table} o
			           INNER JOIN {$meta_table} m
			               ON o.id = m.order_id AND m.meta_key = %s
			           WHERE o.status IN ( {$status_placeholders} )";
			$params = array_merge( array( $agent_key ), $db_statuses );

			if ( $agent_filter ) {
				$sql     .= ' AND m.meta_value = %s';
				$params[] = $agent_filter;
			}

			if ( $search ) {
				// billing_first_name and billing_last_name live in wc_order_addresses
				// (not on wc_orders directly), so this query checks them via correlated
				// EXISTS subqueries filtered to address_type='billing'. billing_email
				// IS a direct column on wc_orders, so no address-table join is needed
				// for that field.
				$sql     .= " AND (
				    EXISTS (
				        SELECT 1 FROM {$addresses_table} a_fn
				        WHERE a_fn.order_id = o.id
				          AND a_fn.address_type = 'billing'
				          AND a_fn.first_name LIKE %s
				    )
				    OR EXISTS (
				        SELECT 1 FROM {$addresses_table} a_ln
				        WHERE a_ln.order_id = o.id
				          AND a_ln.address_type = 'billing'
				          AND a_ln.last_name LIKE %s
				    )
				    OR o.billing_email LIKE %s";
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				if ( ctype_digit( $search ) ) {
					$sql     .= ' OR o.id = %d';
					$params[] = (int) $search;
				}
				$sql .= ')';
			}
		} else {
			// Legacy path — wp_posts + wp_postmeta.
			$sql    = "SELECT COUNT(DISTINCT p.ID)
			           FROM {$wpdb->posts} p
			           INNER JOIN {$wpdb->postmeta} pm
			               ON p.ID = pm.post_id AND pm.meta_key = %s
			           WHERE p.post_type = 'shop_order'
			             AND p.post_status IN ( {$status_placeholders} )";
			$params = array_merge( array( $agent_key ), $db_statuses );

			if ( $agent_filter ) {
				$sql     .= ' AND pm.meta_value = %s';
				$params[] = $agent_filter;
			}

			if ( $search ) {
				// Billing name/email live in wp_postmeta. Use correlated EXISTS
				// subqueries so that orders missing one meta field still match
				// via the other fields (unlike a JOIN which would drop the row).
				$sql     .= " AND (
				    EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta} pm_fn
				        WHERE pm_fn.post_id = p.ID
				          AND pm_fn.meta_key = '_billing_first_name'
				          AND pm_fn.meta_value LIKE %s
				    )
				    OR EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta} pm_ln
				        WHERE pm_ln.post_id = p.ID
				          AND pm_ln.meta_key = '_billing_last_name'
				          AND pm_ln.meta_value LIKE %s
				    )
				    OR EXISTS (
				        SELECT 1 FROM {$wpdb->postmeta} pm_em
				        WHERE pm_em.post_id = p.ID
				          AND pm_em.meta_key = '_billing_email'
				          AND pm_em.meta_value LIKE %s
				    )";
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				if ( ctype_digit( $search ) ) {
					$sql     .= ' OR p.ID = %d';
					$params[] = (int) $search;
				}
				$sql .= ')';
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable

		if ( $wpdb->last_error ) {
			wc_get_logger()->warning(
				'count_ai_orders DB error: ' . $wpdb->last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
		}

		return (int) $count;
	}

	/**
	 * Pages suitable for linking from the Policies tab.
	 *
	 * Returns published pages MINUS WC's system pages (Cart,
	 * Checkout, My Account, Shop) which are never the merchant's
	 * policy page. WC core's `wc_get_page_id()` is the canonical way
	 * to identify these — it tracks the actual page IDs from WC's
	 * settings, so it correctly excludes the merchant's renamed-or-
	 * customised system pages, not just slug-matches against the
	 * defaults.
	 *
	 * Privacy-policy / terms / refund-explainer pages are kept in
	 * the list because merchants may legitimately link to them as
	 * their return policy; the filter is narrow on purpose.
	 *
	 * Response shape mirrors `/wp/v2/pages` (id, title, link) so the
	 * JS call site is a drop-in replacement.
	 *
	 * @return WP_REST_Response|WP_Error WP_Error returned (status 500)
	 *                                   when `get_pages()` fails so the
	 *                                   JS pagesError state lights up
	 *                                   instead of silently rendering
	 *                                   an empty dropdown.
	 */
	public function get_policy_pages() {
		// `wc_get_page_id()` is always available here — this controller
		// only loads when WooCommerce is active (the plugin's
		// `Requires Plugins: woocommerce` header + runtime
		// `class_exists('WooCommerce')` gate). No `function_exists`
		// guard needed at this layer.
		$excluded = array();
		foreach ( array( 'cart', 'checkout', 'myaccount', 'shop' ) as $slug ) {
			$page_id = (int) wc_get_page_id( $slug );
			// `wc_get_page_id()` returns -1 for unconfigured pages; the
			// `> 0` test correctly excludes -1 from the exclude list.
			if ( $page_id > 0 ) {
				$excluded[] = $page_id;
			}
		}

		// Slug-based fallback exclusion. Catches the case where the
		// merchant has a page with a default WC system slug
		// (`cart`, `checkout`, `my-account`, `shop`) that isn't the
		// configured-page ID `wc_get_page_id()` returns — for example
		// stores where WC's Page setup never completed (every
		// `wc_get_page_id()` returns -1) but the system pages were
		// auto-created during install, or stores with duplicates of
		// the system pages. A page whose slug is literally `cart` is
		// almost certainly not the merchant's refund-policy page.
		// Both `my-account` (the WP-hyphenated default WC slug) and
		// `myaccount` (the legacy unhyphenated form) are checked.
		// Batch with get_posts() — 5 serial get_page_by_path() calls
		// were replaced by one query (P-15).
		$system_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'post_name__in'  => array( 'cart', 'checkout', 'my-account', 'myaccount', 'shop' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);
		foreach ( $system_pages as $system_page_id ) {
			$excluded[] = (int) $system_page_id;
		}

		$excluded = array_values( array_unique( $excluded ) );

		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
				// 200 is intentional: WP-default get_pages() pagination
				// would otherwise return everything, and merchants with
				// 1000+ pages would see a slow dropdown render. 200 is
				// generous for a policy-link picker (typical Woo store
				// has 5-30 pages); the bounded result avoids surprises.
				'number'      => 200,
				'exclude'     => $excluded,
			)
		);

		// `get_pages()` returns false on DB error, an array on success
		// (possibly empty). Distinguishing the two matters: an empty
		// array is "no policy-eligible pages exist" (legitimate fresh
		// store) and we return []; false is a real DB failure that
		// should surface as a 500 so the JS pagesError state lights up
		// rather than render an empty dropdown that looks identical to
		// the legitimate-empty case. Without this distinction, a
		// merchant reporting "my policies dropdown is empty" has no
		// traceable signal to debug.
		if ( false === $pages ) {
			return new WP_Error(
				'wc_ai_storefront_pages_query_failed',
				__( 'Could not load pages.', 'woocommerce-ai-storefront' ),
				array( 'status' => 500 )
			);
		}

		$result = array();
		foreach ( $pages as $page ) {
			// Run the title through `the_title` filter to match the
			// `/wp/v2/pages` REST endpoint's output shape: it filters
			// the title (entity decoding, shortcode stripping, plugin
			// hooks like Yoast's title-tweaking). The JS does
			// `decodeEntities()` on the result, so we pre-render here
			// for parity. Raw `$page->post_title` would diverge from
			// what `/wp/v2/pages` returns under `title.rendered` and
			// surface in the dropdown as the literal pre-filter
			// string (e.g., shortcodes unexpanded, entities double-
			// encoded after the JS-side decode).
			$result[] = array(
				'id'    => (int) $page->ID,
				'title' => array(
					// wp_strip_all_tags() prevents a third-party plugin that injects
					// unescaped HTML into `the_title` from surfacing raw markup in the
					// admin REST response (FIND-S05). The dropdown only needs plain text.
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally re-invoking WP core's `the_title` filter to mirror the `/wp/v2/pages` REST endpoint's `title.rendered` field shape (entity decoding, shortcode stripping, third-party title-tweaking plugins). The drop-in-replacement contract requires identical filtering, not a plugin-prefixed parallel hook.
					'rendered' => wp_strip_all_tags( (string) apply_filters( 'the_title', $page->post_title, $page->ID ) ),
				),
				'link'  => get_permalink( $page->ID ),
			);
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Search categories for the selection UI.
	 *
	 * @return WP_REST_Response
	 */
	public function search_categories() {
		// The admin selection UI does client-side filtering on the full
		// list returned here; 500 covers all realistic stores. If more
		// are needed, the merchant can use search-as-you-type (which
		// already has its own pagination).
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 500,
			)
		);

		if ( is_wp_error( $categories ) ) {
			return new WP_REST_Response( array() );
		}

		$data = array();
		foreach ( $categories as $category ) {
			$data[] = array(
				'id'     => $category->term_id,
				'name'   => $category->name,
				'slug'   => $category->slug,
				'count'  => $category->count,
				'parent' => $category->parent,
			);
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
			return new WP_REST_Response( array() );
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
		// The admin selection UI does client-side filtering on the full
		// list returned here; 500 covers all realistic stores. If more
		// are needed, the merchant can use search-as-you-type (which
		// already has its own pagination).
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 500,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( array() );
		}

		$data = array();
		foreach ( $terms as $term ) {
			$data[] = array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			);
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

		$args = array(
			'status' => 'publish',
			'limit'  => $per_page,
			'type'   => array( 'simple', 'variable' ),
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		$products = wc_get_products( $args );
		$data     = array();

		foreach ( $products as $product ) {
			// `wp_get_attachment_image_url` returns false for products with
			// no image; normalize to empty string for JSON consumers.
			$image_url = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
			$data[]    = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'sku'   => $product->get_sku(),
				'price' => wp_strip_all_tags( $product->get_price_html() ),
				'image' => $image_url ? $image_url : '',
			);
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Get crawler-visibility stats for the Discovery tab.
	 *
	 * Reads from the summary table `wc_ai_storefront_crawl_summary` for
	 * aggregate counts and directly from the raw log for top_queries.
	 * The summary is refreshed on every rollup run (hourly by default).
	 *
	 * Returned shape:
	 *   period                 — echoed back for the client's cache key.
	 *   total_requests         — SUM(request_count) across all REQUEST endpoints.
	 *                            Excludes ENDPOINT_STORE_API_SEARCH_HIT impression rows.
	 *   unique_products        — COUNT(DISTINCT product_id) where product_id > 0.
	 *                            Includes products surfaced via catalog/search results
	 *                            (recorded under ENDPOINT_STORE_API_SEARCH_HIT) AND products
	 *                            inspected via catalog/lookup. Reflects "what products has
	 *                            an AI seen" rather than just "what products did an AI click."
	 *   store_api_queries      — requests to store_api_product + store_api_search.
	 *                            (search_hit impression rows are not counted as queries.)
	 *   throttle_count         — SUM(throttle_count) across all REQUEST endpoints (excludes
	 *                            search_hit, which can never be throttled — it's a side-effect
	 *                            of the parent search request).
	 *   throttle_rate          — throttle_count / total_requests × 100 (0 when no data).
	 *   by_agent               — top-10 agents by request count: [{agent, requests}].
	 *   top_queries            — top-10 search queries from the raw log: [{query, count, agents}].
	 *   top_queries_window_days — effective lookback for top_queries (min(period_days, 30)).
	 *   raw_event_count        — total raw-log events in the period (COUNT(*) on TABLE_LOG).
	 *                            Queried live on every response (not cached) so new traffic
	 *                            arriving between rollups suppresses the "no activity" empty
	 *                            state immediately, without waiting for the 5-min transient
	 *                            to expire.
	 *   rollup_interval        — the validated cron recurrence slug ('hourly', 'twicedaily',
	 *                            or 'daily'). Injected live on every response (not cached)
	 *                            so a filter change takes effect immediately without waiting
	 *                            for the transient to expire.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_crawl_stats( $request ) {
		global $wpdb;

		$period        = $request->get_param( 'period' );
		$valid_periods = array( 'day', 'week', 'month', 'quarter' );
		$period        = in_array( $period, $valid_periods, true ) ? $period : 'month';

		$days_map = array(
			'day'     => 1,
			'week'    => 7,
			'month'   => 30,
			'quarter' => 90,
		);

		// Anchor to today's midnight so the window spans exactly N calendar
		// dates (today inclusive). Using time() - N*86400 would floor to a
		// mid-day timestamp, making the window N+1 calendar dates wide once
		// today's rows are included via the open-ended upper bound.
		// Computed before the cache check so the live raw_event_count query
		// (below) can run on the cache-hit path too.
		$today_midnight = gmmktime( 0, 0, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
		$after_date     = gmdate( 'Y-m-d', $today_midnight - ( $days_map[ $period ] - 1 ) * DAY_IN_SECONDS );
		$after_datetime = $after_date . ' 00:00:00';

		$log_table = $wpdb->prefix . WC_AI_Storefront_Crawl_Logger::TABLE_LOG;

		// raw_event_count must be live on every response — its purpose is to
		// detect new raw-log traffic since the last rollup, so caching it for
		// up to 5 minutes inside the transient would defeat the empty-state
		// suppression it powers. Single COUNT(*) on an indexed column.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw_event_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_table} WHERE crawled_at >= %s",
				$after_datetime
			)
		);
		// phpcs:enable

		$cached = get_transient( 'wc_ai_storefront_crawl_stats_' . $period );
		if ( false !== $cached && is_array( $cached ) ) {
			// Filter-/state-dependent fields are injected live on every response
			// so changes take effect without waiting for the transient to expire.
			$cached['rollup_interval'] = WC_AI_Storefront_Crawl_Logger::get_effective_rollup_interval();
			$cached['raw_event_count'] = $raw_event_count;
			return new WP_REST_Response( $cached );
		}

		// Top searches read from the raw log (query strings aren't aggregated
		// into the summary table). The raw log is pruned at RAW_RETENTION_DAYS,
		// so `quarter` (90d) can only return at most 30d of search data. Clamp
		// the lower bound so the timestamp passed to the query reflects what
		// the table actually contains, and surface the effective window in the
		// response so the UI can label it accurately.
		$top_queries_days  = min(
			$days_map[ $period ],
			WC_AI_Storefront_Crawl_Logger::RAW_RETENTION_DAYS
		);
		$top_queries_after = gmdate( 'Y-m-d', $today_midnight - ( $top_queries_days - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';

		$table = $wpdb->prefix . WC_AI_Storefront_Crawl_Logger::TABLE_SUMMARY;

		// Per-endpoint aggregates — single query, aggregated in PHP below.
		// Excludes ENDPOINT_STORE_API_SEARCH_HIT because those rows are
		// per-result impressions emitted alongside each catalog/search
		// request, not requests themselves; counting them here would
		// inflate Catalog queries / total_requests / by-agent totals
		// by N (number of products returned) per search. The impressions
		// are still in the summary table and are picked up by the
		// `unique_products` query below via product_id > 0.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$endpoint_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT endpoint,
				        SUM(request_count)  AS requests,
				        SUM(throttle_count) AS throttles
				 FROM {$table}
				 WHERE crawl_date >= %s
				   AND endpoint != %s
				 GROUP BY endpoint",
				$after_date,
				WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH_HIT
			)
		);
		$last_error    = $wpdb->last_error;
		if ( $last_error ) {
			wc_get_logger()->warning(
				'get_crawl_stats endpoint_rows DB error: ' . $last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
			return new WP_Error( 'db_error', __( 'Could not load crawler stats.', 'woocommerce-ai-storefront' ), array( 'status' => 500 ) );
		}

		// Unique products seen across all endpoints in the period.
		$unique_products = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT product_id)
				 FROM {$table}
				 WHERE product_id > 0 AND crawl_date >= %s",
				$after_date
			)
		);
		$last_error      = $wpdb->last_error;
		if ( $last_error ) {
			wc_get_logger()->warning(
				'get_crawl_stats unique_products DB error: ' . $last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
			return new WP_Error( 'db_error', __( 'Could not load crawler stats.', 'woocommerce-ai-storefront' ), array( 'status' => 500 ) );
		}

		// Top-10 agents by request count. Excludes ENDPOINT_STORE_API_SEARCH_HIT
		// for the same reason as the by-endpoint query above — those rows
		// are per-result impressions, not requests, and would otherwise
		// dominate the by-agent chart for any agent that ran a few searches.
		$agent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent, SUM(request_count) AS requests
				 FROM {$table}
				 WHERE crawl_date >= %s
				   AND endpoint != %s
				 GROUP BY agent
				 ORDER BY requests DESC
				 LIMIT 10",
				$after_date,
				WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH_HIT
			)
		);
		$last_error = $wpdb->last_error;
		if ( $last_error ) {
			wc_get_logger()->warning(
				'get_crawl_stats agent_rows DB error: ' . $last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
			return new WP_Error( 'db_error', __( 'Could not load crawler stats.', 'woocommerce-ai-storefront' ), array( 'status' => 500 ) );
		}

		// Top-10 search queries from the raw log (query strings are not
		// aggregated into the summary table, so we go to TABLE_LOG here).
		// `query != ''` filters out non-search events where query is stored
		// as an empty string (search events always carry a non-empty query).
		// The lower bound is clamped to RAW_RETENTION_DAYS — see comment on
		// $top_queries_after above for why this differs from $after_datetime.
		$query_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query,
				        COUNT(*)                                          AS count,
				        GROUP_CONCAT(DISTINCT agent ORDER BY agent SEPARATOR ',') AS agents
				 FROM {$log_table}
				 WHERE endpoint = %s
				   AND query != ''
				   AND crawled_at >= %s
				 GROUP BY query
				 ORDER BY count DESC
				 LIMIT 10",
				WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH,
				$top_queries_after
			)
		);
		$last_error = $wpdb->last_error;
		if ( $last_error ) {
			wc_get_logger()->warning(
				'get_crawl_stats query_rows DB error: ' . $last_error,
				array( 'source' => 'wc-ai-storefront' )
			);
			return new WP_Error( 'db_error', __( 'Could not load crawler stats.', 'woocommerce-ai-storefront' ), array( 'status' => 500 ) );
		}
		// phpcs:enable

		$total_requests  = 0;
		$total_throttles = 0;
		$by_endpoint     = array();

		foreach ( (array) $endpoint_rows as $row ) {
			$requests                               = (int) $row->requests;
			$throttles                              = (int) $row->throttles;
			$total_requests                        += $requests;
			$total_throttles                       += $throttles;
			$by_endpoint[ (string) $row->endpoint ] = $requests;
		}

		$store_api_queries = ( $by_endpoint[ WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SEARCH ] ?? 0 )
			+ ( $by_endpoint[ WC_AI_Storefront_Crawl_Logger::ENDPOINT_STORE_API_SINGLE ] ?? 0 );

		$by_agent = array();
		foreach ( (array) $agent_rows as $row ) {
			$by_agent[] = array(
				'agent'    => (string) $row->agent,
				'requests' => (int) $row->requests,
			);
		}

		$top_queries = array();
		foreach ( (array) $query_rows as $row ) {
			$top_queries[] = array(
				'query'  => (string) $row->query,
				'count'  => (int) $row->count,
				'agents' => array_values( array_filter( explode( ',', (string) $row->agents ) ) ),
			);
		}

		$data = array(
			'period'                  => $period,
			'total_requests'          => $total_requests,
			'unique_products'         => $unique_products,
			'store_api_queries'       => $store_api_queries,
			'throttle_count'          => $total_throttles,
			'throttle_rate'           => $total_requests > 0 ? round( ( $total_throttles / $total_requests ) * 100, 1 ) : 0.0,
			'by_agent'                => $by_agent,
			'top_queries'             => $top_queries,
			'top_queries_window_days' => $top_queries_days,
		);

		set_transient( 'wc_ai_storefront_crawl_stats_' . $period, $data, 5 * MINUTE_IN_SECONDS );

		// Live fields — never stored in the transient so they reflect current
		// state on every response (filter changes / new raw-log traffic).
		$data['rollup_interval'] = WC_AI_Storefront_Crawl_Logger::get_effective_rollup_interval();
		$data['raw_event_count'] = $raw_event_count;

		return new WP_REST_Response( $data );
	}

	/**
	 * Get discovery endpoint URLs for admin display.
	 *
	 * @return WP_REST_Response
	 */
	public function get_endpoints_info() {
		return new WP_REST_Response(
			array(
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
			)
		);
	}
}
