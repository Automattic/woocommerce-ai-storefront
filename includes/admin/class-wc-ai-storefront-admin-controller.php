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
						'enabled'                  => [
							'type' => 'string',
							'enum' => [ 'yes', 'no' ],
						],
						'product_selection_mode'   => [
							'type' => 'string',
							'enum' => [ 'all', 'by_taxonomy', 'categories', 'tags', 'brands', 'selected' ],
						],
						'selected_categories'      => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'selected_tags'            => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'selected_brands'          => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'selected_products'        => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'rate_limit_rpm'           => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 1000,
						],
						'allowed_crawlers'         => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						// UCP REST gate for unknown-host AI agents.
						// Strict enum here so REST 400s on a malformed
						// value before the sanitizer runs — there's
						// only two valid states for a yes/no toggle and
						// no normalization to do.
						'allow_unknown_ucp_agents' => [
							'type' => 'string',
							'enum' => [ 'yes', 'no' ],
						],
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
						'return_policy'            => [
							'type'       => 'object',
							'properties' => [
								'mode'    => [
									'type' => 'string',
								],
								'page_id' => [
									'type' => 'integer',
								],
								// `days` accepts integer OR null (the
								// "no window configured" sentinel
								// returned by the sanitizer). Without
								// `'null'` in the type list, sending
								// `days: null` would 400 even though
								// it's a canonical sanitizer output.
								'days'    => [
									'type' => [ 'integer', 'null' ],
								],
								'fees'    => [
									'type' => 'string',
								],
								'methods' => [
									'type'  => 'array',
									'items' => [
										'type' => 'string',
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
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					],
					'selected_tags'       => [
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					],
					'selected_brands'     => [
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					],
					'selected_products'   => [
						'type'              => 'array',
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => array( __CLASS__, 'sanitize_id_array' ),
					],
				],
			]
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
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_policy_pages' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
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
	 * Sanitize an ID array input — cast each element to a non-negative integer.
	 *
	 * @param mixed $value Raw input, expected to be an array of IDs.
	 * @return array<int> Array of absint-sanitized IDs.
	 */
	private static function sanitize_id_array( $value ): array {
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
		$data = [];

		$fields = [ 'enabled', 'product_selection_mode', 'selected_categories', 'selected_tags', 'selected_brands', 'selected_products', 'rate_limit_rpm', 'allowed_crawlers', 'allow_unknown_ucp_agents', 'return_policy' ];
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
				// Safe-encoding flag set matches `WC_AI_Storefront_Ucp::serve_manifest()`
				// — uniform across the two write sites that populate
				// `WC_AI_Storefront_Ucp::CACHE_KEY` so a read from the
				// transient lands on identically-encoded bytes regardless
				// of which writer produced it. See that method for the
				// HEX-escape rationale (script-tag breakout + adjacent
				// injection vectors).
				$manifest = wp_json_encode( $ucp->generate_manifest( WC_AI_Storefront::get_settings() ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
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

		// DB error — return an empty result set so the admin UI shows
		// "no orders" rather than a fatal. The error is surfaced via the
		// HTTP response code if the caller checks it; an empty array is
		// less confusing than a stack trace in the admin panel.
		if ( is_wp_error( $orders ) ) {
			return new WP_REST_Response(
				array(
					'orders'   => array(),
					'total'    => 0,
					'currency' => get_woocommerce_currency(),
				)
			);
		}

		$statuses = wc_get_order_statuses();
		$rows     = [];

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
		$excluded = [];
		foreach ( [ 'cart', 'checkout', 'myaccount', 'shop' ] as $slug ) {
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
		foreach ( [ 'cart', 'checkout', 'my-account', 'myaccount', 'shop' ] as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && (int) $page->ID > 0 ) {
				$excluded[] = (int) $page->ID;
			}
		}

		$excluded = array_values( array_unique( $excluded ) );

		$pages = get_pages(
			[
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
			]
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
				[ 'status' => 500 ]
			);
		}

		$result = [];
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
			$result[] = [
				'id'    => (int) $page->ID,
				'title' => [
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally re-invoking WP core's `the_title` filter to mirror the `/wp/v2/pages` REST endpoint's `title.rendered` field shape (entity decoding, shortcode stripping, third-party title-tweaking plugins). The drop-in-replacement contract requires identical filtering, not a plugin-prefixed parallel hook.
					'rendered' => apply_filters( 'the_title', $page->post_title, $page->ID ),
				],
				'link'  => get_permalink( $page->ID ),
			];
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
