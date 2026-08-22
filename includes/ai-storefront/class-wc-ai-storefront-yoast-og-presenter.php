<?php
/**
 * A single meta tag, rendered through Yoast's presenter pipeline.
 *
 * NOT in the autoloader classmap, and deliberately so: this class extends a
 * Yoast class, so merely loading the file when Yoast is absent is a fatal
 * error. WC_AI_Storefront_Og_Strategy_Yoast requires it on demand, after
 * checking the base class exists. Nothing else may reference it — not even
 * class_exists(), which would trigger the autoloader.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

use Yoast\WP\SEO\Presenters\Abstract_Indexable_Tag_Presenter;

/**
 * One commerce property, in the shape Yoast renders.
 *
 * `wpseo_frontend_presenters` silently drops anything that is not an
 * `Abstract_Indexable_Presenter` — no warning, no output, no log (#676 spike).
 * So this extends Yoast's own tag presenter rather than duplicating its
 * rendering: escaping, the admin-bar class attribute, and the empty-value
 * skip all come from the base.
 */
class WC_AI_Storefront_Yoast_Og_Presenter extends Abstract_Indexable_Tag_Presenter {

	/**
	 * The tag's value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * @param string $key      Property name, e.g. `product:price:amount`.
	 * @param string $value    Its value. An empty string renders nothing,
	 *                         which the base class already handles.
	 * @param bool   $property True for `<meta property>` (Open Graph), false
	 *                         for `<meta name>` (Twitter). Getting this wrong
	 *                         is silent: both render, only one is read.
	 */
	public function __construct( string $key, string $value, bool $property = true ) {
		$this->key        = $key;
		$this->value      = $value;
		$this->tag_format = $property ? self::META_PROPERTY_CONTENT : self::META_NAME_CONTENT;
	}

	/**
	 * The raw value; the base class escapes it.
	 *
	 * @return string
	 */
	public function get() {
		return $this->value;
	}
}
