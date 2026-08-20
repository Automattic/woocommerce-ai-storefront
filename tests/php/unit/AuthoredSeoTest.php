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
		wc_ai_storefront_reset_jetpack_seo_doubles();
	}

	protected function tearDown(): void {
		// Also on the way out, not only on the way in: the suite shares one
		// PHP process, so a fixture left set here would otherwise be the
		// starting state for whatever test file PHPUnit runs next.
		wc_ai_storefront_reset_jetpack_seo_doubles();
		parent::tearDown();
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
		// Third-party filters can hook Jetpack's accessors, and Jetpack's own
		// readers hand back get_post_meta() output, which is an array when
		// the meta key holds multiple rows. A non-string must not propagate
		// into a template tag.
		//
		// The value is an array, not null (#668 review): null was collapsed
		// to '' by the double before production code ever saw it, so deleting
		// the is_string() coercion from all three readers left this test
		// green. An array reaches the coercion intact.
		Jetpack::$active_modules                   = array( 'seo-tools' );
		Jetpack_SEO_Posts::$descriptions[42]       = array( 'x' );
		Jetpack_SEO_Posts::$titles[42]             = array( 'x' );
		Jetpack_SEO_Utils::$front_page_description = array( 'x' );

		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_title( 42 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::front_page_description() );
	}

	public function test_surrounding_whitespace_is_trimmed(): void {
		// The other half of `is_string( $value ) ? trim( $value ) : ''`.
		// Untested until now for the same reason the coercion was: the
		// double never returned anything trim() could change.
		Jetpack::$active_modules                   = array( 'seo-tools' );
		Jetpack_SEO_Posts::$descriptions[42]       = "  Authored copy\n";
		Jetpack_SEO_Posts::$titles[42]             = "\tAuthored headline  ";
		Jetpack_SEO_Utils::$front_page_description = '  Authored front page copy  ';

		$this->assertSame( 'Authored copy', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
		$this->assertSame( 'Authored headline', WC_AI_Storefront_Authored_SEO::post_title( 42 ) );
		$this->assertSame( 'Authored front page copy', WC_AI_Storefront_Authored_SEO::front_page_description() );
	}

	public function test_non_positive_post_ids_are_rejected(): void {
		// Load-bearing, not defensive: wc_get_page_id( 'shop' ) returns -1
		// when no Shop page is configured, and WC_AI_Storefront_Meta_Tags
		// passes that value straight through on the shop path. Without this
		// guard a site with post ID -1 or 0 in the meta table (or any future
		// caller that treats 0 as "none") would read a stranger's copy.
		Jetpack::$active_modules             = array( 'seo-tools' );
		Jetpack_SEO_Posts::$descriptions[-1] = 'Copy that must never be read.';
		Jetpack_SEO_Posts::$descriptions[0]  = 'Copy that must never be read.';
		Jetpack_SEO_Posts::$titles[-1]       = 'Headline that must never be read.';
		Jetpack_SEO_Posts::$titles[0]        = 'Headline that must never be read.';

		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( -1 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 0 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_title( -1 ) );
		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_title( 0 ) );
	}
}
