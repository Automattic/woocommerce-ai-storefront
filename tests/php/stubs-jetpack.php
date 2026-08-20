<?php
/**
 * Test doubles for the Jetpack SEO Tools classes
 * WC_AI_Storefront_Authored_SEO reads from (#668).
 *
 * These carry the real Jetpack class names, not "Fake*" aliases.
 * WC_AI_Storefront_Authored_SEO reaches Jetpack only through
 * class_exists( 'Jetpack' ) / class_exists( 'Jetpack_SEO_Posts' ) /
 * class_exists( 'Jetpack_SEO_Utils' ), so a differently-named double would
 * never be found and the guarded paths would go untested. Each double is
 * declared inside a class_exists() guard so it can never collide with a
 * real Jetpack install running in the same process.
 *
 * Loaded once from tests/php/bootstrap.php (like tests/php/stubs.php)
 * rather than from inside a single test file, so any test file can use them
 * regardless of which other test files PHPUnit happens to load first —
 * including when a single file is targeted directly on the command line
 * (e.g. `vendor/bin/phpunit tests/php/unit/MetaTagsTest.php`), which never
 * loads any other test file's contents.
 *
 * Jetpack_SEO_Posts keys its stored descriptions/titles by post ID (an
 * `array<int, mixed>` map) rather than holding one flat value for every
 * post, because production code chooses which post ID to read based on
 * page type (the queried product, vs. the Shop page via
 * `wc_get_page_id( 'shop' )`). A flat value can't tell a correct
 * implementation from one that reads the wrong post's authored copy; a
 * per-ID map can, and that is exactly what the #668 routing tests need to
 * hold true.
 *
 * @package WooCommerce_AI_Storefront
 */

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
		 * Authored descriptions, keyed by post ID. An absent key (or an
		 * explicit `null` value, for coercion tests) means "nothing
		 * authored for that post" — mirrors get_post_custom_description().
		 *
		 * @var array<int, mixed>
		 */
		public static $descriptions = array();

		/**
		 * Authored HTML titles, keyed by post ID. Same absent/null
		 * convention as $descriptions.
		 *
		 * @var array<int, mixed>
		 */
		public static $titles = array();

		/**
		 * Mirrors Jetpack_SEO_Posts::get_post_custom_description().
		 *
		 * @param mixed $post Post ID (int) or a post-like object with ->ID.
		 * @return mixed
		 */
		public static function get_post_custom_description( $post = null ) {
			// array_key_exists(), not `?? ''`: a stored `null` is a value
			// this double must hand back verbatim, so the coercion in
			// WC_AI_Storefront_Authored_SEO is the thing under test rather
			// than the double's own null-collapsing (#668 review).
			$id = self::post_id( $post );
			return array_key_exists( $id, self::$descriptions ) ? self::$descriptions[ $id ] : '';
		}

		/**
		 * Mirrors Jetpack_SEO_Posts::get_post_custom_html_title().
		 *
		 * @param mixed $post Post ID (int) or a post-like object with ->ID.
		 * @return mixed
		 */
		public static function get_post_custom_html_title( $post = null ) {
			// See get_post_custom_description() for why this is not `?? ''`.
			$id = self::post_id( $post );
			return array_key_exists( $id, self::$titles ) ? self::$titles[ $id ] : '';
		}

		/**
		 * Normalise the $post argument to an int key for the maps above.
		 *
		 * @param mixed $post Post ID (int) or a post-like object with ->ID.
		 */
		private static function post_id( $post ): int {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				return (int) $post->ID;
			}
			return (int) $post;
		}
	}
}

if ( ! class_exists( 'Jetpack_SEO_Utils' ) ) {
	/**
	 * Test double for the real Jetpack_SEO_Utils class.
	 */
	class Jetpack_SEO_Utils {

		/**
		 * Value returned by get_front_page_meta_description(). Not keyed by
		 * post ID: Jetpack stores this as a single site-wide option.
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

if ( ! function_exists( 'wc_ai_storefront_reset_jetpack_seo_doubles' ) ) {
	/**
	 * Reset every Jetpack SEO double above to its "nothing authored,
	 * no modules active" state.
	 *
	 * phpunit.xml.dist sets no processIsolation, so the whole suite shares
	 * one PHP process and these static properties outlive the test that set
	 * them. Every test file that drives the doubles must call this from both
	 * setUp() and tearDown(), so a fixture never leaks into a later test in
	 * the same file or into another file. Shared here rather than duplicated
	 * per file so there is one definition to keep correct as the doubles
	 * grow (#668 review).
	 */
	function wc_ai_storefront_reset_jetpack_seo_doubles(): void {
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
}
