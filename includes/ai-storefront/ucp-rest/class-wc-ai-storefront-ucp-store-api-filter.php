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
	 *                    segment, which prevents NEW configuration there.
	 *                    Persisted `selected_brands` may still exist after
	 *                    a downgrade / stale-settings scenario (a merchant
	 *                    configured brands on WC 9.5+, then rolled WC back
	 *                    to an older version, so the option row in the DB
	 *                    survives but the taxonomy doesn't). The defensive
	 *                    `taxonomy_exists` check guards exactly that path:
	 *                    falls back to a no-op (returns args unchanged)
	 *                    rather than emitting an invalid tax_query against
	 *                    an unregistered taxonomy.
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

		// Defensive legacy-mode fallback. Silent migration in
		// `WC_AI_Storefront::get_settings()` rewrites stored values,
		// but a caller that constructs args with a pre-0.1.5 mode
		// still gets correct UNION enforcement. See the companion
		// block in `is_product_syndicated()` for rationale.
		if ( 'categories' === $mode || 'tags' === $mode || 'brands' === $mode ) {
			$mode = 'by_taxonomy';
		}

		if ( 'by_taxonomy' === $mode ) {
			return $this->apply_union_restriction( $args, $settings );
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
	 * Apply UNION restriction across categories / tags / brands.
	 *
	 * Builds a tax_query with `relation => 'OR'` so products match
	 * if they belong to any of the configured terms in any of the
	 * three taxonomies. Example: `selected_categories = [3, 7]`,
	 * `selected_brands = [12]` → products matching cat 3 OR cat 7
	 * OR brand 12 are included.
	 *
	 * Brand-downgrade exception: if `product_brand` isn't
	 * registered (pre-WC 9.5 / custom unregistration) AND brands
	 * is the ONLY configured taxonomy, leave `$args` unchanged
	 * (show all) — same rationale as `is_product_syndicated()`.
	 * A stored but unenforceable brand selection alongside
	 * categories or tags is simply dropped from the UNION.
	 *
	 * Empty-selection policy: no enforceable taxonomy has a non-
	 * empty selection → force `post__in = [0]` (zero matches).
	 * Matches `is_product_syndicated()` returning false in the
	 * same state so llms.txt / JSON-LD and the Store API catalog
	 * stay in lockstep.
	 *
	 * Incoming tax_query merge: if the caller already supplied a
	 * `tax_query`, wrap both our UNION clause and theirs in an
	 * `AND`-relation outer tax_query — preserves their intent
	 * AND enforces ours.
	 *
	 * @param array<string, mixed> $args     Store API query args.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>          Modified args.
	 */
	public function apply_union_restriction( array $args, array $settings ): array {
		$selected_categories = array_map( 'absint', $settings['selected_categories'] ?? [] );
		$selected_tags       = array_map( 'absint', $settings['selected_tags'] ?? [] );
		$selected_brands     = array_map( 'absint', $settings['selected_brands'] ?? [] );

		$brands_supported = taxonomy_exists( 'product_brand' );

		$has_cats   = ! empty( $selected_categories );
		$has_tags   = ! empty( $selected_tags );
		$has_brands = ! empty( $selected_brands ) && $brands_supported;

		// Brand-downgrade exception: only brands configured and the
		// taxonomy is now missing → show all. Preserves the pre-
		// 0.1.5 `brands` mode degradation behavior.
		if ( ! $has_cats && ! $has_tags && ! $brands_supported && ! empty( $selected_brands ) ) {
			return $args;
		}

		// Empty-selection policy: nothing enforceable → zero matches.
		if ( ! $has_cats && ! $has_tags && ! $has_brands ) {
			$args['post__in'] = [ 0 ];
			return $args;
		}

		$clauses = [ 'relation' => 'OR' ];

		if ( $has_cats ) {
			$clauses[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $selected_categories,
			];
		}

		if ( $has_tags ) {
			$clauses[] = [
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => $selected_tags,
			];
		}

		if ( $has_brands ) {
			$clauses[] = [
				'taxonomy' => 'product_brand',
				'field'    => 'term_id',
				'terms'    => $selected_brands,
			];
		}

		// Merge with any incoming tax_query via AND, so the caller's
		// existing filter stays in effect alongside our UNION.
		if ( empty( $args['tax_query'] ) ) {
			$args['tax_query'] = $clauses;
		} else {
			$args['tax_query'] = [
				'relation' => 'AND',
				$args['tax_query'],
				$clauses,
			];
		}

		return $args;
	}
}
