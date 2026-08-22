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
 * 3. Rank Math's own tags are filtered strictly BEFORE the
 *    `rank_math/opengraph/facebook` action fires.
 *
 * Fact 3 is what makes this exact. Each per-tag filter records that Rank Math
 * emitted the property and substitutes our value; the action, running after
 * all of them, adds only the properties that were never recorded. Every
 * property ends up on the page once, carrying our value, whether or not Rank
 * Math had one of its own.
 */
class WC_AI_Storefront_Og_Strategy_Rankmath implements WC_AI_Storefront_Og_Strategy {

	/**
	 * Whether we are on a page this plugin describes.
	 *
	 * @var callable
	 */
	private $on_commerce_page;

	/**
	 * The commerce facts Rank Math is missing.
	 *
	 * @var WC_AI_Storefront_Og_Commerce_Facts
	 */
	private WC_AI_Storefront_Og_Commerce_Facts $facts;

	/**
	 * Properties Rank Math emitted itself this request.
	 *
	 * Request-scoped and reset by init(), never accumulated: #669 shipped a
	 * latch of this exact shape that survived between requests in a
	 * persistent worker.
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

		// Priority 99: this must run after every per-tag filter above, and
		// fact 3 guarantees the action itself already does.
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
		if ( ! $this->has_taken_over() ) {
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
		if ( ! $this->has_taken_over() ) {
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
		if ( ! $this->has_taken_over() || ! is_object( $og ) || ! method_exists( $og, 'tag' ) ) {
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
		if ( ! is_array( $data ) || ! $this->has_taken_over() ) {
			return $data;
		}

		foreach ( $this->facts->twitter_rows() as $label => $value ) {
			// Theirs wins; we only fill what is missing.
			if ( isset( $data[ $label ] ) ) {
				continue;
			}
			$data[ $label ] = $value;
		}

		return $data;
	}
}
