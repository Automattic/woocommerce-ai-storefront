<?php
/**
 * SEO-plugin conflict detector.
 *
 * Presence-based detection of the SEO plugins (Yoast, Rank Math, All in
 * One SEO, SEOPress) that emit their own human-SERP <head> metadata.
 * Rank Math, All in One SEO, and the paid Yoast WooCommerce SEO addon
 * also emit their own WooCommerce Product schema; free Yoast core and
 * SEOPress do not. Used ONLY by the migration nudge
 * ({@see WC_AI_Storefront_Schema_Conflict_Notice}) to tell the merchant
 * they can deactivate the other plugin — it does NOT gate metadata
 * emission (we always emit on commerce pages; see
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
	 * @return array<int,array{slug:string,label:string,handled?:bool}>
	 */
	public static function detect(): array {
		$found = array();

		// Yoast SEO. Free core defines WPSEO_VERSION; the paid WooCommerce
		// SEO addon requires core and additionally defines the
		// Yoast_WooCommerce_SEO class, so whenever the addon is active,
		// core is too. Both states are reported as a single 'yoast' row,
		// carrying the addon's more specific label when the addon is
		// present and the core label otherwise, so the merchant-facing
		// notice never names the same vendor twice.
		$yoast_addon_active = class_exists( 'Yoast_WooCommerce_SEO' );
		if ( $yoast_addon_active || defined( 'WPSEO_VERSION' ) ) {
			$found[] = array(
				'slug'  => 'yoast',
				'label' => $yoast_addon_active
					? __( 'Yoast WooCommerce SEO', 'woocommerce-ai-storefront' )
					: __( 'Yoast SEO', 'woocommerce-ai-storefront' ),
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

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found[] = array(
				'slug'  => 'seopress',
				'label' => __( 'SEOPress', 'woocommerce-ai-storefront' ),
			);
		}

		// Jetpack (incl. WordPress.com / Atomic) emits its own Open Graph +
		// SEO meta description. We auto-suppress the overlap on commerce pages
		// (see WC_AI_Storefront_Meta_Tags), so it is reported as handled — the
		// merchant is never asked to deactivate Jetpack.
		if ( defined( 'JETPACK__VERSION' ) ) {
			$found[] = array(
				'slug'    => 'jetpack',
				'label'   => __( 'Jetpack', 'woocommerce-ai-storefront' ),
				'handled' => true,
			);
		}

		return $found;
	}

	/**
	 * Detected overlapping SEO plugins the merchant could deactivate — i.e.
	 * every entry that is NOT auto-handled. Auto-handled entries (e.g. Jetpack,
	 * whose overlap we suppress ourselves) are excluded, so the deactivate
	 * notice never names them.
	 *
	 * @return array<int,array{slug:string,label:string,handled?:bool}>
	 */
	public static function deactivatable(): array {
		return array_values(
			array_filter(
				self::detect(),
				static function ( $plugin ) {
					return empty( $plugin['handled'] );
				}
			)
		);
	}

	/**
	 * Whether any deactivate-able (non auto-handled) overlapping SEO plugin is
	 * present. Auto-handled entries (e.g. Jetpack, whose overlap we suppress
	 * ourselves) do not count — we never nudge the merchant to remove them.
	 */
	public static function has_conflict(): bool {
		return ! empty( self::deactivatable() );
	}
}
