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
		// Empty children is how the variable-branch capability gate
		// (`method_exists( …, 'get_variation_attributes' ) && get_children()`)
		// distinguishes a simple product: the `WC_Product` test stub declares
		// `get_variation_attributes()` on the base (so `method_exists` is
		// always true in stub-land), so `get_children()` is the real
		// discriminator — empty here means "fall through to the simple branch".
		$p->shouldReceive( 'get_children' )->andReturn( [] );
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
		$product = $this->simple_product( 42, 'a-product' );
		$html    = $this->render_for( $product );

		$this->assertStringContainsString( 'wc-ai-storefront-agent-checkout', $html );
		// URL renders as `<code>` text byte-identical to the accessor's
		// no-identity-source output — the esc_url-safe `ucp_unknown`, NOT the
		// `{agent_id}` placeholder. assertSame so any drift fails.
		$this->assertSame( 1, preg_match( '/<code>([^<]+)<\/code>/', $html, $m ) );
		$this->assertSame(
			WC_AI_Storefront_JsonLd::checkout_url_template( $product, WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE ),
			$m[1]
		);
		$this->assertStringContainsString( 'utm_source=ucp_unknown', $m[1] );
		$this->assertStringNotContainsString( '{agent_id}', $m[1] );
	}

	/**
	 * Simple product: the URL renders as visible `<code>` text, not an
	 * `<a href>`. Markdown-extraction fetch tools drop href attributes (keeping
	 * only the link text), so the `<a href>` form is unreachable while `<code>`
	 * text survives.
	 */
	public function test_simple_product_renders_url_as_code_text(): void {
		$product = $this->simple_product( 42, 'a-product' );
		$html    = $this->render_for( $product );
		$url     = WC_AI_Storefront_JsonLd::checkout_url_template( $product, WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE );
		$this->assertStringContainsString( 'Agent checkout: <code>' . $url . '</code>', $html );
		$this->assertStringNotContainsString( '>buy this item</a>', $html ); // no clickable-label form
	}

	/**
	 * Concrete variant: the URL renders as "Label: <code>url</code>", not an
	 * `<a href>` whose visible text is a bare "checkout" label.
	 */
	public function test_concrete_variant_renders_url_as_code_text(): void {
		$variation = $this->variation( 901, 'Tall' );
		$html      = $this->render_for( $this->variable_product( 900, 'one-var', [ 901 ] ), [ 901 => $variation ] );
		$url       = WC_AI_Storefront_JsonLd::checkout_url_template( $variation, WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE );
		$this->assertStringContainsString( 'Tall: <code>' . $url . '</code>', $html );
		$this->assertStringNotContainsString( '>checkout</a>', $html );
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
	 * A `get_children()` id that `wc_get_product()` can't resolve (a stale
	 * entry pointing at a trashed/deleted variation) must be skipped, never
	 * fataled on `null->is_purchasable()`. The resolvable sibling still
	 * renders. Pins the `! $variation instanceof WC_Product` guard, which is
	 * otherwise invisible to the suite (mutating its `continue` to a no-op
	 * leaves every other test green).
	 */
	public function test_unresolvable_variation_child_is_skipped(): void {
		// 502 is intentionally absent from the variations map, so the
		// render_for() wc_get_product() stub returns null for that id.
		$vars = [ 501 => $this->variation( 501, 'Live' ) ];
		$html = $this->render_for( $this->variable_product( 503, 'stale-child', [ 501, 502 ] ), $vars );

		$this->assertStringContainsString( 'products=501:1', $html );
		$this->assertStringNotContainsString( 'products=502:1', $html );
	}

	/**
	 * `is_product()` is true but `wc_get_product( get_queried_object_id() )`
	 * returns null (the queried object isn't a product, or it was deleted) —
	 * the anchor must render nothing, never fatal on a null product. Pins the
	 * top-level `! $product instanceof WC_Product` guard.
	 */
	public function test_renders_nothing_when_product_unresolvable(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 999 );
		Functions\when( 'wc_get_product' )->justReturn( null );

		ob_start();
		$this->jsonld->render_product_checkout_links();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Spec Testing: "Bundle / grouped -> the permalink-based link."
	 *
	 * Bundle and grouped products take the get_permalink() + UTM branch,
	 * NOT the `/checkout-link/?products=` Shareable Checkout URL form that
	 * simple/variable/variation use.
	 *
	 * @dataProvider permalink_based_type_provider
	 */
	public function test_bundle_or_grouped_renders_permalink_based_link( string $type ): void {
		$product = $this->bundle_or_grouped_product( 500, $type, "a-{$type}", "A {$type} Product" );
		$html    = $this->render_for( $product );

		// URL renders as `<code>` text labelled with the product name, byte-
		// identical to the accessor's no-identity-source permalink URL. Not a
		// substring check, so a trailing/ordering drift on this path fails.
		$this->assertSame( 1, preg_match( '/Agent checkout \(A ' . $type . ' Product\): <code>([^<]+)<\/code>/', $html, $m ) );
		$this->assertSame(
			WC_AI_Storefront_JsonLd::checkout_url_template( $product, WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE ),
			$m[1]
		);
		$this->assertStringContainsString( 'utm_source=ucp_unknown', $m[1] );
		$this->assertStringContainsString( 'utm_id=woo_jsonld', $html );
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
	 * Exercises the `! $product->is_purchasable()` simple-branch guard: a
	 * non-purchasable simple product must render nothing rather than hand
	 * out a SKU WC would reject at checkout.
	 */
	public function test_unpurchasable_simple_product_renders_nothing(): void {
		$html = $this->render_for( $this->simple_product( 42, 'dead-product', false ) );
		$this->assertSame( '', $html );
	}

	/**
	 * Spec Edge cases: "No purchasable variations -> emit nothing". When
	 * every variation is non-purchasable the variable branch collects an
	 * empty set (the `empty( $variations )` guard) and emits nothing.
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
	 * is false." Exercises the `is_product_syndicated()` gate which the
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

	/**
	 * A `WC_Product_Variable` subclass (variable-subscription) whose
	 * `is_type()` returns its own slug — NOT 'variable'. The body anchor
	 * must route it through the variation branch (matching the `<script>`
	 * BuyAction's capability gate) and emit per-variant variation-ID links,
	 * never a single parent-ID `/checkout-link/?products=<parent_id>:1`
	 * link WC rejects at checkout.
	 *
	 * @param string $type The product-type slug `is_type()` returns true for.
	 * @dataProvider variable_subclass_type_provider
	 */
	public function test_variable_subclass_routes_through_variation_branch( string $type ): void {
		$vars   = [
			801 => $this->variation( 801, 'Monthly' ),
			802 => $this->variation( 802, 'Yearly' ),
		];
		$parent = $this->variable_subclass_product( 800, 'sub-plan', [ 801, 802 ], $type );
		$html   = $this->render_for( $parent, $vars );

		// Per-variant variation-ID links present.
		$this->assertStringContainsString( 'products=801:1', $html );
		$this->assertStringContainsString( 'products=802:1', $html );
		// Construct kit present.
		$this->assertStringContainsString( 'products={variation_id}:1', $html );
		$this->assertStringContainsString( 'https://example.com/products/sub-plan.json', $html );
		// MUST NOT emit a parent-ID checkout link (the broken fall-through).
		$this->assertStringNotContainsString( 'products=800:1', $html );
	}

	public static function variable_subclass_type_provider(): array {
		return [
			// is_type('variable') is FALSE for both; only the subclass slug
			// matches. Proves the gate no longer depends on is_type('variable').
			'variable-subscription' => [ 'variable-subscription' ],
			'variable-bundle'       => [ 'variable-bundle' ],
		];
	}

	/**
	 * Boundary: exactly CHECKOUT_ANCHOR_VARIANT_MAX (4) purchasable
	 * variations renders all four concrete links + the construct kit.
	 * Pins the `<=` boundary so an off-by-one (`<`) would fail here.
	 */
	public function test_variable_exactly_four_renders_all_concrete_links(): void {
		$ids  = [ 701, 702, 703, 704 ]; // exactly 4 == CHECKOUT_ANCHOR_VARIANT_MAX
		$vars = [];
		foreach ( $ids as $i => $vid ) {
			$vars[ $vid ] = $this->variation( $vid, "Opt {$i}" );
		}
		$html = $this->render_for( $this->variable_product( 700, 'four-var', $ids ), $vars );

		$this->assertStringContainsString( 'products=701:1', $html );
		$this->assertStringContainsString( 'products=702:1', $html );
		$this->assertStringContainsString( 'products=703:1', $html );
		$this->assertStringContainsString( 'products=704:1', $html );
		// Construct kit also present.
		$this->assertStringContainsString( 'products={variation_id}:1', $html );
		$this->assertStringContainsString( 'https://example.com/products/four-var.json', $html );
	}

	/**
	 * Boundary: exactly 5 purchasable variations (> CHECKOUT_ANCHOR_VARIANT_MAX)
	 * emits NO concrete `products=<id>:1` links, only the construct kit +
	 * `.json` feed. Pins the `<=` boundary so an off-by-one (`<=5`) would
	 * fail here.
	 */
	public function test_variable_exactly_five_omits_concrete_links(): void {
		$ids  = [ 711, 712, 713, 714, 715 ]; // exactly 5 > CHECKOUT_ANCHOR_VARIANT_MAX
		$vars = [];
		foreach ( $ids as $i => $vid ) {
			$vars[ $vid ] = $this->variation( $vid, "Opt {$i}" );
		}
		$html = $this->render_for( $this->variable_product( 710, 'five-var', $ids ), $vars );

		// No concrete per-variant links at the 5-variation boundary.
		$this->assertStringNotContainsString( 'products=711:1', $html );
		$this->assertStringNotContainsString( 'products=712:1', $html );
		$this->assertStringNotContainsString( 'products=713:1', $html );
		$this->assertStringNotContainsString( 'products=714:1', $html );
		$this->assertStringNotContainsString( 'products=715:1', $html );
		// Construct kit + feed remain.
		$this->assertStringContainsString( 'products={variation_id}:1', $html );
		$this->assertStringContainsString( 'https://example.com/products/five-var.json', $html );
	}

	/**
	 * Cardinal invariant: the rendered concrete-variant URL is byte-identical
	 * to {@see WC_AI_Storefront_JsonLd::checkout_url_template()} (and therefore
	 * the `<script>` BuyAction). Extract the URL from the rendered `<code>`
	 * text and assertSame against the accessor so any divergence on the
	 * concrete path fails — substring checks alone can't catch a
	 * trailing/ordering drift.
	 */
	public function test_concrete_variant_href_is_byte_identical_to_accessor(): void {
		$variation = $this->variation( 901, 'Tall' );
		$vars      = [ 901 => $variation ];
		$html      = $this->render_for( $this->variable_product( 900, 'one-var', [ 901 ] ), $vars );

		$this->assertSame( 1, preg_match( '/Tall: <code>([^<]+)<\/code>/', $html, $m ) );
		$rendered_url  = $m[1];
		$expected_url  = WC_AI_Storefront_JsonLd::checkout_url_template( $variation, WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE );

		$this->assertSame( $expected_url, $rendered_url );
		// Concrete URL uses the no-identity source, never the `{agent_id}`
		// placeholder (which the construct-kit template keeps instead).
		$this->assertStringContainsString( 'utm_source=ucp_unknown', $rendered_url );
		$this->assertStringNotContainsString( '{agent_id}', $rendered_url );
	}

	/**
	 * Cardinal invariant on the construct-kit `<code>` template: it must be the
	 * accessor's own output with the concrete id swapped for the
	 * `{variation_id}` placeholder — NOT a self-referential literal. Deriving
	 * the expectation from {@see WC_AI_Storefront_JsonLd::checkout_url_template()}
	 * means a change to the accessor's UTM tail (utm_source/utm_medium/utm_id)
	 * propagates here, so any drift between the template and the concrete
	 * per-variant links (and therefore the `<script>` BuyAction) fails.
	 */
	public function test_construct_kit_template_is_derived_from_accessor(): void {
		$variation = $this->variation( 911, 'X' );
		$vars      = [ 911 => $variation ];
		$html      = $this->render_for( $this->variable_product( 910, 'kit-var', [ 911 ] ), $vars );

		$expected_template = str_replace(
			'products=911:1',
			'products={variation_id}:1',
			WC_AI_Storefront_JsonLd::checkout_url_template( $variation )
		);

		$this->assertStringContainsString( '<code>' . $expected_template . '</code>', $html );
		// Sanity: the derived template still carries the full UTM tail.
		$this->assertStringContainsString(
			'products={variation_id}:1&utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld',
			$html
		);
	}

	/**
	 * Variable-subscription-shaped parent: `is_type()` returns true only for
	 * the given subclass slug (e.g. 'variable-subscription'), and crucially
	 * `is_type('variable')` is FALSE. The capability gate
	 * (`method_exists( …, 'get_variation_attributes' ) && get_children()`)
	 * is what routes it through the variation branch.
	 *
	 * @param int      $id            Parent product ID.
	 * @param string   $slug          Parent slug.
	 * @param int[]    $variation_ids Child variation IDs.
	 * @param string   $type          The subclass type slug.
	 */
	private function variable_subclass_product( int $id, string $slug, array $variation_ids, string $type ) {
		$p = \Mockery::mock( 'WC_Product' );
		$p->shouldReceive( 'get_id' )->andReturn( $id );
		$p->shouldReceive( 'get_slug' )->andReturn( $slug );
		$p->shouldReceive( 'get_name' )->andReturn( ucfirst( $slug ) );
		// is_type('variable') is FALSE — only the subclass slug matches.
		$p->shouldReceive( 'is_type' )->andReturnUsing( fn( $t ) => $type === $t );
		$p->shouldReceive( 'is_purchasable' )->andReturn( true );
		$p->shouldReceive( 'get_children' )->andReturn( $variation_ids );
		$p->shouldReceive( 'get_permalink' )->andReturn( "https://example.com/product/{$slug}/" );
		return $p;
	}
}
