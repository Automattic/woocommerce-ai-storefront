<?php
/**
 * All in One SEO Open Graph coexistence: correct its type, fill its gaps.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enrich All in One SEO's Open Graph through its two tag filters.
 *
 * AIOSEO hands both filters a flat, associative `property => value` map and
 * puts back whatever it is given. Measured (#676 spike,
 * `captures/aioseo-enrich/`): mutating `og:type`, adding `product:*` keys,
 * unsetting `article:*` keys and adding arbitrary properties all reached the
 * page unchanged. Its normalisation then does two useful things and nothing
 * harmful — it drops empty-string and NULL values, and it picks the attribute
 * from the key prefix, so `product:*` and `og:*` render as `property=` and
 * `twitter:*` as `name=` without being told.
 *
 * The one exception is a real one, and it is why has_taken_over() exists:
 * **neither filter fires on a product category.** AIOSEO emits no Open Graph
 * there at all. On that page type there is nothing to enrich and our own
 * block stays.
 */
class WC_AI_Storefront_Og_Strategy_Aioseo implements WC_AI_Storefront_Og_Strategy {

	/**
	 * Whether we are on a page this plugin describes.
	 *
	 * @var callable
	 */
	private $on_commerce_page;

	/**
	 * The commerce facts AIOSEO is missing.
	 *
	 * @var WC_AI_Storefront_Og_Commerce_Facts
	 */
	private WC_AI_Storefront_Og_Commerce_Facts $facts;

	/**
	 * @param WC_AI_Storefront_Og_Commerce_Facts|null $facts Injectable for tests.
	 */
	public function __construct( ?WC_AI_Storefront_Og_Commerce_Facts $facts = null ) {
		$this->facts = $facts ?? new WC_AI_Storefront_Og_Commerce_Facts();
	}

	/**
	 * The detector slug this strategy answers for.
	 */
	public static function slug(): string {
		return 'aioseo';
	}

	/**
	 * AIOSEO renders; we correct and extend what it renders.
	 */
	public static function mode(): string {
		return self::MODE_ENRICH;
	}

	/**
	 * On every commerce page EXCEPT a product category.
	 *
	 * AIOSEO emits no Open Graph on a product category and neither of its
	 * filters fires there, so there is nothing to enrich. Standing our own
	 * block down on the strength of the mode alone would leave that page with
	 * no social tags at all.
	 */
	public function has_taken_over(): bool {
		if ( null === $this->on_commerce_page || ! ( $this->on_commerce_page )() ) {
			return false;
		}

		return ! ( function_exists( 'is_product_category' ) && is_product_category() );
	}

	/**
	 * @param callable $on_commerce_page Resolved at hook time, not here.
	 */
	public function init( callable $on_commerce_page ): void {
		$this->on_commerce_page = $on_commerce_page;
		add_filter( 'aioseo_facebook_tags', array( $this, 'filter_facebook_tags' ) );
		add_filter( 'aioseo_twitter_tags', array( $this, 'filter_twitter_tags' ) );
	}

	/**
	 * Correct the type, drop the article vocabulary, add the commerce facts.
	 *
	 * @param mixed $tags Property => value.
	 * @return mixed Unchanged when this is not ours to touch.
	 */
	public function filter_facebook_tags( $tags ) {
		if ( ! is_array( $tags ) || ! $this->has_taken_over() ) {
			return $tags;
		}

		$tags['og:type'] = ( function_exists( 'is_product' ) && is_product() ) ? 'product' : 'website';

		foreach ( array_keys( $tags ) as $key ) {
			// `article:section`, `article:tag`, `article:published_time`,
			// `article:modified_time`, `article:publisher` and
			// `article:author` all arrive on a product. They describe
			// editorial content, and the type above is no longer `article`.
			if ( is_string( $key ) && 0 === strpos( $key, 'article:' ) ) {
				unset( $tags[ $key ] );
			}
		}

		// Ours win outright. AIOSEO supplies none of these on any page type
		// measured, so there is nothing here to preserve, and a merge would
		// leave the source of a wrong price ambiguous.
		return array_merge( $tags, $this->facts->properties() );
	}

	/**
	 * Add the price and availability rows to AIOSEO's Twitter card.
	 *
	 * Unlike Yoast and Rank Math, AIOSEO has no numbered enhanced-data
	 * pipeline: this filter takes the same flat map, so the raw
	 * `twitter:label1` keys are the right shape here. Its input carries
	 * `twitter:card`, `twitter:site`, `twitter:title`, `twitter:description`
	 * and `twitter:creator` and no label rows at all, so nothing collides.
	 *
	 * @param mixed $tags Property => value.
	 * @return mixed Unchanged when this is not ours to touch.
	 */
	public function filter_twitter_tags( $tags ) {
		if ( ! is_array( $tags ) || ! $this->has_taken_over() ) {
			return $tags;
		}

		// twitter:card is left alone deliberately. AIOSEO manages its own
		// image, so it is the only one that knows whether a large card has
		// anything to put in it (#683).
		return array_merge( $tags, $this->facts->twitter_tags() );
	}
}
