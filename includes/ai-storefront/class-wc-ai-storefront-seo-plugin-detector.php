<?php
/**
 * SEO-plugin conflict detector.
 *
 * Presence-based detection of the three SEO plugins that emit their own
 * WooCommerce Product schema and human-SERP <head> metadata. Used ONLY
 * by the migration nudge ({@see WC_AI_Storefront_Schema_Conflict_Notice})
 * to tell the merchant they can deactivate the other plugin — it does NOT
 * gate metadata emission (we always emit on commerce pages; see
 * {@see WC_AI_Storefront_Meta_Tags::should_emit()}).
 *
 * Presence-based (not option-reading) on purpose: reading each plugin's
 * own "emit schema" toggle would couple us to version-fragile option keys.
 * A false positive here is cheap — a dismissible notice, no output change.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects overlapping SEO plugins.
 */
class WC_AI_Storefront_Seo_Plugin_Detector {

	/**
	 * Return descriptors for every detected SEO plugin.
	 *
	 * @return array<int,array{slug:string,label:string}>
	 */
	public static function detect(): array {
		$found = array();

		// Yoast WooCommerce SEO addon (NOT free Yoast core) is what emits
		// the full WC Product node + product meta.
		if ( class_exists( 'Yoast_WooCommerce_SEO' ) ) {
			$found[] = array(
				'slug'  => 'yoast',
				'label' => __( 'Yoast WooCommerce SEO', 'woocommerce-ai-storefront' ),
			);
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = array(
				'slug'  => 'rankmath',
				'label' => __( 'Rank Math SEO', 'woocommerce-ai-storefront' ),
			);
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$found[] = array(
				'slug'  => 'aioseo',
				'label' => __( 'All in One SEO', 'woocommerce-ai-storefront' ),
			);
		}

		return $found;
	}

	/**
	 * Whether any overlapping SEO plugin is present.
	 */
	public static function has_conflict(): bool {
		return ! empty( self::detect() );
	}
}
