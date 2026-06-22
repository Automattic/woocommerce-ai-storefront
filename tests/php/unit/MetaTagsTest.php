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
		$this->meta = new WC_AI_Storefront_Meta_Tags();
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
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
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? '' );
		$product->shouldReceive( 'get_description' )->andReturn( $overrides['long'] ?? '' );
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
		$p = $this->make_product( array( 'short' => 'A tight short blurb.', 'long' => 'Long body.' ) );
		$this->assertSame( 'A tight short blurb.', $this->meta->build_description( $p ) );
	}

	public function test_description_falls_back_to_long_when_short_blank(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		$p = $this->make_product( array( 'short' => '   ', 'long' => 'The long description.' ) );
		$this->assertSame( 'The long description.', $this->meta->build_description( $p ) );
	}

	public function test_description_empty_when_all_blank(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		$p = $this->make_product( array( 'short' => '', 'long' => '' ) );
		$this->assertSame( '', $this->meta->build_description( $p ) );
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
			(object) array( 'term_id' => 9, 'name' => 'Belts', 'description' => 'All our leather belts.' )
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

	public function test_title_parts_appends_brand_on_product(): void {
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_name' )->andReturn( 'Canvas Belt' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'name' => 'Leather Co' ) )
		);
		$parts = $this->meta->filter_title_parts( array( 'title' => 'Old', 'site' => 'Saltwarp' ) );
		$this->assertSame( 'Canvas Belt | Leather Co', $parts['title'] );
		$this->assertSame( 'Saltwarp', $parts['site'] );
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
			array( 'og:title' => 'X', 'og:description' => 'Y' )
		);
		$this->assertArrayNotHasKey( 'twitter:image', $tw );
	}

	public function test_archive_og_tags_for_category(): void {
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'is_product_category' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array( 'term_id' => 9, 'name' => 'Belts', 'description' => 'Leather belts.' )
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
			(object) array( 'term_id' => 9, 'name' => 'Belts', 'description' => 'Leather belts.' )
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
			(object) array( 'term_id' => 9, 'name' => 'Belts', 'description' => 'Leather belts.' )
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

	public function test_render_omits_empty_og_description_for_descriptionless_product(): void {
		$this->stub_escapers();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.test/p/x/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Saltwarp' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		$product = $this->og_product( array( 'short' => '', 'long' => '' ) );
		$product->shouldReceive( 'get_catalog_visibility' )->andReturn( 'visible' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		ob_start();
		$this->meta->render_head_tags();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'og:description', $html );
		$this->assertStringNotContainsString( 'twitter:description', $html );
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
		$out = $this->meta->suppress_jetpack_description(
			array( 'description' => 'dup', 'google-site-verification' => 'keep' )
		);
		$this->assertArrayNotHasKey( 'description', $out );
		$this->assertSame( 'keep', $out['google-site-verification'] );
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
}
