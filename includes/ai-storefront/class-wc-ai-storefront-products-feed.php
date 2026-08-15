<?php
/**
 * Shopify-compatible /products.json catalog feed.
 *
 * Serves the store catalog in Shopify's public products.json shape at the
 * endpoints AI agents are trained to probe (`/products.json` and the
 * `/collections/all/products.json` alias). NON-UCP, additive compatibility
 * surface — does not alter the UCP manifest, REST/MCP, llms.txt, or JSON-LD.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Shopify-compatible products.json rewrite endpoints and maps
 * WooCommerce products into the Shopify product JSON shape.
 */
class WC_AI_Storefront_Products_Feed {

	const PRODUCT_FILTER = 'wc_ai_storefront_products_feed_product';

	/**
	 * Filter applied to one mapped collection (category) before it enters the
	 * /collections.json list. Mirrors PRODUCT_FILTER.
	 */
	const COLLECTION_FILTER = 'wc_ai_storefront_products_feed_collection';

	/**
	 * Query var both rewrite rules resolve to. WP routes /products.json and
	 * the /collections/all/products.json alias through the same handler.
	 */
	const QUERY_VAR = 'wc_ai_storefront_products_json';

	/**
	 * Query var carrying the product slug for the v2 single-product endpoint
	 * `/products/{handle}.json`.
	 */
	const QUERY_VAR_PRODUCT = 'wc_ai_storefront_product_json';

	/**
	 * Query var carrying the category slug for the v2 per-collection endpoint
	 * `/collections/{handle}/products.json`.
	 */
	const QUERY_VAR_COLLECTION = 'wc_ai_storefront_collection_json';

	/**
	 * Flag query var for the v2 collection-list endpoint `/collections.json`.
	 */
	const QUERY_VAR_COLLECTIONS = 'wc_ai_storefront_collections_json';

	/**
	 * Option holding the monotonically-increasing feed cache version. Bumped
	 * by the cache invalidator on product/settings change; because the cache
	 * key embeds it, a single bump orphans every cached page at once.
	 */
	const VERSION_OPTION = 'wc_ai_storefront_products_feed_version';

	/**
	 * Per-page cache lifetime. The version key handles correctness on change;
	 * the TTL is just a backstop so untouched pages don't live forever.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Register the /products.json and /collections/all/products.json rewrites.
	 * Both resolve to the same all-products feed query var.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^products\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^collections/all/products\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		// v2 single-product: /products/{handle}.json. `([^/]+)` matches any
		// slug (hyphens included). Distinct prefix from the bulk
		// `^products\.json$` rule, so no precedence collision here.
		add_rewrite_rule( '^products/([^/]+)\.json$', 'index.php?' . self::QUERY_VAR_PRODUCT . '=$matches[1]', 'top' );
		// v2 per-collection: /collections/{handle}/products.json. The
		// `(?!all/)` negative lookahead is load-bearing. `add_rewrite_rule(…,
		// 'top')` PREPENDS, so this rule is actually matched BEFORE the bulk
		// `^collections/all/products\.json$` alias above — source-line position
		// does NOT protect the alias. Without the lookahead, `([^/]+)` would
		// capture `all` and route /collections/all/products.json into THIS
		// per-collection handler, which would then look up a product_cat
		// literally slugged `all` and 404. The lookahead makes this rule
		// structurally unable to match `all/`, so the alias is reached
		// regardless of ordering. A category genuinely slugged e.g.
		// `all-weather` is unaffected (the lookahead rejects only the exact
		// `all/` segment).
		add_rewrite_rule( '^collections/(?!all/)([^/]+)/products\.json$', 'index.php?' . self::QUERY_VAR_COLLECTION . '=$matches[1]', 'top' );
		// v2 collection list: /collections.json.
		add_rewrite_rule( '^collections\.json$', 'index.php?' . self::QUERY_VAR_COLLECTIONS . '=1', 'top' );
	}

	/**
	 * Register the query var so WP keeps it through the rewrite.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_PRODUCT;
		$vars[] = self::QUERY_VAR_COLLECTION;
		$vars[] = self::QUERY_VAR_COLLECTIONS;
		return $vars;
	}

	/**
	 * Short-circuit WordPress's canonical-URL redirect for the feed endpoint.
	 *
	 * On trailing-slash permalink structures (the WordPress.com / Atomic
	 * default) core would 301 `/products.json` to a slashed variant that no
	 * longer matches the rewrite rule — the redirected request falls through
	 * to a 404 and probing agents give up. Mirrors
	 * WC_AI_Storefront_Llms_Txt::suppress_canonical_redirect(): return false
	 * only when our query var is set, leaving canonical behaviour elsewhere
	 * on the site untouched.
	 *
	 * @param string|false $redirect_url The candidate canonical URL WordPress
	 *                                   wants to redirect to.
	 * @return string|false              False disables the redirect; the
	 *                                   original value otherwise.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		foreach ( [ self::QUERY_VAR, self::QUERY_VAR_PRODUCT, self::QUERY_VAR_COLLECTION, self::QUERY_VAR_COLLECTIONS ] as $var ) {
			if ( get_query_var( $var ) ) {
				return false;
			}
		}
		return $redirect_url;
	}

	/**
	 * Serve the Shopify-compatible products.json feed.
	 *
	 * Gate (enabled + products_json_enabled) → headers → OPTIONS preflight
	 * → echo the cached JSON body → exit. Mirrors serve_llms_txt()/
	 * serve_agents_md(): rewrite path + discovery cache-control + Vary: Host
	 * make it edge-cacheable, so the platform rate-limiter never sees the
	 * uncached burst that throttles /wp-json discovery probes.
	 */
	public function serve_products_feed(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! $this->feed_enabled() ) {
			status_header( 404 );
			exit;
		}

