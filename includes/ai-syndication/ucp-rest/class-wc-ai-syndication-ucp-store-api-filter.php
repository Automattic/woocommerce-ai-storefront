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
 * "only these products," they mean it everywhere.
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
	 * Register the query-args filter on init.
	 */
	public function init(): void {
		// TODO (task 8): hook woocommerce_store_api_product_collection_query_args.
	}

	/**
	 * Modify the Store API product collection query args to respect
	 * the plugin's product_selection_mode setting.
	 *
	 * @param array<string, mixed> $args Store API query args.
	 * @return array<string, mixed>      Modified args.
	 */
	public function restrict_to_syndicated_products( array $args ): array {
		// TODO (task 8): read settings, inject tax_query or post__in.
		return $args;
	}
}
