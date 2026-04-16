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
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $s ) => strip_tags( (string) $s )
		);
		Functions\when( 'get_terms' )->justReturn( [] );
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ) => 'https://example.com/product-category/' . ( $term->slug ?? 'x' ) . '/'
		);
		Functions\when( 'wc_get_products' )->justReturn( [] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
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

	public function test_attribution_includes_example_url(): void {
		$output = $this->llms->generate();

		$this->assertStringContainsString(
			'https://example.com/product/example/?utm_source=',
			$output
		);
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
}
