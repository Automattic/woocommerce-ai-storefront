<?php
/**
 * AI Syndication: UCP REST Controller
 *
 * Registers the UCP-compliant REST routes at `/wp-json/wc/ucp/v1/`:
 *
 *   POST /catalog/search    — UCP dev.ucp.shopping.catalog.search
 *   POST /catalog/lookup    — UCP dev.ucp.shopping.catalog.lookup
 *   POST /checkout-sessions — UCP dev.ucp.shopping.checkout (create)
 *
 * All routes internally dispatch to the WooCommerce Store API via
 * `rest_do_request()` (in-process, no HTTP loopback). Responses are
 * translated through Product/Variant translators and wrapped in
 * UCP response envelopes before returning to the agent.
 *
 * The `checkout-sessions` route is stateless by design — every
 * create response returns `status: requires_escalation` with a
 * `continue_url` pointing at WooCommerce's native Shareable
 * Checkout URL. The plugin does NOT implement the UCP checkout
 * lifecycle (get/update/complete/cancel) because the session
 * exists only for the duration of the create response; after the
 * agent redirects the user, WooCommerce owns the rest of the
 * transaction.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP REST controller.
 */
class WC_AI_Syndication_UCP_REST_Controller {

	/**
	 * REST namespace — hosted under the `wc/` prefix so it fits
	 * WooCommerce's conventions, with a `ucp` segment marking the
	 * content as Universal Commerce Protocol-shaped (distinct from
	 * WC's native `store/` or `v3/` namespaces).
	 */
	const NAMESPACE = 'wc/ucp/v1';

	/**
	 * Register all UCP REST routes.
	 *
	 * All routes are POST — UCP uses request bodies for its capability
	 * payloads (catalog query, line items) so POST is required even for
	 * read-shaped operations like catalog/search.
	 *
	 * All routes are public (`permission_callback => '__return_true'`):
	 * UCP's agent authentication happens at the `UCP-Agent` header
	 * level and is currently used only for attribution (utm_source in
	 * checkout handoff) and logging, not for access control. Merchants
	 * who need to gate access can pause syndication via the admin UI;
	 * handlers check the enabled setting and return a UCP error
	 * envelope rather than 404ing from unregistered routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/catalog/search',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_catalog_search' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog/lookup',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_catalog_lookup' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/checkout-sessions',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_checkout_sessions_create' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Stub handler for POST /catalog/search.
	 *
	 * Task 11 replaces this with a full implementation that:
	 *   1. Parses filters.categories, filters.price, and query
	 *   2. Dispatches to GET /wc/store/v1/products via rest_do_request
	 *      (which inherits the Store API product-collection filter from
	 *      task 8, so selected_categories/products restrictions apply)
	 *   3. Translates each result via UcpProductTranslator
	 *   4. Wraps in the catalog envelope
	 *
	 * @param WP_REST_Request $request Will carry the UCP search body
	 *                                 once task 11 replaces this stub;
	 *                                 unused until then.
	 * @return WP_Error                501 sentinel until task 11 lands.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Transient stub; task 11 consumes $request.
	public function handle_catalog_search( WP_REST_Request $request ) {
		return new WP_Error(
			'ucp_not_implemented',
			__( 'catalog/search is not yet implemented.', 'woocommerce-ai-syndication' ),
			[ 'status' => 501 ]
		);
	}

	/**
	 * Stub handler for POST /catalog/lookup.
	 *
	 * Task 10 replaces this with an implementation that strips the
	 * `prod_` / `var_` prefix from each requested ID, dispatches
	 * GET /wc/store/v1/products/{id} per entry, translates via
	 * UcpProductTranslator, and emits `not_found` messages for any IDs
	 * that didn't resolve.
	 *
	 * @param WP_REST_Request $request Will carry the UCP lookup body
	 *                                 once task 10 replaces this stub;
	 *                                 unused until then.
	 * @return WP_Error                501 sentinel until task 10 lands.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Transient stub; task 10 consumes $request.
	public function handle_catalog_lookup( WP_REST_Request $request ) {
		return new WP_Error(
			'ucp_not_implemented',
			__( 'catalog/lookup is not yet implemented.', 'woocommerce-ai-syndication' ),
			[ 'status' => 501 ]
		);
	}

	/**
	 * Stub handler for POST /checkout-sessions.
	 *
	 * Task 12 replaces this with an implementation that validates
	 * line items against Store API, computes totals + continue_url
	 * (WC Shareable Checkout URL format), attaches legal links
	 * (privacy + terms), and returns the checkout envelope with
	 * `status: requires_escalation`.
	 *
	 * @param WP_REST_Request $request Will carry line_items + UCP-Agent
	 *                                 context once task 12 replaces this
	 *                                 stub; unused until then.
	 * @return WP_Error                501 sentinel until task 12 lands.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Transient stub; task 12 consumes $request.
	public function handle_checkout_sessions_create( WP_REST_Request $request ) {
		return new WP_Error(
			'ucp_not_implemented',
			__( 'checkout-sessions is not yet implemented.', 'woocommerce-ai-syndication' ),
			[ 'status' => 501 ]
		);
	}
}
