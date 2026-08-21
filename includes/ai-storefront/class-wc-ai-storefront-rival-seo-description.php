<?php
/**
 * Observes whether another SEO plugin is emitting its own meta description.
 *
 * Pure observation, no gating: every callback here returns its input
 * completely unchanged, so hooking it can never alter what another SEO
 * plugin renders. `is_emitting()` becomes true once any of the four rival
 * filters has carried a non-empty string during the current request. That
 * is the signal a stand-down can act on — this class only produces it;
 * nothing reads it yet (#669, task 1 of 3; task 2 wires the stand-down).
 *
 * Zero runtime dependency: every filter is hooked unconditionally, whether
 * or not the plugin that owns it is installed. A filter nobody fires is
 * simply never called, so there is nothing here to guard with
 * `class_exists()`.
 *
 * Every constraint documented below was measured against real installs of
 * Yoast, Rank Math, SEOPress and All in One SEO, not reasoned from their
 * documentation. See `.claude/tmp/artifacts/669/GROUND-TRUTH.md` for the
 * full verification spike (101 captured <head> responses) both docblocks
 * below cite.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Observes the four `<meta name="description">` filters rival SEO plugins use.
 */
final class WC_AI_Storefront_Rival_Seo_Description {

	/**
	 * wp_head-time filters other SEO plugins use to supply their own meta
	 * description. Every one of the four fires on every commerce page type
	 * checked (GROUND-TRUTH.md, #669 spike); how many times per request
	 * differs per plugin — see observe() for why that count matters.
	 *
	 * @var string[]
	 */
	private const FILTERS = array(
		'wpseo_metadesc',                 // Yoast SEO (free core and the paid WooCommerce SEO addon). Fires once.
		'rank_math/frontend/description', // Rank Math. Fires once.
		'seopress_titles_desc',           // SEOPress. Fires 8-12 times per request.
		'aioseo_description',             // All in One SEO. Fires twice; the second firing is always empty.
	);

	/**
	 * First non-empty value any rival filter has carried this request, or
	 * null when none has (yet, or at all).
	 *
	 * @var string|null
	 */
	private static ?string $observed_description = null;

	/**
	 * Hook all four rival description filters.
	 *
	 * Registered at PHP_INT_MAX, not a default priority — measured, not
	 * reasoned (GROUND-TRUTH.md, #669 spike against real installs). With
	 * the paid Yoast WooCommerce SEO addon active, the same request gives:
	 *
	 *   FILTER=wpseo_metadesc [EARLY p5]   | value=
	 *   FILTER=wpseo_metadesc [LATE  pMAX] | value=ZZ669 short description...
	 *
	 * The addon supplies the value ABOVE priority 5, which is where this
	 * plugin's own metadata emitter (WC_AI_Storefront_Meta_Tags) renders.
	 * An observer hooked at a default priority reads the value as empty and
	 * never triggers a stand-down, for exactly the configuration this
	 * feature most needs to handle. PHP_INT_MAX is the only priority
	 * guaranteed to run after every other plugin's callback, whatever that
	 * plugin's own priority turns out to be.
	 */
	public static function init(): void {
		foreach ( self::FILTERS as $filter ) {
			add_filter( $filter, array( __CLASS__, 'observe' ), PHP_INT_MAX );
		}
	}

	/**
	 * Filter callback shared by all four rival filters. Records the first
	 * non-empty value seen and returns its input completely unchanged.
	 *
	 * Keeps the FIRST non-empty value, never the last — measured, not
	 * reasoned (GROUND-TRUTH.md, #669 spike). SEOPress fires
	 * `seopress_titles_desc` 8 to 12 times per request; All in One SEO
	 * fires `aioseo_description` twice with the second call always empty.
	 * An observer that kept the last value seen would read SEOPress's or
	 * AIOSEO's final, empty call and conclude nothing was emitted -
	 * backwards in both cases. An empty firing means "no tag will be
	 * emitted", reliably, in every case measured, including SEOPress on
	 * the shop archive (8 empty firings, no tag) and free Yoast with
	 * nothing authored (1 empty firing, no tag). That reliability is what
	 * makes "first non-empty" a trustworthy signal rather than a guess.
	 *
	 * OBSERVATION ONLY. This callback must never alter what another SEO
	 * plugin renders on a live store - it always returns $value exactly as
	 * received, whether or not it was recorded.
	 *
	 * @param mixed $value Whatever the filter passed. A real firing carries
	 *                      a string; a non-string is treated as no value
	 *                      and never recorded, but is still returned
	 *                      unchanged.
	 * @return mixed $value, byte for byte unchanged.
	 */
	public static function observe( $value ) {
		if ( null === self::$observed_description && is_string( $value ) && '' !== $value ) {
			self::$observed_description = $value;
		}
		return $value;
	}

	/**
	 * Whether a rival SEO plugin has emitted a meta description this request.
	 *
	 * True once any of the four filters has carried a non-empty string, at
	 * any point since the last reset(). A reliable "no tag" predictor: an
	 * empty firing corresponds exactly to "no tag emitted" in every case
	 * measured (GROUND-TRUTH.md, #669 spike), never a false empty.
	 */
	public static function is_emitting(): bool {
		return null !== self::$observed_description;
	}

	/**
	 * Test seam: clear the recorded value.
	 *
	 * Not called in production. PHPUnit runs every test file in one
	 * process (`phpunit.xml.dist` sets no `processIsolation`), so this
	 * per-request static would otherwise leak into whichever test file
	 * PHPUnit happens to run next. Call from setUp() and tearDown() in any
	 * test that touches this class.
	 */
	public static function reset(): void {
		self::$observed_description = null;
	}
}
