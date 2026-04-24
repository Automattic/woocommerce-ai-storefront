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
 * @package WooCommerce_AI_Storefront
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restricts Store API product queries to the plugin's selected products/categories.
 */
class WC_AI_Storefront_UCP_Store_API_Filter {

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
	 * Mode `tags`:       append a tax_query entry for selected product_tag
	 *                    term IDs. Same ANY-match semantics as categories.
	 * Mode `brands`:     append a tax_query entry for selected product_brand
	 *                    term IDs. The `product_brand` taxonomy is WC 9.5+;
	 *                    on older stores the admin UI hides the Brands
	 *                    segment so this branch never receives a non-empty
	 *                    selection. Defensive `taxonomy_exists` check
	 *                    guards against stale settings if the taxonomy is
	 *                    unregistered by a custom env — falls back to no-op
	 *                    (returns args unchanged) rather than emitting an
	 *                    invalid tax_query.
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
	 * Empty-selection policy for taxonomy modes
	 * ------------------------------------------
	 * When mode is `categories`/`tags`/`brands` and the corresponding
	 * `selected_*` array is empty, the filter forces `post__in = [0]`
	 * to hide all products. This matches the behavior of
	 * `WC_AI_Storefront::is_product_syndicated()` which returns false
	 * in the same scenario — keeping llms.txt/JSON-LD (per-product
	 * gate) and the Store API (query-args gate) in lockstep. Without
	 * this symmetry, agents hitting the UCP catalog would see every
	 * product while agents fetching llms.txt would see none — a
	 * silent enforcement inconsistency.
	 *
	 * Exception: `brands` mode with an unregistered `product_brand`
	 * taxonomy. The taxonomy-missing guard runs BEFORE the empty-
	 * selection policy and returns args unchanged (no-op = show
	 * all), regardless of whether `selected_brands` is populated.
	 * That's a deliberate downgrade posture — the merchant picked
	 * brands on a store that supported the taxonomy, then an
	 * environment change removed it; hiding the catalog in that
	 * scenario (even via the empty-selection rule) would be a
	 * surprising consequence of a change the merchant may not have
	 * initiated. `is_product_syndicated()` mirrors this exception
	 * with a hoisted `taxonomy_exists` check of its own.
	 *
	 * The admin UI also surfaces an inline warning Notice when the
	 * merchant picks By-taxonomy with an empty active-taxonomy
	 * selection, so the "hides everything" posture is a visible,
	 * recoverable state rather than a surprise.
	 *
	 * @param array<string, mixed> $args Store API query args.
	 * @return array<string, mixed>      Modified args.
	 */
	public function restrict_to_syndicated_products( array $args ): array {
		$settings = WC_AI_Storefront::get_settings();
		$mode     = $settings['product_selection_mode'] ?? 'all';

		if ( 'categories' === $mode ) {
			return $this->apply_taxonomy_restriction(
				$args,
				'product_cat',
				$settings['selected_categories'] ?? []
			);
		}

		if ( 'tags' === $mode ) {
			return $this->apply_taxonomy_restriction(
				$args,
				'product_tag',
				$settings['selected_tags'] ?? []
			);
		}

		if ( 'brands' === $mode ) {
			// `product_brand` is WC 9.5+. On stores without the
			// taxonomy registered, decline to act rather than emit
			// an invalid tax_query — the admin UI also hides the
			// Brands segment when the server-side `supportsBrands`
			// flag is false, so a persisted `brands` mode on an
			// older store is a rare downgrade scenario we degrade
			// gracefully from.
			if ( ! taxonomy_exists( 'product_brand' ) ) {
				return $args;
			}
			return $this->apply_taxonomy_restriction(
				$args,
				'product_brand',
				$settings['selected_brands'] ?? []
			);
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

	/**
	 * Apply a taxonomy restriction — or force zero matches when
	 * the selection is empty.
	 *
	 * Extracted so the three taxonomy-mode branches
	 * (categories / tags / brands) share a single enforcement path.
	 * Keeping the empty-selection policy in one place guarantees
	 * categories/tags/brands can't silently diverge in how they
	 * handle a "picked a mode but haven't configured it yet" state.
	 *
	 * @param array<string, mixed> $args     Store API query args.
	 * @param string               $taxonomy WP taxonomy slug.
	 * @param array                $term_ids Raw term IDs from settings.
	 * @return array<string, mixed>          Modified args.
	 */
	private function apply_taxonomy_restriction( array $args, string $taxonomy, array $term_ids ): array {
		$term_ids = array_map( 'absint', $term_ids );

		if ( empty( $term_ids ) ) {
			// Force zero matches using the same sentinel
			// (`post__in = [0]`) the `selected` branch uses for an
			// empty intersection. Never a valid post ID, so
			// WP_Query short-circuits to zero results and the
			// agent-facing catalog matches what llms.txt/JSON-LD
			// emit in the same state.
			$args['post__in'] = [ 0 ];
			return $args;
		}

		$args['tax_query'][] = [
			'taxonomy' => $taxonomy,
			'field'    => 'term_id',
			'terms'    => $term_ids,
		];

		return $args;
	}
}
