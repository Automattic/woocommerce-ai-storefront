<?php
/**
 * Image resolution and the escaping rule every emitter must share.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * The parts of image handling that must not be written twice.
 *
 * Extracted when a second emitter needed them (#680 review). The escaping
 * rule in particular was paid for once already: #684 found that testing a raw
 * value for emptiness and escaping it later ships `og:image=""` under a
 * `summary_large_image` card, and the second emitter reproduced that defect
 * within hours of the first being fixed, because it was written by hand
 * rather than shared.
 *
 * The render loops themselves stay per-emitter — twenty lines of printing
 * that differ in their URL-key lists are not worth a class. It is the
 * DECISION about what is printable that has to live in one place.
 */
class WC_AI_Storefront_Meta_Image {

	/**
	 * Drop an og:image the printer would escape away to nothing.
	 *
	 * print_meta() runs esc_url() on og:image, and esc_url() returns '' for a
	 * disallowed protocol (`javascript:`, `data:`). But the emptiness check in
	 * print_og_and_twitter() tests the RAW value, so the page shipped
	 * og:image="" while build_twitter_tags() still saw a URL and asked for
	 * summary_large_image: the large-card-with-no-image state this whole
	 * change exists to remove (#684 review).
	 *
	 * Decided here, once, rather than in the resolver, because it has to hold
	 * for every source — including the product path, which never goes through
	 * archive_image(), and the wc_ai_storefront_og_tags filter, which can
	 * replace og:image after any resolver has finished.
	 *
	 * @param array<string,string> $og Open Graph map.
	 * @return array<string,string> The same map, minus an unprintable image
	 *                              and the properties that describe it.
	 */
	public static function drop_unprintable_image( array $og ): array {
		if ( ! isset( $og['og:image'] ) || '' === $og['og:image'] ) {
			return $og;
		}

		if ( '' !== self::usable_url( (string) $og['og:image'] ) ) {
			return $og;
		}

		WC_AI_Storefront_Logger::debug(
			'Open Graph: og:image "%s" does not survive esc_url(). Dropping it, and the card with it.',
			(string) $og['og:image']
		);
		unset( $og['og:image'], $og['og:image:width'], $og['og:image:height'], $og['og:image:alt'] );

		return $og;
	}

	/**
	 * The URL unchanged, or '' when escaping would empty it.
	 *
	 * Returns the RAW url on success, not the escaped form: print_meta()
	 * escapes again at output, and storing the escaped value would
	 * double-encode it.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function usable_url( string $url ): string {
		if ( '' === $url || ! function_exists( 'esc_url' ) ) {
			return $url;
		}

		return '' === esc_url( $url ) ? '' : $url;
	}

	/**
	 * An attachment's full-size URL and dimensions.
	 *
	 * @param int $attachment_id Attachment ID; 0 or less yields an empty URL.
	 * @return array{url:string,width:int,height:int}
	 */
	public static function attachment_image( int $attachment_id ): array {
		if ( $attachment_id <= 0 || ! function_exists( 'wp_get_attachment_image_src' ) ) {
			return self::no_image();
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		// `'' === (string)` rather than `empty()`: this file documents twice
		// (#679, verified live) that the two disagree on '0', and a URL is the
		// wrong place to start trusting empty().
		if ( ! is_array( $src ) || ! isset( $src[0] ) || '' === (string) $src[0] ) {
			return self::no_image();
		}

		return array(
			'url'    => (string) $src[0],
			'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
			'height' => isset( $src[2] ) ? (int) $src[2] : 0,
		);
	}

	/**
	 * The "no image" result every resolver step returns when it comes up empty.
	 *
	 * @return array{url:string,width:int,height:int}
	 */
	public static function no_image(): array {
		return array(
			'url'    => '',
			'width'  => 0,
			'height' => 0,
		);
	}
}
