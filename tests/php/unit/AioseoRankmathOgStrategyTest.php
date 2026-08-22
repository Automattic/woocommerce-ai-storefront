<?php
/**
 * Tests for the All in One SEO and Rank Math Open Graph strategies.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Stands in for RankMath\OpenGraph\Facebook, which is what the
 * `rank_math/opengraph/facebook` action receives. Records what was added.
 */
class WC_AI_Storefront_Rankmath_Og_Double {

	/**
	 * Property => value, in the order tag() was called.
	 *
	 * @var array<string,string>
	 */
	public array $tags = array();

	/**
	 * @param string $property Open Graph property.
	 * @param string $content  Its value.
	 */
	public function tag( $property, $content ) {
		$this->tags[ $property ] = $content;
	}
}

class AioseoRankmathOgStrategyTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Commerce-page gate handed to each strategy.
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
		Functions\when( 'wc_price' )->alias(
			static function ( $price ) {
				return '&#036;' . number_format( (float) $price, 2 );
			}
		);

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_short_description' )->andReturn( 'A belt.' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'is_purchasable' )->andReturn( true );
		$product->shouldReceive( 'get_price' )->andReturn( '48.00' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_stock_status' )->andReturn( 'instock' );
		$product->shouldReceive( 'get_availability' )->andReturn(
			array(
				'availability' => '',
				'class'        => 'in-stock',
			)
		);
		$product->shouldReceive( 'get_attributes' )->andReturn( array() );
		Functions\when( 'wc_get_product' )->justReturn( $product );
	}

	private function aioseo(): WC_AI_Storefront_Og_Strategy_Aioseo {
		$strategy = new WC_AI_Storefront_Og_Strategy_Aioseo( new WC_AI_Storefront_Og_Commerce_Facts() );
		$strategy->init( $this->gate() );
		return $strategy;
	}

	private function rankmath(): WC_AI_Storefront_Og_Strategy_Rankmath {
		$strategy = new WC_AI_Storefront_Og_Strategy_Rankmath( new WC_AI_Storefront_Og_Commerce_Facts() );
		$strategy->init( $this->gate() );
		return $strategy;
	}

	// --- Identity ---

	public function test_both_declare_the_slugs_the_detector_reports(): void {
		$this->assertSame( 'aioseo', WC_AI_Storefront_Og_Strategy_Aioseo::slug() );
		$this->assertSame( 'rankmath', WC_AI_Storefront_Og_Strategy_Rankmath::slug() );
	}

	public function test_both_enrich_rather_than_suppress(): void {
		$this->assertSame( WC_AI_Storefront_Og_Strategy::MODE_ENRICH, WC_AI_Storefront_Og_Strategy_Aioseo::mode() );
		$this->assertSame( WC_AI_Storefront_Og_Strategy::MODE_ENRICH, WC_AI_Storefront_Og_Strategy_Rankmath::mode() );
	}

	public function test_the_dispatcher_knows_both(): void {
		$strategies = WC_AI_Storefront_Og_Strategies::for_slugs( array( 'aioseo', 'rankmath' ) );
		$this->assertCount( 2, $strategies );
	}

	// --- Shared commerce facts ---

	public function test_facts_do_not_cache_the_answer_from_before_the_query(): void {
		// A strategy's init() runs long before the query is resolved, so the
		// first call to properties() answers "not a product" for a request
		// that turns out to be one. Memoising that emptied every downstream
		// map, and every page lost its price (#676, caught live, not here).
		$facts = new WC_AI_Storefront_Og_Commerce_Facts();

		$this->assertSame( array(), $facts->properties(), 'Nothing is knowable at init().' );

		$this->stub_product_page();

		$this->assertSame( '48.00', $facts->properties()['product:price:amount'] );
	}

	public function test_the_owned_vocabulary_covers_what_properties_produces(): void {
		// Rank Math registers one hook per property at init(), against the
		// vocabulary rather than against values that do not exist yet. A
		// property missing from the list gets no filter, so Rank Math's own
		// value would reach the page unsubstituted.
		$this->stub_product_page();

		$produced = array_keys( ( new WC_AI_Storefront_Og_Commerce_Facts() )->properties() );

		foreach ( $produced as $property ) {
			$this->assertContains(
				$property,
				WC_AI_Storefront_Og_Commerce_Facts::OWNED_PROPERTIES,
				"properties() produced $property, which OWNED_PROPERTIES does not list."
			);
		}
	}

	public function test_facts_exclude_the_page_description_properties(): void {
		$this->stub_product_page();
		$properties = ( new WC_AI_Storefront_Og_Commerce_Facts() )->properties();

		foreach ( array( 'og:title', 'og:description', 'og:url', 'og:site_name', 'og:locale', 'og:type' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $properties, "$key belongs to the other plugin." );
		}
	}

	// --- AIOSEO ---

	public function test_aioseo_does_not_take_over_a_product_category(): void {
		// AIOSEO emits no Open Graph there and neither filter fires, so
		// standing our own block down would leave the page with none.
		Functions\when( 'is_product_category' )->justReturn( true );
		$this->assertFalse( $this->aioseo()->has_taken_over() );
	}

	public function test_aioseo_takes_over_the_other_commerce_pages(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		$this->assertTrue( $this->aioseo()->has_taken_over() );
	}

	public function test_aioseo_type_becomes_product_and_article_keys_go(): void {
		$this->stub_product_page();

		$tags = $this->aioseo()->filter_facebook_tags(
			array(
				'og:type'                => 'article',
				'og:title'               => 'Canvas Belt',
				'article:published_time' => '2026-01-01',
				'article:author'         => '',
				'article:tag'            => array(),
			)
		);

		$this->assertSame( 'product', $tags['og:type'] );
		$this->assertSame( 'Canvas Belt', $tags['og:title'] );
		$this->assertArrayNotHasKey( 'article:published_time', $tags );
		$this->assertArrayNotHasKey( 'article:author', $tags );
		$this->assertArrayNotHasKey( 'article:tag', $tags );
	}

	public function test_aioseo_type_becomes_website_on_the_shop(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		$tags = $this->aioseo()->filter_facebook_tags( array( 'og:type' => 'article' ) );
		$this->assertSame( 'website', $tags['og:type'] );
	}

	public function test_aioseo_gains_the_commerce_properties(): void {
		$this->stub_product_page();
		$tags = $this->aioseo()->filter_facebook_tags( array( 'og:type' => 'article' ) );

		$this->assertSame( '48.00', $tags['product:price:amount'] );
		$this->assertSame( 'USD', $tags['product:price:currency'] );
		$this->assertSame( 'instock', $tags['product:availability'] );
		$this->assertSame( 'instock', $tags['og:availability'] );
	}

	public function test_aioseo_tags_are_untouched_off_a_commerce_page(): void {
		$this->on_commerce_page = false;
		$given                  = array( 'og:type' => 'article' );
		$this->assertSame( $given, $this->aioseo()->filter_facebook_tags( $given ) );
		$this->assertSame( $given, $this->aioseo()->filter_twitter_tags( $given ) );
	}

	public function test_aioseo_twitter_gains_the_label_rows_and_keeps_the_card(): void {
		$this->stub_product_page();
		$tags = $this->aioseo()->filter_twitter_tags(
			array(
				'twitter:card'  => 'summary_large_image',
				'twitter:title' => 'Canvas Belt',
			)
		);

		// The card is AIOSEO's: it manages its own image, so only it knows
		// whether a large card has anything to put in it (#683).
		$this->assertSame( 'summary_large_image', $tags['twitter:card'] );
		$this->assertSame( 'Price', $tags['twitter:label1'] );
		$this->assertSame( 'Availability', $tags['twitter:label2'] );
	}

	public function test_aioseo_ignores_a_non_array(): void {
		$this->assertNull( $this->aioseo()->filter_facebook_tags( null ) );
		$this->assertNull( $this->aioseo()->filter_twitter_tags( null ) );
	}

	// --- Rank Math ---

	public function test_rankmath_registers_a_filter_per_owned_property(): void {
		$this->stub_product_page();
		// One per property in WC_AI_Storefront_Og_Commerce_Facts::properties(),
		// named with ':' replaced by '_' — the slug rule confirmed live.
		Filters\expectAdded( 'rank_math/opengraph/facebook/product_price_amount' )->once();
		Filters\expectAdded( 'rank_math/opengraph/facebook/product_availability' )->once();
		Filters\expectAdded( 'rank_math/opengraph/facebook/og_availability' )->once();
		Filters\expectAdded( 'rank_math/opengraph/facebook/og_type' )->once();
		Filters\expectAdded( 'rank_math/opengraph/slack_enhanced_data' )->once();
		Actions\expectAdded( 'rank_math/opengraph/facebook' )->once();
		$this->rankmath();
	}

	public function test_rankmath_type_is_corrected(): void {
		$this->stub_product_page();
		$this->assertSame( 'product', $this->rankmath()->filter_type( 'article' ) );
	}

	public function test_rankmath_type_becomes_website_on_search(): void {
		Functions\when( 'is_search' )->justReturn( true );
		$this->assertSame( 'website', $this->rankmath()->filter_type( 'article' ) );
	}

	public function test_rankmath_substitutes_a_property_it_already_emits(): void {
		$this->stub_product_page();
		$strategy = $this->rankmath();

		$this->assertSame( 'instock', $strategy->filter_property( 'product:availability', 'whatever-rank-math-said' ) );
	}

	public function test_rankmath_does_not_add_back_what_it_substituted(): void {
		// Fact 2, measured: a tag added with $og->tag() goes through the same
		// per-tag filter, so adding a property we already substituted would
		// put it on the page twice.
		$this->stub_product_page();
		$strategy = $this->rankmath();

		$strategy->filter_property( 'product:availability', 'instock' );
		$og = new WC_AI_Storefront_Rankmath_Og_Double();
		$strategy->add_missing_tags( $og );

		$this->assertArrayNotHasKey( 'product:availability', $og->tags );
	}

	public function test_rankmath_adds_what_it_never_emitted(): void {
		// The variable-product price gap: no per-tag filter fires for it,
		// because Rank Math emits no such tag.
		$this->stub_product_page();
		$strategy = $this->rankmath();

		$og = new WC_AI_Storefront_Rankmath_Og_Double();
		$strategy->add_missing_tags( $og );

		$this->assertSame( '48.00', $og->tags['product:price:amount'] );
		$this->assertSame( 'USD', $og->tags['product:price:currency'] );
		$this->assertSame( 'instock', $og->tags['og:availability'] );
	}

	public function test_rankmath_adds_nothing_off_a_commerce_page(): void {
		$this->on_commerce_page = false;
		$strategy               = $this->rankmath();

		$og = new WC_AI_Storefront_Rankmath_Og_Double();
		$strategy->add_missing_tags( $og );

		$this->assertSame( array(), $og->tags );
	}

	public function test_rankmath_survives_an_object_without_tag(): void {
		$this->stub_product_page();
		$strategy = $this->rankmath();
		$strategy->add_missing_tags( new \stdClass() );
		$this->assertTrue( true, 'No fatal on an object shape we did not measure.' );
	}

	public function test_rankmath_fills_the_missing_twitter_row(): void {
		$this->stub_product_page();
		$data = $this->rankmath()->filter_slack_data( array( 'Availability' => 'In stock' ) );

		$this->assertSame( 'In stock', $data['Availability'], 'Theirs stays.' );
		$this->assertArrayHasKey( 'Price', $data );
	}

	public function test_rankmath_seen_state_resets_on_init(): void {
		// Request-scoped: #669 shipped a latch of this shape that survived
		// between requests in a persistent worker.
		$this->stub_product_page();
		$strategy = $this->rankmath();
		$strategy->filter_property( 'product:availability', 'instock' );

		$strategy->init( $this->gate() );

		$og = new WC_AI_Storefront_Rankmath_Og_Double();
		$strategy->add_missing_tags( $og );
		$this->assertArrayHasKey( 'product:availability', $og->tags );
	}
}
