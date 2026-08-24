<?php
/**
 * Contract for per-plugin Open Graph coexistence strategies.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * How this plugin coexists with one other SEO plugin's social metadata.
 *
 * Every SEO plugin ships its own `og:*` and `twitter:*` tags, so on a commerce
 * page with one active the reader gets two of each. Measured across five
 * providers and five page types (#676 spike), there is no single correct
 * response: some plugins expose extension points we can enrich through, and
 * some only expose callbacks we can remove. One implementation per plugin,
 * because the differences are the whole problem.
 *
 * Implementations register hooks and nothing else. They never emit tags: on a
 * suppression path WC_AI_Storefront_Meta_Tags already emits ours, and on an
 * enrichment path the other plugin does its own rendering.
 */
interface WC_AI_Storefront_Og_Strategy {

	/**
	 * The other plugin's social tags go; ours are the ones on the page.
	 *
	 * For plugins that expose no way to correct their Open Graph, so the only
	 * route to one set of tags is to remove theirs.
	 */
	public const MODE_SUPPRESS = 'suppress';

	/**
	 * The other plugin renders; we correct and extend what it renders.
	 *
	 * WC_AI_Storefront_Meta_Tags stands its own Open Graph and Twitter block
	 * down for the request when any active strategy reports this, or the
	 * enrichment would be a second set of tags rather than a replacement.
	 */
	public const MODE_ENRICH = 'enrich';

	/**
	 * Which of the two this strategy is. One of the MODE_* constants.
	 */
	public static function mode(): string;

	/**
	 * Whether this strategy is rendering our tags for us, for THIS request.
	 *
	 * Must be an OBSERVATION, not a prediction. Presence of the other plugin
	 * is not evidence that it emitted anything, and the gap is not academic:
	 * Rank Math defines its version constant at load but publishes nothing
	 * until its setup wizard is finished, and both Yoast and All in One SEO
	 * ship an Open Graph switch that merchants turn off precisely when
	 * another plugin is handling social. Answering from page type alone in
	 * any of those states stands our own block down against a rival that
	 * publishes nothing, and the page ships with no social tags at all —
	 * worse than the duplication this feature exists to remove (#676 review).
	 *
	 * So implementations latch on their own seam actually running, and answer
	 * true only once they have seen it. The timing allows it: every rival
	 * emits at `wp_head:1` and WC_AI_Storefront_Meta_Tags reads this at
	 * `wp_head:5`, so by the time the question is asked the answer is a fact.
	 * This is the shape WC_AI_Storefront_Rival_Seo_Description already uses
	 * for the same class of decision about the description.
	 *
	 * Falling back to printing our own block is the safe direction: the worst
	 * case is the duplication that shipped before this feature, which is
	 * survivable. Zero tags is not.
	 *
	 * Suppression strategies always answer false: there the other plugin's
	 * tags are the ones being removed, so ours are the only ones left.
	 */
	public function has_taken_over(): bool;

	/**
	 * Whether this provider rendered its own head at all this request.
	 *
	 * Page-agnostic, which is the whole difference from has_taken_over(): that
	 * one ANDs a commerce-page check because it answers "should the commerce
	 * emitter stand down". This one answers "did anything emit here", which is
	 * what the non-commerce emitter needs on a post or a page (#690).
	 *
	 * A suppression strategy answers false, as with has_taken_over(): it does
	 * not suppress off commerce pages, so a provider it would have removed is
	 * still emitting, and the description observer is what sees that.
	 */
	public function is_emitting(): bool;

	/**
	 * Clear this request's observation.
	 *
	 * Called from `wp_head:0`, before any provider's seam can fire and before
	 * either reader at `wp_head:5`. WC_AI_Storefront_Rival_Seo_Description has
	 * done this since #669 and its docblock explains why; the strategies did
	 * not, and could not get away with it once is_emitting() existed.
	 *
	 * `$observed` only ever goes false to true, and the strategy objects live
	 * in a static registry built once per PROCESS, not once per request:
	 * WC_AI_Storefront::get_instance() is a singleton, so init_components()
	 * and therefore Og_Strategies::init() run on a worker's first request and
	 * never again. Under FrankenPHP or RoadRunner one product view would
	 * otherwise latch a strategy and silence the non-commerce fallback on
	 * every later post that worker served (#701 review).
	 *
	 * has_taken_over() was masking this: it ANDs a commerce-page check, so a
	 * stale latch only mattered on another commerce page, where the seam
	 * re-fired and re-latched honestly. is_emitting() is page-agnostic by
	 * design, which removes exactly the term that made the gap survivable.
	 */
	public function reset_observation(): void;

	/**
	 * The detector slug this strategy answers for.
	 *
	 * Must match a `slug` that WC_AI_Storefront_Seo_Plugin_Detector::detect()
	 * reports, or the dispatcher never selects it. Nothing enforces that at
	 * runtime, so each implementation pins it with a test.
	 */
	public static function slug(): string;

	/**
	 * Register this strategy's hooks. Called once per request.
	 *
	 * @param callable $on_commerce_page Returns true when this plugin is
	 *                                   emitting its own tags for the current
	 *                                   request. Resolve it at hook time, not
	 *                                   here: init() runs long before the
	 *                                   query is, so the page type is not yet
	 *                                   knowable.
	 */
	public function init( callable $on_commerce_page ): void;
}
