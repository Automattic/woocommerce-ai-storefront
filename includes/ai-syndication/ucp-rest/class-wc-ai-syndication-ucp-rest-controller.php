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
	 * Handler for POST /catalog/lookup.
	 *
	 * Accepts a UCP request body of shape `{ "ids": ["prod_N", ...] }`,
	 * fetches each referenced product via `rest_do_request` against the
	 * WC Store API, and returns a UCP catalog response wrapping the
	 * translated products plus `not_found` messages for any IDs that
	 * didn't resolve.
	 *
	 * Missing products are a business outcome, not a protocol error:
	 * we return 200 with `products: []` + messages, not 404. A 400 is
	 * only for genuinely malformed input (missing/non-array `ids`).
	 *
	 * @param WP_REST_Request $request UCP lookup request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_catalog_lookup( WP_REST_Request $request ) {
		$ids = $request->get_param( 'ids' );

		if ( ! is_array( $ids ) ) {
			return new WP_Error(
				'ucp_invalid_input',
				__( 'Request body must include an "ids" array.', 'woocommerce-ai-syndication' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $ids ) ) {
			return new WP_Error(
				'ucp_invalid_input',
				__( 'The "ids" array must contain at least one ID.', 'woocommerce-ai-syndication' ),
				[ 'status' => 400 ]
			);
		}

		$products = [];
		$messages = [];

		foreach ( $ids as $index => $raw_id ) {
			$wc_id = self::parse_ucp_id_to_wc_int( $raw_id );

			if ( $wc_id <= 0 ) {
				$messages[] = self::not_found_message( (int) $index );
				continue;
			}

			$wc_product = self::fetch_store_api_product( $wc_id );
			if ( null === $wc_product ) {
				$messages[] = self::not_found_message( (int) $index );
				continue;
			}

			// Variable products: pre-fetch each variation's full Store API
			// response so UcpProductTranslator can emit one real variant
			// per variation rather than a synthesized default. Skipped
			// variations (fetch failed) are silently omitted — a partial
			// set is still more useful than a synthesized fallback.
			$wc_variations = self::fetch_variations_for( $wc_product );

			$products[] = WC_AI_Syndication_UCP_Product_Translator::translate(
				$wc_product,
				$wc_variations
			);
		}

		$response_body = [
			'ucp'      => WC_AI_Syndication_UCP_Envelope::catalog_envelope(
				'dev.ucp.shopping.catalog.lookup'
			),
			'products' => $products,
		];

		if ( ! empty( $messages ) ) {
			$response_body['messages'] = $messages;
		}

		return new WP_REST_Response( $response_body, 200 );
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

	// ------------------------------------------------------------------
	// Shared helpers (used by lookup and, soon, search + checkout handlers)
	// ------------------------------------------------------------------

	/**
	 * Parse a UCP ID string (`prod_N`, `var_N`, `var_N_default`) into
	 * the underlying WC post/variation ID.
	 *
	 * The prefix strip + `(int)` cast is deliberately lenient: PHP's
	 * int cast truncates at the first non-numeric character, so
	 * `var_123_default` → `123` cleanly, and malformed input like
	 * `"abc"` or `"prod_"` → 0 (which the caller treats as not-found).
	 *
	 * Non-string input returns 0 too, so callers don't have to type-
	 * check before calling.
	 *
	 * @param mixed $raw_id
	 */
	private static function parse_ucp_id_to_wc_int( $raw_id ): int {
		if ( ! is_string( $raw_id ) ) {
			return 0;
		}

		$stripped = preg_replace( '/^(prod_|var_)/', '', $raw_id );
		return (int) $stripped;
	}

	/**
	 * Dispatch `GET /wc/store/v1/products/{id}` internally via
	 * `rest_do_request` and return the decoded payload — or null if
	 * the product doesn't exist, the dispatcher errored, or the
	 * response didn't carry a usable array.
	 *
	 * Using `rest_do_request` rather than a direct WC_Data_Store call
	 * matters: it threads the request through the Store API's full
	 * pipeline, which means our `woocommerce_store_api_product_collection_query_args`
	 * filter (from task 8) still applies — products excluded by the
	 * merchant's selected_categories/products settings return 404 here,
	 * even though the agent supplied a raw numeric ID.
	 *
	 * @return ?array<string, mixed>
	 */
	private static function fetch_store_api_product( int $id ): ?array {
		$request  = new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $id );
		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( $response->get_status() >= 400 ) {
			return null;
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : null;
	}

	/**
	 * For variable products, fetch each variation's full Store API
	 * response so the product translator can emit per-variation
	 * variants (task 7). Simple products return an empty array.
	 *
	 * Variations that fail to fetch are silently skipped rather than
	 * aborting the whole translation — partial variant lists are
	 * better than synthesized-default fallbacks for agents trying to
	 * surface the real price range to users.
	 *
	 * @param array<string, mixed> $wc_product Store API response for the parent product.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_variations_for( array $wc_product ): array {
		if ( 'variable' !== ( $wc_product['type'] ?? '' ) ) {
			return [];
		}

		$variation_refs = $wc_product['variations'] ?? [];
		if ( ! is_array( $variation_refs ) || empty( $variation_refs ) ) {
			return [];
		}

		$variations = [];
		foreach ( $variation_refs as $ref ) {
			// WC Store API emits `variations` as `[{id, attributes}, ...]`
			// — just the pointer. Fetch the full variation record.
			$variation_id = is_array( $ref )
				? (int) ( $ref['id'] ?? 0 )
				: (int) $ref;

			if ( $variation_id <= 0 ) {
				continue;
			}

			$data = self::fetch_store_api_product( $variation_id );
			if ( null !== $data ) {
				$variations[] = $data;
			}
		}

		return $variations;
	}

	/**
	 * Build a UCP `not_found` error message for the ID at position
	 * `$index` in the request's `ids` array. The JSONPath-style
	 * `path` lets agents localize which specific ID failed.
	 *
	 * @return array<string, string>
	 */
	private static function not_found_message( int $index ): array {
		return [
			'type'     => 'error',
			'code'     => 'not_found',
			'path'     => '$.ids[' . $index . ']',
			'severity' => 'unrecoverable',
		];
	}
}
