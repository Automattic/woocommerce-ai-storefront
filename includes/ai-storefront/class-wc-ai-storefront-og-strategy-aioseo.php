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
 * AIOSEO hands both filters a flat, associative `property => value` map.
 * Measured (#676 spike, `captures/aioseo-enrich/`): mutating `og:type`,
 * adding `product:*` keys, unsetting `article:*` keys and adding arbitrary
 * properties all reached the page.
 *
 * Two things about what happens after the filter, both easy to get wrong:
 *
 * - It is NOT "puts back whatever it is given". `array_filter()` runs on the
 *   result with no callback, so every falsy value is dropped: '', null, 0,
 *   '0', false, []. A genuinely free product's price of "0" would vanish;
 *   WC_AI_Storefront_Og_Commerce_Facts sends "0.00" for that reason.
 * - The attribute is NOT chosen from the key prefix. Two hardcoded loops
 *   print everything from the facebook map as `property=` and everything from
 *   the twitter map as `name=`. The outcome matches a prefix rule for the
 *   keys we send, but a `twitter:*` key placed in the facebook map would
 *   render as `property=`.
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
	 * Null until init() assigns it, which is why every reader guards.
	 *
	 * @var callable|null
	 */
	private $on_commerce_page;

	/**
	 * Whether AIOSEO's Open Graph output actually ran this request.
	 *
	 * Set from inside filter_facebook_tags(), which fires only when AIOSEO is
	 * rendering. Its `isAllowed()` is an allowlist, so this is false on a
	 * product category by construction, as well as whenever the merchant has
	 * turned its Open Graph off.
	 *
	 * @var bool
	 */
	private bool $observed = false;

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
		// Observed, not predicted. The product-category carve-out used to be
		// spelled out here as a page-type exception; it no longer needs to be,
		// because AIOSEO's filters simply do not fire there and the latch
		// stays false. That also covers the case the hand-written exception
		// could not: the merchant switching AIOSEO's Open Graph off, where
		// neither filter fires on ANY page type (#676 review).
		return $this->observed && $this->should_enrich();
	}

	/**
	 * Whether this request is one we describe. The filters' own gate.
	 *
	 * Separate from has_taken_over() because the filters run BEFORE the latch
	 * they set; asking the observed question inside them would never be true.
	 */
	private function should_enrich(): bool {
		return null !== $this->on_commerce_page && ( $this->on_commerce_page )();
	}

	/**
	 * @param callable $on_commerce_page Resolved at hook time, not here.
	 */
	public function init( callable $on_commerce_page ): void {
		$this->on_commerce_page = $on_commerce_page;
		// Priority 20, not the default. AIOSEO Pro's own WooCommerce
		// integration hooks these same two filters, and at equal priority the
		// winner is registration order — which is exactly how the Yoast addon
		// caught us out (see that strategy's init()). Running after it means
		// our values win the array_merge below rather than being overwritten.
		add_filter( 'aioseo_facebook_tags', array( $this, 'filter_facebook_tags' ), 20 );
		add_filter( 'aioseo_twitter_tags', array( $this, 'filter_twitter_tags' ), 20 );
	}

	/**
	 * Correct the type, drop the article vocabulary, add the commerce facts.
	 *
	 * @param mixed $tags Property => value.
	 * @return mixed Unchanged when this is not ours to touch.
	 */
	public function filter_facebook_tags( $tags ) {
		if ( ! is_array( $tags ) || ! $this->should_enrich() ) {
			return $tags;
		}

		// Reaching here means AIOSEO is rendering Open Graph for a page we
		// describe. That is the fact has_taken_over() reports.
		$this->observed = true;

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
	 * `twitter:label1` keys are the right shape here.
	 *
	 * It does emit label rows of its own, though — the fixture simply had the
	 * option off. With "additional data" enabled it appends
	 * `twitter:label{n}` / `twitter:data{n}` on any singular. Merging ours
	 * over the top would overwrite whatever it put in those numbered slots,
	 * so we only fill slots it left empty.
	 *
	 * @param mixed $tags Property => value.
	 * @return mixed Unchanged when this is not ours to touch.
	 */
	public function filter_twitter_tags( $tags ) {
		if ( ! is_array( $tags ) || ! $this->should_enrich() ) {
			return $tags;
		}

		// twitter:card is left alone deliberately. AIOSEO manages its own
		// image, so it is the only one that knows whether a large card has
		// anything to put in it (#683).
		foreach ( $this->facts->twitter_tags() as $key => $value ) {
			// Never overwrite a numbered slot AIOSEO already filled: with its
			// "additional data" option on it writes these itself, and an
			// array_merge would silently replace its rows with ours.
			if ( isset( $tags[ $key ] ) ) {
				continue;
			}
			$tags[ $key ] = $value;
		}

		return $tags;
	}
}
