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
	 * @var callable
	 */
	private $on_commerce_page;

	/**
	 * Builds the tags we would have emitted ourselves.
	 *
	 * Reused rather than reimplemented so the vocabulary cannot drift: every
	 * property Yoast is missing is computed by exactly the code that computes
	 * it when no SEO plugin is installed.
	 *
	 * @var WC_AI_Storefront_Meta_Tags
	 */
	private WC_AI_Storefront_Meta_Tags $tags;

	/**
	 * @param WC_AI_Storefront_Meta_Tags|null $tags Injectable for tests.
	 */
	public function __construct( ?WC_AI_Storefront_Meta_Tags $tags = null ) {
		$this->tags = $tags ?? new WC_AI_Storefront_Meta_Tags();
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
		// twitter:label1 pair, appended ours beside them, and shipped each of
		// those properties twice. Running last is what makes the
		// already-present check in missing_tags() mean anything.
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
	 * Replace Yoast's inherited `article` with the type the page actually is.
	 *
	 * @param mixed $type Whatever Yoast, or the addon, produced.
	 * @return mixed Unchanged off commerce pages.
	 */
	public function filter_type( $type ) {
		if ( ! ( $this->on_commerce_page )() ) {
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
		if ( ! is_array( $presenters ) || ! ( $this->on_commerce_page )() ) {
			return $presenters;
		}

		if ( ! class_exists( self::PRESENTER_BASE ) ) {
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

		$ours     = $this->commerce_tags();
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
			return '';
		}

		$key = $presenter->escape_key();
		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}

		// escape_key() maps ':' to '_' and cannot be reversed unambiguously,
		// so compare in that space instead: our own keys are normalised the
		// same way before the isset() check in missing_tags().
		return $key;
	}

	/**
	 * The commerce properties this plugin supplies for the current page.
	 *
	 * og:type is the type filter's job, and og:title, og:description, og:url,
	 * og:site_name, og:image and og:locale are Yoast's to own: the merchant
	 * may have authored them in Yoast's own fields, and #668 settled that
	 * authored text wins.
	 *
	 * @return array<string,string> Property name => value. Empty on archives.
	 */
	private function commerce_tags(): array {
		$tags = array();

		foreach ( $this->our_tags() as $key => $value ) {
			if ( '' === (string) $value || ! $this->is_commerce_property( $key ) ) {
				continue;
			}
			$tags[ $key ] = (string) $value;
		}

		return $tags;
	}

	/**
	 * Whether a key is a commerce fact rather than a page description.
	 *
	 * Twitter's label/data rows are deliberately absent: they go through
	 * filter_slack_data() instead, because Yoast renders them from a numbered
	 * array rather than from presenters.
	 */
	private function is_commerce_property( string $key ): bool {
		return 0 === strpos( $key, 'product:' ) || 'og:availability' === $key;
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
	 * @return mixed Unchanged off product pages.
	 */
	public function filter_slack_data( $data ) {
		if ( ! is_array( $data ) || ! ( $this->on_commerce_page )() ) {
			return $data;
		}

		// No separate is_product() guard: our_tags() already returns an empty
		// map off a product, so the loop below adds nothing. A second guard
		// asserting the same invariant is one more thing to keep in step.
		$ours = $this->our_tags();
		foreach ( array( 'twitter:label1', 'twitter:label2' ) as $label_key ) {
			$data_key = str_replace( 'label', 'data', $label_key );
			$label    = (string) ( $ours[ $label_key ] ?? '' );
			$value    = (string) ( $ours[ $data_key ] ?? '' );
			if ( '' === $label || '' === $value || isset( $data[ $label ] ) ) {
				continue;
			}
			$data[ $label ] = $value;
		}

		return $data;
	}

	/**
	 * A property name in the space `escape_key()` reports.
	 */
	private function normalise_key( string $key ): string {
		return str_replace( array( ':', ' ', '-' ), '_', $key );
	}

	/**
	 * The full tag map this plugin would emit for the current request.
	 *
	 * @return array<string,string>
	 */
	private function our_tags(): array {
		$product = ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) )
			? wc_get_product( get_queried_object_id() )
			: null;

		if ( ! $product ) {
			// Archives carry no commerce facts, so every key
			// build_archive_og_tags() produces is one is_commerce_property()
			// then discards. Building it anyway would run the archive image
			// resolver — including its product query — once per archive
			// render, for values nothing reads.
			return array();
		}

		$og = $this->tags->build_og_tags( $product );

		return array_merge( $og, $this->tags->build_twitter_tags( $og, $product ) );
	}
}
