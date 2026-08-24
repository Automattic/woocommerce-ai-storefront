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
	 * Replaced wholesale on every init(), never appended to — but init() runs
	 * once per PROCESS, not once per request, because the plugin instance is a
	 * singleton. Static state on a class that outlives a request is the shape
	 * #669 had to be careful about, and this one named the risk while not
	 * guarding it: the strategies' own `$observed` latches survived into the
	 * next request on a persistent worker. init_for_slugs() now registers a
	 * `wp_head:0` reset, the same guard
	 * WC_AI_Storefront_Rival_Seo_Description has had since #669 (#701 review).
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

		// Per REQUEST, which this method is not. It runs from the plugin's
		// singleton constructor, so under a persistent worker it runs on the
		// first request that worker serves and never again while the same
		// strategy objects answer every later one. Priority 0 puts this before
		// any provider's seam at wp_head:1 and before both readers at
		// wp_head:5, the placement Rival_Seo_Description settled on for the
		// same reason (#701 review).
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_head', array( __CLASS__, 'reset_latches' ), 0 );
		}
	}

	/**
	 * Clear every strategy's observation at the start of a request.
	 */
	public static function reset_latches(): void {
		foreach ( self::$registered as $strategy ) {
			$strategy->reset_observation();
		}
	}

	/**
	 * Test-only. The strategies registered for this request.
	 *
	 * No production caller. Sits beside reset() and register_for_test(), which
	 * label themselves the same way, because a class that mixes API and test
	 * seams has to say which is which (#701 review).
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
		return '' !== self::emitting_slug();
	}

	/**
	 * The detector slug of the first provider observed emitting, or ''.
	 *
	 * The registry knows exactly which strategy latched, and answering only
	 * bool threw that away: the gate's debug line went from naming a plugin a
	 * merchant could check on the Plugins screen to "an SEO plugin", which is
	 * an assertion about one request that nobody can reproduce (#701 review).
	 */
	public static function emitting_slug(): string {
		foreach ( self::$registered as $strategy ) {
			if ( $strategy->is_emitting() ) {
				return $strategy::slug();
			}
		}

		return '';
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
