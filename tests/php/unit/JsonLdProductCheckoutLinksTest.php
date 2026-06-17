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

	private function simple_product( int $id, string $slug = 'a-product', bool $purchasable = true ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $slug );
		$p->shouldReceive( 'get_name' )->andReturn( 'A Product' );
		$p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'simple' === $t );
		$p->shouldReceive( 'is_purchasable' )->andReturn( $purchasable );
		$p->shouldReceive( 'get_permalink' )->andReturn( "https://example.com/product/{$slug}/" );
		return $p;
	}

	/**
	 * Bundle or grouped product. These types take the permalink-based
	 * checkout branch (get_permalink() + UTM), never the
	 * `/checkout-link/?products=` Shareable Checkout URL form.
	 */
	private function bundle_or_grouped_product( int $id, string $type, string $slug, string $name ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $slug );
		$p->shouldReceive( 'get_name' )->andReturn( $name );
		$p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => $type === $t );
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

	private function variable_product( int $id, string $slug, array $variation_ids ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $slug );
		$p->shouldReceive( 'get_name' )->andReturn( ucfirst( $slug ) );
		$p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variable' === $t );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'get_children' )->andReturn( $variation_ids );
		$p->shouldReceive( 'get_permalink' )->andReturn( "https://example.com/product/{$slug}/" );
		return $p;
	}

	private function variation( int $id, string $label, bool $purchasable = true ) {
		$v = \Mockery::mock( 'WC_Product' );
		$v->shouldReceive( 'get_id' )->andReturn( $id );
		$v->shouldReceive( 'get_name' )->andReturn( $label );
		$v->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => 'variation' === $t );
		$v->shouldReceive( 'is_purchasable' )->andReturn( $purchasable );
		return $v;
	}

	/** Render the footer block for $product as the current single-product page, return the HTML. */
	private function render_for( $product, array $variations = [] ): string {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( $product->get_id() );
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( $product, $variations ) {
				if ( (int) $id === $product->get_id() ) {
					return $product;
				}
				return $variations[ (int) $id ] ?? null;
			}
		);
		ob_start();
		$this->jsonld->render_product_checkout_links();
		return (string) ob_get_clean();
	}

	public function test_simple_product_renders_one_checkout_link(): void {
		$html = $this->render_for( $this->simple_product( 42, 'a-product' ) );

		$this->assertStringContainsString( 'wc-ai-storefront-agent-checkout', $html );
		$this->assertStringContainsString( 'https://example.com/checkout-link/?products=42:1', $html );
	}

	public function test_variable_small_renders_concrete_variant_links(): void {
		$vars = [
			101 => $this->variation( 101, 'Belt - S/M' ),
			102 => $this->variation( 102, 'Belt - L/XL' ),
		];
		$html = $this->render_for( $this->variable_product( 100, 'canvas-belt', [ 101, 102 ] ), $vars );

		$this->assertStringContainsString( 'products=101:1', $html );
		$this->assertStringContainsString( 'products=102:1', $html );
		$this->assertStringContainsString( 'Belt - S/M', $html );
		// Construct kit always present for variable products:
		$this->assertStringContainsString( 'https://example.com/products/canvas-belt.json', $html );
		$this->assertStringContainsString( 'products={variation_id}:1', $html );
	}

	public function test_variable_large_omits_concrete_links_keeps_construct_kit(): void {
		$ids  = range( 201, 207 ); // 7 variations > 4
		$vars = [];
		foreach ( $ids as $i => $vid ) {
			$vars[ $vid ] = $this->variation( $vid, "Opt {$i}" );
		}
		$html = $this->render_for( $this->variable_product( 200, 'big-shirt', $ids ), $vars );

		$this->assertStringNotContainsString( 'products=201:1', $html );           // no concrete links
		$this->assertStringContainsString( 'https://example.com/products/big-shirt.json', $html ); // construct kit
		$this->assertStringContainsString( 'products={variation_id}:1', $html );
	}

	public function test_unpurchasable_variation_skipped_from_concrete_links(): void {
		$vars = [
			301 => $this->variation( 301, 'Live', true ),
			302 => $this->variation( 302, 'Dead', false ),
		];
		$html = $this->render_for( $this->variable_product( 300, 'two-var', [ 301, 302 ] ), $vars );

		$this->assertStringContainsString( 'products=301:1', $html );
		$this->assertStringNotContainsString( 'products=302:1', $html );
	}

	/**
	 * Spec Testing: "Bundle / grouped -> the permalink-based link."
	 *
	 * Bundle and grouped products take the get_permalink() + UTM branch
	 * (lines 475-478), NOT the `/checkout-link/?products=` Shareable
	 * Checkout URL form that simple/variable/variation use.
	 *
	 * @dataProvider permalink_based_type_provider
	 */
	public function test_bundle_or_grouped_renders_permalink_based_link( string $type ): void {
		$html = $this->render_for(
			$this->bundle_or_grouped_product( 500, $type, "a-{$type}", "A {$type} Product" )
		);

		// Permalink-based checkout URL surfaces with UTM attribution.
		$this->assertStringContainsString(
			"https://example.com/product/a-{$type}/?utm_source={agent_id}",
			$html
		);
		$this->assertStringContainsString( 'utm_id=woo_jsonld', $html );
		// Product name surfaces as the anchor text.
		$this->assertStringContainsString( "A {$type} Product", $html );
		// Must NOT fall through to the Shareable Checkout URL shape.
		$this->assertStringNotContainsString( '/checkout-link/?products=', $html );
	}

	public static function permalink_based_type_provider(): array {
		return [
			'bundle'  => [ 'bundle' ],
			'grouped' => [ 'grouped' ],
		];
	}

	/**
	 * Spec Edge cases: "unpurchasable simple product -> emit nothing" (#373).
	 *
	 * Exercises the simple-branch guard (lines 514-517): a non-purchasable
	 * simple product must render nothing rather than hand out a SKU WC
	 * would reject at checkout.
	 */
	public function test_unpurchasable_simple_product_renders_nothing(): void {
		$html = $this->render_for( $this->simple_product( 42, 'dead-product', false ) );
		$this->assertSame( '', $html );
	}

	/**
	 * Spec Edge cases: "No purchasable variations -> emit nothing"
	 * (lines 498-500). When every variation is non-purchasable the
	 * variable branch collects an empty set and emits nothing.
	 */
	public function test_variable_all_variations_unpurchasable_renders_nothing(): void {
		$vars = [
			601 => $this->variation( 601, 'Dead A', false ),
			602 => $this->variation( 602, 'Dead B', false ),
		];
		$html = $this->render_for( $this->variable_product( 600, 'all-dead', [ 601, 602 ] ), $vars );
		$this->assertSame( '', $html );
	}

	public function test_renders_nothing_when_disabled(): void {
		WC_AI_Storefront::$test_settings = [ 'enabled' => 'no' ];
		$html                            = $this->render_for( $this->simple_product( 42 ) );
		$this->assertSame( '', $html );
	}

	public function test_renders_nothing_when_not_product_page(): void {
		Functions\when( 'is_product' )->justReturn( false );
		ob_start();
		$this->jsonld->render_product_checkout_links();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Spec Testing gating: "renders nothing when ... is_product_syndicated()
	 * is false." Exercises the syndication gate (lines 448-450) which the
	 * enabled=no and !is_product() tests leave untouched.
	 *
	 * `product_selection_mode='selected'` with an empty `selected_products`
	 * list makes the stub's is_product_syndicated() return false, so the
	 * anchor must render nothing even though enabled=yes and we are on a
	 * product page.
	 */
	public function test_renders_nothing_when_not_syndicated(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'selected',
			'selected_products'      => [],
		];
		$html = $this->render_for( $this->simple_product( 42 ) );
		$this->assertSame( '', $html );
	}

	public function test_variable_omits_json_link_when_feed_disabled(): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'products_json_enabled'  => 'no',
		];
		$vars = [ 401 => $this->variation( 401, 'X' ) ];
		$html = $this->render_for( $this->variable_product( 400, 'nofeed', [ 401 ] ), $vars );

		$this->assertStringNotContainsString( '/products/nofeed.json', $html );
		$this->assertStringContainsString( 'products=401:1', $html ); // concrete link still emitted (<=4)
	}
}
