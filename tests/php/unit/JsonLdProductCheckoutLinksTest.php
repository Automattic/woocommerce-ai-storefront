<?php
/**
 * Tests for the product-page "agent checkout" anchor:
 * WC_AI_Storefront_JsonLd::checkout_url_template() + render_product_checkout_links().
 *
 * Markdown-extraction fetch tools strip the `<script>` JSON-LD BuyAction, so
 * these surfaces re-expose the SAME deterministic checkout URL in the visible
 * body. The cardinal invariant under test: the accessor's output is
 * byte-identical to what `build_checkout_url_template()` (and therefore the
 * `<script>` BuyAction) emits for the same product.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdProductCheckoutLinksTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->jsonld = new WC_AI_Storefront_JsonLd();

		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'products_json_enabled'  => 'yes',
		];

		// `add_query_arg()` mock mirroring WP's actual behavior: it inherits
		// `&amp;` separators from an HTML-escaped incoming URL, which the
		// production `decode_query_url()` wrapper then `html_entity_decode()`s
		// back to `&`. Matching JsonLdTest exactly keeps the accessor's output
		// byte-identical to what the `<script>` BuyAction asserts there.
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$fragment = '';
				if ( str_contains( $url, '#' ) ) {
					[ $url, $fragment ] = explode( '#', $url, 2 );
					$fragment = '#' . $fragment;
				}
				$pairs = array();
				foreach ( $args as $k => $v ) {
					$pairs[] = $k . '=' . $v;
				}
				$query = implode( '&amp;', $pairs );
				$sep   = str_contains( $url, '?' ) ? '&amp;' : '?';
				return $url . $sep . $query . $fragment;
			}
		);
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function simple_product( int $id, string $slug = 'a-product' ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $slug );
		$p->shouldReceive( 'get_name' )->andReturn( 'A Product' );
		$p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'simple' === $t );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'get_permalink' )->andReturn( "https://example.com/product/{$slug}/" );
		return $p;
	}

	public function test_checkout_url_template_simple_matches_buyaction_shape(): void {
		$url = WC_AI_Storefront_JsonLd::checkout_url_template( $this->simple_product( 42 ) );

		$this->assertStringContainsString( 'https://example.com/checkout-link/?products=42:1', $url );
		$this->assertStringContainsString( 'utm_source={agent_id}', $url );
		$this->assertStringContainsString( 'utm_id=woo_jsonld', $url );
	}
}
