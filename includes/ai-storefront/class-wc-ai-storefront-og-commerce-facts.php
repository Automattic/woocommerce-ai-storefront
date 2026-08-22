<?php
/**
 * The commerce facts an SEO plugin's Open Graph is missing.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * What this plugin would say about the current page, minus what is not ours to say.
 *
 * Every enrichment strategy needs the same answer — Yoast, Rank Math and All
 * in One SEO each fill the same gaps through a different seam — so the answer
 * is computed once, here, from WC_AI_Storefront_Meta_Tags. Reimplementing it
 * per strategy would let three copies of the vocabulary drift apart, and the
 * one that drifted would be the one nobody has a live capture for.
 *
 * What is deliberately NOT included: `og:type` (each strategy corrects that
 * through its own type seam) and the page-description properties `og:title`,
 * `og:description`, `og:url`, `og:site_name`, `og:image` and `og:locale`.
 * Those belong to the other plugin: the merchant may have authored them in
 * its fields, and #668 settled that authored text wins.
 */
class WC_AI_Storefront_Og_Commerce_Facts {

	/**
	 * Every property this class can ever own.
	 *
	 * A strategy that needs to register a hook per property has to do it at
	 * init(), which runs long before the query is resolved — so properties()
	 * is empty then and cannot be used for registration. This list is the
	 * vocabulary; properties() is the values.
	 *
	 * @var string[]
	 */
	public const OWNED_PROPERTIES = array(
		'product:price:amount',
		'product:price:currency',
		'product:availability',
		'product:condition',
		'og:availability',
	);

	/**
	 * Builds the tags this plugin would emit on its own.
	 *
	 * @var WC_AI_Storefront_Meta_Tags
	 */
	private WC_AI_Storefront_Meta_Tags $tags;

	/**
	 * Memoised per instance; a strategy asks more than once per request.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $all = null;

	/**
	 * @param WC_AI_Storefront_Meta_Tags|null $tags Injectable for tests.
	 */
	public function __construct( ?WC_AI_Storefront_Meta_Tags $tags = null ) {
		$this->tags = $tags ?? new WC_AI_Storefront_Meta_Tags();
	}

	/**
	 * The commerce properties, keyed by Open Graph property name.
	 *
	 * @return array<string,string> Empty off a product page.
	 */
	public function properties(): array {
		$properties = array();

		foreach ( $this->all() as $key => $value ) {
			if ( '' === $value || ! self::is_commerce_property( $key ) ) {
				continue;
			}
			$properties[ $key ] = self::survivable( $key, $value );
		}

		return $properties;
	}

	/**
	 * The Twitter label/data rows, as raw `twitter:*` keys.
	 *
	 * For seams that take a flat tag map, such as All in One SEO's
	 * `aioseo_twitter_tags`.
	 *
	 * @return array<string,string> Empty off a product page.
	 */
	public function twitter_tags(): array {
		$tags = array();

		foreach ( $this->all() as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( 0 !== strpos( $key, 'twitter:label' ) && 0 !== strpos( $key, 'twitter:data' ) ) {
				continue;
			}
			$tags[ $key ] = $value;
		}

		return $tags;
	}

	/**
	 * The same rows as label => value.
	 *
	 * Yoast and Rank Math both render these from a numbered array rather than
	 * from a tag map — Yoast's Slack Enhanced_Data_Presenter and Rank Math's
	 * `rank_math/opengraph/slack_enhanced_data`. Emitting raw `twitter:label1`
	 * beside either of them produces two different label1 rows on one page
	 * (#676, measured), so those seams take this shape instead.
	 *
	 * @return array<string,string> Label => value. Empty off a product page.
	 */
	public function twitter_rows(): array {
		$rows = array();
		$all  = $this->all();

		foreach ( array( 'twitter:label1', 'twitter:label2' ) as $label_key ) {
			$label = (string) ( $all[ $label_key ] ?? '' );
			$value = (string) ( $all[ str_replace( 'label', 'data', $label_key ) ] ?? '' );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$rows[ $label ] = $value;
		}

		return $rows;
	}

	/**
	 * A value that survives the rival plugins' own falsy filters.
	 *
	 * Both of them discard a falsy value on the way out, and a genuinely free
	 * product's price is the string "0":
	 *
	 * - Rank Math's `OpenGraph::tag()` returns early on `empty( $content )`
	 *   before printing, so "0" never reaches the page.
	 * - All in One SEO runs `array_filter()` with no callback over its tag
	 *   map, which drops '', null, 0, '0', false and [].
	 *
	 * A free product would therefore lose its price on both, silently — the
	 * same class of bug as #658 and #679, where free and unpriced products
	 * were treated alike. "0.00" is the same number, is not falsy, and is
	 * what a shopper is shown anyway.
	 *
	 * @param string $key   Property name.
	 * @param string $value Its value.
	 */
	private static function survivable( string $key, string $value ): string {
		if ( '0' !== $value ) {
			return $value;
		}

		return 'product:price:amount' === $key ? '0.00' : $value;
	}

	/**
	 * Whether a property is a commerce fact rather than a page description.
	 *
	 * @param string $key Open Graph property name.
	 */
	public static function is_commerce_property( string $key ): bool {
		// The constant is the single authority, not a prefix rule that merely
		// happens to agree with it. `build_og_tags()` ends in the public
		// `wc_ai_storefront_og_tags` filter, so a third party can add
		// `product:brand` — which passes a `product:` prefix test and is
		// absent from the list. Rank Math is the one strategy that reads both:
		// it registers per-tag filters from the list, so a key only
		// properties() knows about gets no filter, never lands in $seen, and
		// add_missing_tags() adds it beside Rank Math's own. That is this
		// feature's own defect, reintroduced through our own extension point
		// (#676 review).
		return in_array( $key, self::OWNED_PROPERTIES, true );
	}

	/**
	 * Every tag this plugin would emit for the current request.
	 *
	 * Archives return nothing: they carry no commerce facts, so every key
	 * build_archive_og_tags() produces would be filtered away here anyway, and
	 * building it would run the archive image resolver — including its product
	 * query — once per archive render for values nothing reads.
	 *
	 * @return array<string,string>
	 */
	private function all(): array {
		if ( null !== $this->all ) {
			return $this->all;
		}

		$product = ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) )
			? wc_get_product( get_queried_object_id() )
			: null;

		if ( ! $product ) {
			// Deliberately NOT memoised. A strategy's init() runs before the
			// query is resolved, so the first call to this method answers
			// "not a product" for a request that turns out to be one.
			// Caching that emptied every downstream map (#676, caught live).
			return array();
		}

		$og        = $this->tags->build_og_tags( $product );
		$this->all = array_map( 'strval', array_merge( $og, $this->tags->build_twitter_tags( $og, $product ) ) );

		return $this->all;
	}
}
