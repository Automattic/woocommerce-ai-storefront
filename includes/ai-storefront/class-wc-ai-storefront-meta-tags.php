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
	 * Longest search term published in `og:title`.
	 *
	 * The term is whatever the visitor typed, so it arrives unbounded and
	 * attacker-supplied. Capping it keeps a crafted 2,000-character query
	 * from becoming the whole share card.
	 */
	private const SEARCH_QUERY_MAX = 70;

	/**
	 * Product taxonomies whose term archives carry full commerce metadata.
	 *
	 * An explicit allow-list, deliberately not `is_product_taxonomy()`. That
	 * helper is `is_tax( get_object_taxonomies( 'product' ) )`, which sweeps
	 * in every `pa_*` attribute archive. Attribute archives are off by
	 * default in WooCommerce (`has_archives` is false), so on most stores
	 * there is no page there to describe, and on stores that enable them the
	 * pages are thin and near-duplicate. Adding a taxonomy here is a decision
	 * about crawl surface, so it should be a visible edit rather than a
	 * side effect of WooCommerce registering something new (#705).
	 *
	 * @var string[]
	 */
	private const COVERED_TERM_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * The queried term when this request is a covered product term archive.
	 *
	 * One predicate for every branch that used to restate
	 * `is_product_category()` and then call `get_queried_object()` itself.
	 * Six of those restatements existed before #705 and they had drifted:
	 * the JSON-LD ItemList covered tags, IndexNow covered brands, and the
	 * head metadata covered neither.
	 *
	 * @return object|null The queried term, or null when this is not one.
	 */
	public function covered_term() {
		if ( ! function_exists( 'is_tax' ) || ! is_tax( self::COVERED_TERM_TAXONOMIES ) ) {
			return null;
		}

		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! is_object( $term ) || ! isset( $term->taxonomy, $term->term_id ) ) {
			return null;
		}

		return in_array( (string) $term->taxonomy, self::COVERED_TERM_TAXONOMIES, true ) ? $term : null;
	}

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
	 * The page number of the current request, or 1.
	 *
	 * Both query vars, like core: `wp_get_document_title()` reads `$paged`
	 * and `$page` and suffixes on `max()` of the two. Which one carries the
	 * number depends on the request — on a shop-as-front-page it is `page`,
	 * so reading `paged` alone makes `/`, `/page/2/` and `/page/3/` all
	 * answer 1 (#668).
	 */
	private function current_page_number(): int {
		if ( ! function_exists( 'get_query_var' ) ) {
			return 1;
		}

		$paged_raw = get_query_var( 'paged' );
		$page_raw  = get_query_var( 'page' );

		return max( 1, (int) $paged_raw, (int) $page_raw );
	}

	/**
	 * The canonical URL for page N of an archive.
	 *
	 * Derived from the archive's own permalink, never from the request.
	 * `get_pagenum_link()` builds on `remove_query_arg( 'paged' )`, which with
	 * no URI reads `$_SERVER['REQUEST_URI']` and carries every OTHER argument
	 * through — so `/shop/page/2/?orderby=price` published that whole string
	 * as og:url. That is not stray traffic: `orderby` is WooCommerce's own
	 * sorting dropdown, and `min_price`, `filter_pa_*` and `utm_*` ride along
	 * the same way. Page 1 was always clean, because it uses the permalink,
	 * so one page of the archive was canonical and the rest were
	 * request-derived — and this plugin emits no `<link rel="canonical">` on
	 * archives, which makes og:url the only self-referential claim the page
	 * has (#682 review).
	 *
	 * @param string $base Unpaginated archive URL.
	 * @param int    $page Page number, 2 or greater.
	 */
	private function paginated_url( string $base, int $page ): string {
		if ( '' === $base ) {
			return $base;
		}

		// Plain permalinks have no /page/N/ path segment to append to.
		$structure = function_exists( 'get_option' ) ? (string) get_option( 'permalink_structure' ) : '';
		if ( '' === $structure ) {
			return function_exists( 'add_query_arg' ) ? (string) add_query_arg( 'paged', $page, $base ) : $base;
		}

		// `pagination_base` rather than a literal "page": WordPress lets a
		// site rename it, and a hardcoded segment would 404 on those stores.
		global $wp_rewrite;
		$segment = ( isset( $wp_rewrite->pagination_base ) && '' !== $wp_rewrite->pagination_base )
			? (string) $wp_rewrite->pagination_base
			: 'page';

		$paginated = trailingslashit( $base ) . $segment . '/' . $page;

		return function_exists( 'user_trailingslashit' ) ? (string) user_trailingslashit( $paginated ) : $paginated;
	}

	/**
	 * The canonical URL for a product search, page N.
	 *
	 * Built from the query vars, never from `$_SERVER['REQUEST_URI']` — the
	 * trap paginated_url() documents, where every other argument on the
	 * request (`orderby`, `utm_*`) rides along into the published URL.
	 *
	 * Order matters: paginated_url() appends a `/page/N` PATH segment, so it
	 * has to run on the bare home URL before any query string exists. That
	 * also happens to be right for plain permalinks, where it returns
	 * `?paged=N` instead and the arguments below merge into it cleanly.
	 *
	 * @param string $query The raw search term.
	 * @param int    $page  Page number, 1 or greater.
	 */
	private function search_og_url( string $query, int $page ): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}

		$base = (string) home_url( '/' );
		if ( $page >= 2 ) {
			$base = $this->paginated_url( $base, $page );
		}

		if ( ! function_exists( 'add_query_arg' ) ) {
			return $base;
		}

		// Raw values, deliberately. `add_query_arg()` runs `urlencode_deep()`
		// on its arguments (wp-includes/functions.php), so pre-encoding here
		// would publish `blue %26 grey` as `blue%2B%2526%2Bgrey`.
		return (string) add_query_arg(
			array(
				's'         => $query,
				'post_type' => 'product',
			),
			$base
		);
	}

	/**
	 * Append core's "Page N" suffix, using the merchant's own separator.
	 *
	 * @param string $title Base title.
	 * @param int    $page  Page number, 2 or greater.
	 */
	private function with_page_suffix( string $title, int $page ): string {
		if ( '' === $title ) {
			return $title;
		}

		/** This filter is documented in wp-includes/general-template.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally re-invoking WP core's own `document_title_separator` filter, so a merchant's separator customization applies here too.
		$sep = (string) apply_filters( 'document_title_separator', '-' );

		// Known divergence, measured, and not worth chasing. Core attaches
		// `wptexturize` to the `document_title` filter
		// (wp-includes/default-filters.php:163), and its dash rule rewrites a
		// space-wrapped hyphen to an en dash. So a stock store with no
		// plugins renders `Shop – Page 2 – Store` in the tab beside our
		// `Shop - Page 2` here: same separator, texturized there and not
		// here, because an Open Graph attribute never passes through that
		// filter. The page NUMBER agrees, which is what #682 was about.
		// (The two also differ in that <title> carries the site name and
		// og:title does not, by design — og:site_name carries it.)
		//
		// Not passed through number_format_i18n(), matching the <title>
		// suffix this mirrors — see prepare_authored_title() for why core's
		// own inconsistency is inherited rather than corrected here.
		return $title . ' ' . $sep . ' ' . sprintf(
			/* translators: %s: Page number. */
			__( 'Page %s', 'woocommerce-ai-storefront' ),
			$page
		);
	}

	/**
	 * Last-resort description for the shop archive.
	 *
	 * Names what the store sells rather than repeating the store name, which
	 * the title already carries. The category list comes from the same cached
	 * catalog summary that feeds llms.txt and the JSON-LD `knowsAbout` block,
	 * so the three surfaces cannot describe the catalogue differently, and
	 * reading it costs a transient rather than a query.
	 *
	 * Falls back to naming the store when the catalogue has no categories —
	 * a store selling only uncategorised products, or one with nothing in it
	 * yet.
	 */
	private function generated_shop_description(): string {
		$site  = (string) get_bloginfo( 'name' );
		$names = $this->top_category_names();
		$list  = array() === $names ? '' : $this->list_in_words( $names );

		// Four cases, not two. Bailing on a missing site name discarded the
		// category list we already had and put the shop archive straight back
		// to shipping no description — the defect this exists to fix, on the
		// page it fixes it for (#682 review). The category branch above makes
		// the same call the same way: name what you can, drop what you have
		// not got.
		if ( '' !== $list && '' !== $site ) {
			$description = sprintf(
				/* translators: 1: Comma-separated product category names. 2: Store name. */
				__( 'Shop %1$s at %2$s.', 'woocommerce-ai-storefront' ),
				$list,
				$site
			);
		} elseif ( '' !== $list || '' !== $site ) {
			$description = sprintf(
				/* translators: %s: Product category names, or the store name when there are none. */
				__( 'Shop %s.', 'woocommerce-ai-storefront' ),
				'' !== $list ? $list : $site
			);
		} else {
			// No name and no categories. Nothing truthful to say, and worth a
			// breadcrumb: a store reaching here has neither a site title nor
			// a single categorised product.
			WC_AI_Storefront_Logger::debug(
				'Meta description: the shop archive has no authored description, no site title and no product categories. Emitting none.'
			);

			return '';
		}

		// Cleaned and truncated like every sibling fallback. This is also the
		// path that builds the LONGEST strings — three category names plus
		// the store name — so it is the one most likely to exceed the limit.
		return WC_AI_Storefront_Meta_Text::truncate( WC_AI_Storefront_Meta_Text::clean_text( $description ), self::DESCRIPTION_MAX );
	}

	/**
	 * Up to three top-level category names, from the cached catalog summary.
	 *
	 * @return string[]
	 */
	private function top_category_names(): array {
		// No class_exists() guard: WC_AI_Storefront_JsonLd is in this
		// plugin's own classmap, so class_exists() would autoload it and a
		// missing file would fatal inside the guard rather than returning
		// false. A store running this line has an intact plugin directory by
		// construction — the same autoloader loaded this class (#682 review).
		$summary = ( new WC_AI_Storefront_JsonLd() )->get_catalog_summary();
		if ( ! is_array( $summary ) ) {
			// An empty catalogue returns array(), not this. A non-array means
			// the cached value has been mangled — a persistent object cache,
			// or a `pre_transient_*` filter — and the same read backs
			// llms.txt's Catalog section and the JSON-LD knowsAbout block, so
			// all three degrade together with no other signal.
			WC_AI_Storefront_Logger::debug(
				'Meta description: catalog summary cache returned %s, not an array. llms.txt and knowsAbout read the same value.',
				get_debug_type( $summary )
			);

			return array();
		}

		// Filter first, then cap. Slicing first let a malformed entry eat one
		// of the three slots and silently shorten the list (#682 review).
		$names = array();
		foreach ( $summary as $entry ) {
			$name = is_array( $entry ) ? (string) ( $entry['name'] ?? '' ) : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_slice( $names, 0, 3 );
	}

	/**
	 * Join names the way a person would read them aloud.
	 *
	 * @param string[] $names One or more names; an empty array yields ''.
	 */
	private function list_in_words( array $names ): string {
		if ( count( $names ) < 2 ) {
			return (string) ( $names[0] ?? '' );
		}

		// wp_sprintf( '%l' ) rather than our own join. Core's wp_sprintf_l()
		// exposes the separators as three separately translatable strings —
		// `between`, `between_last_two`, `between_only_two` — plus a
		// `wp_sprintf_l` filter. A hardcoded ', ' cannot reach a locale that
		// joins with a different character, and the separator sitting outside
		// the translatable string put it beyond a translator's reach
		// entirely (#682 review).
		return (string) wp_sprintf( '%l', $names );
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
				$description = WC_AI_Storefront_Meta_Text::truncate( WC_AI_Storefront_Meta_Text::clean_text( $fallback ), self::DESCRIPTION_MAX );
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
			$text = WC_AI_Storefront_Meta_Text::clean_text( $raw );
			if ( WC_AI_Storefront_Meta_Text::is_readable_prose( $text ) ) {
				return WC_AI_Storefront_Meta_Text::truncate( $text, self::DESCRIPTION_MAX );
			}
			if ( '' !== trim( $raw ) ) {
				// Merchant content existed and we discarded it: markup,
				// shortcodes or whitespace with no readable prose. Worth a
				// breadcrumb when a merchant asks why their copy is not in
				// the SERP snippet.
				WC_AI_Storefront_Logger::debug(
					'meta description candidate had content but no readable prose; trying the next one'
				);
			}
		}
		return '';
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

		$covered = $this->covered_term();
		if ( null !== $covered ) {
			$term   = $covered;
			$source = $term;
			if ( is_object( $term ) && isset( $term->description ) ) {
				$candidates[] = (string) $term->description;
			}
			if ( is_object( $term ) && isset( $term->name ) ) {
				// Term fallback so the page always carries a description.
				// Required because we suppress Jetpack's on commerce pages
				// (see suppress_jetpack_description()) and would otherwise
				// leave none. Covers category, tag and brand since #705.
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
			$is_shop      = true;
		}

		$description = $this->first_usable_candidate( $candidates );

		// Generated terminus, the parallel to the product and category
		// fallbacks. Without it the shop archive was the one page type that
		// could ship no description at all, and on a fresh store it does: the
		// tagline is empty by default and the Shop page's content is empty or
		// a bare product-collection block (#682).
		//
		// Built here rather than appended to $candidates so it costs nothing
		// on the common path. As a candidate it was computed on every shop
		// render, including the ones that already had a description, for a
		// value first_usable_candidate() would then discard.
		if ( '' === $description && ! empty( $is_shop ) ) {
			$description = $this->generated_shop_description();
		}

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

		// Same two helpers the archive path uses. This block used to be a
		// second implementation of both — one that read `paged` without the
		// function_exists() guard, and a second copy of the separator
		// comment and its phpcs ignore. The .pot showed the cost: `Page %s`
		// carried two source references for one string (#682 review).
		$page = $this->current_page_number();
		if ( $page >= 2 ) {
			$authored = $this->with_page_suffix( $authored, $page );
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
			'og:locale'      => WC_AI_Storefront_Meta_Text::og_locale(),
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
		// The card type has to match what we can actually show. The Cards
		// spec makes twitter:image REQUIRED for summary_large_image, so
		// declaring that card with no image cannot render as one (#683).
		//
		// This reaches product pages too, deliberately. build_og_tags()
		// sets og:image only when get_post_thumbnail_id() returns an ID
		// whose attachment resolves, so a product with no featured image
		// arrives here without one and now asks for the small card. It
		// previously asked for the large card it had no image for. There
		// is no image fallback on the product path: archive_image() is
		// reached only from build_archive_og_tags().
		$has_image = isset( $og['og:image'] ) && '' !== $og['og:image'];
		$tw        = array(
			'twitter:card'        => $has_image ? 'summary_large_image' : 'summary',
			'twitter:title'       => $og['og:title'] ?? '',
			'twitter:description' => $og['og:description'] ?? '',
		);
		if ( $has_image ) {
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
			'og:locale'      => WC_AI_Storefront_Meta_Text::og_locale(),
		);

		$covered = $this->covered_term();
		if ( null !== $covered ) {
			$term = $covered;
			if ( is_object( $term ) ) {
				if ( isset( $term->name ) ) {
					$og['og:title'] = (string) $term->name;
				}
				$link = isset( $term->term_id ) ? get_term_link( $term ) : '';
				if ( is_string( $link ) && '' !== $link ) {
					$og['og:url'] = $link;
				}

				// Same treatment as the shop archive below: without it every
				// page of a category listing shares one social identity, and
				// og:title disagrees with a <title> that does carry the page
				// number (#682 review).
				$page = $this->current_page_number();
				if ( $page >= 2 ) {
					$og['og:title'] = $this->with_page_suffix( (string) $og['og:title'], $page );
					$og['og:url']   = $this->paginated_url( (string) $og['og:url'], $page );
				}
			}
		} elseif ( '' !== $this->search_query() ) {
			// Ahead of the is_shop() branch on purpose: WooCommerce reports
			// is_shop() as true on a product search, so testing shop first
			// makes this dead code and the page keeps advertising the Shop
			// page's title and URL instead of the search it is (#692).
			//
			// An EMPTY query deliberately falls through to that branch. A card
			// headlined 'Search Results for ""' is worse than the shop's own,
			// and `/?s=&post_type=product` is a real request.
			$query = WC_AI_Storefront_Meta_Text::truncate( $this->search_query(), self::SEARCH_QUERY_MAX );

			$og['og:title'] = sprintf(
				/* translators: %s: the term the visitor searched for. */
				__( 'Search Results for “%s”', 'woocommerce-ai-storefront' ),
				$query
			);

			$page         = $this->current_page_number();
			$og['og:url'] = $this->search_og_url( $this->search_query(), $page );
			if ( $page >= 2 ) {
				$og['og:title'] = $this->with_page_suffix( (string) $og['og:title'], $page );
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

			// Paginate both, or every page of the shop shares one social
			// identity: share page 2 and the preview claims to be page 1
			// (#682). It also left og:title disagreeing with the <title>,
			// which core has suffixed with "Page N" since 4.4 and which #668
			// restored on our own authored-title short-circuit path.
			$page = $this->current_page_number();
			if ( $page >= 2 ) {
				$og['og:title'] = $this->with_page_suffix( (string) $og['og:title'], $page );
				$og['og:url']   = $this->paginated_url( (string) $og['og:url'], $page );
			}
		}

		$image = $this->archive_image();
		if ( '' !== $image['url'] ) {
			$og['og:image'] = $image['url'];
			// Same gate as the product path: for 'full', image_downsize()
			// seeds both at 0 and only overwrites them from the attachment's
			// _wp_attachment_metadata width/height (not its `sizes` entries,
			// which 'full' never consults), so an attachment with no metadata
			// would otherwise publish og:image:width="0". Two of the five
			// sources never reach image_downsize() at all and report 0
			// deliberately: the filter branch and the site icon.
			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$og['og:image:width']  = (string) $image['width'];
				$og['og:image:height'] = (string) $image['height'];
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
	 * The search term as typed, or '' when this is not a product search.
	 *
	 * Tests the `post_type` query var as well as `is_search()`, which
	 * is_product_search() deliberately does not. That helper documents itself
	 * as safe on the strength of every caller sitting behind should_emit(),
	 * which has already narrowed the request to a commerce page. This method
	 * cannot borrow that: build_archive_og_tags() is public, so a theme or
	 * plugin calling it during an ordinary blog search would otherwise get a
	 * headline announcing product results and an og:url carrying
	 * `post_type=product` for a search that was never scoped to products
	 * (#693 review). The narrowing is one condition; the invariant it relies
	 * on otherwise lives in a different class.
	 *
	 * `get_search_query()` escapes with `esc_attr` unless told not to. This
	 * value is escaped once at output by print_meta(), and is used unescaped
	 * to build a URL, so it has to come back raw.
	 */
	private function search_query(): string {
		if ( ! $this->is_product_search() || ! function_exists( 'get_search_query' ) ) {
			return '';
		}

		if ( ! function_exists( 'get_query_var' ) || 'product' !== get_query_var( 'post_type' ) ) {
			return '';
		}

		return trim( (string) get_search_query( false ) );
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
		} elseif ( ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_shop' ) && is_shop() )
			|| $this->is_product_search() ) {
			// Product search included since #692. It used to return here with
			// nothing but the robots directive, on the reasoning that there is
			// no single product or term to describe. That conflated two
			// questions: `noindex` tells a crawler not to index the page,
			// while Open Graph tells Slack and Facebook how to draw a card
			// when someone pastes the link. A noindexed page still gets
			// shared. build_archive_og_tags() branches on the query.
			//
			// is_product_search() is named explicitly rather than left to
			// is_shop(), which WooCommerce also reports as true here. Relying
			// on that quirk would make this silently stop covering search if
			// it ever changed.
			$description = $this->build_archive_description();
			$og          = $this->build_archive_og_tags( $description );
		} else {
			// should_emit() admits exactly four page types and the three
			// branches above cover all of them, so this is unreachable. A
			// bare return rather than an assumption that it is.
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
		// One set of social tags per page (#676). An enriching strategy has
		// already hooked the other plugin's own pipeline to correct its
		// og:type and add the commerce facts it lacks, so printing ours as
		// well would produce exactly the duplication that work removes. The
		// description above is decided separately, by
		// WC_AI_Storefront_Rival_Seo_Description, and is unaffected.
		if ( ! WC_AI_Storefront_Og_Strategies::emission_is_delegated() ) {
			$this->print_og_and_twitter( $og, $product );
		}
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
		$og = WC_AI_Storefront_Meta_Image::drop_unprintable_image( $og );

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
	 * Resolve the archive's social image, with its dimensions when known.
	 *
	 * Order: the archive's own image (a product category's thumbnail, or the
	 * shop page's featured image), then a merchant/dev-configured default
	 * (filter), then a product the merchant curated, then the site logo
	 * (Customizer custom_logo), then the site icon.
	 *
	 * The curated-product step sits below the filter deliberately: a store
	 * that configured a brand image asked for that image, and must not be
	 * overridden by a heuristic. It sits above the logo because a product
	 * photo outperforms a logo as a share card.
	 *
	 * @return array{url:string,width:int,height:int} Empty URL when the store
	 *                                                has no image to offer.
	 */
	private function archive_image(): array {
		$own = $this->archive_own_image();
		if ( '' !== $own['url'] ) {
			return $own;
		}

		/**
		 * Filter the default Open Graph image URL for archive pages.
		 *
		 * @param string $url Default image URL. Empty string falls through to a
		 *                    curated product, then the site logo, then the site
		 *                    icon.
		 */
		$configured = apply_filters( 'wc_ai_storefront_og_default_image', '' );
		if ( ! is_string( $configured ) ) {
			// A non-string here is a callback bug, not a merchant choice, and
			// casting it would turn that bug into a published claim: (string)
			// on an array yields "Array", which esc_url() then ships as
			// og:image="http://Array", and on an object with no __toString it
			// is an uncaught Error mid-wp_head. Returning an attachment array
			// is the easy mistake, since wp_get_attachment_image_src() (used
			// three times in this class) returns exactly that.
			WC_AI_Storefront_Logger::debug(
				'Open Graph: wc_ai_storefront_og_default_image returned %s, not a string. Ignoring it.',
				get_debug_type( $configured )
			);
			$configured = '';
		}
		if ( '' !== $configured && '' === WC_AI_Storefront_Meta_Image::usable_url( $configured ) ) {
			// Returning early on a URL the printer cannot emit would cost the
			// store the curated product, logo and icon steps below, and leave
			// the archive with no image at all.
			WC_AI_Storefront_Logger::debug(
				'Open Graph: wc_ai_storefront_og_default_image returned "%s", which esc_url() empties. Ignoring it.',
				$configured
			);
			$configured = '';
		}
		if ( '' !== $configured ) {
			// A bare URL carries no dimensions we can vouch for.
			return array(
				'url'    => $configured,
				'width'  => 0,
				'height' => 0,
			);
		}

		$curated = $this->archive_product_image();
		if ( '' !== $curated['url'] ) {
			return $curated;
		}

		$logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;
		$logo    = WC_AI_Storefront_Meta_Image::attachment_image( $logo_id );
		if ( '' !== $logo['url'] ) {
			return $logo;
		}

		if ( function_exists( 'get_site_icon_url' ) ) {
			// usable_url() because get_site_icon_url has its own filter, so
			// this is not guaranteed to be a URL WordPress produced.
			$icon = WC_AI_Storefront_Meta_Image::usable_url( (string) get_site_icon_url( 512 ) );
			if ( '' !== $icon ) {
				// No dimensions, for the same reason the filter branch above
				// reports none: we cannot vouch for them. Core's
				// get_site_icon_url() takes the `$size >= 512` branch and asks
				// for 'full', i.e. the site-icon attachment at whatever size it
				// was stored, NOT a 512 crop (wp-includes/general-template.php).
				// The Customizer usually does store 512x512, but "Skip cropping"
				// keeps the original, `wp option update site_icon` sets any
				// attachment, and the get_site_icon_url filter can return any
				// URL at all. Publishing a width a scraper then measures and
				// disagrees with is worse than publishing none.
				return array(
					'url'    => $icon,
					'width'  => 0,
					'height' => 0,
				);
			}
		}

		return WC_AI_Storefront_Meta_Image::no_image();
	}

	/**
	 * The image belonging to this archive itself, if the merchant set one.
	 *
	 * A product category or brand carries one in `thumbnail_id` term meta. A
	 * tag does not, so that branch falls through to `no_image()` below and
	 * archive_image()'s filter and curated-product chain takes over (#705).
	 * The shop archive is backed by a real page (`wc_get_page_id( 'shop' )`),
	 * so it carries one as that page's featured image.
	 *
	 * @return array{url:string,width:int,height:int}
	 */
	private function archive_own_image(): array {
		// Categories and brands can carry a `thumbnail_id`; tags cannot, so a
		// tag archive falls through to the filter and curated-product chain
		// below. That is the intended path, not a gap (#705).
		$covered = $this->covered_term();
		if ( null !== $covered ) {
			$term_id = (int) $covered->term_id;
			if ( $term_id > 0 ) {
				return WC_AI_Storefront_Meta_Image::attachment_image( (int) get_term_meta( $term_id, 'thumbnail_id', true ) );
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop_id > 0 && function_exists( 'get_post_thumbnail_id' ) ) {
				return WC_AI_Storefront_Meta_Image::attachment_image( (int) get_post_thumbnail_id( $shop_id ) );
			}
		}

		return WC_AI_Storefront_Meta_Image::no_image();
	}

	/**
	 * A product image standing in for an archive that has none of its own.
	 *
	 * Prefers Featured products: WooCommerce already stores that curation in
	 * the `product_visibility` taxonomy, so this reads a marketing decision
	 * the merchant already made rather than inventing a new setting.
	 *
	 * Deliberately NOT the first product in the rendered loop. SEOPress does
	 * that, and the result is that `/shop/page/2/` advertises a different
	 * product than `/shop/`, and re-sorting the archive changes both. This
	 * query is independent of the archive's own ordering and paging.
	 *
	 * Runs only after the archive's own image and the filter have both come
	 * up empty, so a store that configured either never pays for the query.
	 *
	 * @return array{url:string,width:int,height:int}
	 */
	private function archive_product_image(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return WC_AI_Storefront_Meta_Image::no_image();
		}

		$args = array(
			'status'     => 'publish',
			// Catalog visibility is the native answer to "may a shopper meet
			// this product on an archive?", which is the question being asked.
			'visibility' => 'catalog',
			'limit'      => 10,
			// ID is the tie-break, not decoration: menu_order is 0 across the
			// whole catalog until a merchant reorders it, so on most stores
			// every candidate ties and the winner would be left to the query
			// plan. The docblock's claim to be stable across paging and
			// re-sorting only holds with a total ordering.
			'orderby'    => 'menu_order ID',
			'order'      => 'ASC',
			'paginate'   => false,
			// IDs, not objects: first_product_image() reads one meta field,
			// and 'objects' would hydrate up to ten full WC_Product instances
			// (post, meta and lookup rows apiece) to get it, during wp_head.
			'return'     => 'ids',
			'featured'   => true,
		);

		$slug = $this->queried_term_slug();
		if ( '' !== $slug ) {
			$args['category'] = array( $slug );
		}

		$image = $this->first_product_image( wc_get_products( $args ) );
		if ( '' !== $image['url'] ) {
			return $image;
		}

		if ( '' === $slug ) {
			// On the shop archive there is no narrower set to retry with: with
			// nothing featured, every product in the catalog is an equally
			// arbitrary stand-in for the whole store, so we pick none.
			return WC_AI_Storefront_Meta_Image::no_image();
		}

		// Within one category every product does belong to the thing being
		// described, so any of them represents it when none is featured.
		unset( $args['featured'] );

		return $this->first_product_image( wc_get_products( $args ) );
	}

	/**
	 * The first product in a list that actually has an image.
	 *
	 * @param mixed $product_ids Whatever wc_get_products() returned. Expected
	 *                           to be an array of IDs; guarded because a
	 *                           filter on the query can change the shape.
	 * @return array{url:string,width:int,height:int}
	 */
	private function first_product_image( $product_ids ): array {
		if ( ! is_array( $product_ids ) || array() === $product_ids ) {
			return WC_AI_Storefront_Meta_Image::no_image();
		}

		foreach ( $product_ids as $product_id ) {
			$image = WC_AI_Storefront_Meta_Image::attachment_image( (int) get_post_thumbnail_id( (int) $product_id ) );
			if ( '' !== $image['url'] ) {
				return $image;
			}
		}

		// Candidates existed and none of them yielded an image. Usually that
		// just means an imageless catalog, but it is also what a deleted
		// attachment looks like, and what a third party forcing a different
		// `return` shape on the query looks like — all three are otherwise
		// indistinguishable from "no candidates" at the call site.
		WC_AI_Storefront_Logger::debug(
			'Open Graph: %d archive image candidates, none with a readable image.',
			count( $product_ids )
		);

		return WC_AI_Storefront_Meta_Image::no_image();
	}

	/**
	 * The queried term's slug, or '' when this is not a covered term archive.
	 *
	 * Was named queried_category_slug(); renamed because the value it
	 * returns is no longer always a category (#705).
	 */
	private function queried_term_slug(): string {
		$term = $this->covered_term();

		return ( null !== $term && isset( $term->slug ) ) ? (string) $term->slug : '';
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
