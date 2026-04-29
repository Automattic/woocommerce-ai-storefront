<?php
/**
 * AI Storefront: UCP Product-Scoping Hook
 *
 * Hooks `pre_get_posts` to restrict product `WP_Query` instances by
 * the plugin's `product_selection_mode` — only when the request is a
 * UCP-controller-initiated dispatch (gated via
 * `enter_ucp_dispatch()` / `exit_ucp_dispatch()` markers around the
 * controller's collection-style `rest_do_request()` calls).
 *
 * Hook layer: `pre_get_posts` is a global WP-level hook that fires
 * before every `WP_Query` SQL build. Prior to this commit the class
 * registered against `woocommerce_store_api_product_collection_query_args`,
 * which does not exist in WooCommerce core — WC's Store API
 * delegates straight to `ProductQuery::get_objects()` → `WP_Query`
 * with no such filter, so the callback never ran in production. The
 * `pre_get_posts` hook is the only WP-level point where the
 * mutations can land on the actual `WP_Query` Store API constructs
 * internally.
 *
 * Threefold gate (see `on_pre_get_posts()` for the implementation):
 *
 *   1. `is_in_ucp_dispatch()` — depth counter is positive.
 *   2. `post_type === 'product'` (or array containing it).
 *   3. Per-mode logic only applies for `by_taxonomy` and `selected`;
 *      `all` mode is a no-op even within UCP scope.
 *
 * Single-product fetches (e.g. `fetch_store_api_product()` for
 * `/catalog/lookup`) take a different path — the controller already
 * gates those dispatches via a direct
 * `WC_AI_Storefront::is_product_syndicated()` check before the
 * inner `rest_do_request()` runs. All three enforcement gates
 * (this hook, the per-id `is_product_syndicated()` gate, and the
 * per-product gate used by llms.txt and JSON-LD) stay in lockstep
 * on the merchant's UNION scope.
 *
 * Why scoped: the Products tab is labeled "Products available to
 * AI crawlers" — applying this scope to every product query
 * (front-end Cart, block-theme Checkout, themes, third-party
 * plugins, admin product list) would silently scope the merchant's
 * storefront to whatever they configured for AI, violating that UI
 * promise. The `is_in_ucp_dispatch()` gate is what makes the
 * pre_get_posts registration safe.
 *
 * The class name `WC_AI_Storefront_UCP_Store_API_Filter` reflects
 * the conceptual purpose ("scope Store-API-mediated product queries
 * to UCP dispatch"); the hook layer is `pre_get_posts` for
 * implementation reasons (the Store-API-specific hook this class
 * was originally written for does not exist).
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restricts Store API product queries to the merchant's syndication
 * scope (UNION across selected_categories / selected_tags /
 * selected_brands under `by_taxonomy` mode, or `selected_products`
 * under `selected` mode), but only inside UCP-controller-initiated
 * dispatches. See file docblock for the full UCP-scoping rationale.
 */
class WC_AI_Storefront_UCP_Store_API_Filter {

	/**
	 * Per-request sentinel preventing duplicate hook registration.
	 *
	 * `add_action` doesn't deduplicate by callback shape — it
	 * compares array callbacks by identity, so
	 * `[ $instance_a, 'on_pre_get_posts' ]` and
	 * `[ $instance_b, 'on_pre_get_posts' ]` register as two distinct
	 * callbacks. A `has_action(...)` check would only catch the
	 * same-instance case (and would also misfire on priority 0,
	 * which `has_action` returns as `0 === falsy`). A class-level
	 * static flag catches the cross-instance case correctly and
	 * resets per request.
	 *
	 * @var bool
	 */
	private static bool $hook_registered = false;

	/**
	 * Register the `pre_get_posts` action.
	 *
	 * Called from `init_components()` inside the enabled branch —
	 * meaning the hook only fires when AI syndication is on.
	 * Disabling the plugin removes the action entirely, restoring
	 * unfiltered WP_Query behavior.
	 */
	public function init(): void {
		// Idempotency guard. Without this, a second `init()` call
		// (plugin re-init in tests, future code instantiating a second
		// filter, activation/deactivation cycle in the same request)
		// would stack a second callback. The mutator is idempotent on
		// its own output, but with stacked callbacks the first writes
		// a UNION `tax_query` and the second wraps it in an outer AND
		// because `$incoming_tax_query` is now non-empty — query is
		// silently mutated into a stricter form than the merchant
		// configured. See the `$hook_registered` docblock above for
		// why a static sentinel beats `has_action()` for this case.
		if ( self::$hook_registered ) {
			WC_AI_Storefront_Logger::debug(
				'WC_AI_Storefront_UCP_Store_API_Filter::init() called when pre_get_posts callback was already registered; skipping duplicate registration'
			);
			return;
		}
		// Priority `PHP_INT_MAX` so the UCP merchant-scope mutations
		// are applied LAST. `pre_get_posts` is a notoriously crowded
		// hook — themes, search plugins, related-products plugins,
		// and WC core itself all register callbacks. Anything that
		// fires AFTER us at a higher priority number could read our
		// mutated `tax_query` / `post__in`, modify, and write back
		// in a way that clobbers part or all of the merchant's
		// syndication scope. By being last we guarantee no later
		// callback gets to override us — the merchant's scope is
		// the final word on what an AI agent sees.
		//
		// Explicit `accepted_args = 1` because the `pre_get_posts`
		// hook only ever passes one argument (`WP_Query`); spelling
		// it out documents intent and prevents a theoretical edge
		// case where another callback in the chain alters the
		// hook's signature via core changes.
		add_action(
			'pre_get_posts',
			[ $this, 'on_pre_get_posts' ],
			PHP_INT_MAX,
			1
		);
		self::$hook_registered = true;
	}

