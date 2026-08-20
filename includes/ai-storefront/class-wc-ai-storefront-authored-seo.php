<?php
/**
 * Merchant-authored SEO metadata, read from Jetpack when it is present.
 *
 * The only file in the plugin that names a Jetpack symbol. Everything here
 * degrades to an empty string when Jetpack is absent, deactivated, or has
 * its SEO Tools module switched off, so callers never guard.
 *
 * Two conditions gate every read, not one. Jetpack's own accessors check
 * `Jetpack_SEO_Utils::is_enabled_jetpack_seo()`, which returns true on any
 * self-hosted or Atomic site — it consults a plan feature only under
 * `IS_WPCOM`. It does NOT check whether the seo-tools module is active. A
 * merchant who switched SEO Tools off has stopped seeing Jetpack emit this
 * metadata, and reviving it here would surprise them, so `is_available()`
 * adds the module check. (#668)
 *
 * @package WooCommerce_AI_Storefront
 * @since   0.39.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guarded reader for merchant-authored SEO fields Jetpack stores.
 */
final class WC_AI_Storefront_Authored_SEO {

	/**
	 * Jetpack module slug that owns the authored-SEO fields.
	 */
	const MODULE = 'seo-tools';

	/**
	 * Whether merchant-authored SEO metadata can be read on this site.
	 */
	public static function is_available(): bool {
		if ( ! class_exists( 'Jetpack' ) || ! method_exists( 'Jetpack', 'is_module_active' ) ) {
			return false;
		}
		if ( ! class_exists( 'Jetpack_SEO_Posts' ) ) {
			return false;
		}
		return (bool) Jetpack::is_module_active( self::MODULE );
	}

	/**
	 * The merchant's authored meta description for a post, or ''.
	 *
	 * @param int $post_id Post to read. 0 or a missing post yields ''.
	 */
	public static function post_description( int $post_id ): string {
		if ( $post_id <= 0 || ! self::is_available() ) {
			return '';
		}
		if ( ! method_exists( 'Jetpack_SEO_Posts', 'get_post_custom_description' ) ) {
			return '';
		}
		$value = Jetpack_SEO_Posts::get_post_custom_description( $post_id );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The merchant's authored HTML title for a post, or ''.
	 *
	 * @param int $post_id Post to read. 0 or a missing post yields ''.
	 */
	public static function post_title( int $post_id ): string {
		if ( $post_id <= 0 || ! self::is_available() ) {
			return '';
		}
		if ( ! method_exists( 'Jetpack_SEO_Posts', 'get_post_custom_html_title' ) ) {
			return '';
		}
		$value = Jetpack_SEO_Posts::get_post_custom_html_title( $post_id );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The merchant's site-wide front-page meta description, or ''.
	 *
	 * Distinct from any per-post value: Jetpack stores this as an option and
	 * applies it on the front page regardless of what is queried there.
	 */
	public static function front_page_description(): string {
		if ( ! self::is_available() || ! class_exists( 'Jetpack_SEO_Utils' ) ) {
			return '';
		}
		if ( ! method_exists( 'Jetpack_SEO_Utils', 'get_front_page_meta_description' ) ) {
			return '';
		}
		$value = Jetpack_SEO_Utils::get_front_page_meta_description();
		return is_string( $value ) ? trim( $value ) : '';
	}
}
