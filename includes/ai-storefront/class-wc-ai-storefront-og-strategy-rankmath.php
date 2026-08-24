<?php
/**
 * Rank Math Open Graph coexistence: correct its type, fill its gaps.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enrich Rank Math's Open Graph through its per-tag filters and its action.
 *
 * Rank Math is the closest of the five to correct already. On a singular
 * product it emits `og:type=product`, a full image block including the only
 * `og:image:alt` any provider produced, and Twitter label rows carrying price
 * and availability. What it misses (#676 spike, measured):
 *
 * - `product:price:amount` and `product:price:currency` on a VARIABLE
 *   product. Deliberate on its part; a simple product gets both.
 * - `og:availability`, the Pinterest spelling, on every product.
 * - `og:type=product` anywhere but a singular product. Category, shop and
 *   search all get `article`.
 *
 * Three measured facts shape how this is done, and none of them is guessable:
 *
 * 1. A per-tag filter returning `''` removes the tag entirely. It does NOT
 *    emit `content=""`, so substitution through the filter is safe.
 * 2. A tag added with `$og->tag()` passes through the SAME per-tag filter, so
 *    blanking a property and then adding it back cancels out.
 * 3. Rank Math's own emitters are callbacks ON that same action, not a pass
 *    before it. Verified against seo-by-rank-math 1.0.276: locale 1, type 5,
 *    title 10, description 11, url 12, site_name 13, website 14,
 *    article_author 15, tags 16, category 17, publish_date 19, site_owner 20,
 *    image 30, the WooCommerce module's og_enhancement 50, and the Schema
 *    module's add_schema_tags 90. Each per-tag filter fires the instant the
 *    callback currently running calls `tag()`.
 *
 * So priority 99 is not incidental, it is the whole mechanism: 99 is above
 * Rank Math's highest, 90. Below 90 the schema tags are missed, below 50 the
 * WooCommerce module's price and availability are, and every property lands
 * twice. Each per-tag filter records that Rank Math emitted the property and
 * substitutes our value; the action, running after all of them, adds only
 * what was never recorded. Every property ends up on the page once, carrying
 * our value, whether or not Rank Math had one.
 *
 * Rank Math PRO is closed source and could register above 90. 99 is safe
 * against the free plugin, not a guarantee against PRO.
 */
class WC_AI_Storefront_Og_Strategy_Rankmath implements WC_AI_Storefront_Og_Strategy {

	/**
	 * Whether we are on a page this plugin describes.
	 *
	 * Null until init() assigns it, which is why every reader guards.
	 *
	 * @var callable|null
	 */
	private $on_commerce_page;

	/**
	 * Whether Rank Math's Open Graph output actually ran this request.
	 *
	 * Set from inside add_missing_tags(), on the action Rank Math fires only
	 * when it is rendering. Rank Math defines RANK_MATH_VERSION at load but
	 * publishes nothing until its setup wizard is finished, so presence alone
	 * is no evidence at all (#676 review).
	 *
	 * @var bool
	 */
	private bool $observed = false;

	/**
	 * The commerce facts Rank Math is missing.
	 *
	 * @var WC_AI_Storefront_Og_Commerce_Facts
	 */
	private WC_AI_Storefront_Og_Commerce_Facts $facts;

