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
 * WooCommerce install none does. Core emits plenty in `wp_head` — robots,
 * feed links, oEmbed discovery, generator, canonical, shortlink — but no
 * `og:*`, no `twitter:*` and no `<meta name="description">`, so a scraper
 * falls back to guessing from the `<title>` and the markup (#680).
 *
 * This is not this plugin becoming an SEO plugin. Six `og:*` properties,
 * three `twitter:*`, a `<meta name="description">`, and an image with its
 * dimensions when the post has one.
 *
 * Explicitly NOT emitted: `article:published_time`, `article:modified_time`,
 * `article:author` and `profile:*`. #679 excluded the first two from the
 * COMMERCE path, on the argument that publish timestamps on a product reach
 * outside this plugin's line — which does not transfer here, since posts are
 * exactly what those properties describe. The reason here is narrower and
 * its own: this is a fallback for stores with no general-purpose emitter,
 * and the smallest thing that fixes a blank card is the right size for it.
 * Authorship and timestamps are where a real SEO plugin starts.
 *
 * `og:type` here is `article`, which unlike `product` is in the ogp.me
 * vocabulary, so this one carries no grey area.
 */
class WC_AI_Storefront_Content_Meta_Tags {

	/**
	 * Soft cap on the generated description, matching the commerce emitter.
	 */ private const DESCRIPTION_MAX = 155;

	/**
	 * Whether render() actually printed a meta description this request.
	 *
	 * should_emit() is not the same question. It says we are the emitter for
	 * this page; it does not say we produced a description, and
	 * build_description() returns '' whenever neither the excerpt nor the
	 * content is readable prose. Suppressing Jetpack's on should_emit() alone
	 * therefore deleted a description the merchant had written by hand and
	 * printed nothing in its place (#689 review).
	 */
	private bool $printed_description = false;

	/**
	 * Register the emitter.
	 */
	public function init(): void {
		// Jetpack's SEO description is a SEPARATE module from its Open Graph:
		// Jetpack_SEO::init() hooks wp_head at 10 with no dependency on
		// Publicize or Sharing, while Open Graph only loads when one of those
		// is active. "SEO Tools on, Open Graph off" is an ordinary state, and
		// on it we printed a description at wp_head:5 and Jetpack printed a
		// second at 10 — the defect #678 exists to remove (#680 review).
		//
		// The filter fires at wp_head:10, after render(), so should_emit()
		// has already settled by the time this runs.
		add_filter( 'jetpack_seo_meta_tags', array( $this, 'suppress_jetpack_description' ) );

		// Same priority as the commerce emitter, and they are mutually
		// exclusive by should_emit(). Also after Jetpack's wp_head:1 loader,
		// so has_action( 'wp_head', 'jetpack_og_tags' ) is answerable by now.
		add_action( 'wp_head', array( $this, 'render' ), 5 );
	}   /**
	 * Drop Jetpack's meta description when we have written one.
	 *
	 * Keyed on what render() printed rather than on should_emit(), because
	 * the two can disagree: a post whose excerpt and content are both
	 * unreadable passes the gate and still yields no description. The filter
	 * fires at wp_head:10 and render() runs at 5, so the flag has settled.
	 *
	 * Recomputing build_description() here would not do either, because the
	 * tag map passes through `wc_ai_storefront_content_og_tags` before it is
	 * printed, so what reached the page is the only honest answer.
	 *
	 * @param mixed $meta Jetpack's tag map.
	 * @return mixed Unchanged when we printed no description of our own.
	 */
	public function suppress_jetpack_description( $meta ) {
		if ( ! is_array( $meta ) || ! $this->printed_description ) {
			return $meta;
		}

		unset( $meta['description'] );

		return $meta;
	}

	/**
	 * Whether to emit for the current request.
	 *
	 * Presence-based and deliberately coarser than the observe-the-filter
	 * approach #669 uses for commerce descriptions. That is the right trade
	 * here, because the two failure directions are not symmetric: a false
	 * negative leaves a post with the blank card it has today, while a false
	 * positive puts a second set of social tags on a page that already has
	 * one. Err toward silence.
	 *
	 * KNOWN LIMIT, and it is the direction this cannot cover: the detector
	 * knows five plugins, so The SEO Framework, Slim SEO, Squirrly and any
	 * theme with built-in Open Graph open the gate and get duplicates. Only
	 * observing what actually reached the page closes that, the way
	 * WC_AI_Storefront_Og_Strategies does for commerce — but that ground
	 * truth was measured on commerce pages and has to be re-measured on posts
	 * before it can be leaned on here. Tracked as #690.
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

		// Posts and pages, named explicitly. A bare is_singular() also
		// matches attachments — which have no featured image, so an image
		// page got a card with no image — and every public custom post type
		// another plugin registers, all labelled og:type=article. The
		// docblock and the CHANGELOG both said "posts and pages"; the code
		// said any singular (#680 review).
		if ( ! function_exists( 'is_singular' ) || ! is_singular( array( 'post', 'page' ) ) ) {
			return false;
		}

		// A social scraper carries no cookie, so post_password_required() is
		// always true for it — and get_the_excerpt() answers a protected post
		// with core's own "There is no excerpt because this is a protected
		// post." That is readable prose, so it won the candidate chain and
		// shipped as the description of every protected page (#680 review).
		if ( function_exists( 'post_password_required' ) && post_password_required() ) {
			return false;
		}

		if ( $this->is_commerce_page() ) {
			return false;
		}

		$present = null === $slugs ? $this->detected_slugs() : $slugs;
		$blocker = $this->blocking_source( $present );

		if ( '' !== $blocker ) {
			// The only failure mode of this feature is silence, and a
			// merchant who installed it to fix blank share cards has no way
			// to tell "the gate closed" from "the plugin is not running"
			// (#680 review).
			WC_AI_Storefront_Logger::debug(
				'Content social tags: standing down, %s is already providing metadata.',
				$blocker
			);

			return false;
		}

		return true;
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
	private function blocking_source( array $slugs ): string {
		// Jetpack was always observed rather than assumed, and the rest have
		// caught up (#690). This is the same signal
		// suppress_jetpack_open_graph() keys on, registered by Jetpack's own
		// wp_head:1 loader and settled before this runs at wp_head:5.
		if ( function_exists( 'has_action' ) && false !== has_action( 'wp_head', 'jetpack_og_tags' ) ) {
			return 'jetpack (Open Graph)';
		}

		// The four SEO plugins, by evidence that they rendered rather than by
		// the fact that they are installed. Two observers, because neither
		// covers all four on its own (measured on posts and pages, #690):
		//
		// - The Open Graph latches see Yoast, Rank Math and AIOSEO. Yoast with
		//   nothing authored emits a full Open Graph block and NO meta
		//   description at all, so the description observer alone would call
		//   it silent and we would duplicate its tags.
		// - The description observer sees Rank Math, AIOSEO and SEOPress.
		//   SEOPress has no filter seam to latch, only sixteen wp_head
		//   actions, and off commerce pages this plugin removes none of them.
		//
		// Their union covers all four. What neither reports is a provider we
		// have never measured: The SEO Framework, Slim SEO, SmartCrawl,
		// Squirrly, or a theme with built-in Open Graph. Those still produce
		// duplicates, and widening the detector's list would not change that,
		// which is why #690 ruled it out.
		if ( class_exists( 'WC_AI_Storefront_Og_Strategies' )
			&& WC_AI_Storefront_Og_Strategies::any_provider_emitting() ) {
			return 'an SEO plugin (Open Graph)';
		}

		if ( class_exists( 'WC_AI_Storefront_Rival_Seo_Description' )
			&& WC_AI_Storefront_Rival_Seo_Description::is_emitting() ) {
			return 'an SEO plugin (description)';
		}

		unset( $slugs );

		return '';
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

		// A static front page is singular, but it is the site, not an
		// article. The commerce emitter makes the same call for the mirror
		// case (shop-as-front-page gets `website` and the site name), and
		// Jetpack does `is_front_page() || is_home() -> website` (#680
		// review).
		$is_front = function_exists( 'is_front_page' ) && is_front_page();

		$og = array(
			'og:type'        => $is_front ? 'website' : 'article',
			'og:title'       => $is_front
				? (string) get_bloginfo( 'name' )
				: (string) get_the_title( get_queried_object_id() ),
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
		$filtered = apply_filters( 'wc_ai_storefront_content_og_tags', $og );

		// NOT a bare (array) cast. `(array) null` is [], which would delete
		// this feature store-wide in silence; `(array) 'oops'` is
		// [0 => 'oops'], which prints <meta property="0">; and a value that
		// is itself an array reaches esc_attr() and ships content="Array".
		// #684 hit the same class of problem casting a filter result and its
		// fix is the precedent here (#680 review).
		if ( ! is_array( $filtered ) ) {
			WC_AI_Storefront_Logger::debug(
				'Content social tags: wc_ai_storefront_content_og_tags returned %s, not an array. Ignoring it.',
				get_debug_type( $filtered )
			);

			return $og;
		}

		$clean = array();
		foreach ( $filtered as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$clean[ $key ] = (string) $value;
				continue;
			}

			WC_AI_Storefront_Logger::debug(
				'Content social tags: dropping filtered entry %s, which is %s.',
				is_string( $key ) ? $key : get_debug_type( $key ),
				get_debug_type( $value )
			);
		}

		return $clean;
	}

	/**
	 * Description for the current post: its authored excerpt, else its content.
	 *
	 * No tagline fallback on purpose. Repeating the same sentence under every
	 * post is worse than a card with a title and no description, and unlike
	 * the shop archive there is no single page whose identity it would carry.
	 */
	private function build_description(): string {
		$id = get_queried_object_id();

		// The RAW excerpt, deliberately. get_the_excerpt() runs
		// wp_trim_excerpt(), which calls apply_filters( 'the_content' ) —
		// so reading it here executes the entire content chain inside
		// wp_head:5. On the plain store this feature targets that means the
		// chain runs twice per view, and third-party callbacks that inject
		// once per request (ad injectors, related posts, share buttons behind
		// a static flag) fire in the head and go missing from the body
		// (#680 review). Falling through to post_content ourselves reaches
		// the same text without the side effects.
		$candidates = array(
			(string) get_post_field( 'post_excerpt', $id, 'raw' ),
			(string) get_post_field( 'post_content', $id, 'raw' ),
		);

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
		if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
			return WC_AI_Storefront_Meta_Image::no_image();
		}

		$thumbnail_id = (int) get_post_thumbnail_id( get_queried_object_id() );
		$image        = WC_AI_Storefront_Meta_Image::attachment_image( $thumbnail_id );

		if ( $thumbnail_id > 0 && '' === $image['url'] ) {
			// The merchant set a featured image and it did not resolve: a
			// deleted attachment, or a PDF set as the featured image. They
			// see a picture in the editor and a text-only card in the wild,
			// with nothing connecting the two (#680 review).
			WC_AI_Storefront_Logger::debug(
				'Content social tags: featured image %d did not resolve to a usable image.',
				$thumbnail_id
			);
		}

		return $image;
	}

	/**
	 * Print the tags.
	 */
	public function render(): void {
		if ( ! $this->should_emit() ) {
			return;
		}

		// The shared rule, not a second hand-written one. Testing a raw value
		// for emptiness and escaping it at print time ships og:image="" under
		// a summary_large_image card — #684 found that in the commerce
		// emitter, and writing this printer by hand reproduced it here within
		// hours (#680 review). Applied AFTER the filter, because the filter
		// can replace og:image.
		$tags = WC_AI_Storefront_Meta_Image::drop_unprintable_image( $this->build_tags() );

		// Recomputed from what survived, not from what was proposed.
		if ( ! isset( $tags['og:image'] ) ) {
			unset( $tags['twitter:image'] );
			$tags['twitter:card'] = 'summary';
		}

		$description = (string) ( $tags['og:description'] ?? '' );      if ( '' !== $description ) {
			$this->printed_description = true;

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
				// Escaped inline by the ternary below.
				$is_url ? esc_url( $content ) : esc_attr( $content ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}
}