	/**
	 * Reset the idempotency sentinel. Test-only hook so the suite
	 * can re-init the filter across cases without leaking state.
	 *
	 * @internal
	 */
	public static function reset_hook_registered_for_test(): void {
		self::$hook_registered = false;
	}

	/**
	 * Apply UCP syndication scope to product WP_Query instances.
	 *
	 * Bridges the args-shape mutation function below
	 * (`restrict_to_syndicated_products()`) onto the live `WP_Query`
	 * object that the Store API ultimately runs. Reads the relevant
	 * fields off `$query`, builds an args array, hands it to the pure
	 * function, and writes the mutations back via `$query->set()`.
	 *
	 * Threefold gate (any failing means no-op):
	 *
	 *   1. `is_in_ucp_dispatch()` — front-end Cart, block-theme
	 *      Checkout, themes, and third-party Store API consumers all
	 *      run `WP_Query` outside this scope and must be untouched.
	 *   2. `post_type === 'product'` — `pre_get_posts` fires for menus,
	 *      widgets, related-posts queries, etc. Mutating those would
	 *      silently break unrelated parts of the site.
	 *   3. Per-mode logic inside `restrict_to_syndicated_products()`
	 *      no-ops for `all` mode, so even an in-scope product query
	 *      passes through unchanged when the merchant hasn't opted
	 *      into scoping.
	 *
	 * Only `tax_query` and `post__in` round-trip through `$query`;
	 * those are the two fields the underlying mutation function may
	 * touch. Other args (orderby, posts_per_page, etc.) stay on the
	 * query object untouched.
	 *
	 * @since 0.1.15
	 *
	 * @param WP_Query $query The query about to execute.
	 */
	public function on_pre_get_posts( WP_Query $query ): void {
		// Gate 1: only inside a UCP-controller-initiated dispatch.
		if ( ! self::is_in_ucp_dispatch() ) {
			return;
		}

		// Gate 2: only product queries. `post_type` may be a string
		// or array; treat both shapes.
		$post_type = $query->get( 'post_type' );
		if ( 'product' !== $post_type && ! ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
			return;
		}

		// Read existing args off the query, run them through the pure
		// mutation function, write back any changes. Only fields the
		// mutator may touch (`tax_query`, `post__in`) are reflected.
		$incoming_tax_query = $query->get( 'tax_query' );
		$incoming_post_in   = $query->get( 'post__in' );

		$args = [];
		if ( ! empty( $incoming_tax_query ) ) {
			$args['tax_query'] = $incoming_tax_query;
		}
		if ( ! empty( $incoming_post_in ) ) {
			$args['post__in'] = $incoming_post_in;
		}

		$mutated = $this->restrict_to_syndicated_products( $args );

		if ( array_key_exists( 'tax_query', $mutated ) ) {
			$query->set( 'tax_query', $mutated['tax_query'] );
		}
		if ( array_key_exists( 'post__in', $mutated ) ) {
			$query->set( 'post__in', $mutated['post__in'] );
		}
	}

	/**
	 * Mark the start of a UCP-controller-initiated Store API
	 * dispatch. Pair with `exit_ucp_dispatch()` in a `try/finally`
	 * around the controller's collection-style `rest_do_request()`
	 * calls. Enables the query-args filter for the duration of the
	 * inner dispatch.
	 *
	 * Forwards to `WC_AI_Storefront_UCP_Dispatch_Context::enter()`.
	 *
	 * @since 0.1.7
	 */
	public static function enter_ucp_dispatch(): void {
		WC_AI_Storefront_UCP_Dispatch_Context::enter();
	}

	/**
	 * Mark the end of a UCP-controller-initiated Store API
	 * dispatch. Idempotent: never decrements below zero, so an
	 * accidental double-call from a `finally` block can't leak
	 * negative depth.
	 *
	 * Forwards to `WC_AI_Storefront_UCP_Dispatch_Context::exit()`.
	 *
	 * @since 0.1.7
	 */
	public static function exit_ucp_dispatch(): void {
		WC_AI_Storefront_UCP_Dispatch_Context::exit();
	}

	/**
	 * Whether the current Store API request is inside a
	 * UCP-controller dispatch. Public so tests can introspect.
	 *
	 * Forwards to `WC_AI_Storefront_UCP_Dispatch_Context::is_active()`.
	 *
	 * @since 0.1.7
	 */
	public static function is_in_ucp_dispatch(): bool {
		return WC_AI_Storefront_UCP_Dispatch_Context::is_active();
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

		if ( 'selected' === $mode ) {
			$allowed = array_map( 'absint', $settings['selected_products'] ?? [] );

			// Empty allow-list under `selected` mode: force zero
			// matches via the `post__in = [0]` sentinel. Mirrors
			// `is_product_syndicated()` returning false and
			// `get_product_count()` returning 0 in the same state
			// — without this branch, an empty `selected_products`
			// would let the filter return args unchanged and
			// expose the entire catalog to AI agents, contradicting
			// the merchant's "hand-picked, none picked yet"
			// configuration.
			if ( empty( $allowed ) ) {
				$args['post__in'] = [ 0 ];
				return $args;
			}

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
