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

namespace Yoast\WP\SEO\Presenters\Twitter;

/**
 * Twitter presenters are a separate namespace from Open Graph, and Yoast emits
 * them with Open Graph switched off. Measured: OG off left five twitter tags
 * and zero og:* tags (#701 review).
 */
class Card_Presenter {
}
