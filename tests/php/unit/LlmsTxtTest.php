<?php
/**
 * Tests for WC_AI_Syndication_Llms_Txt.
 *
 * Focuses on `generate()` — the method that produces the Markdown
 * document served at `/llms.txt`. These tests pin the document's
 * *structure* (required sections, heading hierarchy) and the
 * decoration/escaping of dynamic values (HTML entities, singular/
 * plural grammar, price formatting).
 *
 * The featured-products path is intentionally covered minimally: it
 * requires rich WC_Product mocks whose shape is better exercised in
 * dedicated integration tests. The structural test here stubs
 * `wc_get_products` to return an empty array, which takes the "no
 * featured products" branch — enough to confirm the surrounding
 * code doesn't crash on empty fixtures.
 *
 * @package WooCommerce_AI_Syndication
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class LlmsTxtTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Syndication_Llms_Txt $llms;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->llms = new WC_AI_Syndication_Llms_Txt();

		// Configure the shared test settings (consumed by the stubbed
		// `WC_AI_Syndication::get_settings()` in the bootstrap).
		WC_AI_Syndication::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
		];

		// Baseline WP/WC function stubs. Individual tests override these
		// via `Functions\when()->alias()` for specific scenarios.
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . ( $path ?: '/' )
		);
		Functions\when( 'rest_url' )->alias(
			static fn( $path ) => 'https://example.com/wp-json/' . ltrim( $path, '/' )
		);
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => [
				'name'        => 'Example Store',
				'description' => 'A test storefront',
			][ $key ] ?? ''
		);
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		// wp_strip_all_tags() is now stubbed globally in tests/php/stubs.php
		// (it's loaded early enough to be seen by every test). Previously
		// we Brain\Monkey-aliased it here, but Patchwork — Brain\Monkey's
		// runtime — refuses to redefine symbols declared before Patchwork
		// itself is loaded, which is the case for our stubs.php. The
		// global stub uses the real WordPress-equivalent implementation,
		// so functional behavior for this test is unchanged.
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => 'https://example.com/product-category/' . ( $term->slug ?? 'x' ) . '/'
		);
		Functions\when( 'wc_get_products' )->justReturn( [] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Sitemap-discovery stubs. Default: nothing found (no sitemap
		// section in output). Individual tests override via
		// `Functions\when()->alias()` to simulate found sitemaps.
		// `is_wp_error` is globally stubbed in `tests/php/stubs.php`
		// (too early for Patchwork to redefine it here).
		Functions\when( 'get_sitemap_url' )->justReturn( '' );
		Functions\when( 'wp_remote_head' )->justReturn(
			new WP_Error( 'no_probe', 'Not stubbed in test' )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		WC_AI_Syndication::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Structural — required sections
	// ------------------------------------------------------------------

	public function test_output_starts_with_h1_of_store_name(): void {
		$output = $this->llms->generate();

		// The llms.txt convention is H1 = store identity.
		$this->assertStringStartsWith( "# Example Store\n", $output );
	}

	public function test_output_includes_blockquote_description(): void {
		$output = $this->llms->generate();

		// Blockquote is the llms.txt convention for site tagline.
		$this->assertStringContainsString( '> A test storefront', $output );
	}

	public function test_output_includes_core_sections(): void {
		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Store Information', $output );
		$this->assertStringContainsString( '## API Access', $output );
		$this->assertStringContainsString( '## Attribution', $output );
	}

	public function test_api_access_section_points_to_store_api_and_ucp(): void {
		// The plugin does NOT expose its own authenticated API. llms.txt
		// must advertise WooCommerce's public Store API and the UCP
		// manifest — NOT the removed `wc/v3/ai-syndication/*` endpoints
		// or the `X-AI-Agent-Key` header (both existed in a pre-1.0
		// draft of the architecture).
		$output = $this->llms->generate();

		// Correct endpoints advertised.
		$this->assertStringContainsString( 'wc/store/v1', $output );
		$this->assertStringContainsString( '.well-known/ucp', $output );

		// Regression guard: the deleted endpoints must NEVER appear again.
		$this->assertStringNotContainsString( 'X-AI-Agent-Key', $output );
		$this->assertStringNotContainsString( 'wc/v3/ai-syndication', $output );
		$this->assertStringNotContainsString( 'Product Catalog', $output );
		$this->assertStringNotContainsString( '## API Endpoints', $output );
	}

	public function test_store_information_lists_url_currency_and_checkout_policy(): void {
		$output = $this->llms->generate();

		$this->assertStringContainsString(
			'- **URL**: https://example.com/',
			$output
		);
		$this->assertStringContainsString( '- **Currency**: USD', $output );
		$this->assertStringContainsString(
			'- **Checkout**: On-site only (web redirect)',
			$output
		);
	}

	public function test_store_information_links_ucp_manifest(): void {
		// AI crawlers that parse llms.txt should be able to follow the
		// link to the UCP manifest for machine-readable capabilities.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '.well-known/ucp', $output );
	}

	public function test_attribution_section_documents_utm_parameters(): void {
		$output = $this->llms->generate();

		$this->assertStringContainsString( '`utm_source`', $output );
		$this->assertStringContainsString( '`utm_medium`: `ai_agent`', $output );
		$this->assertStringContainsString( '`ai_session_id`', $output );
	}

	public function test_attribution_leads_with_api_first_checkout_flow(): void {
		// 1.6.5 change: the attribution section now leads with the
		// canonical UCP flow (POST /checkout-sessions) rather than
		// URL-template examples. Matches the UCP checkout spec's
		// SHOULD directive: businesses provide continue_url,
		// platforms don't construct their own.
		$output = $this->llms->generate();

		$this->assertStringContainsString( '/checkout-sessions', $output );
		$this->assertStringContainsString( 'UCP-Agent', $output );
		$this->assertStringContainsString( 'requires_escalation', $output );
		$this->assertStringContainsString( 'continue_url', $output );
	}

	public function test_attribution_still_documents_utm_parameters_for_legacy_flow(): void {
		// Agents that must construct URLs client-side (non-UCP-aware
		// or legacy flows) still need the UTM convention documented.
		// Kept in llms.txt as authoritative human-readable guidance
		// after 1.6.5 removed the template library from the manifest.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'utm_source', $output );
		$this->assertStringContainsString( 'utm_medium', $output );
		$this->assertStringContainsString( 'ai_agent', $output );
		$this->assertStringContainsString( 'ai_session_id', $output );
	}

	public function test_attribution_points_to_woocommerce_public_docs_for_url_construction(): void {
		// When manifest templates are removed, agents that need URL
		// patterns should be directed to WooCommerce's public
		// documentation rather than discovering them by trial.
		// 1.6.5 added this pointer in place of the manifest
		// purchase_urls reference.
		$output = $this->llms->generate();

		$this->assertStringContainsString( 'woocommerce.com/document/creating-sharable-checkout-urls', $output );
	}

	// ------------------------------------------------------------------
	// HTML-entity decoding
	// ------------------------------------------------------------------

	public function test_store_name_with_html_entities_is_decoded(): void {
		// WordPress `get_bloginfo()` HTML-encodes by default. Raw entities
		// in a Markdown document confuse AI crawlers; the generator must
		// decode them.
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => [
				'name'        => 'Joe&#039;s Shop &amp; Cafe',
				'description' => 'Best &quot;coffee&quot;',
			][ $key ] ?? ''
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( "# Joe's Shop & Cafe\n", $output );
		$this->assertStringContainsString( '> Best "coffee"', $output );
		// And the encoded forms should NOT appear.
		$this->assertStringNotContainsString( '&#039;', $output );
		$this->assertStringNotContainsString( '&amp;', $output );
	}

	public function test_empty_description_omits_blockquote(): void {
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'Example Store' : ''
		);

		$output = $this->llms->generate();

		// No tagline -> no '>' blockquote line.
		$this->assertStringNotContainsString( '> ', $output );
	}

	// ------------------------------------------------------------------
	// Category section
	// ------------------------------------------------------------------

	public function test_categories_section_renders_when_categories_exist(): void {
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Coffee Beans',
			'slug'    => 'coffee-beans',
			'count'   => 3,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Product Categories', $output );
		$this->assertStringContainsString(
			'[Coffee Beans](https://example.com/product-category/coffee-beans/)',
			$output
		);
		$this->assertStringContainsString( '(3 products)', $output );
	}

	public function test_category_with_one_product_uses_singular_grammar(): void {
		// Category counts feeding "(1 products)" is a grammar bug AI
		// readers will notice. Singular vs plural is explicitly handled.
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Espresso',
			'slug'    => 'espresso',
			'count'   => 1,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '(1 product)', $output );
		$this->assertStringNotContainsString( '(1 products)', $output );
	}

	public function test_category_name_with_entities_is_decoded(): void {
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Tea &amp; Infusions',
			'slug'    => 'tea',
			'count'   => 2,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );

		$output = $this->llms->generate();

		$this->assertStringContainsString( '[Tea & Infusions]', $output );
	}

	public function test_category_with_wp_error_term_link_is_skipped(): void {
		// get_term_link() can return a WP_Error for orphaned terms.
		// The generator must handle that gracefully without rendering
		// a broken link.
		$category = (object) [
			'term_id' => 5,
			'name'    => 'Broken',
			'slug'    => 'broken',
			'count'   => 1,
		];
		Functions\when( 'get_terms' )->justReturn( [ $category ] );
		Functions\when( 'get_term_link' )->justReturn(
			new WP_Error( 'invalid_term', 'bad term' )
		);

		$output = $this->llms->generate();

		// The heading still renders (a category did exist), but the
		// broken row is omitted. Confirm no "[Broken]" link slipped through.
		$this->assertStringNotContainsString( '[Broken]', $output );
	}

	public function test_categories_section_omitted_when_no_categories(): void {
		// Fresh stores or disabled/empty taxonomy -> no section heading.
		Functions\when( 'get_terms' )->justReturn( [] );

		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Product Categories', $output );
	}

	public function test_wp_error_from_get_terms_is_handled(): void {
		Functions\when( 'get_terms' )->justReturn(
			new WP_Error( 'db_error', 'database' )
		);

		$output = $this->llms->generate();

		// A WP_Error should be treated as "no categories", not crash.
		$this->assertStringNotContainsString( '## Product Categories', $output );
	}

	// ------------------------------------------------------------------
	// Filter extensibility
	// ------------------------------------------------------------------

	public function test_output_is_filterable_via_lines_hook(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $lines, $settings ) {
				if ( 'wc_ai_syndication_llms_txt_lines' === $hook ) {
					$lines[] = '## Custom Extension';
					$lines[] = 'Injected by third party.';
				}
				return $lines;
			}
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Custom Extension', $output );
		$this->assertStringContainsString( 'Injected by third party.', $output );
	}

	// ------------------------------------------------------------------
	// Cache-hit semantics — the 1.4.4 empty-string regression
	// ------------------------------------------------------------------
	//
	// These tests lock in the defense against the bug that shipped in
	// production before 1.4.4: the cache-hit check was `false !== $cached`,
	// which treated an empty-string transient as a valid hit rather than
	// a miss. If anything ever poisoned the cache with `''` (and one did,
	// during the 1.4.2 wiring-bug window), blank responses were served
	// for the full 1-hour TTL. The fix is a pair: treat empty as miss on
	// read, refuse to write empty on the update path. These tests cover
	// both halves so a future refactor that only restores one of them
	// leaves a broken build.
	//
	// `get_cached_content()` is private by design; reflection is the
	// least-invasive way to exercise it without altering visibility.

	public function test_empty_cached_value_is_treated_as_miss(): void {
		Functions\when( 'get_transient' )->justReturn( '' );
		$set_transient_called_with = null;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$set_transient_called_with ) {
				$set_transient_called_with = [
					'key'   => $key,
					'value' => $value,
				];
				return true;
			}
		);

		$result = $this->invoke_private( 'get_cached_content' );

		// Non-empty content was produced by regeneration — proves the
		// empty cached value did NOT short-circuit the lookup.
		$this->assertNotSame( '', $result );
		$this->assertStringContainsString( '# Example Store', $result );

		// The fresh non-empty content was written back to the cache,
		// healing the poisoned state on first request.
		$this->assertNotNull( $set_transient_called_with );
		$this->assertNotSame( '', $set_transient_called_with['value'] );
	}

	public function test_valid_cached_value_is_returned_verbatim(): void {
		Functions\when( 'get_transient' )->justReturn( "# Cached Content\n\nHello from cache." );
		Functions\when( 'set_transient' )->justReturn( true );

		$result = $this->invoke_private( 'get_cached_content' );

		// Verbatim return — we did NOT fall through to regeneration
		// when a valid cached value was present.
		$this->assertSame( "# Cached Content\n\nHello from cache.", $result );
	}

	public function test_empty_generated_content_is_not_written_to_cache(): void {
		Functions\when( 'get_transient' )->justReturn( false ); // Fresh miss.
		$set_transient_calls = 0;
		Functions\when( 'set_transient' )->alias(
			static function () use ( &$set_transient_calls ) {
				++$set_transient_calls;
				return true;
			}
		);
		// Force generate() to return empty by having the filter nuke
		// the lines array. This is the only realistic path to empty
		// output given generate()'s always-produces-skeleton design;
		// if a future refactor introduces other empty paths, this
		// test still catches them via the set_transient observation.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $lines ) {
				return ( 'wc_ai_syndication_llms_txt_lines' === $hook ) ? [] : $lines;
			}
		);

		$result = $this->invoke_private( 'get_cached_content' );

		$this->assertSame( '', $result, 'generate() should have returned empty in this setup.' );
		$this->assertSame( 0, $set_transient_calls, 'Empty content must not be cached — would poison the TTL window.' );
	}

	// ------------------------------------------------------------------
	// Sitemaps section (1.6.3)
	// ------------------------------------------------------------------

	public function test_sitemap_section_absent_when_no_sitemaps_respond(): void {
		// Default stubs: wp_remote_head returns WP_Error for every
		// probe, get_sitemap_url returns empty → zero candidates
		// confirmed existent → section not rendered.
		$output = $this->llms->generate();

		$this->assertStringNotContainsString( '## Sitemaps', $output );
	}

	public function test_sitemap_section_rendered_when_sitemap_exists(): void {
		// Simulate a site where /sitemap.xml responds 200 to a HEAD
		// probe. The section should render with that URL listed.
		Functions\when( 'wp_remote_head' )->alias(
			static function ( string $url ): array {
				if ( str_ends_with( $url, '/sitemap.xml' ) ) {
					return [ 'response' => [ 'code' => 200 ] ];
				}
				return [ 'response' => [ 'code' => 404 ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) =>
				is_array( $response ) && isset( $response['response']['code'] )
					? (int) $response['response']['code']
					: 0
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '## Sitemaps', $output );
		$this->assertStringContainsString( '- https://example.com/sitemap.xml', $output );
	}

	public function test_sitemap_section_excludes_paths_that_404(): void {
		// When some candidates probe OK and others don't, only
		// the responding URLs make it into the output. Validates
		// the HEAD-filter logic — emitting non-existent paths in
		// llms.txt would be factually wrong (unlike robots.txt
		// Allow, which is a harmless no-op).
		Functions\when( 'wp_remote_head' )->alias(
			static function ( string $url ): array {
				// Only /sitemap.xml responds; /sitemap_index.xml,
				// /wp-sitemap.xml, /news-sitemap.xml all 404.
				$code = str_ends_with( $url, '/sitemap.xml' ) ? 200 : 404;
				return [ 'response' => [ 'code' => $code ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => (int) $response['response']['code']
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- https://example.com/sitemap.xml', $output );
		$this->assertStringNotContainsString( '/sitemap_index.xml', $output );
		$this->assertStringNotContainsString( '/news-sitemap.xml', $output );
	}

	public function test_wp_core_sitemap_included_when_non_empty(): void {
		// `get_sitemap_url( 'index' )` returns WP core's canonical
		// sitemap URL when the feature is active. That candidate
		// should be probed alongside the hardcoded COMMON_SITEMAP_PATHS.
		Functions\when( 'get_sitemap_url' )->justReturn( 'https://example.com/wp-sitemap.xml' );
		Functions\when( 'wp_remote_head' )->alias(
			static function ( string $url ): array {
				$code = str_ends_with( $url, '/wp-sitemap.xml' ) ? 200 : 404;
				return [ 'response' => [ 'code' => $code ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => (int) $response['response']['code']
		);

		$output = $this->llms->generate();

		$this->assertStringContainsString( '- https://example.com/wp-sitemap.xml', $output );
	}

	/**
	 * Invoke a private method on the LlmsTxt instance via reflection.
	 *
	 * @param string $method Method name.
	 * @return mixed          Return value of the method.
	 */
	private function invoke_private( string $method ) {
		$reflection = new ReflectionClass( $this->llms );
		$m          = $reflection->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $this->llms );
	}
}
