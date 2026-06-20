<?php
/**
 * Tests for WC_AI_Storefront_Seo_Plugin_Detector.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

class SeoPluginDetectorTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_detect_returns_empty_when_no_seo_plugin_present(): void {
		// CI environment has none of the three SEO plugins loaded.
		$this->assertSame( array(), WC_AI_Storefront_Seo_Plugin_Detector::detect() );
		$this->assertFalse( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_rankmath_when_constant_defined(): void {
		define( 'RANK_MATH_VERSION', '1.0.0-test' );
		$found = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$slugs = array_column( $found, 'slug' );
		$this->assertContains( 'rankmath', $slugs );
		$this->assertTrue( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_aioseo_when_constant_defined(): void {
		define( 'AIOSEO_VERSION', '4.0.0-test' );
		$slugs = array_column( WC_AI_Storefront_Seo_Plugin_Detector::detect(), 'slug' );
		$this->assertContains( 'aioseo', $slugs );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_yoast_when_class_present(): void {
		// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- inline test double
		class_alias( '\stdClass', 'Yoast_WooCommerce_SEO' );
		$slugs = array_column( WC_AI_Storefront_Seo_Plugin_Detector::detect(), 'slug' );
		$this->assertContains( 'yoast', $slugs );
	}
}
