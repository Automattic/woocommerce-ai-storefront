<?php
/**
 * Social metadata for posts and pages, when nothing else provides any.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * A minimal share card for non-commerce content on a store that has no other
 * metadata source.
 *
 * WC_AI_Storefront_Meta_Tags is deliberately scoped to commerce pages, and
 * that boundary is what makes coexistence clean: a product page carries our
 * tags, a policy page carries whatever general-purpose plugin the merchant
 * runs. But the boundary assumes a second emitter exists, and on a plain
 * WooCommerce install none does — so a shared blog post unfurls as a blank
 * card, with core emitting a `<title>` and a canonical link and nothing else
 * (#680).
 *
 * This is not this plugin becoming an SEO plugin. Six core properties, an
 * image when there is one, and a description. Explicitly NOT emitted:
 * `article:published_time`, `article:modified_time`, `article:author` and
 * `profile:*`. Those belong to a general-purpose emitter, and #679 left them
 * out of the commerce path for the same reason.
 *
 * `og:type` here is `article`, which unlike `product` is in the ogp.me
 * vocabulary, so this one carries no grey area.
 */
class WC_AI_Storefront_Content_Meta_Tags {

	/**
	 * Soft cap on the generated description, matching the commerce emitter.
	 */
	private const DESCRIPTION_MAX = 155;

	/**
	 * Register the emitter.
	 */
	public function init(): void {
		// Same priority as the commerce emitter, and they are mutually
		// exclusive by should_emit(). Also after Jetpack's wp_head:1 loader,
		// so has_action( 'wp_head', 'jetpack_og_tags' ) is answerable by now.
		add_action( 'wp_head', array( $this, 'render' ), 5 );
	}

	/**
	 * Whether to emit for the current request.
	 *
	 * Presence-based and deliberately coarser than the observe-the-filter
	 * approach #669 uses for commerce descriptions. That is the right trade
	 * here, because the two failure directions are not symmetric: a false
	 * negative leaves a post with the blank card it has today, while a false
	 * positive puts duplicate tags on page types this plugin has never
	 * touched. Err toward silence.
	 *
	 * @param string[]|null $slugs Detected SEO plugin slugs; resolved from
	 *                             the detector when null. Injectable because
	 *                             detection reads version constants, which a
	 *                             shared-process test cannot undefine.
	 */
	public function should_emit( ?array $slugs = null ): bool {
		/** This filter is documented in WC_AI_Storefront_Meta_Tags::should_emit(). */
		if ( ! (bool) apply_filters( 'wc_ai_storefront_emit_meta_tags', true ) ) {
			return false;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return false;
		}

		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			// Singular only. An author or date archive has no authored text
			// to describe it, and a generated description there is worse
			// than none (#680).
			return false;
		}

		if ( $this->is_commerce_page() ) {
			return false;
		}

