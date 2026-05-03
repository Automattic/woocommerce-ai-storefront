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
		// Priority 9 — one tick before WooCommerce's ProductQuery::add_query_clauses()
		// at priority 10 on the same hook. Running first lets us zero out the 'search'
		// query var so WooCommerce's callback becomes a no-op, then we add our own
		// per-word AND LIKE clauses in their place.
		add_filter(
			'posts_clauses',
			[ $this, 'on_posts_clauses_search' ],
			9,
			2
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
	 * Replace WooCommerce's single-phrase LIKE with per-signal-term
	 * clauses that expand each term against the store's own product
	 * taxonomy (categories, tags, brands, attributes) and fall back
	 * to a title LIKE for terms that don't match any taxonomy term.
	 *
	 * WooCommerce's ProductQuery::add_query_clauses() (priority 10 on
	 * posts_clauses) builds `post_title LIKE '%full phrase%'`. For
	 * natural-language AI queries like "Hoodie with logo" or "Running
	 * shoes for men" this phrase match fails unless the product title
	 * contains the exact multi-word string.
	 *
	 * Running at priority 9 this method:
	 *   1. Strips punctuation (apostrophes in-place, hyphens→spaces),
	 *      splits on whitespace, removes stopwords — leaving signal terms.
	 *   2. Resolves each signal term against the store's taxonomy via
	 *      exact match or suffix-flip dictionary (ies↔y, es↔, s↔):
	 *      product_cat, product_tag, product_brand, pa_* attributes.
	 *   3. For taxonomy-matched terms emits an EXISTS subquery so the
	 *      product can be found via its category/tag/attribute term
	 *      even when that word doesn't appear in the title.
	 *   4. Combines taxonomy clause OR title LIKE per term so both
	 *      routes count.
	 *   5. Zeroes out 'search' to suppress WooCommerce's phrase LIKE.
	 *
	 * Example — "Hoodie with logo":
	 *   signal terms: hoodie, logo
	 *   hoodie → matches category "Hoodies" (term_id 5)
	 *   logo   → no taxonomy match
	 *   WHERE AND (
	 *     (EXISTS(… term_id IN (5)) OR post_title LIKE '%hoodie%')
	 *     AND post_title LIKE '%logo%'
	 *   )
	 *
	 * Only active inside UCP dispatch.
	 *
	 * @since 0.9.0
	 *
	 * @param array    $args     posts_clauses array (join, where, …).
	 * @param WP_Query $wp_query The WP_Query being built.
	 * @return array             Modified args.
	 */
	public function on_posts_clauses_search( array $args, WP_Query $wp_query ): array {
		if ( ! self::is_in_ucp_dispatch() ) {
			return $args;
		}

		// Product-query gate — mirrors on_pre_get_posts(). posts_clauses fires
		// for every WP_Query inside the UCP dispatch (nav menus, related posts,
		// etc.); mutating non-product queries would corrupt unrelated SQL.
		$post_type = $wp_query->get( 'post_type' );
		if ( 'product' !== $post_type && ! ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
			return $args;
		}

		$raw_search = $wp_query->get( 'search' );
		if ( empty( $raw_search ) || ! is_string( $raw_search ) ) {
			return $args;
		}

		$terms = self::extract_search_terms( $raw_search );
		if ( empty( $terms ) ) {
			// All stopwords — let WooCommerce's phrase LIKE run as fallback.
			return $args;
		}

		global $wpdb;

		// Suppress WooCommerce's phrase-LIKE at priority 10.
		$wp_query->set( 'search', '' );

		$posts_table = $wpdb->prefix . 'posts';
		$meta_table  = $wpdb->prefix . 'wc_product_meta_lookup';
		$tr_table    = $wpdb->prefix . 'term_relationships';
		$tt_table    = $wpdb->prefix . 'term_taxonomy';
		$sku_enabled = function_exists( 'wc_product_sku_enabled' ) && wc_product_sku_enabled();

		if ( $sku_enabled && false === strpos( $args['join'], $meta_table ) ) {
			$args['join'] .= " LEFT JOIN {$meta_table} ON {$posts_table}.ID = {$meta_table}.product_id ";
		}

		// Resolve which signal terms have matching taxonomy term IDs.
		// Returns map of signal → [ term_id, … ] scoped to product taxonomies.
		$taxonomy_map      = self::resolve_taxonomy_terms( $terms );
		$product_tax_names = self::get_product_taxonomy_names();
		// Build a quoted IN list for use in the EXISTS subquery taxonomy constraint.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$tax_names_sql = implode(
			',',
			array_map(
				static fn( string $t ) => "'" . esc_sql( $t ) . "'",
				$product_tax_names
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$per_term = array();
		foreach ( $terms as $signal ) {
			$like = '%' . $wpdb->esc_like( $signal ) . '%';

			// Title (+ SKU) fallback — always present.
			// For title LIKE, also include the suffix-flip variant so "hoodies"
			// matches products titled "Classic Hoodie" and "shoe" matches
			// "Trail Running Shoes". SKU LIKE uses only the raw signal — SKUs
			// are codes, not English words, so stemming would add noise.
			// Table names can't be parameterised — suppress the interpolation sniff.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$like_forms     = self::get_title_like_forms( $signal );
			$title_likes    = array_map(
				function ( string $form ) use ( $wpdb, $posts_table ) {
					$f = '%' . $wpdb->esc_like( $form ) . '%';
					return $wpdb->prepare( "{$posts_table}.post_title LIKE %s", $f );
				},
				$like_forms
			);
			$title_like_sql = count( $title_likes ) > 1
				? '( ' . implode( ' OR ', $title_likes ) . ' )'
				: $title_likes[0];

			if ( $sku_enabled ) {
				$title_clause = $wpdb->prepare(
					"( {$title_like_sql} OR {$meta_table}.sku LIKE %s )",
					$like
				);
			} else {
				$title_clause = $title_like_sql;
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$term_ids = $taxonomy_map[ $signal ] ?? array();
			if ( ! empty( $term_ids ) && ! empty( $tax_names_sql ) ) {
				// Taxonomy route via EXISTS subquery. The ucp_tt.taxonomy IN (…)
				// constraint prevents false positives from WordPress sharing a
				// term_id across multiple taxonomies — without it, a term_id that
				// appears in an unrelated taxonomy (e.g. a post category) could
				// match product rows that have no product taxonomy relationship.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids_sql    = implode( ',', array_map( 'intval', $term_ids ) );
				$tax_clause = "EXISTS (
					SELECT 1 FROM {$tr_table} ucp_tr
					JOIN {$tt_table} ucp_tt ON ucp_tr.term_taxonomy_id = ucp_tt.term_taxonomy_id
					WHERE ucp_tr.object_id = {$posts_table}.ID
					AND ucp_tt.term_id IN ({$ids_sql})
					AND ucp_tt.taxonomy IN ({$tax_names_sql})
				)";
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_term[] = "( {$tax_clause} OR {$title_clause} )";
			} else {
				$per_term[] = $title_clause;
			}
		}

		$args['where'] .= ' AND ( ' . implode( ' AND ', $per_term ) . ' )';

		return $args;
	}

	/**
	 * Return the list of product taxonomy names relevant for AI search:
	 * product_cat, product_tag, product_brand, and pa_* attributes.
	 *
	 * Extracted as a shared helper so both `resolve_taxonomy_terms()`
	 * (which resolves term IDs) and `on_posts_clauses_search()` (which
	 * needs the taxonomy names for the EXISTS subquery constraint) call
	 * the same logic without duplication.
	 *
	 * @since 0.9.0
	 *
	 * @return string[] Taxonomy name strings.
	 */
	private static function get_product_taxonomy_names(): array {
		/** @var array<string, string> $raw_taxonomies */
		$raw_taxonomies = (array) get_taxonomies( array( 'object_type' => array( 'product' ) ), 'names' );
		return array_values(
			array_filter(
				array_keys( $raw_taxonomies ),
				static function ( string $t ) {
					return in_array( $t, array( 'product_cat', 'product_tag', 'product_brand' ), true )
						|| str_starts_with( $t, 'pa_' );
				}
			)
		);
	}

	/**
	 * Resolve signal terms to product taxonomy term IDs using the
	 * store's own categories, tags, brands, and attribute values.
	 *
	 * Fetches all terms across product_cat / product_tag /
	 * product_brand / pa_* in a single get_terms() call (result is
	 * cached by WordPress's object cache). Matches each signal term
	 * by exact name or slug, then via `find_in_lookup_via_variants()`
	 * which applies a suffix-flip dictionary (ies↔y, es↔, s↔).
	 *
	 * @since 0.9.0
	 *
	 * @param string[] $signal_terms Lowercase signal terms.
	 * @return array<string, int[]>  Map of signal_term → matched term IDs.
	 */
	public static function resolve_taxonomy_terms( array $signal_terms ): array {
		if ( empty( $signal_terms ) ) {
			return array();
		}

		$taxonomies = self::get_product_taxonomy_names();

		if ( empty( $taxonomies ) ) {
			return array();
		}

		// Option B: pre-generate all candidate name strings so get_terms()
		// fetches only the handful of rows that could possibly match,
		// rather than the full taxonomy table on every search request.
		// Note: WP does not support name__in + slug__in together cleanly;
		// name__in covers the common case since single-word term slugs
		// equal their lowercased name. Slug-only matches (multi-word terms
		// with spaces-to-hyphens slugs) still resolve via the post-filter
		// in find_in_lookup_via_variants() because those terms ARE returned
		// when their name matches a candidate.
		$candidates = array_values(
			array_unique(
				array_merge(
					...array_map(
						array( self::class, 'get_candidate_strings' ),
						$signal_terms
					)
				)
			)
		);

		$all_terms = get_terms(
			array(
				'taxonomy'               => $taxonomies,
				'hide_empty'             => false,
				'fields'                 => 'all',
				'number'                 => 0,
				'name__in'               => $candidates,
				'update_term_meta_cache' => false,
				'orderby'                => 'none',
			)
		);

		if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
			return array();
		}

		// Build lookup: normalised key → [term_id, …]
		$lookup = array();
		foreach ( $all_terms as $term ) {
			$key_name              = strtolower( trim( $term->name ) );
			$lookup[ $key_name ][] = (int) $term->term_id;
			if ( $term->slug !== $key_name ) {
				$lookup[ $term->slug ][] = (int) $term->term_id;
			}
		}

		$result = array();
		foreach ( $signal_terms as $signal ) {
			// Exact match first.
			if ( isset( $lookup[ $signal ] ) ) {
				$result[ $signal ] = array_unique( $lookup[ $signal ] );
				continue;
			}

			// Try morphological variants in priority order and take the
			// first that hits the lookup. Rules cover the most common
			// English suffix transformations seen in product taxonomy names.
			$ids = self::find_in_lookup_via_variants( $signal, $lookup );
			if ( ! empty( $ids ) ) {
				$result[ $signal ] = $ids;
			}
		}

		return $result;
	}

	/**
	 * Generate morphological variants of a signal term and return the
	 * first batch of term IDs found in the taxonomy lookup.
	 *
	 * Covers the most common English suffix transformations that occur
	 * between how AI agents phrase queries and how merchants title their
	 * product taxonomy terms:
	 *
	 *   Plural → singular (query is plural, taxonomy is singular):
	 *     ies → y   : "hoodies" → "hoodie"
	 *     ches → ch : "watches" → "watch"
	 *     shes → sh : "brushes" → "brush"
	 *     xes → x   : "boxes"   → "box"
	 *     ses → s   : "dresses" → "dress"
	 *     zes → z   : "buzzes"  → "buzz"
	 *     es → (drop): "scarves" edge case covered by next rule
	 *     s → (drop) : "shoes"  → "shoe"
	 *
	 *   Singular → plural (query is singular, taxonomy is plural):
	 *     y → ies   : "hoodie" missed; covers "category" → "categories"
	 *     (base)→es : only tried for ch/sh/x/s/z endings
	 *     (base)→s  : "shoe"   → "shoes"
	 *
	 * Returns the first non-empty hit, or an empty array if no variant
	 * is found. Does NOT mutate the lookup.
	 *
	 * @since 0.9.0
	 *
	 * @param string            $signal The signal term to match.
	 * @param array<string,int[]> $lookup Normalised name/slug → [term_id, …].
	 * @return int[]                     Unique term IDs, or empty array.
	 */
	private static function find_in_lookup_via_variants( string $signal, array $lookup ): array {
		$len = strlen( $signal );

		// ---- Plural → singular ----------------------------------------

		// ies → y  (hoodies → hoodie, accessories → accessory)
		if ( $len > 3 && str_ends_with( $signal, 'ies' ) ) {
			$candidate = substr( $signal, 0, -3 ) . 'y';
			if ( isset( $lookup[ $candidate ] ) ) {
				return array_unique( $lookup[ $candidate ] );
			}
		}

		// {ch,sh,x,s,z}es → drop 'es'  (watches → watch, boxes → box)
		if ( $len > 3 && str_ends_with( $signal, 'es' ) ) {
			$base     = substr( $signal, 0, -2 );
			$last_two = substr( $base, -2 );
			if ( in_array( $last_two, array( 'ch', 'sh' ), true ) || in_array( substr( $base, -1 ), array( 'x', 's', 'z' ), true ) ) {
				if ( isset( $lookup[ $base ] ) ) {
					return array_unique( $lookup[ $base ] );
				}
			}
		}

		// s → drop (shoes → shoe, jackets → jacket)
		if ( $len > 2 && str_ends_with( $signal, 's' ) ) {
			$candidate = substr( $signal, 0, -1 );
			if ( isset( $lookup[ $candidate ] ) ) {
				return array_unique( $lookup[ $candidate ] );
			}
		}

		// ---- Singular → plural ----------------------------------------

		// y → ies  (accessory → accessories)
		if ( $len > 2 && str_ends_with( $signal, 'y' ) ) {
			$candidate = substr( $signal, 0, -1 ) . 'ies';
			if ( isset( $lookup[ $candidate ] ) ) {
				return array_unique( $lookup[ $candidate ] );
			}
		}

		// base + es  (watch → watches, box → boxes)
		$last_two = substr( $signal, -2 );
		if ( in_array( $last_two, array( 'ch', 'sh' ), true ) || in_array( substr( $signal, -1 ), array( 'x', 's', 'z' ), true ) ) {
			$candidate = $signal . 'es';
			if ( isset( $lookup[ $candidate ] ) ) {
				return array_unique( $lookup[ $candidate ] );
			}
		}

		// base + s  (shoe → shoes, jacket → jackets)
		if ( isset( $lookup[ $signal . 's' ] ) ) {
			return array_unique( $lookup[ $signal . 's' ] );
		}

		return array();
	}

	/**
	 * Return both morphological forms of a signal term for title LIKE
	 * expansion — the raw signal plus its suffix-flip variant.
	 *
	 * Unlike `find_in_lookup_via_variants()` this helper requires no
	 * taxonomy lookup; it returns both the original form and its
	 * counterpart unconditionally so the SQL title LIKE clause can OR
	 * across both. Result is deduplicated so a no-op flip (e.g. a word
	 * whose plural and singular are identical after the rule) returns
	 * just the original form.
	 *
	 * Rules mirror the suffix table in `find_in_lookup_via_variants()`:
	 *   ies → y      : "hoodies"  → ["hoodies", "hoodie"]
	 *   {ch,sh,x,s,z}es → base : "watches"  → ["watches", "watch"]
	 *   s → drop     : "shoes"   → ["shoes", "shoe"]
	 *   y → ies      : "hoodie"  → ["hoodie", "hoodies"]
	 *   base + es    : "watch"   → ["watch", "watches"]
	 *   base + s     : "shoe"    → ["shoe", "shoes"]
	 *   no rule      : "logo"    → ["logo"]
	 *
	 * SKU LIKE is intentionally excluded — SKUs are codes, not English
	 * words, so suffix stemming would add noise rather than recall.
	 *
	 * @since 0.9.0
	 *
	 * @param string $signal Lowercase signal term.
	 * @return string[]      One or two deduplicated LIKE forms.
	 */
	private static function get_title_like_forms( string $signal ): array {
		$len = strlen( $signal );

		// ---- Plural → singular ----------------------------------------
		// s → drop FIRST so "hoodies" → "hoodie" via simple strip, not
		// "hoody" via the ies→y rule. ies→y is correct for taxonomy names
		// ("accessories"→"accessory") but wrong for product titles where
		// the stem ends in 'ie' ("hoodie", "beanie").

		// {ch,sh,x,s,z}es → drop 'es'  (watches → watch)
		if ( $len > 3 && str_ends_with( $signal, 'es' ) ) {
			$base     = substr( $signal, 0, -2 );
			$last_two = substr( $base, -2 );
			if ( in_array( $last_two, array( 'ch', 'sh' ), true ) || in_array( substr( $base, -1 ), array( 'x', 's', 'z' ), true ) ) {
				return array_unique( array( $signal, $base ) );
			}
		}

		// s → drop (hoodies → hoodie, shoes → shoe)
		if ( $len > 2 && str_ends_with( $signal, 's' ) ) {
			$variant = substr( $signal, 0, -1 );
			return array_unique( array( $signal, $variant ) );
		}

		// ---- Singular → plural ----------------------------------------

		// y → ies  (accessory → accessories)
		if ( $len > 2 && str_ends_with( $signal, 'y' ) ) {
			$variant = substr( $signal, 0, -1 ) . 'ies';
			return array_unique( array( $signal, $variant ) );
		}

		// base + es  (watch → watches, box → boxes)
		$last_two = substr( $signal, -2 );
		if ( in_array( $last_two, array( 'ch', 'sh' ), true ) || in_array( substr( $signal, -1 ), array( 'x', 's', 'z' ), true ) ) {
			return array_unique( array( $signal, $signal . 'es' ) );
		}

		// base + s for words ending in 'e' (shoe → shoes, hoodie → hoodies,
		// beanie → beanies). Skipping the unconditional +s fallback prevents
		// widening terms like "logo" → "logos" where the plural is uncommon
		// in product titles and adds noise rather than recall.
		if ( $len > 2 && str_ends_with( $signal, 'e' ) ) {
			return array( $signal, $signal . 's' );
		}

		return array( $signal );
	}

	/**
	 * Return all candidate strings that `find_in_lookup_via_variants()`
	 * would attempt as lookup keys for a given signal term.
	 *
	 * Used by `resolve_taxonomy_terms()` to pre-scope the `get_terms()`
	 * DB query via `name__in` — fetching only the handful of terms whose
	 * name matches a candidate rather than the full taxonomy table.
	 *
	 * Mirrors the variant generation logic in `find_in_lookup_via_variants()`
	 * without the lookup step; always returns all possible forms so no
	 * match is silently missed at the DB scoping stage.
	 *
	 * @since 0.9.0
	 *
	 * @param string $signal Lowercase signal term.
	 * @return string[]      Deduplicated candidate strings.
	 */
	private static function get_candidate_strings( string $signal ): array {
		$len        = strlen( $signal );
		$candidates = array( $signal );

		// ies → y
		if ( $len > 3 && str_ends_with( $signal, 'ies' ) ) {
			$candidates[] = substr( $signal, 0, -3 ) . 'y';
		}

		// {ch,sh,x,s,z}es → base
		if ( $len > 3 && str_ends_with( $signal, 'es' ) ) {
			$base     = substr( $signal, 0, -2 );
			$last_two = substr( $base, -2 );
			if ( in_array( $last_two, array( 'ch', 'sh' ), true ) || in_array( substr( $base, -1 ), array( 'x', 's', 'z' ), true ) ) {
				$candidates[] = $base;
			}
		}

		// s → drop
		if ( $len > 2 && str_ends_with( $signal, 's' ) ) {
			$candidates[] = substr( $signal, 0, -1 );
		}

		// y → ies
		if ( $len > 2 && str_ends_with( $signal, 'y' ) ) {
			$candidates[] = substr( $signal, 0, -1 ) . 'ies';
		}

		// base + es
		$last_two_sig = substr( $signal, -2 );
		if ( in_array( $last_two_sig, array( 'ch', 'sh' ), true ) || in_array( substr( $signal, -1 ), array( 'x', 's', 'z' ), true ) ) {
			$candidates[] = $signal . 'es';
		}

		// base + s
		$candidates[] = $signal . 's';

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Split a raw search string into meaningful signal terms.
	 *
	 * Lowercases the query, splits on whitespace, and strips common
	 * English stopwords and single-character tokens. Returns an empty
	 * array if every word is a stopword — callers treat that as "fall
	 * back to WooCommerce default behavior."
	 *
	 * @since 0.9.0
	 *
	 * @param string $query Raw search query.
	 * @return string[]     Lowercase signal terms.
	 */
	public static function extract_search_terms( string $query ): array {
		static $stopwords = array(
			'a',
			'an',
			'the',
			'and',
			'or',
			'for',
			'in',
			'on',
			'at',
			'to',
			'of',
			'from',
			'by',
			'with',
			'is',
			'are',
			'was',
			'were',
			'be',
			'i',
			'me',
			'my',
			'we',
			'our',
			'you',
			'your',
			'it',
			'its',
			'this',
			'that',
			'these',
			'those',
			'some',
			'any',
		);

		// Strip apostrophes in-place (women's → womens) so possessives and
		// contractions don't produce a token with a literal apostrophe that
		// would never match a product title. Other punctuation (hyphens,
		// slashes) is converted to spaces so compound words split into
		// separate signal tokens ("mid-layer" → "mid", "layer"). Everything
		// else that is not a letter or digit is then removed.
		$normalised = strtolower( trim( $query ) );
		$normalised = str_replace( "'", '', $normalised );      // apostrophes: don't split.
		$normalised = preg_replace( '/[-\/]+/', ' ', $normalised ); // hyphens/slashes: split.
		$normalised = preg_replace( '/[^a-z0-9 ]/', '', (string) $normalised ); // everything else: drop.

		$words = preg_split( '/\s+/', (string) $normalised, -1, PREG_SPLIT_NO_EMPTY );
		return array_values(
			array_filter(
				(array) $words,
				static function ( string $w ) use ( $stopwords ) {
					return strlen( $w ) > 1 && ! in_array( $w, $stopwords, true );
				}
			)
		);
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
