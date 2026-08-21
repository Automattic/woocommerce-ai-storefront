<?php
/**
 * Tests for WC_AI_Storefront_Seo_Plugin_Detector.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

// User-defined stand-in for Yoast's WooCommerce SEO addon class. The
// detector only needs `class_exists( 'Yoast_WooCommerce_SEO' )` to resolve
// true; this empty class is aliased to that name in the Yoast detection
// test. It MUST be a user-defined class — `class_alias()` rejects aliasing
// an internal class (e.g. stdClass) on PHP 8.1-8.3 with a ValueError.
if ( ! class_exists( 'WC_AI_Storefront_Yoast_WC_SEO_Test_Double' ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- inline test double
	class WC_AI_Storefront_Yoast_WC_SEO_Test_Double {}
}

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
	public function test_detect_reports_seopress_when_constant_defined(): void {
		define( 'SEOPRESS_VERSION', '7.0-test' );
		$slugs = array_column( WC_AI_Storefront_Seo_Plugin_Detector::detect(), 'slug' );
		$this->assertContains( 'seopress', $slugs );
		$this->assertTrue( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_yoast_when_class_present(): void {
		// Alias a USER-DEFINED stub (not an internal class like stdClass,
		// which class_alias() rejects on PHP 8.1-8.3).
		class_alias( 'WC_AI_Storefront_Yoast_WC_SEO_Test_Double', 'Yoast_WooCommerce_SEO' );
		$found = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$slugs = array_column( $found, 'slug' );
		$this->assertContains( 'yoast', $slugs );
		// The addon is the more specific product; its label wins even when
		// free Yoast core (WPSEO_VERSION) is not itself defined.
		$yoast = $found[ array_search( 'yoast', $slugs, true ) ];
		$this->assertSame( 'Yoast WooCommerce SEO', $yoast['label'] );
		// The paid addon is the most complete overlap this detector knows
		// about, so its row must stay deactivate-able, not silently handled.
		$this->assertTrue( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_yoast_seo_when_only_core_present(): void {
		// Free Yoast core only, no paid WooCommerce SEO addon class loaded.
		// This is the common case: most stores run free Yoast, not the addon.
		define( 'WPSEO_VERSION', '22.0-test' );
		$found = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$slugs = array_column( $found, 'slug' );
		$this->assertContains( 'yoast', $slugs );
		$yoast = $found[ array_search( 'yoast', $slugs, true ) ];
		$this->assertSame( 'Yoast SEO', $yoast['label'] );
		$this->assertTrue( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_single_yoast_row_when_core_and_addon_both_present(): void {
		// Normal real-world state: the paid addon requires free core, so
		// both are active together. This must not surface as two vendor
		// rows - WC_AI_Storefront_Schema_Conflict_Notice::maybe_render()
		// joins every label into one sentence ("X emits some of the same
		// tags..."), which reads wrong (and grammatically singular-vs-
		// plural broken) if the same vendor appears twice.
		define( 'WPSEO_VERSION', '22.0-test' );
		class_alias( 'WC_AI_Storefront_Yoast_WC_SEO_Test_Double', 'Yoast_WooCommerce_SEO' );
		$found = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		// Match on label content, not slug: the risk this guards against is
		// two rows naming Yoast (whatever their slugs), since the notice
		// renders by label, not slug.
		$yoast = array_values(
			array_filter(
				$found,
				static fn( $p ) => false !== stripos( $p['label'], 'yoast' )
			)
		);
		$this->assertCount( 1, $yoast );
		$this->assertSame( 'Yoast WooCommerce SEO', $yoast[0]['label'] );
		// The merged row still carries the addon's overlap, so it must
		// remain deactivate-able rather than falling out silently.
		$this->assertTrue( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_reports_jetpack_as_handled(): void {
		define( 'JETPACK__VERSION', '13.0-test' );
		$found   = WC_AI_Storefront_Seo_Plugin_Detector::detect();
		$jetpack = array_values( array_filter( $found, static fn( $p ) => 'jetpack' === $p['slug'] ) );
		$this->assertNotEmpty( $jetpack );
		$this->assertTrue( ! empty( $jetpack[0]['handled'] ) );
		// Jetpack alone is auto-handled, so it is NOT a deactivate-able conflict.
		$this->assertFalse( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deactivatable_excludes_handled_but_keeps_real_conflicts(): void {
		// Jetpack (handled) + Rank Math + SEOPress (both deactivate-able,
		// the latter a row this PR introduced) all present.
		define( 'JETPACK__VERSION', '13.0-test' );
		define( 'RANK_MATH_VERSION', '1.0.0-test' );
		define( 'SEOPRESS_VERSION', '7.0-test' );
		$slugs = array_column( WC_AI_Storefront_Seo_Plugin_Detector::deactivatable(), 'slug' );
		$this->assertContains( 'rankmath', $slugs );
		$this->assertContains( 'seopress', $slugs );
		$this->assertNotContains( 'jetpack', $slugs );
		// A real deactivate-able conflict still exists.
		$this->assertTrue( WC_AI_Storefront_Seo_Plugin_Detector::has_conflict() );
	}
}
