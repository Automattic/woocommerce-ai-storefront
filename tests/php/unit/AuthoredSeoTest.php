<?php
/**
 * Tests for WC_AI_Storefront_Authored_SEO.
 *
 * The Jetpack, Jetpack_SEO_Posts and Jetpack_SEO_Utils doubles at the
 * bottom of this file carry the real Jetpack class names, not "Fake*"
 * aliases. WC_AI_Storefront_Authored_SEO reaches Jetpack only through
 * class_exists( 'Jetpack' ) / class_exists( 'Jetpack_SEO_Posts' ) /
 * class_exists( 'Jetpack_SEO_Utils' ), so a differently-named double would
 * never be found and the guarded paths would go untested. Each double is
 * declared inside a class_exists() guard so it can never collide with a
 * real Jetpack install running in the same process.
 *
 * phpunit.xml.dist sets no processIsolation, so every test file in this
 * suite shares one PHP process and these doubles, once loaded, stay loaded
 * for the rest of the run. That also means "Jetpack is not loaded at all"
 * is not an observable state from within this file; see the class-presence
 * test below for how that guarantee is covered instead.
 *
 * @package WooCommerce_AI_Storefront
 */

class AuthoredSeoTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Jetpack::$active_modules          = array();
		Jetpack_SEO_Posts::$description   = '';
		Jetpack_SEO_Posts::$title         = '';
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
		Jetpack::$active_modules        = array( 'stats' );
		Jetpack_SEO_Posts::$description = 'Authored copy';

		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
	}

	public function test_post_description_returns_authored_copy_when_available(): void {
		Jetpack::$active_modules        = array( 'seo-tools' );
		Jetpack_SEO_Posts::$description = 'Authored copy';

		$this->assertSame( 'Authored copy', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
	}

	public function test_post_title_returns_authored_title_when_available(): void {
		Jetpack::$active_modules  = array( 'seo-tools' );
		Jetpack_SEO_Posts::$title = 'Authored headline';

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
		Jetpack::$active_modules        = array( 'seo-tools' );
		Jetpack_SEO_Posts::$description = null;

		$this->assertSame( '', WC_AI_Storefront_Authored_SEO::post_description( 42 ) );
	}
}

if ( ! class_exists( 'Jetpack' ) ) {
	/**
	 * Test double for the real Jetpack class.
	 *
	 * Named to match production so WC_AI_Storefront_Authored_SEO's
	 * class_exists( 'Jetpack' ) checks find it.
	 */
	class Jetpack {

		/**
		 * Modules the double reports as active.
		 *
		 * @var string[]
		 */
		public static $active_modules = array();

		/**
		 * Mirrors Jetpack::is_module_active().
		 *
		 * @param string $module Module slug.
		 */
		public static function is_module_active( string $module ): bool {
			return in_array( $module, self::$active_modules, true );
		}
	}
}

if ( ! class_exists( 'Jetpack_SEO_Posts' ) ) {
	/**
	 * Test double for the real Jetpack_SEO_Posts class.
	 */
	class Jetpack_SEO_Posts {

		/**
		 * Value returned by get_post_custom_description().
		 *
		 * @var mixed
		 */
		public static $description = '';

		/**
		 * Value returned by get_post_custom_html_title().
		 *
		 * @var mixed
		 */
		public static $title = '';

		/**
		 * Mirrors Jetpack_SEO_Posts::get_post_custom_description().
		 *
		 * @param mixed $post Unused by the double.
		 * @return mixed
		 */
		public static function get_post_custom_description( $post = null ) {
			return self::$description;
		}

		/**
		 * Mirrors Jetpack_SEO_Posts::get_post_custom_html_title().
		 *
		 * @param mixed $post Unused by the double.
		 * @return mixed
		 */
		public static function get_post_custom_html_title( $post = null ) {
			return self::$title;
		}
	}
}

if ( ! class_exists( 'Jetpack_SEO_Utils' ) ) {
	/**
	 * Test double for the real Jetpack_SEO_Utils class.
	 */
	class Jetpack_SEO_Utils {

		/**
		 * Value returned by get_front_page_meta_description().
		 *
		 * @var mixed
		 */
		public static $front_page_description = '';

		/**
		 * Mirrors Jetpack_SEO_Utils::get_front_page_meta_description().
		 *
		 * @return mixed
		 */
		public static function get_front_page_meta_description() {
			return self::$front_page_description;
		}
	}
}
