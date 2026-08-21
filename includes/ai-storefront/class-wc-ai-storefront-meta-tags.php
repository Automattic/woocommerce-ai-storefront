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
	 * The authored lookup is keyed on `$product->get_id()`, not on the
	 * queried object, so this method describes the product it was handed
	 * rather than whatever page happens to be rendering (#668 review). Both
	 * this method and build_og_tags() are public on a non-final class, so
	 * callers may legitimately pass a product other than the queried one.
	 *
	 * @param WC_Product $product Product to derive from.
	 * @return string Cleaned, truncated description (non-empty when the product has a name).
	 */
	public function build_description( $product ): string {
		$description = $this->first_usable_candidate(
			array(
				$this->authored_description( (int) $product->get_id() ),
				(string) $product->get_short_description(),
				(string) $product->get_description(),
			)
		);

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
	 * First candidate that still has text after cleaning, cleaned and truncated.
	 *
	 * Emptiness is judged on the CLEANED value, never on the raw one (#668
	 * review). A candidate can be non-empty raw and empty once cleaned — a
	 * Shop page whose content is a product-collection block and no prose is
	 * the realistic case, since `wp_strip_all_tags()` reduces
	 * `<!-- wp:woocommerce/product-collection /-->` to nothing. Testing the
	 * raw value would let such a candidate consume the chain and leave the
	 * page with no description at all, because
	 * suppress_jetpack_description() has already removed Jetpack's.
	 *
	 * @param string[] $candidates Raw candidate strings, best first.
	 * @return string Cleaned, truncated text, or '' when none has any.
	 */
	private function first_usable_candidate( array $candidates ): string {
		foreach ( $candidates as $raw ) {
			$raw  = (string) $raw;
			$text = $this->clean_text( $raw );
			if ( '' !== $text ) {
				return $this->truncate( $text, self::DESCRIPTION_MAX );
			}
			if ( '' !== trim( $raw ) ) {
				// Merchant content existed and we discarded it: markup or
				// shortcodes with no readable prose. Worth a breadcrumb when
				// a merchant asks why their copy is not in the SERP snippet.
				WC_AI_Storefront_Logger::debug(
					'meta description candidate had content but cleaned to empty; trying the next one'
				);
			}
		}
		return '';
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
	 * "Shop {category} at {store}". Shop → the authored description, then the
	 * shop page content, falling back to the store tagline. Cleaned/truncated
	 * like the product path.
	 *
	 * Every candidate is judged after cleaning, not before — see
	 * first_usable_candidate() for why that distinction is load-bearing.
	 *
	 * @return string Cleaned, truncated description (non-empty on category/shop pages).
	 */
	public function build_archive_description(): string {
		$candidates = array();
		$source     = null;

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term   = get_queried_object();
			$source = $term;
			if ( is_object( $term ) && isset( $term->description ) ) {
				$candidates[] = (string) $term->description;
			}
			if ( is_object( $term ) && isset( $term->name ) ) {
				// Category-specific fallback so the page always carries a
				// description. Required because we suppress Jetpack's on
				// commerce pages (see suppress_jetpack_description()) and
				// would otherwise leave none.
				$store        = (string) get_bloginfo( 'name' );
				$candidates[] = '' !== $store
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
			// it. Precedence: authored fields (see authored_description()),
			// then shop content, then tagline.
			$candidates[] = $this->authored_description();

			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop_id > 0 ) {
				$candidates[] = (string) get_post_field( 'post_content', $shop_id );
			}
			$candidates[] = (string) get_bloginfo( 'description' ); // store tagline
		}

		$description = $this->first_usable_candidate( $candidates );

		/** This filter is documented in build_description(). */
		return (string) apply_filters( 'wc_ai_storefront_meta_description', $description, $source );
	}

	/**
	 * `document_title_parts` callback — enrich the product title with its brand.
	 *
	 * Hooked at a late priority so we win over an active SEO plugin (there is
	 * only one <title>, so this never duplicates). This callback only ever
	 * appends the brand, and only on single products; it never claims a title
	 * for a category, the shop archive, or a product search.
	 *
	 * That is not the whole title story. {@see filter_document_title()} claims
	 * `pre_get_document_title` on the shop archive and on single products
	 * whenever the merchant authored a headline, which short-circuits
	 * `wp_get_document_title()` before `document_title_parts` ever fires. So a
	 * commerce page keeps core's assembled title only when nothing is authored
	 * for it — on a category or a product search, always.
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
				// said what they want, so we leave the parts untouched and
				// emit that headline ourselves from filter_document_title().
				// The brand still travels as a discrete `brand` field in the
				// product JSON-LD, which is where it does the
				// machine-readable work.
				//
				// This is normally unreachable on an authored product,
				// because filter_document_title() short-circuits
				// wp_get_document_title() before `document_title_parts`
				// fires. It stays as the guard for anything else that
				// assembles a title from the parts filter directly.
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
	 * `pre_get_document_title` callback — apply the merchant's authored title.
	 *
	 * Two page types are handled, the shop archive and single products.
	 *
	 * Shop: WooCommerce renders the product archive at the Shop page's URL,
	 * so the Shop page post is never what WordPress or Jetpack resolves from
	 * the query, and the SEO title a merchant typed into that page does
	 * nothing. We resolve it explicitly through `wc_get_page_id( 'shop' )`.
	 *
	 * Products: we emit the authored headline ourselves rather than assuming
	 * Jetpack will (#668 review). That assumption failed in both directions.
	 * When Jetpack does apply it, its own `pre_get_document_title` callback
	 * short-circuits `wp_get_document_title()` and `document_title_parts`
	 * never fires, so standing down there achieved nothing. When Jetpack does
	 * not — `jetpack_seo_custom_titles` filtered false, or a theme listed by
	 * `Jetpack_SEO_Titles::is_conflicted_theme()` — standing down left the
	 * merchant with neither their authored title nor the brand suffix they
	 * had before. This is the same prediction suppress_jetpack_description()
	 * deliberately stopped making; see its docblock. Running at priority 11
	 * we see and replace whatever Jetpack produced, which is the same string
	 * when Jetpack did apply it, so there is never a second `<title>`.
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
	 * otherwise all emit an identical `<title>`. prepare_authored_title()
	 * appends a page suffix in the same "$title $sep Page $n" shape core
	 * uses, on the same `document_title_separator` filter, when paginated —
	 * on both branches, since a product split with `<!--nextpage-->` carries
	 * the same segment.
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

		if ( function_exists( 'is_product' ) && is_product() ) {
			$authored = WC_AI_Storefront_Authored_SEO::post_title( (int) get_queried_object_id() );
			return '' === $authored ? $title : $this->prepare_authored_title( $authored );
		}

		if ( ! ( function_exists( 'is_shop' ) && is_shop() ) ) {
			return $title;
		}
		if ( $this->is_product_search() ) {
			return $title;
		}

		$shop_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		$authored = WC_AI_Storefront_Authored_SEO::post_title( $shop_id );

		if ( '' === $authored ) {
			return $title;
		}

		return $this->prepare_authored_title( $authored );
	}

	/**
	 * Ready a merchant-authored title for direct emission into `<title>`.
	 *
	 * @since 0.39.0
	 *
	 * @param string $authored Raw authored title (caller guarantees non-empty).
	 * @return string Escaped title, with core's page-number suffix when paginated.
	 */
	private function prepare_authored_title( string $authored ): string {
		// Escape: this value is merchant-authored post meta
		// (`jetpack_seo_html_title`), and Jetpack registers that meta with no
		// `sanitize_callback` (`Jetpack_SEO_Posts::register_post_meta()`,
		// modules/seo-tools/class-jetpack-seo-posts.php:159), so it can carry
		// raw markup — Jetpack's own reader escapes it on the way out
		// (`esc_html( $custom_title )` in `Jetpack_SEO_Titles::get_custom_title()`,
		// class-jetpack-seo-titles.php:113 and :136). A non-empty return from
		// this filter short-circuits `wp_get_document_title()`
		// (wp-includes/general-template.php, ~1192-1195) straight into
		// `_wp_render_title_tag()`'s
		// `echo '<title>' . wp_get_document_title() . '</title>'` (~1315),
		// which does not escape. `<title>` is RCDATA, so an unescaped value
		// here can break out with `</title><script>...`. Do not remove this
		// call; it is the only escaping the merchant-authored value gets
		// before it reaches the page's <head>. (The page-number suffix
		// appended below is not post meta: it is a translated core-shaped
		// string plus a separator from `document_title_separator`.) (#668)
		$authored = esc_html( $authored );

		// Both query vars, like core: `wp_get_document_title()` reads the
		// `$page` and `$paged` globals and suffixes on `max( $paged, $page )`
		// (wp-includes/general-template.php, `global $page, $paged;` then
		// "Add a page number if necessary"). Which of the two carries the
		// number depends on the request — on a shop-as-front-page it is
		// `page`, so reading `paged` alone would make `/`, `/page/2/` and
		// `/page/3/` all emit the same title (#668).
		$paged_raw = get_query_var( 'paged' );
		$paged     = $paged_raw ? (int) $paged_raw : 1;
		$page_raw  = get_query_var( 'page' );
		$page      = $page_raw ? (int) $page_raw : 1;
		if ( $paged >= 2 || $page >= 2 ) {
			/** This filter is documented in wp-includes/general-template.php */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally re-invoking WP core's own `document_title_separator` filter, so a merchant's separator customization (e.g. via a theme) applies here too, the same way it would to core's own page-number suffix.
			$sep = (string) apply_filters( 'document_title_separator', '-' );
			// The number is deliberately NOT passed through
			// `number_format_i18n()`. Core is inconsistent here:
			// `paginate_links()` (general-template.php:4787, :4804) and
			// `blocks/breadcrumbs.php:249` both wrap, and breadcrumbs uses
			// this same `Page %s` string, but `wp_get_document_title()`
			// (general-template.php:1254) does not. This suffix extends
			// that last one, on the same pages, so wrapping would give a
			// locale with non-Western digits one numeral system in the
			// title of a paginated shop page carrying an authored title
			// and another in every other paginated title on the site.
			// Inheriting core's inconsistency beats creating a new one.
			// If core:1254 is ever brought in line, follow it. (#674 review)
			$authored .= ' ' . $sep . ' ' . sprintf(
				/* translators: %s: Page number. */
				__( 'Page %s', 'woocommerce-ai-storefront' ),
				max( $paged, $page )
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

		// Attachment ID first, then the ONE wp_get_attachment_image_src() call
		// that returns url + width + height together (#679) — replaces
		// the previous get_the_post_thumbnail_url(), which only ever gave us
		// the url and discarded the dimensions Open Graph and Twitter both
		// want too.
		$thumbnail_id = (int) get_post_thumbnail_id( $product->get_id() );
		if ( $thumbnail_id > 0 ) {
			$image = wp_get_attachment_image_src( $thumbnail_id, 'full' );
			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				$og['og:image'] = (string) $image[0];

				// image_downsize() (WP core, wp-includes/media.php)
				// initialises width/height to 0 and only overwrites them
				// from the attachment's _wp_attachment_metadata. An
				// attachment with no metadata — media offloaded by a
				// plugin that clears it, a regeneration that failed on
				// upload, an import that skipped it — leaves both at 0
				// while the URL is still perfectly valid. Emit the pair
				// only when WordPress actually reported a size: a zero
				// dimension is worse than no dimension, since Facebook's
				// scraper rejects it and falls back to re-measuring the
				// image itself, the exact fetch these two properties
				// exist to avoid. Same omit-rather-than-emit-a-useless-
				// value shape as og:image:alt below.
				$width  = (int) $image[1];
				$height = (int) $image[2];
				if ( $width > 0 && $height > 0 ) {
					$og['og:image:width']  = (string) $width;
					$og['og:image:height'] = (string) $height;
				}

				// Alt text is frequently empty; omit the key rather than
				// emitting an empty string, same as og:image itself above.
				$alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
				if ( is_string( $alt ) && '' !== $alt ) {
					$og['og:image:alt'] = $alt;
				}
			}
		}

		if ( $product->is_purchasable() ) {
			$price = (string) $product->get_price();
			if ( '' !== $price ) {
				$og['product:price:amount']   = $price;
				$og['product:price:currency'] = get_woocommerce_currency();
			}
		}

		// Stock and Condition (#679), both read from the shared,
		// vocabulary-neutral resolvers on WC_AI_Storefront_Product_Facts so
		// this emitter can never disagree with JSON-LD about the same
		// product. Availability is unconditional — every product has a
		// stock state regardless of price/purchasability — while Condition
		// is left out entirely when nothing types, per condition_slug()'s
		// own contract.
		$stock_state                = WC_AI_Storefront_Product_Facts::stock_state( $product );
		$og['product:availability'] = $this->product_availability( $stock_state );
		$og['og:availability']      = $this->og_availability( $stock_state );

		$condition = WC_AI_Storefront_Product_Facts::condition_slug( $product );
		if ( '' !== $condition ) {
			$og['product:condition'] = $condition;
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
	 * Facebook's `product:availability` vocabulary for a WC stock state.
	 *
	 * Diverges from {@see og_availability()} on backorder — the one place
	 * these two vocabularies disagree (#679), found by reading
	 * Yoast's WooCommerce SEO addon presenter classes:
	 * `woocommerce-product-availability-presenter.php` (Facebook's
	 * `product:availability`) has no "backorder" term of its own, so a
	 * backordered product reads "available for order" there — still
	 * purchasable, just not from stock on hand. `og_availability()` covers
	 * the sibling `woocommerce-pinterest-product-availability-presenter.php`,
	 * which DOES have a distinct "backorder" term. Getting this pairing
	 * wrong ships a technically well-formed tag with the wrong word in it,
	 * which no crawler validator flags.
	 *
	 * @param string $stock_state One of `instock`, `outofstock`,
	 *                            `onbackorder` ({@see WC_AI_Storefront_Product_Facts::stock_state()}).
	 * @return string Facebook's vocabulary term for `product:availability`.
	 */
	private function product_availability( string $stock_state ): string {
		if ( 'onbackorder' === $stock_state ) {
			return 'available for order';
		}
		return 'outofstock' === $stock_state ? 'out of stock' : 'instock';
	}

	/**
	 * Pinterest's `og:availability` vocabulary for a WC stock state.
	 *
	 * See {@see product_availability()} for why this disagrees with it on
	 * backorder.
	 *
	 * @param string $stock_state One of `instock`, `outofstock`,
	 *                            `onbackorder`.
	 * @return string Pinterest's vocabulary term for `og:availability`.
	 */
	private function og_availability( string $stock_state ): string {
		if ( 'onbackorder' === $stock_state ) {
			return 'backorder';
		}
		return 'outofstock' === $stock_state ? 'out of stock' : 'instock';
	}

	/**
	 * Derive Twitter Card tags from an Open Graph map.
	 *
	 * The label/data pairs (#679) are Twitter's own "Product" card
	 * fields, the same ones competing SEO plugins already populate:
	 * label1/data1 carry price, label2/data2 carry availability. Both
	 * pairs are gated on presence — label1/data1 on `product:price:amount`
	 * being set, label2/data2 on `$product` being passed at all — and both
	 * are now content mirrors of the (possibly filtered) OG map, not just
	 * presence mirrors: label1/data1's value comes from the OG map (via
	 * twitter_price_data()), so a `wc_ai_storefront_og_tags` filter
	 * consumer that changes `product:price:amount` changes data1 too.
	 * data2's value now does the same (#681 review) — it prefers the OG
	 * map's `product:availability`, read back through
	 * twitter_availability_data(), and only recomputes from `$product`
	 * when that OG value is missing or not one of Facebook's three known
	 * terms. Previously data2 always recomputed from `$product`, so a
	 * filter that rewrote `product:availability` changed only that key
	 * and left twitter:data2 contradicting it on the same page; see
	 * twitter_availability_data() for how the two are now kept in sync
	 * without a second copy of the vocabulary.
	 *
	 * `og:availability` is a separate key in the same map, carrying
	 * Pinterest's vocabulary rather than Facebook's (see
	 * product_availability()/og_availability()). A filter consumer who
	 * edits only `product:availability` still leaves `og:availability`
	 * disagreeing with it; that is inherent to filtering a flat map and
	 * is not something this method fixes.
	 *
	 * Both pairs render as a visible two-column strip under the card, so a
	 * person reads them; both carry human-readable text, not machine
	 * vocabulary (#679), matching what Rank Math and Yoast's
	 * WooCommerce add-on already emit. data1 goes through
	 * twitter_price_data(), the localised, symbol-prefixed price. data2
	 * goes through twitter_availability_data(), our own display strings
	 * for the same vocabulary `product:availability` already carries
	 * (#679, #681) — see that method's docblock for why this pair does
	 * NOT read `WC_Product::get_availability()`.
	 *
	 * $product travels alongside $og rather than being re-derived here so
	 * this method still only recomputes from data its caller already has:
	 * render_head_tags() resolves the product once on the single-product
	 * path and has none on the archive path, where `product:availability`
	 * is never set in the first place. build_twitter_tags() is public and
	 * `$product` defaults to null, so a caller can pass an `$og` map that
	 * already carries `product:availability` with no product to read — a
	 * test exercises exactly that path. The `null !== $product` check in
	 * the gate below is what stops that combination from emitting a pair
	 * with no fallback source to compute data2 from if the OG value ever
	 * turned out unusable; it is not an invariant this method can assume
	 * from its usual caller.
	 *
	 * @param array<string,string> $og      Open Graph map.
	 * @param WC_Product|null      $product Source product, read only as the
	 *                                      fallback source for twitter:data2
	 *                                      when the OG map's
	 *                                      `product:availability` is absent
	 *                                      or unrecognised. Null on the
	 *                                      archive-page path, where the
	 *                                      pair is never emitted.
	 * @return array<string,string> property => content.
	 */
	public function build_twitter_tags( array $og, $product = null ): array {
		$tw = array(
			'twitter:card'        => 'summary_large_image',
			'twitter:title'       => $og['og:title'] ?? '',
			'twitter:description' => $og['og:description'] ?? '',
		);
		if ( ! empty( $og['og:image'] ) ) {
			$tw['twitter:image'] = $og['og:image'];
		}
		if ( isset( $og['og:image:alt'] ) && '' !== $og['og:image:alt'] ) {
			// Mirrors build_og_tags()'s own `'' !== $alt` gate literally.
			// `! empty()` would drop alt text of "0" — legal alt text.
			$tw['twitter:image:alt'] = $og['og:image:alt'];
		}
		if ( isset( $og['product:price:amount'] ) && '' !== $og['product:price:amount'] ) {
			// Same gate as product:price:amount itself (build_og_tags()): an
			// unpurchasable or unpriced product never populates that key,
			// so this pair must not appear either. Written as the OG gate
			// is written, `'' !== $price`, rather than as `! empty()`:
			// those two disagree on exactly one value, '0', and a free
			// product got an Availability row with no Price row beside it
			// (#679 review, verified live).
			$tw['twitter:label1'] = __( 'Price', 'woocommerce-ai-storefront' );
			$tw['twitter:data1']  = $this->twitter_price_data( $og );
		}
		if ( null !== $product ) {
			// Gated on $product alone, not on `product:availability` being
			// present in $og (#681): twitter_availability_data() falls back
			// to $product's own state whenever the OG value is missing or
			// unrecognised, so this pair is always emitted once a product
			// is present; no OG-presence guard needed.
			$tw['twitter:label2'] = __( 'Availability', 'woocommerce-ai-storefront' );
			$tw['twitter:data2']  = $this->twitter_availability_data(
				(string) ( $og['product:availability'] ?? '' ),
				$product
			);
		}
		return $tw;
	}

	/**
	 * Facebook's `product:availability` vocabulary term => the matching
	 * shopper-facing display string for `twitter:data2` (#681).
	 *
	 * Built by calling product_availability() itself for all three
	 * stock states it is total over, rather than hard-coding 'instock' /
	 * 'out of stock' / 'available for order' a second time here. That
	 * keeps this map derived from, not duplicated from, the one place
	 * the vocabulary is defined: if product_availability()'s terms ever
	 * change, this map's keys move with them instead of silently going
	 * stale.
	 *
	 * @return array<string,string> Vocabulary term => display text.
	 */
	private function twitter_availability_display_map(): array {
		return array(
			$this->product_availability( 'instock' )     => __( 'In stock', 'woocommerce-ai-storefront' ),
			$this->product_availability( 'outofstock' )  => __( 'Out of stock', 'woocommerce-ai-storefront' ),
			$this->product_availability( 'onbackorder' ) => __( 'Available on backorder', 'woocommerce-ai-storefront' ),
		);
	}

	/**
	 * The shopper-facing availability text for `twitter:data2`.
	 *
	 * Prefers the (possibly filtered) `product:availability` value from
	 * the OG map, so a `wc_ai_storefront_og_tags` filter consumer who
	 * rewrites availability is mirrored here instead of being silently
	 * overridden by a fresh read of `$product` (#681 review — the exact
	 * drift a Copilot review on #679 flagged). Falls back to `$product`'s
	 * own state, via `WC_AI_Storefront_Product_Facts::stock_state()`,
	 * whenever `$og_availability` is not one of Facebook's three known
	 * terms: absent (the key was never set, or a filter removed it), or
	 * an unrecognised token a filter consumer invented, which must never
	 * be echoed back out as display text. Both the OG value and the
	 * `$product` fallback are resolved through the same
	 * twitter_availability_display_map(), so the two paths cannot drift
	 * from each other.
	 *
	 * Deliberately NOT `WC_Product::get_availability()`, which an earlier
	 * version of this method read (#679). Live verification against
	 * real WooCommerce (#679) found it unusable for a public social
	 * card:
	 *
	 * - For an unmanaged in-stock product, WooCommerce's own text is `''`.
	 *   Stock management is OFF by default, so this is the commonest
	 *   configuration on any store, and the row simply never appeared.
	 * - For a managed product it includes the live quantity, e.g.
	 *   "5 in stock". That publishes the merchant's stock level into a
	 *   public social card, which nobody asked for and a merchant would
	 *   not expect.
	 *
	 * A third justification stood here and was WRONG: it claimed
	 * `get_availability()` reads "Out of stock" for a backordered
	 * product. Re-verified against real WooCommerce (#679 review), a
	 * genuine backorder product returns `''` (managed, `backorders=yes`)
	 * or "Available on backorder" (managed, `backorders=notify`), never
	 * "Out of stock". The original evidence came from a fixture with
	 * backorders DISABLED, where WooCommerce rewrites the stock status to
	 * `outofstock` on save — so it was never a backorder product at all.
	 * The two justifications above are each independently sufficient.
	 *
	 * The fallback path maps `WC_AI_Storefront_Product_Facts::stock_state()`
	 * — the same neutral three-way state `product:availability` and
	 * `og:availability` already read — to our own translatable display
	 * strings via product_availability(). The mapping is total (every
	 * stock state has a string), so the pair is always present, always
	 * agrees with the machine tags on the same page absent a filter, and
	 * never discloses a quantity.
	 *
	 * @param string     $og_availability The OG map's `product:availability`
	 *                                    value, or `''` if it was absent.
	 *                                    Read as-is; not assumed to be one
	 *                                    of Facebook's known terms.
	 * @param WC_Product $product         Source product, used only as the
	 *                                    fallback when $og_availability is
	 *                                    not recognised.
	 * @return string Shopper-facing availability text: "In stock",
	 *                "Out of stock", or "Available on backorder".
	 */
	private function twitter_availability_data( string $og_availability, WC_Product $product ): string {
		$map = $this->twitter_availability_display_map();
		if ( isset( $map[ $og_availability ] ) ) {
			return $map[ $og_availability ];
		}
		$stock_state = WC_AI_Storefront_Product_Facts::stock_state( $product );
		return $map[ $this->product_availability( $stock_state ) ];
	}

	/**
	 * Format the price already in an Open Graph map for `twitter:data1`.
	 *
	 * Symbol-prefixed and localised (#679), e.g. "$48.00" — what
	 * a shopper recognises as a price, the same shape Rank Math emits.
	 * `wc_price()` builds this correctly but returns HTML (a `<span>`
	 * wrapper plus an entity-encoded currency symbol, e.g. `&#36;` for
	 * `$`); that markup cannot go into a meta `content` attribute, so
	 * `wp_strip_all_tags()` reduces it to plain text with the entity
	 * intact, `&#36;0.00`. Stripping tags does not decode entities, so
	 * without a decode step the currency symbol would still be the
	 * literal entity string (#679); a live capture of the printed
	 * attribute — Rank Math's own, after `esc_attr()`'s normalisation —
	 * confirmed it really does emit `&#036;48.00` before this fix. The
	 * extra zero comes from `esc_attr()`'s own `wp_kses_normalize_entities()`
	 * pass downstream, not from `wc_price()`. `html_entity_decode()`
	 * turns that back into `$` so the returned string is plain text,
	 * matching the same decode `extract_description()`
	 * (`WC_AI_Storefront_UCP_Variant_Translator`) already applies to
	 * WooCommerce HTML elsewhere in this plugin. It happened to render
	 * correctly before this fix only because `esc_attr()` in `print_meta()`
	 * defaults to not double-encoding an already-encoded entity; decoding
	 * here removes that dependency on a default, so the value it hands to
	 * `print_meta()` is escaped exactly once, deliberately, regardless of
	 * how it is escaped. Previously this formatted "{currency} {amount}"
	 * (e.g. "USD 48.00"), the same currency-code-prefixed shape
	 * `WC_AI_Storefront_MCP_Tools::format_money()` uses elsewhere in this
	 * plugin for a machine reader; that shape was wrong here because a
	 * person, not a crawler, reads this pair. Falls back to it only when
	 * `wc_price()`/`wp_strip_all_tags()` are unavailable.
	 * `product:price:amount`/`:currency` are untouched and still carry the
	 * bare machine-readable decimal.
	 *
	 * @param array<string,string> $og Open Graph map. Caller only invokes
	 *                                 this once `product:price:amount` is
	 *                                 confirmed present; `product:price:currency`
	 *                                 is set alongside it by build_og_tags()
	 *                                 so it is expected here too, but is
	 *                                 read defensively all the same.
	 * @return string
	 */
	private function twitter_price_data( array $og ): string {
		$currency = (string) ( $og['product:price:currency'] ?? '' );
		$amount   = (string) ( $og['product:price:amount'] ?? '' );

		if ( ! is_numeric( $amount ) ) {
			// `(float)` on a non-numeric string does not fail, it invents:
			// "Call for price" casts to 0.0 and this method would publish
			// "$0.00", and a comma-decimal "1.234,56" casts to 1.234 and
			// would publish "$1.23" (#679 review, both verified live).
			// `product:price:amount` on the same page still carries the
			// original string, so the card and the machine tag would
			// disagree with nothing to flag it. Nowhere else on this path
			// does bad input become a CLAIM rather than an absence, so
			// fall back to the unformatted currency-code shape and say so
			// in the log.
			if ( class_exists( 'WC_AI_Storefront_Logger' ) ) {
				WC_AI_Storefront_Logger::debug(
					'Open Graph: product:price:amount is not numeric (%s), so twitter:data1 keeps the raw value instead of a currency-formatted zero.',
					$amount
				);
			}
			return '' !== $currency ? $currency . ' ' . $amount : $amount;
		}

		if ( function_exists( 'wc_price' ) && function_exists( 'wp_strip_all_tags' ) ) {
			$args = '' !== $currency ? array( 'currency' => $currency ) : array();
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
		}

		return '' !== $currency ? $currency . ' ' . $amount : $amount;
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
	 * The merchant's authored meta description, or ''.
	 *
	 * Pass a post ID to read that post's authored field directly; that is
	 * what the product path does, keyed on the product it was handed rather
	 * than on the queried object (#668 review). With no ID the lookup is
	 * resolved from the current page type instead, which is what the shop
	 * archive needs: WooCommerce renders it at the Shop page's URL, so the
	 * Shop page post is never what the query resolves to, and Jetpack's own
	 * `meta_tags()` gates its per-post description on `is_singular()` and so
	 * never sees the field the merchant filled in.
	 *
	 * Shop precedence: the Shop page post's authored description, then
	 * Jetpack's site-wide front-page option BUT only when the shop actually
	 * is the front page (#668 review). That option describes the homepage;
	 * on a `/shop/` that is not the front page it would displace the Shop
	 * page's own body copy with copy written about a different page.
	 *
	 * Product categories resolve to '': terms carry no Jetpack post meta, and
	 * the authored term description is already preferred by
	 * build_archive_description().
	 *
	 * @since 0.39.0
	 *
	 * @param int $post_id Post whose authored description to read. 0 (the
	 *                     default) resolves from the current page type.
	 */
	private function authored_description( int $post_id = 0 ): string {
		// No `is_available()` guard here: every accessor below self-guards,
		// so an outer check only duplicates the class/method/module lookups
		// on the paths that go on to make them anyway. The reader's contract
		// is that callers never have to guard.
		if ( $post_id > 0 ) {
			return WC_AI_Storefront_Authored_SEO::post_description( $post_id );
		}

		if ( function_exists( 'is_shop' ) && is_shop() && ! $this->is_product_search() ) {
			$shop_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			$authored = WC_AI_Storefront_Authored_SEO::post_description( $shop_id );
			if ( '' !== $authored ) {
				return $authored;
			}
			if ( function_exists( 'is_front_page' ) && is_front_page() ) {
				return WC_AI_Storefront_Authored_SEO::front_page_description();
			}
		}

		return '';
	}

	/**
	 * Whether this request is a product-search results page.
	 *
	 * WooCommerce defines `is_shop()` as
	 * `is_post_type_archive( 'product' ) || is_page( wc_get_page_id( 'shop' ) )`,
	 * and `WP_Query::parse_query()` sets `is_post_type_archive` for
	 * `?s=…&post_type=product` without gating on `is_search`. So `is_shop()`
	 * is true on a product search as well, and every shop-scoped branch has
	 * to exclude it explicitly or the Shop page's own metadata lands on a
	 * search-results page (#668 review).
	 *
	 * Tests `is_search()` alone rather than re-testing the `post_type` query
	 * var: every caller sits behind should_emit(), which has already narrowed
	 * the request to a commerce page, and the only searching commerce page is
	 * the product search.
	 */
	private function is_product_search(): bool {
		return function_exists( 'is_search' ) && is_search();
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

		// Threaded through to print_og_and_twitter() -> build_twitter_tags()
		// for the shopper-facing availability text (#679); stays
		// null on the archive path below, which never needs it.
		$product = null;

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
			if ( ! $product ) {
				return;
			}
			$description = $this->build_description( $product );
			$og          = $this->build_og_tags( $product, $description );
		} elseif ( ! $this->is_product_search()
			&& ( ( function_exists( 'is_product_category' ) && is_product_category() )
				|| ( function_exists( 'is_shop' ) && is_shop() ) ) ) {
			$description = $this->build_archive_description();
			$og          = $this->build_archive_og_tags( $description );
		} else {
			// Product-search results: robots noindex only (emitted above);
			// there is no single product or term to describe. Reachable only
			// because of the is_product_search() guard above: `is_shop()` is
			// true on a product search too, so without it this branch was
			// dead code and a search page shipped the Shop page's authored
			// description plus a full OG/Twitter card (#668 review).
			return;
		}

		// A third input to the same structural decision (#669 task 2), not a
		// new mechanism: suppress_jetpack_description() still always removes
		// Jetpack's own tag on commerce pages regardless of what it predicts
		// (see its docblock) — these four plugins do not route through
		// jetpack_seo_meta_tags, so that removal is untouched here. What
		// changes is OUR emit decision, and only for these four:
		// WC_AI_Storefront_Rival_Seo_Description::is_emitting() reports
		// what a rival plugin's own filter actually carried this request,
		// settled before this callback runs because every rival filter is
		// hooked at PHP_INT_MAX and fires during wp_head:1, strictly before
		// this wp_head:5 callback (see that class's init()) — never a guess
		// about whether it will render. Between the two suppressions, at
		// most one plugin's description tag ever reaches the page.
		//
		// Description only, deliberately: og:description below is NOT
		// gated on this signal. The filter these plugins expose predicts
		// only their own <meta name="description">, not their Open Graph
		// output — free Yoast with nothing authored fires wpseo_metadesc
		// empty (correctly predicting no description tag) yet still emits
		// og:description regardless. Extending the stand-down to Open
		// Graph would commit to a correlation the signal does not carry.
		// Tracked separately as #676.
		if ( '' !== $description && ! WC_AI_Storefront_Rival_Seo_Description::is_emitting() ) {
			$this->print_meta( 'name', 'description', $description );
		}
		$this->print_og_and_twitter( $og, $product );
	}

	/**
	 * Print an Open Graph map followed by its derived Twitter cards.
	 *
	 * @param array<string,string> $og      Open Graph map.
	 * @param WC_Product|null      $product Source product; threaded through
	 *                                      to build_twitter_tags() for the
	 *                                      shopper-facing availability text
	 *                                      (#679). Null on the
	 *                                      archive-page path.
	 */
	private function print_og_and_twitter( array $og, $product = null ): void {
		foreach ( $og as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}
			$this->print_meta( 'property', $property, $content, 'url' === $this->attr_kind( $property ) );
		}
		foreach ( $this->build_twitter_tags( $og, $product ) as $name => $content ) {
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
