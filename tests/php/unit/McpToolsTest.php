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
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_definitions_exposes_exactly_three_tools(): void {
		$defs  = WC_AI_Storefront_MCP_Tools::definitions();
		$names = array_column( $defs, 'name' );

		$this->assertCount( 3, $defs );
		$this->assertSame(
			[ 'catalog_search', 'catalog_lookup', 'checkout_create' ],
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
		foreach ( [ 'filters', 'sort', 'pagination', 'context' ] as $obj ) {
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
			[ 'price', 'title', 'date', 'newest', 'popularity', 'rating', 'menu_order' ],
			$sort['properties']['field']['enum']
		);
		$this->assertSame( [ 'asc', 'desc' ], $sort['properties']['direction']['enum'] );
	}

	public function test_checkout_line_item_shape_requires_item_id(): void {
		// Mirrors process_line_item: each entry is { item: { id }, quantity }.
		$items = $this->tool( 'checkout_create' )['inputSchema']['properties']['line_items']['items'];
		$this->assertSame( 'object', $items['type'] );
		$this->assertContains( 'item', $items['required'] );
		$this->assertContains( 'id', $items['properties']['item']['required'] );
	}

	public function test_core_result_to_mcp_maps_success_to_structured_content(): void {
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			[
				'body'   => [ 'ok' => true ],
				'status' => 200,
			],
			'x'
		);

		$this->assertArrayNotHasKey( 'isError', $result );
		$this->assertSame( [ 'ok' => true ], $result['structuredContent'] );
		$this->assertSame( 'x', $result['content'][0]['text'] );
	}

	public function test_core_result_to_mcp_maps_error_envelope_to_is_error(): void {
		// The real UCP error envelope carries the error under `messages[0]`,
		// NOT under a top-level `error` key. core_result_to_mcp must read the
		// real shape: messages[0].code + messages[0].content.
		$result = WC_AI_Storefront_MCP_Tools::core_result_to_mcp(
			[
				'body'   => [
					'ucp'      => [ 'version' => 'dev' ],
					'products' => [],
					'messages' => [
						[
							'type'     => 'error',
							'code'     => 'ucp_disabled',
							'severity' => 'unrecoverable',
							'content'  => 'Off',
						],
					],
				],
				'status' => 503,
			],
			'x'
		);

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'ucp_disabled', $result['content'][0]['text'] );
		$this->assertStringContainsString( 'Off', $result['content'][0]['text'] );
		$this->assertArrayNotHasKey( 'structuredContent', $result );
	}

	public function test_call_returns_wp_error_for_unknown_tool(): void {
		$result = WC_AI_Storefront_MCP_Tools::call( 'gibberish_tool', [], 'ChatGPT' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_unknown_tool', $result->get_error_code() );
	}

	public function test_call_dispatches_catalog_search_through_core(): void {
		// Drive the disabled-syndication gate so run_catalog_search short-
		// circuits to a 503 UCP error envelope before any Store API dispatch.
		// This proves call() routes the tool name to the right core, threads
		// the client_name into agent_data, and maps the result through
		// core_result_to_mcp (which surfaces the messages[0] error).
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		Functions\when( 'apply_filters' )->returnArg( 2 );
		WC_AI_Storefront_Logger::reset_cache();

		$result = WC_AI_Storefront_MCP_Tools::call( 'catalog_search', [ 'query' => 'hat' ], 'ChatGPT' );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'ucp_disabled', $result['content'][0]['text'] );
	}
}
