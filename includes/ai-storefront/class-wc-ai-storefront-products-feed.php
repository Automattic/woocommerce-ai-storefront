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
	 * Query var both rewrite rules resolve to. WP routes /products.json and
	 * the /collections/all/products.json alias through the same handler.
	 */
	const QUERY_VAR = 'wc_ai_storefront_products_json';

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
	}

	/**
	 * Register the query var so WP keeps it through the rewrite.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
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
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
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

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) || 'yes' !== ( $settings['products_json_enabled'] ?? 'no' ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: ' . WC_AI_Storefront::discovery_cache_control() );
		header( 'Vary: Host' );
		header( 'X-Content-Type-Options: nosniff' );
		// Machine surface, not a landing page — keep it out of search indexes,
		// matching the sibling /opensearch.xml endpoint.
		header( 'X-Robots-Tag: noindex' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a constant.
			status_header( 204 );
			exit;
		}

		echo $this->get_cached_feed_json( $this->request_limit(), $this->request_page() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded JSON.
		exit;
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

		$mapped = [];
		foreach ( (array) $products as $product ) {
			if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
				continue;
			}
			$mapped[] = self::map_product( $product );
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
	 * @return array Shopify-shaped product.
	 */
	public static function map_product( $product ): array {
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
				? self::build_variants( $product )
				: [ self::build_simple_variant( $product ) ],
			'images'       => self::build_images( $product ),
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
		return [
			'id'                => (int) $product->get_id(),
			'title'             => 'Default Title',
			'option1'           => 'Default Title',
			'option2'           => null,
			'option3'           => null,
			'sku'               => (string) $product->get_sku(),
			'price'             => self::money( $product->get_price() ),
			'compare_at_price'  => self::compare_at( $product ),
			'available'         => (bool) ( $product->is_in_stock() && $product->is_purchasable() ),
			'requires_shipping' => method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true,
		];
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
	 * compare_at_price = regular price when on sale, else null.
	 *
	 * @param WC_Product $product The product (or variation).
	 * @return string|null
	 */
	private static function compare_at( $product ): ?string {
		if ( method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
			$regular = $product->get_regular_price();
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
	 * @param WC_Product $product The product.
	 * @return array
	 */
	private static function build_images( $product ): array {
		$ids    = array_filter( array_merge( [ (int) $product->get_image_id() ], array_map( 'intval', (array) $product->get_gallery_image_ids() ) ) );
		$images = [];
		foreach ( array_unique( $ids ) as $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( is_string( $src ) && '' !== $src ) {
				$images[] = [
					'id'  => $id,
					'src' => $src,
				];
			}
		}
		return $images;
	}

	/**
	 * Build variants[] for a variable product from its variation children.
	 *
	 * option1/2/3 are filled from the variation's attribute values in the
	 * same order as build_options(); unused slots are null.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array
	 */
	private static function build_variants( $product ): array {
		// Attribute names in declared order, e.g. pa_size then pa_color.
		$attr_keys = array_keys( $product->get_variation_attributes() );
		$variants  = [];

		foreach ( $product->get_children() as $child_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $child_id ) : null;
			if ( ! $variation ) {
				continue;
			}
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
				'price'             => self::money( $variation->get_price() ),
				'compare_at_price'  => self::compare_at( $variation ),
				'available'         => (bool) ( $variation->is_in_stock() && $variation->is_purchasable() ),
				'requires_shipping' => method_exists( $variation, 'needs_shipping' ) ? (bool) $variation->needs_shipping() : true,
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
