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
	 * tools/list payload. inputSchemas mirror the wc/ucp/v1 route args.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		return [
			[
				'name'        => 'catalog_search',
				'description' => __( 'Search the store catalog. Returns UCP products.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'query'      => [ 'type' => 'string' ],
						'context'    => [ 'type' => 'object' ],
						'signals'    => [ 'type' => 'object' ],
						'filters'    => [ 'type' => 'object' ],
						'pagination' => [ 'type' => 'object' ],
						'sort'       => [ 'type' => 'object' ],
					],
				],
			],
			[
				'name'        => 'catalog_lookup',
				'description' => __( 'Look up specific products by id. Max 100 ids.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'ids'     => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'context' => [ 'type' => 'object' ],
						'signals' => [ 'type' => 'object' ],
					],
					'required'   => [ 'ids' ],
				],
			],
			[
				'name'        => 'checkout_create',
				'description' => __( 'Create a stateless checkout session and get a continue_url.', 'woocommerce-ai-storefront' ),
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'line_items' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'context'    => [ 'type' => 'object' ],
					],
					'required'   => [ 'line_items' ],
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
