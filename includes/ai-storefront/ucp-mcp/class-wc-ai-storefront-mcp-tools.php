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
	 * description — plus one `anyOf` on catalog_search. We deliberately avoid
	 * the numeric/array bound keywords `minItems`/`maxItems` and
	 * `minimum`/`maximum`: Gemini's function-declaration validator rejects them
	 * with a hard 400 that breaks tool registration for the WHOLE session, and
	 * they add little value, so those limits live in the prose descriptions
	 * (e.g. "1-100 ids", "rating 1-5") instead.
	 *
	 * `anyOf` is the one structural constraint we keep — it expresses
	 * catalog_search's "provide query and/or filters" rule, which no single
	 * `required` entry can (filters-only browse is valid). Caveat: Gemini's
	 * function-calling is documented to reject `anyOf` too, but we could not
	 * verify that here (the playground 500s were its own backend, not our
	 * schema). If a Gemini-specific tool-registration failure appears, drop
	 * this `anyOf` first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		return [
			[
				'name'        => 'catalog_search',
				'description' => __( 'Search the store catalog by keyword and/or structured filters; returns matching UCP products. Provide `query` for keyword search and/or `filters` to browse — at least one is recommended.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'query'      => [
							'type'        => 'string',
							'description' => __( "Keyword search, e.g. 'blue hoodie'. Optional — you may browse with filters alone — but provide it for text search.", 'woocommerce-ai-storefront' ),
						],
						'filters'    => [
							'type'        => 'object',
							'description' => __( 'Structured filters to narrow results. All fields optional.', 'woocommerce-ai-storefront' ),
							'properties'  => [
								'categories' => [
									'type'        => 'array',
									'items'       => [ 'type' => 'string' ],
									'description' => __( 'Category slugs or names to match.', 'woocommerce-ai-storefront' ),
								],
								'tags'       => [
									'type'        => 'array',
									'items'       => [ 'type' => 'string' ],
									'description' => __( 'Tag slugs or names to match.', 'woocommerce-ai-storefront' ),
								],
								'brand'      => [
									'type'        => 'array',
									'items'       => [ 'type' => 'string' ],
									'description' => __( 'Brand slugs or names to match.', 'woocommerce-ai-storefront' ),
								],
								'price'      => [
									'type'        => 'object',
									'description' => __( 'Price range in minor units (e.g. cents), denominated in context.currency.', 'woocommerce-ai-storefront' ),
									'properties'  => [
										'min' => [
											'type'        => 'integer',
											'description' => __( 'Minimum price in minor units.', 'woocommerce-ai-storefront' ),
										],
										'max' => [
											'type'        => 'integer',
											'description' => __( 'Maximum price in minor units.', 'woocommerce-ai-storefront' ),
										],
									],
								],
								'on_sale'    => [
									'type'        => 'boolean',
									'description' => __( 'Only products currently on sale.', 'woocommerce-ai-storefront' ),
								],
								'in_stock'   => [
									'type'        => 'boolean',
									'description' => __( 'Only in-stock products.', 'woocommerce-ai-storefront' ),
								],
								'featured'   => [
									'type'        => 'boolean',
									'description' => __( 'Only featured products.', 'woocommerce-ai-storefront' ),
								],
								'min_rating' => [
									'type'        => 'integer',
									'description' => __( 'Minimum average star rating, from 1 to 5.', 'woocommerce-ai-storefront' ),
								],
								'attributes' => [
									'type'        => 'object',
									'description' => __( 'Map of attribute slug to an array of accepted values, e.g. {"color":["blue"],"size":["M"]}.', 'woocommerce-ai-storefront' ),
								],
							],
						],
						'sort'       => [
							'type'        => 'object',
							'description' => __( 'Result ordering.', 'woocommerce-ai-storefront' ),
							'properties'  => [
								'field'     => [
									'type'        => 'string',
									'enum'        => [ 'price', 'title', 'date', 'newest', 'popularity', 'rating', 'menu_order' ],
									'description' => __( "Field to sort by. 'newest' is an alias for date descending.", 'woocommerce-ai-storefront' ),
								],
								'direction' => [
									'type'        => 'string',
									'enum'        => [ 'asc', 'desc' ],
									'description' => __( "Sort direction. Defaults to 'asc'; ignored for 'newest' (always descending).", 'woocommerce-ai-storefront' ),
								],
							],
						],
						'pagination' => [
							'type'        => 'object',
							'description' => __( 'Pagination controls.', 'woocommerce-ai-storefront' ),
							'properties'  => [
								'limit'  => [
									'type'        => 'integer',
									'description' => __( 'Maximum number of products to return (a positive integer).', 'woocommerce-ai-storefront' ),
								],
								'cursor' => [
									'type'        => 'string',
									'description' => __( "Opaque cursor from a prior response's pagination.cursor, to fetch the next page.", 'woocommerce-ai-storefront' ),
								],
							],
						],
						'context'    => self::context_schema(),
					],
					// A meaningful search needs a keyword or at least one filter.
					// Expressed as anyOf (not a hard `required: [query]`) so the
					// filters-only browse the core supports stays valid. See the
					// class docblock re: the Gemini anyOf caveat.
					'anyOf'      => [
						[ 'required' => [ 'query' ] ],
						[ 'required' => [ 'filters' ] ],
					],
				],
			],
			[
				'name'        => 'catalog_lookup',
				'description' => __( 'Look up specific products by their UCP id; returns full UCP product records. Use ids returned by catalog_search.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'ids'     => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( "UCP product ids to fetch, e.g. ['prod_123','var_456']. Provide between 1 and 100 ids.", 'woocommerce-ai-storefront' ),
						],
						'context' => self::context_schema(),
					],
					'required'   => [ 'ids' ],
				],
			],
			[
				'name'        => 'checkout_create',
				'description' => __( 'Create a stateless checkout session for one or more products and return a continue_url to redirect the shopper to. Does not place the order.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'line_items' => [
							'type'        => 'array',
							'description' => __( 'Items to purchase (at least one).', 'woocommerce-ai-storefront' ),
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'item'     => [
										'type'        => 'object',
										'description' => __( 'The product or variation to add.', 'woocommerce-ai-storefront' ),
										'properties'  => [
											'id' => [
												'type' => 'string',
												'description' => __( "UCP product or variation id, e.g. 'prod_123' or 'var_456'.", 'woocommerce-ai-storefront' ),
											],
										],
										'required'    => [ 'id' ],
									],
									'quantity' => [
										'type'        => 'integer',
										'description' => __( 'Quantity to purchase (a positive integer). Defaults to 1.', 'woocommerce-ai-storefront' ),
									],
								],
								'required'   => [ 'item' ],
							],
						],
						'context'    => self::context_schema(),
					],
					'required'   => [ 'line_items' ],
				],
			],
		];
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
		return [
			'type'        => 'object',
			'description' => __( 'Optional request context.', 'woocommerce-ai-storefront' ),
			'properties'  => [
				'currency' => [
					'type'        => 'string',
					'description' => __( "ISO 4217 currency code, e.g. 'USD', for price-filter denomination and price display.", 'woocommerce-ai-storefront' ),
				],
				'locale'   => [
					'type'        => 'string',
					'description' => __( "BCP 47 locale, e.g. 'en-US'.", 'woocommerce-ai-storefront' ),
				],
			],
		];
	}

	/**
	 * Dispatch a tool call to its neutral core and return an MCP tool result.
	 *
	 * @param string $tool_name   catalog_search|catalog_lookup|checkout_create.
	 * @param array  $arguments   Validated tool arguments.
	 * @param string $client_name Raw agent handshake name from the session.
	 * @return array|WP_Error MCP tools/call result, or WP_Error for unknown tool.
	 */
	public static function call( string $tool_name, array $arguments, string $client_name ) {
		// Resolve the raw handshake name into the same attribution triple the
		// REST transport produces (resolve_agent_host), so MCP-originated orders
		// land in the same WC Order Attribution cohort (utm_source) as REST.
		$agent_data = WC_AI_Storefront_UCP_REST_Controller::resolve_agent_data_from_name( $client_name );
		$base       = [
			'agent_data'       => $agent_data,
			'ucp_agent_header' => '',
		];
		$controller = new WC_AI_Storefront_UCP_REST_Controller();

		switch ( $tool_name ) {
			case 'catalog_search':
				$params = array_merge(
					$base,
					[
						'query'      => $arguments['query'] ?? null,
						'context'    => $arguments['context'] ?? null,
						'signals'    => $arguments['signals'] ?? null,
						'filters'    => $arguments['filters'] ?? null,
						'pagination' => $arguments['pagination'] ?? null,
						'sort'       => $arguments['sort'] ?? null,
					]
				);
				return self::core_result_to_mcp(
					$controller->run_catalog_search( $params ),
					__( 'Catalog search', 'woocommerce-ai-storefront' )
				);

			case 'catalog_lookup':
				$params = array_merge(
					$base,
					[
						// Pass null (not []) when ids is absent so the core can
						// distinguish "missing ids array" from "empty ids array"
						// and return the accurate error message.
						'ids'     => $arguments['ids'] ?? null,
						'context' => $arguments['context'] ?? null,
						'signals' => $arguments['signals'] ?? null,
					]
				);
				return self::core_result_to_mcp(
					$controller->run_catalog_lookup( $params ),
					__( 'Catalog lookup', 'woocommerce-ai-storefront' )
				);

			case 'checkout_create':
				$params = array_merge(
					$base,
					[
						'line_items' => $arguments['line_items'] ?? [],
						'context'    => $arguments['context'] ?? null,
					]
				);
				return self::core_result_to_mcp(
					$controller->run_checkout_create( $params ),
					__( 'Checkout', 'woocommerce-ai-storefront' )
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
	 * @param array  $result  ['body'=>array,'status'=>int].
	 * @param string $summary One-line summary label for the text block.
	 * @return array MCP tool result.
	 */
	public static function core_result_to_mcp( array $result, string $summary ): array {
		$status = (int) ( $result['status'] ?? 200 );
		$body   = is_array( $result['body'] ?? null ) ? $result['body'] : [];

		if ( $status >= 400 ) {
			$messages = isset( $body['messages'] ) && is_array( $body['messages'] )
				? $body['messages']
				: [];

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
				$error_msg = is_array( $first ) ? $first : [];
			}

			$code    = (string) ( $error_msg['code'] ?? '' );
			$message = (string) ( $error_msg['content'] ?? '' );
			if ( '' === $code ) {
				$code = 'error';
			}

			return [
				'isError' => true,
				'content' => [
					[
						'type' => 'text',
						'text' => trim( $code . ' ' . $message ),
					],
				],
			];
		}

		return [
			'content'           => [
				[
					'type' => 'text',
					'text' => $summary,
				],
			],
			'structuredContent' => $body,
		];
	}
}
