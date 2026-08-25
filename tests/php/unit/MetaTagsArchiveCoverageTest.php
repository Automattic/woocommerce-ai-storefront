<?php
/**
 * Archive coverage shared by every metadata branch.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class MetaTagsArchiveCoverageTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Meta_Tags $meta;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'yes' );
		// apply_filters returns the value it was given (pass-through).
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// __() pass-through (returns the untranslated format string).
		Functions\when( '__' )->returnArg();
		// Default all commerce conditionals to false; tests opt in.
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_product_category' )->justReturn( false );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		// covered_term() reads is_tax() first; default false so suite
		// order cannot change a result (#705). Tests opt in.
		Functions\when( 'is_tax' )->justReturn( false );
		// build_archive_og_tags() computes og:locale unconditionally via
		// WC_AI_Storefront_Meta_Text::og_locale() before any branch runs;
		// default it so tests that don't care about locale need not stub it.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		// build_archive_og_tags() also runs archive_image() unconditionally
		// after every branch. Same defaults as MetaTagsTest.php's setUp(): a
		// test that doesn't care about the image resolver gets "nothing
		// found" from each source, whatever order the suite runs in.
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'wc_get_products' )->justReturn( array() );
		Functions\when( 'esc_url' )->returnArg();
		// archive_own_image() now reads get_term_meta() for every covered
		// term (category, tag, brand), not only category (#705); default to
		// "no thumbnail" so an OG/description test that never mentions the
		// image resolver does not have to know it exists.
		Functions\when( 'get_term_meta' )->justReturn( 0 );
		$this->meta = new WC_AI_Storefront_Meta_Tags();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a queried term double.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return object
	 */
	private function term( string $taxonomy ) {
		$term           = new stdClass();
		$term->term_id  = 7;
		$term->taxonomy = $taxonomy;
		$term->name     = 'Thornwick';
		$term->slug     = 'thornwick';
		return $term;
	}

	public function test_covered_term_returns_the_term_on_a_brand_archive(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_brand' ) );

		$term = $this->meta->covered_term();

		$this->assertNotNull( $term );
		$this->assertSame( 'product_brand', $term->taxonomy );
	}

	public function test_covered_term_returns_the_term_on_a_tag_archive(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_tag' ) );

		$this->assertSame( 'product_tag', $this->meta->covered_term()->taxonomy );
	}

	public function test_covered_term_is_null_on_an_attribute_archive(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'pa_color' ) );

		$this->assertNull( $this->meta->covered_term() );
	}

	public function test_covered_term_is_null_when_not_on_a_taxonomy_archive(): void {
		Functions\when( 'is_tax' )->justReturn( false );

		$this->assertNull( $this->meta->covered_term() );
	}

	public function test_brand_archive_description_falls_back_to_the_term_name(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( '__' )->returnArg();
		// clean_text() runs on every candidate, including the empty
		// description; strip_shortcodes() only removes registered
		// shortcodes, so identity mirrors a site with none (see MetaTextTest).
		Functions\when( 'strip_shortcodes' )->returnArg();

		$term              = $this->term( 'product_brand' );
		$term->description = '';
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$tags = new WC_AI_Storefront_Meta_Tags();

		$this->assertStringContainsString( 'Thornwick', $tags->build_archive_description() );
		$this->assertStringContainsString( 'Saltwarp', $tags->build_archive_description() );
	}

	public function test_tag_archive_description_prefers_the_term_description(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'strip_shortcodes' )->returnArg();

		$term              = $this->term( 'product_tag' );
		$term->description = 'Everything fleece.';
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$tags = new WC_AI_Storefront_Meta_Tags();

		$this->assertSame( 'Everything fleece.', $tags->build_archive_description() );
	}

	public function test_brand_archive_og_tags_carry_the_term_name_and_link(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'get_term_link' )->justReturn( 'https://saltwarp.shop/brand/thornwick/' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_brand' ) );

		$tags = new WC_AI_Storefront_Meta_Tags();
		$og   = $tags->build_archive_og_tags( 'A brand.' );

		$this->assertSame( 'Thornwick', $og['og:title'] );
		$this->assertSame( 'https://saltwarp.shop/brand/thornwick/', $og['og:url'] );
	}

	public function test_term_archive_og_url_is_empty_when_the_link_errors(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_tag' ) );
		// get_term_link() returns WP_Error on an unregistered taxonomy.
		Functions\when( 'get_term_link' )->justReturn( new WP_Error( 'invalid_taxonomy', 'nope' ) );
		// build_archive_og_tags() falls back to home_url() when og:url is
		// still empty; some other test file in this shared process stubs
		// home_url() too, which makes function_exists( 'home_url' ) true
		// here even without this line, so an unstubbed call would error
		// rather than silently no-op (see MetaTagsTest.php for the same
		// note). Stubbed to '' so the fallback cannot mask the assertion.
		Functions\when( 'home_url' )->justReturn( '' );

		$tags = new WC_AI_Storefront_Meta_Tags();
		$og   = $tags->build_archive_og_tags( 'A tag.' );

		$this->assertSame( '', $og['og:url'] );
	}

	public function test_brand_archive_uses_the_term_thumbnail_when_set(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_brand' ) );

		// Captures the term_id get_term_meta() is called with, rather than
		// just justReturn()-ing a value: proves term_id 7 (the brand's own
		// id) is what flowed out of covered_term(), not merely that some
		// value came back regardless of which id (or none) was asked for.
		$meta_term_ids = array();
		Functions\when( 'get_term_meta' )->alias(
			static function ( $term_id = 0, $key = '', $single = false ) use ( &$meta_term_ids ) {
				$meta_term_ids[] = $term_id;
				return 42;
			}
		);
		// setUp() defaults this to false ("no image"); overridden here with
		// real dimensions so a correct brand flow can actually surface a
		// non-empty url. Review round 1 (#705): assertIsArray() alone held
		// for any `: array` return, including the pre-fix no_image() default,
		// and this stub's prior `false` meant even a correct flow could never
		// produce a non-empty url to tell them apart.
		Functions\when( 'wp_get_attachment_image_src' )->justReturn(
			array( 'https://saltwarp.shop/brand/thornwick.jpg', 800, 600, false )
		);

		$tags = new WC_AI_Storefront_Meta_Tags();
		$ref  = new ReflectionMethod( $tags, 'archive_own_image' );
		$ref->setAccessible( true );
		$image = $ref->invoke( $tags );

		$this->assertSame( array( 7 ), $meta_term_ids );
		$this->assertSame( 'https://saltwarp.shop/brand/thornwick.jpg', $image['url'] );
		$this->assertSame( 800, $image['width'] );
	}

	public function test_tag_archive_falls_through_when_no_thumbnail_meta_exists(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_tag' ) );
		Functions\when( 'get_term_meta' )->justReturn( '' );

		$tags = new WC_AI_Storefront_Meta_Tags();
		$ref  = new ReflectionMethod( $tags, 'archive_own_image' );
		$ref->setAccessible( true );
		$image = $ref->invoke( $tags );

		$this->assertSame( '', $image['url'] );
	}

	public function test_tag_archive_product_image_query_is_filtered_by_the_tag_slug(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_tag' ) );

		// archive_own_image() itself does not discriminate here (see the
		// test above): a tag carries no thumbnail_id either way, so that
		// method's return is identical pre- and post-#705 (review round 1).
		// The actual regression was one level down: queried_category_slug()
		// (queried_term_slug()'s predecessor) gated on is_product_category(),
		// so a tag's slug was always '' and this query never filtered by it.
		// Captures every wc_get_products() call's args to assert on that.
		$calls = array();
		Functions\when( 'wc_get_products' )->alias(
			static function ( $args ) use ( &$calls ) {
				$calls[] = $args;
				return array();
			}
		);

		$tags = new WC_AI_Storefront_Meta_Tags();
		$ref  = new ReflectionMethod( $tags, 'archive_product_image' );
		$ref->setAccessible( true );
		$ref->invoke( $tags );

		$this->assertNotEmpty( $calls );
		// 'category' is wc_get_products()'s product_cat-only arg
		// (WC_Product_Data_Store_CPT::get_wp_query_args(), WC 11.0.1); a tag
		// slug under that key matches nothing. Caught on live-store
		// verification for #705: should_emit() opening the gate was the
		// first time this query ever ran for a real tag archive, and the
		// pre-fix assertion here (on 'category') had encoded the bug as the
		// expected result.
		$this->assertArrayNotHasKey( 'category', $calls[0] );
		$this->assertSame( array( 'thornwick' ), $calls[0]['tag'] );
	}

	public function test_brand_archive_product_image_query_is_filtered_by_a_tax_query(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_brand' ) );

		// product_brand has no native wc_get_products() arg (unlike
		// 'category' and 'tag'), so the brand's slug has to reach the query
		// as a raw tax_query clause instead (#705).
		$calls = array();
		Functions\when( 'wc_get_products' )->alias(
			static function ( $args ) use ( &$calls ) {
				$calls[] = $args;
				return array();
			}
		);

		$tags = new WC_AI_Storefront_Meta_Tags();
		$ref  = new ReflectionMethod( $tags, 'archive_product_image' );
		$ref->setAccessible( true );
		$ref->invoke( $tags );

		$this->assertNotEmpty( $calls );
		$this->assertArrayNotHasKey( 'category', $calls[0] );
		$this->assertArrayNotHasKey( 'tag', $calls[0] );
		$this->assertSame(
			array(
				array(
					'taxonomy' => 'product_brand',
					'field'    => 'slug',
					'terms'    => array( 'thornwick' ),
				),
			),
			$calls[0]['tax_query']
		);
	}

	public function test_category_archive_product_image_query_is_still_filtered_by_category(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( $this->term( 'product_cat' ) );

		// Regression guard: the category path already worked before #705
		// (it is the one taxonomy 'category' actually matches); this pins
		// that behaviour while term_query_constraint() adds the tag/brand
		// branches alongside it.
		$calls = array();
		Functions\when( 'wc_get_products' )->alias(
			static function ( $args ) use ( &$calls ) {
				$calls[] = $args;
				return array();
			}
		);

		$tags = new WC_AI_Storefront_Meta_Tags();
		$ref  = new ReflectionMethod( $tags, 'archive_product_image' );
		$ref->setAccessible( true );
		$ref->invoke( $tags );

		$this->assertNotEmpty( $calls );
		$this->assertSame( array( 'thornwick' ), $calls[0]['category'] );
	}
}
