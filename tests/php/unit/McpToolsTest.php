<?php
/**
 * Unit tests for WC_AI_Storefront_MCP_Tools.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class McpToolsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		// _n returns the singular or plural format string by count; callers sprintf it.
		Functions\when( '_n' )->alias(
			static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_definitions_exposes_exactly_three_tools(): void {
		$defs  = WC_AI_Storefront_MCP_Tools::definitions();
		$names = array_column( $defs, 'name' );

		$this->assertCount( 3, $defs );
		$this->assertSame(
			array( 'catalog_search', 'catalog_lookup', 'checkout_create' ),
			$names
		);
	}

	public function test_every_tool_input_schema_is_an_object(): void {
		foreach ( WC_AI_Storefront_MCP_Tools::definitions() as $def ) {
			$this->assertArrayHasKey( 'inputSchema', $def );
			$this->assertSame( 'object', $def['inputSchema']['type'] );
		}
	}

	public function test_catalog_lookup_requires_ids(): void {
		$defs   = WC_AI_Storefront_MCP_Tools::definitions();
		$lookup = null;
		foreach ( $defs as $def ) {
			if ( 'catalog_lookup' === $def['name'] ) {
				$lookup = $def;
				break;
			}
		}

		$this->assertNotNull( $lookup );
		$this->assertContains( 'ids', $lookup['inputSchema']['required'] );
	}

	public function test_checkout_create_requires_line_items(): void {
		$defs     = WC_AI_Storefront_MCP_Tools::definitions();
		$checkout = null;
		foreach ( $defs as $def ) {
			if ( 'checkout_create' === $def['name'] ) {
				$checkout = $def;
				break;
			}
		}

		$this->assertNotNull( $checkout );
		$this->assertContains( 'line_items', $checkout['inputSchema']['required'] );
	}

	/**
	 * Find a tool definition by name (test helper).
	 *
	 * @param string $name Tool name.
	 * @return array<string,mixed>
	 */
	private function tool( string $name ): array {
		foreach ( WC_AI_Storefront_MCP_Tools::definitions() as $def ) {
			if ( $name === $def['name'] ) {
				return $def;
			}
		}
		$this->fail( "Tool not found: {$name}" );
	}

	/**
	 * Recursively collect every string array key in a nested structure.
	 *
	 * @param mixed $node
	 * @return string[]
	 */
	private function collect_schema_keys( $node ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}
		$keys = array();
		foreach ( $node as $k => $v ) {
			if ( is_string( $k ) ) {
				$keys[] = $k;
			}
			$keys = array_merge( $keys, $this->collect_schema_keys( $v ) );
		}
		return $keys;
	}

	public function test_every_tool_has_a_nonempty_description(): void {
		foreach ( WC_AI_Storefront_MCP_Tools::definitions() as $def ) {
			$this->assertArrayHasKey( 'description', $def );
			$this->assertNotEmpty( $def['description'], "{$def['name']} needs a description" );
		}
	}

	public function test_every_top_level_parameter_has_a_description(): void {
		// Models guess a parameter's purpose when it has no description; every
		// advertised parameter must carry one.
		foreach ( WC_AI_Storefront_MCP_Tools::definitions() as $def ) {
			foreach ( $def['inputSchema']['properties'] as $param => $schema ) {
				$this->assertNotEmpty(
					$schema['description'] ?? '',
					"{$def['name']}.{$param} needs a description"
				);
			}
		}
	}

	public function test_object_parameters_declare_nested_properties(): void {
		// A bare `{type:object}` tells a model nothing about what to send; every
		// honored object parameter must expose its nested shape. `attributes`
		// and `filters.price`-style free-form maps are exempt only where the
		// shape is genuinely dynamic (attributes), which we assert explicitly
		// below rather than skipping silently.
		$search = $this->tool( 'catalog_search' )['inputSchema']['properties'];
		foreach ( array( 'filters', 'sort', 'pagination', 'context' ) as $obj ) {
			$this->assertSame( 'object', $search[ $obj ]['type'] );
			$this->assertNotEmpty( $search[ $obj ]['properties'], "{$obj} needs nested properties" );
		}
		// attributes is a deliberately dynamic map (attribute slug => values).
		$this->assertSame( 'object', $search['filters']['properties']['attributes']['type'] );
	}

	public function test_signals_is_not_advertised(): void {
		// signals is accepted by the cores but never honored (UCP spec), so it
		// is deliberately omitted from the advertised schema to avoid agents
		// spending tokens on a no-op. The server still tolerates it if sent.
		foreach ( WC_AI_Storefront_MCP_Tools::definitions() as $def ) {
			$this->assertArrayNotHasKey(
				'signals',
				$def['inputSchema']['properties'],
				"{$def['name']} must not advertise the no-op signals param"
			);
		}
	}

	public function test_sort_field_enum_matches_core_allow_list(): void {
		// Lock the advertised sort vocabulary to the orderby_map the core
		// honors; drift here means agents get told about fields we ignore.
		$sort = $this->tool( 'catalog_search' )['inputSchema']['properties']['sort'];
		$this->assertSame(
			array( 'price', 'title', 'date', 'newest', 'popularity', 'rating', 'menu_order' ),
			$sort['properties']['field']['enum']
		);
		$this->assertSame( array( 'asc', 'desc' ), $sort['properties']['direction']['enum'] );
	}

	public function test_checkout_line_item_shape_requires_item_id(): void {
		// Mirrors process_line_item: each entry is { item: { id }, quantity }.
		$items = $this->tool( 'checkout_create' )['inputSchema']['properties']['line_items']['items'];
		$this->assertSame( 'object', $items['type'] );
		$this->assertContains( 'item', $items['required'] );
		$this->assertContains( 'id', $items['properties']['item']['required'] );
	}

	public function test_catalog_search_requires_query_or_filters(): void {
		// catalog_search has no single top-level required field (filters-only
		// browse is valid), so the "query and/or filters" rule is expressed as
		// an anyOf and reinforced in the description. (anyOf is a documented
		// Gemini function-calling risk — see the class docblock — but it's the
		// only machine-readable way to say "at least one of these two".)
		$tool   = $this->tool( 'catalog_search' );
		$schema = $tool['inputSchema'];
		$this->assertArrayNotHasKey( 'required', $schema );
		$this->assertArrayHasKey( 'anyOf', $schema );
		$required_sets = array_map( static fn( array $b ) => $b['required'], $schema['anyOf'] );
		$this->assertContains( array( 'query' ), $required_sets );
		$this->assertContains( array( 'filters' ), $required_sets );
		$this->assertMatchesRegularExpression( '/query/i', $tool['description'] );
		$this->assertMatchesRegularExpression( '/filters/i', $tool['description'] );
	}

	public function test_schemas_avoid_bounds_keywords_for_gemini_compat(): void {
		// Gemini's function-declaration validator 400s on array/number bound
		// keywords, breaking tool registration for the whole session. Keep them
		// out of every inputSchema (the limits live in descriptions instead).
		// `anyOf` is deliberately retained on catalog_search — see the dedicated
		// test above — so it is NOT in this forbidden set.
		$forbidden = array( 'oneOf', 'allOf', 'minItems', 'maxItems', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum' );
		$keys      = $this->collect_schema_keys( array_column( WC_AI_Storefront_MCP_Tools::definitions(), 'inputSchema' ) );
		foreach ( $forbidden as $kw ) {
			$this->assertNotContains( $kw, $keys, "inputSchema must not use '{$kw}'" );
		}
	}

	public function test_core_result_to_mcp_maps_success_to_structured_content(): void {
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array( 'ok' => true ),
				'status' => 200,
			),
			'x'
		);

		$this->assertArrayNotHasKey( 'isError', $result );
		$this->assertSame( array( 'ok' => true ), $result['structuredContent'] );
		$this->assertSame( 'x', $result['content'][0]['text'] );
	}

	public function test_core_result_to_mcp_maps_error_envelope_to_is_error(): void {
		// The real UCP error envelope carries the error under `messages[0]`,
		// NOT under a top-level `error` key. core_result_to_mcp must read the
		// real shape: messages[0].code + messages[0].content.
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array(
					'ucp'      => array( 'version' => 'dev' ),
					'products' => array(),
					'messages' => array(
						array(
							'type'     => 'error',
							'code'     => 'ucp_disabled',
							'severity' => 'unrecoverable',
							'content'  => 'Off',
						),
					),
				),
				'status' => 503,
			),
			'x'
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'ucp_disabled', $result['content'][0]['text'] );
		$this->assertStringContainsString( 'Off', $result['content'][0]['text'] );
		$this->assertArrayNotHasKey( 'structuredContent', $result );
	}

	public function test_checkout_with_no_continue_url_is_flagged_as_error(): void {
		// A checkout where every line item failed to resolve returns HTTP 200
		// with the reason in messages[] and NO continue_url. With
		// require_continue_url=true the adapter must surface it as isError so a
		// fast agent doesn't read "Checkout" + isError:false as success.
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array(
					'status'   => 'incomplete',
					'messages' => array(
						array(
							'type'    => 'error',
							'code'    => 'not_found',
							'content' => 'Product not found.',
						),
					),
				),
				'status' => 200,
			),
			'Checkout',
			true
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'not_found', $result['content'][0]['text'] );
		$this->assertArrayNotHasKey( 'structuredContent', $result );
	}

	public function test_checkout_with_continue_url_is_success(): void {
		// Full or partial success carries a continue_url for the resolvable
		// items and must NOT be flagged, even with require_continue_url=true.
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array(
					'status'       => 'requires_escalation',
					'continue_url' => 'https://example.com/checkout-link/?products=15:1',
					'messages'     => array(),
				),
				'status' => 200,
			),
			'Checkout',
			true
		);

		$this->assertArrayNotHasKey( 'isError', $result );
		$this->assertSame( 'Checkout', $result['content'][0]['text'] );
	}

	public function test_require_continue_url_defaults_off_for_non_checkout_tools(): void {
		// catalog_search/lookup never carry a continue_url; the default
		// (false) must leave their 200 responses as successes.
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array( 'products' => array() ),
				'status' => 200,
			),
			'Catalog search'
		);

		$this->assertArrayNotHasKey( 'isError', $result );
		$this->assertSame( array( 'products' => array() ), $result['structuredContent'] );
	}

	public function test_core_result_to_mcp_phase2_fallback_non_error_typed_message(): void {
		// When the error body has messages[] but none with type:'error', Phase 2
		// falls back to the first message of any type. The error text must include
		// that message's code and content so the agent gets actionable detail.
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array(
					'messages' => array(
						array(
							'type'    => 'info',
							'code'    => 'partial_gibberish',
							'content' => 'Some items unavailable.',
						),
					),
				),
				'status' => 422,
			),
			'Catalog search'
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'partial_gibberish', $result['content'][0]['text'] );
	}

	public function test_core_result_to_mcp_empty_messages_yields_fallback_text(): void {
		// When the error body has no messages[] at all, the fallback text must
		// include the HTTP status so the agent has some signal — not just 'error'.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array(),
				'status' => 500,
			),
			'Catalog search'
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( '500', $result['content'][0]['text'] );
	}

	public function test_call_returns_wp_error_for_unknown_tool(): void {
		$result = WC_AI_Storefront_MCP_Tools::call( 'gibberish_tool', array(), 'ChatGPT' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_unknown_tool', $result->get_error_code() );
	}

	public function test_call_dispatches_catalog_lookup_through_core(): void {
		// Parity with the catalog_search dispatch test: drive the disabled-
		// syndication gate to prove call() routes 'catalog_lookup' to the right
		// core (not the default WP_Error unknown-tool path).
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$result = WC_AI_Storefront_MCP_Tools::call( 'catalog_lookup', array( 'ids' => array( 'prod_1' ) ), 'ChatGPT' );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'ucp_disabled', $result['content'][0]['text'] );
	}

	public function test_call_dispatches_checkout_create_through_core(): void {
		// Drive the empty-line_items 400 path to prove call() routes
		// 'checkout_create' to the right core without needing WC stubbing.
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		WC_AI_Storefront_Logger::reset_cache();

		$result = WC_AI_Storefront_MCP_Tools::call( 'checkout_create', array( 'line_items' => array() ), 'ChatGPT' );

		$this->assertTrue( $result['isError'] );
	}

	public function test_call_dispatches_catalog_search_through_core(): void {
		// Drive the disabled-syndication gate so run_catalog_search short-
		// circuits to a 503 UCP error envelope before any Store API dispatch.
		// This proves call() routes the tool name to the right core, threads
		// the client_name into agent_data, and maps the result through
		// core_result_to_mcp (which surfaces the messages[0] error).
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$result = WC_AI_Storefront_MCP_Tools::call( 'catalog_search', array( 'query' => 'hat' ), 'ChatGPT' );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'ucp_disabled', $result['content'][0]['text'] );
	}

	/**
	 * Build a minimal UCP product shape for summary tests.
	 *
	 * @param string $id       UCP product id.
	 * @param string $title    Product title.
	 * @param int    $min      Min price in minor units.
	 * @param int    $max      Max price in minor units (defaults to $min).
	 * @param string $currency ISO 4217 code.
	 * @return array<string,mixed>
	 */
	private function product( string $id, string $title, int $min = 0, int $max = 0, string $currency = 'USD' ): array {
		return array(
			'id'          => $id,
			'title'       => $title,
			'price_range' => array(
				'min' => array(
					'amount'   => $min,
					'currency' => $currency,
				),
				'max' => array(
					'amount'   => 0 !== $max ? $max : $min,
					'currency' => $currency,
				),
			),
		);
	}

	public function test_summarize_search_lists_products_with_count_and_cursor_hint(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_search(
			array(
				'products'   => array(
					$this->product( 'prod_22', 'Day Hoodie', 4800 ),
					$this->product( 'prod_31', 'Night Tee', 2400 ),
				),
				'pagination' => array( 'has_next_page' => true ),
			)
		);

		$this->assertStringContainsString( '2 products', $text );
		$this->assertStringContainsString( 'Day Hoodie (prod_22)', $text );
		$this->assertStringContainsString( 'USD 48.00', $text );
		$this->assertStringContainsString( 'pagination.cursor', $text );
	}

	public function test_summarize_search_reports_total_when_known(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_search(
			array(
				'products'   => array( $this->product( 'prod_1', 'Cap', 1800 ) ),
				'pagination' => array( 'total_count' => 42 ),
			)
		);

		$this->assertStringContainsString( '1 of 42 products', $text );
	}

	public function test_summarize_search_empty_returns_no_match(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_search( array( 'products' => array() ) );

		$this->assertStringContainsString( 'No products matched', $text );
	}

	public function test_summarize_search_caps_long_lists_with_overflow_note(): void {
		$products = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$products[] = $this->product( 'prod_' . $i, 'Item ' . $i, 100 );
		}

		$text = WC_AI_Storefront_MCP_Tools::summarize_search( array( 'products' => $products ) );

		// Only the first 10 are enumerated; the remaining 5 collapse to a note.
		$this->assertStringContainsString( 'Item 9 (prod_9)', $text );
		$this->assertStringNotContainsString( 'Item 10 (prod_10)', $text );
		$this->assertStringContainsString( '…and 5 more', $text );
	}

	public function test_summarize_search_formats_a_price_range(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_search(
			array( 'products' => array( $this->product( 'prod_5', 'Variable', 4800, 6000 ) ) )
		);

		$this->assertStringContainsString( 'USD 48.00–60.00', $text );
	}

	public function test_summarize_lookup_notes_unresolved_ids(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_lookup(
			array(
				'products' => array( $this->product( 'prod_22', 'Day Hoodie', 4800 ) ),
				'messages' => array(
					array(
						'type'    => 'info',
						'code'    => 'not_found',
						'content' => 'var_999 missing.',
					),
				),
			)
		);

		$this->assertStringContainsString( '1 product', $text );
		$this->assertStringContainsString( 'Day Hoodie (prod_22)', $text );
		$this->assertStringContainsString( 'not found', $text );
	}

	public function test_summarize_lookup_empty_with_messages(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_lookup(
			array(
				'products' => array(),
				'messages' => array(
					array(
						'type'    => 'error',
						'code'    => 'not_found',
						'content' => 'x',
					),
				),
			)
		);

		$this->assertStringContainsString( 'No products found', $text );
	}

	public function test_summarize_checkout_includes_item_count_and_url(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_checkout(
			array(
				'continue_url' => 'https://store.example/checkout-link/?products=22:1,45:1',
				'line_items'   => array( array( 'x' => 1 ), array( 'y' => 2 ) ),
			)
		);

		$this->assertStringContainsString( 'Checkout ready for 2 items', $text );
		$this->assertStringContainsString( 'https://store.example/checkout-link/?products=22:1,45:1', $text );
	}

	public function test_summarize_checkout_notes_skipped_items(): void {
		$text = WC_AI_Storefront_MCP_Tools::summarize_checkout(
			array(
				'continue_url' => 'https://store.example/x',
				'line_items'   => array( array( 'x' => 1 ) ),
				'messages'     => array(
					array(
						'type'    => 'info',
						'code'    => 'not_found',
						'content' => 'skipped',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Checkout ready for 1 item.', $text );
		$this->assertStringContainsString( 'skipped', $text );
	}

	public function test_format_money_respects_currency_decimals(): void {
		$this->assertSame( 'USD 48.00', WC_AI_Storefront_MCP_Tools::format_money( 4800, 'USD' ) );
		// JPY is a zero-decimal currency: the minor-unit amount IS the major value
		// (and number_format adds a thousands separator for readability).
		$this->assertSame( 'JPY 4,800', WC_AI_Storefront_MCP_Tools::format_money( 4800, 'JPY' ) );
	}

	public function test_core_result_to_mcp_uses_success_summarizer_when_provided(): void {
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array( 'products' => array( $this->product( 'prod_1', 'Hat', 1800 ) ) ),
				'status' => 200,
			),
			'Catalog search',
			false,
			array( WC_AI_Storefront_MCP_Tools::class, 'summarize_search' )
		);

		$this->assertArrayNotHasKey( 'isError', $result );
		$this->assertStringContainsString( 'Hat (prod_1)', $result['content'][0]['text'] );
		// structuredContent is untouched — the full body still rides along.
		$this->assertArrayHasKey( 'products', $result['structuredContent'] );
	}

	public function test_core_result_to_mcp_summarizer_is_ignored_on_error_path(): void {
		// On an error result the summarizer must NOT run; the error text comes
		// from messages[] as before.
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			array(
				'body'   => array(
					'messages' => array(
						array(
							'type'    => 'error',
							'code'    => 'boom',
							'content' => 'Bang',
						),
					),
				),
				'status' => 400,
			),
			'Catalog search',
			false,
			array( WC_AI_Storefront_MCP_Tools::class, 'summarize_search' )
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'boom', $result['content'][0]['text'] );
	}

	public function test_format_money_three_decimal_currency(): void {
		// BHD is a three-decimal currency: 4800 minor units = 4.800. Guards
		// against a regression that dropped the three-decimal list or hard-coded
		// /100 (which would 10x the displayed price).
		$this->assertSame( 'BHD 4.800', WC_AI_Storefront_MCP_Tools::format_money( 4800, 'BHD' ) );
	}

	public function test_summarize_search_formats_cross_currency_range(): void {
		// The min != max AND differing-currency branch formats each bound with
		// its own currency + decimals (no code elision).
		$text = WC_AI_Storefront_MCP_Tools::summarize_search(
			array(
				'products' => array(
					array(
						'id'          => 'prod_5',
						'title'       => 'Imported',
						'price_range' => array(
							'min' => array(
								'amount'   => 4800,
								'currency' => 'USD',
							),
							'max' => array(
								'amount'   => 6000,
								'currency' => 'EUR',
							),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'USD 48.00–EUR 60.00', $text );
	}

	public function test_summarize_search_omits_price_when_range_absent(): void {
		// A product with no price_range renders "Title (id)" with no price
		// fragment — never a fabricated "USD 0.00".
		$text = WC_AI_Storefront_MCP_Tools::summarize_search(
			array(
				'products' => array(
					array(
						'id'    => 'prod_22',
						'title' => 'Day Hoodie',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Day Hoodie (prod_22)', $text );
		$this->assertStringNotContainsString( 'USD', $text );
		$this->assertStringNotContainsString( '–', $text );
	}

	public function test_summarize_search_omits_price_when_amount_non_numeric(): void {
		// A non-numeric amount (here a nested array from a hypothetical malformed
		// envelope) must omit the price rather than (int)-coerce it to a wrong
		// value. The omission is debug-logged (apply_filters drives the logger).
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$text = WC_AI_Storefront_MCP_Tools::summarize_search(
			array(
				'products' => array(
					array(
						'id'          => 'prod_9',
						'title'       => 'Glitched',
						'price_range' => array(
							'min' => array(
								'amount'   => array( 'nested' => 1 ),
								'currency' => 'USD',
							),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'Glitched (prod_9)', $text );
		$this->assertStringNotContainsString( 'USD', $text );
	}

	public function test_product_line_falls_back_to_id_when_title_is_empty(): void {
		// An empty title renders the bare id — no dangling " (id)" with a blank
		// leading title.
		$text = WC_AI_Storefront_MCP_Tools::summarize_search(
			array(
				'products' => array(
					array(
						'id'    => 'prod_22',
						'title' => '',
					),
				),
			)
		);

		$this->assertStringContainsString( 'prod_22', $text );
		$this->assertStringNotContainsString( '(prod_22)', $text );
	}
}