		$this->send_feed_headers();
		if ( $this->is_options_request() ) {
			status_header( 204 );
			exit;
		}

		echo $this->get_cached_feed_json( $this->request_limit(), $this->request_page() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded JSON.
		exit;
	}

	/**
	 * Serve the v2 single-product endpoint /products/{handle}.json.
	 *
	 * Same gate/header/OPTIONS preamble as serve_products_feed(), then
	 * resolves the slug to a catalog-visible, syndicated, published product
	 * and emits `{ "product": { … } }` — Shopify's SINGULAR `product` key
	 * holding an OBJECT, not the bulk `{ "products": [ … ] }`. A slug that
	 * doesn't resolve (unknown, or resolving only to a hidden/unsyndicated
	 * product) 404s rather than leaking the product.
	 */
	public function serve_single_product(): void {
		$handle = (string) get_query_var( self::QUERY_VAR_PRODUCT );
		if ( '' === $handle ) {
			return;
		}
		if ( ! $this->feed_enabled() ) {
			status_header( 404 );
			exit;
		}

		$this->send_feed_headers();
		if ( $this->is_options_request() ) {
			status_header( 204 );
			exit;
		}

		// Resolved AFTER headers so OPTIONS/gate are uniform with the other
		// endpoints; a handle miss returns a bodyless 404 (the json
		// content-type header is harmless on an empty 404).
		$json = $this->get_cached_single_product( $handle );
		if ( null === $json ) {
			status_header( 404 );
			exit;
		}

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded JSON.
		exit;
	}

