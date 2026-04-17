<?php
/**
 * AI Syndication: Store API Product Collection Filter
 *
 * Hooks `woocommerce_store_api_product_collection_query_args` to
 * restrict Store API product queries according to the plugin's
 * `product_selection_mode` setting. Without this filter, the
 * merchant's "only these categories" or "only these products"
 * choice silently applies to llms.txt / JSON-LD but NOT to the
 * Store API — meaning AI agents hitting our UCP catalog routes
 * would see every product, including ones the merchant explicitly
 * chose to hide.
 *
 * The filter fires for ALL Store API product queries (not just
 * UCP routes), which is intentional — if a merchant configures
 * "only these products," they mean it everywhere (including
 * block-theme Cart/Checkout blocks that hit Store API). Merchants
 * who need the filter scoped only to UCP routes can toggle the
 * selection mode back to "all" and rely on their own storefront
 * controls.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restricts Store API product queries to the plugin's selected products/categories.
 */
class WC_AI_Syndication_UCP_Store_API_Filter {

	/**
	 * Register the query-args filter.
	 *
	 * Called from `init_components()` inside the enabled branch —
	 * meaning the filter only applies when AI syndication is on.
	 * Disabling the plugin removes the filter entirely, restoring
	 * unfiltered Store API behavior.
	 */
	public function init(): void {
		add_filter(
			'woocommerce_store_api_product_collection_query_args',
			[ $this, 'restrict_to_syndicated_products' ]
		);
	}

	/**
	 * Modify the Store API product collection query args to respect
	 * the plugin's product_selection_mode setting.
	 *
	 * Mode `all`:        return args unchanged.
	 * Mode `categories`: append a tax_query entry for selected product_cat
	 *                    term IDs. WP_Query ANDs multiple tax_query entries
	 *                    by default, so any incoming category filter is
	 *                    preserved and ours becomes an additional constraint.
	 * Mode `selected`:   restrict post__in to the merchant's allow-list.
	 *                    If the incoming request has its own post__in,
	 *                    intersect instead of overriding — this preserves
	 *                    the original request's intent AND enforces the
	 *                    merchant's allow-list. Empty intersection produces
	 *                    `post__in = [0]` (never a valid ID) to force zero
	 *                    matches; raw `[]` would ironically match all posts
	 *                    due to WP_Query's historical handling of empty
	 *                    post__in.
	 *
	 * Empty selection lists in either mode are treated as no-op — the
	 * merchant has picked a mode but hasn't populated it yet, so applying
	 * an empty restriction would hide everything. This matches the
	 * behavior of the existing llms.txt / JSON-LD filters.
	 *
	 * @param array<string, mixed> $args Store API query args.
	 * @return array<string, mixed>      Modified args.
	 */
	public function restrict_to_syndicated_products( array $args ): array {
		$settings = WC_AI_Syndication::get_settings();
		$mode     = $settings['product_selection_mode'] ?? 'all';

		if ( 'categories' === $mode && ! empty( $settings['selected_categories'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $settings['selected_categories'] ),
			];
			return $args;
		}

		if ( 'selected' === $mode && ! empty( $settings['selected_products'] ) ) {
			$allowed = array_map( 'absint', $settings['selected_products'] );

			if ( isset( $args['post__in'] ) && is_array( $args['post__in'] ) && ! empty( $args['post__in'] ) ) {
				$incoming         = array_map( 'absint', $args['post__in'] );
				$intersection     = array_values( array_intersect( $incoming, $allowed ) );
				$args['post__in'] = empty( $intersection ) ? [ 0 ] : $intersection;
			} else {
				$args['post__in'] = $allowed;
			}
		}

		return $args;
	}
}
