<?php
/**
 * Text and locale helpers shared by every metadata emitter.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turning merchant-authored content into something publishable.
 *
 * Extracted from WC_AI_Storefront_Meta_Tags when a second emitter needed the
 * same rules (#680). The cleaning in particular is not obvious code and has
 * been corrected twice against live behaviour — entity decoding, shortcode
 * remnants, Unicode whitespace, invalid UTF-8, and the difference between
 * "non-empty" and "readable" (#682 review). Two copies of that would drift,
 * and the copy that drifted would be the one nobody had measured.
 */
class WC_AI_Storefront_Meta_Text {

	/**
	 * Strip shortcodes + HTML and collapse whitespace.
	 */
	public static function clean_text( string $raw ): string {
		// Three passes that have to happen before the ASCII whitespace
		// collapse below, each closing a way for non-prose to read as
		// content (#682 review).
		//
		// 1. Decode entities. The block editor stores its own empty
		//    paragraph as the literal `&nbsp;`, six ASCII bytes that survive
		//    tag-stripping and whitespace-collapsing and even carry letters.
		$raw = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 2. Remove shortcode-shaped tokens. strip_shortcodes() intersects
		//    against the shortcodes registered AT THAT MOMENT, so a tag left
		//    behind by a deactivated plugin passes through verbatim and
		//    `[some_slider id="3"]` ships as the SERP snippet.
		$raw = (string) preg_replace( '/\[\/?[a-zA-Z0-9_-]+(?:[^\]]*)?\]/', ' ', $raw );

		// 3. Fold Unicode whitespace. `\s` without the `u` flag does not
		//    match U+00A0, U+200B or U+FEFF, and neither does trim()'s
		//    default charlist, so they survive every other step.
		//
		//    Null-safe, and that is load-bearing rather than defensive. A
		//    `/u` pattern returns NULL when the SUBJECT is not valid UTF-8,
		//    and `(string) null` is '' — so one mis-encoded byte anywhere in
		//    a merchant's Shop page silently discarded the whole description
		//    and fell through to the generated fallback. Mojibake from an
		//    old latin-1 import is the ordinary way to get there (#682
		//    review). Keeping the unfolded text is strictly better than
		//    losing it: the ASCII collapse below still runs.
		$folded = preg_replace( '/[\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $raw );
		$raw    = is_string( $folded ) ? $folded : $raw;
		$raw    = strip_shortcodes( $raw );
		$raw    = wp_strip_all_tags( $raw );
		return trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
	}

	/**
	 * Truncate to a soft max on a word boundary, appending an ellipsis.
	 */
	public static function truncate( string $text, int $max ): string {
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
	 * Whether cleaned text is worth publishing as a description.
	 *
	 * Not the same question as "is it non-empty", and the difference ships.
	 * clean_text() strips tags and collapses ASCII whitespace, but a
	 * non-breaking space is neither: `&nbsp;` survives as six literal bytes,
	 * a raw U+00A0 as two, and `trim()`'s default charlist does not include
	 * either. So the block editor's own empty paragraph — open the Shop page,
	 * press Enter, leave — cleans to `&nbsp;` and reads as a usable
	 * description.
	 *
	 * strip_shortcodes() has the same shape of hole: it intersects against
	 * the shortcodes registered AT THAT MOMENT, so a tag left behind by a
	 * deactivated plugin passes through verbatim and `[some_slider id="3"]`
	 * ships as the SERP snippet.
	 *
	 * Both cases cost more than the tag they produce, because the candidate
	 * chain stops at the first "usable" entry: a stray non-breaking space on
	 * the Shop page suppressed the merchant's own tagline AND the generated
	 * fallback beneath it (#682 review).
	 *
	 * The test is one letter or digit, in any script.
	 *
	 * @param string $text Cleaned candidate text.
	 */
	public static function is_readable_prose( string $text ): bool {
		if ( '' === $text ) {
			return false;
		}

		// preg_match() returns false, not 0, on a subject that is not valid
		// UTF-8. Treat that as readable rather than as junk: the text came
		// from the merchant, and discarding it would be the same silent loss
		// the fold above guards against.
		$match = preg_match( '/[\p{L}\p{N}]/u', $text );

		return false === $match || 1 === $match;
	}

	/**
	 * The current locale as an Open Graph `language_TERRITORY` value.
	 *
	 * WordPress locales like `de_DE_formal` carry a variant suffix Open Graph
	 * does not accept, so we keep only the language and territory segments.
	 * Defaults to `en_US` when the locale is unavailable.
	 */
	public static function og_locale(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		if ( '' === $locale ) {
			return 'en_US';
		}
		// Normalize a BCP-47 hyphen form (e.g. a filtered `pt-BR`) to Open
		// Graph's underscore form before stripping any WP variant suffix.
		$parts = explode( '_', str_replace( '-', '_', $locale ) );
		return isset( $parts[1] ) ? $parts[0] . '_' . $parts[1] : $parts[0];
	}
}