		return ! $this->another_source_is_emitting( null === $slugs ? $this->detected_slugs() : $slugs );
	}

	/**
	 * Whether the commerce emitter owns this request.
	 *
	 * Includes the shop-as-front-page case, which is singular-ish and
	 * commerce at once: it belongs to the other emitter.
	 */
	private function is_commerce_page(): bool {
		return ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_shop' ) && is_shop() );
	}

	/**
	 * Whether anything else on this store is already describing pages.
	 *
	 * @param string[] $slugs Detected SEO plugin slugs.
	 */
	private function another_source_is_emitting( array $slugs ): bool {
		foreach ( $slugs as $slug ) {
			if ( 'jetpack' !== $slug ) {
				return true;
			}

			// Jetpack is the one entry where presence is not the question.
			// It is active on a large share of WooCommerce stores with its
			// Open Graph feature off, and the store that demonstrated this
			// bug was exactly that. Treating presence as disqualifying would
			// mean the fix never fires where it is needed most (#680).
			//
			// This is the same signal suppress_jetpack_open_graph() keys on,
			// registered by Jetpack's own wp_head:1 loader and therefore
			// settled before this runs at wp_head:5.
			if ( function_exists( 'has_action' ) && false !== has_action( 'wp_head', 'jetpack_og_tags' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Slugs for every SEO plugin the detector reports.
	 *
	 * @return string[]
	 */
	private function detected_slugs(): array {
		return array_column( WC_AI_Storefront_Seo_Plugin_Detector::detect(), 'slug' );
	}

	/**
	 * The tag map for the current post or page.
	 *
	 * @return array<string,string> Property => content. Empty values are
	 *                              dropped at print time, as in the commerce
	 *                              emitter.
	 */
	public function build_tags(): array {
		$description = $this->build_description();

		$og = array(
			'og:type'        => 'article',
			'og:title'       => (string) get_the_title( get_queried_object_id() ),
			'og:description' => $description,
			'og:url'         => (string) get_permalink( get_queried_object_id() ),
			'og:site_name'   => (string) get_bloginfo( 'name' ),
			'og:locale'      => WC_AI_Storefront_Meta_Text::og_locale(),
		);

		$image = $this->featured_image();
		if ( '' !== $image['url'] ) {
			$og['og:image'] = $image['url'];
			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$og['og:image:width']  = (string) $image['width'];
				$og['og:image:height'] = (string) $image['height'];
			}
		}

		// Card type follows the image, the same rule #683 settled for
		// archives: declaring a large card with nothing to put in it
		// degrades to a bare link.
		$og['twitter:card']        = '' !== $image['url'] ? 'summary_large_image' : 'summary';
		$og['twitter:title']       = $og['og:title'];
		$og['twitter:description'] = $description;
		if ( '' !== $image['url'] ) {
			$og['twitter:image'] = $image['url'];
		}

		/**
		 * Filter the non-commerce social tag map.
		 *
		 * @param array<string,string> $og Property => content.
		 */
		return (array) apply_filters( 'wc_ai_storefront_content_og_tags', $og );
	}

	/**
	 * Description for the current post: its excerpt, else its content.
	 *
	 * No tagline fallback on purpose. Repeating the same sentence under every
	 * post is worse than a card with a title and no description, and unlike
	 * the shop archive there is no single page whose identity it would carry.
	 */
	private function build_description(): string {
		$candidates = array( (string) get_the_excerpt( get_queried_object_id() ) );

		if ( function_exists( 'get_post_field' ) ) {
			$candidates[] = (string) get_post_field( 'post_content', get_queried_object_id() );
		}

		foreach ( $candidates as $raw ) {
			$text = WC_AI_Storefront_Meta_Text::clean_text( $raw );
			if ( WC_AI_Storefront_Meta_Text::is_readable_prose( $text ) ) {
				return WC_AI_Storefront_Meta_Text::truncate( $text, self::DESCRIPTION_MAX );
			}
		}

		return '';
	}

	/**
	 * The post's featured image, with dimensions when known.
	 *
	 * @return array{url:string,width:int,height:int}
	 */
	private function featured_image(): array {
		$empty = array(
			'url'    => '',
			'width'  => 0,
			'height' => 0,
		);

		if ( ! function_exists( 'get_post_thumbnail_id' ) || ! function_exists( 'wp_get_attachment_image_src' ) ) {
			return $empty;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( get_queried_object_id() );
		if ( $thumbnail_id <= 0 ) {
			return $empty;
		}

		$src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
		if ( ! is_array( $src ) || ! isset( $src[0] ) || '' === (string) $src[0] ) {
			return $empty;
		}

		return array(
			'url'    => (string) $src[0],
			'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
			'height' => isset( $src[2] ) ? (int) $src[2] : 0,
		);
	}

	/**
	 * Print the tags.
	 */
	public function render(): void {
		if ( ! $this->should_emit() ) {
			return;
		}

		$tags        = $this->build_tags();
		$description = (string) ( $tags['og:description'] ?? '' );

		if ( '' !== $description ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $description )
			);
		}

		foreach ( $tags as $key => $content ) {
			if ( '' === $content ) {
				continue;
			}

			$is_twitter = 0 === strpos( $key, 'twitter:' );
			$is_url     = in_array( $key, array( 'og:url', 'og:image', 'twitter:image' ), true );

			printf(
				'<meta %1$s="%2$s" content="%3$s" />' . "\n",
				$is_twitter ? 'name' : 'property',
				esc_attr( $key ),
				// Escaped immediately above by the ternary.
				$is_url ? esc_url( $content ) : esc_attr( $content ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}
}
