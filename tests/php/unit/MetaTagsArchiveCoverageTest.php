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
}
