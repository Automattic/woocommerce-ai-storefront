<?php
/**
 * AI Storefront: Store API Product Collection Filter
 *
 * Hooks `woocommerce_store_api_product_collection_query_args` to
 * restrict Store API product queries by the plugin's
 * `product_selection_mode` — only when the request is a UCP-
 * controller-initiated dispatch (gated via
 * `enter_ucp_dispatch()` / `exit_ucp_dispatch()` markers around
 * every `rest_do_request()` call inside the UCP REST controller).
 *
 * Why scoped: the Products tab is labeled "Products available to
 * AI crawlers" — applying this filter to every Store API call
 * (front-end Cart, block-theme Checkout, themes, third-party
 * plugins) would silently scope the merchant's storefront to
 * whatever they configured for AI, violating that UI promise.
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.1.7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restricts Store API product queries to the plugin's selected products/categories.
 */
class WC_AI_Storefront_UCP_Store_API_Filter {

	/**
	 * Depth counter for UCP-initiated dispatches.
	 *
	 * Incremented by `enter_ucp_dispatch()` before every UCP
	 * controller call to `rest_do_request()`, decremented by
	 * `exit_ucp_dispatch()` immediately after (in a `finally`
	 * block so exceptions don't leak the depth). The query-args
	 * filter checks this counter and short-circuits when zero,
	 * meaning Store API requests OUTSIDE UCP-controller dispatch
	 * (front-end cart, block-theme Checkout, third-party Store
	 * API consumers) are unaffected by the merchant's AI-scoping
	 * settings.
	 *
	 * Counter (not boolean) so nested dispatches still terminate
	 * correctly — current code doesn't nest, but future
	 * controllers might compose UCP requests internally.
	 *
	 * @var int
	 */
	private static int $ucp_dispatch_depth = 0;

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
	 * Mark the start of a UCP-controller-initiated Store API
	 * dispatch. Pair with `exit_ucp_dispatch()` in a `try/finally`
	 * around every `rest_do_request()` call inside the UCP REST
	 * controller. Enables the query-args filter for the duration
	 * of the inner dispatch.
	 */
	public static function enter_ucp_dispatch(): void {
		++self::$ucp_dispatch_depth;
	}

	/**
	 * Mark the end of a UCP-controller-initiated Store API
	 * dispatch. Idempotent: never decrements below zero, so an
	 * accidental double-call from a `finally` block can't leak
	 * negative depth.
	 */
	public static function exit_ucp_dispatch(): void {
		if ( self::$ucp_dispatch_depth <= 0 ) {
			// Unbalanced exit. Either a controller called
			// exit without a matching enter, or a finally
			// block fired twice. The clamp below keeps the
			// depth non-negative (safe-fail: filter no-ops
			// outside scope), but log so a developer can
			// catch the invariant violation in dev/staging.
			if ( class_exists( 'WC_AI_Storefront_Logger' ) ) {
				WC_AI_Storefront_Logger::debug(
					'WC_AI_Storefront_UCP_Store_API_Filter::exit_ucp_dispatch called with depth=0 (unbalanced enter/exit)'
				);
			}
		}
		self::$ucp_dispatch_depth = max( 0, self::$ucp_dispatch_depth - 1 );
	}

	/**
	 * Whether the current Store API request is inside a
	 * UCP-controller dispatch. Public so tests can introspect.
	 */
	public static function is_in_ucp_dispatch(): bool {
		return self::$ucp_dispatch_depth > 0;
	}

	/**
	 * Modify the Store API product collection query args to respect
	 * the plugin's `product_selection_mode` setting.
	 *
	 *   - `all`          → args unchanged.
	 *   - `by_taxonomy`  → delegate to `apply_union_restriction()`,
	 *                      which emits a UNION `tax_query` across
	 *                      `selected_categories ∪ selected_tags ∪
	 *                      selected_brands`. See that method's
	 *                      docblock for full decision table
	 *                      (empty-selection policy, brand-downgrade
	 *                      exception, incoming-tax_query merge).
	 *   - `selected`     → restrict `post__in` to the merchant's
	 *                      allow-list. If the incoming request has
	 *                      its own `post__in`, intersect instead
	 *                      of overriding (preserves caller intent
	 *                      AND enforces our list). Empty
	 *                      intersection produces `post__in = [0]`
	 *                      (never a valid ID) to force zero
	 *                      matches; raw `[]` would ironically match
	 *                      all posts due to WP_Query's historical
	 *                      handling of empty `post__in`.
	 *
	 * Pre-0.1.5 enum values (`categories` / `tags` / `brands`) route
	 * to `by_taxonomy` via the silent-migration fallback at the top
	 * of this method. Stored values are normalized on first read by
	 * `WC_AI_Storefront::get_settings()`; this defensive mapping
	 * covers in-flight requests during the migration window.
	 *
	 * Empty-selection policy and brand-downgrade exception live in
	 * `apply_union_restriction()` — see that method's docblock.
	 *
	 * @param array<string, mixed> $args Store API query args.
	 * @return array<string, mixed>      Modified args.
	 */
	public function restrict_to_syndicated_products( array $args ): array {
		// UCP-dispatch gate. The filter is registered globally
		// (WordPress doesn't expose a "fire only for these
		// callers" registration), so we self-gate based on the
		// UCP controller's enter/exit_ucp_dispatch markers. Any
		// Store API request OUTSIDE that scope (front-end cart,
		// block-theme Checkout, theme product carousels, third-
		// party plugins consuming Store API) returns args
		// unchanged.
		if ( ! self::is_in_ucp_dispatch() ) {
			return $args;
		}

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
