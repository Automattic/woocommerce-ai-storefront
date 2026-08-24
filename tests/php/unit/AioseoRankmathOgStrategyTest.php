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
	private function stub_product_page( string $price = '48.00' ): void {
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
		$product->shouldReceive( 'get_price' )->andReturn( $price );
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

	// --- #690/#701: page-agnostic observation ---

	public function test_aioseo_latches_on_a_non_commerce_page_too(): void {
		// The PR's headline change, and it was pinned for Yoast only. With the
		// latch back below the commerce gate it sets only on commerce pages,
		// so is_emitting() is permanently false on posts and the non-commerce
		// fallback duplicates AIOSEO's whole Open Graph block (#701 review).
		$this->on_commerce_page = false;

		$strategy = $this->aioseo();
		$this->assertFalse( $strategy->is_emitting(), 'nothing yet' );

		$strategy->filter_facebook_tags( array( 'og:type' => 'article' ) );

		$this->assertTrue( $strategy->is_emitting(), 'AIOSEO rendered, on a post' );
		$this->assertFalse( $strategy->has_taken_over(), 'but it has not taken over a commerce page' );
	}

	public function test_rankmath_latches_on_a_non_commerce_page_too(): void {
		$this->on_commerce_page = false;

		$strategy = $this->rankmath();
		$this->assertFalse( $strategy->is_emitting() );

		$strategy->add_missing_tags( new WC_AI_Storefront_Rankmath_Og_Double() );

		$this->assertTrue( $strategy->is_emitting() );
		$this->assertFalse( $strategy->has_taken_over() );
	}

	public function test_aioseo_observation_does_not_survive_a_reset(): void {
		// The registry is built once per PROCESS, so on a persistent worker a
		// latch from one request would silence the fallback on every later one.
		$strategy = $this->aioseo();
		$strategy->filter_facebook_tags( array( 'og:type' => 'article' ) );
		$this->assertTrue( $strategy->is_emitting() );

		$strategy->reset_observation();

		$this->assertFalse( $strategy->is_emitting() );
	}

	public function test_rankmath_observation_does_not_survive_a_reset(): void {
		$strategy = $this->rankmath();
		$strategy->add_missing_tags( new WC_AI_Storefront_Rankmath_Og_Double() );
		$this->assertTrue( $strategy->is_emitting() );

		$strategy->reset_observation();

		$this->assertFalse( $strategy->is_emitting() );
	}

	public function test_rankmath_reset_clears_the_seen_list_as_well(): void {
		// $seen records which properties Rank Math emitted this request. A
		// stale entry makes add_missing_tags() SKIP a property Rank Math has
		// not emitted yet, so the page loses product:price:amount rather than
		// gaining a duplicate — a different failure shape from the latch, and
		// invisible without this (#701 review).
		$strategy = $this->rankmath();

		// Seeded directly: driving filter_property() to record needs a live
		// product behind the facts object, and the property under test here is
		// the reset, not the recording.
		$seen = new \ReflectionProperty( $strategy, 'seen' );
		$seen->setValue( $strategy, array( 'product:price:amount' => true ) );

		$strategy->reset_observation();

		$this->assertSame( array(), $seen->getValue( $strategy ), 'released with the latch' );
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

	public function test_the_twitter_rows_carry_both_facts_correctly_paired(): void {
		// Only the Price row was ever asserted, so dropping label2 from the
		// enumeration, or pairing label2 with data1, both survived — the
		// second silently puts the PRICE under the Availability heading.
		$this->stub_product_page();

		$this->assertSame(
			array(
				'Price'        => '$48.00',
				'Availability' => 'In stock',
			),
			( new WC_AI_Storefront_Og_Commerce_Facts() )->twitter_rows()
		);
	}

	public function test_the_twitter_tag_map_carries_labels_and_their_data(): void {
		// Narrowing this to `twitter:label` survived: AIOSEO would ship two
		// headings with no values beside them. Widening it to all `twitter:`
		// keys also survived, handing AIOSEO the image and title that #683
		// decided are its to own.
		$this->stub_product_page();

		$this->assertSame(
			array(
				'twitter:label1' => 'Price',
				'twitter:data1'  => '$48.00',
				'twitter:label2' => 'Availability',
				'twitter:data2'  => 'In stock',
			),
			( new WC_AI_Storefront_Og_Commerce_Facts() )->twitter_tags()
		);
	}

	public function test_a_blanked_property_is_not_claimed(): void {
		// A third party emptying a key through wc_ai_storefront_og_tags would
		// otherwise have us claim ownership, drop the rival's presenter for
		// it, and render nothing in its place.
		$this->stub_product_page();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_og_tags' === $hook && is_array( $value ) ) {
					$value['product:availability'] = '';
				}
				return $value;
			}
		);

		$this->assertArrayNotHasKey(
			'product:availability',
			( new WC_AI_Storefront_Og_Commerce_Facts() )->properties()
		);
	}

	// --- AIOSEO ---

	public function test_aioseo_does_not_take_over_a_product_category(): void {
		// AIOSEO emits no Open Graph there and neither filter fires, so
		// standing our own block down would leave the page with none.
		Functions\when( 'is_product_category' )->justReturn( true );
		$this->assertFalse( $this->aioseo()->has_taken_over() );
	}

	public function test_aioseo_takes_over_only_once_its_filter_has_run(): void {
		// Presence is not evidence. AIOSEO ships an Open Graph switch, and a
		// merchant who turns it off gets neither filter on any page type —
		// answering from page type alone would stand our block down against a
		// plugin publishing nothing, leaving the page bare (#676 review).
		Functions\when( 'is_shop' )->justReturn( true );
		$strategy = $this->aioseo();

		$this->assertFalse( $strategy->has_taken_over(), 'Nothing observed yet.' );

		$strategy->filter_facebook_tags( array( 'og:type' => 'article' ) );

		$this->assertTrue( $strategy->has_taken_over() );
	}

	public function test_aioseo_never_takes_over_a_product_category_by_observation(): void {
		// The hand-written page-type exception is gone: AIOSEO's isAllowed()
		// is an allowlist that excludes product categories, so its filters
		// never fire there and the latch simply stays false.
		Functions\when( 'is_product_category' )->justReturn( true );
		$strategy = $this->aioseo();

		$this->assertFalse( $strategy->has_taken_over() );
	}

	public function test_rankmath_takes_over_only_once_its_action_has_run(): void {
		// RANK_MATH_VERSION is defined at load, but Rank Math publishes
		// nothing until its setup wizard is finished.
		$this->stub_product_page();
		$strategy = $this->rankmath();

		$this->assertFalse( $strategy->has_taken_over(), 'Activated is not the same as emitting.' );

		$strategy->add_missing_tags( new WC_AI_Storefront_Rankmath_Og_Double() );

		$this->assertTrue( $strategy->has_taken_over() );
	}

	public function test_rankmath_does_not_take_over_when_the_object_shape_is_wrong(): void {
		// Rank Math changing or removing tag() is an integration break. We
		// added nothing, so we must not stand our own block down either.
		$this->stub_product_page();
		$strategy = $this->rankmath();

		$strategy->add_missing_tags( new \stdClass() );

		$this->assertFalse( $strategy->has_taken_over() );
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

	public function test_aioseo_hooks_after_its_own_pro_woocommerce_integration(): void {
		// Mockery::any() rather than ::type( 'array' ): the priority is the
		// contract, the callable shape is not, and pinning it breaks on a
		// first-class-callable refactor for no behavioural reason.
		Filters\expectAdded( 'aioseo_facebook_tags' )->once()->with( \Mockery::any(), 20 );
		Filters\expectAdded( 'aioseo_twitter_tags' )->once()->with( \Mockery::any(), 20 );
		$this->aioseo();
	}

	public function test_aioseo_latches_only_on_the_facebook_seam(): void {
		// AIOSEO gates Facebook and Twitter output separately, so its Twitter
		// filter running is no evidence its Open Graph did.
		Functions\when( 'is_shop' )->justReturn( true );
		$strategy = $this->aioseo();

		$strategy->filter_twitter_tags( array( 'twitter:card' => 'summary' ) );

		$this->assertFalse( $strategy->has_taken_over() );
	}

	public function test_aioseo_values_win_over_theirs(): void {
		// "Ours win outright" is the merge direction, and every other AIOSEO
		// test passes an input carrying only og:type, so reversing it survived.
		$this->stub_product_page();

		$tags = $this->aioseo()->filter_facebook_tags(
			array(
				'og:type'              => 'article',
				'product:price:amount' => '999.00',
			)
		);

		$this->assertSame( '48.00', $tags['product:price:amount'] );
	}

	public function test_aioseo_does_not_overwrite_a_label_slot_it_filled(): void {
		// With "additional data" on, AIOSEO writes twitter:label{n} itself.
		// An array_merge would silently replace its rows with ours.
		$this->stub_product_page();

		$tags = $this->aioseo()->filter_twitter_tags(
			array(
				'twitter:card'   => 'summary',
				'twitter:label1' => 'Est. reading time',
				'twitter:data1'  => '3 minutes',
			)
		);

		$this->assertSame( 'Est. reading time', $tags['twitter:label1'] );
		$this->assertSame( '3 minutes', $tags['twitter:data1'] );
		$this->assertSame( 'Availability', $tags['twitter:label2'], 'A free slot still gets ours.' );
	}

	public function test_a_free_products_price_survives_the_falsy_filters(): void {
		// Rank Math's tag() returns early on empty(), and AIOSEO array_filter()s
		// its map with no callback. A price of "0" is dropped by both, so a
		// genuinely free product would lose it silently — the #658 and #679
		// class of bug.
		$this->stub_product_page( '0' );

		$properties = ( new WC_AI_Storefront_Og_Commerce_Facts() )->properties();

		$this->assertSame( '0.00', $properties['product:price:amount'] );
		$this->assertNotEmpty( $properties['product:price:amount'] );
	}

	public function test_a_property_only_the_og_tags_filter_added_is_not_claimed(): void {
		// build_og_tags() ends in the public wc_ai_storefront_og_tags filter.
		// A third party adding product:brand passes a `product:` prefix test
		// and is absent from OWNED_PROPERTIES; claiming it would give Rank
		// Math no per-tag filter for it and duplicate the tag.
		$this->stub_product_page();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wc_ai_storefront_og_tags' === $hook && is_array( $value ) ) {
					$value['product:brand'] = 'Saltwarp';
				}
				return $value;
			}
		);

		$properties = ( new WC_AI_Storefront_Og_Commerce_Facts() )->properties();

		$this->assertArrayNotHasKey( 'product:brand', $properties );
	}

	// --- Rank Math ---

	public function test_rankmath_registers_a_filter_per_owned_property(): void {
		// Deliberately NO stub_product_page(). init() runs on plugins_loaded,
		// long before the query resolves, so is_product() is false and
		// properties() is empty — which is exactly why registration reads the
		// OWNED_PROPERTIES vocabulary instead. Stubbing a product first made
		// this test pass against a version that registered nothing in
		// production (#676 review).
		foreach ( WC_AI_Storefront_Og_Commerce_Facts::OWNED_PROPERTIES as $property ) {
			Filters\expectAdded( 'rank_math/opengraph/facebook/' . str_replace( ':', '_', $property ) )->once();
		}
		$this->rankmath();
	}

	public function test_rankmath_pins_every_hook_priority(): void {
		// The class docblock says lowering the action's 99 "does not degrade
		// gracefully: it silently doubles properties" — Rank Math's own
		// emitters are callbacks on that same action, the highest at 90.
		// Nothing pinned it, so every priority here was free to drift.
		Actions\expectAdded( 'rank_math/opengraph/facebook' )->once()->with( \Mockery::any(), 99 );
		Filters\expectAdded( 'rank_math/opengraph/facebook/og_type' )->once()->with( \Mockery::any(), 20 );
		Filters\expectAdded( 'rank_math/opengraph/slack_enhanced_data' )->once()->with( \Mockery::any(), 20 );
		$this->rankmath();
	}

	public function test_rankmath_latches_only_on_the_open_graph_action(): void {
		// Evidence from any other seam is not evidence the Open Graph
		// pipeline ran: Rank Math gates Facebook and Twitter output
		// separately. Latching on the wrong one stands our block down for a
		// page that got no Open Graph at all.
		$this->stub_product_page();
		$strategy = $this->rankmath();

		$strategy->filter_type( 'article' );
		$strategy->filter_property( 'product:availability', 'instock' );
		$strategy->filter_slack_data( array() );

		$this->assertFalse( $strategy->has_taken_over(), 'None of those is the Open Graph pipeline.' );
	}

	public function test_rankmath_fills_the_missing_twitter_row_without_repeating_theirs(): void {
		// Rank Math carries the same value-based dedupe as Yoast and had no
		// twin test, so a non-English store could get two rows for one fact.
		$this->stub_product_page();

		$data = $this->rankmath()->filter_slack_data( array( 'Verfügbarkeit' => 'In stock' ) );

		$this->assertArrayNotHasKey( 'Availability', $data );
		$this->assertArrayHasKey( 'Price', $data );
	}

	public function test_rankmath_survives_a_non_object_on_its_action(): void {
		// do_action() with no argument passes ''. method_exists( '', 'tag' )
		// is a TypeError on PHP 8, mid-wp_head.
		$this->stub_product_page();
		$this->rankmath()->add_missing_tags( '' );
		$this->assertTrue( true, 'No fatal.' );
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
