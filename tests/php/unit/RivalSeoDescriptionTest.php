<?php
/**
 * Tests for WC_AI_Storefront_Rival_Seo_Description.
 *
 * The two multi-firing shapes exercised here (test_is_emitting_is_true_
 * for_the_seopress_shape, ..._aioseo_shape) are not arbitrary — they
 * reproduce the exact firing patterns measured against real installs in
 * `.claude/tmp/artifacts/669/GROUND-TRUTH.md` (#669 spike). Every callback
 * is called directly, the same way MetaTagsTest exercises
 * suppress_jetpack_description(), rather than through a real `wp_head`
 * dispatch: Brain Monkey's `apply_filters()` does not invoke callbacks
 * registered via `add_filter()` (it is an expectation-recording double,
 * not a real hook engine), so the only way to exercise observe() under
 * test is to call it directly.
 *
 * @package WooCommerce_AI_Storefront
 */

class RivalSeoDescriptionTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		WC_AI_Storefront_Rival_Seo_Description::reset();
	}

	protected function tearDown(): void {
		// Also on the way out, not only on the way in: the suite shares one
		// PHP process (phpunit.xml.dist sets no processIsolation), so a
		// fixture left set here would otherwise be the starting state for
		// whatever test file PHPUnit runs next.
		WC_AI_Storefront_Rival_Seo_Description::reset();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// is_emitting()
	// ------------------------------------------------------------------

	public function test_is_emitting_is_false_when_nothing_ever_fires(): void {
		$this->assertFalse( WC_AI_Storefront_Rival_Seo_Description::is_emitting() );
	}

	public function test_is_emitting_is_true_after_one_non_empty_firing(): void {
		WC_AI_Storefront_Rival_Seo_Description::observe( 'ZZ669 rival plugin description.' );

		$this->assertTrue( WC_AI_Storefront_Rival_Seo_Description::is_emitting() );
	}

	public function test_is_emitting_is_false_after_one_empty_firing(): void {
		WC_AI_Storefront_Rival_Seo_Description::observe( '' );

		$this->assertFalse( WC_AI_Storefront_Rival_Seo_Description::is_emitting() );
	}

	public function test_is_emitting_is_true_for_the_seopress_shape(): void {
		// Measured, not assumed (GROUND-TRUTH.md, #669 spike): SEOPress
		// fires `seopress_titles_desc` 8 to 12 times per request. The first
		// firing carries the real value; every firing after it is empty.
		// An observer that kept the LAST value would conclude nothing was
		// emitted.
		WC_AI_Storefront_Rival_Seo_Description::observe( 'ZZ669 first firing, the real value.' );
		for ( $i = 0; $i < 11; $i++ ) {
			WC_AI_Storefront_Rival_Seo_Description::observe( '' );
		}

		$this->assertTrue( WC_AI_Storefront_Rival_Seo_Description::is_emitting() );
	}

	public function test_is_emitting_is_true_for_the_aioseo_shape(): void {
		// Measured, not assumed (GROUND-TRUTH.md, #669 spike): All in One
		// SEO fires `aioseo_description` exactly twice per request - the
		// real value first, then an empty string. Same failure mode as the
		// SEOPress shape above if the observer kept the last value instead
		// of the first non-empty one.
		WC_AI_Storefront_Rival_Seo_Description::observe( 'ZZ669 real value, fires first.' );
		WC_AI_Storefront_Rival_Seo_Description::observe( '' );

		$this->assertTrue( WC_AI_Storefront_Rival_Seo_Description::is_emitting() );
	}

	public function test_non_string_value_is_treated_as_no_value_without_a_fatal(): void {
		// A third-party filter can hand back anything. get_post_meta()
		// storage returns an array when a meta key holds multiple rows -
		// the same non-string shape AuthoredSeoTest exercises against a
		// different reader.
		WC_AI_Storefront_Rival_Seo_Description::observe( array( 'unexpected-shape' ) );

		$this->assertFalse( WC_AI_Storefront_Rival_Seo_Description::is_emitting() );
	}

	// ------------------------------------------------------------------
	// observe() - observation only, never rewrites the input
	// ------------------------------------------------------------------

	public function test_callback_returns_non_empty_input_unchanged(): void {
		// OBSERVATION ONLY: a bug here would silently rewrite another SEO
		// plugin's <head> output on a live store.
		$value = 'ZZ669 rival plugin description, byte for byte.';

		$this->assertSame( $value, WC_AI_Storefront_Rival_Seo_Description::observe( $value ) );
	}

	public function test_callback_returns_empty_input_unchanged(): void {
		$this->assertSame( '', WC_AI_Storefront_Rival_Seo_Description::observe( '' ) );
	}

	public function test_callback_returns_non_string_input_unchanged(): void {
		$value = array( 'unexpected-shape' );

		$this->assertSame( $value, WC_AI_Storefront_Rival_Seo_Description::observe( $value ) );
	}

	// ------------------------------------------------------------------
	// init()
	// ------------------------------------------------------------------

	public function test_init_hooks_all_four_rival_description_filters(): void {
		// Registration existence only - deliberately not asserting the
		// PHP_INT_MAX priority here. See init()'s own docblock for why the
		// priority is load-bearing; that requirement is a runtime-ordering
		// property (this observer must run after whatever priority the
		// rival plugin itself uses), not something a same-process unit
		// test can prove by re-reading Brain Monkey's recorded args.
		WC_AI_Storefront_Rival_Seo_Description::init();

		$filters = array(
			'wpseo_metadesc',
			'rank_math/frontend/description',
			'seopress_titles_desc',
			'aioseo_description',
		);
		foreach ( $filters as $filter ) {
			$this->assertNotFalse(
				has_filter( $filter, array( 'WC_AI_Storefront_Rival_Seo_Description', 'observe' ) ),
				"init() did not hook {$filter}"
			);
		}
	}
}
