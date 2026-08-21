<?php
/**
 * Tests for WC_AI_Storefront_Meta_Tags.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class MetaTagsTest extends \PHPUnit\Framework\TestCase {
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
		// Defaults for tags added in the Jetpack-coexistence work; falsy so
		// existing assertions are unaffected. Tests opt in to real values.
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		// Shared with AuthoredSeoTest; defined in tests/php/stubs-jetpack.php.
		wc_ai_storefront_reset_jetpack_seo_doubles();
		// Shared with RivalSeoDescriptionTest; the suite runs every file in
		// one process (phpunit.xml.dist sets no processIsolation), so this
		// per-request static would otherwise leak between test files (#669
		// task 2).
		WC_AI_Storefront_Rival_Seo_Description::reset();
		$this->meta = new WC_AI_Storefront_Meta_Tags();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		wc_ai_storefront_reset_jetpack_seo_doubles();
		WC_AI_Storefront_Rival_Seo_Description::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_should_emit_true_on_product_when_enabled(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertTrue( $this->meta->should_emit() );
	}

	public function test_should_emit_false_when_syndication_disabled(): void {
		WC_AI_Storefront::$test_settings = array( 'enabled' => 'no' );
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertFalse( $this->meta->should_emit() );
	}

	public function test_should_emit_false_on_non_commerce_page(): void {
		// All conditionals default false in setUp().
		$this->assertFalse( $this->meta->should_emit() );
	}

	public function test_should_emit_false_when_master_filter_off(): void {
		Functions\when( 'is_product' )->justReturn( true );
		// Override apply_filters so the master toggle returns false.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wc_ai_storefront_emit_meta_tags' === $hook ? false : $value;
			}
		);
		$this->assertFalse( $this->meta->should_emit() );
	}

	public function test_should_emit_true_on_category(): void {
		Functions\when( 'is_product_category' )->justReturn( true );
		$this->assertTrue( $this->meta->should_emit() );
	}

	public function test_should_emit_true_on_shop(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		$this->assertTrue( $this->meta->should_emit() );
	}

	public function test_should_emit_true_on_product_search(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 'product' );
		$this->assertTrue( $this->meta->should_emit() );
	}

	public function test_should_emit_false_for_non_product_search(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( '' );
		$this->assertFalse( $this->meta->should_emit() );
	}

	private function make_product( array $overrides = array() ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		// build_description() keys its authored lookup on the product it was
		// handed, so every product mock needs an ID (#668 review).
		$product->shouldReceive( 'get_id' )->andReturn( $overrides['id'] ?? 42 );
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? '' );
		$product->shouldReceive( 'get_description' )->andReturn( $overrides['long'] ?? '' );
		$product->shouldReceive( 'get_name' )->andReturn( $overrides['name'] ?? 'Test Product' );
		return $product;
	}

	private function og_product( array $overrides = array() ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $overrides['id'] ?? 42 );
		$product->shouldReceive( 'get_name' )->andReturn( $overrides['name'] ?? 'Canvas Belt' );
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? 'A belt.' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'is_purchasable' )->andReturn( $overrides['purchasable'] ?? true );
		$product->shouldReceive( 'get_price' )->andReturn( $overrides['price'] ?? '48.00' );
		// Stock/Condition facts (#679 task 2), read by build_og_tags() via
		// WC_AI_Storefront_Product_Facts. Default to a plain in-stock,
		// condition-less product so every OG test predating this task keeps
		// its existing behaviour unchanged; pass 'in_stock' / 'stock_status'
		// / 'condition' overrides to exercise the other branches.
		$product->shouldReceive( 'is_in_stock' )->andReturn( $overrides['in_stock'] ?? true );
		$product->shouldReceive( 'get_stock_status' )->andReturn( $overrides['stock_status'] ?? 'instock' );
		if ( isset( $overrides['condition'] ) ) {
			// Mirrors ProductFactsTest::make_product_with_attributes() for
			// the single pa_condition case OG/Twitter tests need.
			$attr = \Mockery::mock();
			$attr->shouldReceive( 'get_visible' )->andReturn( true );
			$attr->shouldReceive( 'get_name' )->andReturn( 'pa_condition' );
			$product->shouldReceive( 'get_attributes' )->andReturn( array( 'pa_condition' => $attr ) );
			$product->shouldReceive( 'get_attribute' )->andReturn( $overrides['condition'] );
			$product->shouldReceive( 'get_variation_attributes' )->andReturn( array() );
		} else {
			$product->shouldReceive( 'get_attributes' )->andReturn( array() );
		}
		return $product;
	}

	public function test_description_prefers_short_description(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		$p = $this->make_product(
			array(
				'short' => 'A tight short blurb.',
				'long'  => 'Long body.',
			)
		);
		$this->assertSame( 'A tight short blurb.', $this->meta->build_description( $p ) );
	}

	public function test_description_falls_back_to_long_when_short_blank(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		$p = $this->make_product(
			array(
				'short' => '   ',
				'long'  => 'The long description.',
			)
		);
		$this->assertSame( 'The long description.', $this->meta->build_description( $p ) );
	}


	public function test_description_strips_html_and_shortcodes_and_collapses_whitespace(): void {
		Functions\when( 'strip_shortcodes' )->alias(
			static fn( $s ) => preg_replace( '/\[[^\]]*\]/', '', $s )
		);
		$p = $this->make_product( array( 'short' => "<p>Fine   leather</p>\n[sale] belt</p>" ) );
		$this->assertSame( 'Fine leather belt', $this->meta->build_description( $p ) );
	}

	public function test_description_truncates_on_word_boundary_with_ellipsis(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		$long = str_repeat( 'word ', 60 ); // 300 chars of "word "
		$p    = $this->make_product( array( 'short' => trim( $long ) ) );
		$out  = $this->meta->build_description( $p );
		$this->assertLessThanOrEqual( 156, mb_strlen( $out ) ); // 155 + ellipsis
		$this->assertStringEndsWith( '…', $out );
		$this->assertStringNotContainsString( 'wor…', $out ); // cut on a space, not mid-word
	}

	public function test_archive_description_from_category_term(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'is_product_category' )->justReturn( true );
		// Every candidate is built up front now, including the generated
		// "Shop {category} at {store}" fallback this test never reaches.
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 9,
				'name'        => 'Belts',
				'description' => 'All our leather belts.',
			)
		);
		$this->assertSame( 'All our leather belts.', $this->meta->build_archive_description() );
	}

	public function test_archive_description_shop_uses_page_content_then_tagline(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' ); // empty shop page content
		Functions\when( 'get_bloginfo' )->justReturn( 'Fine leather goods, made to last.' );
		$this->assertSame( 'Fine leather goods, made to last.', $this->meta->build_archive_description() );
	}

	public function test_archive_description_shop_prefers_page_content(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		// wp_strip_all_tags is a real stub (identity for plain text); see stub_escapers().
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( 'Curated leather goods.' );
		Functions\when( 'get_bloginfo' )->justReturn( 'tagline should not win' );
		$this->assertSame( 'Curated leather goods.', $this->meta->build_archive_description() );
	}

	public function test_title_parts_appends_brand_when_distinct(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Field Boot' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'Thornwick' ) )
		);
		$parts = $this->meta->filter_title_parts(
			array(
				'title' => 'Old',
				'site'  => 'Saltwarp',
			)
		);
		$this->assertSame( 'Field Boot | Thornwick', $parts['title'] );
		$this->assertSame( 'Saltwarp', $parts['site'] );
	}

	public function test_title_parts_skips_brand_when_brand_equals_site(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Camp Shirt' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'Saltwarp' ) )
		);
		$parts = $this->meta->filter_title_parts(
			array(
				'title' => 'Old',
				'site'  => 'Saltwarp',
			)
		);
		$this->assertSame( 'Camp Shirt', $parts['title'] );
	}

	public function test_title_parts_skips_brand_when_name_contains_brand(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Saltwarp x Thornwick Tote' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'Thornwick' ) )
		);
		$parts = $this->meta->filter_title_parts(
			array(
				'title' => 'Old',
				'site'  => 'Saltwarp',
			)
		);
		$this->assertSame( 'Saltwarp x Thornwick Tote', $parts['title'] );
	}

	public function test_title_parts_brand_redundancy_is_case_insensitive(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Camp Shirt' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'saltwarp' ) ) // lower-case brand
		);
		$parts = $this->meta->filter_title_parts(
			array(
				'title' => 'Old',
				'site'  => 'Saltwarp',
			)
		);
		$this->assertSame( 'Camp Shirt', $parts['title'] );
	}

	public function test_title_parts_skips_brand_when_site_from_bloginfo_fallback(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Camp Shirt' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'Saltwarp' ) )
		);
		// No 'site' key — triggers the get_bloginfo() fallback path.
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Old' ) );
		$this->assertSame( 'Camp Shirt', $parts['title'] );
		$this->assertStringNotContainsString( '| Saltwarp', $parts['title'] );
	}

	public function test_title_parts_skips_brand_when_name_starts_with_brand(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Saltwarp Tote' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'Saltwarp' ) )
		);
		// Distinct site so this exercises the substring path, not the brand==site path.
		$parts = $this->meta->filter_title_parts(
			array(
				'title' => 'Old',
				'site'  => 'Different Store',
			)
		);
		$this->assertSame( 'Saltwarp Tote', $parts['title'] );
		$this->assertStringNotContainsString( '| Saltwarp', $parts['title'] );
	}

	public function test_title_parts_no_brand_when_absent(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn( false ); // no brand terms
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Old' ) );
		$this->assertSame( 'Canvas Belt', $parts['title'] );
	}

	public function test_title_parts_untouched_on_non_product(): void {
		// is_product() false (default). Category/shop titles stay core's.
		Functions\when( 'is_product_category' )->justReturn( true );
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Accessories' ) );
		$this->assertSame( 'Accessories', $parts['title'] );
	}

	public function test_title_parts_untouched_when_product_lookup_fails(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( false );
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Untouched' ) );
		$this->assertSame( 'Untouched', $parts['title'] );
	}

	public function test_og_tags_core_fields_and_price(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/canvas-belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://shop.test/img/belt.jpg', 800, 600, false ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$og = $this->meta->build_og_tags( $this->og_product() );
		$this->assertSame( 'product', $og['og:type'] );
		$this->assertSame( 'Canvas Belt', $og['og:title'] );
		$this->assertSame( 'A belt.', $og['og:description'] );
		$this->assertSame( 'https://shop.test/product/canvas-belt/', $og['og:url'] );
		$this->assertSame( 'Saltwarp', $og['og:site_name'] );
		$this->assertSame( 'https://shop.test/img/belt.jpg', $og['og:image'] );
		$this->assertSame( '48.00', $og['product:price:amount'] );
		$this->assertSame( 'USD', $og['product:price:currency'] );
	}

	public function test_og_tags_omit_image_when_absent_and_price_when_not_purchasable(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 ); // no image
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$og = $this->meta->build_og_tags( $this->og_product( array( 'purchasable' => false ) ) );
		$this->assertArrayNotHasKey( 'og:image', $og );
		$this->assertArrayNotHasKey( 'og:image:width', $og );
		$this->assertArrayNotHasKey( 'og:image:height', $og );
		$this->assertArrayNotHasKey( 'og:image:alt', $og );
		$this->assertArrayNotHasKey( 'product:price:amount', $og );

		// Gated the same way as product:price:amount itself (#679 task 2).
		$tw = $this->meta->build_twitter_tags( $og );
		$this->assertArrayNotHasKey( 'twitter:label1', $tw );
		$this->assertArrayNotHasKey( 'twitter:data1', $tw );
		$this->assertArrayNotHasKey( 'twitter:image:alt', $tw );
	}

	public function test_twitter_price_pair_omitted_when_purchasable_but_unpriced(): void {
		// The second way product:price:amount ends up unset: purchasable,
		// but get_price() is ''. Distinct from the not-purchasable branch
		// above; both must gate the Twitter pair.
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$og = $this->meta->build_og_tags(
			$this->og_product(
				array(
					'purchasable' => true,
					'price'       => '',
				)
			)
		);
		$this->assertArrayNotHasKey( 'product:price:amount', $og );

		$tw = $this->meta->build_twitter_tags( $og );
		$this->assertArrayNotHasKey( 'twitter:label1', $tw );
		$this->assertArrayNotHasKey( 'twitter:data1', $tw );
	}

	// --- Image dimensions and alt text (#679 task 2) ---

	public function test_og_tags_include_image_dimensions_and_alt(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/canvas-belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://shop.test/img/belt.jpg', 800, 600, false ) );
		Functions\when( 'get_post_meta' )->justReturn( 'A canvas belt on a wooden table.' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$og = $this->meta->build_og_tags( $this->og_product() );
		$this->assertSame( '800', $og['og:image:width'] );
		$this->assertSame( '600', $og['og:image:height'] );
		$this->assertSame( 'A canvas belt on a wooden table.', $og['og:image:alt'] );

		$tw = $this->meta->build_twitter_tags( $og );
		$this->assertSame( 'A canvas belt on a wooden table.', $tw['twitter:image:alt'] );
	}

	public function test_og_tags_omit_alt_when_attachment_has_no_alt_text(): void {
		// Mutation check target: dropping the alt-empty guard must make
		// this test fail (#679 task 2 brief, Step 5).
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://shop.test/img/belt.jpg', 800, 600, false ) );
		Functions\when( 'get_post_meta' )->justReturn( '' ); // no alt text set on the attachment
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$og = $this->meta->build_og_tags( $this->og_product() );
		$this->assertSame( '800', $og['og:image:width'] );
		$this->assertSame( '600', $og['og:image:height'] );
		$this->assertArrayNotHasKey( 'og:image:alt', $og );

		$tw = $this->meta->build_twitter_tags( $og );
		$this->assertArrayNotHasKey( 'twitter:image:alt', $tw );
	}

	// --- Availability vocabulary (#679 task 2) ---

	public function test_og_tags_availability_instock(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$og = $this->meta->build_og_tags(
			$this->og_product(
				array(
					'in_stock'     => true,
					'stock_status' => 'instock',
				)
			)
		);
		$this->assertSame( 'instock', $og['product:availability'] );
		$this->assertSame( 'instock', $og['og:availability'] );
	}

	public function test_og_tags_availability_diverges_on_backorder(): void {
		// The one case the two vocabularies disagree, and the one most
		// likely to be got wrong (#679 task 2 brief). Facebook's
		// product:availability has no "backorder" term of its own — a
		// backordered product reads "available for order" there — while
		// Pinterest's og:availability does have a distinct "backorder"
		// term. Mutation check target: swapping the two vocabularies must
		// make this test fail.
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$og = $this->meta->build_og_tags(
			$this->og_product(
				array(
					'in_stock'     => true,
					'stock_status' => 'onbackorder',
				)
			)
		);
		$this->assertSame( 'available for order', $og['product:availability'] );
		$this->assertSame( 'backorder', $og['og:availability'] );
	}

	public function test_og_tags_availability_out_of_stock(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$og = $this->meta->build_og_tags( $this->og_product( array( 'in_stock' => false ) ) );
		$this->assertSame( 'out of stock', $og['product:availability'] );
		$this->assertSame( 'out of stock', $og['og:availability'] );
	}

	// --- Condition (#679 task 2) ---

	public function test_og_tags_include_condition_when_attribute_present(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$og = $this->meta->build_og_tags( $this->og_product( array( 'condition' => 'new' ) ) );
		$this->assertSame( 'new', $og['product:condition'] );
	}

	public function test_og_tags_omit_condition_when_attribute_absent(): void {
		// Mutation check target: removing the product:condition key must
		// make this test fail (#679 task 2 brief, Step 5).
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/product/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$og = $this->meta->build_og_tags( $this->og_product() ); // no 'condition' override
		$this->assertArrayNotHasKey( 'product:condition', $og );
	}

	// --- Twitter label/data pairs (#679 task 2) ---

	public function test_twitter_tags_include_price_and_availability_labels(): void {
		$tw = $this->meta->build_twitter_tags(
			array(
				'og:title'               => 'Canvas Belt',
				'og:description'         => 'A belt.',
				'product:price:amount'   => '48.00',
				'product:price:currency' => 'USD',
				'product:availability'   => 'instock',
			)
		);
		$this->assertSame( 'Price', $tw['twitter:label1'] );
		$this->assertSame( 'USD 48.00', $tw['twitter:data1'] );
		$this->assertSame( 'Availability', $tw['twitter:label2'] );
		$this->assertSame( 'instock', $tw['twitter:data2'] );
	}

	public function test_twitter_tags_derive_from_og(): void {
		$tw = $this->meta->build_twitter_tags(
			array(
				'og:title'       => 'Canvas Belt',
				'og:description' => 'A belt.',
				'og:image'       => 'https://shop.test/img/belt.jpg',
			)
		);
		$this->assertSame( 'summary_large_image', $tw['twitter:card'] );
		$this->assertSame( 'Canvas Belt', $tw['twitter:title'] );
		$this->assertSame( 'A belt.', $tw['twitter:description'] );
		$this->assertSame( 'https://shop.test/img/belt.jpg', $tw['twitter:image'] );
	}

	public function test_twitter_tags_omit_image_when_og_image_absent(): void {
		$tw = $this->meta->build_twitter_tags(
			array(
				'og:title'       => 'X',
				'og:description' => 'Y',
			)
		);
		$this->assertArrayNotHasKey( 'twitter:image', $tw );
	}

	public function test_archive_og_tags_for_category(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 9,
				'name'        => 'Belts',
				'description' => 'Leather belts.',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/belts/' );
		Functions\when( 'get_term_meta' )->justReturn( 0 ); // no thumbnail
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'website', $og['og:type'] );
		$this->assertSame( 'Belts', $og['og:title'] );
		$this->assertSame( 'Leather belts.', $og['og:description'] );
		$this->assertSame( 'https://shop.test/product-category/belts/', $og['og:url'] );
		$this->assertSame( 'Saltwarp', $og['og:site_name'] );
		$this->assertArrayNotHasKey( 'og:image', $og );
		$this->assertArrayNotHasKey( 'product:price:amount', $og );
	}

	public function test_archive_og_tags_for_category_with_thumbnail(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		// wp_strip_all_tags is a real stub (identity for plain text); see stub_escapers().
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 9,
				'name'        => 'Belts',
				'description' => 'Leather belts.',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/belts/' );
		Functions\when( 'get_term_meta' )->justReturn( 77 );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://shop.test/cat.jpg' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/cat.jpg', $og['og:image'] );
	}

	public function test_archive_og_tags_for_shop(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		// wp_strip_all_tags is a real stub (identity for plain text); see stub_escapers().
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'website', $og['og:type'] );
		$this->assertSame( 'Shop', $og['og:title'] );
		$this->assertSame( 'https://shop.test/shop/', $og['og:url'] );
		$this->assertArrayNotHasKey( 'product:price:amount', $og );
	}

	public function test_noindex_true_for_hidden_product(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'hidden' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->assertTrue( $this->meta->should_noindex() );
	}

	public function test_noindex_false_for_visible_product(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->assertFalse( $this->meta->should_noindex() );
	}

	public function test_noindex_true_for_search(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 'product' );
		$this->assertTrue( $this->meta->should_noindex() );
	}

	public function test_noindex_false_for_non_product_search(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( '' );
		$this->assertFalse( $this->meta->should_noindex() );
	}

	private function stub_escapers(): void {
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		// Identity by default so existing assertions can match exact
		// strings; test_authored_shop_title_is_escaped_against_markup_injection()
		// overrides this with a real htmlspecialchars() alias to prove
		// filter_document_title() actually calls it.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'strip_shortcodes' )->returnArg();
		// wp_strip_all_tags is a real function defined in tests/php/stubs.php
		// (before Patchwork), so it cannot be redefined via Brain Monkey.
		// The real stub already behaves as identity for plain-text inputs.
	}

	public function test_render_noop_when_should_not_emit(): void {
		// Non-commerce page (all conditionals false in setUp).
		ob_start();
		$this->meta->render_head_tags();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_emits_description_og_and_twitter_for_product(): void {
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://shop.test/i.jpg', 800, 600, false ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$product = $this->og_product();
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="A belt."', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Canvas Belt"', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image"', $html );
		$this->assertStringContainsString( '<meta name="twitter:image" content="https://shop.test/i.jpg"', $html );
		// End-to-end confirmation (#679 task 2) that the new properties
		// reach the actual printed <meta> output, not just the arrays
		// build_og_tags()/build_twitter_tags() return.
		$this->assertStringContainsString( '<meta property="product:availability" content="instock"', $html );
		$this->assertStringContainsString( '<meta property="og:availability" content="instock"', $html );
		$this->assertStringNotContainsString( 'noindex', $html );
	}

	public function test_render_emits_noindex_for_hidden_product(): void {
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$product = $this->og_product();
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'hidden' );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="robots" content="noindex,follow"', $html );
	}

	public function test_render_emits_archive_metadata_for_category(): void {
		$this->stub_escapers();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 9,
				'name'        => 'Belts',
				'description' => 'Leather belts.',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/belts/' );
		Functions\when( 'get_term_meta' )->justReturn( 0 );

		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="Leather belts."', $html );
		$this->assertStringContainsString( '<meta property="og:type" content="website"', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="Belts"', $html );
		$this->assertStringNotContainsString( 'product:price:amount', $html );
	}

	public function test_render_emits_fallback_description_for_descriptionless_product(): void {
		// A product with no short/long description still emits a non-empty
		// description (and og:/twitter: description) via the name fallback (#537),
		// because we suppress Jetpack's on commerce pages.
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$product = $this->og_product(
			array(
				'short' => '',
				'long'  => '',
			)
		);
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<meta name="description" content="Canvas Belt at Saltwarp."', $html );
		$this->assertStringContainsString( 'og:description', $html );
		$this->assertStringContainsString( 'twitter:description', $html );
	}

	public function test_render_emits_noindex_only_for_product_search(): void {
		$this->stub_escapers();
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 'product' );
		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<meta name="robots" content="noindex,follow"', $html );
		$this->assertStringNotContainsString( 'og:', $html );
		$this->assertStringNotContainsString( 'name="description"', $html );
	}

	// --- Task 1: og:locale (#527) ---

	public function test_og_tags_include_locale(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		$product = $this->og_product(
			array(
				'id'          => 10,
				'purchasable' => false,
			)
		);
		$og      = $this->meta->build_og_tags( $product );
		$this->assertSame( 'en_US', $og['og:locale'] );
	}

	public function test_archive_og_tags_include_locale(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'en_US', $og['og:locale'] );
	}

	public function test_og_locale_normalizes_wp_variant(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_locale' )->justReturn( 'de_DE_formal' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'de_DE', $og['og:locale'] );
	}

	// --- Task 2: archive og:image fallback (#527) ---

	public function test_archive_og_image_falls_back_to_site_logo(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_theme_mod' )->justReturn( 7 );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://shop.test/logo.png' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/logo.png', $og['og:image'] );
	}

	public function test_archive_og_image_falls_back_to_site_icon(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( 'https://shop.test/icon.png' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/icon.png', $og['og:image'] );
	}

	public function test_archive_og_image_prefers_configured_default(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		// Override the pass-through apply_filters for the default-image hook only.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wc_ai_storefront_og_default_image' === $hook ? 'https://shop.test/branded-og.png' : $value;
			}
		);
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/branded-og.png', $og['og:image'] );
	}

	// --- Task 3: front-page brand og:title (#527) ---

	public function test_archive_og_title_is_brand_when_shop_is_front_page(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'Saltwarp', $og['og:title'] );
	}

	// --- Task 4: Jetpack suppression (#527) ---

	public function test_suppress_jetpack_description_drops_only_description_on_commerce(): void {
		Functions\when( 'is_product' )->justReturn( true );
		// `robots` is the real co-key Jetpack puts in this map (noindex posts);
		// it must survive while only `description` is dropped.
		$out = $this->meta->suppress_jetpack_description(
			array(
				'description' => 'dup',
				'robots'      => 'noindex',
			)
		);
		$this->assertArrayNotHasKey( 'description', $out );
		$this->assertSame( 'noindex', $out['robots'] );
	}

	public function test_suppress_jetpack_description_noop_off_commerce(): void {
		// All commerce conditionals default false in setUp().
		$in = array( 'description' => 'keep' );
		$this->assertSame( $in, $this->meta->suppress_jetpack_description( $in ) );
	}

	public function test_suppress_jetpack_description_passes_non_array(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$this->assertNull( $this->meta->suppress_jetpack_description( null ) );
	}

	public function test_suppress_jetpack_open_graph_removes_action_on_commerce(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'has_action' )->justReturn( 10 );
		Functions\expect( 'remove_action' )->once()->with( 'wp_head', 'jetpack_og_tags' );
		$this->meta->suppress_jetpack_open_graph();
	}

	public function test_suppress_jetpack_open_graph_noop_off_commerce(): void {
		// Not a commerce page (setUp defaults). remove_action must never fire.
		Functions\when( 'has_action' )->justReturn( 10 );
		Functions\expect( 'remove_action' )->never();
		$this->meta->suppress_jetpack_open_graph();
	}

	// --- Review follow-ups (#529): edge-case hardening ---

	public function test_og_locale_defaults_to_en_us_when_locale_empty(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_locale' )->justReturn( '' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'en_US', $og['og:locale'] );
	}

	public function test_og_locale_normalizes_hyphen_form(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		Functions\when( 'get_locale' )->justReturn( 'pt-BR' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'pt_BR', $og['og:locale'] );
	}

	public function test_archive_og_image_skips_broken_logo_and_uses_icon(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		// Logo ID set, but the attachment URL is missing → fall through to icon.
		Functions\when( 'get_theme_mod' )->justReturn( 7 );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( 'https://shop.test/icon.png' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertSame( 'https://shop.test/icon.png', $og['og:image'] );
	}

	public function test_archive_og_image_omitted_when_no_default_available(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'wc_get_page_id' )->justReturn( 5 );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_title' )->justReturn( 'Shop' );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
		// No configured default (setUp pass-through), no logo, no icon.
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		$og = $this->meta->build_archive_og_tags();
		$this->assertArrayNotHasKey( 'og:image', $og );
	}

	// --- Description fallbacks: never emit an empty description while we
	// suppress Jetpack's (#537). ---

	public function test_archive_description_category_falls_back_when_term_has_no_description(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 9,
				'name'        => 'Belts',
				'description' => '',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		$desc = $this->meta->build_archive_description();
		$this->assertNotSame( '', $desc );
		$this->assertStringContainsString( 'Belts', $desc );
		$this->assertStringContainsString( 'Saltwarp', $desc );
	}

	public function test_description_product_falls_back_to_name_when_blank(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_short_description' )->andReturn( '' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$desc = $this->meta->build_description( $product );
		$this->assertNotSame( '', $desc );
		$this->assertStringContainsString( 'Canvas Belt', $desc );
		$this->assertStringContainsString( 'Saltwarp', $desc );
	}

	// ------------------------------------------------------------------
	// Authored SEO metadata wins (#668)
	// ------------------------------------------------------------------

	/**
	 * Stub the page-type conditionals plus whatever WP/WC functions
	 * render_head_tags() needs to complete without error for that page
	 * type, so callers only add the specifics a given test cares about
	 * (e.g. set_shop_page_id(), set_authored_description()).
	 *
	 * @param string $type    'product' | 'shop' | 'product_category', or any
	 *                        other string for a non-commerce page: every
	 *                        page-type conditional then stubs false, which is
	 *                        how tests assert we stand down off commerce pages
	 *                        (see the 'blog_post' caller).
	 * @param int    $post_id Queried product ID. Only meaningful for
	 *                        'product'; the Shop page ID is set separately
	 *                        via set_shop_page_id().
	 */
	private function fake_page( string $type, int $post_id = 0 ): void {
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( 'product' === $type );
		Functions\when( 'is_shop' )->justReturn( 'shop' === $type );
		Functions\when( 'is_product_category' )->justReturn( 'product_category' === $type );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		// Not paginated by default; filter_document_title() reads this on
		// every page type it handles to decide whether to append a page
		// suffix. Tests exercising pagination override it after calling
		// fake_page().
		Functions\when( 'get_query_var' )->justReturn( 0 );

		if ( 'product' === $type ) {
			Functions\when( 'get_queried_object_id' )->justReturn( $post_id );
			// Same ID as the queried object: build_description() keys its
			// authored lookup on the product it is handed (#668 review).
			$product = $this->og_product(
				array(
					'id'   => $post_id,
					'name' => 'Storm Jacket',
				)
			);
			$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
			Functions\when( 'wc_get_product' )->justReturn( $product );
			Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/x/' );
			Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		} elseif ( 'shop' === $type ) {
			// No Shop page by default; set_shop_page_id() overrides this.
			Functions\when( 'wc_get_page_id' )->justReturn( 0 );
			Functions\when( 'get_post_field' )->justReturn( '' );
			Functions\when( 'get_the_title' )->justReturn( 'Shop' );
			Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/shop/' );
			// build_archive_og_tags() falls back to home_url() for og:url when
			// shop_id is 0 (no Shop page resolved); some other test file in
			// this shared process stubs home_url() too, which makes
			// function_exists( 'home_url' ) true here even without this line,
			// so an unstubbed call would error rather than silently no-op.
			Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		} elseif ( 'product_category' === $type ) {
			// Stubbed so a regression that read authored post meta keyed on
			// the term's ID would produce a wrong description rather than an
			// error — the assertion has to be what fails, not the stub.
			Functions\when( 'get_queried_object_id' )->justReturn( 9 );
			Functions\when( 'get_queried_object' )->justReturn(
				(object) array(
					'term_id'     => 9,
					'name'        => 'Belts',
					'description' => 'Leather belts.',
				)
			);
			Functions\when( 'get_term_link' )->justReturn( 'https://shop.test/product-category/belts/' );
			Functions\when( 'get_term_meta' )->justReturn( 0 );
		}
	}

	/**
	 * Make wc_get_page_id( 'shop' ) resolve to the given Shop page post ID.
	 */
	private function set_shop_page_id( int $id ): void {
		Functions\when( 'wc_get_page_id' )->justReturn( $id );
	}

	/**
	 * Make WC_AI_Storefront_Authored_SEO::post_description() answer
	 * $description for $post_id specifically, as it would when Jetpack SEO
	 * Tools carries authored copy for that post. Activates the seo-tools
	 * module so is_available() is true. The double keys its stored value by
	 * $post_id, so a test can tell a correct implementation (reads the right
	 * post) from one that reads the wrong one.
	 *
	 * @param int    $post_id     Post the description belongs to.
	 * @param string $description Authored description, '' for "none set".
	 */
	private function set_authored_description( int $post_id, string $description ): void {
		Jetpack::$active_modules                     = array( 'seo-tools' );
		Jetpack_SEO_Posts::$descriptions[ $post_id ] = $description;
	}

	/**
	 * Make WC_AI_Storefront_Authored_SEO::front_page_description() answer
	 * $description, as it would when the merchant filled in Jetpack's
	 * site-wide front-page meta description. Activates the seo-tools module
	 * so is_available() is true.
	 */
	private function set_front_page_description( string $description ): void {
		Jetpack::$active_modules                   = array( 'seo-tools' );
		Jetpack_SEO_Utils::$front_page_description = $description;
	}

	/**
	 * Make WC_AI_Storefront_Authored_SEO::post_title() answer $title for
	 * $post_id specifically, as it would when Jetpack SEO Tools carries an
	 * authored HTML title for that post. Activates the seo-tools module so
	 * is_available() is true. The double keys its stored value by $post_id,
	 * so a test can tell a correct implementation (reads the right post)
	 * from one that reads the wrong one.
	 *
	 * @param int    $post_id Post the title belongs to.
	 * @param string $title   Authored HTML title, '' for "none set".
	 */
	private function set_authored_title( int $post_id, string $title ): void {
		Jetpack::$active_modules               = array( 'seo-tools' );
		Jetpack_SEO_Posts::$titles[ $post_id ] = $title;
	}

	/**
	 * Make get_the_terms() answer a single `product_brand` term named
	 * $brand for the current product. No post ID is needed: the production
	 * brand lookup does not key off one — get_brand_name() stubs
	 * get_the_terms() unconditionally.
	 *
	 * @param string $brand Brand name.
	 */
	private function set_product_brand( string $brand ): void {
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => $brand ) )
		);
	}

	/**
	 * Capture render_head_tags() output.
	 */
	private function render_head(): string {
		ob_start();
		$this->meta->render_head_tags();
		return (string) ob_get_clean();
	}

	public function test_authored_product_description_is_emitted_by_us(): void {
		// Fix (#668 review): predicting whether Jetpack would go on to emit
		// the authored description itself was fragile — a site filtering
		// `jetpack_seo_meta_tags_enabled` to false, or a theme listed in
		// `jetpack_seo_meta_tags_conflicted_themes`, left
		// `is_enabled_jetpack_seo()` true while Jetpack's own `meta_tags()`
		// never ran, so the old "defer" prediction produced a page with no
		// description at all in those states. We now always suppress
		// Jetpack's copy AND always emit our own, so the authored value
		// comes from us in every state and there is exactly one tag.
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, 'Hand-written product copy.' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Hand-written product copy.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$html = $this->render_head();
		$this->assertStringContainsString(
			'<meta name="description" content="Hand-written product copy."',
			$html
		);
	}

	public function test_unauthored_product_keeps_todays_behaviour(): void {
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, '' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Jetpack generated.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$this->assertStringContainsString( 'name="description"', $this->render_head() );
	}

	public function test_authored_shop_description_is_emitted_by_us(): void {
		// Jetpack gates its per-post description on is_singular(), which is
		// false on the product archive, so it can never emit this one. We
		// read the Shop page post directly via wc_get_page_id( 'shop' ).
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, 'Authored shop copy.' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Site tagline.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$this->assertStringContainsString( 'Authored shop copy.', $this->render_head() );
	}

	public function test_jetpack_front_page_description_outranks_our_fallbacks_when_shop_is_the_front_page(): void {
		// Jetpack's site-wide option describes the HOMEPAGE, so it is the
		// right copy exactly when the shop is the homepage. There it is
		// authored and more specific than the Shop page's post_content or
		// the tagline, and keeps its precedence. We no longer defer emission
		// of it to Jetpack; we suppress Jetpack's copy and print this same
		// value ourselves, same as any other authored description.
		$this->fake_page( 'shop' );
		Functions\when( 'is_front_page' )->justReturn( true );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, '' );
		$this->set_front_page_description( 'Authored front page copy.' );
		Functions\when( 'get_post_field' )->justReturn( 'Shop page body copy.' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Authored front page copy.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$html = $this->render_head();
		$this->assertStringContainsString( 'Authored front page copy.', $html );
		$this->assertStringNotContainsString( 'Shop page body copy.', $html );
	}

	public function test_shop_page_post_description_outranks_the_front_page_option(): void {
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, 'Authored shop copy.' );
		$this->set_front_page_description( 'Authored front page copy.' );

		$this->assertStringContainsString( 'Authored shop copy.', $this->render_head() );
	}

	public function test_category_page_is_untouched_by_this_change(): void {
		// Terms carry no Jetpack post meta, so no authored post field may
		// reach a category page. Behaviour must not drift.
		//
		// The seo-tools module is deliberately ACTIVE here (#668 review):
		// the earlier version of this test left it off, so
		// authored_description() returned '' at its is_available() guard
		// before page-type routing ran at all, and a leak added to the
		// category branch would have gone unnoticed. Authored copy is
		// planted both on the term's own queried-object ID and on an
		// unrelated post, so either shape of leak shows up in the output.
		$this->fake_page( 'product_category' );
		$this->set_authored_description( 9, 'Authored copy keyed on the term ID.' );
		$this->set_authored_description( 5, 'Authored copy from another post.' );
		$this->set_front_page_description( 'Authored front page copy.' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Site tagline.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$html = $this->render_head();
		// fake_page() gives the term the description "Leather belts.".
		$this->assertStringContainsString(
			'<meta name="description" content="Leather belts."',
			$html
		);
		$this->assertStringNotContainsString( 'Authored copy', $html );
		$this->assertStringNotContainsString( 'Authored front page copy.', $html );
	}

	public function test_without_jetpack_every_page_behaves_as_before(): void {
		// Zero-runtime-dependency guarantee. With no Jetpack modules active
		// the adapter answers '' everywhere and the old paths are unchanged.
		$this->fake_page( 'shop' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Site tagline.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$this->assertStringContainsString( 'name="description"', $this->render_head() );
	}

	public function test_shop_not_front_page_ignores_jetpacks_front_page_option(): void {
		// Jetpack's `jetpack_seo_front_page_description` option is a
		// site-wide value describing the HOMEPAGE. On a /shop/ that is not
		// the front page, letting it outrank the Shop page's own body copy
		// puts copy written about a different page into this page's snippet
		// (#668 review). The Shop page's post_content is the more specific
		// source here and must win.
		//
		// This is about OUR precedence, not about whether Jetpack would
		// emit: Jetpack_SEO::meta_tags() does seed $meta['description'] from
		// this option unconditionally (class-jetpack-seo.php:178-180), which
		// is exactly why suppress_jetpack_description() removes its copy on
		// every commerce page.
		$this->fake_page( 'shop' );
		Functions\when( 'is_front_page' )->justReturn( false );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, '' );
		$this->set_front_page_description( 'Authored front page copy.' );
		Functions\when( 'get_post_field' )->justReturn( 'Shop page body copy.' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Authored front page copy.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$html = $this->render_head();
		$this->assertStringContainsString( 'Shop page body copy.', $html );
		$this->assertStringNotContainsString( 'Authored front page copy.', $html );
	}

	public function test_authored_product_title_suppresses_brand_enrichment(): void {
		// filter_title_parts() exists to append the brand. An authored title
		// is the merchant's finished headline; appending to it overrides
		// their intent. The brand still ships as a discrete `brand` field in
		// the product JSON-LD. A brand IS stubbed here on purpose: without
		// one there would be nothing to append either way and the assertion
		// would hold whether or not the guard existed.
		$this->fake_page( 'product', 77 );
		$this->set_authored_title( 77, 'The Only Jacket You Need' );
		$this->set_product_brand( 'Northmoor' );

		$parts = ( new WC_AI_Storefront_Meta_Tags() )->filter_title_parts(
			array(
				'title' => 'Storm Jacket',
				'site'  => 'Saltwarp',
			)
		);

		$this->assertSame( 'Storm Jacket', $parts['title'] );
	}

	public function test_unauthored_product_title_still_gets_the_brand(): void {
		$this->fake_page( 'product', 77 );
		$this->set_authored_title( 77, '' );
		$this->set_product_brand( 'Northmoor' );

		$parts = ( new WC_AI_Storefront_Meta_Tags() )->filter_title_parts(
			array(
				'title' => 'Storm Jacket',
				'site'  => 'Saltwarp',
			)
		);

		$this->assertSame( 'Storm Jacket | Northmoor', $parts['title'] );
	}

	public function test_authored_shop_title_is_applied_verbatim(): void {
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 5, 'Gear for weather that argues back' );

		$this->assertSame(
			'Gear for weather that argues back',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( '' )
		);
	}

	public function test_authored_shop_title_beats_a_title_jetpack_resolved(): void {
		// On a shop-as-front-page Jetpack reads get_post(), which WP has set
		// to the first PRODUCT in the archive loop, so a product's SEO
		// title can arrive here as the incoming value. Deferring to it would
		// honour the wrong author.
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 5, 'Gear for weather that argues back' );

		$this->assertSame(
			'Gear for weather that argues back',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( 'Storm Jacket' )
		);
	}

	public function test_unauthored_shop_title_is_left_alone(): void {
		// We do not own title emission on this page and must not start.
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 5, '' );

		$this->assertSame(
			'Whatever WordPress Chose',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( 'Whatever WordPress Chose' )
		);
	}

	public function test_document_title_untouched_off_commerce_pages(): void {
		$this->fake_page( 'blog_post' );

		$this->assertSame(
			'A Blog Post',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( 'A Blog Post' )
		);
	}

	// --- Fix 1 (#668 review): authored title reaches <title> escaped ---

	public function test_authored_shop_title_is_escaped_against_markup_injection(): void {
		// A non-empty return from this filter short-circuits
		// wp_get_document_title() straight into _wp_render_title_tag()'s
		// `echo '<title>' . wp_get_document_title() . '</title>'`, which does
		// not escape. Jetpack registers jetpack_seo_html_title with no
		// sanitize_callback, so raw markup can be stored. Stub esc_html()
		// with a real implementation (not the identity pass-through
		// stub_escapers() installs) so this test can tell an escaped result
		// from an unescaped one.
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 5, '</title><script>alert(1)</script>' );
		Functions\when( 'esc_html' )->alias(
			static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);

		$title = ( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( '' );

		$this->assertStringNotContainsString( '</title><script>', $title );
		$this->assertStringContainsString( '&lt;script&gt;', $title );
	}

	// --- Fix 2 (#668 review): shop title override excludes product search
	// and preserves pagination ---

	public function test_product_search_title_passes_through_untouched(): void {
		// WooCommerce defines is_shop() as
		// is_post_type_archive( 'product' ) || is_page( wc_get_page_id( 'shop' ) ),
		// and WP_Query sets is_post_type_archive( 'product' ) to true for a
		// product search (?s=&post_type=product) too, so is_shop() is also
		// true there. The Shop page's authored title must not hijack the
		// search-results title core already built.
		$this->fake_page( 'shop' );
		Functions\when( 'is_search' )->justReturn( true );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 5, 'Gear for weather that argues back' );

		$this->assertSame(
			'Search Results for "boots"',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( 'Search Results for "boots"' )
		);
	}

	public function test_authored_shop_title_page_two_does_not_collide_with_page_one(): void {
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 5, 'Gear for weather that argues back' );

		// fake_page() defaults get_query_var() to 0 (unpaginated).
		$page_one = ( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( '' );

		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => 'paged' === $var ? 2 : 0
		);
		$page_two = ( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( '' );

		$this->assertSame( 'Gear for weather that argues back', $page_one );
		$this->assertSame( 'Gear for weather that argues back - Page 2', $page_two );
		$this->assertNotSame( $page_one, $page_two );
	}

	public function test_authored_shop_title_page_two_via_page_query_var_when_shop_is_front_page(): void {
		// On a shop-as-front-page, WC_Query::is_query_var_valid_on_front_page()
		// whitelists `page`, not `paged`, as the pagination var WordPress
		// resolves there, so `/page/2/` arrives as `page` => 2 with `paged`
		// left unset (#668).
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 5, 'Gear for weather that argues back' );

		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => 'page' === $var ? 2 : 0
		);
		$page_two = ( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( '' );

		$this->assertSame( 'Gear for weather that argues back - Page 2', $page_two );
	}

	// --- Fix 4 (#668 review): og:description agrees with the authored
	// meta description on products ---

	public function test_authored_product_description_matches_og_and_twitter_description(): void {
		// Before this fix, an authored product fed build_og_tags() the
		// auto-derived description regardless, so og:description (and
		// twitter:description) carried generated copy while the meta
		// description carried the merchant's own words — a social preview
		// that contradicted the search snippet.
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, 'Hand-written product copy.' );

		$html = $this->render_head();

		$this->assertStringContainsString(
			'<meta name="description" content="Hand-written product copy."',
			$html
		);
		$this->assertStringContainsString(
			'<meta property="og:description" content="Hand-written product copy."',
			$html
		);
		$this->assertStringContainsString(
			'<meta name="twitter:description" content="Hand-written product copy."',
			$html
		);
	}

	// --- Fix 1 (#668 review): a candidate is judged after cleaning ---

	public function test_shop_content_that_cleans_to_nothing_falls_through_to_the_tagline(): void {
		// The realistic trigger: a Shop page whose content is a
		// product-collection block and no prose. It is non-empty raw, so a
		// chain that tested the RAW value consumed it here, never consulted
		// the tagline, and shipped a page with NO description at all —
		// suppress_jetpack_description() having already removed Jetpack's.
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		Functions\when( 'get_post_field' )->justReturn(
			'<!-- wp:woocommerce/product-collection {"query":{}} /-->'
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Gear for weather that argues back.' );

		$html = $this->render_head();

		$this->assertStringContainsString(
			'<meta name="description" content="Gear for weather that argues back."',
			$html
		);
	}

	public function test_authored_shop_copy_that_cleans_to_nothing_falls_through(): void {
		// Same defect one candidate earlier in the chain: an authored field
		// holding only markup must not consume the shop's own body copy.
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, '<span></span>' );
		Functions\when( 'get_post_field' )->justReturn( 'Shop page body copy.' );

		$this->assertStringContainsString(
			'<meta name="description" content="Shop page body copy."',
			$this->render_head()
		);
	}

	public function test_category_description_that_cleans_to_nothing_falls_back_to_the_generated_one(): void {
		$this->stub_escapers();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 9,
				'name'        => 'Belts',
				'description' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
			)
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );

		$desc = $this->meta->build_archive_description();

		$this->assertNotSame( '', $desc );
		$this->assertStringContainsString( 'Belts', $desc );
		$this->assertStringContainsString( 'Saltwarp', $desc );
	}

	// --- Fix 2 (#668 review): product search gets robots only ---

	public function test_product_search_emits_robots_only(): void {
		// is_shop() is true on ?s=…&post_type=product, so before this fix
		// the archive branch caught the search request and shipped the Shop
		// page's authored description plus a full OG/Twitter card on a
		// noindexed results page.
		$this->fake_page( 'shop' );
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'get_query_var' )->alias(
			static fn( $var ) => 'post_type' === $var ? 'product' : 0
		);
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, 'Authored shop copy.' );

		$html = $this->render_head();

		$this->assertStringContainsString( '<meta name="robots" content="noindex,follow"', $html );
		$this->assertStringNotContainsString( 'name="description"', $html );
		$this->assertStringNotContainsString( 'property="og:', $html );
		$this->assertStringNotContainsString( 'name="twitter:', $html );
		$this->assertStringNotContainsString( 'Authored shop copy.', $html );
	}

	public function test_product_search_does_not_resolve_the_shop_pages_authored_description(): void {
		// The guard also sits in authored_description(), so a caller that
		// reaches build_archive_description() directly on a search request
		// cannot pull the Shop page's authored copy either.
		$this->fake_page( 'shop' );
		Functions\when( 'is_search' )->justReturn( true );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, 'Authored shop copy.' );
		Functions\when( 'get_post_field' )->justReturn( 'Shop page body copy.' );

		$this->assertStringNotContainsString(
			'Authored shop copy.',
			$this->meta->build_archive_description()
		);
	}

	// --- Fix 4 (#668 review): we emit the authored product title ourselves ---

	public function test_authored_product_title_is_emitted_by_us(): void {
		// Standing down on the assumption that Jetpack would apply the
		// headline failed in both directions. When Jetpack does apply it,
		// its own pre_get_document_title callback short-circuits
		// wp_get_document_title() and document_title_parts never fires, so
		// the stand-down was unreachable. When Jetpack does not — custom
		// titles filtered off, or a conflicted theme — the merchant got
		// neither their headline nor the brand suffix they had before.
		$this->fake_page( 'product', 77 );
		$this->set_authored_title( 77, 'The Only Jacket You Need' );

		$this->assertSame(
			'The Only Jacket You Need',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( '' )
		);
	}

	public function test_authored_product_title_overrides_what_jetpack_resolved(): void {
		$this->fake_page( 'product', 77 );
		$this->set_authored_title( 77, 'The Only Jacket You Need' );

		$this->assertSame(
			'The Only Jacket You Need',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( 'Storm Jacket - Saltwarp' )
		);
	}

	public function test_unauthored_product_title_is_left_alone(): void {
		// Nothing authored: core (and our brand enrichment via
		// document_title_parts) keeps the title.
		$this->fake_page( 'product', 77 );
		$this->set_authored_title( 77, '' );

		$this->assertSame(
			'Storm Jacket - Saltwarp',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( 'Storm Jacket - Saltwarp' )
		);
	}

	public function test_authored_product_title_is_escaped_against_markup_injection(): void {
		$this->fake_page( 'product', 77 );
		$this->set_authored_title( 77, '</title><script>alert(1)</script>' );
		Functions\when( 'esc_html' )->alias(
			static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' )
		);

		$title = ( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( '' );

		$this->assertStringNotContainsString( '</title><script>', $title );
		$this->assertStringContainsString( '&lt;script&gt;', $title );
	}

	public function test_authored_product_title_is_not_applied_to_the_shop_page(): void {
		// The two branches must not cross: a product's authored headline
		// belongs on that product, never on the archive. WordPress sets the
		// queried object on a non-singular query to the first item in the
		// loop, which on the product archive is a product — so a branch that
		// read the queried object here would pick up product 77's headline.
		$this->fake_page( 'shop' );
		Functions\when( 'get_queried_object_id' )->justReturn( 77 );
		$this->set_shop_page_id( 5 );
		$this->set_authored_title( 77, 'The Only Jacket You Need' );

		$this->assertSame(
			'Shop - Saltwarp',
			( new WC_AI_Storefront_Meta_Tags() )->filter_document_title( 'Shop - Saltwarp' )
		);
	}

	// --- Fix 5 (#668 review): build_description() honours its argument ---

	public function test_build_description_reads_the_product_it_was_given(): void {
		// build_description() and build_og_tags() are public on a non-final
		// class, so the product handed in need not be the queried one. The
		// authored lookup must key on $product->get_id(), not on
		// get_queried_object_id().
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, 'Copy for the queried product.' );
		$this->set_authored_description( 42, 'Copy for the product passed in.' );

		$this->assertSame(
			'Copy for the product passed in.',
			$this->meta->build_description( $this->make_product( array( 'id' => 42 ) ) )
		);
	}

	// --- Headline invariant: exactly one description tag per page ---

	public function test_exactly_one_description_tag_on_a_product(): void {
		// The whole design rests on there being one emitter: we always
		// remove Jetpack's tag and always print our own. A second emitter
		// added anywhere must fail here rather than ship duplicate tags.
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, 'Hand-written product copy.' );

		$this->assertSame(
			1,
			substr_count( $this->render_head(), '<meta name="description"' )
		);
	}

	public function test_exactly_one_description_tag_on_the_shop_page(): void {
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, 'Authored shop copy.' );

		$this->assertSame(
			1,
			substr_count( $this->render_head(), '<meta name="description"' )
		);
	}

	// ------------------------------------------------------------------
	// Stand down for other SEO plugins (#669 task 2)
	//
	// "Not emitting -> unchanged on product, category and shop" is not
	// re-tested here: every test above already exercises those three page
	// types through render_head_tags() with WC_AI_Storefront_Rival_Seo_Description
	// left at its reset() default (is_emitting() === false), e.g.
	// test_authored_product_description_is_emitted_by_us() (product),
	// test_render_emits_archive_metadata_for_category() and
	// test_category_page_is_untouched_by_this_change() (category), and
	// test_authored_shop_description_is_emitted_by_us() (shop). Adding
	// near-duplicates of those here would only restate the same assertion.
	// ------------------------------------------------------------------

	public function test_description_tag_suppressed_when_a_rival_seo_plugin_is_emitting(): void {
		// is_emitting() reports what a rival plugin's own filter actually
		// carried this request — settled before this wp_head:5 callback
		// runs, since every rival filter is hooked at PHP_INT_MAX and fires
		// during wp_head:1 (see WC_AI_Storefront_Rival_Seo_Description::init()).
		// Never a prediction. Authored copy is set so our own path would
		// otherwise have produced a description here.
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, 'Hand-written product copy.' );
		WC_AI_Storefront_Rival_Seo_Description::observe( 'Yoast wrote this one.' );

		$this->assertStringNotContainsString( 'name="description"', $this->render_head() );
	}

	public function test_og_description_still_emitted_when_a_rival_seo_plugin_is_emitting(): void {
		// Scope is description-only. Open Graph/Twitter duplication is a
		// separate, deliberately out-of-scope problem (#676): the rival
		// filter predicts only the rival's own <meta name="description">,
		// not its Open Graph output — free Yoast with nothing authored
		// fires wpseo_metadesc empty (correctly predicting no description
		// tag) yet still emits og:description regardless. Asserted
		// explicitly so this asymmetry reads as a decision, not an
		// oversight.
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, 'Hand-written product copy.' );
		WC_AI_Storefront_Rival_Seo_Description::observe( 'Yoast wrote this one.' );

		$html = $this->render_head();

		$this->assertStringContainsString(
			'<meta property="og:description" content="Hand-written product copy."',
			$html
		);
		$this->assertStringContainsString(
			'<meta name="twitter:description" content="Hand-written product copy."',
			$html
		);
	}

	public function test_rival_signal_has_no_effect_when_should_emit_is_false(): void {
		// Non-commerce page (every conditional defaults false in setUp()):
		// should_emit() is false regardless of what the rival observer
		// says, and render_head_tags() stays a no-op. The stand-down is a
		// third input to the existing decision, living inside should_emit()'s
		// gate, not a parallel check that could fire outside it.
		WC_AI_Storefront_Rival_Seo_Description::observe( 'Yoast wrote this one.' );

		$this->assertSame( '', $this->render_head() );
	}

	public function test_exactly_one_description_tag_on_the_page_when_a_rival_plugin_emits(): void {
		// render_head_tags() only ever controls our half of the page; the
		// rival plugin prints its own tag directly during its own wp_head
		// callback, which this unit test cannot invoke. Concatenating a
		// stand-in for that tag with our own output proves the *page* ends
		// up with exactly one description tag, not merely that our own
		// emitter stayed silent (the test above).
		$this->fake_page( 'product', 77 );
		$this->set_authored_description( 77, 'Hand-written product copy.' );
		WC_AI_Storefront_Rival_Seo_Description::observe( 'Yoast wrote this one.' );

		$rival_tag = '<meta name="description" content="Yoast wrote this one." />' . "\n";
		$html      = $rival_tag . $this->render_head();

		$this->assertSame( 1, substr_count( $html, '<meta name="description"' ) );
	}
}
