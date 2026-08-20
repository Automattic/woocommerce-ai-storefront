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
		$this->reset_jetpack_seo_doubles();
		$this->meta = new WC_AI_Storefront_Meta_Tags();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		$this->reset_jetpack_seo_doubles();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset the Jetpack, Jetpack_SEO_Posts and Jetpack_SEO_Utils test doubles
	 * loaded from tests/php/stubs-jetpack.php via the shared bootstrap.
	 * phpunit.xml.dist sets no processIsolation, so every test file in the
	 * suite shares one PHP process, and these doubles' static properties
	 * persist across files. Called from both setUp() and tearDown() so
	 * fixtures set by one test never leak into another test in this file, or
	 * into AuthoredSeoTest.php.
	 */
	private function reset_jetpack_seo_doubles(): void {
		if ( class_exists( 'Jetpack' ) ) {
			Jetpack::$active_modules = array();
		}
		if ( class_exists( 'Jetpack_SEO_Posts' ) ) {
			Jetpack_SEO_Posts::$descriptions = array();
			Jetpack_SEO_Posts::$titles       = array();
		}
		if ( class_exists( 'Jetpack_SEO_Utils' ) ) {
			Jetpack_SEO_Utils::$front_page_description = '';
		}
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
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? '' );
		$product->shouldReceive( 'get_description' )->andReturn( $overrides['long'] ?? '' );
		$product->shouldReceive( 'get_name' )->andReturn( $overrides['name'] ?? 'Test Product' );
		return $product;
	}

	private function og_product( array $overrides = array() ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_name' )->andReturn( $overrides['name'] ?? 'Canvas Belt' );
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? 'A belt.' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'is_purchasable' )->andReturn( $overrides['purchasable'] ?? true );
		$product->shouldReceive( 'get_price' )->andReturn( $overrides['price'] ?? '48.00' );
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
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://shop.test/img/belt.jpg' );
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
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false ); // no image
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$og = $this->meta->build_og_tags( $this->og_product( array( 'purchasable' => false ) ) );
		$this->assertArrayNotHasKey( 'og:image', $og );
		$this->assertArrayNotHasKey( 'product:price:amount', $og );
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
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://shop.test/i.jpg' );
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
		$this->assertStringNotContainsString( 'noindex', $html );
	}

	public function test_render_emits_noindex_for_hidden_product(): void {
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/belt/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
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
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
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
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_short_description' )->andReturn( 'A belt.' );
		$product->shouldReceive( 'get_description' )->andReturn( '' );
		$product->shouldReceive( 'get_id' )->andReturn( 10 );
		$product->shouldReceive( 'is_purchasable' )->andReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '' );
		$og = $this->meta->build_og_tags( $product );
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
	 * @param string $type    'product' | 'shop' | 'product_category'.
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

		if ( 'product' === $type ) {
			Functions\when( 'get_queried_object_id' )->justReturn( $post_id );
			$product = $this->og_product( array( 'name' => 'Storm Jacket' ) );
			$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
			Functions\when( 'wc_get_product' )->justReturn( $product );
			Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/x/' );
			Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
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
			// Not paginated by default; filter_document_title() reads this to
			// decide whether to append a page suffix. Tests exercising
			// pagination override it after calling fake_page().
			Functions\when( 'get_query_var' )->justReturn( 0 );
		} elseif ( 'product_category' === $type ) {
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
	 * $brand for the current product. $post_id is accepted for symmetry
	 * with set_authored_title() (the production brand lookup does not key
	 * off it: get_brand_name() reads get_the_terms() unconditionally).
	 *
	 * @param int    $post_id Product ID (accepted for symmetry; unused).
	 * @param string $brand   Brand name.
	 */
	private function set_product_brand( int $post_id, string $brand ): void {
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

	public function test_jetpack_front_page_description_still_outranks_our_fallbacks(): void {
		// The site-wide front-page meta description is authored too, and
		// more specific than the shop page's post_content or the tagline —
		// it still wins that precedence contest inside
		// resolve_authored_description(). What changed (#668 review): we no
		// longer defer emission of it to Jetpack; we suppress Jetpack's copy
		// and print this same value ourselves, same as any other authored
		// description.
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, '' );
		$this->set_front_page_description( 'Authored front page copy.' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Authored front page copy.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$this->assertStringContainsString( 'Authored front page copy.', $this->render_head() );
	}

	public function test_shop_page_post_description_outranks_the_front_page_option(): void {
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, 'Authored shop copy.' );
		$this->set_front_page_description( 'Authored front page copy.' );

		$this->assertStringContainsString( 'Authored shop copy.', $this->render_head() );
	}

	public function test_category_page_is_untouched_by_this_change(): void {
		// Terms carry no Jetpack post meta. Behaviour must not drift.
		$this->fake_page( 'product_category' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Site tagline.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$this->assertStringContainsString( 'name="description"', $this->render_head() );
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

	public function test_shop_not_front_page_still_emits_jetpacks_front_page_option(): void {
		// Does Jetpack even seed the front-page option's value when the shop
		// is NOT the site's front page? Yes. In Jetpack's own
		// Jetpack_SEO::meta_tags() (modules/seo-tools/class-jetpack-seo.php):
		//   - Lines 178-180 seed $meta['description'] unconditionally from
		//     the front-page option, falling back to the tagline, before
		//     any page-type conditional runs.
		//   - The is_front_page() check at line 184 sits INSIDE the
		//     is_singular() branch, and only decides whether a per-post
		//     description overrides that seed on a singular front page.
		//   - The elseif chain that follows (is_author,
		//     is_tag/is_category/is_tax, is_date) closes at line 281 with no
		//     post-type-archive branch, so on is_shop() the seed reaches the
		//     jetpack_seo_meta_tags filter at line 283 untouched.
		// So with the Shop page's own post carrying no authored description,
		// resolve_authored_description() must still pick up the front-page
		// option here even though this shop is not the front page — the
		// fallback chain is not gated on is_front_page(). What changed
		// (#668 review): we no longer defer printing it to Jetpack; we
		// suppress Jetpack's copy and emit this value ourselves, same as
		// every other page in this file.
		$this->fake_page( 'shop' );
		Functions\when( 'is_front_page' )->justReturn( false );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, '' );
		$this->set_front_page_description( 'Authored front page copy.' );

		$meta = ( new WC_AI_Storefront_Meta_Tags() )->suppress_jetpack_description(
			array( 'description' => 'Authored front page copy.' )
		);

		$this->assertArrayNotHasKey( 'description', $meta );
		$this->assertStringContainsString( 'Authored front page copy.', $this->render_head() );
	}

	public function test_authored_description_is_memoized_within_one_request(): void {
		// authored_description() is consulted from build_description() on
		// the product path and build_archive_description() on the shop
		// path; an unmemoized call would re-enter
		// WC_AI_Storefront_Authored_SEO::is_available()'s class_exists()/
		// method_exists() checks every time (#668). Prove it resolves once
		// per instance: render once, change the underlying Jetpack double,
		// then render again on the SAME instance and confirm it still
		// reflects the value seen the first time, not the changed one.
		$this->fake_page( 'shop' );
		$this->set_shop_page_id( 5 );
		$this->set_authored_description( 5, 'First shop copy.' );

		$first = $this->render_head();
		$this->assertStringContainsString( 'First shop copy.', $first );

		// If authored_description() re-resolved on this next render instead
		// of returning the memo, this is the value it would pick up.
		Jetpack_SEO_Posts::$descriptions[5] = 'Changed shop copy.';

		$second = $this->render_head();

		$this->assertStringContainsString( 'First shop copy.', $second );
		$this->assertStringNotContainsString( 'Changed shop copy.', $second );
	}

	public function test_authored_product_title_suppresses_brand_enrichment(): void {
		// filter_title_parts() exists to append the brand. An authored title
		// is the merchant's finished headline; appending to it overrides
		// their intent. The brand still ships as a discrete `brand` field in
		// the product JSON-LD.
		$this->fake_page( 'product', 77 );
		$this->set_authored_title( 77, 'The Only Jacket You Need' );

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
		$this->set_product_brand( 77, 'Northmoor' );

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
}
