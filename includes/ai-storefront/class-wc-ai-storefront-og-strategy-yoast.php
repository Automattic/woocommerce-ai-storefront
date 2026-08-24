<?php
/**
 * Yoast SEO Open Graph coexistence: correct its type, fill its gaps.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enrich Yoast's Open Graph rather than removing it.
 *
 * Yoast exposes two extension points that reach the page, and the paid
 * WooCommerce addon uses both of them itself, so this is the sanctioned way in
 * rather than a discovered one. Enriching also keeps whatever the merchant
 * authored in Yoast's own fields, which suppression would throw away.
 *
 * What Yoast gets wrong, measured (#676 spike, `captures/yoast-base/` and
 * `captures/yoast-woo/`):
 *
 * | page              | free Yoast          | with the WooCommerce addon        |
 * |-------------------|---------------------|-----------------------------------|
 * | simple product    | `article`, no facts | complete: type, price, all facts  |
 * | variable product  | `article`, no facts | type and facts, but NO price      |
 * | product category  | `article`           | `article`                         |
 * | shop archive      | `article`           | `article` + `article:modified_time` |
 * | product search    | `article`           | `article`                         |
 *
 * `article` is not a considered choice on Yoast's part. Its base presentation
 * returns `website` and three subclasses hardcode `return 'article';` — post
 * type, term archive and search result page — with no conditional. So a
 * product is an article by inheritance, and so is a category listing, which
 * was never an article under any version of the Open Graph vocabulary.
 *
 * The addon overrides exactly one cell of that, via `wpseo_opengraph_type` on
 * singular products, and never touches the archives or the `article:*`
 * presenters. That is why `article:modified_time` still sits next to
 * `og:type=product` on a product page with the addon active.
 */
class WC_AI_Storefront_Og_Strategy_Yoast implements WC_AI_Storefront_Og_Strategy {

	/**
	 * Yoast's own presenter base. Absent means Yoast is not really here.
	 */
	private const PRESENTER_BASE = 'Yoast\\WP\\SEO\\Presenters\\Abstract_Indexable_Tag_Presenter';

	/**
	 * Whether we are on a page this plugin describes.
	 *
	 * Null until init() assigns it, which is why every reader guards.
	 *
	 * @var callable|null
	 */
	private $on_commerce_page;

	/**
	 * Whether Yoast's presenter pipeline actually ran this request.
	 *
	 * Set from inside filter_presenters(), which only fires if Yoast is
	 * rendering. Per-instance and therefore per-request: for_slugs() builds a
	 * fresh strategy on every load.
	 *
	 * @var bool
	 */
	private bool $observed = false;

	/**
	 * The commerce facts Yoast is missing.
	 *
	 * Shared with the other enrichment strategies so three copies of the
	 * vocabulary cannot drift apart.
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
		return 'yoast';
	}

	/**
	 * Yoast renders; we correct and extend what it renders.
	 */
	public static function mode(): string {
		return self::MODE_ENRICH;
	}

	/**
	 * On every commerce page: Yoast emits Open Graph on all five of them.
	 */
	public function has_taken_over(): bool {
		// Observed, not predicted. `wpseo_frontend_presenters` fires at
		// wp_head:1 and Meta_Tags asks this at wp_head:5, so by now the
		// answer is a fact. Yoast with its Open Graph switch off, or with a
		// third party unhooking its head integration, never reaches the
		// filter — and answering true there would leave the page with no
		// social tags at all.
		//
		// class_exists() as well, because filter_presenters() bails on a
		// missing presenter base and adds nothing. Standing down then would
		// leave Yoast's uncorrected `article` type with none of our facts and
		// no block of our own: strictly worse than not being installed.
		return $this->observed
			&& $this->on_commerce_page()
			&& $this->presenter_base_exists();
	}

