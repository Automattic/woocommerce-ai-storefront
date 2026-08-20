<?php
/**
 * Human-SERP / social metadata emitter.
 *
 * Self-emits <title>, meta description, Open Graph / Twitter cards, and an
 * opinionated robots noindex on commerce pages, all auto-derived from
 * WooCommerce core data. Zero merchant configuration; developer filters are
 * the only override surface. See
 * docs/superpowers/specs/2026-06-20-serp-social-metadata-design.md.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emits human-facing SERP/social <head> metadata for commerce pages.
 */
class WC_AI_Storefront_Meta_Tags {

	/**
	 * Soft maximum length for the meta description, in characters.
	 */
	private const DESCRIPTION_MAX = 155;

	/**
	 * Memo for authored_description(), resolved at most once per instance.
	 *
	 * `null` means "not yet resolved this request"; any string, including
	 * `''`, means "resolved" and is returned as-is. A plain `''` sentinel
	 * would be indistinguishable from a resolved-but-empty result and would
	 * recompute on every call, defeating the memo. A request renders exactly
	 * one page through exactly one instance of this class, so there is
	 * nothing to invalidate once set.
	 *
	 * @var string|null
	 */
	private $authored_description_memo = null;

	/**
	 * Whether to emit metadata for the current request.
	 *
	 * NOT gated on SEO-plugin presence — per the assert-and-warn design we
	 * always emit on commerce pages; {@see WC_AI_Storefront_Schema_Conflict_Notice}
	 * handles the migration nudge separately.
	 */
	public function should_emit(): bool {
		/**
		 * Master switch for the entire metadata layer.
		 *
		 * @param bool $emit Default true.
		 */
		if ( ! (bool) apply_filters( 'wc_ai_storefront_emit_meta_tags', true ) ) {
			return false;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return false;
		}

		return ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_search' ) && is_search() && 'product' === get_query_var( 'post_type' ) );
	}

	/**
	 * Build the meta description for a product.
	 *
	 * Authored intent wins (#668): the merchant's own Jetpack-authored
	 * description, when present, is tried first, through the same
	 * clean/truncate treatment as every other candidate — symmetric with
	 * build_archive_description()'s shop/category handling. Falls through to
	 * the short description, then the long description, then
	 * "{name} at {store}" when nothing has text, so the page always carries
	 * a description. suppress_jetpack_description() always removes
	 * Jetpack's own tag on commerce pages (see its docblock), so whatever
	 * this method returns is always the description that gets printed.
	 *
	 * @param WC_Product $product Product to derive from.
	 * @return string Cleaned, truncated description (non-empty when the product has a name).
	 */
	public function build_description( $product ): string {
		$candidates = array(
			$this->authored_description(),
			(string) $product->get_short_description(),
			(string) $product->get_description(),
		);

		$description = '';
		foreach ( $candidates as $raw ) {
			$text = $this->clean_text( $raw );
			if ( '' !== $text ) {
				$description = $this->truncate( $text, self::DESCRIPTION_MAX );
				break;
			}
		}

		if ( '' === $description ) {
			// No authored SEO description, short description, or long
			// description: fall back to the product name so the page
			// always carries one. Required because
			// suppress_jetpack_description() always removes Jetpack's
			// description on commerce pages and would otherwise leave none.
			$name = (string) $product->get_name();
			if ( '' !== $name ) {
				$store    = (string) get_bloginfo( 'name' );
				$fallback = '' !== $store
					? sprintf(
						/* translators: 1: product name, 2: store name. */
						__( '%1$s at %2$s.', 'woocommerce-ai-storefront' ),
						$name,
						$store
					)
					: $name;
				$description = $this->truncate( $this->clean_text( $fallback ), self::DESCRIPTION_MAX );
			}
		}

		/**
		 * Filter the auto-derived meta description.
		 *
		 * @param string     $description Derived description ('' when none).
		 * @param WC_Product $product     Source product.
		 */
		return (string) apply_filters( 'wc_ai_storefront_meta_description', $description, $product );
	}

	/**
	 * Strip shortcodes + HTML and collapse whitespace.
	 */
	private function clean_text( string $raw ): string {
		$raw = strip_shortcodes( $raw );
		$raw = wp_strip_all_tags( $raw );
		return trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
	}