	/**
	 * Properties Rank Math emitted itself this request.
	 *
	 * Request-scoped by construction: `for_slugs()` builds a fresh strategy on
	 * every load, so this is a new array each request. The `init()` reset is
	 * belt and braces for a caller that re-inits one instance.
	 *
	 * @var array<string,true>
	 */
	private array $seen = array();

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
		return 'rankmath';
	}

	/**
	 * Rank Math renders; we correct and extend what it renders.
	 */
	public static function mode(): string {
		return self::MODE_ENRICH;
	}

	/**
	 * On every commerce page: Rank Math emits Open Graph on all five.
	 */
	public function has_taken_over(): bool {
		// Observed, not predicted. An activated but unconfigured Rank Math is
		// one wizard step away and emits nothing; standing our block down for
		// it leaves the page with no social tags at all.
		return $this->observed && $this->should_enrich();
	}

	/**
	 * Clear this request's observation. See the interface for why.
	 *
	 * `$seen` goes with it: it records which properties Rank Math emitted this
	 * request, and a stale entry would make add_missing_tags() skip a property
	 * Rank Math has not actually emitted yet.
	 */
	public function reset_observation(): void {
		$this->observed = false;
		$this->seen     = array();
	}

	/**
	 * Whether this provider rendered its own head at all this request.
	 */
	public function is_emitting(): bool {
		return $this->observed;
	}

	/**
	 * Whether this request is one we describe. The hooks' own gate.
	 *
	 * Separate from has_taken_over() because the hooks run BEFORE the latch
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
		$this->seen             = array();

		// Priority 20 so a site filtering the type at the default priority
		// still wins over Rank Math and loses to nobody unexpectedly.
		add_filter( 'rank_math/opengraph/facebook/og_type', array( $this, 'filter_type' ), 20 );

		// The vocabulary, not properties(): init() runs before the query is
		// resolved, so properties() is empty here and would register nothing.
		foreach ( WC_AI_Storefront_Og_Commerce_Facts::OWNED_PROPERTIES as $property ) {
			add_filter(
				'rank_math/opengraph/facebook/' . self::filter_slug( $property ),
				function ( $value ) use ( $property ) {
					return $this->filter_property( $property, $value );
				},
				20
			);
		}

		// Priority 99, and the margin is nine. Rank Math's own emitters are
		// callbacks on this same action, the highest being the Schema
		// module's at 90. Lowering this does not degrade gracefully: it
		// silently doubles properties.
		add_action( 'rank_math/opengraph/facebook', array( $this, 'add_missing_tags' ), 99 );

		// The twitter:label/data rows come from a numbered array here too,
		// exactly as they do in Yoast. Emitting raw twitter:label1 beside it
		// would put two different label1 rows on the page.
		add_filter( 'rank_math/opengraph/slack_enhanced_data', array( $this, 'filter_slack_data' ), 20 );
	}

	/**
	 * Rank Math's per-tag filter name for an Open Graph property.
	 *
	 * Confirmed by observation: the property with `:` replaced by `_`.
	 * `product:availability` becomes `product_availability`.
	 *
	 * @param string $property Open Graph property name.
	 */
	private static function filter_slug( string $property ): string {
		return str_replace( ':', '_', $property );
	}

	/**
	 * Replace Rank Math's inherited `article` with the type the page is.
	 *
	 * @param mixed $type Whatever Rank Math produced.
	 * @return mixed Unchanged off commerce pages.
	 */
	public function filter_type( $type ) {
		if ( ! $this->should_enrich() ) {
			return $type;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}

		return 'website';
	}

	/**
	 * Substitute our value for a property Rank Math is emitting, and note it.
	 *
	 * The note is what add_missing_tags() reads: a property that reached this
	 * method is one Rank Math had, so the action must not add it again.
	 *
	 * @param string $property Open Graph property name.
	 * @param mixed  $value    Rank Math's value.
	 * @return mixed Ours, or the original off a commerce page.
	 */
	public function filter_property( string $property, $value ) {
		if ( ! $this->should_enrich() ) {
			return $value;
		}

		$ours = $this->facts->properties();
		if ( ! isset( $ours[ $property ] ) ) {
			return $value;
		}

		$this->seen[ $property ] = true;

		return $ours[ $property ];
	}

	/**
	 * Add the commerce properties Rank Math did not emit.
	 *
	 * @param mixed $og RankMath\OpenGraph\Facebook.
	 */
	public function add_missing_tags( $og ): void {
		if ( ! is_object( $og ) ) {
			return;
		}

		if ( ! method_exists( $og, 'tag' ) ) {
			// Reaching the action with an object that is not Rank Math's
			// OpenGraph is either a third party firing our hook, or Rank Math
			// having changed the shape we integrate against. The second is an
			// integration break and must NOT leave has_taken_over() true, or
			// we stand down having added nothing.
			WC_AI_Storefront_Logger::debug(
				'Open Graph: rank_math/opengraph/facebook passed a %s, which has no tag(). Leaving Rank Math alone.',
				get_class( $og )
			);

			return;
		}

		// Latched BEFORE the commerce check (#690). This action fires only
		// when Rank Math is rendering Open Graph, so reaching here proves
		// emission on any page type — including a post, where Rank Math with
		// an unfinished setup wizard emits nothing at all and never gets here.
		$this->observed = true;

		if ( ! $this->should_enrich() ) {
			return;
		}

		foreach ( $this->facts->properties() as $property => $value ) {
			if ( isset( $this->seen[ $property ] ) ) {
				continue;
			}
			$og->tag( $property, $value );
		}
	}

	/**
	 * Fill the price row in Rank Math's numbered Twitter data.
	 *
	 * @param mixed $data Label => value.
	 * @return mixed Unchanged off commerce pages.
	 */
	public function filter_slack_data( $data ) {
		if ( ! is_array( $data ) || ! $this->should_enrich() ) {
			return $data;
		}

		foreach ( $this->facts->twitter_rows() as $label => $value ) {
			// Comparing values as well as labels: both sides translate
			// "Price" and "Availability" in their own text domain, so the
			// keys match only in English (#676 review).
			if ( isset( $data[ $label ] ) || in_array( $value, $data, true ) ) {
				continue;
			}
			$data[ $label ] = $value;
		}

		return $data;
	}
}
