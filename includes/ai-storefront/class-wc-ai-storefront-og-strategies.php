<?php
/**
 * Selects the Open Graph coexistence strategy for whichever SEO plugin is active.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps detected SEO plugins to their Open Graph strategies.
 *
 * Selection is by detector slug, so a plugin with no strategy registered here
 * is left entirely alone. That is the correct default: touching another
 * plugin's output without having measured it is how you turn one wrong tag
 * into two.
 *
 * Jetpack is deliberately absent. Its suppression predates this seam and
 * lives in WC_AI_Storefront_Meta_Tags::suppress_jetpack_open_graph(), which
 * runs at `wp_head:9` between Jetpack's lazy `wp_head:1` loader and its
 * `wp_head:10` emitter. Registering it here as well would remove the same
 * action from two places with no owner.
 */
class WC_AI_Storefront_Og_Strategies {

	/**
	 * Detector slug => strategy class.
	 *
	 * Providers arrive one issue at a time, each behind its own measurement.
	 * An unlisted slug is not an oversight, it is "not measured yet".
	 *
	 * @var array<string,string>
	 */
	private const STRATEGIES = array(
		'seopress' => WC_AI_Storefront_Og_Strategy_Seopress::class,
		'yoast'    => WC_AI_Storefront_Og_Strategy_Yoast::class,
		'aioseo'   => WC_AI_Storefront_Og_Strategy_Aioseo::class,
		'rankmath' => WC_AI_Storefront_Og_Strategy_Rankmath::class,
	);

	/**
	 * Strategies registered for THIS request.
	 *
	 * Replaced wholesale on every init(), never appended to: #669 shipped a
	 * request-scoped latch that survived between requests in a persistent
	 * worker, and this is the same shape.
	 *
	 * @var WC_AI_Storefront_Og_Strategy[]
	 */
	private static array $registered = array();

	/**
	 * Build and register a strategy for every active SEO plugin we handle.
	 *
	 * @param callable $on_commerce_page Returns true when this plugin is
	 *                                   emitting its own tags for the current
	 *                                   request.
	 */
	public static function init( callable $on_commerce_page ): void {
		self::init_for_slugs( self::detected_slugs(), $on_commerce_page );
	}

	/**
	 * Register strategies for an explicit slug list.
	 *
	 * Split out from init() so the mapping can be exercised without defining
	 * a plugin's version constant, which is process-global and cannot be
	 * undone inside a shared test process.
	 *
	 * @param string[] $slugs            Detector slugs.
	 * @param callable $on_commerce_page Returns true when this plugin is
	 *                                   emitting its own tags.
	 */
	public static function init_for_slugs( array $slugs, callable $on_commerce_page ): void {
		self::$registered = self::for_slugs( $slugs );

		foreach ( self::$registered as $strategy ) {
			$strategy->init( $on_commerce_page );
		}
	}

	/**
	 * Whether another plugin is rendering our social tags for us this request.
	 *
	 * True when any registered strategy is in MODE_ENRICH. WC_AI_Storefront_Meta_Tags
	 * asks before printing its own Open Graph and Twitter block: enrichment
	 * that did not also stand our block down would produce two sets of tags,
	 * which is the defect, not the fix.
	 *
	 * Suppression strategies answer false — there the other plugin's tags are
	 * the ones being removed, so ours are the only ones left to print.
	 */
	public static function emission_is_delegated(): bool {
		foreach ( self::$registered as $strategy ) {
			if ( WC_AI_Storefront_Og_Strategy::MODE_ENRICH !== $strategy::mode() ) {
				continue;
			}
			// Per request, not per plugin: All in One SEO enriches on four of
			// the five commerce page types and emits nothing at all on a
			// product category, where standing our block down would leave the
			// page with no social tags.
			if ( $strategy->has_taken_over() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forget this request's strategies. Test-only; production re-registers on
	 * every request, and nothing in a request should need to undo init().
	 */
	public static function reset(): void {
		self::$registered = array();
	}

	/**
	 * The strategies handling a given set of detector slugs.
	 *
	 * @param string[] $slugs Detector slugs.
	 * @return WC_AI_Storefront_Og_Strategy[] Empty when none is handled.
	 */
	public static function for_slugs( array $slugs ): array {
		$strategies = array();

		foreach ( $slugs as $slug ) {
			if ( ! isset( self::STRATEGIES[ $slug ] ) ) {
				continue;
			}
			$class        = self::STRATEGIES[ $slug ];
			$strategies[] = new $class();
		}

		return $strategies;
	}

	/**
	 * Slugs for every SEO plugin the detector currently reports.
	 *
	 * @return string[]
	 */
	private static function detected_slugs(): array {
		return array_column( WC_AI_Storefront_Seo_Plugin_Detector::detect(), 'slug' );
	}
}
