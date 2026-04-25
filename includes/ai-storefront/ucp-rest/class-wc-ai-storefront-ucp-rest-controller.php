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
 * @package WooCommerce_AI_Storefront
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP REST controller.
 */
class WC_AI_Storefront_UCP_REST_Controller {

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
	 * Default page size for `POST /catalog/search`.
	 *
	 * Matches the UCP pagination spec's documented default (`limit: 10`
	 * in `types/pagination.json#/$defs/request`). Agents that don't
	 * specify pagination in their request get this many products per
	 * call; those wanting larger pages set `pagination.limit` up to
	 * `MAX_SEARCH_LIMIT`.
	 */
	const DEFAULT_SEARCH_LIMIT = 10;

	/**
	 * Upper bound on products per catalog/search response page.
	 *
	 * Matches the WC Store API's own maximum. Agents requesting a
	 * `pagination.limit` above this are silently clamped — we don't
	 * error, because the pagination loop still terminates correctly;
	 * the agent just gets 100 products per page instead of the
	 * higher value they asked for. If they need the full catalog
	 * they page through cursors normally.
	 */
	const MAX_SEARCH_LIMIT = 100;

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
	 * legitimate bulk order and safe for the overflow math on 64-bit
	 * PHP (PHP_INT_MAX ~9.2×10¹⁸; even a $100k product at 10k units is
	 * 10¹¹, well under the ceiling).
	 *
	 * **Assumes 64-bit PHP.** On 32-bit builds (PHP_INT_MAX ~2.1×10⁹)
	 * this cap can still overflow for high-value products — e.g. a
	 * $10k product at 10k units = 10¹¹ > 32-bit max. The plugin's
	 * tested matrix is WC 9.9+ / PHP 8.0+ which is effectively always
	 * 64-bit in practice (WordPress.org stats show <0.1% of sites on
	 * 32-bit PHP as of this release). Sites running 32-bit PHP that
	 * sell high-ticket goods should tighten this constant via a
	 * filter override.
	 */
	const MAX_QUANTITY_PER_LINE_ITEM = 10000;

	/**
	 * Upper bound on per-filter array length for taxonomy filters
	 * (`filters.categories[]`, `filters.tags[]`, `filters.brand[]`)
	 * and attribute-set keys (`filters.attributes.*`).
	 *
	 * DoS mitigation: without a cap, an agent can submit a filter
	 * array with tens of thousands of entries, each driving a
	 * `get_term_by` DB lookup (typically two per entry — slug then
	 * name fallback). A cheap POST becomes N × 2 synchronous MySQL
	 * round-trips and pins a DB connection per request.
	 *
	 * 50 is generous for legitimate agents (even catalog-browsing
	 * agents refining through multi-category filters rarely exceed
	 * a dozen) and keeps the worst-case DB hit to 100 queries per
	 * taxonomy class, per request. Exceeding the cap truncates
	 * silently at the handler and emits a `filter_truncated`
	 * advisory so agents know their tail was dropped.
	 */
	const MAX_FILTER_VALUES = 50;

	/**
	 * Register all UCP REST routes.
	 *
	 * Two shapes of routes:
	 *
	 *   1. Three COMMERCE routes (POST): catalog/search, catalog/lookup,
	 *      checkout-sessions. UCP uses request bodies for its capability
	 *      payloads (catalog query, line items) so POST is required even
	 *      for read-shaped operations like catalog/search. Each handler
	 *      checks `is_syndication_disabled()` before doing any work and,
	 *      when disabled, returns a UCP-shaped `WP_REST_Response` with
	 *      HTTP 503 (via `ucp_catalog_error_response()` /
	 *      `ucp_checkout_error_response()`).
	 *
	 *   2. One DOCS route (GET): extension/schema. Serves a static JSON
	 *      Schema document describing our merchant-extension capability.
	 *      NOT gated on `is_syndication_disabled()` — same availability
	 *      as the UCP manifest itself at `/.well-known/ucp` (both are
	 *      discovery surfaces; the manifest continues to be served even
	 *      when syndication is paused, and the schema it points at must
	 *      resolve to stay consistent).
	 *
	 * All routes are public (`permission_callback => '__return_true'`):
	 * UCP's agent authentication happens at the `UCP-Agent` header
	 * level and is currently used only for attribution (utm_source in
	 * checkout handoff) and logging, not for access control. Merchants
	 * who need to gate access can pause syndication (commerce routes
	 * refuse) or use WP's standard REST permission filters.
	 *
	 * Keeping routes registered (versus unregistering on disable) avoids
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

		// JSON Schema for our merchant extension capability
		// (com.woocommerce.ai_storefront). Served per-site so the
		// schema matches the running plugin version exactly and
		// respects the merchant's own access-control configuration
		// (same perms as the rest of the UCP routes). Referenced by
		// the manifest's extension capability `schema` URL.
		register_rest_route(
			self::NAMESPACE,
			'/extension/schema',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_extension_schema' ],
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
	 * Pagination: cursor + limit, per UCP v2026-04-08
	 * `types/pagination.json`. Agents pass `pagination.cursor`
	 * (opaque base64 from prior response) and `pagination.limit`
	 * (default `DEFAULT_SEARCH_LIMIT=10`, clamped at
	 * `MAX_SEARCH_LIMIT=100`). Response emits `pagination` with
	 * `has_next_page` (always), `cursor` (only when a next page
	 * exists), and `total_count` (when Store API provides
	 * X-WP-Total). Wire-format helpers: `build_pagination_response()`,
	 * `encode_cursor()`, `decode_cursor()`. Malformed cursors emit
	 * an `invalid_cursor` warning and silently fall back to page 1;
	 * clamped limits emit `pagination_limit_clamped`.
	 *
	 * Performance note: variable products fan out to N+1 dispatches
	 * (1 list call + 1 per variation per product). Per-request
	 * memoization is implemented via reset_request_cache() and
	 * fetch_store_api_product() to bound fan-out on duplicate IDs.
	 *
	 * @param WP_REST_Request $request UCP search request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_catalog_search( WP_REST_Request $request ) {
		$capability = 'dev.ucp.shopping.catalog.search';

		// Clear per-request memoization so a product fetched in a
		// prior request can't leak here (static class-state safety).
		self::reset_request_cache();

		if ( self::is_syndication_disabled() ) {
			WC_AI_Storefront_Logger::debug( 'UCP catalog/search rejected: syndication disabled' );
			return self::ucp_catalog_error_response(
				$capability,
				__( 'AI Storefront is not currently enabled on this store.', 'woocommerce-ai-storefront' ),
				'ucp_disabled',
				null,
				503
			);
		}

		// Attribution: note the calling agent (unblocking; logging only).
		$agent_header = $request->get_header( 'ucp-agent' );
		if ( is_string( $agent_header ) && '' !== $agent_header ) {
			$host = WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname(
				$agent_header
			);
			WC_AI_Storefront_Logger::debug(
				'UCP catalog/search from agent: ' . ( '' !== $host ? $host : 'unknown' )
			);
		}

		// Signals (spec-level field, platform-observed environment data).
		// Accept and log for observability; do not gate on any signal
		// value. The UCP spec is explicit that signals MUST NOT be
		// buyer-asserted claims — we'd need a trust model for the
		// platform source before acting on `dev.ucp.buyer_ip` etc.
		// Until then: log presence, ignore values. Compliant with the
		// negative side of the spec (we don't misuse them) without
		// prematurely committing to a trust decision.
		$signals = $request->get_param( 'signals' );
		// Short-circuit on `is_enabled()` before calling
		// `format_signal_keys_for_log` — sanitizing the keys walks
		// every signal even though the result is thrown away when
		// logging is off. A large `signals` payload (bounded only
		// by the request size limit) would pay that cost on every
		// request in prod.
		if ( is_array( $signals ) && ! empty( $signals ) && WC_AI_Storefront_Logger::is_enabled() ) {
			WC_AI_Storefront_Logger::debug(
				'UCP catalog/search: received signals (not honored): '
				. self::format_signal_keys_for_log( $signals )
			);
		}

		// Note: spec has a MUST clause about validating that a request
		// "contains at least one recognized input" and a SHOULD about
		// rejecting empty ones. We satisfy the MUST (shape validation
		// happens throughout `map_ucp_search_to_store_api`) and decline
		// the SHOULD — an empty body is treated as "browse all products",
		// which the same spec section explicitly permits ("accepting
		// filter-only requests for category browsing"). Returning 400
		// on `{}` would be hostile to legitimate catalog enumeration.

		[ $store_params, $mapping_messages ] = self::map_ucp_search_to_store_api( $request );

		$store_request = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		foreach ( $store_params as $k => $v ) {
			$store_request->set_param( $k, $v );
		}

		// Mark this dispatch as UCP-initiated so the Store API
		// query-args filter fires (the filter is otherwise
		// self-gated to no-op outside UCP scope — see
		// `WC_AI_Storefront_UCP_Store_API_Filter` class docblock).
		// `try/finally` ensures the depth is decremented even if
		// `rest_do_request()` throws.
		WC_AI_Storefront_UCP_Store_API_Filter::enter_ucp_dispatch();
		try {
			$store_response = rest_do_request( $store_request );
		} finally {
			WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch();
		}

		if ( $store_response instanceof WP_Error ) {
			WC_AI_Storefront_Logger::debug(
				'UCP catalog/search: Store API dispatch returned WP_Error: '
				. $store_response->get_error_message()
			);
			return self::ucp_catalog_error_response(
				$capability,
				__( 'Unable to fetch products from the store.', 'woocommerce-ai-storefront' ),
				'ucp_internal_error',
				null,
				500
			);
		}

		$store_status = $store_response->get_status();

		// Branch by status class:
		//   - 5xx + 400/403: our translation layer or WC itself is broken;
		//     surface as internal_error so the merchant notices (not as
		//     "agent's query didn't match anything").
		//   - 404: genuinely no products matching the filters — empty
		//     results, 200 response with products: [].
		//   - Other 4xx: same as 404 (e.g., WC quirks on rare filter
		//     combinations); log and return empty.
		//   - 2xx: happy path.
		if ( $store_status >= 500 || 400 === $store_status || 403 === $store_status ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'UCP catalog/search: Store API returned %d — likely a bug in UCP→Store API param mapping',
					$store_status
				)
			);
			return self::ucp_catalog_error_response(
				$capability,
				__( 'Unable to fetch products from the store.', 'woocommerce-ai-storefront' ),
				'ucp_internal_error',
				null,
				500
			);
		}

		$wc_products = [];
		if ( $store_status < 400 ) {
			$data = $store_response->get_data();
			// Normalize the entire list payload in one pass. See the
			// docblock on `normalize_store_api_data()` for why this is
			// needed — WC Store API's internal responses use stdClass
			// for nested structures, which the translator can't
			// array-access. json round-trip forces everything to
			// associative arrays recursively.
			$normalized = self::normalize_store_api_data( $data );
			if ( is_array( $normalized ) ) {
				$wc_products = $normalized;
			} else {
				WC_AI_Storefront_Logger::debug(
					'UCP catalog/search: Store API response body could not be normalized (possible plugin conflict)'
				);
			}
		} else {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'UCP catalog/search: Store API returned %d, treating as empty result set',
					$store_status
				)
			);
		}

		// Compute the seller block once per request — it's identical
		// for every product in a single-merchant store, and we don't
		// want to re-read get_bloginfo() / WC()->countries on every
		// product. Built here (not the translator) to keep the
		// translator WP-unaware and testable without WP globals.
		$seller = self::build_seller();

		$products         = [];
		$variant_messages = [];
		foreach ( $wc_products as $wc_product ) {
			if ( ! is_array( $wc_product ) ) {
				continue;
			}
			$variation_fetch = self::fetch_variations_for( $wc_product );
			if ( $variation_fetch['skipped'] > 0 ) {
				$variant_messages[] = self::partial_variants_message(
					(int) ( $wc_product['id'] ?? 0 ),
					$variation_fetch['skipped']
				);
			}
			$products[] = WC_AI_Storefront_UCP_Product_Translator::translate(
				$wc_product,
				$variation_fetch['variations'],
				$seller
			);
		}

		$body = [
			'ucp'      => WC_AI_Storefront_UCP_Envelope::catalog_envelope( $capability ),
			'products' => $products,
		];

		// Pagination state derived from Store API's response headers.
		// The WC Store API emits `X-WP-Total` (matching row count
		// across all pages) and `X-WP-TotalPages` (total page count
		// at the requested per_page size). We translate into the
		// UCP pagination response shape (`cursor`, `has_next_page`,
		// `total_count`) so agents can iterate the catalog without
		// ever seeing the WC-internal page indexing. See
		// `encode_cursor()` for the cursor format contract.
		$body['pagination'] = self::build_pagination_response(
			$store_response,
			(int) ( $store_params['page'] ?? 1 )
		);

		$messages = array_merge( $mapping_messages, $variant_messages );
		if ( ! empty( $messages ) ) {
			$body['messages'] = $messages;
		}

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * Build the UCP pagination response object.
	 *
	 * Maps Store API's page-based response headers to UCP's cursor-
	 * based shape. Emits:
	 *
	 *   - `has_next_page` (always) — whether further pages exist
	 *   - `cursor` (when has_next_page is true) — opaque token
	 *     consumers pass back in `pagination.cursor` on the next
	 *     request to fetch the subsequent page
	 *   - `total_count` (when known) — Store API reports this
	 *     via `X-WP-Total`; we surface it for agents that want to
	 *     show "X of Y results" in the user UI
	 *
	 * Per spec (`types/pagination.json` $defs/response), `cursor`
	 * MUST be present when `has_next_page` is true; we never emit
	 * it on the last page.
	 *
	 * @param \WP_REST_Response $store_response Response from the
	 *                                          internal Store API
	 *                                          dispatch.
	 * @param int               $current_page   Page number we
	 *                                          requested.
	 * @return array<string, mixed>             UCP pagination shape.
	 */
	private static function build_pagination_response( \WP_REST_Response $store_response, int $current_page ): array {
		// Case-insensitive header lookup. `WP_REST_Response::get_headers()`
		// preserves whatever casing the producer used, and while
		// WC Store API currently emits `X-WP-Total` / `X-WP-TotalPages`
		// verbatim, any middleware in the `rest_post_dispatch_*`
		// filter chain that normalizes to lowercase would silently
		// break this pagination: `has_next_page` stuck at false,
		// `total_count` absent, agents stop iterating at page 1.
		// Normalizing the lookup side is cheap insurance.
		$headers = array_change_key_case( $store_response->get_headers(), CASE_LOWER );

		$total_pages = 1;
		if ( isset( $headers['x-wp-totalpages'] ) ) {
			$total_pages = max( 1, (int) $headers['x-wp-totalpages'] );
		} else {
			// A Store API response without this header is a contract
			// anomaly worth surfacing to the debug log (not to the
			// agent — too noisy). If it starts firing in production,
			// a WC update or plugin conflict is the suspect.
			WC_AI_Storefront_Logger::debug(
				'UCP catalog/search: Store API response missing X-WP-TotalPages header — defaulting to single page'
			);
		}

		$has_next = $current_page < $total_pages;

		$pagination = [
			'has_next_page' => $has_next,
		];

		if ( $has_next ) {
			$pagination['cursor'] = self::encode_cursor( $current_page + 1 );
		}

		if ( isset( $headers['x-wp-total'] ) ) {
			$pagination['total_count'] = (int) $headers['x-wp-total'];
		}

		return $pagination;
	}

	/**
	 * Build the `seller` block stamped on every emitted product.
	 *
	 * UCP core's `product.seller` is spec-expected even for single-
	 * merchant stores — strict validators will reject a product
	 * without it. For this plugin (single-merchant by posture), the
	 * seller is the same for every product in a request, so we
	 * compute it once and thread it through the translator rather
	 * than re-reading WP globals per-product.
	 *
	 * Shape:
	 *   - `name`    — `get_bloginfo('name')` stripped + entity-decoded
	 *                  (same normalization the JSON-LD @graph uses so
	 *                  the two surfaces agree on merchant display name)
	 *   - `country` — ISO 3166-1 alpha-2 from WC's base country, or
	 *                  omitted when not configured. Mirrors the
	 *                  store_context.country logic exactly.
	 *
	 * Why not add an `id` — the UCP core shape allows seller.id but
	 * we have no namespace-stable seller identifier (site URL could
	 * work but changes with migrations; plugin is single-merchant
	 * anyway so a distinguishing ID adds cost without value). If
	 * this plugin ever grows multi-vendor support, seller.id becomes
	 * required and the per-request compute-once pattern here
	 * becomes per-product.
	 *
	 * @return array<string, string>
	 */
	private static function build_seller(): array {
		$seller = [
			'name' => html_entity_decode(
				wp_strip_all_tags( get_bloginfo( 'name' ) ),
				ENT_QUOTES,
				'UTF-8'
			),
		];

		// `countries` is a PROPERTY on the WooCommerce singleton (an
		// instance of WC_Countries), not a method — `method_exists`
		// would always return false here. Guard via `isset()` on the
		// property so we correctly pick up the country when WC is
		// fully loaded, and fall through gracefully when it isn't
		// (tests, early-boot paths, WC plugin-deactivated state).
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( $woocommerce && isset( $woocommerce->countries ) && is_object( $woocommerce->countries ) ) {
			$country = $woocommerce->countries->get_base_country();
			if ( $country ) {
				$seller['country'] = $country;
			}
		}

		return $seller;
	}

	/**
	 * Format `signals` keys for a debug-log line safely.
	 *
	 * Signal keys are request-supplied and therefore untrusted. Logging
	 * them verbatim (via `implode(',', array_keys($signals))`) risks:
	 *   - Log injection via embedded newlines or control chars — an
	 *     attacker could splice fake log lines into the stream.
	 *   - Oversized log lines if keys are very long, or if the signals
	 *     map has thousands of entries.
	 *
	 * This helper:
	 *   - Drops non-string keys (map-with-numeric-index is illegal per
	 *     UCP spec's reverse-domain naming rule, so a numeric key is a
	 *     malformed payload signal anyway).
	 *   - Strips control characters (ASCII 0–31 + 127) and Unicode
	 *     line-separator code points (U+0085, U+2028, U+2029, U+FEFF)
	 *     from each key.
	 *   - Truncates each key to 100 chars, then appends a single
	 *     `…` ellipsis marker (101 chars total) so truncation is
	 *     visible in the log. The marker is intentionally outside
	 *     the 100-char cap — the cap bounds the untrusted content;
	 *     the single extra glyph is controlled by us.
	 *   - Caps the total number of keys logged at 32; past that we
	 *     append an overflow sigil rather than flood the log.
	 *
	 * @param array<mixed, mixed> $signals
	 * @return string Comma-joined, bounded, sanitized signal-key list.
	 */
	private static function format_signal_keys_for_log( array $signals ): string {
		$max_keys      = 32;
		$max_key_chars = 100;

		// Two counts matter: how many keys we actually LOG (capped at
		// $max_keys) and how many keys were ELIGIBLE after
		// sanitization (the universe we're truncating from). The
		// overflow sigil must be derived from the latter — basing it
		// on count($signals) miscounts when the payload contains
		// non-string or empty-after-sanitization keys, producing
		// misleading "(+N more)" figures that don't match what was
		// actually truncated.
		$logged   = [];
		$eligible = 0;
		foreach ( array_keys( $signals ) as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			// Strip log-breaking characters. Two classes:
			//   1. ASCII control (0-31 + 127) — classic log injection.
			//   2. Unicode line-break code points missed by #1:
			//      - U+0085 (NEL, Next Line)
			//      - U+2028 (LINE SEPARATOR)
			//      - U+2029 (PARAGRAPH SEPARATOR)
			//      - U+FEFF (BOM, inserted at arbitrary positions
			//        confuses log-line parsers that heuristically
			//        split on line-start byte sequences).
			// systemd-journal and many SIEMs treat the Unicode
			// separators as logical line breaks, so the ASCII-only
			// blocklist (previous version) still let agents splice
			// forged entries.
			$sanitized = preg_replace(
				'/[\x00-\x1F\x7F]|\xc2\x85|\xe2\x80[\xa8\xa9]|\xef\xbb\xbf/u',
				'',
				$key
			);
			if ( null === $sanitized || '' === $sanitized ) {
				continue;
			}
			++$eligible;

			// Keep iterating past the log cap so the overflow sigil
			// reflects the true eligible count, not just what fit in
			// the capped set — but don't keep sanitized strings we
			// won't emit (bounded memory).
			if ( count( $logged ) >= $max_keys ) {
				continue;
			}
			// Multibyte-aware truncate — byte-based `substr` would
			// chop a UTF-8 sequence mid-byte and emit invalid bytes
			// into the log stream, corrupting downstream parsers.
			$needs_truncate = function_exists( 'mb_strlen' )
				? mb_strlen( $sanitized ) > $max_key_chars
				: strlen( $sanitized ) > $max_key_chars;
			if ( $needs_truncate ) {
				$sanitized = function_exists( 'mb_substr' )
					? mb_substr( $sanitized, 0, $max_key_chars ) . '…'
					: substr( $sanitized, 0, $max_key_chars ) . '…';
			}
			$logged[] = $sanitized;
		}

		// Explicit placeholder when every key was filtered out (e.g.
		// agent sent a numeric-indexed list). Prevents confusing
		// output like "" or " (+N more)" with no leading keys — the
		// log line reads clearly as "no valid keys" instead.
		if ( empty( $logged ) ) {
			return '(none)';
		}

		$out = implode( ',', $logged );
		if ( $eligible > $max_keys ) {
			$out .= sprintf( ' (+%d more)', $eligible - $max_keys );
		}
		return $out;
	}

	/**
	 * Encode a Store API page number as an opaque UCP cursor.
	 *
	 * Format: base64 of `p<page_number>`. The `p` prefix reserves
	 * the cursor namespace so future implementation changes (e.g.
	 * switching from page-based to keyset pagination) can use
	 * different prefixes without ambiguity. Consumers treat the
	 * cursor as opaque and never parse it.
	 *
	 * Base64 is used (not raw page numbers) to discourage agents
	 * from trying to predict/fabricate cursors — they're expected
	 * to use cursors returned from prior responses. A naive agent
	 * guessing `cursor: "2"` gets a decode failure and falls to
	 * page 1 gracefully; a well-behaved agent always round-trips
	 * what the server gave it.
	 *
	 * @param int $page Page number (1-indexed).
	 * @return string   Opaque cursor string.
	 */
	private static function encode_cursor( int $page ): string {
		return base64_encode( 'p' . $page ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Not obfuscation; opaque-cursor encoding.
	}

	/**
	 * Decode a UCP cursor string back to a Store API page number.
	 *
	 * Returns null on any malformed input — the caller falls back
	 * to page 1. We accept malformed cursors silently because
	 * catalog mutation between agent calls can invalidate previously
	 * emitted cursors (e.g. products added → page counts shift),
	 * and surfacing this as an error would make pagination brittle.
	 *
	 * @param string $cursor The opaque cursor string.
	 * @return int|null      Decoded page number (≥1), or null if
	 *                       the cursor is malformed.
	 */
	private static function decode_cursor( string $cursor ): ?int {
		$decoded = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Not obfuscation; opaque-cursor decoding.
		if ( false === $decoded ) {
			return null;
		}
		if ( 0 !== strpos( $decoded, 'p' ) ) {
			return null;
		}
		$page = (int) substr( $decoded, 1 );

		// Sanity bounds on the decoded page number. Page must be
		// positive (zero or negative are forged inputs) AND below an
		// upper limit that prevents `WP_Query` OFFSET overflow on
		// 32-bit MySQL compilations. A forged `pPHP_INT_MAX` cursor
		// would otherwise compute OFFSET = (PHP_INT_MAX-1) * per_page
		// → integer wraparound → negative offset → SQL error on some
		// MySQL versions. 100,000 pages at the max limit of 100 is
		// already 10M products; no real commerce catalog needs more.
		if ( $page < 1 || $page > 100000 ) {
			return null;
		}
		return $page;
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
		$capability = 'dev.ucp.shopping.catalog.lookup';

		// Clear per-request memoization; see handle_catalog_search.
		self::reset_request_cache();

		if ( self::is_syndication_disabled() ) {
			WC_AI_Storefront_Logger::debug( 'UCP catalog/lookup rejected: syndication disabled' );
			return self::ucp_catalog_error_response(
				$capability,
				__( 'AI Storefront is not currently enabled on this store.', 'woocommerce-ai-storefront' ),
				'ucp_disabled',
				null,
				503
			);
		}

		// Signals parity with catalog.search — log for observability, no
		// trust decisions yet. See handle_catalog_search for the
		// rationale on deferring trust-model work until there's a
		// verified platform source. Same `is_enabled()` guard as
		// search — keeps the sanitization walk off the hot path
		// when debug logging is off.
		$signals = $request->get_param( 'signals' );
		if ( is_array( $signals ) && ! empty( $signals ) && WC_AI_Storefront_Logger::is_enabled() ) {
			WC_AI_Storefront_Logger::debug(
				'UCP catalog/lookup: received signals (not honored): '
				. self::format_signal_keys_for_log( $signals )
			);
		}

		$ids = $request->get_param( 'ids' );

		if ( ! is_array( $ids ) ) {
			WC_AI_Storefront_Logger::debug( 'UCP catalog/lookup rejected: "ids" is not an array' );
			return self::ucp_catalog_error_response(
				$capability,
				__( 'Request body must include an "ids" array.', 'woocommerce-ai-storefront' ),
				'invalid_input',
				'$.ids'
			);
		}

		if ( empty( $ids ) ) {
			WC_AI_Storefront_Logger::debug( 'UCP catalog/lookup rejected: empty "ids" array' );
			return self::ucp_catalog_error_response(
				$capability,
				__( 'The "ids" array must contain at least one ID.', 'woocommerce-ai-storefront' ),
				'invalid_input',
				'$.ids'
			);
		}

		if ( count( $ids ) > self::MAX_IDS_PER_LOOKUP ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'UCP catalog/lookup rejected: "ids" array size %d exceeds MAX_IDS_PER_LOOKUP %d',
					count( $ids ),
					self::MAX_IDS_PER_LOOKUP
				)
			);
			return self::ucp_catalog_error_response(
				$capability,
				sprintf(
					/* translators: %d is the maximum number of IDs per request. */
					__( 'The "ids" array exceeds the per-request limit of %d entries.', 'woocommerce-ai-storefront' ),
					self::MAX_IDS_PER_LOOKUP
				),
				'invalid_input',
				'$.ids'
			);
		}

		$products = [];
		$messages = [];

		// Deduplicate + normalize before fetching. `wc_ids` is the
		// work list (one per unique input, aligned positionally
		// with `inputs`); `inputs` is the echo array we return to
		// the agent so they can reconcile what they sent against
		// what we processed. See `normalize_and_dedupe_lookup_ids`
		// for the dedup semantics.
		$normalized = self::normalize_and_dedupe_lookup_ids( $ids );
		$inputs     = $normalized['inputs'];
		$wc_ids     = $normalized['wc_ids'];

		// Same single-merchant seller block as the search handler —
		// computed once, stamped on every product (see handle_catalog_search).
		$seller = self::build_seller();

		foreach ( $wc_ids as $index => $wc_id ) {
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
			// response so the product translator can emit one real variant
			// per variation rather than a synthesized default. When any
			// variations fail to fetch, we still render the product (partial
			// set beats synthesized fallback) but emit a `partial_variants`
			// warning so agents know the variant list is incomplete.
			$variation_fetch = self::fetch_variations_for( $wc_product );
			if ( $variation_fetch['skipped'] > 0 ) {
				$messages[] = self::partial_variants_message(
					(int) ( $wc_product['id'] ?? 0 ),
					$variation_fetch['skipped']
				);
			}

			$products[] = WC_AI_Storefront_UCP_Product_Translator::translate(
				$wc_product,
				$variation_fetch['variations'],
				$seller
			);
		}

		$response_body = [
			'ucp'      => WC_AI_Storefront_UCP_Envelope::catalog_envelope( $capability ),
			'inputs'   => $inputs,
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
	 *   - grouped / external / subscription / subscription_variation
	 *                                → rejected (product_type_unsupported)
	 *   - unknown ID                 → rejected (not_found)
	 *   - out of stock               → rejected (out_of_stock); WC's
	 *                                  `is_in_stock` already factors the
	 *                                  merchant's backorder settings, so
	 *                                  false means WooCommerce itself
	 *                                  has concluded the item is not
	 *                                  purchasable right now
	 *   - invalid shape (item not an array, id missing/non-string)
	 *                                → rejected (invalid_line_item)
	 *   - invalid quantity (≤0 or > MAX_QUANTITY_PER_LINE_ITEM)
	 *                                → rejected (invalid_quantity)
	 *
	 * Response status (UCP 2026-04-08 enum: incomplete |
	 * requires_escalation | ready_for_complete | complete_in_progress
	 * | completed | canceled):
	 *   - any valid items + eligible for redirect → 201 with
	 *     status=requires_escalation + continue_url
	 *   - all items fail                          → 200 with
	 *     status=incomplete, no continue_url, messages explain
	 *     each failure
	 *   - valid items but minimum-order filter blocks → 200 with
	 *     status=incomplete, no continue_url, `minimum_not_met`
	 *     message. Line items + subtotal are still echoed so agents
	 *     can show the user the gap to the threshold.
	 *
	 * Legal links: `links` is mandatory per UCP schema. We emit what's
	 * configured via get_privacy_policy_url() + wc_get_page_permalink('terms'),
	 * with advisory warnings for any page the merchant hasn't set up.
	 *
	 * Totals: emits both `subtotal` AND `total` entries per UCP spec
	 * (minContains:1, maxContains:1 each). With our web-redirect
	 * stance `total` equals `subtotal` and a `total_is_provisional`
	 * info-message explains that tax + shipping are calculated at
	 * merchant checkout.
	 *
	 * @param WP_REST_Request $request UCP checkout-sessions create request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_checkout_sessions_create( WP_REST_Request $request ) {
		// Clear per-request memoization; see handle_catalog_search.
		self::reset_request_cache();

		if ( self::is_syndication_disabled() ) {
			WC_AI_Storefront_Logger::debug( 'UCP checkout-sessions rejected: syndication disabled' );
			return self::ucp_checkout_error_response(
				__( 'AI Storefront is not currently enabled on this store.', 'woocommerce-ai-storefront' ),
				'ucp_disabled',
				null,
				503
			);
		}

		$line_items_raw = $request->get_param( 'line_items' );

		if ( ! is_array( $line_items_raw ) || empty( $line_items_raw ) ) {
			return self::ucp_checkout_error_response(
				__( 'Request must include a non-empty "line_items" array.', 'woocommerce-ai-storefront' ),
				'invalid_input',
				'$.line_items'
			);
		}

		if ( count( $line_items_raw ) > self::MAX_LINE_ITEMS_PER_CHECKOUT ) {
			return self::ucp_checkout_error_response(
				sprintf(
					/* translators: %d is the maximum number of line items per request. */
					__( 'The "line_items" array exceeds the per-request limit of %d entries.', 'woocommerce-ai-storefront' ),
					self::MAX_LINE_ITEMS_PER_CHECKOUT
				),
				'invalid_input',
				'$.line_items'
			);
		}

		$agent_name = self::resolve_agent_host( $request );
		$currency   = function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency()
			: 'USD';

		// Locale extraction — UCP `context.locale` is the agent's hint
		// about the buyer's language. Validate as BCP-47-ish: a
		// leading alphabetic language subtag (2–8 letters) followed
		// by optional hyphen/underscore-separated alphanumeric subtags
		// (1–8 chars each), with a conservative 35-char overall cap
		// to limit DoS-via-oversized-input. Covers common tags like
		// `en`, `en-US`, `zh-Hant-HK`, and extended ones like
		// `en-GB-oxendict` while still rejecting free-form text or
		// shell-injection payloads. Empty string when absent or
		// malformed — the handoff filter still runs with store-default
		// locale.
		$context        = $request->get_param( 'context' );
		$request_locale = '';
		if ( is_array( $context ) && isset( $context['locale'] ) && is_string( $context['locale'] ) ) {
			$candidate = trim( $context['locale'] );
			if (
				strlen( $candidate ) <= 35
				&& preg_match( '/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $candidate )
			) {
				$request_locale = $candidate;
			}
		}

		$processed = [];
		$messages  = [];

		foreach ( $line_items_raw as $index => $line_item ) {
			$outcome = self::process_line_item( $line_item, (int) $index, $currency );

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

		// Tax-inclusive vs exclusive disclosure per line. WC's
		// configured setting governs whether `prices.price` already
		// includes tax (common in EU stores) or is pre-tax (typical
		// US). The store_context in the manifest carries the same
		// boolean for upfront disclosure, but echoing it per-line
		// removes ambiguity when agents render the cart — no need to
		// cross-reference the manifest to interpret the number.
		$prices_include_tax = function_exists( 'wc_prices_include_tax' )
			? (bool) wc_prices_include_tax()
			: false;

		// Build response line_items AND compute the subtotal in a
		// single pass. Single-source so the min-order check, the
		// response `totals`, and the line-level display numbers all
		// agree. Items that failed validation are NOT in line_items
		// — they appear only via messages pointing at their original
		// request index.
		$response_line_items = [];
		$subtotal_amount     = 0;
		foreach ( $processed as $p ) {
			$line_total            = $p['unit_price_minor'] * $p['quantity'];
			$subtotal_amount      += $line_total;
			$response_line_items[] = [
				'item'               => [ 'id' => $p['ucp_id'] ],
				'quantity'           => $p['quantity'],
				'unit_price'         => [
					'amount'   => $p['unit_price_minor'],
					'currency' => $currency,
				],
				'line_total'         => [
					'amount'   => $line_total,
					'currency' => $currency,
				],
				'price_includes_tax' => $prices_include_tax,
			];
		}

		// Redirect eligibility — starts as "has valid items" but the
		// minimum-order filter (below) can flip it off. Kept separate
		// from `$has_valid_items` because the two concepts are distinct:
		//   - $has_valid_items: "at least one line item survived validation"
		//   - $should_redirect: "we can issue a continue_url AND mark
		//     the session as requires_escalation"
		// An agent whose cart has valid items but falls below the
		// merchant minimum has `has_valid_items=true` (the line items
		// + subtotal are still echoed so they can show the user the
		// gap) but `should_redirect=false` (no continue_url, status
		// incomplete).
		$should_redirect = $has_valid_items;

		// Minimum-order enforcement. WC core doesn't ship a
		// minimum-order-amount setting natively (it's usually
		// plugin territory or a theme convention), so we gate via a
		// filter hook rather than an admin UI: merchants who want
		// enforcement return the minor-unit minimum from
		// `wc_ai_storefront_minimum_order_amount`. Zero (default)
		// means no minimum, matching existing behavior.
		//
		// When the subtotal doesn't meet the minimum we leave the
		// cart visible but flip `$should_redirect` off — surfacing
		// the gap upfront with an actionable message beats
		// redirecting the user to a checkout they can't complete.
		$minimum_order_amount = (int) apply_filters(
			'wc_ai_storefront_minimum_order_amount',
			0,
			[
				'subtotal_minor' => $subtotal_amount,
				'currency'       => $currency,
				'agent'          => $agent_name,
				'line_items'     => $processed,
			]
		);
		if ( $has_valid_items && $minimum_order_amount > 0 && $subtotal_amount < $minimum_order_amount ) {
			$messages[]      = [
				'type'     => 'error',
				'code'     => 'minimum_not_met',
				// `requires_buyer_input`, not `unrecoverable`: the
				// message instructs the buyer to "add more items to
				// proceed" — it's a fixable condition, parallel to
				// `buyer_handoff_required`. Using unrecoverable would
				// mislead agents into treating this as a terminal
				// failure and abandoning the cart.
				'severity' => 'requires_buyer_input',
				'path'     => '$.line_items',
				'content'  => sprintf(
					/* translators: 1: current subtotal (minor units), 2: minimum order (minor units). */
					__( 'Order subtotal %1$d is below the merchant minimum of %2$d (minor units). Add more items to proceed.', 'woocommerce-ai-storefront' ),
					$subtotal_amount,
					$minimum_order_amount
				),
			];
			$should_redirect = false;
		}

		$continue_url = $should_redirect
			? self::build_continue_url( $processed, $agent_name )
			: '';

		if ( $should_redirect ) {
			// Buyer handoff message accompanies every redirect. Agents
			// surface the `content` to the user verbatim before linking
			// out, so the phrasing matters — keep it short and neutral.
			// Filter hook lets merchants override (e.g. "Review and
			// secure payment at Acme Store") without an admin UI; the
			// default is intentionally generic.
			$default_handoff = __( 'Complete your purchase on the merchant site.', 'woocommerce-ai-storefront' );
			$handoff_content = apply_filters(
				'wc_ai_storefront_checkout_handoff_message',
				$default_handoff,
				[
					'line_items' => $processed,
					'agent'      => $agent_name,
					'locale'     => $request_locale,
				]
			);
			// Notice-free coercion. A third-party filter returning an
			// array/object would trigger an "Array to string conversion"
			// PHP notice on `(string) $handoff_content`. Only accept
			// string returns; fall back to the default for anything
			// else. The filter docblock documents the string contract,
			// so this is a defense against misbehaving callbacks, not
			// a supported alternative return type.
			if ( ! is_string( $handoff_content ) ) {
				$handoff_content = $default_handoff;
			}
			$messages[] = [
				'type'     => 'error',
				'code'     => 'buyer_handoff_required',
				'severity' => 'requires_buyer_input',
				'content'  => $handoff_content,
			];

			// `total_is_provisional` — UCP spec requires a `total`
			// entry in `totals` (see below). With our web-redirect
			// stance we can't compute real tax/shipping server-side
			// (those require an address + shipping-method selection
			// that only happens at the merchant checkout). Emit an
			// info-message alongside `total: subtotal` so agents
			// can disclose the caveat to the user before the redirect.
			$messages[] = [
				'type'     => 'info',
				'code'     => 'total_is_provisional',
				'severity' => 'advisory',
				'content'  => __( 'Total excludes tax and shipping, which are calculated at the merchant checkout.', 'woocommerce-ai-storefront' ),
			];
		}

		// UCP 2026-04-08 `totals` schema requires exactly one `subtotal`
		// AND exactly one `total` entry (both minContains:1,
		// maxContains:1). With our web-redirect stance we can't compute
		// real tax/shipping, so `total` equals `subtotal` on the happy
		// path and is 0 when no items validated. The accompanying
		// `total_is_provisional` info-message (emitted above when
		// should_redirect) explains the elision.
		$response_totals = [
			[
				'type'   => 'subtotal',
				'amount' => $subtotal_amount,
			],
			[
				'type'   => 'total',
				'amount' => $subtotal_amount,
			],
		];

		// Status: `requires_escalation` when we have something to
		// escalate to. Otherwise `incomplete` — spec enum value that
		// most closely maps to "session awaiting valid input" (the
		// pre-spec-check `error` wasn't in the status enum).
		// `$should_redirect` is false when either (a) no items
		// validated, or (b) valid items but below the merchant minimum.
		$response_body = [
			'ucp'        => WC_AI_Storefront_UCP_Envelope::checkout_envelope(),
			'id'         => 'chk_' . bin2hex( random_bytes( 8 ) ),
			'status'     => $should_redirect ? 'requires_escalation' : 'incomplete',
			'currency'   => $currency,
			'line_items' => $response_line_items,
			'totals'     => $response_totals,
			'links'      => $links,

			// Explicit `null` — not omission — because the UCP spec
			// carries an optional `session.expires_at` field. Omitting
			// it could be misread as either "I don't know the TTL" or
			// "there's a bug here"; emitting `null` is the correct
			// semantic for "this session has no TTL because it's
			// stateless — no server-side state exists to expire".
			// Every checkout-sessions POST is a fresh computation
			// with no persistence, so there's no session lifetime to
			// advertise. Strict UCP consumers key on the field's
			// presence to distinguish stateless from stateful
			// implementations.
			'expires_at' => null,
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
			$should_redirect ? 201 : 200
		);
	}

	/**
	 * Handler for GET /extension/schema.
	 *
	 * Serves the JSON Schema describing the shape of our merchant
	 * extension capability (`com.woocommerce.ai_storefront`). The
	 * manifest's extension capability advertises this endpoint under
	 * its `schema` field; agents that want to validate our
	 * extension-specific payload fetch it here rather than hardcoding
	 * the shape.
	 *
	 * Self-hosted by design: the schema served matches the version of
	 * the plugin the merchant is running (no risk of schema/runtime
	 * drift from a third-party registry), and honors whatever access
	 * controls the site has on the REST API (same permissions as the
	 * catalog/checkout endpoints). `$id` is computed from the current
	 * URL so a static mirror of the schema keeps the right self-reference.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_extension_schema(): WP_REST_Response {
		$self_id = rest_url( self::NAMESPACE . '/extension/schema' );

		$schema = [
			'$schema'     => 'https://json-schema.org/draft/2020-12/schema',
			'$id'         => $self_id,
			'title'       => 'WooCommerce AI Storefront UCP Extension Contract',
			'description' => 'Schema for the `com.woocommerce.ai_storefront` extension contract. The top-level `config` property describes the merchant-extension configuration advertised in the UCP manifest at `capabilities[com.woocommerce.ai_storefront][0].config`. Starting 2.0.0, no response-level payloads are emitted under this extension — rating data moved to core `product.rating` per the UCP 2026-04-08 product shape.',
			'type'        => 'object',
			'properties'  => [
				'config'                  => [
					'type'        => 'object',
					'description' => 'Merchant-extension configuration advertised at `capabilities[com.woocommerce.ai_storefront][0].config` in the UCP manifest.',
					'properties'  => [
						'store_context' => [
							'type'        => 'object',
							'description' => 'Commerce conventions this store operates under — agents pre-filter based on these before calling catalog/checkout.',
							'properties'  => [
								'currency'           => [
									'type'        => 'string',
									'description' => 'ISO 4217 currency code. Catalog prices quote in this currency; agents unable to transact here should decline rather than misrepresent the amount.',
									'examples'    => [ 'USD', 'EUR', 'JPY' ],
								],
								'locale'             => [
									'type'        => 'string',
									'description' => 'BCP 47 locale tag for default customer-facing content language.',
									'examples'    => [ 'en-US', 'fr-FR', 'zh-Hant-HK' ],
								],
								'country'            => [
									'type'        => [ 'string', 'null' ],
									'description' => 'ISO 3166-1 alpha-2 for the merchant base country. Nullable when the merchant has not configured a base country in WC settings.',
								],
								'prices_include_tax' => [
									'type'        => 'boolean',
									'description' => 'When true (EU-typical), catalog prices are tax-inclusive. When false (US-typical), tax is added at checkout. Agents rendering cart previews use this to decide whether to show a tax line.',
								],
								'shipping_enabled'   => [
									'type'        => 'boolean',
									'description' => 'When true, the store collects shipping addresses. When false, it is digital-only — agents should skip address-collection prompts.',
								],
							],
						],
					],
				],
				'accepted_request_inputs' => [
					'type'        => 'object',
					'description' => 'Documents the extension-side request-input surface on `POST /catalog/search` and `POST /catalog/lookup` — not a full enumeration of every accepted field. Covers: (a) UCP-spec-standard objects this implementation explicitly accepts and acts on (`context`, `signals`), and (b) merchant-specific extensions (`custom_filters`). Spec-standard fields like `query`, `filters.price`, `filters.categories`, `pagination`, `sort`, and `ids` are documented by the UCP core spec itself and are not repeated here. The `custom_filters` sub-tree exists per the UCP spec hint that merchants "MAY support additional custom filters via additionalProperties".',
					'properties'  => [
						'context'        => [
							'type'        => 'object',
							'description' => 'Spec-standard `context` object is accepted. Currently honored field: `context.currency` — when set and matching store currency, `filters.price` is applied; when mismatched, the price filter is dropped and a `currency_conversion_unsupported` warning is emitted (we don\'t carry FX rates). Other `context` fields (`address_country`, `address_region`, `postal_code`) are accepted but not yet acted upon; agents MAY send them today for forward compatibility.',
						],
						'signals'        => [
							'type'        => 'object',
							'description' => 'Spec-standard `signals` object is accepted and logged for observability. No values are used for decisions at this time; the plugin complies with the spec\'s "MUST NOT treat buyer claims as signals" rule by not acting on any signal. Known-valuable future wiring: `dev.ucp.buyer_ip` for per-end-buyer rate limiting (currently we rate-limit by request IP, which conflates agent-platform traffic).',
						],
						'custom_filters' => [
							'type'        => 'object',
							'description' => 'Custom filters via `additionalProperties` — accepted on `filters{}` in `/catalog/search` only. (`/catalog/lookup` reads only `ids` + `signals`; filters are ignored there because lookup resolves by explicit ID.) Unresolvable values on search emit `*_not_found` advisory warnings with JSONPath.',
							'properties'  => [
								'brand'      => [
									'type'        => 'array',
									'description' => 'Array of brand names or slugs. Resolves against the native WC 9.5+ `product_brand` taxonomy. Multiple values OR together.',
									'items'       => [ 'type' => 'string' ],
								],
								'tags'       => [
									'type'        => 'array',
									'description' => 'Array of tag names or slugs. Resolves against `product_tag`. Multiple values OR together.',
									'items'       => [ 'type' => 'string' ],
								],
								'in_stock'   => [
									'type'        => 'boolean',
									'description' => 'When true, restrict results to products currently in stock.',
								],
								'featured'   => [
									'type'        => 'boolean',
									'description' => 'When true, restrict to merchant-flagged featured products.',
								],
								'min_rating' => [
									'type'        => 'integer',
									'description' => 'Integer 1-5; restrict to products with average rating ≥ this value.',
									'minimum'     => 1,
									'maximum'     => 5,
								],
								'on_sale'    => [
									'type'        => 'boolean',
									'description' => 'When true, restrict to products with an active sale price.',
								],
								'attributes' => [
									'type'                 => 'object',
									'description'          => 'Keyed map of attribute slug → array of values (e.g. `{"color": ["red", "blue"]}`). Resolves against WC `pa_*` taxonomies; `pa_` prefix is auto-added if missing. Unresolvable attribute taxonomies emit `attribute_not_found` warnings with JSONPath.',
									'additionalProperties' => [
										'type'  => 'array',
										'items' => [ 'type' => 'string' ],
									],
								],
							],
						],
					],
				],
			],
		];

		// Note: the `ratings` property previously documented here was
		// removed in 2.0.0. Rating + review count now emit under core
		// `product.rating` directly — agents should read the UCP core
		// product schema for that shape rather than this extension
		// schema. Keeping the extension capability around for forward-
		// compat on `store_context` + any future merchant-specific
		// config, but it currently documents no response-level payloads.

		$response = new WP_REST_Response( $schema, 200 );
		$response->header( 'Content-Type', 'application/schema+json; charset=utf-8' );
		// Schema is immutable per plugin version; safe to cache.
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		// `$id` (set in $schema) derives from `rest_url()` which
		// uses the incoming `Host` header. If a shared cache keys
		// only on path (common misconfiguration) an attacker can
		// prime the cache with a forged Host: attacker.example
		// response whose `$id` points to their domain, then
		// legitimate clients that fetch the schema get an attacker-
		// controlled canonical URL. `Vary: Host` forces the cache
		// to key on Host too, defanging the poisoning vector even
		// when the CDN config is otherwise wrong.
		$response->header( 'Vary', 'Host' );

		return $response;
	}

	// ------------------------------------------------------------------
	// Shared helpers (used by all three handlers)
	// ------------------------------------------------------------------

	/**
	 * True when the merchant has paused syndication via the admin UI.
	 *
	 * Routes are registered unconditionally on `rest_api_init` so the
	 * rewrite-rule state stays stable across enable/disable toggles.
	 * Gating lives inside each handler: they check this first, and if
	 * disabled, return a UCP-envelope-shaped error via
	 * `ucp_catalog_error_response()` / `ucp_checkout_error_response()`.
	 *
	 * The routes still dispatch to the WooCommerce Store API at its
	 * own path when disabled — merchants who pause AI Syndication do
	 * not lose their regular Store API access, only the UCP wrapper.
	 */
	private static function is_syndication_disabled(): bool {
		$settings = WC_AI_Storefront::get_settings();
		return 'yes' !== ( $settings['enabled'] ?? 'no' );
	}

	/**
	 * Build a catalog response body carrying a single UCP error message.
	 *
	 * Use for any failure on /catalog/search or /catalog/lookup that
	 * needs a UCP-envelope shape (validation errors, disabled-state
	 * rejections, upstream Store API failures). Returning a
	 * `WP_REST_Response` instead of a `WP_Error` means agents see the
	 * same envelope shape regardless of success vs. failure — strict
	 * UCP clients can parse both without a shape-switch.
	 *
	 * `products: []` is always present because the catalog response
	 * schema requires it. `messages` carries the single error describing
	 * what went wrong.
	 *
	 * @param string  $capability_key e.g. 'dev.ucp.shopping.catalog.search'
	 * @param string  $content        Human-readable error detail.
	 * @param string  $code           UCP error code (default: 'invalid_input').
	 * @param ?string $path           Optional JSONPath locator into the request body.
	 * @param int     $status         HTTP status code (default: 400).
	 */
	private static function ucp_catalog_error_response(
		string $capability_key,
		string $content,
		string $code = 'invalid_input',
		?string $path = null,
		int $status = 400
	): WP_REST_Response {
		$message = [
			'type'     => 'error',
			'code'     => $code,
			'severity' => 'unrecoverable',
			'content'  => $content,
		];
		if ( null !== $path ) {
			$message['path'] = $path;
		}

		return new WP_REST_Response(
			[
				'ucp'      => WC_AI_Storefront_UCP_Envelope::catalog_envelope( $capability_key ),
				'products' => [],
				'messages' => [ $message ],
			],
			$status
		);
	}

	/**
	 * Build a checkout response body carrying a single UCP error message.
	 *
	 * Same rationale as `ucp_catalog_error_response()`, but populating
	 * all required fields of the UCP checkout response schema:
	 *   - `ucp` envelope (version + capabilities + payment_handlers)
	 *   - `id` (fresh `chk_` correlation token; each error response
	 *     gets a unique ID even though it's a terminal response)
	 *   - `status` = 'incomplete' (UCP 2026-04-08 spec enum value
	 *     for "validation failed, no session to escalate to")
	 *   - `currency` (merchant's WC currency, fallback 'USD')
	 *   - `line_items` (empty array)
	 *   - `totals` (zeroed `subtotal` AND zeroed `total` — spec
	 *     requires both entries, `minContains:1, maxContains:1`)
	 *   - `links` (empty array)
	 *   - `messages` (the single error)
	 *
	 * A validation failure still produces a schema-conformant body
	 * — strict UCP clients can parse success and failure through
	 * the same pipeline.
	 */
	private static function ucp_checkout_error_response(
		string $content,
		string $code = 'invalid_input',
		?string $path = null,
		int $status = 400
	): WP_REST_Response {
		$message = [
			'type'     => 'error',
			'code'     => $code,
			'severity' => 'unrecoverable',
			'content'  => $content,
		];
		if ( null !== $path ) {
			$message['path'] = $path;
		}

		$currency = function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency()
			: 'USD';

		// UCP 2026-04-08 compliance:
		//   - `totals` MUST contain exactly one `subtotal` AND one `total`
		//     entry (both minContains:1, maxContains:1). Zero-amount on
		//     the error path; the accompanying message carries the
		//     semantic "nothing was processed."
		//   - `status` enum: `incomplete | requires_escalation |
		//     ready_for_complete | complete_in_progress | completed |
		//     canceled`. `incomplete` is the closest match for
		//     "validation failed, no session to escalate to."
		return new WP_REST_Response(
			[
				'ucp'        => WC_AI_Storefront_UCP_Envelope::checkout_envelope(),
				'id'         => 'chk_' . bin2hex( random_bytes( 8 ) ),
				'status'     => 'incomplete',
				'currency'   => $currency,
				'line_items' => [],
				'totals'     => [
					[
						'type'   => 'subtotal',
						'amount' => 0,
					],
					[
						'type'   => 'total',
						'amount' => 0,
					],
				],
				'links'      => [],
				'messages'   => [ $message ],
			],
			$status
		);
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

		// Prefix strings live on the translator classes as constants
		// (PRODUCT_ID_PREFIX, VARIANT_ID_PREFIX). Building the regex
		// from those constants prevents drift if either is ever
		// renamed — the parse logic and the emit logic stay in sync
		// through one shared source of truth.
		$prefix_re = sprintf(
			'/^(%s|%s)/',
			preg_quote( WC_AI_Storefront_UCP_Product_Translator::PRODUCT_ID_PREFIX, '/' ),
			preg_quote( WC_AI_Storefront_UCP_Variant_Translator::VARIANT_ID_PREFIX, '/' )
		);

		$stripped = preg_replace( $prefix_re, '', $raw_id );
		return (int) $stripped;
	}

	/**
	 * Per-request memoization cache for fetch_store_api_product.
	 *
	 * Same WC product ID can be requested multiple times within
	 * one UCP request — e.g. `catalog/lookup` with duplicate IDs
	 * pre-dedup, or a parent + its variations where the Store API
	 * index fetches the parent separately from fetch_variations_for.
	 * Each call dispatches an internal `rest_do_request` that runs
	 * the Store API filter chain, so without memoization a hostile
	 * `ids: ["prod_1" × 100]` payload becomes 100 full dispatches.
	 *
	 * Scope is per-request: reset by `reset_request_cache()` on
	 * each handler entry. Keyed on int WC id. Null result for
	 * "not found" is also cached (via the distinct
	 * `$request_cache_has_key`) so repeated 404 lookups don't
	 * re-dispatch.
	 *
	 * @var array<int, ?array<string, mixed>>
	 */
	private static array $request_product_cache = [];

	/**
	 * Tracks which keys have been resolved this request (including
	 * null-resolving ones). Separates "cache hit with null" from
	 * "cache miss" — plain `isset($cache[$id])` would false-negative
	 * on cached 404s and bypass the memoization.
	 *
	 * @var array<int, bool>
	 */
	private static array $request_product_cache_has_key = [];

	/**
	 * Clear the per-request product cache. Invoked at the top of
	 * each public handler so caches don't leak between requests
	 * (WordPress REST framework may or may not spin a fresh class
	 * instance; the static-state model requires explicit reset to
	 * be safe under either dispatch model).
	 */
	private static function reset_request_cache(): void {
		self::$request_product_cache         = [];
		self::$request_product_cache_has_key = [];
	}

	/**
	 * Dispatch `GET /wc/store/v1/products/{id}` internally via
	 * `rest_do_request` and return the decoded payload — or null if
	 * the product doesn't exist, the dispatcher errored, or the
	 * response didn't carry a usable array.
	 *
	 * Using `rest_do_request` rather than a direct WC_Data_Store call
	 * matters: it threads the request through the Store API's full
	 * pipeline (variation expansion, image URL resolution, embedded
	 * pricing, etc.) — so the resulting array is the exact same
	 * shape an external Store API consumer would see.
	 *
	 * Scope enforcement note: the Store API's
	 * `woocommerce_store_api_product_collection_query_args` filter
	 * applies ONLY to collection queries. Single-product requests
	 * (this method) bypass that filter entirely. To keep agents
	 * from looking up products outside the merchant's `selected_*`
	 * scope by supplying raw IDs, this method calls
	 * `WC_AI_Storefront::is_product_syndicated()` BEFORE the
	 * dispatch and returns null (treated as "not found" by callers)
	 * when the gate fails. This mirrors what llms.txt and JSON-LD
	 * emit for the same product — all three gates stay in
	 * lockstep.
	 *
	 * @return ?array<string, mixed>
	 */
	private static function fetch_store_api_product( int $id ): ?array {
		if ( isset( self::$request_product_cache_has_key[ $id ] ) ) {
			return self::$request_product_cache[ $id ];
		}

		// Scope-enforcement gate. The Store API filter only fires
		// on COLLECTION queries; this method dispatches a SINGLE-
		// PRODUCT request which bypasses that filter (Store API has
		// distinct internal handling for the two). Without an
		// explicit gate here, an agent calling /catalog/lookup with
		// arbitrary product IDs would receive products outside the
		// merchant's `selected_*` scope. The check uses the same
		// UNION enforcement as `WC_AI_Storefront::
		// is_product_syndicated()` for llms.txt and JSON-LD, so all
		// gates stay in lockstep. Memoized as a `null` cache entry
		// so repeated lookups of the same out-of-scope ID don't
		// re-run the per-id checks.
		if ( ! WC_AI_Storefront::is_product_syndicated( $id ) ) {
			WC_AI_Storefront_Logger::debug(
				sprintf( 'UCP fetch_store_api_product(%d): out of merchant scope, returning null', $id )
			);
			self::$request_product_cache[ $id ]         = null;
			self::$request_product_cache_has_key[ $id ] = true;
			return null;
		}

		$request  = new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $id );
		$response = rest_do_request( $request );

		// Three distinct failure modes collapse to a single `null`
		// return (caller treats as "not found"), but each gets its
		// own debug log so production incidents can be diagnosed:
		//
		//   - WP_Error: internal REST pipeline failure (filter threw,
		//     handler is missing, etc.). Returning null treats it as
		//     "product missing" to the agent; the log surfaces the
		//     real cause.
		//   - 4xx status: usually a genuine 404 (product does not
		//     exist or is excluded by the Store API filter). Logged
		//     at info level so catalog misses are visible during debug.
		//   - Non-array body: plugin-conflict smell (some other plugin
		//     hooking rest_post_dispatch returned a string/object).
		//     Logged so this doesn't become a mystery empty catalog.
		$result                                     = self::fetch_store_api_product_inner( $id, $response );
		self::$request_product_cache[ $id ]         = $result;
		self::$request_product_cache_has_key[ $id ] = true;
		return $result;
	}

	/**
	 * Inner resolution logic extracted so the outer
	 * `fetch_store_api_product` stays focused on the
	 * memoization-gate + write. Returns the same `?array`
	 * contract.
	 *
	 * @param int                       $id       WC product ID to fetch.
	 * @param WP_REST_Response|WP_Error $response Store API response for that ID.
	 * @return ?array<string, mixed>
	 */
	private static function fetch_store_api_product_inner( int $id, $response ): ?array {
		if ( $response instanceof WP_Error ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'UCP fetch_store_api_product(%d): WP_Error — %s',
					$id,
					$response->get_error_message()
				)
			);
			return null;
		}

		$status = $response->get_status();
		if ( $status >= 400 ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'UCP fetch_store_api_product(%d): Store API returned %d',
					$id,
					$status
				)
			);
			return null;
		}

		$data       = $response->get_data();
		$normalized = self::normalize_store_api_data( $data );
		if ( null === $normalized ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'UCP fetch_store_api_product(%d): response body could not be normalized to an array (possible plugin conflict)',
					$id
				)
			);
			return null;
		}

		return $normalized;
	}

	/**
	 * Normalize a WC Store API response payload to a pure nested-array
	 * structure regardless of whether the source used `stdClass` or
	 * associative arrays internally.
	 *
	 * This is the fix for a production-only fatal: when `rest_do_request`
	 * returns Store API data internally (no HTTP serialization step),
	 * nested structures — `prices`, `attributes`, `categories`, etc. —
	 * stay as their native PHP types, often `stdClass`. The translator
	 * expects associative arrays and fatals with
	 * "Cannot use object of type stdClass as array" on
	 * `$prices['currency_code']`-style access.
	 *
	 * Tests never surfaced the bug because their `rest_do_request` stub
	 * returns pre-shaped assoc arrays; external HTTP callers never saw
	 * it either because `WP_REST_Server::serve_request` JSON-serializes
	 * the response, and the receiving end decodes back to assoc arrays.
	 * Only the internal-dispatch-with-object-nests path hits the fatal.
	 *
	 * `json_decode(wp_json_encode(...), true)` is the canonical PHP
	 * idiom for deep stdClass→array conversion. It preserves nested
	 * structure, handles arbitrary depth, and matches the serialization
	 * semantics agents see over the wire — so translator output is
	 * byte-identical whether data came via internal dispatch or
	 * external HTTP.
	 *
	 * @param mixed $data Source payload; may be array, stdClass, or mixed.
	 * @return ?array Pure nested-array equivalent, or null if $data is
	 *                neither array nor object.
	 */
	private static function normalize_store_api_data( $data ): ?array {
		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return null;
		}
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * For variable products, fetch each variation's full Store API
	 * response so the product translator can emit per-variation
	 * variants. Simple products return an empty `variations` list.
	 *
	 * Variations that fail to fetch are skipped rather than aborting
	 * the whole translation — partial variant lists are better than
	 * synthesized-default fallbacks for agents trying to surface the
	 * real price range to users. The `skipped` count is exposed in
	 * the return value so callers can emit a `partial_variants`
	 * warning to the agent (otherwise the variants list would silently
	 * disagree with the product's price_range, giving agents bad data
	 * with no signal to distrust it).
	 *
	 * Capped at MAX_VARIATIONS_PER_PRODUCT to bound the N+1 fan-out.
	 * `array_slice` preserves source order so variations come back in
	 * the same sequence WC emitted — important because the product's
	 * `options` (attribute order) is derived from the variations list.
	 * Slice overage counts toward the skipped total so agents see a
	 * single consistent signal regardless of whether variations were
	 * lost to the cap or to fetch failures.
	 *
	 * @param array<string, mixed> $wc_product Store API response for the parent product.
	 * @return array{variations: array<int, array<string, mixed>>, skipped: int}
	 */
	private static function fetch_variations_for( array $wc_product ): array {
		if ( 'variable' !== ( $wc_product['type'] ?? '' ) ) {
			return [
				'variations' => [],
				'skipped'    => 0,
			];
		}

		$variation_refs = $wc_product['variations'] ?? [];
		if ( ! is_array( $variation_refs ) || empty( $variation_refs ) ) {
			return [
				'variations' => [],
				'skipped'    => 0,
			];
		}

		$total_declared = count( $variation_refs );

		if ( $total_declared > self::MAX_VARIATIONS_PER_PRODUCT ) {
			$variation_refs = array_slice( $variation_refs, 0, self::MAX_VARIATIONS_PER_PRODUCT );
		}

		$variations      = [];
		$fetch_attempted = 0;
		foreach ( $variation_refs as $ref ) {
			// WC Store API emits `variations` as `[{id, attributes}, ...]`
			// — just the pointer. Fetch the full variation record.
			$variation_id = is_array( $ref )
				? (int) ( $ref['id'] ?? 0 )
				: (int) $ref;

			if ( $variation_id <= 0 ) {
				continue;
			}

			++$fetch_attempted;
			$data = self::fetch_store_api_product( $variation_id );
			if ( null !== $data ) {
				$variations[] = $data;
			}
		}

		// Skipped = everything the product declared that didn't make it
		// into $variations. Includes cap-truncated + fetch-failed +
		// malformed-ref entries.
		$skipped = $total_declared - count( $variations );

		if ( $skipped > 0 ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'UCP fetch_variations_for(%d): skipped %d of %d declared variations',
					(int) ( $wc_product['id'] ?? 0 ),
					$skipped,
					$total_declared
				)
			);
		}

		return [
			'variations' => $variations,
			'skipped'    => $skipped,
		];
	}

	/**
	 * Build a UCP `not_found` error message for the ID at position
	 * `$index` in the response's `inputs` array. The JSONPath-style
	 * `path` lets agents localize which specific ID failed.
	 *
	 * Note: the path references `$.inputs[...]`, not `$.ids[...]`.
	 * After request-side deduplication (see
	 * `normalize_and_dedupe_lookup_ids`), the original `ids[]` is
	 * collapsed into a deduped `inputs[]` echoed in the response.
	 * Messages address positions in that echoed array so agents can
	 * map failures to the processed (not raw-requested) set.
	 *
	 * @return array<string, string>
	 */
	private static function not_found_message( int $index ): array {
		return [
			'type'     => 'error',
			'code'     => 'not_found',
			'path'     => '$.inputs[' . $index . ']',
			'severity' => 'unrecoverable',
		];
	}

	/**
	 * Normalize + deduplicate the raw `ids[]` submitted to
	 * catalog.lookup. Two agent semantics we enforce:
	 *
	 *   1. **Idempotence**: `["123","123"]` fetches/translates once.
	 *      Downstream work is O(unique) not O(request). This also
	 *      matters for `fetch_store_api_product` which dispatches an
	 *      internal REST request per ID — duplicates doubled the
	 *      work for no benefit.
	 *
	 *   2. **Prefix-form collapsing**: `"woo-p-123"` and bare `"123"`
	 *      parse to the same WC product id. We dedupe on the parsed
	 *      int so an agent sending both forms gets one product and
	 *      one inputs entry. First-occurrence wins for the raw echo.
	 *
	 * Malformed inputs (non-string, empty-after-strip, non-numeric)
	 * are preserved in `inputs[]` so the response faithfully echoes
	 * what the agent sent, with a per-raw-value dedup so the same
	 * garbage string doesn't produce N not_found messages. They
	 * surface as `not_found` messages downstream.
	 *
	 * Non-string inputs (numbers, booleans, arrays, null, etc.) are
	 * coerced to a stable string form for the echo but their
	 * `wc_id` is set to 0 — i.e. treated as not_found. The UCP spec
	 * requires IDs to be strings; we enforce that strictly at the
	 * resolution step even though we echo the raw form back so the
	 * agent can see what we received.
	 *
	 * @param array<int, mixed> $raw_ids
	 *
	 * @return array{
	 *     inputs: array<int, string>,
	 *     wc_ids: array<int, int>
	 * }
	 */
	private static function normalize_and_dedupe_lookup_ids( array $raw_ids ): array {
		$inputs = [];
		$wc_ids = [];
		$seen   = [];

		foreach ( $raw_ids as $raw ) {
			// Non-scalar inputs (arrays/objects/null) can't resolve
			// to a WC product, but we still echo a stable,
			// distinguishable string form so agents see what kind of
			// invalid entry they sent AND so different invalid
			// values dedupe separately. An earlier version echoed
			// everything to `""`, which merged distinct entries
			// (e.g. `[null, []]`) into a single inputs slot — that
			// shifted message paths and hid the agent's real
			// payload. Prefer JSON for arrays/objects so nested
			// structure is preserved in the echo; fall back to a
			// type tag only if wp_json_encode fails (e.g. on a
			// resource or circular reference).
			if ( ! is_scalar( $raw ) ) {
				if ( null === $raw ) {
					$echo = 'null';
				} elseif ( is_array( $raw ) ) {
					$encoded = wp_json_encode( $raw );
					$echo    = ( is_string( $encoded ) && '' !== $encoded ) ? $encoded : '[array]';
				} elseif ( is_object( $raw ) ) {
					$encoded = wp_json_encode( $raw );
					$echo    = ( is_string( $encoded ) && '' !== $encoded ) ? $encoded : '[object]';
				} else {
					// Resources, anything else non-scalar. Unlikely
					// in JSON payloads but keep the enumeration
					// exhaustive so nothing falls through silently.
					$echo = '[invalid]';
				}
				$wc_id = 0;
			} elseif ( is_string( $raw ) ) {
				$echo  = $raw;
				$wc_id = self::parse_ucp_id_to_wc_int( $raw );
				if ( $wc_id < 0 ) {
					$wc_id = 0;
				}
			} elseif ( is_bool( $raw ) ) {
				// Booleans need an explicit branch: `(string) false`
				// is `""`, which is NOT distinguishable from a
				// genuinely-empty string id — the two would share a
				// dedup key and collapse into one inputs entry,
				// hiding what the agent actually sent. Emit
				// "true"/"false" so each remains uniquely addressable.
				$echo  = $raw ? 'true' : 'false';
				$wc_id = 0;
			} else {
				// Remaining non-string scalars (int, float). The spec
				// requires string IDs and `parse_ucp_id_to_wc_int`
				// already returns 0 for non-string input — keeping
				// resolution logic in one place prevents drift
				// between parser and handler.
				$echo  = (string) $raw;
				$wc_id = 0;
			}

			// Dedup key: valid IDs collapse by parsed int (so prefix
			// variants fold together); invalid IDs dedupe only
			// against identical raw echoes so `["abc","abc"]` stays
			// a single entry while `["abc","xyz"]` stays two.
			$key = $wc_id > 0 ? 'id:' . $wc_id : 'raw:' . $echo;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$inputs[]     = $echo;
			$wc_ids[]     = $wc_id;
		}

		return [
			'inputs' => $inputs,
			'wc_ids' => $wc_ids,
		];
	}

	/**
	 * Build a `partial_variants` warning message for a product whose
	 * variations weren't fully retrievable.
	 *
	 * The `skipped` count aggregates two distinct causes (fetch
	 * failure AND cap truncation). The wording uses "are not
	 * included" to cover both accurately — "could not be loaded"
	 * would be misleading for cap-truncated entries which were
	 * never attempted.
	 *
	 * Without this warning, agents would see a product's price_range
	 * (computed at the DB level by WC from ALL variations) disagree
	 * silently with the rendered variants[] list. The message alerts
	 * agents that the variant data is known-incomplete so they can
	 * either re-query via `POST /catalog/lookup` for specific
	 * variation IDs or flag the partial data to the end user.
	 *
	 * @return array<string, string>
	 */
	private static function partial_variants_message( int $product_id, int $skipped ): array {
		return [
			'type'     => 'warning',
			'code'     => 'partial_variants',
			'severity' => 'advisory',
			'content'  => sprintf(
				/* translators: 1: number of variations missing, 2: WC product ID. */
				_n(
					'%1$d variation of product %2$d is not included in the variants list; the list is incomplete.',
					'%1$d variations of product %2$d are not included in the variants list; the list is incomplete.',
					$skipped,
					'woocommerce-ai-storefront'
				),
				$skipped,
				$product_id
			),
		];
	}

	/**
	 * Translate a UCP search request's body fields onto WC Store API
	 * query params and surface any resolution warnings.
	 *
	 * The mapping covers `query`, `pagination`, `sort`, and every
	 * well-known entry under `filters` (categories, tags, price range,
	 * stock status, featured flag, rating floor, attribute filters,
	 * on-sale). The authoritative list is the inline code below — this
	 * docblock deliberately avoids enumerating every filter key to keep
	 * from drifting as new filters are added.
	 *
	 * Non-object `filters`, unknown keys, and malformed nested shapes
	 * are silently ignored — returning empty `$params` is equivalent
	 * to "list all products," a sensible fallback for garbled input.
	 *
	 * Unresolvable category/tag strings and malformed sort inputs ARE
	 * surfaced: they produce `category_not_found`, `tag_not_found`,
	 * `invalid_sort_field`, or `invalid_sort_shape` warnings in the
	 * returned messages array so agents learn their filter/sort didn't
	 * apply (instead of silently receiving the unfiltered catalog).
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
	 *         [params, messages]. `params` contains Store API query
	 *         arguments with heterogeneous value shapes depending on the
	 *         mapped filter: scalar values for simple query params, arrays
	 *         of scalars for multi-value filters, and nested arrays of
	 *         objects for structured filters such as attributes.
	 */
	private static function map_ucp_search_to_store_api( WP_REST_Request $request ): array {
		$params   = [];
		$messages = [];

		$query = $request->get_param( 'query' );
		if ( is_string( $query ) && '' !== $query ) {
			$params['search'] = $query;
		}

		// Pagination mapping. UCP uses opaque cursors + a limit;
		// Store API uses integer page + per_page. We base64-encode
		// the page number as the cursor. See `encode_cursor()` for
		// format rationale.
		//
		// Warning emission: limit clamping, malformed cursors, and
		// non-object `pagination` payloads are all recoverable —
		// we apply defaults and continue rather than HTTP 400. But
		// they're signals an agent needs to see, so each emits a
		// `messages[]` advisory entry. Agents that pagination-math
		// around a clamped limit (e.g. requested 500, got 100)
		// would otherwise miscalculate page counts silently.
		$pagination = $request->get_param( 'pagination' );
		$limit      = self::DEFAULT_SEARCH_LIMIT;
		$page       = 1;

		if ( null !== $pagination && ! is_array( $pagination ) ) {
			$messages[] = [
				'type'     => 'warning',
				'code'     => 'invalid_pagination_shape',
				'severity' => 'advisory',
				'path'     => '$.pagination',
				'content'  => __( 'pagination must be an object; using defaults.', 'woocommerce-ai-storefront' ),
			];
		}

		if ( is_array( $pagination ) ) {
			// Use the same strict integer validator as price bounds —
			// `is_numeric` accepts decimals/scientific notation and
			// lets overflow strings saturate to `PHP_INT_MAX`, which
			// then clamps safely but reflects a misleading
			// "requested" value in the warning message. Routing
			// through `is_integer_like_non_negative` keeps validation
			// discipline consistent across the handler.
			//
			// Invalid shapes (negative, decimal, scientific, overflow,
			// non-numeric) fall through to the default limit, BUT we
			// still emit a `pagination_limit_clamped` warning so the
			// agent gets the same "your value was ignored" signal they
			// got previously. The alternative — silent default — would
			// be worse feedback than the pre-strictness behavior.
			if ( isset( $pagination['limit'] ) ) {
				if ( self::is_integer_like_non_negative( $pagination['limit'] ) ) {
					$requested = (int) $pagination['limit'];
					$limit     = max( 1, min( self::MAX_SEARCH_LIMIT, $requested ) );
					if ( $limit !== $requested ) {
						$messages[] = [
							'type'     => 'warning',
							'code'     => 'pagination_limit_clamped',
							'severity' => 'advisory',
							'path'     => '$.pagination.limit',
							'content'  => sprintf(
								/* translators: 1: requested limit, 2: applied limit, 3: max allowed. */
								__( 'Requested pagination.limit %1$d was clamped to %2$d (allowed range: 1–%3$d).', 'woocommerce-ai-storefront' ),
								$requested,
								$limit,
								self::MAX_SEARCH_LIMIT
							),
						];
					}
				} else {
					// Invalid shape — clamp to the applied default +
					// warn. Path is the same so agents with retry logic
					// keyed on `pagination_limit_clamped` still catch
					// it; the content tells them the value was
					// unusable, not clamped-from-a-number.
					$messages[] = [
						'type'     => 'warning',
						'code'     => 'pagination_limit_clamped',
						'severity' => 'advisory',
						'path'     => '$.pagination.limit',
						'content'  => sprintf(
							/* translators: %d is the applied default limit. */
							__( 'pagination.limit must be a non-negative integer; using default %d.', 'woocommerce-ai-storefront' ),
							$limit
						),
					];
				}
			}
			if ( isset( $pagination['cursor'] ) && is_string( $pagination['cursor'] ) && '' !== $pagination['cursor'] ) {
				$decoded = self::decode_cursor( $pagination['cursor'] );
				if ( null !== $decoded ) {
					$page = $decoded;
				} else {
					// Malformed cursor — emit a warning so the agent
					// can distinguish "my cursor is garbled" from
					// "the cursor is stale after catalog mutation"
					// (both fall back to page 1 here, but only the
					// former is a client bug the agent should know
					// about). Stale-cursor scenarios where the page
					// decodes cleanly but exceeds total_pages are
					// handled downstream by Store API returning an
					// empty result set — no warning needed there.
					$messages[] = [
						'type'     => 'warning',
						'code'     => 'invalid_cursor',
						'severity' => 'advisory',
						'path'     => '$.pagination.cursor',
						'content'  => __( 'Pagination cursor could not be decoded; returning first page. If you copied this cursor from a prior response the catalog may have changed, but a malformed cursor most often indicates a client bug.', 'woocommerce-ai-storefront' ),
					];
				}
			}
		}
		$params['per_page'] = $limit;
		$params['page']     = $page;

		// Sort order — top-level `sort: {field, direction}`, not under
		// filters because it's an ordering concern rather than a
		// result-set restriction. Maps to Store API's `orderby` + `order`.
		// Unknown fields emit an `invalid_sort_field` warning rather
		// than fall through silently: a mistyped sort that returns
		// default ordering is worse than returning default-with-a-hint,
		// because agents otherwise assume their sort took effect.
		$sort = $request->get_param( 'sort' );
		if ( is_array( $sort ) ) {
			// Defensive: non-scalar field/direction (e.g. an agent
			// sending `{sort: {field: []}}`) would coerce to "Array"
			// via (string) cast and trigger a misleading
			// `invalid_sort_field` warning with value "array".
			// Require string inputs; anything else surfaces as a
			// dedicated `invalid_sort_shape` warning so agents can
			// distinguish "unknown field" from "malformed input."
			$raw_field     = $sort['field'] ?? '';
			$raw_direction = $sort['direction'] ?? 'asc';

			if ( ! is_string( $raw_field ) || ! is_string( $raw_direction ) ) {
				$messages[] = [
					'type'     => 'warning',
					'code'     => 'invalid_sort_shape',
					'severity' => 'advisory',
					'path'     => '$.sort',
					'content'  => __( 'sort.field and sort.direction must be strings; using default ordering.', 'woocommerce-ai-storefront' ),
				];
			} else {
				$field     = strtolower( trim( $raw_field ) );
				$direction = strtolower( trim( $raw_direction ) );

				// UCP-friendly names → Store API orderby values. `newest`
				// is an alias for date-desc — more human-intuitive than
				// Store API's `date` + `order=desc` but we still
				// translate here so agents have one sort vocabulary.
				$orderby_map = [
					'price'      => 'price',
					'title'      => 'title',
					'date'       => 'date',
					'newest'     => 'date',
					'popularity' => 'popularity',
					'rating'     => 'rating',
					'menu_order' => 'menu_order',
				];
				if ( isset( $orderby_map[ $field ] ) ) {
					$params['orderby'] = $orderby_map[ $field ];
					$params['order']   = ( 'desc' === $direction ) ? 'desc' : 'asc';
					// `newest` implies desc regardless of caller intent
					// — "newest ascending" is a contradiction we normalize
					// rather than silently honor.
					if ( 'newest' === $field ) {
						$params['order'] = 'desc';
					}
				} elseif ( '' !== $field ) {
					$messages[] = [
						'type'     => 'warning',
						'code'     => 'invalid_sort_field',
						'severity' => 'advisory',
						'path'     => '$.sort.field',
						'content'  => sprintf(
							/* translators: %s is the unsupported sort field the agent sent. */
							__( 'Sort field "%s" is not supported; using default ordering.', 'woocommerce-ai-storefront' ),
							$raw_field
						),
					];
				}
			}
		}

		$filters = $request->get_param( 'filters' );
		if ( ! is_array( $filters ) ) {
			return [ $params, $messages ];
		}

		if ( isset( $filters['categories'] ) && is_array( $filters['categories'] ) ) {
			$categories_capped = self::cap_filter_array(
				$filters['categories'],
				'$.filters.categories',
				$messages
			);
			$category_result   = self::resolve_category_term_ids( $categories_capped );
			if ( ! empty( $category_result['ids'] ) ) {
				$params['category'] = implode( ',', $category_result['ids'] );
			}
			foreach ( $category_result['unresolved'] as $index => $bad ) {
				$messages[] = [
					'type'     => 'warning',
					'code'     => 'category_not_found',
					'severity' => 'advisory',
					'path'     => '$.filters.categories[' . $index . ']',
					'content'  => sprintf(
						/* translators: %s is the category slug/name the agent sent that couldn't be resolved. */
						__( 'Category "%s" was not found; filter ignored for this value.', 'woocommerce-ai-storefront' ),
						self::sanitize_reflected_value( $bad )
					),
				];
			}
		}

		if ( isset( $filters['price'] ) && is_array( $filters['price'] ) ) {
			// UCP spec: price filter is "denominated in context.currency".
			// Three sanctioned behaviors when context.currency is set:
			//   1. Matches store currency → apply directly.
			//   2. Mismatches → business SHOULD convert; if conversion
			//      unsupported, MAY ignore + SHOULD emit a message.
			//   3. Absent → filter denomination is ambiguous; MAY ignore.
			//
			// We don't have a currency-conversion source (no FX rates
			// surfaced from WC core), so mismatch → skip + warn per the
			// spec's MAY/SHOULD path. Absent context.currency → apply
			// directly in the store's currency, which is the lenient
			// (non-ambiguous) reading since our price_range responses
			// always carry the store currency — agents that derive
			// filter bounds from a prior response are self-consistent.
			//
			// Short-circuit: if neither `min` nor `max` is a usable
			// non-negative number, the filter would be a no-op anyway.
			// Running the currency-mismatch branch would emit a
			// warning for a filter we weren't going to apply — noise
			// the agent can't act on. Evaluating bounds first keeps
			// the warning signal proportional to the actual work
			// skipped.
			//
			// Integer-like validation (native int OR digit-only
			// string) rather than `is_numeric()`: UCP minor-unit
			// amounts are spec'd as integers, but `is_numeric` also
			// accepts "25.00" (silently truncates cents on int cast),
			// "1e3" (scientific notation), and whitespace-padded
			// numbers — all of which would forward wrong values to
			// the Store API. Same pattern used elsewhere in this
			// class for amount validation; see `process_line_item`.
			$price             = $filters['price'];
			$has_min           = isset( $price['min'] ) && self::is_integer_like_non_negative( $price['min'] );
			$has_max           = isset( $price['max'] ) && self::is_integer_like_non_negative( $price['max'] );
			$has_usable_bounds = $has_min || $has_max;

			if ( $has_usable_bounds ) {
				$apply_price_filter = true;
				$context            = $request->get_param( 'context' );
				// Validate `context.currency` as ISO 4217 — exactly 3
				// ASCII letters after trim + uppercase. Any malformed
				// value (empty string, too long, non-alpha) is treated
				// as "absent" per spec's MAY-ignore allowance rather
				// than as "mismatch" — and we don't reflect the raw
				// value back in the warning, only the sanitized form,
				// to prevent a hostile agent from bloating responses.
				$ctx_currency_raw = is_array( $context ) && isset( $context['currency'] ) && is_string( $context['currency'] )
					? trim( $context['currency'] )
					: '';
				$ctx_currency     = preg_match( '/^[A-Z]{3}$/', strtoupper( $ctx_currency_raw ) )
					? strtoupper( $ctx_currency_raw )
					: null;
				$store_currency   = function_exists( 'get_woocommerce_currency' )
					? strtoupper( (string) get_woocommerce_currency() )
					: 'USD';
				if ( null !== $ctx_currency && $ctx_currency !== $store_currency ) {
					$apply_price_filter = false;
					$messages[]         = [
						'type'     => 'warning',
						'code'     => 'currency_conversion_unsupported',
						'severity' => 'advisory',
						'path'     => '$.filters.price',
						'content'  => sprintf(
							/* translators: 1: agent-supplied currency, 2: store currency. */
							__( 'context.currency "%1$s" does not match store currency "%2$s" and conversion is not supported; price filter ignored.', 'woocommerce-ai-storefront' ),
							$ctx_currency,
							$store_currency
						),
					];
				}

				if ( $apply_price_filter ) {
					if ( $has_min ) {
						$params['min_price'] = self::minor_units_to_presentment( (int) $price['min'] );
					}
					if ( $has_max ) {
						$params['max_price'] = self::minor_units_to_presentment( (int) $price['max'] );
					}
				}
			}
		}

		// On-sale filter — agents searching for deals pass
		// `filters.on_sale: true`. Store API's `on_sale` param is a
		// boolean flag that restricts results to products with an
		// active sale price. We accept both strict true and stringy
		// "true" since JSON-to-PHP boolean handling varies between
		// REST clients.
		if ( isset( $filters['on_sale'] ) && ( true === $filters['on_sale'] || 'true' === $filters['on_sale'] ) ) {
			$params['on_sale'] = true;
		}

		// Tag filter — parallel to categories but across WC's tag
		// taxonomy. Same name-to-term-ID resolution logic, same
		// unresolved-warning emission for agent feedback. Tags
		// surface cross-cutting discovery signals (e.g. "eco-friendly",
		// "summer") that are orthogonal to hierarchical categories.
		if ( isset( $filters['tags'] ) && is_array( $filters['tags'] ) ) {
			$tags_capped = self::cap_filter_array(
				$filters['tags'],
				'$.filters.tags',
				$messages
			);
			$tag_result  = self::resolve_tag_term_ids( $tags_capped );
			if ( ! empty( $tag_result['ids'] ) ) {
				$params['tag'] = implode( ',', $tag_result['ids'] );
			}
			foreach ( $tag_result['unresolved'] as $index => $bad ) {
				$messages[] = [
					'type'     => 'warning',
					'code'     => 'tag_not_found',
					'severity' => 'advisory',
					'path'     => '$.filters.tags[' . $index . ']',
					'content'  => sprintf(
						/* translators: %s is the tag slug/name the agent sent that couldn't be resolved. */
						__( 'Tag "%s" was not found; filter ignored for this value.', 'woocommerce-ai-storefront' ),
						self::sanitize_reflected_value( $bad )
					),
				];
			}
		}

		// Brand filter — parallel to tags but across the `product_brand`
		// taxonomy (native in WC 9.5+, previously a plugin). Same
		// resolution path + unresolved-warning emission. Store API
		// accepts comma-joined term IDs or slugs on the `brand` param;
		// we resolve to IDs for consistency with category/tag.
		if ( isset( $filters['brand'] ) && is_array( $filters['brand'] ) ) {
			$brand_capped = self::cap_filter_array(
				$filters['brand'],
				'$.filters.brand',
				$messages
			);
			$brand_result = self::resolve_brand_term_ids( $brand_capped );
			if ( ! empty( $brand_result['ids'] ) ) {
				$params['brand'] = implode( ',', $brand_result['ids'] );
			}
			foreach ( $brand_result['unresolved'] as $index => $bad ) {
				$messages[] = [
					'type'     => 'warning',
					'code'     => 'brand_not_found',
					'severity' => 'advisory',
					'path'     => '$.filters.brand[' . $index . ']',
					'content'  => sprintf(
						/* translators: %s is the brand slug/name the agent sent that couldn't be resolved. */
						__( 'Brand "%s" was not found; filter ignored for this value.', 'woocommerce-ai-storefront' ),
						self::sanitize_reflected_value( $bad )
					),
				];
			}
		}

		// In-stock filter — agents transacting in real time shouldn't
		// pitch products they can't actually deliver. Store API's
		// stock_status param takes an array enum (instock/outofstock/
		// onbackorder); when an agent opts in with `in_stock: true` we
		// restrict to `["instock"]`. Not forwarding when the caller
		// passes false or omits the filter, so the default remains
		// "whatever the merchant configured for frontend visibility".
		if ( isset( $filters['in_stock'] ) && ( true === $filters['in_stock'] || 'true' === $filters['in_stock'] ) ) {
			$params['stock_status'] = [ 'instock' ];
		}

		// Featured filter — merchandising signal. Merchants flag hero
		// products via WC's native "featured" toggle; agents surfacing
		// a "staff picks" or "popular now" carousel can request only
		// those with `featured: true`.
		if ( isset( $filters['featured'] ) && ( true === $filters['featured'] || 'true' === $filters['featured'] ) ) {
			$params['featured'] = true;
		}

		// Min rating filter — agents seeking quality ("4+ stars only")
		// map to Store API's `rating` param, which takes an array of
		// acceptable integer ratings (1–5). We expand `min_rating: N`
		// to `[N, N+1, ..., 5]` — Store API's shape is a set-inclusion
		// filter, not a floor. Clamping to [1,5] keeps the array
		// non-empty and the semantics coherent.
		// Strict integer-shape validation (same helper as price
		// bounds and pagination.limit) — the Store API rating param
		// wants integers, and accepting `"4.9"` via `is_numeric`
		// would silently truncate to 4. Clamping to [1,5] contains
		// the damage but the inconsistency invites copy-paste bugs
		// into future unclamped fields.
		if ( isset( $filters['min_rating'] ) && self::is_integer_like_non_negative( $filters['min_rating'] ) ) {
			$min     = max( 1, min( 5, (int) $filters['min_rating'] ) );
			$ratings = [];
			for ( $r = $min; $r <= 5; $r++ ) {
				$ratings[] = $r;
			}
			$params['rating'] = $ratings;
		}

		// Attribute filters — `filters.attributes: {color: ["red"], size: ["M"]}`.
		// WC uses `pa_*` taxonomies for custom product attributes;
		// agents typically don't know the `pa_` convention, so we
		// prepend it when the caller's key doesn't already have it.
		// The Store API `attributes` param is an array of objects with
		// `attribute` (taxonomy), `slug[]` (term slugs), and `operator`.
		// Unlike categories/tags we don't resolve to term IDs first —
		// Store API accepts slugs directly for attributes, and
		// invalid slugs produce empty results rather than errors.
		if ( isset( $filters['attributes'] ) && is_array( $filters['attributes'] ) ) {
			$attributes_input = self::cap_filter_map( $filters['attributes'], '$.filters.attributes', $messages );
			$attribute_result = self::build_attribute_filter_params( $attributes_input );
			if ( ! empty( $attribute_result['filters'] ) ) {
				$params['attributes'] = $attribute_result['filters'];
			}
			foreach ( $attribute_result['unresolved'] as $bad ) {
				// Use JSONPath bracket notation for the key — dot
				// notation is only valid for identifier-style keys
				// (letters, digits, underscores). Agent keys like
				// `"Fabric Type"` (spaces), `"pa-size"` (hyphens), or
				// `"foo's"` (quotes) need quoted bracket notation to
				// remain machine-addressable. Escape backslashes
				// first (else the \' below would end up as the literal
				// character \\' which terminates the JSONPath string
				// early) and then single quotes.
				//
				// Sanitize BOTH `key` (reflected into path) and
				// `taxonomy` (reflected into content) — downstream
				// renderers shouldn't be able to render stored
				// markup from either axis.
				$sanitized_key = self::sanitize_reflected_value( $bad['key'] );
				$escaped_key   = str_replace(
					[ '\\', "'" ],
					[ '\\\\', "\\'" ],
					$sanitized_key
				);
				$messages[]    = [
					'type'     => 'warning',
					'code'     => 'attribute_not_found',
					'severity' => 'advisory',
					'path'     => sprintf( "\$.filters.attributes['%s']", $escaped_key ),
					'content'  => sprintf(
						/* translators: %s is the attribute taxonomy name the agent sent that doesn't exist on the store. */
						__( 'Attribute taxonomy "%s" was not found on the store; filter ignored for this axis.', 'woocommerce-ai-storefront' ),
						self::sanitize_reflected_value( $bad['taxonomy'] )
					),
				];
			}
		}

		return [ $params, $messages ];
	}

	/**
	 * Build the Store API `attributes` filter array from a UCP-shaped
	 * attributes map.
	 *
	 * Input : `{color: ["red", "blue"], size: ["M"], pa_brand: ["nike"]}`
	 * Output: `[
	 *   {attribute: "pa_color", slug: ["red","blue"], operator: "in"},
	 *   {attribute: "pa_size",  slug: ["m"],          operator: "in"},
	 *   {attribute: "pa_brand", slug: ["nike"],       operator: "in"},
	 * ]`
	 *
	 * Each attribute key is normalized into a WooCommerce `pa_`
	 * taxonomy name by stripping any leading `pa_` (case-insensitive),
	 * sanitizing the remainder with `sanitize_title()`, and re-applying
	 * the `pa_` prefix. Each value is likewise converted to a sanitized
	 * slug via `sanitize_title()`, which does more than just lowercase
	 * (spaces → dashes, accents → ASCII, entity stripping, etc.).
	 * Empty arrays, non-array values, numeric keys, and entries that
	 * collapse to `pa_` after normalization are skipped so a malformed
	 * entry doesn't poison the whole filter list.
	 *
	 * Taxonomies are validated via `taxonomy_exists()` before
	 * forwarding. Normalized-but-unknown attribute names produce an
	 * `attribute_not_found` entry in the returned `unresolved` list so
	 * the caller can emit the warning, symmetric with how categories
	 * and tags already surface unresolved filters.
	 *
	 * @param array<mixed, mixed> $attribute_map
	 * @return array{
	 *     filters: array<int, array{attribute: string, slug: array<int, string>, operator: string}>,
	 *     unresolved: array<int, array{key: string, taxonomy: string}>
	 * }
	 */
	private static function build_attribute_filter_params( array $attribute_map ): array {
		$filters    = [];
		$unresolved = [];
		foreach ( $attribute_map as $key => $values ) {
			// Skip numeric keys — a malformed list-shaped input like
			// `filters.attributes: [["red"]]` produces integer keys
			// (0, 1, ...) which would cast to strings and forward as
			// `pa_0`, `pa_1` taxonomies. Those match no real attribute
			// and silently restrict the catalog to zero results with
			// no signal. Attribute axes are named; numeric keys are
			// always a shape bug.
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}

			// Normalize the taxonomy key. Reject empty/whitespace-only
			// keys up front — forwarding taxonomy `pa_` (or empty) to
			// Store API silently returns no results, leaving the agent
			// with no signal their input was malformed. `sanitize_title`
			// canonicalizes "Light Blue" → "light-blue" rather than the
			// naive strtolower → "light blue" (which is an invalid slug).
			$raw_key = trim( $key );
			if ( '' === $raw_key ) {
				continue;
			}
			// Strip a leading `pa_` prefix (case-insensitive) BEFORE
			// sanitize_title, then re-add it. WP core's default
			// sanitize_title preserves underscores, but the behavior
			// is hookable via the `sanitize_title` filter: a plugin
			// or theme converting underscores to dashes would turn
			// `pa_brand` into `pa-brand`, fail a naive `pa_` prefix
			// check, and re-prefix to the nonsense `pa_pa-brand`.
			// Stripping and re-adding the prefix ourselves removes
			// that dependency on the `sanitize_title` contract.
			//
			// Also handles mixed-case variants: `"PA_Color"` /
			// `"Pa_brand"` both canonicalize to `pa_color` / `pa_brand`.
			$attribute_key  = preg_match( '/^pa_/i', $raw_key )
				? preg_replace( '/^pa_/i', '', $raw_key )
				: $raw_key;
			$normalized_key = sanitize_title( $attribute_key );
			// After normalization a whitespace-only-after-trim input
			// (or a bare `pa_` / `PA_`) can collapse to an empty
			// string. That's not a valid taxonomy suffix — drop it
			// rather than forward a semantically-empty filter.
			if ( '' === $normalized_key ) {
				continue;
			}
			$taxonomy = 'pa_' . $normalized_key;

			// Validate the taxonomy exists on the store. `taxonomy_exists`
			// is cheap (in-memory map lookup) and catches typos the
			// agent can't otherwise distinguish from a valid query
			// that returned nothing. Unknown taxonomy → record the
			// original input key + normalized taxonomy name so the
			// caller can emit an `attribute_not_found` warning with
			// JSON-path precision, symmetric with `category_not_found`
			// / `tag_not_found` for the parallel filters.
			if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
				$unresolved[] = [
					'key'      => $raw_key,
					'taxonomy' => $taxonomy,
				];
				continue;
			}

			// Normalize slug values. Reject non-string/non-numeric
			// entries — a nested array coerces to "Array" via (string)
			// cast, which would silently forward as a bogus slug.
			// sanitize_title keeps the WP-canonical slug form and
			// matches how WC stores attribute term slugs in the DB.
			$slugs = [];
			foreach ( $values as $v ) {
				if ( ! is_string( $v ) && ! is_numeric( $v ) ) {
					continue;
				}
				$slug = sanitize_title( (string) $v );
				if ( '' !== $slug ) {
					$slugs[] = $slug;
				}
			}
			if ( empty( $slugs ) ) {
				continue;
			}

			$filters[] = [
				'attribute' => $taxonomy,
				'slug'      => array_values( array_unique( $slugs ) ),
				'operator'  => 'in',
			];
		}
		return [
			'filters'    => $filters,
			'unresolved' => $unresolved,
		];
	}

	/**
	 * Resolve UCP category strings to WC product_cat term IDs.
	 *
	 * WC Store API's `category` param only accepts numeric IDs, not
	 * slugs or names — despite some documentation saying otherwise.
	 * We try slug lookup first (canonical, URL-safe), then name as a
	 * fallback so agents echoing back category values from our search
	 * responses still work.
	 *
	 * Returns both the resolved IDs (deduplicated — `["shirts","Shirts"]`
	 * both resolving to term 123 produces `ids: [123]`, not `[123,123]`)
	 * AND the subset of input strings that couldn't be resolved. The
	 * caller surfaces the unresolved set as `category_not_found`
	 * warnings to the agent — the old behavior of silently dropping
	 * them would cause the agent to see the full unfiltered catalog
	 * (the opposite of what they asked for) with no indication that
	 * the filter was ignored.
	 *
	 * A future release should revisit emitting slugs from
	 * `WC_AI_Storefront_UCP_Product_Translator::extract_taxonomies()`
	 * so round-tripping doesn't rely on the name fallback here.
	 *
	 * @param array<int, mixed> $inputs
	 * @return array{ids: array<int, int>, unresolved: array<int, string>}
	 *         `unresolved` preserves the original request index as key
	 *         so callers can build JSONPath locators.
	 */
	private static function resolve_category_term_ids( array $inputs ): array {
		return self::resolve_taxonomy_term_ids( $inputs, 'product_cat' );
	}

	/**
	 * Resolve UCP tag strings to WC product_tag term IDs.
	 *
	 * Parallel to `resolve_category_term_ids` — same slug-first,
	 * name-fallback lookup strategy, same shape return. Extracted
	 * via the generic helper below so the category/tag resolution
	 * stays DRY; if a future filter adds product_brand or another
	 * taxonomy, it's a one-liner.
	 *
	 * @param array<int, mixed> $inputs
	 * @return array{ids: array<int, int>, unresolved: array<int, string>}
	 */
	private static function resolve_tag_term_ids( array $inputs ): array {
		return self::resolve_taxonomy_term_ids( $inputs, 'product_tag' );
	}

	/**
	 * Resolve UCP brand strings to WC product_brand term IDs.
	 *
	 * Parallel to `resolve_tag_term_ids` but across the
	 * `product_brand` taxonomy — native in WC 9.5+ and shipped by the
	 * standalone "WooCommerce Brands" plugin before that. Same
	 * slug-first / name-fallback resolution path.
	 *
	 * @param array<int, mixed> $inputs
	 * @return array{ids: array<int, int>, unresolved: array<int, string>}
	 */
	private static function resolve_brand_term_ids( array $inputs ): array {
		return self::resolve_taxonomy_term_ids( $inputs, 'product_brand' );
	}

	/**
	 * Cap a filter input array to `MAX_FILTER_VALUES` and emit a
	 * `filter_truncated` warning message when truncation occurs.
	 *
	 * Purpose — DoS mitigation at the handler boundary: taxonomy
	 * filters feed into `get_term_by` DB lookups (two per entry),
	 * so an uncapped agent-supplied array becomes N × 2 MySQL
	 * round-trips per request. The cap keeps worst-case work
	 * bounded per request; the warning keeps honest agents
	 * informed that their tail got dropped.
	 *
	 * `&$messages` is appended to by-reference — caller's warning
	 * accumulator receives the truncation advisory next to its
	 * other filter-resolution warnings.
	 *
	 * @param array<int, mixed>           $values   Raw agent-supplied array.
	 * @param string                      $path     JSONPath to the array (e.g. `$.filters.categories`).
	 * @param array<int, array<string, mixed>> &$messages Warning accumulator.
	 *
	 * @return array<int, mixed> Capped array (first `MAX_FILTER_VALUES` entries).
	 */
	private static function cap_filter_array( array $values, string $path, array &$messages ): array {
		if ( count( $values ) <= self::MAX_FILTER_VALUES ) {
			return $values;
		}
		$original_count = count( $values );
		$capped         = array_slice( $values, 0, self::MAX_FILTER_VALUES );
		$messages[]     = [
			'type'     => 'warning',
			'code'     => 'filter_truncated',
			'severity' => 'advisory',
			'path'     => $path,
			'content'  => sprintf(
				/* translators: 1: filter path, 2: original count, 3: applied cap. */
				__( '%1$s received %2$d values; truncated to the first %3$d. Further values were ignored.', 'woocommerce-ai-storefront' ),
				$path,
				$original_count,
				self::MAX_FILTER_VALUES
			),
		];
		return $capped;
	}

	/**
	 * Cap a filter input map (associative) to `MAX_FILTER_VALUES` keys
	 * and emit a `filter_truncated` warning when truncation occurs.
	 *
	 * Mirrors `cap_filter_array()` but uses `preserve_keys: true` on
	 * `array_slice` so the caller's original string keys survive the cap.
	 *
	 * @param array<string, mixed>             $map      Raw agent-supplied map.
	 * @param string                           $path     JSONPath to the map (e.g. `$.filters.attributes`).
	 * @param array<int, array<string, mixed>> &$messages Warning accumulator.
	 *
	 * @return array<string, mixed> Capped map (first `MAX_FILTER_VALUES` keys).
	 */
	private static function cap_filter_map( array $map, string $path, array &$messages ): array {
		if ( count( $map ) <= self::MAX_FILTER_VALUES ) {
			return $map;
		}
		$original_count = count( $map );
		$capped         = array_slice( $map, 0, self::MAX_FILTER_VALUES, true );
		$messages[]     = [
			'type'     => 'warning',
			'code'     => 'filter_truncated',
			'severity' => 'advisory',
			'path'     => $path,
			'content'  => sprintf(
				/* translators: 1: filter path, 2: original count, 3: applied cap. */
				__( '%1$s received %2$d keys; truncated to the first %3$d. Further keys were ignored.', 'woocommerce-ai-storefront' ),
				$path,
				$original_count,
				self::MAX_FILTER_VALUES
			),
		];
		return $capped;
	}

	/**
	 * Sanitize an agent-supplied string for safe reflection into a
	 * response `content` field (warning/error message body).
	 *
	 * Downstream consumers — merchant admin dashboards, Slack
	 * webhooks posting response summaries, CRM syncs of agent
	 * conversation logs — may render the `content` string as HTML
	 * without escaping. Treating any agent-echoed value as
	 * attacker-authored before serialization prevents stored XSS
	 * via the response body.
	 *
	 * Applies three passes:
	 *   1. Non-string → stringify defensively via `(string)` so
	 *      `sprintf('%s', ...)` doesn't warn on object/array.
	 *   2. `wp_strip_all_tags` — strips `<script>`, `<img>`, and
	 *      all other HTML tags (plus their content for script/style)
	 *      while leaving plain text intact.
	 *   3. Hard length cap at 200 chars — bounds the response
	 *      payload growth (a hostile agent can't inflate the
	 *      response with a 100KB "brand" string).
	 *
	 * @param mixed $value
	 */
	private static function sanitize_reflected_value( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = is_scalar( $value ) ? (string) $value : '';
		}
		$stripped = function_exists( 'wp_strip_all_tags' )
			? wp_strip_all_tags( $value )
			: strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		// Multibyte-aware truncate. Byte-based `substr` could chop
		// a UTF-8 sequence mid-byte and emit invalid bytes into a
		// JSON response, which some clients choke on.
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $stripped ) > 200 ) {
			return mb_substr( $stripped, 0, 200 );
		}
		return strlen( $stripped ) > 200 ? substr( $stripped, 0, 200 ) : $stripped;
	}

	/**
	 * Generic term-resolution helper — slug first, name fallback.
	 *
	 * Abstracted from the original `resolve_category_term_ids` so
	 * new taxonomies (tags, brands if merchants use them) can reuse
	 * the same round-tripping strategy without copy-pasting the
	 * skip/lookup/unresolved pattern.
	 *
	 * @param array<int, mixed> $inputs
	 * @param string            $taxonomy The WC taxonomy slug ('product_cat', 'product_tag', 'product_brand').
	 * @return array{ids: array<int, int>, unresolved: array<int, string>}
	 */
	private static function resolve_taxonomy_term_ids( array $inputs, string $taxonomy ): array {
		$ids        = [];
		$unresolved = [];

		foreach ( $inputs as $index => $input ) {
			if ( ! is_string( $input ) || '' === $input ) {
				// Skip non-string/empty inputs silently — they're
				// malformed enough that a not-found warning would be
				// misleading (the agent didn't even spell a term).
				continue;
			}

			$term = get_term_by( 'slug', $input, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				$term = get_term_by( 'name', $input, $taxonomy );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			} else {
				$unresolved[ (int) $index ] = $input;
			}
		}

		return [
			'ids'        => array_values( array_unique( $ids ) ),
			'unresolved' => $unresolved,
		];
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

	/**
	 * Strict "non-negative integer" validator.
	 *
	 * Required for UCP minor-unit amounts (prices in the smallest
	 * currency denomination) where the spec is explicit: integers
	 * only. `is_numeric()` would accept:
	 *
	 *   - Decimal strings like `"25.00"` → `(int) "25.00"` = 25,
	 *     silently dropping sub-unit precision.
	 *   - Scientific notation like `"1e3"` → `(int) "1e3"` = 1000,
	 *     which parses correctly but signals a probably-buggy
	 *     agent encoding its amounts as floats.
	 *   - Whitespace-padded strings like `"  100"` → `(int) "  100"`
	 *     = 100, which would also work but indicates a client that's
	 *     not serializing amounts correctly.
	 *
	 * Accept non-negative ints (including 0) OR digit-only strings.
	 * `ctype_digit` is false for `"-5"` and `"5.0"` — exactly what
	 * we want. Leading zeros are harmless for amount use (`"001"`
	 * → `1`), so we don't reject them. Zero is a legitimate lower
	 * bound (e.g. `min_price: 0`), so it passes.
	 *
	 * @param mixed $value
	 */
	private static function is_integer_like_non_negative( $value ): bool {
		if ( is_int( $value ) ) {
			return $value >= 0;
		}
		if ( ! is_string( $value ) || ! ctype_digit( $value ) ) {
			return false;
		}
		// `ctype_digit` accepts arbitrarily long digit strings —
		// `"9" x 30` passes but silently saturates to `PHP_INT_MAX`
		// on `(int)` cast, turning a malformed amount into a valid-
		// looking but wrong one. `filter_var` with
		// `FILTER_VALIDATE_INT` rejects out-of-range values
		// (returns `false`), so overflow is detected before the
		// cast. Combined with the `ctype_digit` pre-check we also
		// skip FILTER_VALIDATE_INT's leniency around leading `+`,
		// negatives, and whitespace.
		return false !== filter_var(
			$value,
			FILTER_VALIDATE_INT,
			[ 'options' => [ 'min_range' => 0 ] ]
		);
	}

	// ------------------------------------------------------------------
	// Checkout-sessions helpers
	// ------------------------------------------------------------------

	/**
	 * Resolve the canonical agent name for attribution, derived from the
	 * UCP-Agent header's profile URL. Falls back to the `ucp_unknown`
	 * sentinel when the header is missing/malformed.
	 *
	 * The returned value lands in `utm_source` on the continue_url, is
	 * captured by WooCommerce Order Attribution into
	 * `_wc_order_attribution_utm_source`, and shows up verbatim in the
	 * Orders list's "Origin" column as `Source: {name}`. Canonicalizing
	 * here (rather than at display time) keeps that WC-captured value
	 * clean and queryable for stats breakdowns.
	 *
	 * @see WC_AI_Storefront_UCP_Agent_Header::canonicalize_host() for
	 *      the hostname → brand-name mapping rationale.
	 */
	private static function resolve_agent_host( WP_REST_Request $request ): string {
		$header = $request->get_header( 'ucp-agent' );

		if ( is_string( $header ) && '' !== $header ) {
			$host = WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname( $header );
			if ( '' !== $host ) {
				return WC_AI_Storefront_UCP_Agent_Header::canonicalize_host( $host );
			}
		}

		return WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE;
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
	 * @param mixed  $line_item      Raw line_item value from the request body.
	 * @param int    $index          Position in the line_items array (for
	 *                               JSON-path in error messages).
	 * @param string $store_currency ISO 4217 currency code the store
	 *                               operates in — used to validate that
	 *                               `expected_unit_price.currency`
	 *                               matches before running the
	 *                               `price_changed` comparison. Passed in
	 *                               (rather than read from WC here) to
	 *                               keep the method pure + testable and
	 *                               avoid a second `get_woocommerce_currency`
	 *                               call per line item.
	 * @return array{processed: ?array<string, mixed>, messages: array<int, array<string, mixed>>}
	 */
	private static function process_line_item( $line_item, int $index, string $store_currency ): array {
		$messages = [];
		$path     = '$.line_items[' . $index . ']';

		if ( ! is_array( $line_item ) ) {
			$messages[] = self::checkout_error_message( 'invalid_line_item', $path );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		// `$line_item['item']` must itself be an array before we drill in.
		// Under PHP 8, accessing `['id']` on a string (e.g. an agent
		// sending `{"item": "prod_123"}`) throws a fatal "cannot access
		// offset" error BEFORE any error-response path runs — so the
		// shape check has to happen at this layer, not inside
		// `parse_ucp_id_to_wc_int`.
		if ( ! isset( $line_item['item'] ) || ! is_array( $line_item['item'] ) ) {
			$messages[] = self::checkout_error_message( 'invalid_line_item', $path . '.item' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		$raw_id = $line_item['item']['id'] ?? null;

		// `item.id` must be a non-empty string. Non-string input would
		// eventually surface via `parse_ucp_id_to_wc_int` returning 0 →
		// `not_found`, but that conflates "shape wrong" with "ID not in
		// the catalog." Emit `invalid_line_item` here to give agents a
		// clearer signal about malformed request shape.
		if ( ! is_string( $raw_id ) || '' === trim( $raw_id ) ) {
			$messages[] = self::checkout_error_message( 'invalid_line_item', $path . '.item.id' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

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
		// required. Shareable Checkout URLs can't add a parent to cart
		// — they need a concrete variation ID. `variable-subscription`
		// is the subscription-extension variant of the same kind.
		if ( 'variable' === $type || 'variable-subscription' === $type ) {
			// `checkout_error_message` supplies the default
			// variation-required wording via `default_error_content`
			// — no override needed here.
			$messages[] = self::checkout_error_message( 'variation_required', $path . '.item.id' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		// Types incompatible with Shareable Checkout URLs:
		//   - grouped: multiple sub-products with per-child quantities
		//   - external: redirect to a third-party seller's site
		//   - subscription / subscription_variation: recurring billing;
		//     the Shareable Checkout URL treats every item as a one-off
		//     purchase, which mis-routes subscription sign-ups
		//
		// The UCP manifest's purchase_urls.checkout_link.unsupported
		// list already advertises this; the handler enforces the
		// contract. Agents should link directly to the product
		// permalink for these types.
		if ( 'grouped' === $type || 'external' === $type
			|| 'subscription' === $type || 'subscription_variation' === $type
		) {
			$messages[] = self::checkout_error_message( 'product_type_unsupported', $path . '.item.id' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		// Out-of-stock rejected outright. WC's `is_in_stock` already
		// factors the merchant's backorder settings: when it's false,
		// WooCommerce has concluded the item is not purchasable right
		// now. Passing it through to continue_url would hand the user
		// a checkout that then refuses the item — worse UX than
		// surfacing the error at the source. Merchants who want to
		// accept backorders should ensure their product's backorder
		// setting makes `is_in_stock` return true.
		$in_stock = (bool) ( $wc_product['is_in_stock'] ?? true );
		if ( ! $in_stock ) {
			$messages[] = self::checkout_error_message( 'out_of_stock', $path . '.item.id' );
			return [
				'processed' => null,
				'messages'  => $messages,
			];
		}

		$unit_price_minor = (int) ( $wc_product['prices']['price'] ?? 0 );

		// Price-stability warning. Agents typically scrape the catalog
		// at T, present to the user at T+N, then POST /checkout-sessions
		// at T+M. Merchant prices can drift in the interval. If the
		// agent sends `expected_unit_price` (the amount they showed
		// the user), compare to current; mismatch → `price_changed`
		// warning with both values so the agent can confirm with the
		// user before redirecting. Agent-opt-in: agents that don't
		// send `expected_unit_price` get no warning (our legacy
		// behavior unchanged).
		//
		// Currency guard: minor-unit amounts aren't comparable across
		// currencies (50 JPY ≠ 50 USD), so we only run the comparison
		// when the agent's declared currency matches the store's.
		// Empty/omitted currency is treated as "matches store" (the
		// lenient path — agents pre-negotiated the currency via the
		// manifest's store_context). A non-matching currency causes
		// us to silently skip the check rather than emit a confusing
		// warning; agents can verify the store currency from the
		// manifest before calling.
		// Extract + shape-guard `expected_unit_price` before nested
		// array access. PHP 8 `isset($str['x']['y'])` on a string-
		// typed `$line_item['expected_unit_price']` can emit
		// "Trying to access array offset on value of type string"
		// warnings; pulling the value into a local and checking
		// `is_array` is the clean defensive pattern (mirrors the
		// guard on `$line_item['item']` earlier in the function).
		//
		// Amount type-strictness: UCP amounts are integer minor units.
		// `is_numeric()` accepts decimal strings and floats, which
		// `(int)` would silently truncate — `"25.00"` → 25,
		// potentially firing a bogus `price_changed` for a client
		// using the wrong encoding. Require `is_int()` OR a
		// digit-only string (`ctype_digit`). Decimals, floats,
		// negatives, and scientific notation all skip the comparison
		// without emitting a warning.
		$expected_unit_price    = $line_item['expected_unit_price'] ?? null;
		$expected_amount_raw    = is_array( $expected_unit_price )
			? ( $expected_unit_price['amount'] ?? null )
			: null;
		$amount_is_integer_like = is_int( $expected_amount_raw )
			|| ( is_string( $expected_amount_raw ) && ctype_digit( $expected_amount_raw ) );
		if ( is_array( $expected_unit_price ) && $amount_is_integer_like ) {
			// Currency must be a string. A non-string value (array,
			// object, etc.) cast via `(string)` would emit "Array to
			// string conversion" notices; treat non-string as
			// missing (empty-string lenient path) so the comparison
			// runs against the store currency without polluting
			// logs. Same defensive pattern as the handoff filter's
			// non-string fallback.
			$expected_currency = isset( $expected_unit_price['currency'] ) && is_string( $expected_unit_price['currency'] )
				? $expected_unit_price['currency']
				: '';
			$currency_matches  = '' === $expected_currency
				|| 0 === strcasecmp( $expected_currency, $store_currency );

			if ( $currency_matches ) {
				$expected = (int) $expected_amount_raw;
				if ( $expected !== $unit_price_minor ) {
					$messages[] = [
						'type'     => 'warning',
						'code'     => 'price_changed',
						'severity' => 'advisory',
						'path'     => $path,
						'content'  => sprintf(
							/* translators: 1: expected amount (minor units), 2: current amount (minor units). */
							__( 'Unit price changed from %1$d to %2$d (minor units) since the catalog was fetched.', 'woocommerce-ai-storefront' ),
							$expected,
							$unit_price_minor
						),
					];
				}
			}
		}

		return [
			'processed' => [
				'wc_id'            => $wc_id,
				// Preserve the agent's original ID for round-tripping —
				// if they sent `var_456`, echo `var_456` back even though
				// we resolved it to WC ID 456 internally. The shape
				// check above guarantees `$raw_id` is a non-empty string
				// by this point, so no fallback is needed.
				'ucp_id'           => $raw_id,
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
	 *   &utm_source={agent_name}&utm_medium=ai_agent
	 *
	 * WC's own Order Attribution system captures these on the resulting
	 * order, so merchants see agent-sourced traffic without needing any
	 * extra plumbing.
	 *
	 * @param array<int, array<string, mixed>> $processed  Successfully-processed line items.
	 * @param string                           $agent_name Canonical brand name for `utm_source`
	 *                                                     (e.g. "Gemini", "ChatGPT"), as produced
	 *                                                     by `resolve_agent_host()`. Shows up in
	 *                                                     WC's Origin column as `Source: {name}`.
	 */
	private static function build_continue_url( array $processed, string $agent_name ): string {
		$segments = [];
		foreach ( $processed as $p ) {
			$segments[] = $p['wc_id'] . ':' . $p['quantity'];
		}

		$base = function_exists( 'home_url' )
			? home_url( '/checkout-link/' )
			: '/checkout-link/';

		return $base
			. '?products=' . implode( ',', $segments )
			. '&utm_source=' . rawurlencode( $agent_name )
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
	 * Includes a code-specific `content` (human-readable explanation)
	 * by default so agents surfacing messages to end users get useful
	 * text — without this, every error would read "something failed"
	 * with only a machine-readable code to go on. Callers can override
	 * via the `$content` argument for codes where default phrasing
	 * isn't enough (e.g. `variation_required` wants to say so in
	 * plain language).
	 *
	 * @return array<string, string>
	 */
	private static function checkout_error_message( string $code, string $path, string $content = '' ): array {
		if ( '' === $content ) {
			$content = self::default_error_content( $code );
		}

		return [
			'type'     => 'error',
			'code'     => $code,
			'path'     => $path,
			'severity' => 'unrecoverable',
			'content'  => $content,
		];
	}

	/**
	 * Default human-readable content for each known UCP checkout
	 * error code. Returned text is what agents show the end user
	 * when the code alone isn't informative.
	 *
	 * Codes without a specific entry fall through to a generic
	 * phrase rather than empty content — machine-readable code is
	 * preserved either way, but human-facing surface never goes
	 * blank.
	 */
	private static function default_error_content( string $code ): string {
		switch ( $code ) {
			case 'invalid_line_item':
				return __( 'Line item must be an object with "item.id" and "quantity".', 'woocommerce-ai-storefront' );
			case 'invalid_quantity':
				return sprintf(
					/* translators: %d is the maximum quantity per line item. */
					__( 'Quantity must be a positive integer up to %d.', 'woocommerce-ai-storefront' ),
					self::MAX_QUANTITY_PER_LINE_ITEM
				);
			case 'not_found':
				return __( 'Product not found.', 'woocommerce-ai-storefront' );
			case 'product_type_unsupported':
				return __( 'Product type cannot be added via the Shareable Checkout URL; link to the product page directly.', 'woocommerce-ai-storefront' );
			case 'out_of_stock':
				return __( 'Product is out of stock.', 'woocommerce-ai-storefront' );
			case 'variation_required':
				// Caller overrides with the more specific message; default
				// here matches in case the override is ever dropped.
				return __( 'Product is variable — specify a variation ID instead of the parent product ID.', 'woocommerce-ai-storefront' );
			default:
				return __( 'Line item could not be processed.', 'woocommerce-ai-storefront' );
		}
	}
}