	/**
	 * Truncate to a soft max on a word boundary, appending an ellipsis.
	 */
	private function truncate( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut ) . '…';
	}

	/**
	 * Build the meta description for the current archive (category or shop).
	 *
	 * Category → the term's description, falling back to a generated
	 * "Shop {category} at {store}". Shop → the shop page content, falling back
	 * to the store tagline. Cleaned/truncated like the product path.
	 *
	 * @return string Cleaned, truncated description (non-empty on category/shop pages).
	 */
	public function build_archive_description(): string {
		$raw    = '';
		$source = null;

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term   = get_queried_object();
			$source = $term;
			if ( is_object( $term ) && isset( $term->description ) ) {
				$raw = (string) $term->description;
			}
			if ( '' === trim( $raw ) && is_object( $term ) && isset( $term->name ) ) {
				// No authored term description: emit a category-specific
				// fallback so the page always carries one. Required because we
				// suppress Jetpack's description on commerce pages (see
				// suppress_jetpack_description()) and would otherwise leave none.
				$store = (string) get_bloginfo( 'name' );
				$raw   = '' !== $store
					? sprintf(
						/* translators: 1: product category name, 2: store name. */
						__( 'Shop %1$s at %2$s.', 'woocommerce-ai-storefront' ),
						(string) $term->name,
						$store
					)
					: sprintf(
						/* translators: %s: product category name. */
						__( 'Shop %s.', 'woocommerce-ai-storefront' ),
						(string) $term->name
					);
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			// Authored intent wins (#668). Jetpack cannot emit this one
			// itself — `Jetpack_SEO::meta_tags()` gates per-post description
			// on `is_singular()`, false on the product archive — so we carry
			// it. Precedence: authored post field, then Jetpack's site-wide
			// front-page option, then shop content, then tagline.
			$raw = $this->authored_description();

			if ( '' === trim( $raw ) ) {
				$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
				if ( $shop_id > 0 ) {
					$raw = (string) get_post_field( 'post_content', $shop_id );
				}
			}
			if ( '' === trim( $raw ) ) {
				$raw = (string) get_bloginfo( 'description' ); // store tagline
			}
		}

		$text        = $this->clean_text( $raw );
		$description = '' !== $text ? $this->truncate( $text, self::DESCRIPTION_MAX ) : '';

		/** This filter is documented in build_description(). */
		return (string) apply_filters( 'wc_ai_storefront_meta_description', $description, $source );
	}

	/**
	 * `document_title_parts` callback — enrich the product title with its brand.
	 *
	 * Hooked at a late priority so we win over an active SEO plugin (there is
	 * only one <title>, so this never duplicates). Non-product commerce pages
	 * keep core's title (it already supplies the term/shop name); we only add
	 * the brand on single products.
	 *
	 * @param array $parts Title parts (keys: title, page, tagline, site).
	 * @return array
	 */
	public function filter_title_parts( array $parts ): array {
		if ( ! $this->should_emit() ) {
			return $parts;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
			if ( $product ) {
				// Authored intent wins (#668). Our job here is appending the
				// brand; a merchant who wrote their own headline has already
				// said what they want, and Jetpack applies that headline
				// itself on singular pages. The brand still travels as a
				// discrete `brand` field in the product JSON-LD, which is
				// where it does the machine-readable work.
				if ( '' !== WC_AI_Storefront_Authored_SEO::post_title( (int) get_queried_object_id() ) ) {
					// Skips the wc_ai_storefront_meta_title_parts filter below;
					// that filter documents itself as running "after our
					// enrichment", and on this path there is none.
					return $parts;
				}

				$title = $product->get_name();
				$brand = $this->get_brand_name( $product );
				if ( '' !== $brand ) {
					$site_name = (string) ( $parts['site'] ?? ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '' ) );
					if ( ! $this->brand_is_redundant( $brand, $title, $site_name ) ) {
						$title .= ' | ' . $brand;
					}
				}
				$parts['title'] = $title;
			}
		}

		/**
		 * Filter the title parts after our enrichment.
		 *
		 * @param array $parts Title parts.
		 */
		return (array) apply_filters( 'wc_ai_storefront_meta_title_parts', $parts );
	}

	/**
	 * `pre_get_document_title` callback — apply the Shop page's authored title.
	 *
	 * Only the shop archive is handled. WooCommerce renders the product
	 * archive at the Shop page's URL, so the Shop page post is never what
	 * WordPress or Jetpack resolves from the query, and the SEO title a
	 * merchant typed into that page does nothing. We resolve it explicitly
	 * through `wc_get_page_id( 'shop' )`.
	 *
	 * This overrides a title Jetpack already produced rather than only
	 * filling an empty slot, because on a shop-as-front-page Jetpack DOES
	 * reach its per-post branch, and reads the wrong post. WordPress sets
	 * the global post to the first item in the loop on a non-singular query
	 * (`WP::register_globals()` -> `WP_Query::$post` -> `reset( $this->posts )`),
	 * which on the product archive is a product. Deferring would honour a
	 * product's SEO title on the shop page. With nothing authored on the Shop
	 * page we return the incoming value untouched. (#668)
	 *
	 * `pre_get_document_title` rather than `document_title_parts`: this
	 * short-circuits `wp_get_document_title()` and emits the authored string
	 * verbatim, where the parts filter would append the site name to a
	 * headline the merchant had already finished.
	 *
	 * Excludes product search (#668 review): WooCommerce defines `is_shop()`
	 * as `is_post_type_archive( 'product' ) || is_page( wc_get_page_id( 'shop' ) )`,
	 * and a product search query (`?s=&post_type=product`) satisfies the
	 * first branch too — `is_shop()` is true there as well as `is_search()`.
	 * That page needs the search-results title core already builds, not the
	 * Shop page's authored one; render_head_tags() already treats product
	 * search as a distinct case for the same reason.
	 *
	 * Preserves pagination (#668 review): short-circuiting this filter drops
	 * the `$title['page']` segment `wp_get_document_title()` would otherwise
	 * have assembled (wp-includes/general-template.php, "Add a page number
	 * if necessary"), so `/shop/`, `/shop/page/2/`, `/shop/page/3/` would
	 * otherwise all emit an identical `<title>`. We append a page suffix in
	 * the same "$title $sep Page $n" shape core uses, on the same
	 * `document_title_separator` filter, when paginated.
	 *
	 * @since 0.39.0
	 *
	 * @param mixed $title Title resolved so far ('' when nothing has claimed it).
	 * @return mixed Authored title, or the input unchanged.
	 */
	public function filter_document_title( $title ) {
		if ( ! $this->should_emit() ) {
			return $title;
		}
		if ( ! ( function_exists( 'is_shop' ) && is_shop() ) ) {
			return $title;
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return $title;
		}

		$shop_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		$authored = WC_AI_Storefront_Authored_SEO::post_title( $shop_id );

		if ( '' === $authored ) {
			return $title;
		}

		// Escape: this value is merchant-authored post meta
		// (`jetpack_seo_html_title`), and Jetpack registers that meta with
		// no `sanitize_callback` (`Jetpack_SEO_Titles::register_post_meta()`),
		// so it can carry raw markup — Jetpack's own reader escapes it
		// (`esc_html( $custom_title )` in `Jetpack_SEO_Titles::get_post_title()`)
		// before use. A non-empty return from this filter short-circuits
		// `wp_get_document_title()` (wp-includes/general-template.php,
		// ~1192-1195) straight into `_wp_render_title_tag()`'s
		// `echo '<title>' . wp_get_document_title() . '</title>'` (~1315),
		// which does not escape. `<title>` is RCDATA, so an unescaped value
		// here can break out with `</title><script>...`. Do not remove this
		// call; it is the only thing standing between merchant-authored
		// post meta and the page's <head>. (#668)
		$authored = esc_html( $authored );

		$paged_raw = get_query_var( 'paged' );
		$paged     = $paged_raw ? (int) $paged_raw : 1;
		if ( $paged >= 2 ) {
			/** This filter is documented in wp-includes/general-template.php */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally re-invoking WP core's own `document_title_separator` filter, so a merchant's separator customization (e.g. via a theme) applies here too, the same way it would to core's own page-number suffix.
			$sep       = (string) apply_filters( 'document_title_separator', '-' );
			$authored .= ' ' . $sep . ' ' . sprintf(
				/* translators: %s: Page number. */
				__( 'Page %s', 'woocommerce-ai-storefront' ),
				$paged
			);
		}

		return $authored;
	}

	/**
	 * First brand name from the core `product_brand` taxonomy, or '' if none.
	 *
	 * @param WC_Product $product Product.
	 * @return string Brand name, or empty string if none.
	 */
	private function get_brand_name( $product ): string {
		$terms = get_the_terms( $product->get_id(), 'product_brand' );
		if ( is_array( $terms ) && ! empty( $terms ) && isset( $terms[0]->name ) ) {
			return (string) $terms[0]->name;
		}
		return '';
	}

	/**
	 * Whether appending the brand to the title would be redundant.
	 *
	 * On in-house-label stores the brand equals the store name (core already
	 * appends the site segment), and on collaboration products the brand is
	 * already in the product name (e.g. "Saltwarp x Thornwick Tote"). In both
	 * cases re-appending the brand only repeats a word already in the headline.
	 *
	 * The product-name test is a case-insensitive *substring* match, not a
	 * word-boundary one: a brand that is a coincidental substring of the name
	 * (e.g. "Arc" in "Arctic Parka") is also suppressed. This errs on the safe
	 * side for a SERP headline — the only effect is a missing brand suffix on a
	 * minority of products, never a wrong or duplicated one, and the brand is
	 * still carried as a discrete `brand` field in the product JSON-LD output.
	 *
	 * @param string $brand        Brand name (caller guarantees non-empty).
	 * @param string $product_name Product name.
	 * @param string $site_name    Store name (core's site title segment).
	 * @return bool True when the brand should NOT be appended.
	 */
	private function brand_is_redundant( string $brand, string $product_name, string $site_name ): bool {
		$brand_lc = mb_strtolower( $brand );
		if ( $brand_lc === mb_strtolower( $site_name ) ) {
			return true;
		}
		return false !== mb_strpos( mb_strtolower( $product_name ), $brand_lc );
	}

	/**
	 * Build the Open Graph tag map for a product page.
	 *
	 * @param WC_Product  $product     Product.
	 * @param string|null $description Optional pre-built description; when null,
	 *                                 it is derived via build_description() so the
	 *                                 description filter fires only once per render.
	 * @return array<string,string> property => content.
	 */
	public function build_og_tags( $product, ?string $description = null ): array {
		$og = array(
			'og:type'        => 'product',
			'og:title'       => $product->get_name(),
			'og:description' => null === $description ? $this->build_description( $product ) : $description,
			'og:url'         => get_permalink( $product->get_id() ),
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:locale'      => $this->og_locale(),
		);

		$image = get_the_post_thumbnail_url( $product->get_id(), 'full' );
		if ( is_string( $image ) && '' !== $image ) {
			$og['og:image'] = $image;
		}

		if ( $product->is_purchasable() ) {
			$price = (string) $product->get_price();
			if ( '' !== $price ) {
				$og['product:price:amount']   = $price;
				$og['product:price:currency'] = get_woocommerce_currency();
			}
		}

		/**
		 * Filter the Open Graph tag map.
		 *
		 * @param array           $og      property => content.
		 * @param WC_Product|null $product Source product, or null on archive pages (category / shop) where there is no single product.
		 */
		return (array) apply_filters( 'wc_ai_storefront_og_tags', $og, $product );
	}

	/**
	 * Derive Twitter Card tags from an Open Graph map.
	 *
	 * @param array<string,string> $og Open Graph map.
	 * @return array<string,string> property => content.
	 */
	public function build_twitter_tags( array $og ): array {
		$tw = array(
			'twitter:card'        => 'summary_large_image',
			'twitter:title'       => $og['og:title'] ?? '',
			'twitter:description' => $og['og:description'] ?? '',
		);
		if ( ! empty( $og['og:image'] ) ) {
			$tw['twitter:image'] = $og['og:image'];
		}
		return $tw;
	}

	/**
	 * Build the Open Graph tag map for a category or shop archive.
	 *
	 * @param string|null $description Optional pre-built description; when null,
	 *                                 it is derived via build_archive_description()
	 *                                 so the description filter fires only once per
	 *                                 render.
	 * @return array<string,string> property => content.
	 */
	public function build_archive_og_tags( ?string $description = null ): array {
		$site = get_bloginfo( 'name' );
		$og   = array(
			'og:type'        => 'website',
			'og:description' => null === $description ? $this->build_archive_description() : $description,
			'og:site_name'   => $site,
			'og:title'       => $site,
			'og:url'         => '',
			'og:locale'      => $this->og_locale(),
		);

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( is_object( $term ) ) {
				if ( isset( $term->name ) ) {
					$og['og:title'] = (string) $term->name;
				}
				$link = isset( $term->term_id ) ? get_term_link( $term ) : '';
				if ( is_string( $link ) && '' !== $link ) {
					$og['og:url'] = $link;
				}
				$thumb_id = isset( $term->term_id ) ? (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) : 0;
				if ( $thumb_id > 0 ) {
					$img = wp_get_attachment_url( $thumb_id );
					if ( is_string( $img ) && '' !== $img ) {
						$og['og:image'] = $img;
					}
				}
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$is_front_page = function_exists( 'is_front_page' ) && is_front_page();
			$shop_id       = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			// When the shop archive is the site front page, the brand is the
			// correct share headline; the bare "Shop" archive title is not.
			if ( $is_front_page ) {
				$og['og:title'] = $site;
			} elseif ( $shop_id > 0 ) {
				$og['og:title'] = get_the_title( $shop_id );
			}
			if ( $shop_id > 0 ) {
				$og['og:url'] = get_permalink( $shop_id );
			}
		}

		// No archive-specific image (shop never has one; a category may lack a
		// thumbnail) → fall back to the site's default so the share card keeps
		// an image even after we suppress Jetpack's auto-generated one.
		if ( empty( $og['og:image'] ) ) {
			$default_image = $this->archive_default_image();
			if ( '' !== $default_image ) {
				$og['og:image'] = $default_image;
			}
		}

		// Fallback only when no branch set a URL — kept lazy so unit tests
		// exercising the category/shop branches need not stub home_url().
		if ( '' === $og['og:url'] && function_exists( 'home_url' ) ) {
			$og['og:url'] = home_url( '/' );
		}

		/** This filter is documented in build_og_tags(). */
		return (array) apply_filters( 'wc_ai_storefront_og_tags', $og, null );
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		// Late priority so our product-title enrichment wins over an active
		// SEO plugin (single <title>, never duplicated).
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 99 );
		// Priority 11 so we run after Jetpack's default-priority
		// `pre_get_document_title` callback and can see what it produced.
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 11 );
		// Early in <head> so the description/OG/robots sit near the top.
		add_action( 'wp_head', array( $this, 'render_head_tags' ), 5 );
		// Avoid duplicate / conflicting tags from Jetpack on the commerce pages
		// where we emit our own. Page-scoped via should_emit(); off commerce
		// pages Jetpack keeps describing posts/pages we do not handle.
		// Priority 9: see suppress_jetpack_open_graph() for why it must sit
		// between Jetpack's wp_head:1 loader and its wp_head:10 emitter.
		add_action( 'wp_head', array( $this, 'suppress_jetpack_open_graph' ), 9 );
		add_filter( 'jetpack_seo_meta_tags', array( $this, 'suppress_jetpack_description' ) );
	}

	/**
	 * Remove Jetpack's Open Graph tags on commerce pages where we emit our own.
	 *
	 * Jetpack lazily registers `jetpack_og_tags` (at the default wp_head
	 * priority 10) from inside its own `check_open_graph` callback, which runs
	 * at wp_head priority 1. We therefore run at priority 9 — strictly after
	 * Jetpack's priority-1 loader (so `jetpack_og_tags` is already registered
	 * and `has_action` sees it) and strictly before its priority-10 emit. This
	 * is deterministic by priority ordering, independent of plugin load order;
	 * running at priority 1 (same as Jetpack's loader) would be a registration-
	 * order race that could silently skip the removal. No-op off commerce pages
	 * and when Jetpack's OG output is disabled (then `jetpack_og_tags` is never
	 * registered and there is nothing to remove).
	 */
	public function suppress_jetpack_open_graph(): void {
		if ( ! $this->should_emit() ) {
			return;
		}
		if ( false !== has_action( 'wp_head', 'jetpack_og_tags' ) ) {
			remove_action( 'wp_head', 'jetpack_og_tags' );
		}
	}

	/**
	 * The merchant's authored meta description for this request, or ''.
	 *
	 * Memoized: consulted from build_description() on the product path and
	 * from build_archive_description() on the shop path (and, transitively,
	 * from build_og_tags()'s description fallback), so a single render can
	 * reach it more than once. An unmemoized call would re-enter
	 * WC_AI_Storefront_Authored_SEO::is_available()'s class_exists()/
	 * method_exists() checks each time (#668). See $authored_description_memo
	 * for why the "not yet resolved" sentinel is `null`, not `''`.
	 *
	 * @since 0.39.0
	 */
	private function authored_description(): string {
		if ( null === $this->authored_description_memo ) {
			$this->authored_description_memo = $this->resolve_authored_description();
		}
		return $this->authored_description_memo;
	}

	/**
	 * Resolves authored_description(); see that method for the memoization
	 * this backs.
	 *
	 * Resolution differs by page type because Jetpack's own reach does:
	 * `Jetpack_SEO::meta_tags()` only consults per-post description when
	 * `is_singular()`, so on the shop archive it never sees the Shop page's
	 * field even though the merchant filled it in.
	 *
	 *   - Single product: the queried post's authored description.
	 *   - Shop: the Shop page post's authored description, then Jetpack's
	 *     site-wide front-page option. Resolved through
	 *     `wc_get_page_id( 'shop' )` rather than `get_post()`, which on an
	 *     archive returns the first product in the loop, not the Shop page.
	 *   - Product category: none — terms carry no Jetpack post meta, and the
	 *     authored term description is already preferred by
	 *     `build_archive_description()`.
	 */
	private function resolve_authored_description(): string {
		if ( ! WC_AI_Storefront_Authored_SEO::is_available() ) {
			return '';
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return WC_AI_Storefront_Authored_SEO::post_description( (int) get_queried_object_id() );
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			$authored = WC_AI_Storefront_Authored_SEO::post_description( $shop_id );
			if ( '' !== $authored ) {
				return $authored;
			}
			return WC_AI_Storefront_Authored_SEO::front_page_description();
		}

		return '';
	}

	/**
	 * Drop Jetpack SEO Tools' meta description on commerce pages where we emit
	 * our own. Only the `description` key is removed; any other entry Jetpack
	 * puts in this map (e.g. `robots` => `noindex`) is left untouched. Jetpack's
	 * site-verification tags come from a separate hook and are unaffected.
	 *
	 * Unconditional on commerce pages, deliberately (#668 review). This used
	 * to predict whether Jetpack itself would go on to emit a description
	 * and stand down when it thought Jetpack would carry it — but
	 * `Jetpack_SEO_Utils::is_enabled_jetpack_seo()` can be true while
	 * Jetpack's own `meta_tags()` never runs or returns early: a site
	 * filtering `jetpack_seo_meta_tags_enabled` to false
	 * (`class-jetpack-seo.php`), or a theme listed in
	 * `jetpack_seo_meta_tags_conflicted_themes`. Predicting wrong in either
	 * state left the page with no description at all. We now always remove
	 * Jetpack's copy here and always print our own in render_head_tags()
	 * (which reads the same authored value, via build_description() on the
	 * product path and build_archive_description() on the shop path), so
	 * there is exactly one description tag regardless of what Jetpack does.
	 *
	 * Filters `jetpack_seo_meta_tags`. No-op off commerce pages and for
	 * non-array input.
	 *
	 * @param mixed $meta Jetpack's name => content meta map.
	 * @return mixed Filtered map.
	 */
	public function suppress_jetpack_description( $meta ) {
		if ( ! is_array( $meta ) || ! $this->should_emit() ) {
			return $meta;
		}

		unset( $meta['description'] );
		return $meta;
	}

	/**
	 * Echo the <head> metadata for the current commerce page.
	 */
	public function render_head_tags(): void {
		if ( ! $this->should_emit() ) {
			return;
		}

		if ( $this->should_noindex() ) {
			$this->print_meta( 'name', 'robots', 'noindex,follow' );
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
			if ( ! $product ) {
				return;
			}
			$description = $this->build_description( $product );
			$og          = $this->build_og_tags( $product, $description );
		} elseif ( ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_shop' ) && is_shop() ) ) {
			$description = $this->build_archive_description();
			$og          = $this->build_archive_og_tags( $description );
		} else {
			// Product-search results: robots noindex only (emitted above);
			// there is no single product or term to describe.
			return;
		}

		// Always emit (#668 review): suppress_jetpack_description() always
		// removes Jetpack's own tag on commerce pages, so this is always the
		// only description tag printed; see that method's docblock.
		if ( '' !== $description ) {
			$this->print_meta( 'name', 'description', $description );
		}
		$this->print_og_and_twitter( $og );
	}

	/**
	 * Print an Open Graph map followed by its derived Twitter cards.
	 *
	 * @param array<string,string> $og Open Graph map.
	 */
	private function print_og_and_twitter( array $og ): void {
		foreach ( $og as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}
			$this->print_meta( 'property', $property, $content, 'url' === $this->attr_kind( $property ) );
		}
		foreach ( $this->build_twitter_tags( $og ) as $name => $content ) {
			if ( '' === $content ) {
				continue;
			}
			$this->print_meta( 'name', $name, $content, 'twitter:image' === $name );
		}
	}

	/**
	 * Default social image for an archive that has no image of its own.
	 *
	 * Order: a merchant/dev-configured default (filter), then the site logo
	 * (Customizer custom_logo), then the site icon. Returns '' when none is
	 * available. Keeps the shop/home share card from going imageless when we
	 * suppress Jetpack's auto-generated Open Graph image.
	 */
	private function archive_default_image(): string {
		/**
		 * Filter the default Open Graph image URL for archive pages.
		 *
		 * @param string $url Default image URL. Empty string falls through to
		 *                    the site logo, then the site icon.
		 */
		$configured = (string) apply_filters( 'wc_ai_storefront_og_default_image', '' );
		if ( '' !== $configured ) {
			return $configured;
		}

		$logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;
		if ( $logo_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		if ( function_exists( 'get_site_icon_url' ) ) {
			$icon = (string) get_site_icon_url( 512 );
			if ( '' !== $icon ) {
				return $icon;
			}
		}

		return '';
	}

	/**
	 * The current locale as an Open Graph `language_TERRITORY` value.
	 *
	 * WordPress locales like `de_DE_formal` carry a variant suffix Open Graph
	 * does not accept, so we keep only the language and territory segments.
	 * Defaults to `en_US` when the locale is unavailable.
	 */
	private function og_locale(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		if ( '' === $locale ) {
			return 'en_US';
		}
		// Normalize a BCP-47 hyphen form (e.g. a filtered `pt-BR`) to Open
		// Graph's underscore form before stripping any WP variant suffix.
		$parts = explode( '_', str_replace( '-', '_', $locale ) );
		return isset( $parts[1] ) ? $parts[0] . '_' . $parts[1] : $parts[0];
	}

	/**
	 * Whether an OG property carries a URL value (so it is esc_url'd).
	 */
	private function attr_kind( string $property ): string {
		return in_array( $property, array( 'og:url', 'og:image' ), true ) ? 'url' : 'text';
	}

	/**
	 * Print a single escaped <meta> tag.
	 *
	 * @param string $attr    'name' or 'property'.
	 * @param string $key     The attribute key (e.g. 'og:title').
	 * @param string $content The content value.
	 * @param bool   $is_url  Escape content as a URL instead of an attribute.
	 */
	private function print_meta( string $attr, string $key, string $content, bool $is_url = false ): void {
		$value = $is_url ? esc_url( $content ) : esc_attr( $content );
		printf(
			'<meta %1$s="%2$s" content="%3$s" />' . "\n",
			esc_attr( $attr ),
			esc_attr( $key ),
			// $value is already escaped by esc_url()/esc_attr() above.
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Whether the current commerce page should carry robots noindex.
	 *
	 * Opinionated, zero-config: a product the merchant set to "Hidden" in
	 * WooCommerce (still reachable by URL) and internal shop search results
	 * (product search, i.e. post_type=product; thin/duplicate content) are
	 * noindexed. Everything else is indexable.
	 */
	public function should_noindex(): bool {
		$noindex = false;

		if ( function_exists( 'is_product' ) && is_product()
			&& function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product && 'hidden' === $product->get_catalog_visibility() ) {
				$noindex = true;
			}
		}

		if ( function_exists( 'is_search' ) && is_search()
			&& 'product' === get_query_var( 'post_type' ) ) {
			$noindex = true;
		}

		/**
		 * Filter the robots noindex decision for the current request.
		 *
		 * @param bool $noindex Whether to emit robots noindex.
		 */
		return (bool) apply_filters( 'wc_ai_storefront_robots_noindex', $noindex );
	}
}
