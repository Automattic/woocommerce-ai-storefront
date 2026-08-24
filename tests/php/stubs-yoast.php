<?php
/**
 * Minimal stand-in for the one Yoast class our presenter extends.
 *
 * `wpseo_frontend_presenters` silently drops anything that is not an
 * Abstract_Indexable_Presenter, so WC_AI_Storefront_Yoast_Og_Presenter has to
 * extend Yoast's real base in production. That makes the class unloadable
 * without Yoast, and the strategy guards on class_exists() before requiring
 * it. This stub is what lets the suite exercise the path at all.
 *
 * Mirrors the real signatures from wordpress-seo 28.x
 * (src/presenters/abstract-indexable-tag-presenter.php and
 * src/presenters/abstract-indexable-presenter.php), verified against a live
 * install. If Yoast changes them, this stub keeps the suite green while
 * production breaks — so the live check in #676 Task 4 is what actually
 * proves the pipeline, not this file.
 *
 * @package WooCommerce_AI_Storefront
 */

namespace Yoast\WP\SEO\Presenters;

/**
 * Renders one meta tag from a key, a format and a value.
 */
abstract class Abstract_Indexable_Tag_Presenter {

	public const META_PROPERTY_CONTENT = '<meta property="%2$s" content="%1$s"%3$s />';
	public const META_NAME_CONTENT     = '<meta name="%2$s" content="%1$s"%3$s />';
	public const LINK_REL_HREF         = '<link rel="%2$s" href="%1$s"%3$s />';
	public const DEFAULT_TAG_FORMAT    = self::META_NAME_CONTENT;

	/**
	 * The property name this presenter renders.
	 *
	 * @var string
	 */
	protected $key = 'NO KEY PROVIDED';

	/**
	 * sprintf format for the rendered tag.
	 *
	 * @var string
	 */
	protected $tag_format = self::DEFAULT_TAG_FORMAT;

	/**
	 * The raw value.
	 *
	 * @return string|array
	 */
	abstract public function get();

	/**
	 * The key, with separators normalised. Yoast's own transform: this is the
	 * only public accessor for a key, and it is lossy.
	 *
	 * @return string|null
	 */
	public function escape_key() {
		if ( 'NO KEY PROVIDED' === $this->key ) {
			return null;
		}

		return \str_replace( array( ':', ' ', '-' ), '_', $this->key );
	}

	/**
	 * The rendered tag, or '' for an empty value.
	 *
	 * @return string
	 */
	public function present() {
		$value = $this->get();
		if ( ! \is_string( $value ) || '' === $value ) {
			return '';
		}

		return \sprintf( $this->tag_format, $value, $this->key, '' );
	}
}

namespace Yoast\WP\SEO\Presenters\Open_Graph;

/**
 * Stand-in for one of Yoast's Open Graph presenters.
 *
 * Only the NAMESPACE matters. `WC_AI_Storefront_Og_Strategy_Yoast` latches its
 * observation on the presence of a `\Open_Graph\` presenter in the list rather
 * than on `wpseo_frontend_presenters` merely firing, because that filter runs
 * for Yoast's whole head and fires even with Open Graph switched off. Measured
 * in the #701 review: OG off leaves zero `og:*` tags and the filter still runs.
 */
class Type_Presenter {

	/**
	 * The tag this presenter would render.
	 *
	 * @var string
	 */
	public $key = 'og:type';
}

namespace Yoast\WP\SEO\Presenters;

/**
 * A non-Open-Graph presenter, correctly namespaced.
 *
 * The namespace is the point. `carries_open_graph()` has to tell "is a Yoast
 * presenter" from "is an Open Graph presenter", and a globally-scoped double
 * cannot: `get_class()` returns a bare name with no separator, so a matcher
 * keyed on `\Presenters\` or `Yoast` passes a test built from doubles while
 * latching on an Open-Graph-off head in production (#701 review).
 */
class Title_Presenter {
}

/**
 * As above. Yoast puts these AHEAD of the Open Graph block in a real head,
 * which is why fixtures must not put the OG presenter first.
 */
class Canonical_Presenter {
}

namespace Yoast\WP\SEO\Presenters\Twitter;

/**
 * Twitter presenters are a separate namespace from Open Graph, and Yoast emits
 * them with Open Graph switched off. Measured: OG off left five twitter tags
 * and zero og:* tags (#701 review).
 */
class Card_Presenter {
}
