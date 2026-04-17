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
	 * Upper bound on IDs per `POST /catalog/lookup` request.
	 *
	 * Each ID triggers a `rest_do_request( GET /wc/store/v1/products/{id} )`
	 * dispatch, plus a per-variation fan-out for variable products. Caps
	 * exist to prevent unauthenticated callers from amplifying one request
	 * into thousands of internal dispatches and exhausting PHP-FPM workers.
	 * 100 is generous for legitimate agent use (typical agents lookup a
	 * handful of products at a time).
	 */
	const MAX_IDS_PER_LOOKUP = 100;

	/**
	 * Upper bound on line items per `POST /checkout-sessions` request.
	 *
	 * Same DoS-mitigation rationale as MAX_IDS_PER_LOOKUP. Real carts
	 * rarely exceed 20 items; 100 gives agents enough headroom without
	 * letting a malicious caller turn a single request into a load-test.
	 */
	const MAX_LINE_ITEMS_PER_CHECKOUT = 100;

	/**
	 * Upper bound on variations fetched per variable product.
	 *
	 * Bounds the N+1 fan-out in fetch_variations_for(). A product with
	 * 200 variations would otherwise trigger 200 internal dispatches just
	 * for one hit of a search response. 50 covers typical
	 * color/size/pattern combinations; products with more are rare and
	 * can surface via `POST /catalog/lookup` with specific variation IDs
	 * if an agent needs fidelity.
	 */
	const MAX_VARIATIONS_PER_PRODUCT = 50;

	/**
	 * Upper bound on `quantity` in a checkout line item.
	 *
	 * Prevents `unit_price_minor * quantity` from silently overflowing
	 * PHP_INT_MAX and promoting to a float, which would JSON-serialize
	 * as `1.84e19` and violate the UCP schema's integer constraint on
	 * `line_total.amount` / `totals[].amount`. 10,000 is well above any
	 * legitimate bulk order and well below the overflow threshold even
	 * for high-value products (10k * 10M cents = 10^11, safely inside
	 * PHP_INT_MAX on 64-bit).
	 */
	const MAX_QUANTITY_PER_LINE_ITEM = 10000;

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
	 * who need to gate access can pause syndication via the admin UI —
	 * each handler calls `check_syndication_enabled()` before doing any
	 * work and returns a UCP-shaped 503 WP_Error when off. Keeping
	 * routes registered (versus unregistering on disable) avoids
	 * rewrite-flush churn every time a merchant toggles the setting.
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
	 * Handler for POST /catalog/search.
	 *
	 * Accepts a UCP search request body of shape:
	 *   {
	 *     "query": "blue shirt",
	 *     "filters": {
	 *       "categories": ["clothing", "tops"],
	 *       "price":      { "min": 1000, "max": 5000 }
	 *     }
	 *   }
	 *
	 * Maps UCP fields onto WC Store API query params and dispatches
	 * `GET /wc/store/v1/products` via `rest_do_request`. Every returned
	 * product is translated to UCP shape; variable products get their
	 * variations pre-fetched by `fetch_variations_for()` so variant
	 * lists are real rather than synthesized defaults.
	 *
	 * Pagination is deferred to a future version — v1 returns whatever
	 * Store API considers the default page (typically 10 products).
	 * Agents needing more will page via repeated calls once v1.4 adds
	 * explicit pagination support.
	 *
	 * Performance note: variable products fan out to N+1 dispatches
	 * (1 list call + 1 per variation per product). For large catalogs
	 * this may need per-request memoization; profile on real stores
	 * before optimizing (see PLAN-ucp-adapter.md known-unknown #2).
	 *
	 * @param WP_REST_Request $request UCP search request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_catalog_search( WP_REST_Request $request ) {
		$disabled = self::check_syndication_enabled();
		if ( $disabled ) {
			return $disabled;
		}

		// Attribution: note the calling agent (unblocking; logging only).
		$agent_header = $request->get_header( 'ucp-agent' );
		if ( is_string( $agent_header ) && '' !== $agent_header ) {
			$host = WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname(
				$agent_header
			);
			WC_AI_Syndication_Logger::debug(
				'UCP catalog/search from agent: ' . ( '' !== $host ? $host : 'unknown' )
			);
		}

		$store_params = self::map_ucp_search_to_store_api( $request );

		$store_request = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		foreach ( $store_params as $k => $v ) {
			$store_request->set_param( $k, $v );
		}

		$store_response = rest_do_request( $store_request );

		if ( is_wp_error( $store_response ) || $store_response->get_status() >= 500 ) {
			return new WP_Error(
				'ucp_internal_error',
				__( 'Unable to fetch products from the store.', 'woocommerce-ai-syndication' ),
				[ 'status' => 500 ]
			);
		}

		// 4xx from Store API (invalid filter, e.g., unknown category) is
		// treated as "no results" rather than an error: the agent's query
		// simply didn't match anything. UCP's business-outcome convention.
		$wc_products = [];
		if ( $store_response->get_status() < 400 ) {
			$data = $store_response->get_data();
			if ( is_array( $data ) ) {
				$wc_products = $data;
			}
		}

		$products = [];
		foreach ( $wc_products as $wc_product ) {
			if ( ! is_array( $wc_product ) ) {
				continue;
			}
			$wc_variations = self::fetch_variations_for( $wc_product );
			$products[]    = WC_AI_Syndication_UCP_Product_Translator::translate(
				$wc_product,
				$wc_variations
			);
		}

		return new WP_REST_Response(
			[
				'ucp'      => WC_AI_Syndication_UCP_Envelope::catalog_envelope(
					'dev.ucp.shopping.catalog.search'
				),
				'products' => $products,
			],
			200
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
		$disabled = self::check_syndication_enabled();
		if ( $disabled ) {
			return $disabled;
		}

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

		if ( count( $ids ) > self::MAX_IDS_PER_LOOKUP ) {
			return new WP_Error(
				'ucp_invalid_input',
				sprintf(
					/* translators: %d is the maximum number of IDs per request. */
					__( 'The "ids" array exceeds the per-request limit of %d entries.', 'woocommerce-ai-syndication' ),
					self::MAX_IDS_PER_LOOKUP
				),
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
			// response so WC_AI_Syndication_UCP_Product_Translator can
			// emit one real variant per variation rather than a synthesized
			// default. Skipped variations (fetch failed) are silently
			// omitted — a partial set is still more useful than a
			// synthesized fallback.
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
	 * Handler for POST /checkout-sessions (create).
	 *
	 * UCP checkout sessions in this implementation are **stateless
	 * one-shot redirects**: every successful response returns
	 * `status: requires_escalation` with a `continue_url` pointing at
	 * WooCommerce's native Shareable Checkout URL. Once the agent
	 * redirects the user, WooCommerce owns the rest of the transaction;
	 * the session ID is a correlation token only — no storage, no
	 * subsequent GET/PUT/DELETE on this URL.
	 *
	 * Request shape:
	 *   {
	 *     "line_items": [
	 *       { "item": { "id": "var_123" }, "quantity": 2 },
	 *       ...
	 *     ]
	 *   }
	 *
	 * Per-line-item handling:
	 *   - prod_N + simple product    → includable
	 *   - var_N  + variation         → includable
	 *   - prod_N + variable (parent) → rejected (variation_required)
	 *   - grouped / external types   → rejected (product_type_unsupported)
	 *   - unknown ID                 → rejected (not_found)
	 *   - out of stock               → warning, still included; the
	 *                                  merchant's checkout page makes
	 *                                  the final determination (may
	 *                                  allow backorders)
	 *
	 * Response status:
	 *   - any valid items → 201 with status=requires_escalation + continue_url
	 *   - all items fail  → 200 with status=error, no continue_url,
	 *                       messages explain each failure
	 *
	 * Legal links: `links` is mandatory per UCP schema. We emit what's
	 * configured via get_privacy_policy_url() + wc_get_page_permalink('terms'),
	 * with advisory warnings for any page the merchant hasn't set up.
	 *
	 * @param WP_REST_Request $request UCP checkout-sessions create request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_checkout_sessions_create( WP_REST_Request $request ) {
		$disabled = self::check_syndication_enabled();
		if ( $disabled ) {
			return $disabled;
		}

		$line_items_raw = $request->get_param( 'line_items' );

		if ( ! is_array( $line_items_raw ) || empty( $line_items_raw ) ) {
			return new WP_Error(
				'ucp_invalid_input',
				__( 'Request must include a non-empty "line_items" array.', 'woocommerce-ai-syndication' ),
				[ 'status' => 400 ]
			);
		}

		if ( count( $line_items_raw ) > self::MAX_LINE_ITEMS_PER_CHECKOUT ) {
			return new WP_Error(
				'ucp_invalid_input',
				sprintf(
					/* translators: %d is the maximum number of line items per request. */
					__( 'The "line_items" array exceeds the per-request limit of %d entries.', 'woocommerce-ai-syndication' ),
					self::MAX_LINE_ITEMS_PER_CHECKOUT
				),
				[ 'status' => 400 ]
			);
		}

		$agent_host = self::resolve_agent_host( $request );
		$currency   = function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency()
			: 'USD';

		$processed = [];
		$messages  = [];

		foreach ( $line_items_raw as $index => $line_item ) {
			$outcome = self::process_line_item( $line_item, (int) $index );

			foreach ( $outcome['messages'] as $message ) {
				$messages[] = $message;
			}
			if ( null !== $outcome['processed'] ) {
				$processed[] = $outcome['processed'];
			}
		}

		// Legal links + warnings for any gaps.
		[ $links, $legal_warnings ] = self::collect_legal_links();
		foreach ( $legal_warnings as $warning ) {
			$messages[] = $warning;
		}

		$has_valid_items = ! empty( $processed );
		$continue_url    = $has_valid_items
			? self::build_continue_url( $processed, $agent_host )
			: '';

		// Response line_items: echo successfully-processed items with
		// enriched price/total data. Items that failed validation are
		// NOT in line_items — they only appear via messages pointing
		// at the original request index.
		$response_line_items = [];
		$subtotal_amount     = 0;
		foreach ( $processed as $p ) {
			$line_total            = $p['unit_price_minor'] * $p['quantity'];
			$subtotal_amount      += $line_total;
			$response_line_items[] = [
				'item'       => [ 'id' => $p['ucp_id'] ],
				'quantity'   => $p['quantity'],
				'unit_price' => [
					'amount'   => $p['unit_price_minor'],
					'currency' => $currency,
				],
				'line_total' => [
					'amount'   => $line_total,
					'currency' => $currency,
				],
			];
		}

		if ( $has_valid_items ) {
			// Buyer handoff message accompanies every redirect. Agents
			// surface the `content` to the user verbatim before linking
			// out, so the phrasing matters — keep it short and neutral.
			$messages[] = [
				'type'     => 'error',
				'code'     => 'buyer_handoff_required',
				'severity' => 'requires_buyer_input',
				'content'  => __( 'Complete your purchase on the merchant site.', 'woocommerce-ai-syndication' ),
			];
		}

		$response_body = [
			'ucp'        => WC_AI_Syndication_UCP_Envelope::checkout_envelope(),
			'id'         => 'chk_' . bin2hex( random_bytes( 8 ) ),
			'status'     => $has_valid_items ? 'requires_escalation' : 'error',
			'currency'   => $currency,
			'line_items' => $response_line_items,
			'totals'     => [
				[
					'type'   => 'subtotal',
					'amount' => $subtotal_amount,
				],
			],
			'links'      => $links,
		];

		if ( ! empty( $messages ) ) {
			$response_body['messages'] = $messages;
		}

		if ( '' !== $continue_url ) {
			$response_body['continue_url'] = $continue_url;
		}

		// 201 Created when we have something to escalate to; 200 otherwise.
		// The session ID is a correlation token only — no persistence.
		return new WP_REST_Response(
			$response_body,
			$has_valid_items ? 201 : 200
		);
	}

	// ------------------------------------------------------------------
	// Shared helpers (used by all three handlers)
	// ------------------------------------------------------------------

	/**
	 * Return a UCP error response if AI Syndication is disabled, else null.
	 *
	 * Routes are registered unconditionally on `rest_api_init` so the
	 * rewrite-rule and permalink state stays stable across enable/disable
	 * toggles. Gating access lives here instead: when the merchant has
	 * paused syndication, each handler returns a UCP-shaped 503 error
	 * before doing any work. Agents see a clean signal ("the service is
	 * temporarily offline") rather than a confusing success response
	 * with filtered or empty catalog data.
	 *
	 * The routes still dispatch to the WooCommerce Store API at its
	 * own path when disabled — merchants who pause AI Syndication do
	 * not lose their regular Store API access, only the UCP wrapper.
	 *
	 * @return ?WP_Error Null when enabled; WP_Error with status 503 when off.
	 */
	private static function check_syndication_enabled(): ?WP_Error {
		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return new WP_Error(
				'ucp_disabled',
				__( 'AI Syndication is not currently enabled on this store.', 'woocommerce-ai-syndication' ),
				[ 'status' => 503 ]
			);
		}
		return null;
	}

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
	 * filter still applies — products excluded by the
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
	 * variants. Simple products return an empty array.
	 *
	 * Variations that fail to fetch are silently skipped rather than
	 * aborting the whole translation — partial variant lists are
	 * better than synthesized-default fallbacks for agents trying to
	 * surface the real price range to users.
	 *
	 * Capped at MAX_VARIATIONS_PER_PRODUCT to bound the N+1 fan-out.
	 * A product with 200 variations would otherwise trigger 200
	 * internal dispatches just for one hit of a search response.
	 * Agents that need every variation of a high-variant-count product
	 * can paginate via repeated `POST /catalog/lookup` calls with
	 * specific `var_N` IDs.
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

		// Hard cap before entering the fetch loop. `array_slice` preserves
		// source order so variations come back in the same sequence WC
		// emitted — important because the product's `options` (attribute
		// order) is derived from the variations list.
		if ( count( $variation_refs ) > self::MAX_VARIATIONS_PER_PRODUCT ) {
			$variation_refs = array_slice( $variation_refs, 0, self::MAX_VARIATIONS_PER_PRODUCT );
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

	/**
	 * Translate a UCP search request's body fields onto WC Store API
	 * query params.
	 *
	 * Mapping:
	 *   query                → search       (full-text match)
	 *   filters.categories   → category     (comma-joined term IDs)
	 *   filters.price.min    → min_price    (presentment units, string)
	 *   filters.price.max    → max_price
	 *
	 * Non-object `filters`, unknown keys, and malformed nested shapes
	 * are silently ignored — returning an empty `$params` array is
	 * equivalent to "list all products," which is the sensible fallback
	 * for a garbled search query. (Pipeline-breaking input — e.g., body
	 * not even an object — is rejected upstream by WP's REST dispatcher.)
	 *
	 * @return array<string, string|int>
	 */
	private static function map_ucp_search_to_store_api( WP_REST_Request $request ): array {
		$params = [];

		$query = $request->get_param( 'query' );
		if ( is_string( $query ) && '' !== $query ) {
			$params['search'] = $query;
		}

		$filters = $request->get_param( 'filters' );
		if ( ! is_array( $filters ) ) {
			return $params;
		}

		if ( isset( $filters['categories'] ) && is_array( $filters['categories'] ) ) {
			$term_ids = self::resolve_category_term_ids( $filters['categories'] );
			if ( ! empty( $term_ids ) ) {
				$params['category'] = implode( ',', $term_ids );
			}
		}

		if ( isset( $filters['price'] ) && is_array( $filters['price'] ) ) {
			$price = $filters['price'];
			if ( isset( $price['min'] ) && is_numeric( $price['min'] ) && $price['min'] >= 0 ) {
				$params['min_price'] = self::minor_units_to_presentment( (int) $price['min'] );
			}
			if ( isset( $price['max'] ) && is_numeric( $price['max'] ) && $price['max'] >= 0 ) {
				$params['max_price'] = self::minor_units_to_presentment( (int) $price['max'] );
			}
		}

		return $params;
	}

	/**
	 * Resolve UCP category strings to WC product_cat term IDs.
	 *
	 * WC Store API's `category` param only accepts numeric IDs, not
	 * slugs or names — despite some documentation saying otherwise.
	 * We try slug lookup first (canonical, URL-safe), then name as a
	 * fallback so agents echoing back category values from our search
	 * responses still work. Unresolvable strings are silently dropped
	 * rather than returning an error: the agent asked for a category
	 * that doesn't exist, which naturally yields an empty result set.
	 *
	 * A future release should revisit emitting slugs from
	 * `WC_AI_Syndication_UCP_Product_Translator::extract_categories()`
	 * so round-tripping doesn't rely on the name fallback here.
	 *
	 * @param array<int, mixed> $inputs
	 * @return array<int, int>
	 */
	private static function resolve_category_term_ids( array $inputs ): array {
		$ids = [];
		foreach ( $inputs as $input ) {
			if ( ! is_string( $input ) || '' === $input ) {
				continue;
			}

			$term = get_term_by( 'slug', $input, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				$term = get_term_by( 'name', $input, 'product_cat' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * Convert integer minor units (UCP's price encoding) to a decimal
	 * string in the store's currency presentment format.
	 *
	 * UCP sends `1000` meaning $10.00 (for a 2-decimal currency like
	 * USD). WC Store API's min_price/max_price expect `"10.00"`. We
	 * divide by 10^decimals where `decimals` is the merchant's configured
	 * price precision (2 for USD/EUR, 0 for JPY, 3 for BHD).
	 *
	 * `number_format()` over raw float division sidesteps floating-point
	 * representation quirks — 0.1 + 0.2 famously isn't 0.3, and we
	 * don't want that kind of drift in a price string.
	 */
	private static function minor_units_to_presentment( int $minor_units ): string {
		$decimals = function_exists( 'wc_get_price_decimals' )
			? (int) wc_get_price_decimals()
			: 2;

		return number_format(
			$minor_units / ( 10 ** $decimals ),
			$decimals,
			'.',
			''
		);
	}

	// ------------------------------------------------------------------
	// Checkout-sessions helpers
	// ------------------------------------------------------------------

	/**
	 * Resolve the hostname from the UCP-Agent header for attribution,
	 * falling back to the plugin's `ucp_unknown` sentinel when the
	 * header is missing/malformed.
	 *
	 * Used as `utm_source` in the Shareable Checkout URL — lets
	 * merchants see order attribution flowing through WooCommerce's
	 * native Order Attribution system, so they can measure AI-sourced
	 * revenue without extra integration.
	 */
	private static function resolve_agent_host( WP_REST_Request $request ): string {
		$header = $request->get_header( 'ucp-agent' );

		if ( is_string( $header ) && '' !== $header ) {
			$host = WC_AI_Syndication_UCP_Agent_Header::extract_profile_hostname( $header );
			if ( '' !== $host ) {
				return $host;
			}
		}

		return WC_AI_Syndication_UCP_Agent_Header::FALLBACK_SOURCE;
	}

	/**
	 * Validate + fetch one line item, returning the processable data
	 * on success plus any UCP messages (errors and/or warnings) the
	 * agent should see.
	 *
	 * The `messages` array may contain multiple entries for a single
	 * line item (e.g., an in-stock product still includable but with
	 * a separate advisory about stock) — don't short-circuit after
	 * the first.
	 *
	 * @param mixed $line_item Raw line_item value from the request body.
	 * @return array{processed: ?array<string, mixed>, messages: array<int, array<string, mixed>>}
	 */
	private static function process_line_item( $line_item, int $index ): array {
		$messages = [];
		$path     = '$.line_items[' . $index . ']';

		if ( ! is_array( $line_item ) ) {
			$messages[] = self::checkout_error_message( 'invalid_line_item', $path );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		$raw_id   = $line_item['item']['id'] ?? null;
		$quantity = isset( $line_item['quantity'] ) ? (int) $line_item['quantity'] : 1;

		// Reject non-positive quantities AND quantities above the cap.
		// The upper bound prevents `unit_price_minor * quantity` from
		// silently overflowing PHP_INT_MAX into a float (which would
		// JSON-serialize as scientific notation and violate UCP's
		// integer schema constraint on `line_total.amount`).
		if ( $quantity <= 0 || $quantity > self::MAX_QUANTITY_PER_LINE_ITEM ) {
			$messages[] = self::checkout_error_message( 'invalid_quantity', $path . '.quantity' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		$wc_id = self::parse_ucp_id_to_wc_int( $raw_id );
		if ( $wc_id <= 0 ) {
			$messages[] = self::checkout_error_message( 'not_found', $path . '.item.id' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		$wc_product = self::fetch_store_api_product( $wc_id );
		if ( null === $wc_product ) {
			$messages[] = self::checkout_error_message( 'not_found', $path . '.item.id' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		$type = $wc_product['type'] ?? 'simple';

		// Variable product PARENT sent where a specific variation is
		// required. Shareable Checkout URLs can't add a parent to cart —
		// they need the variation ID.
		if ( 'variable' === $type ) {
			$messages[] = array_merge(
				self::checkout_error_message( 'variation_required', $path . '.item.id' ),
				[
					'content' => __(
						'Product is variable — specify a variation ID instead of the parent product ID.',
						'woocommerce-ai-syndication'
					),
				]
			);
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		// Grouped + external products don't fit the Shareable Checkout URL
		// model (grouped = multiple sub-products; external = redirect to
		// third-party seller). Agents should use the product's permalink
		// directly rather than our checkout flow.
		if ( 'grouped' === $type || 'external' === $type ) {
			$messages[] = self::checkout_error_message( 'product_type_unsupported', $path . '.item.id' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		// Out of stock is a warning, not a rejection: the merchant's
		// store may allow backorders, and we shouldn't second-guess.
		// The line item stays in the continue_url; checkout decides.
		$in_stock = (bool) ( $wc_product['is_in_stock'] ?? true );
		if ( ! $in_stock ) {
			$messages[] = [
				'type'     => 'warning',
				'code'     => 'out_of_stock',
				'path'     => $path . '.item.id',
				'severity' => 'advisory',
			];
		}

		$unit_price_minor = (int) ( $wc_product['prices']['price'] ?? 0 );

		return [
			'processed' => [
				'wc_id'            => $wc_id,
				// Preserve the agent's original ID for round-tripping —
				// if they sent `var_456`, echo `var_456` back even though
				// we resolved it to WC ID 456 internally. `prod_N` fallback
				// is for defensive completeness.
				'ucp_id'           => is_string( $raw_id ) && '' !== $raw_id
					? $raw_id
					: 'prod_' . $wc_id,
				'quantity'         => $quantity,
				'unit_price_minor' => $unit_price_minor,
			],
			'messages'  => $messages,
		];
	}

	/**
	 * Construct the Shareable Checkout URL for the successful items.
	 *
	 * Format per WooCommerce's native /checkout-link/ feature:
	 *   /checkout-link/?products=ID:QTY,ID:QTY
	 *
	 * UTM parameters append for attribution:
	 *   &utm_source={agent_host}&utm_medium=ai_agent
	 *
	 * WC's own Order Attribution system captures these on the resulting
	 * order, so merchants see agent-sourced traffic without needing any
	 * extra plumbing.
	 *
	 * @param array<int, array<string, mixed>> $processed Successfully-processed line items.
	 */
	private static function build_continue_url( array $processed, string $agent_host ): string {
		$segments = [];
		foreach ( $processed as $p ) {
			$segments[] = $p['wc_id'] . ':' . $p['quantity'];
		}

		$base = function_exists( 'home_url' )
			? home_url( '/checkout-link/' )
			: '/checkout-link/';

		return $base
			. '?products=' . implode( ',', $segments )
			. '&utm_source=' . rawurlencode( $agent_host )
			. '&utm_medium=ai_agent';
	}

	/**
	 * Collect Privacy Policy + Terms of Service links for the response.
	 *
	 * UCP schema requires `links` on every checkout response for legal
	 * compliance. When the merchant hasn't configured a page, we emit
	 * what IS available and add an advisory warning rather than
	 * fabricating URLs that might 404 or mis-route.
	 *
	 * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, string>>}
	 *         [links, warnings]
	 */
	private static function collect_legal_links(): array {
		$links    = [];
		$warnings = [];

		$privacy_url = function_exists( 'get_privacy_policy_url' )
			? (string) get_privacy_policy_url()
			: '';
		if ( '' !== $privacy_url ) {
			$links[] = [
				'type' => 'privacy_policy',
				'url'  => $privacy_url,
			];
		} else {
			$warnings[] = [
				'type'     => 'warning',
				'code'     => 'privacy_policy_unconfigured',
				'severity' => 'advisory',
			];
		}

		$terms_url = function_exists( 'wc_get_page_permalink' )
			? (string) wc_get_page_permalink( 'terms' )
			: '';
		if ( '' !== $terms_url ) {
			$links[] = [
				'type' => 'terms_of_service',
				'url'  => $terms_url,
			];
		} else {
			$warnings[] = [
				'type'     => 'warning',
				'code'     => 'terms_unconfigured',
				'severity' => 'advisory',
			];
		}

		return [ $links, $warnings ];
	}

	/**
	 * Build a standard UCP unrecoverable-error message for the
	 * checkout-sessions flow.
	 *
	 * @return array<string, string>
	 */
	private static function checkout_error_message( string $code, string $path ): array {
		return [
			'type'     => 'error',
			'code'     => $code,
			'path'     => $path,
			'severity' => 'unrecoverable',
		];
	}
}