	/**
	 * @param callable $on_commerce_page Resolved at hook time, not here.
	 */
	public function init( callable $on_commerce_page ): void {
		$this->on_commerce_page = $on_commerce_page;
		// Priority 20, after the WooCommerce addon's default-priority filter.
		// On a singular product we then agree with it rather than fight it;
		// on the three archive types the addon never runs and we are the only
		// thing correcting `article`.
		add_filter( 'wpseo_opengraph_type', array( $this, 'filter_type' ), 20 );
		// Priority 99, not the default. The WooCommerce addon adds its own
		// presenters on this same filter at priority 10, so at equal priority
		// the winner is registration order — and measured live, the addon
		// registers after us. We then never saw its product:availability,
		// og:availability, product:condition, product:retailer_item_id or its
		// twitter:label1 pair. Running last is what lets us SEE its
		// presenters, which is what filter_presenters() needs in order to
		// DROP the ones whose properties we supply — the opposite of an
		// already-present check. At the default priority nothing was in the
		// list to drop, ours went in beside theirs, and the page shipped
		// each of those properties twice.
		add_filter( 'wpseo_frontend_presenters', array( $this, 'filter_presenters' ), 99 );
		// The twitter:label/data rows are NOT presenters. Yoast's Slack
		// Enhanced_Data_Presenter builds them by numbering a label => value
		// array, so their keys never appear in the presenter list and cannot
		// be deduplicated there. Contributing to that array instead keeps the
		// numbering consistent; emitting our own twitter:label1 beside
		// Yoast's produced two different label1 rows on the same page.
		// Priority 20, after the addon's own default-priority callback.
		add_filter( 'wpseo_enhanced_slack_data', array( $this, 'filter_slack_data' ), 20 );
	}

	/**
	 * Whether Yoast's presenter base is loadable.
	 *
	 * Protected rather than an inline class_exists() so a test can answer no.
	 * The suite loads a stub of that class for the whole process, so an inline
	 * check is unfalsifiable there — and this guards a path where getting it
	 * wrong leaves a page with no social tags at all.
	 */
	protected function presenter_base_exists(): bool {
		return class_exists( self::PRESENTER_BASE );
	}

	/**
	 * Whether this provider rendered its own head at all this request.
	 */
	public function is_emitting(): bool {
		return $this->observed;
	}

	/**
	 * Whether this request is one we describe.
	 *
	 * Guards the null: `for_slugs()` is public and hands out strategies that
	 * have not been init()'d, and callbacks below used to dereference the
	 * callable raw while has_taken_over() guarded it — one class, two answers
	 * to the same question (#676 review).
	 */
	private function on_commerce_page(): bool {
		return null !== $this->on_commerce_page && ( $this->on_commerce_page )();
	}

	/**
	 * Replace Yoast's inherited `article` with the type the page actually is.
	 *
	 * @param mixed $type Whatever Yoast, or the addon, produced.
	 * @return mixed Unchanged off commerce pages.
	 */
	public function filter_type( $type ) {
		if ( ! $this->on_commerce_page() ) {
			return $type;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			// A Meta vendor extension, not in the ogp.me vocabulary, but the
			// only type every commerce consumer reads. Chosen over `article`,
			// which claims a product page has an author and a publish date.
			return 'product';
		}

		// Category, shop and search are collections. Open Graph has no
		// collection type, and `website` is its documented default, so this
		// moves toward the spec rather than away from it.
		return 'website';
	}

	/**
	 * Drop Yoast's article vocabulary, then add the commerce facts it lacks.
	 *
	 * @param mixed $presenters Yoast's presenter list.
	 * @return mixed Unchanged off commerce pages, or when Yoast's presenter
	 *               base is missing.
	 */
	public function filter_presenters( $presenters ) {
		if ( ! is_array( $presenters ) ) {
			return $presenters;
		}

		// Latched BEFORE the commerce check, deliberately. Yoast only runs
		// this filter when it is rendering its own head, so reaching here is
		// proof of emission on ANY page type — which is what the non-commerce
		// emitter needs to know (#690). has_taken_over() still ANDs the page
		// check, so the commerce behaviour is unchanged.
		$this->observed = true;

		if ( ! $this->on_commerce_page() ) {
			return $presenters;
		}

		if ( ! $this->presenter_base_exists() ) {
			// Yoast reported present but its presenter pipeline is not what we
			// measured against. Adding a presenter that does not extend the
			// base is dropped silently, so say so once rather than emit
			// nothing and look correct.
			WC_AI_Storefront_Logger::debug(
				'Open Graph: Yoast is active but %s is missing. Leaving its tags alone.',
				self::PRESENTER_BASE
			);
			return $presenters;
		}

		require_once __DIR__ . '/class-wc-ai-storefront-yoast-og-presenter.php';

		$ours     = $this->facts->properties();
		$supplied = array();
		foreach ( array_keys( $ours ) as $key ) {
			$supplied[ $this->normalise_key( $key ) ] = true;
		}

		$kept = array();
		foreach ( $presenters as $presenter ) {
			// `article:author`, `article:published_time`, `article:modified_time`
			// and `article:publisher` describe editorial content. They ride
			// along on a product even once the type is corrected, because
			// Yoast's type presenter and its article presenters are separate
			// and the addon only replaces the first.
			if ( $this->is_article_presenter( $presenter ) ) {
				continue;
			}

			// One owner per commerce property, rather than a merge. Asking a
			// presenter what it WOULD render is not available here: Yoast
			// assigns $presenter->presentation after this filter returns, so
			// calling get() throws mid-wp_head and truncates the page. And
			// registered is not the same as rendered — the addon registers
			// product:price:amount for every product and returns '' from it on
			// a variable one — so a key-presence test drops the very row this
			// strategy exists to add. Dropping the ones we supply and adding
			// ours needs neither question answered.
			//
			// Keys we do not supply stay: product:brand and
			// product:retailer_item_id are the addon's, and are useful.
			if ( isset( $supplied[ $this->presenter_key( $presenter ) ] ) ) {
				continue;
			}

			$kept[] = $presenter;
		}

		foreach ( $ours as $key => $value ) {
			$kept[] = new WC_AI_Storefront_Yoast_Og_Presenter( $key, $value, true );
		}

		return $kept;
	}

