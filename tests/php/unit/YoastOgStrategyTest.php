<?php
/**
 * Tests for the Yoast Open Graph enrichment strategy.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * A presenter double carrying nothing but a key.
 *
 * Enough for the two things the strategy asks of a presenter: what property it
 * renders, and whether that property is article vocabulary.
 */
class WC_AI_Storefront_Presenter_Double extends \Yoast\WP\SEO\Presenters\Abstract_Indexable_Tag_Presenter {

	/**
	 * @param string $key Property name.
	 */
	public function __construct( string $key ) {
		$this->key = $key;
	}

	/**
	 * @return string
	 */
	public function get() {
		return 'stub';
	}
}

class YoastOgStrategyTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Commerce-page gate handed to the strategy.
	 *
	 * @var bool
	 */
	private bool $on_commerce_page = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_product_category' )->justReturn( false );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		$this->on_commerce_page = true;
		WC_AI_Storefront_Og_Strategies::reset();
	}

	protected function tearDown(): void {
		WC_AI_Storefront_Og_Strategies::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function gate(): callable {
		return function () {
			return $this->on_commerce_page;
		};
	}

	private function strategy(): WC_AI_Storefront_Og_Strategy_Yoast {
		$strategy = new WC_AI_Storefront_Og_Strategy_Yoast();
		$strategy->init( $this->gate() );
		return $strategy;
	}

	/**
	 * @param string[] $keys Property names the double list should carry.
	 * @return WC_AI_Storefront_Presenter_Double[]
	 */
	private function presenters( array $keys ): array {
		return array_map(
			static function ( $key ) {
				return new WC_AI_Storefront_Presenter_Double( $key );
			},
			$keys
		);
	}

	/**
	 * The property names a presenter list renders, in order.
	 *
	 * @param array<int,mixed> $presenters Presenter list.
	 * @return string[]
	 */
	private function keys_of( array $presenters ): array {
		return array_values(
			array_filter(
				array_map(
					static function ( $presenter ) {
						return $presenter->escape_key();
					},
					$presenters
				)
			)
		);
	}

	// --- Identity and wiring ---

	public function test_declares_the_slug_the_detector_reports(): void {
		$this->assertSame( 'yoast', WC_AI_Storefront_Og_Strategy_Yoast::slug() );
	}

	public function test_enriches_rather_than_suppresses(): void {
		// Yoast exposes extension points that reach the page, and its paid
		// WooCommerce addon uses the same two, so removal would throw away
		// merchant-authored fields for no gain.
		$this->assertSame(
			WC_AI_Storefront_Og_Strategy::MODE_ENRICH,
			WC_AI_Storefront_Og_Strategy_Yoast::mode()
		);
	}

	public function test_hooks_the_type_filter_after_the_woocommerce_addon(): void {
		// The addon filters wpseo_opengraph_type at the default priority. On a
		// singular product we must run after it and agree, not before it and
		// get overwritten.
		Filters\expectAdded( 'wpseo_opengraph_type' )->once()->with( \Mockery::type( 'array' ), 20 );
		$this->strategy();
	}

	public function test_hooks_the_presenter_pipeline_after_the_addon(): void {
		// The addon adds its presenters on this filter at priority 10, and
		// registers after us, so at the default priority we would not see its
		// product:* tags and would ship each of them twice.
		Filters\expectAdded( 'wpseo_frontend_presenters' )->once()->with( \Mockery::type( 'array' ), 99 );
		$this->strategy();
	}

	// --- og:type ---

	public function test_type_becomes_product_on_a_product(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertSame( 'product', $this->strategy()->filter_type( 'article' ) );
	}

	public function test_type_becomes_website_on_a_category(): void {
		Functions\when( 'is_product_category' )->justReturn( true );
		// Not `article`: a category listing has no author and no publish date.
		// Open Graph has no collection type, and `website` is its own default.
		$this->assertSame( 'website', $this->strategy()->filter_type( 'article' ) );
	}

	public function test_type_becomes_website_on_the_shop_and_on_search(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		$this->assertSame( 'website', $this->strategy()->filter_type( 'article' ) );

		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( true );
		$this->assertSame( 'website', $this->strategy()->filter_type( 'article' ) );
	}

	public function test_type_is_untouched_off_a_commerce_page(): void {
		$this->on_commerce_page = false;
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertSame( 'article', $this->strategy()->filter_type( 'article' ) );
	}

	public function test_type_agrees_with_the_addon_rather_than_fighting_it(): void {
		// With the addon active this filter receives 'product', not 'article'.
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertSame( 'product', $this->strategy()->filter_type( 'product' ) );
	}

	// --- Presenters ---

	public function test_article_presenters_are_dropped_on_commerce_pages(): void {
		Functions\when( 'is_shop' )->justReturn( true );

		$kept = $this->strategy()->filter_presenters(
			$this->presenters(
				array( 'og:title', 'article:modified_time', 'article:author', 'og:url' )
			)
		);

		// article:* rides along even once og:type is corrected, because
		// Yoast's type presenter and its article presenters are separate.
		$keys = $this->keys_of( $kept );
		$this->assertContains( 'og_title', $keys );
		$this->assertContains( 'og_url', $keys );
		$this->assertNotContains( 'article_modified_time', $keys );
		$this->assertNotContains( 'article_author', $keys );
	}

	public function test_an_archive_costs_no_commerce_lookup(): void {
		// Nothing build_archive_og_tags() produces survives
		// is_commerce_property(), so building it would run the archive image
		// resolver's product query once per render for nothing.
		Functions\when( 'is_product_category' )->justReturn( true );
		$queried = 0;
		Functions\when( 'wc_get_products' )->alias(
			static function () use ( &$queried ) {
				++$queried;
				return array();
			}
		);

		$kept = $this->strategy()->filter_presenters( $this->presenters( array( 'og:title' ) ) );

		$this->assertSame( 0, $queried );
		$this->assertSame( array( 'og_title' ), $this->keys_of( $kept ) );
	}

	public function test_presenters_are_untouched_off_a_commerce_page(): void {
		$this->on_commerce_page = false;
		$given                  = $this->presenters( array( 'og:title', 'article:modified_time' ) );
		$this->assertSame( $given, $this->strategy()->filter_presenters( $given ) );
	}

	/**
	 * A product adequate for build_og_tags() and build_twitter_tags().
	 *
	 * Deliberately its own mock rather than a shared fixture: this suite is
	 * about which properties reach Yoast, not about how they are computed,
	 * and MetaTagsTest already owns the computation.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 */
	private function product( array $overrides = array() ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_short_description' )->andReturn( 'A belt.' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'is_purchasable' )->andReturn( true );
		$product->shouldReceive( 'get_price' )->andReturn( $overrides['price'] ?? '48.00' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_stock_status' )->andReturn( 'instock' );
		$product->shouldReceive( 'get_availability' )->andReturn(
			array(
				'availability' => '',
				'class'        => 'in-stock',
			)
		);
		$product->shouldReceive( 'get_attributes' )->andReturn( array() );

		return $product;
	}

	/**
	 * Stub everything build_og_tags() reaches for on a product page.
	 */
	private function stub_product_page(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/canvas-belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'esc_url' )->returnArg();
		// wp_strip_all_tags is a real function from tests/php/stubs.php, loaded
		// before Patchwork, so Brain Monkey cannot redefine it. It behaves as
		// identity for the plain text these tests use.
		Functions\when( 'wc_price' )->alias(
			static function ( $price ) {
				return '&#036;' . number_format( (float) $price, 2 );
			}
		);
		Functions\when( 'wc_get_product' )->justReturn( $this->product() );
	}

	// --- Enrichment: the commerce facts Yoast lacks ---

	public function test_price_is_added_when_yoast_does_not_supply_it(): void {
		// The gap the WooCommerce addon leaves on variable products, and the
		// gap free Yoast leaves on every product.
		$this->stub_product_page();

		$kept = $this->strategy()->filter_presenters( $this->presenters( array( 'og:title', 'og:url' ) ) );
		$keys = $this->keys_of( $kept );

		$this->assertContains( 'product_price_amount', $keys );
		$this->assertContains( 'product_price_currency', $keys );
		$this->assertContains( 'product_availability', $keys );
		$this->assertContains( 'og_availability', $keys );
	}

	public function test_a_property_yoast_already_supplies_is_not_added_twice(): void {
		$this->stub_product_page();

		$kept = $this->strategy()->filter_presenters(
			$this->presenters( array( 'og:title', 'product:price:amount', 'product:availability' ) )
		);
		$keys = $this->keys_of( $kept );

		$this->assertSame( 1, count( array_keys( $keys, 'product_price_amount', true ) ) );
		$this->assertSame( 1, count( array_keys( $keys, 'product_availability', true ) ) );
		// Still fills what is genuinely missing beside them.
		$this->assertContains( 'product_price_currency', $keys );
	}

	public function test_page_description_properties_are_left_to_yoast(): void {
		// og:title, og:description, og:url, og:site_name, og:image and
		// og:locale are Yoast's to own: the merchant may have authored them in
		// Yoast's own fields, and #668 settled that authored text wins.
		$this->stub_product_page();

		$kept  = $this->strategy()->filter_presenters( $this->presenters( array( 'og:url' ) ) );
		$added = array_diff( $this->keys_of( $kept ), array( 'og_url' ) );

		foreach ( $added as $key ) {
			$this->assertTrue(
				0 === strpos( $key, 'product_' ) || 'og_availability' === $key || 0 === strpos( $key, 'twitter_' ),
				"Added $key, which is a page description rather than a commerce fact."
			);
		}
	}

	public function test_the_added_presenters_render_as_property_tags(): void {
		// property= for Open Graph, name= for Twitter. Getting it wrong is
		// silent: both render, only one is read.
		$this->stub_product_page();

		$kept = $this->strategy()->filter_presenters( $this->presenters( array() ) );
		foreach ( $kept as $presenter ) {
			$key    = $presenter->escape_key();
			$markup = $presenter->present();
			if ( 0 === strpos( (string) $key, 'twitter_' ) ) {
				$this->assertStringContainsString( '<meta name=', $markup );
			} else {
				$this->assertStringContainsString( '<meta property=', $markup );
			}
		}
	}

	public function test_a_commerce_presenter_of_theirs_is_replaced_not_joined(): void {
		// The addon registers product:price:amount for every product and
		// returns '' from it on a variable one. Asking the presenter what it
		// would render is not possible here — Yoast assigns
		// $presenter->presentation after this filter returns, so get() throws
		// mid-wp_head — so we take ownership of the keys we supply instead.
		$this->stub_product_page();

		$kept = $this->strategy()->filter_presenters(
			$this->presenters( array( 'og:title', 'product:price:amount' ) )
		);
		$keys = $this->keys_of( $kept );

		$this->assertSame( 1, count( array_keys( $keys, 'product_price_amount', true ) ) );
		$this->assertContains( 'og_title', $keys );
	}

	public function test_their_properties_we_do_not_supply_are_kept(): void {
		// product:brand and product:retailer_item_id are the addon's, and we
		// emit neither. Taking them would lose a fact for no gain.
		$this->stub_product_page();

		$keys = $this->keys_of(
			$this->strategy()->filter_presenters(
				$this->presenters( array( 'product:brand', 'product:retailer_item_id' ) )
			)
		);

		$this->assertContains( 'product_brand', $keys );
		$this->assertContains( 'product_retailer_item_id', $keys );
	}

	public function test_no_presenter_is_asked_what_it_would_render(): void {
		// Calling get() during this filter throws, because Yoast has not
		// assigned $presenter->presentation yet, and the page truncates
		// mid-head. Measured live.
		$this->stub_product_page();

		$exploding = new class( 'product:price:amount' ) extends \Yoast\WP\SEO\Presenters\Abstract_Indexable_Tag_Presenter {
			public function __construct( string $key ) {
				$this->key = $key;
			}
			public function get() {
				throw new \RuntimeException( 'presentation is not set yet' );
			}
		};

		$this->assertNotEmpty( $this->strategy()->filter_presenters( array( $exploding ) ) );
	}

	// --- Twitter label rows ---

	public function test_the_price_row_is_contributed_through_yoasts_enhanced_data(): void {
		// twitter:label1/data1 are not presenters: Yoast's Slack presenter
		// numbers a label => value array. Emitting our own label1 beside
		// Yoast's produced two different label1 rows on one page.
		$this->stub_product_page();

		$data = $this->strategy()->filter_slack_data( array( 'Availability' => 'In stock' ) );

		$this->assertSame( 'In stock', $data['Availability'], 'Their row stays.' );
		$this->assertArrayHasKey( 'Price', $data );
	}

	public function test_a_row_they_already_supply_is_not_added_again(): void {
		$this->stub_product_page();

		$data = $this->strategy()->filter_slack_data( array( 'Price' => '&#036;18.00' ) );

		$this->assertSame( '&#036;18.00', $data['Price'], 'Theirs wins; we only fill gaps.' );
	}

	public function test_enhanced_data_is_untouched_off_a_product(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		$given = array( 'Est. reading time' => '3 minutes' );
		$this->assertSame( $given, $this->strategy()->filter_slack_data( $given ) );
	}

	public function test_enhanced_data_is_untouched_off_a_commerce_page(): void {
		$this->on_commerce_page = false;
		$given                  = array( 'Est. reading time' => '3 minutes' );
		$this->assertSame( $given, $this->strategy()->filter_slack_data( $given ) );
	}

	public function test_hooks_enhanced_data_after_the_addon(): void {
		Filters\expectAdded( 'wpseo_enhanced_slack_data' )->once()->with( \Mockery::type( 'array' ), 20 );
		$this->strategy();
	}

	public function test_a_non_array_presenter_list_is_returned_unchanged(): void {
		$this->assertNull( $this->strategy()->filter_presenters( null ) );
	}
}
