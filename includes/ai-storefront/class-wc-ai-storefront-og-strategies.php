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
	 * Replaced wholesale on every init(), never appended to. Static state on a
	 * class that outlives a request is the shape #669 had to be careful about,
	 * and WC_AI_Storefront_Rival_Seo_Description guards its own with a
	 * `wp_head:0` reset for that reason.
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
	 * The strategies registered for this request.
	 *
	 * @return WC_AI_Storefront_Og_Strategy[]
	 */
	public static function registered(): array {
		return self::$registered;
	}

	/**
	 * Whether any registered provider rendered its own head this request.
	 *
	 * Page-agnostic, and it includes suppression strategies for completeness
	 * even though they always answer false. The commerce emitter wants
	 * emission_is_delegated() instead; this one exists for the non-commerce
	 * emitter, which is not asking "will someone enrich" but "is this page
	 * already described" (#690).
	 */
	public static function any_provider_emitting(): bool {
		foreach ( self::$registered as $strategy ) {
			if ( $strategy->is_emitting() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether another plugin is rendering our social tags for us this request.
	 *
	 * True when a registered strategy both enriches AND has observed its own
	 * seam run this request. Enrichment that did not also stand our block down
	 * would produce two sets of tags, which is the defect rather than the fix
	 * — but standing down for a rival that published nothing leaves the page
	 * with no tags at all, which is worse. Both halves are required.
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
	 * Register already-built strategies. Test-only.
	 *
	 * has_taken_over() is an observation, so a test that needs delegation to
	 * be true has to hand over a strategy whose seam has already run. There
	 * is no production path that wants this: init_for_slugs() builds its own.
	 *
	 * @param WC_AI_Storefront_Og_Strategy[] $strategies Prepared strategies.
	 */
	public static function register_for_test( array $strategies ): void {
		self::$registered = $strategies;
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
