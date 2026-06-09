<?php
/**
 * Tests for the OpenSearch descriptor endpoint in WC_AI_Storefront_Ucp.
 *
 * Covers:
 *  - add_rewrite_rules() registers the /opensearch.xml rule
 *  - add_query_vars() adds the OPENSEARCH_QUERY_VAR variable
 *  - inject_head_link() emits the <link rel="search"> tag
 *  - serve_opensearch_xml() XML content (run in separate process to allow exit)
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class UcpOpenSearchTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Ucp $ucp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->ucp = new WC_AI_Storefront_Ucp();

		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg( 1 );

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Constant
	// ------------------------------------------------------------------

	public function test_opensearch_query_var_constant_defined(): void {
		$this->assertSame( 'wc_ai_storefront_opensearch', WC_AI_Storefront_Ucp::OPENSEARCH_QUERY_VAR );
	}

	// ------------------------------------------------------------------
	// add_rewrite_rules()
	// ------------------------------------------------------------------

	public function test_opensearch_rewrite_rule_registered(): void {
		$rules = [];
		Functions\when( 'add_rewrite_rule' )->alias(
			static function ( $regex, $query, $after ) use ( &$rules ) {
				$rules[ $regex ] = [ 'query' => $query, 'after' => $after ];
			}
		);

		$this->ucp->add_rewrite_rules();

		$this->assertArrayHasKey( '^opensearch\.xml$', $rules );
		$this->assertSame( 'top', $rules['^opensearch\.xml$']['after'] );
		$this->assertStringContainsString(
			WC_AI_Storefront_Ucp::OPENSEARCH_QUERY_VAR . '=1',
			$rules['^opensearch\.xml$']['query']
		);
	}

	// ------------------------------------------------------------------
	// add_query_vars()
	// ------------------------------------------------------------------

	public function test_opensearch_query_var_registered(): void {
		Functions\when( 'add_rewrite_rule' )->justReturn();
		$vars = $this->ucp->add_query_vars( [] );
		$this->assertContains( WC_AI_Storefront_Ucp::OPENSEARCH_QUERY_VAR, $vars );
	}

	// ------------------------------------------------------------------
	// inject_head_link()
	// ------------------------------------------------------------------

	public function test_opensearch_head_link_emitted(): void {
		ob_start();
		$this->ucp->inject_head_link();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="search"', $output );
		$this->assertStringContainsString( 'application/opensearchdescription+xml', $output );
		$this->assertStringContainsString( 'opensearch.xml', $output );
	}

	public function test_opensearch_head_link_has_store_title(): void {
		ob_start();
		$this->ucp->inject_head_link();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Test Store', $output );
	}

	// ------------------------------------------------------------------
	// build_opensearch_xml() — XML content tests (no exit, no separate process)
	// ------------------------------------------------------------------

	public function test_opensearch_xml_contains_html_search_url(): void {
		Functions\when( 'esc_html' )->returnArg();

		$xml = $this->ucp->build_opensearch_xml();

		$this->assertStringContainsString( 'type="text/html"', $xml );
		$this->assertStringContainsString( '?s={searchTerms}', $xml );
		$this->assertStringContainsString( 'post_type=product', $xml );
	}

	public function test_opensearch_xml_contains_rest_api_url(): void {
		Functions\when( 'esc_html' )->returnArg();

		$xml = $this->ucp->build_opensearch_xml();

		$this->assertStringContainsString( 'type="application/json"', $xml );
		$this->assertStringContainsString( 'catalog/search', $xml );
		$this->assertStringContainsString( '?q={searchTerms}', $xml );
	}

	public function test_opensearch_xml_contains_opensearch_namespace(): void {
		Functions\when( 'esc_html' )->returnArg();

		$xml = $this->ucp->build_opensearch_xml();

		$this->assertStringContainsString( 'OpenSearchDescription', $xml );
		$this->assertStringContainsString( 'http://a9.com/-/spec/opensearch/1.1/', $xml );
	}

	// ------------------------------------------------------------------
	// serve_opensearch_xml() early-return paths (no exit)
	// ------------------------------------------------------------------

	public function test_no_output_when_query_var_not_set(): void {
		Functions\when( 'get_query_var' )->justReturn( 0 );

		ob_start();
		$this->ucp->serve_opensearch_xml();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}
}