	/**
	 * Add the price row to Yoast's Slack/Twitter enhanced data.
	 *
	 * Yoast renders this array as `twitter:label1`/`twitter:data1`,
	 * `twitter:label2`/`twitter:data2` and so on, in order. The WooCommerce
	 * addon already contributes Availability here; on a variable product it
	 * contributes no price, which is the row this fills.
	 *
	 * @param mixed $data Label => value, or whatever a third party made it.
	 * @return mixed Unchanged off commerce pages.
	 */
	public function filter_slack_data( $data ) {
		if ( ! is_array( $data ) || ! $this->on_commerce_page() ) {
			return $data;
		}

		foreach ( $this->facts->twitter_rows() as $label => $value ) {
			// isset() on the label alone compares OUR translation against
			// THEIRS. Both sides translate "Price" and "Availability", in
			// different text domains, so the keys match only in English —
			// any locale where the renderings differ gets both rows, theirs
			// at label2 and ours at label3, both saying availability (#676
			// review). Comparing values too catches the common case: the same
			// fact under a different word.
			if ( isset( $data[ $label ] ) || in_array( $value, $data, true ) ) {
				continue;
			}
			$data[ $label ] = $value;
		}

		return $data;
	}

	/**
	 * Whether a presenter renders `article:*`.
	 *
	 * Keyed rather than matched on class name: Yoast ships four of these and
	 * the addon could add more, and the key is the thing that reaches the page.
	 *
	 * @param mixed $presenter One entry from Yoast's presenter list.
	 */
	private function is_article_presenter( $presenter ): bool {
		// `article_`, not `article:` — presenter_key() reports what Yoast's
		// escape_key() returns, and that has already rewritten ':' to '_'.
		$key = $this->presenter_key( $presenter );

		return '' !== $key && 0 === strpos( $key, 'article_' );
	}

	/**
	 * A presenter's property key, or '' when it will not say.
	 *
	 * `escape_key()` is the only public accessor Yoast offers — `$key` itself
	 * is protected — and it rewrites `:` to `_`. That transform is lossy, so
	 * callers compare in that same space rather than mapping back.
	 *
	 * @param mixed $presenter One entry from Yoast's presenter list.
	 */
	private function presenter_key( $presenter ): string {
		if ( ! is_object( $presenter ) || ! method_exists( $presenter, 'escape_key' ) ) {
			// Not a tag presenter at all. Yoast's Title, Canonical, Robots and
			// Schema presenters have no escape_key(), and dropping those would
			// strip Yoast's title and canonical off every commerce page. Keep
			// them, silently: the common case, not a problem.
			return '';
		}

		$key = $presenter->escape_key();
		if ( ! is_string( $key ) || '' === $key ) {
			// A tag presenter that will not say what it renders. Yoast returns
			// null from escape_key() while $key is still 'NO KEY PROVIDED', so
			// a presenter that sets its key late — as Yoast already does with
			// $presenter->presentation, assigned after this filter returns —
			// looks anonymous here. Keeping it is the safe direction, but if
			// it renders a property we also supply the page carries that
			// property twice. Worth knowing rather than discovering from a
			// duplicated tag.
			WC_AI_Storefront_Logger::debug(
				'Open Graph: Yoast presenter %s renders a tag but will not name it. Keeping it unread.',
				get_class( $presenter )
			);

			return '';
		}

		// Yoast offers two accessors: get_key() returns the raw key, and
		// escape_key() rewrites ':', ' ' and '-' to '_'. We compare in the
		// escaped space because normalise_key() puts our own property names
		// into it before the $supplied lookup in filter_presenters(). The
		// transform is lossy and is never reversed.
		return $key;
	}

	/**
	 * A property name in the space `escape_key()` reports.
	 */
	private function normalise_key( string $key ): string {
		return str_replace( array( ':', ' ', '-' ), '_', $key );
	}
}