	/**
	 * Serve the v2 per-collection endpoint /collections/{handle}/products.json.
	 *
	 * Same gate/header/OPTIONS preamble, then emits the syndicated products in
	 * the named category as `{ "products": [ … ] }`, paginated like the bulk
	 * feed. Unlike the single-product endpoint, an unknown OR empty-after-gate
	 * category returns `200 { "products": [] }` (a uniform empty body, never a
	 * 404) — only the global gate (plugin/feed off) 404s. Uniform empties
	 * avoid leaking which category slugs exist.
	 */
	public function serve_collection_products(): void {
		$handle = (string) get_query_var( self::QUERY_VAR_COLLECTION );
		if ( '' === $handle ) {
			return;
		}
		if ( ! $this->feed_enabled() ) {
			status_header( 404 );
			exit;
		}

		$this->send_feed_headers();
		if ( $this->is_options_request() ) {
			status_header( 204 );
			exit;
		}

		echo $this->get_cached_collection_products( $handle, $this->request_limit(), $this->request_page() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded JSON.
		exit;
	}

	/**
	 * Serve the v2 collection-list endpoint /collections.json.
	 *
	 * Same gate/header/OPTIONS preamble, then emits the store's categories as
	 * `{ "collections": [ … ] }`. Only categories with at least one
	 * catalog-visible, syndicated product are listed, and `products_count` is
	 * that post-gate count — so /collections.json and the per-collection
	 * endpoint stay mutually consistent (no advertised-but-empty categories).
	 */
	public function serve_collections(): void {
		if ( ! get_query_var( self::QUERY_VAR_COLLECTIONS ) ) {
			return;
		}
		if ( ! $this->feed_enabled() ) {
			status_header( 404 );
			exit;
		}

		$this->send_feed_headers();
		if ( $this->is_options_request() ) {
			status_header( 204 );
			exit;
		}

		echo $this->get_cached_collections(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded JSON.
		exit;
	}

	/**
	 * Whether the feed is servable: plugin enabled AND the products.json
	 * toggle on. The shared 404 gate for every feed endpoint.
	 */
	private function feed_enabled(): bool {
		$settings = WC_AI_Storefront::get_settings();
		return 'yes' === ( $settings['enabled'] ?? 'no' ) && 'yes' === ( $settings['products_json_enabled'] ?? 'no' );
	}

	/**
	 * Emit the edge-cache + CORS + content-type headers every feed endpoint
	 * sends. Rewrite path + discovery cache-control + Vary: Host make the
	 * response edge-cacheable so the platform rate-limiter never sees the
	 * uncached burst that throttles /wp-json discovery probes. The
	 * `X-Robots-Tag: noindex` keeps the machine surface out of search
	 * indexes, matching the sibling /opensearch.xml endpoint.
	 */
	private function send_feed_headers(): void {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: ' . WC_AI_Storefront::discovery_cache_control() );
		header( 'Vary: Host' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
	}

	/**
	 * Whether the current request is a CORS preflight (OPTIONS).
	 */
	private function is_options_request(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === wp_unslash( $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a constant.
	}

	/**
	 * Return the page body, going through the cache.
	 *
	 * Key = host + feed version + limit + page. The version component means a
	 * single bump (on product/settings change, via the cache invalidator)
	 * orphans every cached page at once without enumerating keys. Host-scoping
	 * keeps multi-domain installs (www vs apex, alias domains) from serving
	 * one host's body under another's Vary: Host edge entry.
	 *
	 * @param int $limit Per-page count.
	 * @param int $page  1-based page.
	 * @return string JSON.
	 */
	private function get_cached_feed_json( int $limit, int $page ): string {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only inside an md5 cache key.
		$key     = 'wc_ai_sf_pjson_' . md5( $host . "|{$version}|{$limit}|{$page}" );

		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$json = $this->get_feed_json( $limit, $page );
		set_transient( $key, $json, self::CACHE_TTL );
		return $json;
	}

	/**
	 * Return the single-product body, going through the cache. Null (a 404,
	 * NOT cached) when the slug doesn't resolve to a servable product.
	 *
	 * Key family `wc_ai_sf_prod_` is distinct from the paginated bulk feed's
	 * `wc_ai_sf_pjson_`, but embeds the SAME version integer — so the single
	 * global version bump (on product/settings change) orphans single-product
	 * entries along with every other family at once. No pagination component.
	 *
	 * @param string $handle Product slug.
	 * @return string|null JSON, or null to 404.
	 */
	private function get_cached_single_product( string $handle ): ?string {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only inside an md5 cache key.
		$key     = 'wc_ai_sf_prod_' . md5( $host . "|{$version}|{$handle}" );

		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$json = $this->build_single_product_json( $handle );
		if ( null === $json ) {
			return null;
		}
		set_transient( $key, $json, self::CACHE_TTL );
		return $json;
	}

	/**
	 * Resolve a slug to a servable product and build `{ "product": { … } }`,
	 * or null when it doesn't resolve.
	 *
	 * The leak-proof gate: `visibility => 'catalog'` makes WC drop Hidden /
	 * Search-only products at the query, and is_product_syndicated() then
	 * applies the merchant's syndication scope. A slug that exists but points
	 * at a hidden or unsyndicated product returns null (404) — it must never
	 * 200 with the product body. WC enforces unique product slugs, so
	 * `limit => 1` is exact.
	 *
	 * @param string $handle Product slug.
	 * @return string|null
	 */
	private function build_single_product_json( string $handle ): ?string {
		if ( ! function_exists( 'get_page_by_path' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		// Resolve the slug to a product via get_page_by_path() — NOT
		// wc_get_products( [ 'slug' => … ] ): `slug` is not a supported
		// wc_get_products arg (unlike `category`), so it is silently ignored
		// and the query returns the FIRST product for every handle. This is the
		// same resolver the UCP catalog/lookup `?slug=` path uses.
		$post = get_page_by_path( $handle, OBJECT, 'product' );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		// Catalog-visibility leak guard — the equivalent of the bulk feed's
		// `visibility => 'catalog'` query arg (which get_page_by_path can't
		// express). Only products that appear in catalog listings qualify:
		// 'visible' (shop + search) and 'catalog' (shop only). 'search'
		// (search-only) and 'hidden' must 404, never leak.
		if ( ! in_array( $product->get_catalog_visibility(), [ 'visible', 'catalog' ], true ) ) {
			return null;
		}
		if ( ! WC_AI_Storefront::is_product_syndicated( $product, WC_AI_Storefront::get_settings() ) ) {
			return null;
		}
		return (string) wp_json_encode( [ 'product' => self::map_product( $product ) ] );
	}

	/**
	 * Return the per-collection page body, going through the cache. Key family
	 * `wc_ai_sf_coll_` embeds the slug AND limit+page (paginated bodies must
	 * not collide) plus the shared version integer, so one global bump orphans
	 * it with every other family. An empty result is a valid body and is
	 * cached; a category that doesn't exist yet refreshes on the version bump
	 * the invalidator fires when a product_cat term is created.
	 *
	 * @param string $handle Category slug.
	 * @param int    $limit  Per-page count.
	 * @param int    $page   1-based page.
	 * @return string JSON.
	 */
	private function get_cached_collection_products( string $handle, int $limit, int $page ): string {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only inside an md5 cache key.
		$key     = 'wc_ai_sf_coll_' . md5( $host . "|{$version}|{$handle}|{$limit}|{$page}" );

		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$json = $this->build_collection_products_json( $handle, $limit, $page );
		set_transient( $key, $json, self::CACHE_TTL );
		return $json;
	}

	/**
	 * Build `{ "products": [ … ] }` for the products in one category.
	 *
	 * Unknown category slug → empty products (the caller serves it 200). The
	 * same leak-proof gate as every other endpoint: `visibility => 'catalog'`
	 * drops Hidden / Search-only products at the query and is_product_syndicated()
	 * applies syndication scope — so a hidden product assigned to a visible
	 * category never appears here.
	 *
	 * @param string $handle Category slug.
	 * @param int    $limit  Per-page count.
	 * @param int    $page   1-based page.
	 * @return string JSON.
	 */
	private function build_collection_products_json( string $handle, int $limit, int $page ): string {
		$empty = (string) wp_json_encode( [ 'products' => [] ] );

		$term = get_term_by( 'slug', $handle, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			return $empty;
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $empty;
		}

		$settings = WC_AI_Storefront::get_settings();
		$products = wc_get_products(
			[
				'status'     => 'publish',
				'visibility' => 'catalog',
				'category'   => [ $term->slug ],
				'limit'      => $limit,
				'page'       => $page,
				'paginate'   => false,
				'return'     => 'objects',
			]
		);

		$mapped   = [];
		$products = is_array( $products ) ? $products : [];
		foreach ( $products as $product ) {
			if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
				continue;
			}
			$mapped[] = self::map_product( $product, true );
		}

		return (string) wp_json_encode( [ 'products' => $mapped ] );
	}

	/**
	 * Return the collection-list body, going through the cache. Key family
	 * `wc_ai_sf_colls_` is host + the shared version integer (no pagination —
	 * the list is unpaginated). The per-category visible/syndicated count is
	 * the expensive part, so it's computed once per version bump and reused.
	 *
	 * @return string JSON.
	 */
	private function get_cached_collections(): string {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only inside an md5 cache key.
		$key     = 'wc_ai_sf_colls_' . md5( $host . "|{$version}" );

		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$json = $this->build_collections_json();
		set_transient( $key, $json, self::CACHE_TTL );
		return $json;
	}

	/**
	 * Build `{ "collections": [ … ] }`.
	 *
	 * Lists only product_cat terms with at least one catalog-visible,
	 * syndicated product, so the list never advertises a category the
	 * per-collection endpoint would return empty for. `hide_empty => true`
	 * pre-filters terms with zero products; the post-gate count then drops any
	 * remaining category whose products are all hidden/unsyndicated.
	 *
	 * @return string JSON.
	 */
	private function build_collections_json(): string {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			]
		);
		if ( ! is_array( $terms ) ) {
			return (string) wp_json_encode( [ 'collections' => [] ] );
		}

		$settings    = WC_AI_Storefront::get_settings();
		$collections = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$count = $this->syndicated_count_for_category( $term->slug, $settings );
			if ( $count < 1 ) {
				continue;
			}
			$collections[] = self::map_collection( $term, $count );
		}

		return (string) wp_json_encode( [ 'collections' => $collections ] );
	}

	/**
	 * Count the catalog-visible, syndicated products in one category — the
	 * post-gate count that `products_count` and category inclusion key on.
	 * Uses `return => 'ids'` (cheap) + prime_syndication_cache() so by_taxonomy
	 * mode doesn't issue an N+1 of term-relationship queries.
	 *
	 * @param string $slug     Category slug.
	 * @param array  $settings Plugin settings.
	 * @return int
	 */
	private function syndicated_count_for_category( string $slug, array $settings ): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$ids = wc_get_products(
			[
				'status'     => 'publish',
				'visibility' => 'catalog',
				'category'   => [ $slug ],
				'limit'      => -1,
				'paginate'   => false,
				'return'     => 'ids',
			]
		);
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return 0;
		}
		$ids = array_map( 'intval', $ids );
		WC_AI_Storefront::prime_syndication_cache( $ids, $settings );

		$count = 0;
		foreach ( $ids as $id ) {
			if ( WC_AI_Storefront::is_product_syndicated( $id, $settings ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Map one product_cat term to the Shopify collection shape.
	 *
	 * `published_at` / `updated_at` are null: the `wp_terms` table carries no
	 * created/modified dates, and fabricating one (e.g. "now") would poison
	 * agent diff-sync. Shopify always emits the keys, so they're present as
	 * null rather than omitted. `body_html` is the raw term description
	 * (Shopify's collection description slot).
	 *
	 * @param WP_Term $term           The category term.
	 * @param int     $products_count Post-gate visible/syndicated count.
	 * @return array
	 */
	private static function map_collection( WP_Term $term, int $products_count ): array {
		$data = [
			'id'             => (int) $term->term_id,
			'handle'         => (string) $term->slug,
			'title'          => self::decode( (string) $term->name ),
			'body_html'      => (string) $term->description,
			'published_at'   => null,
			'updated_at'     => null,
			'products_count' => $products_count,
		];

		/**
		 * Filter a single mapped collection before it enters /collections.json.
		 *
		 * @param array   $data The mapped collection.
		 * @param WP_Term $term The source product_cat term.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- COLLECTION_FILTER is the literal 'wc_ai_storefront_products_feed_collection'; the sniff can't resolve the constant to see the prefix.
		$filtered = apply_filters( self::COLLECTION_FILTER, $data, $term );
		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Build the JSON body for one page. Only syndicated products are
	 * included; the syndication settings are resolved once and reused for
	 * every product to avoid redundant option reads.
	 *
	 * @param int $limit Per-page count.
	 * @param int $page  1-based page.
	 * @return string JSON.
	 */
	private function get_feed_json( int $limit, int $page ): string {
		$settings = WC_AI_Storefront::get_settings();

		$query    = [
			'status'     => 'publish',
			// Only products that appear in catalog listings. Excludes the WC
			// "Hidden" and "Search results only" catalog-visibility states
			// (both carry the `exclude-from-catalog` term). is_product_syndicated()
			// gates SCOPE (which products the merchant opted into syndicating),
			// NOT catalog visibility — without this a Hidden product would leak
			// into the public feed, contradicting the spec and the toggle copy.
			'visibility' => 'catalog',
			'limit'      => $limit,
			'page'       => $page,
			'paginate'   => false,
			'return'     => 'objects',
		];
		$products = function_exists( 'wc_get_products' ) ? wc_get_products( $query ) : [];

		$mapped   = [];
		$products = is_array( $products ) ? $products : [];
		foreach ( $products as $product ) {
			if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
				continue;
			}
			$mapped[] = self::map_product( $product, true );
		}

		return (string) wp_json_encode( [ 'products' => $mapped ] );
	}

	/**
	 * Resolve ?limit (default 30, max 250, Shopify-style).
	 *
	 * @return int
	 */
	private function request_limit(): int {
		$raw = isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read endpoint.
		if ( $raw < 1 ) {
			return 30;
		}
		return min( $raw, 250 );
	}

	/**
	 * Resolve ?page (1-based, default 1).
	 *
	 * @return int
	 */
	private function request_page(): int {
		$raw = isset( $_GET['page'] ) ? absint( wp_unslash( $_GET['page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read endpoint.
		return max( 1, $raw );
	}

	/**
	 * Map one WC product to the Shopify product JSON shape (pragmatic full —
	 * the fields a trained parser keys on; Shopify-internal fields omitted).
	 *
	 * @param WC_Product $product The product.
	 * @param bool       $compact When true (list feeds), emit only the first
	 *                            valid image per product; the single-product
	 *                            feed keeps the default (all images).
	 * @return array Shopify-shaped product.
	 */
	public static function map_product( $product, bool $compact = false ): array {
		$is_variable = method_exists( $product, 'is_type' ) && $product->is_type( 'variable' );

		// Shopify emits published_at/created_at/updated_at as RFC 3339 UTC
		// strings. WC has only created/modified dates, so published_at and
		// created_at both map to created (WC has no separate publish date).
		// The method_exists guard keeps Mockery doubles of the date-less
		// WC_Product stub from tripping (and PHPStan honors it), and the
		// `$ts > 0` guard in iso_date() drops an uninitialized WC_DateTime
		// rather than rendering a 1970 date that would poison diff-sync.
		$created  = method_exists( $product, 'get_date_created' ) ? $product->get_date_created() : null;
		$modified = method_exists( $product, 'get_date_modified' ) ? $product->get_date_modified() : null;

		// Order matters here: the variations produce the ownership map, the map
		// completes the image list's variant_ids, and the finished image list
		// is what a variant's featured_image points into.
		$variations = $is_variable ? self::collect_variations( $product ) : [];
		$owner_map  = self::build_image_owner_map( $variations );

		// Build the full image list first, then slice for output. Compact mode
		// truncates the array's LENGTH only — never a retained entry's
		// richness, and never its `position`, which must still rank it within
		// the complete gallery.
		$all_images = self::build_images( $product, $owner_map );

		$data = [
			'id'           => (int) $product->get_id(),
			'title'        => self::decode( (string) $product->get_name() ),
			'handle'       => (string) $product->get_slug(),
			'body_html'    => (string) $product->get_description(),
			'published_at' => self::iso_date( $created ),
			'created_at'   => self::iso_date( $created ),
			'updated_at'   => self::iso_date( $modified ),
			'vendor'       => self::resolve_vendor( $product ),
			'product_type' => self::resolve_product_type( $product ),
			'tags'         => self::resolve_tags( $product ),
			'variants'     => $is_variable
				? self::build_variants( $product, $variations )
				: [ self::build_simple_variant( $product ) ],
			'images'       => $compact ? array_slice( $all_images, 0, 1 ) : $all_images,
		];

		$options = $is_variable ? self::build_options( $product ) : [];
		if ( ! empty( $options ) ) {
			$data['options'] = $options;
		}

		/**
		 * Filter a single mapped Shopify-shaped product before it enters the
		 * /products.json feed. Mirrors `wc_ai_storefront_ucp_product`.
		 *
		 * @param array      $data    The mapped product.
		 * @param WC_Product $product The source product.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- PRODUCT_FILTER is the literal 'wc_ai_storefront_products_feed_product'; the sniff can't resolve the constant to see the prefix.
		$filtered = apply_filters( self::PRODUCT_FILTER, $data, $product );
		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Format a WC date getter result as an RFC 3339 UTC (`Z`) string, or
	 * null. Mirrors the proven idiom in the Store API extension: a real
	 * WC_DateTime is a \DateTimeInterface; `$ts > 0` drops an uninitialized
	 * (epoch-0) or pre-epoch value that would otherwise render as a
	 * misleading 1970 timestamp.
	 *
	 * @param mixed $dt The get_date_created()/get_date_modified() result.
	 * @return string|null
	 */
	private static function iso_date( $dt ): ?string {
		if ( $dt instanceof \DateTimeInterface ) {
			$ts = $dt->getTimestamp();
			if ( $ts > 0 ) {
				return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
			}
		}
		return null;
	}

	/**
	 * Vendor = first product_brand term, else null (genuinely absent).
	 *
	 * @param WC_Product $product The product.
	 * @return string|null
	 */
	private static function resolve_vendor( $product ): ?string {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return null;
		}
		$brands = wp_get_post_terms( (int) $product->get_id(), 'product_brand', [ 'fields' => 'names' ] );
		if ( is_array( $brands ) && ! empty( $brands ) && is_string( $brands[0] ) ) {
			return self::decode( $brands[0] );
		}
		return null;
	}

	/**
	 * Tags = comma-joined product_tag names (Shopify emits a string).
	 *
	 * @param WC_Product $product The product.
	 * @return string
	 */
	private static function resolve_tags( $product ): string {
		$ids = $product->get_tag_ids();
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return '';
		}
		$names = array_filter(
			array_map(
				static function ( $id ) {
					$t = get_term( (int) $id, 'product_tag' );
					return $t instanceof WP_Term ? self::decode( $t->name ) : null;
				},
				$ids
			)
		);
		return implode( ', ', $names );
	}

	/**
	 * Build the single variant for a simple product.
	 *
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private static function build_simple_variant( $product ): array {
		// Key order mirrors Shopify's so a field-by-field diff against a live
		// feed reads cleanly.
		return [
			'id'                => (int) $product->get_id(),
			'title'             => 'Default Title',
			'option1'           => 'Default Title',
			'option2'           => null,
			'option3'           => null,
			'sku'               => (string) $product->get_sku(),
			'requires_shipping' => method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true,
			'taxable'           => self::is_taxable( $product ),
			'available'         => (bool) ( $product->is_in_stock() && $product->is_purchasable() ),
			'price'             => self::money( self::base_price( $product ) ),
			'grams'             => self::weight_grams( $product ),
			'compare_at_price'  => self::compare_at( $product ),
			'position'          => 1,
			'product_id'        => (int) $product->get_id(),
			'created_at'        => self::variant_created_at( $product ),
			'updated_at'        => self::variant_updated_at( $product ),
		];
	}

	/**
	 * Weight in integer grams, converted FROM the store's configured unit.
	 *
	 * wc_get_weight() reads `woocommerce_weight_unit` when $from_unit is
	 * omitted, so a store configured in lbs or oz converts correctly —
	 * assuming kg would silently corrupt every non-metric store. It returns a
	 * float and Shopify types grams as an integer, so the round-and-cast is
	 * required rather than cosmetic.
	 *
	 * An unset weight yields 0, matching Shopify: a live feed sample had 6 of
	 * 413 variants at grams:0 and none at null, three of them physical goods
	 * that simply have no weight recorded.
	 *
	 * Safe to call with either a product or a variation. A variation's
	 * get_weight() falls back to its parent, which is correct here — the
	 * shipping weight of a variation with no weight of its own IS the
	 * parent's. Contrast get_image_id(), where the identical fallback is a
	 * trap (see build_image_owner_map()).
	 *
	 * @param WC_Product $item Product or variation.
	 * @return int
	 */
	private static function weight_grams( $item ): int {
		if ( ! method_exists( $item, 'get_weight' ) || ! function_exists( 'wc_get_weight' ) ) {
			return 0;
		}
		return (int) round( (float) wc_get_weight( (float) $item->get_weight(), 'g' ) );
	}

	/**
	 * A variation's 1-based position.
	 *
	 * WooCommerce stores 0 as "unset" menu order, while Shopify positions
	 * start at 1, so an unset order falls through to the loop index rather
	 * than emitting a 0 that would sort ahead of every real position.
	 *
	 * @param WC_Product $variation The variation.
	 * @param int        $index     1-based index within the variation loop.
	 * @return int
	 */
	private static function variant_position( $variation, int $index ): int {
		if ( ! method_exists( $variation, 'get_menu_order' ) ) {
			return $index;
		}
		$order = (int) $variation->get_menu_order();
		return $order > 0 ? $order : $index;
	}

	/**
	 * Whether the item is taxable.
	 *
	 * WooCommerce has no per-variation tax status — the variation data store
	 * never reads `_tax_status` and unconditionally copies the parent's — so
	 * this can never diverge from the product-level value. That is a fact
	 * about WC core, not a limitation of this feed.
	 *
	 * @param WC_Product $item Product or variation.
	 * @return bool
	 */
	private static function is_taxable( $item ): bool {
		return method_exists( $item, 'get_tax_status' )
			? 'taxable' === $item->get_tax_status()
			: true;
	}

	/**
	 * A variant's created timestamp, or null when the getter is absent.
	 *
	 * @param WC_Product $item Product or variation.
	 * @return string|null
	 */
	private static function variant_created_at( $item ): ?string {
		return self::iso_date( method_exists( $item, 'get_date_created' ) ? $item->get_date_created() : null );
	}

	/**
	 * A variant's modified timestamp, or null when the getter is absent.
	 *
	 * @param WC_Product $item Product or variation.
	 * @return string|null
	 */
	private static function variant_updated_at( $item ): ?string {
		return self::iso_date( method_exists( $item, 'get_date_modified' ) ? $item->get_date_modified() : null );
	}

	/**
	 * A product or variation's price in the store's BASE currency, independent
	 * of any active multi-currency (e.g. WooPayments) presentment.
	 *
	 * The Shopify-compatible feed is a single-currency surface (base) and is
	 * cached, so it must not vary by request geolocation. `get_price('edit')`
	 * returns the raw stored value WITHOUT the `woocommerce_product_get_price`
	 * 'view'-context filter that multi-currency plugins use to convert — and
	 * WooCommerce stores prices in base currency, so 'edit' IS base.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string Base-currency price (may be '' when unset).
	 */
	private static function base_price( $product ): string {
		return (string) $product->get_price( 'edit' );
	}

	/**
	 * Format a price as a 2-decimal string (Shopify emits price as a string).
	 *
	 * @param mixed $price Raw WC price.
	 * @return string
	 */
	private static function money( $price ): string {
		return is_numeric( $price ) ? number_format( (float) $price, 2, '.', '' ) : '0.00';
	}

	/**
	 * Increment the feed cache version, orphaning every cached page/endpoint
	 * at once (each key embeds this version). The feed class owns VERSION_OPTION,
	 * so it owns the bump; the cache invalidator and the upgrade path both call
	 * this. Autoload disabled — the value is read only inside the feed serve path.
	 */
	public static function bump_cache_version(): void {
		update_option( self::VERSION_OPTION, ( (int) get_option( self::VERSION_OPTION, 1 ) ) + 1, false );
	}

	/**
	 * compare_at_price = regular price when on sale, else null.
	 *
	 * @param WC_Product $product The product (or variation).
	 * @return string|null
	 */
	private static function compare_at( $product ): ?string {
		if ( method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
			$regular = $product->get_regular_price( 'edit' );
			if ( is_numeric( $regular ) ) {
				return self::money( $regular );
			}
		}
		return null;
	}

	/**
	 * Build images[] from the featured image + gallery (needed by both simple
	 * and variable products, so defined here with the simple-product path).
	 *
	 * Sources are the featured image, then the gallery, then any image owned
	 * by an individual variation. That last source matters: WooCommerce sets
	 * variation images in the variation editor, outside the parent gallery,
	 * whereas Shopify guarantees a variant's image is always one of the
	 * product's images (verified 110 of 110 against a live feed). Without the
	 * union, variant_ids would be empty on every image for a typical store.
	 *
	 * Compact truncation is deliberately NOT applied here — the caller slices
	 * the result. `position` must rank an image within the full gallery, not
	 * within whatever survived truncation, so the numbering has to be assigned
	 * before any slicing.
	 *
	 * @param WC_Product $product   The product.
	 * @param array      $owner_map attachment id => list of owning variation ids.
	 * @return array
	 */
	private static function build_images( $product, array $owner_map = [] ): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_merge(
						[ (int) $product->get_image_id() ],
						array_map( 'intval', (array) $product->get_gallery_image_ids() ),
						array_map( 'intval', array_keys( $owner_map ) )
					)
				)
			)
		);

		$images   = [];
		$position = 1;
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( ! is_string( $src ) || '' === $src ) {
				continue;
			}
			$images[] = self::build_image_record( $id, $src, $product, $position, $owner_map );
			++$position;
		}

		return $images;
	}

	/**
	 * One image record, used verbatim in both `images[]` and a variant's
	 * `featured_image` — Shopify uses the same struct in both positions.
	 *
	 * Every read here lands on a cache the caller already primed: the
	 * wp_get_attachment_image_url() call above runs get_post() and
	 * wp_get_attachment_metadata() internally, and WP's meta API caches all of
	 * an object's meta rows on first read. Moving these reads before that URL
	 * lookup, or onto ids it skipped, would turn each one back into a query.
	 *
	 * Shopify omits `alt` from images[] and includes it on featured_image. We
	 * emit it in both: alt text is often the only description of what is
	 * actually IN a photo, which is precisely what a vision-less agent needs,
	 * so withholding it from the more commonly read position to imitate an
	 * inconsistency would serve nobody.
	 *
	 * @param int        $id        Attachment id.
	 * @param string     $src       Resolved full-size URL.
	 * @param WC_Product $product   Owning product.
	 * @param int        $position  1-based rank within the full gallery.
	 * @param array      $owner_map attachment id => list of owning variation ids.
	 * @return array
	 */
	private static function build_image_record( int $id, string $src, $product, int $position, array $owner_map ): array {
		$meta = wp_get_attachment_metadata( $id );
		$post = get_post( $id );
		$alt  = get_post_meta( $id, '_wp_attachment_image_alt', true );

		return [
			'id'          => $id,
			'product_id'  => (int) $product->get_id(),
			'position'    => $position,
			'created_at'  => is_object( $post ) ? self::gmt_date( $post->post_date_gmt ?? null ) : null,
			'updated_at'  => is_object( $post ) ? self::gmt_date( $post->post_modified_gmt ?? null ) : null,
			'alt'         => ( is_string( $alt ) && '' !== $alt ) ? self::decode( $alt ) : null,
			'width'       => ( is_array( $meta ) && isset( $meta['width'] ) ) ? (int) $meta['width'] : null,
			'height'      => ( is_array( $meta ) && isset( $meta['height'] ) ) ? (int) $meta['height'] : null,
			'src'         => $src,
			'variant_ids' => array_values( $owner_map[ $id ] ?? [] ),
		];
	}

	/**
	 * Format a WordPress GMT date string as RFC 3339 UTC, or null.
	 *
	 * The sibling of iso_date(), which takes a WC_DateTime. Attachments carry
	 * post_date_gmt/post_modified_gmt strings instead, and an unset one is
	 * '0000-00-00 00:00:00' — which must not render as a year-zero timestamp,
	 * for the same reason iso_date() drops epoch 0.
	 *
	 * @param mixed $gmt Raw post_date_gmt / post_modified_gmt.
	 * @return string|null
	 */
	private static function gmt_date( $gmt ): ?string {
		if ( ! is_string( $gmt ) || '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
			return null;
		}
		$ts = strtotime( $gmt . ' UTC' );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null;
	}

	/**
	 * Hydrate a variable product's variations once.
	 *
	 * Both the image-owner map and the variant list need them, and resolving
	 * separately would double the wc_get_product() calls per product.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private static function collect_variations( $product ): array {
		$variations = [];
		foreach ( $product->get_children() as $child_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $child_id ) : null;
			if ( $variation ) {
				$variations[] = $variation;
			}
		}
		return $variations;
	}

	/**
	 * Reverse WooCommerce's image relation into Shopify's.
	 *
	 * WooCommerce points each variation at one image. Shopify lists, per
	 * image, every variant that uses it — one colourway photo covering all of
	 * its sizes. That reverse index is how an agent picks the right photo when
	 * a shopper chooses a colour.
	 *
	 * The 'edit' context is mandatory, not stylistic. WC_Product_Variation
	 * overrides get_image_id() to fall back to the PARENT's image whenever the
	 * variation has none of its own and the context is 'view':
	 *
	 *     if ( 'view' === $context && ! $image_id ) {
	 *         $image_id = apply_filters( …, $this->parent_data['image_id'], $this );
	 *     }
	 *
	 * So asking the obvious way — "does this variation have an image?" — gets
	 * "yes, the parent's" from every photo-less variation. That would populate
	 * featured_image where it must be null AND list the entire catalogue of
	 * photo-less variations under the featured image's variant_ids. Neither
	 * failure looks wrong in a smoke test, because both fields come back
	 * populated. base_price() in this file bypasses a view-context filter the
	 * same way, for the same class of reason.
	 *
	 * @param array $variations Variation objects.
	 * @return array attachment id => list of owning variation ids.
	 */
	private static function build_image_owner_map( array $variations ): array {
		$map = [];
		foreach ( $variations as $variation ) {
			if ( ! method_exists( $variation, 'get_image_id' ) ) {
				continue;
			}
			$own_id = (int) $variation->get_image_id( 'edit' );
			if ( $own_id > 0 ) {
				$map[ $own_id ][] = (int) $variation->get_id();
			}
		}
		return $map;
	}

	/**
	 * Build variants[] for a variable product from its variation children.
	 *
	 * option1/2/3 are filled from the variation's attribute values in the
	 * same order as build_options(); unused slots are null.
	 *
	 * @param WC_Product $product    The variable product.
	 * @param array      $variations Variations already hydrated by
	 *                               collect_variations(), so this does not
	 *                               re-resolve what the owner map needed.
	 * @return array
	 */
	private static function build_variants( $product, array $variations ): array {
		// Attribute names in declared order, e.g. pa_size then pa_color.
		$attr_keys = array_keys( $product->get_variation_attributes() );
		$variants  = [];

		$index = 0;
		foreach ( $variations as $variation ) {
			++$index;
			// Selected values keyed by attribute_<slug>, e.g. attribute_pa_size => m.
			$attributes = $variation->get_variation_attributes();

			$options = [ null, null, null ];
			$i       = 0;
			foreach ( $attr_keys as $key ) {
				if ( $i > 2 ) {
					break; // Shopify supports exactly 3 option positions.
				}
				$value         = $attributes[ 'attribute_' . sanitize_title( $key ) ] ?? ( $attributes[ 'attribute_' . $key ] ?? '' );
				$options[ $i ] = '' !== $value ? self::decode( (string) $value ) : null;
				++$i;
			}

			$variants[] = [
				'id'                => (int) $variation->get_id(),
				'title'             => implode(
					' / ',
					array_filter(
						$options,
						static function ( $v ) {
							return null !== $v;
						}
					)
				),
				'option1'           => $options[0],
				'option2'           => $options[1],
				'option3'           => $options[2],
				'sku'               => (string) $variation->get_sku(),
				'requires_shipping' => method_exists( $variation, 'needs_shipping' ) ? (bool) $variation->needs_shipping() : true,
				'taxable'           => self::is_taxable( $variation ),
				'available'         => (bool) ( $variation->is_in_stock() && $variation->is_purchasable() ),
				'price'             => self::money( self::base_price( $variation ) ),
				'grams'             => self::weight_grams( $variation ),
				'compare_at_price'  => self::compare_at( $variation ),
				'position'          => self::variant_position( $variation, $index ),
				'product_id'        => method_exists( $variation, 'get_parent_id' )
					? (int) $variation->get_parent_id()
					: (int) $product->get_id(),
				'created_at'        => self::variant_created_at( $variation ),
				'updated_at'        => self::variant_updated_at( $variation ),
			];
		}

		return $variants;
	}

	/**
	 * Build options[] (name, position, values) for a variable product.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private static function build_options( $product ): array {
		$options  = [];
		$position = 1;
		foreach ( $product->get_variation_attributes() as $name => $values ) {
			if ( $position > 3 ) {
				break;
			}
			$label     = wc_attribute_label( $name, $product );
			$options[] = [
				'name'     => self::decode( (string) $label ),
				'position' => $position,
				'values'   => array_values( array_map( [ self::class, 'decode' ], array_map( 'strval', (array) $values ) ) ),
			];
			++$position;
		}
		return $options;
	}

	/**
	 * Resolve a single Shopify-style `product_type` string from a product's
	 * WooCommerce categories.
	 *
	 * Shopify's `product_type` is a single free-text type (e.g. "Hoodie"),
	 * distinct from collections. WC has no equivalent single field and allows
	 * many `product_cat` terms, so we synthesize one in priority order:
	 *   1. SEO-plugin primary category (Yoast / RankMath meta) — merchant intent.
	 *   2. Most-specific (deepest) assigned category — mimics Shopify's type.
	 *   3. First assigned category.
	 *   4. '' (Shopify always emits the key as a string).
	 *
	 * @param WC_Product $product The product.
	 * @return string Decoded plain-text type, or '' when uncategorized.
	 */
	public static function resolve_product_type( $product ): string {
		$product_id = (int) $product->get_id();

		$term_ids = $product->get_category_ids();
		if ( empty( $term_ids ) || ! is_array( $term_ids ) ) {
			return '';
		}

		// 1. SEO-plugin primary category — but only when the product is STILL
		// assigned to it. The Yoast/RankMath meta is not cleared when a
		// category is unassigned, so a stale id would otherwise emit a
		// product_type the product no longer belongs to.
		$assigned = array_map( 'intval', (array) $term_ids );
		foreach ( [ '_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat' ] as $meta_key ) {
			$primary_id = (int) get_post_meta( $product_id, $meta_key, true );
			if ( $primary_id > 0 && in_array( $primary_id, $assigned, true ) ) {
				$term = get_term( $primary_id, 'product_cat' );
				if ( $term instanceof WP_Term ) {
					return self::decode( $term->name );
				}
			}
		}

		$terms = array_filter(
			array_map(
				static function ( $id ) {
					return get_term( (int) $id, 'product_cat' );
				},
				$term_ids
			),
			static function ( $t ) {
				return $t instanceof WP_Term;
			}
		);
		if ( empty( $terms ) ) {
			return '';
		}

		// 2. Deepest (most-specific) term — greatest ancestor depth.
		usort(
			$terms,
			static function ( $a, $b ) {
				$depth_a = count( get_ancestors( $a->term_id, 'product_cat' ) );
				$depth_b = count( get_ancestors( $b->term_id, 'product_cat' ) );
				if ( $depth_a !== $depth_b ) {
					return $depth_b <=> $depth_a; // Deeper first.
				}
				return $a->term_id <=> $b->term_id; // Stable tiebreak.
			}
		);

		// 3. First (now: deepest, else first assigned) — usort leaves the best at [0].
		return self::decode( $terms[0]->name );
	}

	/**
	 * Decode HTML entities to plain UTF-8 (term/product names arrive encoded).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function decode( string $value ): string {
		return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
