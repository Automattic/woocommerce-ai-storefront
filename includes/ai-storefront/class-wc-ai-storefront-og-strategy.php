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
	 * Asked per request rather than answered by mode() alone, because
	 * "enriches" is not the same as "enriches everywhere". All in One SEO
	 * emits no Open Graph at all on a product category — no tags, and neither
	 * of its filters fires — so on that page type there is nothing to enrich
	 * and standing our own block down would leave the page with no social
	 * tags whatsoever (#676 spike).
	 *
	 * Suppression strategies always answer false: there the other plugin's
	 * tags are the ones being removed, so ours are the only ones left.
	 */
	public function has_taken_over(): bool;

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
