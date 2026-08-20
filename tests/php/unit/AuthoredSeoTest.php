<?php
/**
 * Tests for WC_AI_Storefront_Authored_SEO.
 *
 * The Jetpack, Jetpack_SEO_Posts and Jetpack_SEO_Utils doubles this class
 * drives live in tests/php/stubs-jetpack.php, loaded once from
 * tests/php/bootstrap.php, not in this file — see that file's docblock for
 * why. They carry the real Jetpack class names, not "Fake*" aliases, since
 * WC_AI_Storefront_Authored_SEO reaches Jetpack only through
 * class_exists( 'Jetpack' ) / class_exists( 'Jetpack_SEO_Posts' ) /
 * class_exists( 'Jetpack_SEO_Utils' ).
 *
 * Because the bootstrap loads them unconditionally, "Jetpack is not loaded
 * at all" is not an observable state from within this file; see the
 * class-presence test below for how that guarantee is covered instead.
 *
 * @package WooCommerce_AI_Storefront
 */

class AuthoredSeoTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Jetpack::$active_modules                   = array();
		Jetpack_SEO_Posts::$descriptions           = array();
		Jetpack_SEO_Posts::$titles                 = array();
		Jetpack_SEO_Utils::$front_page_description = '';
	}

	public function test_is_available_and_accessors_are_false_and_empty_when_no_modules_are_active(): void {
		// Behaviourally identical to Jetpack being absent: nothing this
		// adapter reads is active, so every entry point must answer safely
		// rather than surface anything. The zero-runtime-dependency promise
		// is about this outcome, not about whether the Jetpack class itself
		// happens to be loaded in the process.
		Jetpack::$active_modules = array();

		$this->assertFalse( WC_AI_Storefront_Authored_SEO::is_available() );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_title( 42 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::front_page_description() );
	}

	public function test_is_available_is_false_when_seo_module_is_off(): void {
		// Jetpack's own accessors do NOT check module state — they only
		// check is_enabled_jetpack_seo(), which is true on any self-hosted
		// site. Without this gate we would revive authored metadata on a
		// store whose merchant switched SEO Tools off and where Jetpack
		// has stopped emitting it.
		Jetpack::$active_modules = array( 'stats' );

		$this->assertFalse( WC_AI_Storefront_Authored_SEO::is_available() );
	}

	public function test_post_description_returns_empty_when_module_is_off(): void {
		Jetpack::$active_modules             = array( 'stats' );
		Jetpack_SEO_Posts::$descriptions[42] = 'Authored copy';

		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
	}

	public function test_post_description_returns_authored_copy_when_available(): void {
		Jetpack::$active_modules             = array( 'seo-tools' );
		Jetpack_SEO_Posts::$descriptions[42] = 'Authored copy';

		$this->assertSame( 'Authored copy', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
	}

	public function test_post_description_is_specific_to_the_requested_post(): void {
		// The double keys by post ID, not a single flat value, because
		// production code chooses which post to read based on page type
		// (#668). A test fixture that answered the same value for any post
		// ID could never catch a routing bug that read the wrong post.
		Jetpack::$active_modules             = array( 'seo-tools' );
		Jetpack_SEO_Posts::$descriptions[42] = 'Authored copy for 42';
		Jetpack_SEO_Posts::$descriptions[43] = 'Authored copy for 43';

		$this->assertSame( 'Authored copy for 42', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
		$this->assertSame( 'Authored copy for 43', WC_AI_Storefront_Authored_SEO::post_description( 43 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 44 ) );
	}

	public function test_post_title_returns_authored_title_when_available(): void {
		Jetpack::$active_modules       = array( 'seo-tools' );
		Jetpack_SEO_Posts::$titles[42] = 'Authored headline';

		$this->assertSame( 'Authored headline', WC_AI_Storefront_Authored_SEO::post_title( 42 ) );
	}

	public function test_front_page_description_returns_authored_copy_when_available(): void {
		Jetpack::$active_modules                   = array( 'seo-tools' );
		Jetpack_SEO_Utils::$front_page_description = 'Authored front page copy';

		$this->assertSame( 'Authored front page copy', WC_AI_Storefront_Authored_SEO::front_page_description() );
	}

	public function test_non_string_return_from_jetpack_is_coerced(): void {
		// Third-party filters can hook Jetpack's accessors. A non-string
		// must not propagate into a template tag.
		Jetpack::$active_modules             = array( 'seo-tools' );
		Jetpack_SEO_Posts::$descriptions[42] = null;

		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
	}
}
