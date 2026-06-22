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
	 * Build the meta description for a product, auto-derived from core fields.
	 *
	 * @param WC_Product $product Product to derive from.
	 * @return string Cleaned, truncated description; '' when no source text.
	 */
	public function build_description( $product ): string {
		$candidates = array(
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
	 * Category → the term's description. Shop → the shop page content, falling
	 * back to the store tagline. Cleaned/truncated like the product path.
	 *
	 * @return string Cleaned, truncated description; '' when no source text.
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
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop_id > 0 ) {
				$raw = (string) get_post_field( 'post_content', $shop_id );
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
				$title = $product->get_name();
				$brand = $this->get_brand_name( $product );
				if ( '' !== $brand ) {
					$title .= ' | ' . $brand;
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
			$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			if ( $shop_id > 0 ) {
				$og['og:title'] = get_the_title( $shop_id );
				$og['og:url']   = get_permalink( $shop_id );
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
		// Early in <head> so the description/OG/robots sit near the top.
		add_action( 'wp_head', array( $this, 'render_head_tags' ), 5 );
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
		$parts = explode( '_', $locale );
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
