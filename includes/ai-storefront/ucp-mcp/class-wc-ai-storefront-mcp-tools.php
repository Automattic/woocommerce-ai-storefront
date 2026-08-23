<?php
/**
 * MCP transport: tool catalog + argument/result mapping.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declares the MCP tool catalog (tools/list) and maps tool calls onto the
 * transport-neutral run_* cores on WC_AI_Storefront_UCP_REST_Controller.
 *
 * This is the thin argument/result adapter only — all UCP business logic
 * lives in the cores. Keep it that way so a future migration to
 * `wordpress/mcp-adapter` can re-wrap the same cores without re-deriving
 * any mapping logic.
 */
class WC_AI_Storefront_MCP_Tools {

	/**
	 * tools/list payload. inputSchemas describe exactly the parameters the
	 * neutral cores honor (mirrored from the wc/ucp/v1 route handlers), with
	 * descriptions and nested properties so models call the tools correctly
	 * instead of guessing object shapes and sending empty placeholders.
	 *
	 * `signals` is intentionally omitted: the UCP cores accept it but MUST NOT
	 * honor it (per spec — see run_catalog_search), so advertising it would
	 * only invite agents to spend tokens populating a no-op. The server still
	 * tolerates a `signals` payload if one is sent.
	 *
	 * Schema keywords are kept close to the subset every major model's
	 * function-calling layer accepts: type, properties, items, required, enum,
	 * description — plus one `anyOf` on search_catalog. We deliberately avoid
	 * the numeric/array bound keywords `minItems`/`maxItems` and
	 * `minimum`/`maximum`: Gemini's function-declaration validator rejects them
	 * with a hard 400 that breaks tool registration for the WHOLE session, and
	 * they add little value, so those limits live in the prose descriptions
	 * (e.g. "1-100 ids", "rating 1-5") instead.
	 *
	 * `anyOf` is the one structural constraint we keep — it expresses
	 * search_catalog's "provide query and/or filters" rule, which no single
	 * `required` entry can (filters-only browse is valid). Caveat: Gemini's
	 * function-calling is documented to reject `anyOf` too, but we could not
	 * verify that here (the playground 500s were its own backend, not our
	 * schema). If a Gemini-specific tool-registration failure appears, drop
	 * this `anyOf` first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		return array(
			array(
				'name'        => 'search_catalog',
				'description' => __( 'Search the store catalog by keyword and/or structured filters; returns matching UCP products. Provide `query` for keyword search and/or `filters` to browse. At least one is recommended.', 'woocommerce-ai-storefront' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'      => array(
							'type'        => 'string',
							'description' => __( "Keyword search, e.g. 'blue hoodie'. Optional (you may browse with filters alone), but provide it for text search.", 'woocommerce-ai-storefront' ),
						),
						'filters'    => array(
							'type'        => 'object',
							'description' => __( 'Structured filters to narrow results. All fields optional.', 'woocommerce-ai-storefront' ),
							'properties'  => array(
								'categories' => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Category slugs or names to match.', 'woocommerce-ai-storefront' ),
								),
								'tags'       => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Tag slugs or names to match.', 'woocommerce-ai-storefront' ),
								),
								'brands'     => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Brand slugs or names to match.', 'woocommerce-ai-storefront' ),
								),
								'price'      => array(
									'type'        => 'object',
									'description' => __( 'Price range in minor units (e.g. cents), denominated in context.currency.', 'woocommerce-ai-storefront' ),
									'properties'  => array(
										'min' => array(
											'type'        => 'integer',
											'description' => __( 'Minimum price in minor units.', 'woocommerce-ai-storefront' ),
										),
										'max' => array(
											'type'        => 'integer',
											'description' => __( 'Maximum price in minor units.', 'woocommerce-ai-storefront' ),
										),
									),
								),
								'on_sale'    => array(
									'type'        => 'boolean',
									'description' => __( 'Only products currently on sale.', 'woocommerce-ai-storefront' ),
								),
								'in_stock'   => array(
									'type'        => 'boolean',
									'description' => __( 'Only in-stock products.', 'woocommerce-ai-storefront' ),
								),
								'featured'   => array(
									'type'        => 'boolean',
									'description' => __( 'Only featured products.', 'woocommerce-ai-storefront' ),
								),
								'min_rating' => array(
									'type'        => 'integer',
									'description' => __( 'Minimum average star rating, from 1 to 5.', 'woocommerce-ai-storefront' ),
								),
								'attributes' => array(
									'type'        => 'object',
									'description' => __( 'Map of attribute slug to an array of accepted values, e.g. {"color":["blue"],"size":["M"]}.', 'woocommerce-ai-storefront' ),
								),
							),
						),
						'sort'       => array(
							'type'        => 'object',
							'description' => __( 'Result ordering.', 'woocommerce-ai-storefront' ),
							'properties'  => array(
								'field'     => array(
									'type'        => 'string',
									'enum'        => array( 'price', 'title', 'date', 'newest', 'popularity', 'rating', 'menu_order' ),
									'description' => __( "Field to sort by. 'newest' is an alias for date descending.", 'woocommerce-ai-storefront' ),
								),
								'direction' => array(
									'type'        => 'string',
									'enum'        => array( 'asc', 'desc' ),
									'description' => __( "Sort direction. Defaults to 'asc'; ignored for 'newest' (always descending).", 'woocommerce-ai-storefront' ),
								),
							),
						),
						'pagination' => array(
							'type'        => 'object',
							'description' => __( 'Pagination controls.', 'woocommerce-ai-storefront' ),
							'properties'  => array(
								'limit'  => array(
									'type'        => 'integer',
									'description' => __( 'Maximum number of products to return (a positive integer).', 'woocommerce-ai-storefront' ),
								),
								'cursor' => array(
									'type'        => 'string',
									'description' => __( "Opaque cursor from a prior response's pagination.cursor, to fetch the next page.", 'woocommerce-ai-storefront' ),
								),
							),
						),
						'context'    => self::context_schema(),
					),
					// A meaningful search needs a keyword or at least one filter.
					// Expressed as anyOf (not a hard `required: [query]`) so the
					// filters-only browse the core supports stays valid. See the
					// class docblock re: the Gemini anyOf caveat.
					'anyOf'      => array(
						array( 'required' => array( 'query' ) ),
						array( 'required' => array( 'filters' ) ),
					),
				),
			),
			array(
				'name'        => 'lookup_catalog',
				/* translators: `search_catalog` is a UCP tool name — do not translate it. */
				'description' => __( 'Look up specific products by their UCP id; returns full UCP product records. Use ids returned by search_catalog.', 'woocommerce-ai-storefront' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( "UCP product ids to fetch, e.g. ['prod_123','var_456']. Provide between 1 and 100 ids.", 'woocommerce-ai-storefront' ),
						),
						'context' => self::context_schema(),
					),
					'required'   => array( 'ids' ),
				),
			),
			array(
				'name'        => 'create_checkout',
				'description' => __( 'Create a stateless checkout session for one or more products and return a continue_url to redirect the shopper to. Does not place the order.', 'woocommerce-ai-storefront' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'line_items' => array(
							'type'        => 'array',
							'description' => __( 'Items to purchase (at least one).', 'woocommerce-ai-storefront' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'item'     => array(
										'type'        => 'object',
										'description' => __( 'The product or variation to add.', 'woocommerce-ai-storefront' ),
										'properties'  => array(
											'id' => array(
												'type' => 'string',
												'description' => __( "UCP product or variation id, e.g. 'prod_123' or 'var_456'.", 'woocommerce-ai-storefront' ),
											),
										),
										'required'    => array( 'id' ),
									),
									'quantity' => array(
										'type'        => 'integer',
										'description' => __( 'Quantity to purchase (a positive integer). Defaults to 1.', 'woocommerce-ai-storefront' ),
									),
								),
								'required'   => array( 'item' ),
							),
						),
						'context'    => self::context_schema(),
					),
					'required'   => array( 'line_items' ),
				),
			),
		);
	}

	/**
	 * Shared schema for the optional UCP `context` object, honored by every
	 * tool (currency drives price-filter denomination + price display; locale
	 * is advisory). Mirrors how get_currency_from_context / the price-filter
	 * branch read context in the REST controller.
	 *
	 * @return array<string,mixed>
	 */
	private static function context_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'Optional request context.', 'woocommerce-ai-storefront' ),
			'properties'  => array(
				'currency' => array(
					'type'        => 'string',
					'description' => __( "ISO 4217 currency code, e.g. 'USD', for price-filter denomination and price display.", 'woocommerce-ai-storefront' ),
				),
				'locale'   => array(
					'type'        => 'string',
					'description' => __( "BCP 47 locale, e.g. 'en-US'.", 'woocommerce-ai-storefront' ),
				),
			),
		);
	}

	/**
	 * Dispatch a tool call to its neutral core and return an MCP tool result.
	 *
	 * @param string $tool_name   search_catalog|lookup_catalog|create_checkout.
	 * @param array  $arguments   Validated tool arguments.
	 * @param string $client_name Raw agent handshake name from the session.
	 * @return array|WP_Error MCP tools/call result, or WP_Error for unknown tool.
	 */
	public static function call( string $tool_name, array $arguments, string $client_name ) {
		// Resolve the raw handshake name into the same attribution triple the
		// REST transport produces (resolve_agent_host), so MCP-originated orders
		// land in the same WC Order Attribution cohort (utm_source) as REST.
		$agent_data = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( $client_name );
		$base       = array(
			'agent_data'       => $agent_data,
			'ucp_agent_header' => '',
		);
		$controller = new WC_AI_Storefront_UCP_REST_Controller();

		switch ( $tool_name ) {
			case 'search_catalog':
				$params = array_merge(
					$base,
					array(
						'query'      => $arguments['query'] ?? null,
						'context'    => $arguments['context'] ?? null,
						'signals'    => $arguments['signals'] ?? null,
						'filters'    => $arguments['filters'] ?? null,
						'pagination' => $arguments['pagination'] ?? null,
						'sort'       => $arguments['sort'] ?? null,
					)
				);
				$result = $controller->run_catalog_search( $params );

				// MCP has no response headers, so the REST transport's
				// X-WC-AI-Storefront-Unknown-Params advisory has nowhere to
				// go here. Carry the same detection in `messages[]` — the
				// body channel this class already reads — so an MCP client
				// that pages with `pagination.page` (the GET-only spelling)
				// learns its paging did nothing, instead of re-reading page
				// one and believing it advanced. Runs against the raw
				// `$arguments`: `$params` above copies only the keys the
				// core honors, so by that point the unrecognized ones are
				// already gone. The MCP tool schemas declare no
				// `additionalProperties: false` and the server does no
				// argument validation, so nothing else catches them.
				$unknown_params = WC_AI_Storefront_UCP_REST_Controller::unknown_search_params_message( $arguments );
				if ( null !== $unknown_params ) {
					$result['body']['messages'][] = $unknown_params;
				}

				return self::core_result_to_mcp(
					$result,
					__( 'Catalog search', 'woocommerce-ai-storefront' ),
					false,
					array( self::class, 'summarize_search' )
				);

			case 'lookup_catalog':
				$params = array_merge(
					$base,
					array(
						// Pass null (not []) when ids is absent so the core can
						// distinguish "missing ids array" from "empty ids array"
						// and return the accurate error message.
						'ids'     => $arguments['ids'] ?? null,
						'context' => $arguments['context'] ?? null,
						'signals' => $arguments['signals'] ?? null,
					)
				);
				return self::core_result_to_mcp(
					$controller->run_catalog_lookup( $params ),
					__( 'Catalog lookup', 'woocommerce-ai-storefront' ),
					false,
					array( self::class, 'summarize_lookup' )
				);

			case 'create_checkout':
				$params = array_merge(
					$base,
					array(
						'line_items' => $arguments['line_items'] ?? array(),
						'context'    => $arguments['context'] ?? null,
					)
				);
				return self::core_result_to_mcp(
					$controller->run_checkout_create( $params ),
					__( 'Checkout', 'woocommerce-ai-storefront' ),
					// Require a continue_url: a checkout that resolved no items
					// returns HTTP 200 with the reason in messages[], and must
					// surface as an MCP error rather than a silent success.
					true,
					array( self::class, 'summarize_checkout' )
				);

			default:
				return new WP_Error( 'mcp_unknown_tool', __( 'Unknown tool.', 'woocommerce-ai-storefront' ) );
		}
	}

	/**
	 * Map a neutral-core ['body','status'] result to an MCP tools/call result.
	 *
	 * On the error path the cores return a UCP envelope whose error detail
	 * lives in `messages[0]` (built by ucp_catalog_error_response /
	 * ucp_checkout_error_response — `{ type, code, severity, content }`),
	 * NOT under a top-level `error` key. We read that real shape: the first
	 * `error`-typed message's `code` + `content` form the MCP error text.
	 *
	 * @param array         $result             ['body'=>array,'status'=>int].
	 * @param string        $summary            Fallback one-line label for the text
	 *                                          block when no summarizer is supplied.
	 * @param bool          $require_continue_url Treat a 200 with no continue_url as
	 *                                          an error (checkout only).
	 * @param callable|null $summarize_success  Optional fn(array $body):string that
	 *                                          builds the success text block from the
	 *                                          response body. Runs ONLY on the success
	 *                                          path; the error path always derives its
	 *                                          text from messages[]. Falls back to
	 *                                          $summary when null. This is what surfaces
	 *                                          results into the model-visible `content`
	 *                                          channel (clients may not read
	 *                                          structuredContent), per the MCP spec's
	 *                                          "also return serialized text" guidance.
	 * @return array MCP tool result.
	 */
	public static function core_result_to_mcp( array $result, string $summary, bool $require_continue_url = false, ?callable $summarize_success = null ): array {
		$status = (int) ( $result['status'] ?? 200 );
		$body   = is_array( $result['body'] ?? null ) ? $result['body'] : array();

		// A checkout that produced no continue_url is a total failure even when
		// the core returns HTTP 200 with the reason in messages[] (e.g. every
		// line item was not_found). Without this, an agent reads "Checkout" +
		// isError:false as success. Partial success still carries a
		// continue_url for the resolvable items and is NOT flagged.
		$checkout_failed = $require_continue_url && empty( $body['continue_url'] );

		if ( $status >= 400 || $checkout_failed ) {
			$messages = isset( $body['messages'] ) && is_array( $body['messages'] )
				? $body['messages']
				: array();

			// Phase 1: prefer the first error-typed message.
			$error_msg = null;
			foreach ( $messages as $msg ) {
				if ( is_array( $msg ) && 'error' === ( $msg['type'] ?? '' ) ) {
					$error_msg = $msg;
					break;
				}
			}

			// Phase 2: fall back to the first message of any type so a
			// non-conforming envelope still yields some detail.
			if ( null === $error_msg ) {
				$first     = $messages[0] ?? null;
				$error_msg = is_array( $first ) ? $first : array();
			}

			$code    = (string) ( $error_msg['code'] ?? '' );
			$message = (string) ( $error_msg['content'] ?? '' );
			if ( '' === $code ) {
				// No messages[] at all — log so the developer can trace which
				// core path produced an envelope without error detail.
				WC_AI_Storefront_Logger::debug(
					'MCP core_result_to_mcp: HTTP %d response had no messages — summary: %s',
					$status,
					$summary
				);
				$code    = 'error';
				$message = sprintf(
					/* translators: 1: operation label, e.g. "Catalog search", 2: HTTP status code. */
					__( '%1$s failed (HTTP %2$d).', 'woocommerce-ai-storefront' ),
					$summary,
					$status
				);
			}

			return array(
				'isError' => true,
				'content' => array(
					array(
						'type' => 'text',
						'text' => trim( $code . ' ' . $message ),
					),
				),
			);
		}

		$text = null !== $summarize_success
			? (string) call_user_func( $summarize_success, $body )
			: $summary;

		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'structuredContent' => $body,
		);
	}

	/**
	 * Maximum products enumerated in a result's text summary before the
	 * remainder collapses into an "…and N more" note. Bounds the token cost
	 * of the model-facing text channel on large lookups (up to 100 ids).
	 */
	const SUMMARY_MAX_ITEMS = 10;

	/**
	 * ISO 4217 currencies with no minor unit — the stored minor-unit amount
	 * IS the major value (no division). Anything not listed here or in
	 * THREE_DECIMAL_CURRENCIES is treated as 2-decimal.
	 *
	 * @var string[]
	 */
	private const ZERO_DECIMAL_CURRENCIES = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * ISO 4217 currencies whose minor unit has three digits.
	 *
	 * @var string[]
	 */
	private const THREE_DECIMAL_CURRENCIES = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Build the `content` text for a successful search_catalog result.
	 *
	 * @param array $body Search response body (`products`, `pagination`).
	 * @return string
	 */
	public static function summarize_search( array $body ): string {
		$products = self::products_of( $body );
		if ( empty( $products ) ) {
			$pagination = is_array( $body['pagination'] ?? null ) ? $body['pagination'] : array();
			$text       = __( 'No products matched.', 'woocommerce-ai-storefront' );

			// A page can come back empty because every product on it was
			// suppressed (#658 unpriced-product filtering), not because the
			// store has nothing — has_next_page still says whether later
			// pages are worth trying before the model gives up on the query.
			if ( ! empty( $pagination['has_next_page'] ) ) {
				$text .= ' ' . __( 'More available. Pass pagination.cursor for the next page.', 'woocommerce-ai-storefront' );
			}

			return self::with_unknown_params_note( $text, $body );
		}

		$count      = count( $products );
		$pagination = is_array( $body['pagination'] ?? null ) ? $body['pagination'] : array();
		$total      = isset( $pagination['total_count'] ) ? (int) $pagination['total_count'] : null;

		$head = ( null !== $total && $total > $count )
			? sprintf(
				/* translators: 1: products returned in this page, 2: total matching products. */
				__( '%1$d of %2$d products', 'woocommerce-ai-storefront' ),
				$count,
				$total
			)
			: self::product_count_label( $count );

		$text = $head . ' — ' . implode( '; ', self::product_lines( $products ) ) . '.';

		if ( ! empty( $pagination['has_next_page'] ) ) {
			$text .= ' ' . __( 'More available. Pass pagination.cursor for the next page.', 'woocommerce-ai-storefront' );
		}

		return self::with_unknown_params_note( $text, $body );
	}

	/**
	 * Append the unrecognized-parameters advisory to a summary line.
	 *
	 * `structuredContent` already carries the whole `messages[]` array,
	 * but clients are not obliged to read it (see core_result_to_mcp),
	 * so an advisory that lives only there can still never reach the
	 * model. summarize_lookup() and summarize_checkout() already mirror
	 * their messages into the text channel; search does the same for
	 * this one, and names the offending keys so the model can correct
	 * the call rather than just learn that something was wrong.
	 *
	 * @param string $text Summary text built by the caller.
	 * @param array  $body Response body, possibly carrying `messages[]`.
	 * @return string
	 */
	private static function with_unknown_params_note( string $text, array $body ): string {
		$messages = is_array( $body['messages'] ?? null ) ? $body['messages'] : array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			if ( WC_AI_Storefront_UCP_Error_Codes::UNKNOWN_PARAMS !== ( $message['code'] ?? '' ) ) {
				continue;
			}
			return trim( $text . ' ' . (string) ( $message['content'] ?? '' ) );
		}

		return $text;
	}

	/**
	 * Build the `content` text for a successful lookup_catalog result.
	 *
	 * The "some ids were not found" wording is only honest when every
	 * message actually carries the `not_found` code. A message can also
	 * carry `item_unpurchasable` (#658 unpriced-product suppression) —
	 * that ID resolved to a real product the store is declining to
	 * syndicate, the opposite of "not found". Telling the model the
	 * product doesn't exist would be exactly the lie that code was
	 * introduced to avoid, so the wording branches on the codes present
	 * rather than only on whether any messages exist.
	 *
	 * @param array $body Lookup response body (`products`, optional `messages`).
	 * @return string
	 */
	public static function summarize_lookup( array $body ): string {
		$products      = self::products_of( $body );
		$messages      = is_array( $body['messages'] ?? null ) ? $body['messages'] : array();
		$has_messages  = ! empty( $messages );
		$all_not_found = self::messages_are_all_not_found( $messages );

		if ( empty( $products ) ) {
			if ( ! $has_messages ) {
				return __( 'No products found for the given ids.', 'woocommerce-ai-storefront' );
			}
			return $all_not_found
				? __( 'No products found for the given ids (see messages).', 'woocommerce-ai-storefront' )
				: __( 'No products could be returned for the given ids (see messages).', 'woocommerce-ai-storefront' );
		}

		$text = self::product_count_label( count( $products ) )
			. ' — ' . implode( '; ', self::product_lines( $products ) ) . '.';

		if ( $has_messages ) {
			$text .= ' ' . ( $all_not_found
				? __( 'Note: some ids were not found (see messages).', 'woocommerce-ai-storefront' )
				: __( 'Note: some ids could not be returned (see messages).', 'woocommerce-ai-storefront' ) );
		}

		return $text;
	}

	/**
	 * Whether every message in `messages[]` carries the `not_found` code.
	 *
	 * Distinguishes "every missing id genuinely doesn't exist" from "at
	 * least one id exists but the store is withholding it" (e.g. the
	 * #658 `item_unpurchasable` code) — see the summarize_lookup()
	 * docblock for why that distinction matters to the wording.
	 *
	 * @param array $messages Response `messages[]` array.
	 * @return bool True when non-empty and every entry's code is not_found.
	 */
	private static function messages_are_all_not_found( array $messages ): bool {
		if ( empty( $messages ) ) {
			return false;
		}

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				return false;
			}
			if ( WC_AI_Storefront_UCP_Error_Codes::NOT_FOUND !== ( $message['code'] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the `content` text for a successful create_checkout result.
	 *
	 * @param array $body Checkout response body (`continue_url`, `line_items`).
	 * @return string
	 */
	public static function summarize_checkout( array $body ): string {
		$url = is_string( $body['continue_url'] ?? null ) ? $body['continue_url'] : '';
		if ( '' === $url ) {
			// Defensive: the require_continue_url path already routes empty-url
			// checkouts to the error branch, so a success summary always has one.
			return __( 'Checkout created.', 'woocommerce-ai-storefront' );
		}

		$items = is_array( $body['line_items'] ?? null ) ? count( $body['line_items'] ) : 0;
		if ( $items < 1 ) {
			$lead = __( 'Checkout ready.', 'woocommerce-ai-storefront' );
		} else {
			$lead = sprintf(
				/* translators: %d: number of line items in the checkout. */
				_n( 'Checkout ready for %d item.', 'Checkout ready for %d items.', $items, 'woocommerce-ai-storefront' ),
				$items
			);
		}

		$text = $lead . ' ' . sprintf(
			/* translators: %s: URL to continue the purchase on the merchant site. */
			__( 'Continue at %s', 'woocommerce-ai-storefront' ),
			$url
		);

		if ( ! empty( $body['messages'] ) && is_array( $body['messages'] ) ) {
			$text .= ' ' . __( 'Note: some items were skipped (see messages).', 'woocommerce-ai-storefront' );
		}

		return $text;
	}

	/**
	 * Format a minor-unit amount with its currency's correct decimal places.
	 *
	 * @param int    $amount_minor Amount in the currency's minor units.
	 * @param string $currency     ISO 4217 code (defaults to USD when blank).
	 * @return string e.g. "USD 48.00", "JPY 4,800" (number_format adds a
	 *                thousands separator).
	 */
	public static function format_money( int $amount_minor, string $currency ): string {
		$currency = '' !== $currency ? strtoupper( $currency ) : 'USD';
		$decimals = self::decimals_for( $currency );
		return $currency . ' ' . number_format( $amount_minor / ( 10 ** $decimals ), $decimals );
	}

	/**
	 * Decimal places for a currency code.
	 *
	 * @param string $currency Uppercase ISO 4217 code.
	 * @return int 0, 2, or 3.
	 */
	private static function decimals_for( string $currency ): int {
		if ( in_array( $currency, self::ZERO_DECIMAL_CURRENCIES, true ) ) {
			return 0;
		}
		if ( in_array( $currency, self::THREE_DECIMAL_CURRENCIES, true ) ) {
			return 3;
		}
		return 2;
	}

	/**
	 * Extract the `products` array from a response body, keeping only the
	 * array-shaped entries (defensive against a malformed envelope).
	 *
	 * @param array $body Response body.
	 * @return array<int,array<string,mixed>>
	 */
	private static function products_of( array $body ): array {
		$products = is_array( $body['products'] ?? null ) ? $body['products'] : array();
		return array_values( array_filter( $products, 'is_array' ) );
	}

	/**
	 * "%d product" / "%d products", pluralized via _n().
	 *
	 * @param int $count Product count.
	 * @return string
	 */
	private static function product_count_label( int $count ): string {
		return sprintf(
			/* translators: %d: number of products. */
			_n( '%d product', '%d products', $count, 'woocommerce-ai-storefront' ),
			$count
		);
	}

	/**
	 * Render up to SUMMARY_MAX_ITEMS products as "Title (id) PRICE" lines,
	 * collapsing any remainder into an "…and N more" entry.
	 *
	 * @param array<int,array<string,mixed>> $products Array-shaped products.
	 * @return string[]
	 */
	private static function product_lines( array $products ): array {
		$shown = array_slice( $products, 0, self::SUMMARY_MAX_ITEMS );
		$lines = array_map( array( self::class, 'product_line' ), $shown );

		$extra = count( $products ) - count( $shown );
		if ( $extra > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of additional products not individually listed. */
				__( '…and %d more', 'woocommerce-ai-storefront' ),
				$extra
			);
		}

		return $lines;
	}

	/**
	 * Render one product as "Title (id) PRICE" (price omitted when absent).
	 *
	 * @param array<string,mixed> $product UCP product shape.
	 * @return string
	 */
	private static function product_line( array $product ): string {
		$id    = is_string( $product['id'] ?? null ) ? $product['id'] : '';
		$title = is_string( $product['title'] ?? null ) ? trim( $product['title'] ) : '';
		$label = '' !== $title ? sprintf( '%s (%s)', $title, $id ) : $id;
		$price = self::format_price_range( $product['price_range'] ?? null );

		return '' !== $price ? trim( $label . ' ' . $price ) : $label;
	}

	/**
	 * Format a UCP `price_range` ({min,max} money objects) for display.
	 * A single price when min == max; a "LO–HI" range otherwise (the second
	 * currency code is elided when both bounds share one). Returns '' when the
	 * shape can't be read cleanly, so price is simply omitted rather than wrong.
	 *
	 * @param mixed $range The product's price_range value.
	 * @return string
	 */
	private static function format_price_range( $range ): string {
		if ( ! is_array( $range ) ) {
			return '';
		}
		$min = is_array( $range['min'] ?? null ) ? $range['min'] : null;
		$max = is_array( $range['max'] ?? null ) ? $range['max'] : null;
		if ( null === $min || ! isset( $min['amount'] ) ) {
			return '';
		}
		// A non-numeric `amount` (e.g. a nested array, or a non-numeric string from
		// a filter that mutated the envelope) would (int)-coerce to a plausible but
		// WRONG price. Omit the price instead — honoring this method's "omit rather
		// than wrong" contract — and debug-log so the upstream shape bug is traceable
		// (mirrors the missing-messages[] log in core_result_to_mcp).
		if ( ! is_numeric( $min['amount'] ) ) {
			WC_AI_Storefront_Logger::debug(
				'MCP summary: price_range.min.amount was non-numeric (%s) — price omitted.',
				gettype( $min['amount'] )
			);
			return '';
		}

		$min_amount = (int) $min['amount'];
		// A non-numeric max degrades to the min (rendered as a single price), never
		// a fabricated range bound.
		$max_amount   = ( isset( $max['amount'] ) && is_numeric( $max['amount'] ) ) ? (int) $max['amount'] : $min_amount;
		$min_currency = is_string( $min['currency'] ?? null ) ? strtoupper( $min['currency'] ) : 'USD';
		$max_currency = is_string( $max['currency'] ?? null ) ? strtoupper( $max['currency'] ) : $min_currency;

		if ( $max_amount === $min_amount ) {
			return self::format_money( $min_amount, $min_currency );
		}

		if ( $max_currency === $min_currency ) {
			$decimals = self::decimals_for( $min_currency );
			return $min_currency . ' '
				. number_format( $min_amount / ( 10 ** $decimals ), $decimals )
				. '–'
				. number_format( $max_amount / ( 10 ** $decimals ), $decimals );
		}

		return self::format_money( $min_amount, $min_currency ) . '–' . self::format_money( $max_amount, $max_currency );
	}
}
