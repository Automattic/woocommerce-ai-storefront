<?php
/**
 * Yoast presenter stubs, one namespace per file.
 *
 * Split out because phpcs enforces Universal.Namespaces.OneDeclarationPerFile,
 * and the strategy latches on the NAMESPACE of a presenter rather than on
 * `wpseo_frontend_presenters` firing (#701 review).
 *
 * @package WooCommerce_AI_Storefront
 */

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
