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
		Functions\when( 'rest_url' )->alias(
			static fn( $path = '' ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
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

	public function test_head_link_advertises_llms_txt(): void {
		// Machine-only /llms.txt advertisement for head-parsing crawlers (e.g.
		// Googlebot, which feeds the search-index discovery path); HTML companion
		// to the `Link:` HTTP header. Replaced the former visible body anchor.
		ob_start();
		$this->ucp->inject_head_link();
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<link rel="alternate" type="text\/markdown" href="[^"]*\/llms\.txt"/',
			$output
		);
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

	public function test_opensearch_short_name_falls_back_to_home_url_when_blogname_empty(): void {
		// A blank store name would produce an empty <ShortName>, which is an
		// invalid OpenSearch descriptor (consumers reject it). The builder must
		// fall back to a truncated home_url(). Exercises the empty-name branch.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://my-store.example.com' . ( $path ?: '/' )
		);

		$xml = $this->ucp->build_opensearch_xml();

		// Capture the ShortName content and assert it's the truncated host, not empty.
		$this->assertMatchesRegularExpression( '#<ShortName>.+</ShortName>#', $xml );
		preg_match( '#<ShortName>(.*)</ShortName>#', $xml, $m );
		$this->assertNotSame( '', $m[1] );
		$this->assertSame( 'https://my-store', $m[1] ); // home_url() truncated to 16 chars.
	}

	public function test_opensearch_short_name_truncated_to_16_chars(): void {
		// ShortName has a 16-char ceiling. A long store name must be truncated.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Supercalifragilistic Emporium' );

		$xml = $this->ucp->build_opensearch_xml();

		preg_match( '#<ShortName>(.*)</ShortName>#', $xml, $m );
		$this->assertSame( 'Supercalifragil', mb_substr( $m[1], 0, 15 ) ); // sanity: prefix preserved.
		$this->assertLessThanOrEqual( 16, mb_strlen( $m[1] ) );
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

	/**
	 * Disabled store → 404 (then exit). We stub status_header() to throw a
	 * sentinel so we can assert the 404 branch ran WITHOUT reaching the real
	 * exit(). Runs in a separate process so a stray exit() (if the stub ever
	 * fails to intercept) ends only the forked child, not the whole suite.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_serve_returns_404_when_plugin_disabled(): void {
		Functions\when( 'get_query_var' )->justReturn( 1 ); // descriptor requested.
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];

		$captured_status = null;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$captured_status ) {
				$captured_status = $code;
				throw new \RuntimeException( 'status_header:' . $code );
			}
		);

		try {
			$this->ucp->serve_opensearch_xml();
			$this->fail( 'Expected serve_opensearch_xml() to emit a 404 status on a disabled store.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 404, $captured_status );
			$this->assertSame( 'status_header:404', $e->getMessage() );
		}
	}
}
