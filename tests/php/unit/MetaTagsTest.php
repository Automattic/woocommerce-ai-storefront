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

	private function make_product( array $overrides = array() ): \Mockery\MockInterface {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_short_description' )->andReturn( $overrides['short'] ?? '' );
		$product->shouldReceive( 'get_description' )->andReturn( $overrides['long'] ?? '' );
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
}
