<?php
/**
 * AI Syndication: WC Variation → UCP Variant Translator
 *
 * Converts a WooCommerce variation (either a product response for a
 * variable-product variation ID, or a synthesized default variant for
 * a simple product) into a UCP variant object conforming to:
 *
 *     source/schemas/shopping/types/variant.json
 *
 * Required UCP fields: id, title, description, price.
 * (`price` carries the current/cart amount from WC's `prices.price`;
 * on-sale variants additionally emit the optional `list_price` from
 * `prices.regular_price` for strikethrough rendering.) Variants also
 * carry `options` (selected option values like "Color: Blue,
 * Size: Large"), `availability`, and optional `sku`, `barcodes`,
 * `media`.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates WC variations into UCP variant objects.
 */
class WC_AI_Storefront_UCP_Variant_Translator {

	/**
	 * UCP variant ID prefix. Distinguishes variant IDs from product
	 * IDs at the UCP layer.
	 */
	const VARIANT_ID_PREFIX = 'var_';

	/**
	 * Suffix for the synthesized default variant emitted for simple
	 * products. WC simple products don't have variations, but UCP
	 * requires at least one variant per product (schema minItems 1),
	 * so we emit one representing the product itself.
	 */
	const DEFAULT_VARIANT_SUFFIX = '_default';

	/**
	 * Translate a WC variation product response into a UCP variant.
	 *
	 * Expects `$wc_variation` to be the JSON-decoded Store API response
	 * for a single variation (typically fetched via
	 * `rest_do_request(GET /wc/store/v1/products/{variation_id})`).
	 *
	 * @param array<string, mixed>    $wc_variation           Decoded Store API response.
	 * @param array<int, string>|null $parent_attribute_names Names of the parent product's
	 *                                                        attributes (e.g. ["Color", "Size"]).
	 *                                                        Used to parse the variation's
	 *                                                        formatted `variation` string when
	 *                                                        the Store API leaves `attributes[]`
	 *                                                        empty (which it does for every
	 *                                                        variable-product variation as of WC
	 *                                                        9.x). Without these, comma-in-value
	 *                                                        cases (e.g. "Color: Red, White")
	 *                                                        cannot be split unambiguously.
	 * @return array<string, mixed>                           UCP variant shape.
	 */
	public static function translate( array $wc_variation, ?array $parent_attribute_names = null ): array {
		$id = (int) ( $wc_variation['id'] ?? 0 );

		// Parse the formatted `variation` string at most once per variant
		// and pass the result into both `extract_title()` and
		// `extract_options()`. The anchor-aware regex path
		// (`split_variation_segments()`) builds an alternation pattern
		// from `$parent_attribute_names` — doing that twice per variant
		// on every catalog/search response (up to ~20 products × N
		// variations) adds up.
		//
		// Parse whenever `variation` is non-empty — NOT just when
		// `attributes[]` is empty. A malformed payload could populate
		// `attributes[]` with a list whose values all filter out
		// (`null`/`false`/`""`) and leave both helpers with zero
		// usable pairs from the array path. In that case they fall
		// through to `$pre_parsed_pairs` to recover. Earlier drafts
		// gated this on "`attributes[]` is missing/empty", which broke
		// the lazy fallback the helpers used to do themselves.
		$pre_parsed_pairs = null;
		$variation_string = $wc_variation['variation'] ?? '';
		if ( is_string( $variation_string ) && '' !== trim( $variation_string ) ) {
			$pre_parsed_pairs = self::parse_variation_string(
				$variation_string,
				$parent_attribute_names
			);
		}

		$variant = [
			'id'          => self::VARIANT_ID_PREFIX . $id,
			'title'       => self::extract_title( $wc_variation, $pre_parsed_pairs ),
			'description' => self::extract_description( $wc_variation ),
			// `price` is the UCP-required field for the current
			// purchasable amount — sourced from WC's `prices.price`,
			// which is the active value (sale price when on_sale, else
			// regular). Strike-through display lives in the optional
			// `list_price` field per `variant.json` (see Commit 2 of
			// the 0.12.0 compliance pass for that rename).
			'price'       => self::extract_price( $wc_variation ),
		];

		// Structured options — the {attribute, value} pairs that
		// distinguish this variant from siblings (e.g. "Color: Blue,
		// Size: M"). Already implied by `title` for human display, but
		// agents that want to filter or match by attribute need them
		// structured. UCP v2026-04-08 variant schema carries
		// `options` exactly for this.
		$options = self::extract_options( $wc_variation, $pre_parsed_pairs );
		if ( ! empty( $options ) ) {
			$variant['options'] = $options;
		}

		// Sale pricing — agents showing "was $X, now $Y" need the
		// pre-discount amount alongside the active `price`. WC marks
		// this via the `on_sale` flag plus `prices.regular_price`
		// (higher) vs `prices.price` (the active/sale value). Spec
		// names the strikethrough field `list_price` (variant.json
		// optional). Only emit when actually on sale so non-sale
		// variants stay clean.
		if ( ! empty( $wc_variation['on_sale'] ) ) {
			$list_price = self::extract_list_price( $wc_variation );
			if ( null !== $list_price ) {
				$variant['list_price'] = $list_price;
			}
		}

		// Optional fields. Only emit when present in WC source.
		if ( ! empty( $wc_variation['sku'] ) ) {
			$variant['sku'] = $wc_variation['sku'];
		}

		// Barcodes (GTIN/UPC/EAN/MPN). Sourced from the Store API
		// extension we register in `WC_AI_Storefront_Store_Api_Extension`
		// (WC core doesn't expose `global_unique_id` on the Store API
		// product schema yet — see the WC enhancement request). The
		// extension surfaces it under `extensions.{namespace}.barcodes`
		// as an array of `{type, value}` pairs matching the UCP
		// variant.barcodes shape.
		$barcodes = self::extract_barcodes( $wc_variation );
		if ( ! empty( $barcodes ) ) {
			$variant['barcodes'] = $barcodes;
		}

		$variant['availability'] = self::extract_availability( $wc_variation );

		// Per-variant media — WC lets merchants set a different image
		// per variation (the red shirt gets the red photo, the blue
		// shirt the blue one). Store API returns those under the
		// variation's own `images[]` array. Emitting them at variant
		// level lets agents present the right visual for each option;
		// when a variation doesn't have its own image we simply omit
		// the field and the product-level media carries the default.
		$media = self::extract_media( $wc_variation );
		if ( ! empty( $media ) ) {
			$variant['media'] = $media;
		}

		// Weight + dimensions — shipping-aware agents need these to
		// estimate delivery costs or filter by physical attributes
		// (fits-in-standard-flatrate, oversize surcharge, etc.).
		// WC Store API emits them natively under `weight` (string
		// scalar in merchant-configured unit) and `dimensions`
		// (object with length/width/height).
		//
		// Emitted under `metadata.shipping` (2.0.0+). The canonical
		// UCP variant shape doesn't have a dedicated shipping block —
		// weight/dimensions live under `metadata` as vendor-extension
		// data, per spec. Previously (1.x) we emitted a top-level
		// `shipping_attributes` key which was non-spec; agents parsing
		// by shape expected it under `metadata`. Only emit when the
		// merchant has filled in real values — empty fields would
		// produce misleading zeros or fabricated defaults.
		$shipping = self::extract_shipping_attributes( $wc_variation );
		if ( ! empty( $shipping ) ) {
			// Nothing else writes into variant-level metadata today, so
			// a straight assignment is safe. If a future field also
			// writes under `metadata`, switch to merge-style to preserve
			// sibling keys.
			$variant['metadata'] = [
				'shipping' => $shipping,
			];
		}

		return $variant;
	}

	/**
	 * Synthesize a default variant for a simple (non-variable) product.
	 *
	 * Simple WC products don't have variations, but UCP's schema requires
	 * every product to emit `variants[]` with minItems 1. We satisfy that
	 * by emitting one variant representing the product itself: same price,
	 * same availability, id suffixed with `_default` so it's distinguishable
	 * from a real variation.
	 *
	 * @param array<string, mixed> $wc_product Decoded Store API response.
	 * @return array<string, mixed>            UCP variant shape.
	 */
	public static function synthesize_default( array $wc_product ): array {
		$id = (int) ( $wc_product['id'] ?? 0 );

		$variant = [
			'id'          => self::VARIANT_ID_PREFIX . $id . self::DEFAULT_VARIANT_SUFFIX,
			'title'       => $wc_product['name'] ?? '',
			'description' => [ 'plain' => '' ],
			// `price` — UCP-required active price. See translate() above.
			'price'       => self::extract_price( $wc_product ),
		];

		// Sale pricing carries through the simple-product path too
		// (a discounted simple product has on_sale + regular_price
		// just like a variation).
		if ( ! empty( $wc_product['on_sale'] ) ) {
			$list_price = self::extract_list_price( $wc_product );
			if ( null !== $list_price ) {
				$variant['list_price'] = $list_price;
			}
		}

		if ( ! empty( $wc_product['sku'] ) ) {
			$variant['sku'] = $wc_product['sku'];
		}

		// Simple products carry the same Store API extensions.{namespace}
		// payload the variations do, so `barcodes` routes through the
		// same helper.
		$barcodes = self::extract_barcodes( $wc_product );
		if ( ! empty( $barcodes ) ) {
			$variant['barcodes'] = $barcodes;
		}

		$variant['availability'] = self::extract_availability( $wc_product );

		// Simple products carry the same weight/dimensions shape the
		// Store API uses for variations, so shipping data routes
		// through the same helper on the synthesized-default path.
		// Emitted under `metadata.shipping` (2.0.0+ — see translate()
		// above for the relocation rationale). Keeps shipping-aware
		// agents unaware of the simple-vs-variable distinction.
		$shipping = self::extract_shipping_attributes( $wc_product );
		if ( ! empty( $shipping ) ) {
			// Straight assignment — no other metadata siblings yet.
			// Same invariant as the translate() path above; keep them
			// in sync if future fields add to `metadata`.
			$variant['metadata'] = [
				'shipping' => $shipping,
			];
		}

		return $variant;
	}

	/**
	 * Extract a human-readable variant title from the WC response.
	 *
	 * Two paths in source-of-truth order:
	 *
	 *   1. `attributes[]` — the structured array shape `[{name, value}]`.
	 *      Used by simple-product fixtures and any future Store API
	 *      version that populates this. Iterates and joins values with
	 *      " / " (e.g. "Blue / Large").
	 *   2. `variation` — a formatted string the Store API uses for
	 *      every variable-product variation as of WC 9.x: an empty
	 *      `attributes[]` plus a string like "Color: Tan, Size: 9".
	 *      We parse it via `parse_variation_string()` and join the
	 *      values with " / ".
	 *
	 * Pre-issue-#347 this only consulted `attributes[]`, so live
	 * variations (which always carry empty `attributes[]`) all fell
	 * through to the parent product `name` — making every variant in a
	 * 22-variation set indistinguishable. The `variation` parse path
	 * fixes that.
	 *
	 * The `variation` string is parsed once per variant by `translate()`
	 * and passed in via `$pre_parsed_pairs` so this helper and
	 * `extract_options()` share the result instead of each rebuilding
	 * the anchor regex.
	 *
	 * @param array<string, mixed>                                            $wc_variation
	 * @param array<int, array{attribute: string, value: string}>|null        $pre_parsed_pairs
	 *        Pairs already parsed from the variation string by `translate()`,
	 *        or null if the array path is the live one (and the parse never
	 *        ran).
	 */
	private static function extract_title(
		array $wc_variation,
		?array $pre_parsed_pairs = null
	): string {
		$attributes = $wc_variation['attributes'] ?? [];
		$values     = [];

		if ( is_array( $attributes ) ) {
			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) ) {
					continue;
				}
				// Cast first, then check for empty string. This handles
				// the full set of bad inputs uniformly (null, false,
				// missing key all coerce to "") while preserving the
				// literal string "0" — `empty()` would drop "0", and a
				// strict `'' === $value` without the cast would let
				// `false` (cast: "") leak through as an empty title
				// fragment. Mirrors `extract_options()` so both helpers
				// agree on what counts as a value.
				$value = (string) ( $attribute['value'] ?? '' );
				if ( '' === $value ) {
					continue;
				}
				$values[] = $value;
			}
		}

		if ( empty( $values ) && is_array( $pre_parsed_pairs ) ) {
			foreach ( $pre_parsed_pairs as $pair ) {
				$values[] = $pair['value'];
			}
		}

		if ( ! empty( $values ) ) {
			return implode( ' / ', $values );
		}

		return $wc_variation['name'] ?? '';
	}

	/**
	 * Map WC variation image objects to UCP media entries.
	 *
	 * UCP media shape: `{type, url, alt_text?}` — `alt_text` is
	 * optional and omitted when the source image has no alt attribute
	 * (avoids emitting an empty-string key that agents would have to
	 * filter on their side). Mirrors the product translator's
	 * `extract_media` (image-only for v1; video/3D model types stay
	 * reserved for future expansion). Kept local to the variant
	 * translator rather than shared with the product translator so
	 * the two classes have independent call sites and can evolve
	 * their shape rules independently — variant-specific images
	 * often have different cropping/alt-text conventions.
	 *
	 * @param array<string, mixed> $wc_variation
	 * @return array<int, array{type: string, url: string, alt_text?: string}>
	 */
	private static function extract_media( array $wc_variation ): array {
		$images = $wc_variation['images'] ?? [];
		if ( ! is_array( $images ) ) {
			return [];
		}
		$result = [];
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) || empty( $image['src'] ) ) {
				continue;
			}
			$media = [
				'type' => 'image',
				'url'  => (string) $image['src'],
			];
			if ( ! empty( $image['alt'] ) ) {
				$media['alt_text'] = (string) $image['alt'];
			}
			$result[] = $media;
		}
		return $result;
	}

	/**
	 * Extract shipping-relevant physical attributes (weight + dimensions).
	 *
	 * WC Store API emits `weight` as a string scalar in the merchant's
	 * configured weight unit (e.g. `"0.5"` kg) and `dimensions` as an
	 * object with string `length` / `width` / `height` in the
	 * merchant's configured dimension unit (e.g. `"10"` cm). We pass
	 * the values through as strings because the unit lives separately
	 * — converting to a canonical unit would require store-configuration
	 * awareness we don't want to duplicate here, and the store context
	 * already advertises the unit conventions on the manifest.
	 *
	 * Emit shape:
	 *   { weight: "0.5", dimensions: { length: "10", width: "5", height: "2" } }
	 *
	 * When none of the fields are set, return an empty array so the
	 * caller can omit `shipping_attributes` entirely — better than
	 * emitting a half-empty object agents have to filter through.
	 *
	 * @param array<string, mixed> $wc_variation
	 * @return array<string, mixed>
	 */
	private static function extract_shipping_attributes( array $wc_variation ): array {
		$result = [];

		$weight = $wc_variation['weight'] ?? '';
		if ( is_string( $weight ) && '' !== trim( $weight ) ) {
			$result['weight'] = $weight;
		}

		$dimensions = $wc_variation['dimensions'] ?? [];
		$dim_result = [];
		if ( is_array( $dimensions ) ) {
			foreach ( [ 'length', 'width', 'height' ] as $key ) {
				$value = $dimensions[ $key ] ?? '';
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$dim_result[ $key ] = $value;
				}
			}
		}
		if ( ! empty( $dim_result ) ) {
			$result['dimensions'] = $dim_result;
		}

		return $result;
	}

	/**
	 * Extract a UCP description object from the WC response.
	 *
	 * @param array<string, mixed> $wc
	 * @return array{plain: string}
	 */
	private static function extract_description( array $wc ): array {
		$raw = $wc['short_description'] ?? '';
		// wp_strip_all_tags() over native strip_tags(): the WordPress
		// helper also strips the CONTENT of <script> and <style> tags
		// (not just the tags themselves) and trims surrounding whitespace.
		// Both are safer defaults for content that might originate from a
		// rich-text editor. PHPCS flags native strip_tags in plugin code
		// for exactly this reason.
		$plain = html_entity_decode(
			wp_strip_all_tags( (string) $raw ),
			ENT_QUOTES,
			'UTF-8'
		);
		return [ 'plain' => $plain ];
	}

	/**
	 * Extract a UCP price object from the WC response.
	 *
	 * Critical: WC Store API returns `prices.price` as a STRING in
	 * integer minor units (e.g. "12000" = $120.00 for USD). No float
	 * conversion, no * 100 math. Just cast to int. Works for JPY (0
	 * decimals), BHD (3 decimals), USD/EUR (2 decimals) uniformly
	 * because WC already computed correctly.
	 *
	 * @param array<string, mixed> $wc
	 * @return array{amount: int, currency: string}
	 */
	private static function extract_price( array $wc ): array {
		$prices = $wc['prices'] ?? [];
		return [
			'amount'   => (int) ( $prices['price'] ?? 0 ),
			'currency' => $prices['currency_code'] ?? 'USD',
		];
	}

	/**
	 * Extract the strikethrough `list_price` (pre-discount amount), or
	 * null when the variation isn't on sale or the regular_price isn't
	 * higher.
	 *
	 * WC sale convention: `prices.price` is the currently-charged
	 * amount (sale price when on_sale is true, regular price otherwise).
	 * `prices.regular_price` is the "was" value. When on_sale is true
	 * AND regular > price, we emit `list_price` so agents can render
	 * "was $X, now $Y" or compute a savings percent.
	 *
	 * Defensive against data oddities: if regular_price somehow equals
	 * or is less than price while on_sale is flagged (inconsistent
	 * state from third-party plugins), we return null rather than
	 * emit a nonsensical "was $10, now $10" comparison.
	 *
	 * @param array<string, mixed> $wc
	 * @return array{amount: int, currency: string}|null
	 */
	private static function extract_list_price( array $wc ): ?array {
		$prices  = $wc['prices'] ?? [];
		$regular = isset( $prices['regular_price'] ) ? (int) $prices['regular_price'] : 0;
		$current = (int) ( $prices['price'] ?? 0 );

		if ( $regular <= 0 || $regular <= $current ) {
			return null;
		}

		return [
			'amount'   => $regular,
			'currency' => $prices['currency_code'] ?? 'USD',
		];
	}

	/**
	 * Extract the structured options list from WC variation attributes.
	 *
	 * Two source paths in priority order:
	 *
	 *   1. `attributes[]` (array of `{name, value, taxonomy}`) — the
	 *      historical shape; populated by simple-product fixtures and
	 *      any future Store API version that fills it in for variations.
	 *   2. `variation` (formatted string like "Color: Tan, Size: 9") —
	 *      the actual shape WC's Store API returns for variable-product
	 *      variations as of WC 9.x. Pre-issue-#347 this path didn't
	 *      exist, so every real-world variation emitted an empty
	 *      `options` field, leaving agents unable to disambiguate
	 *      siblings. We now parse the string via
	 *      `parse_variation_string()` and emit the same UCP shape.
	 *
	 * Both paths use the human-readable label (e.g. "Color") rather than
	 * the taxonomy slug ("pa_color") because agents display this to
	 * buyers. Empty-value or empty-label entries are skipped.
	 *
	 * The `variation` string is parsed once per variant by `translate()`
	 * and passed in via `$pre_parsed_pairs` so this helper and
	 * `extract_title()` share the result instead of each rebuilding the
	 * anchor regex.
	 *
	 * @param array<string, mixed>                                            $wc_variation
	 * @param array<int, array{attribute: string, value: string}>|null        $pre_parsed_pairs
	 *        Pairs already parsed from the variation string by `translate()`,
	 *        or null if the array path is the live one.
	 * @return array<int, array{attribute: string, value: string}>
	 */
	private static function extract_options(
		array $wc_variation,
		?array $pre_parsed_pairs = null
	): array {
		$attributes = $wc_variation['attributes'] ?? [];
		$options    = [];

		if ( is_array( $attributes ) ) {
			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) ) {
					continue;
				}
				// Cast first, then check for empty string — same gate
				// as `extract_title()`. `false`/null/missing all coerce
				// to "" and get skipped uniformly; the literal string
				// "0" survives and becomes a legitimate option value.
				// Without the upfront cast a `false` value would slip
				// past `'' === $value` (false !== '') and emit an empty
				// option `{value: ""}`.
				$value = (string) ( $attribute['value'] ?? '' );
				if ( '' === $value ) {
					continue;
				}
				// Skip entries missing a human-readable label. Emitting
				// `{attribute: "", value: "Blue"}` conveys no option axis
				// to the agent — worse than dropping the entry because it
				// pollutes the options list with an unlabeled row that
				// can't be filtered or displayed meaningfully. Parallel to
				// the empty-value skip above.
				$label = (string) ( $attribute['name'] ?? '' );
				if ( '' === $label ) {
					continue;
				}
				$options[] = [
					'attribute' => $label,
					'value'     => $value,
				];
			}
		}

		if ( ! empty( $options ) ) {
			return $options;
		}

		if ( ! is_array( $pre_parsed_pairs ) ) {
			return [];
		}

		foreach ( $pre_parsed_pairs as $pair ) {
			$options[] = [
				'attribute' => $pair['attribute'],
				'value'     => $pair['value'],
			];
		}

		return $options;
	}

	/**
	 * Parse WC's `variation` formatted string into structured pairs.
	 *
	 * WC's Store API emits the active option set for a variable-product
	 * variation as a single human-readable string under the `variation`
	 * key — e.g. `"Color: Tan, Size: 9"`. Pairs are separated by `", "`
	 * and each pair's name/value is separated by `": "`. The structured
	 * `attributes[]` array exists in the schema but is always empty for
	 * variations as of WC 9.x — which is why pre-#347 callers reading
	 * only `attributes[]` saw 22 indistinguishable variants per product.
	 *
	 * Comma-in-value disambiguation: if a merchant defines a value that
	 * itself contains `, ` (e.g. `"Red, White"`), the naive `, ` split
	 * over-counts pairs. To handle this we accept an optional
	 * `$known_attribute_names` list (the parent product's attribute
	 * names from `$wc_product['attributes'][i]['name']`). When present,
	 * we re-tokenize so that `, ` is only treated as a pair separator
	 * when the segment that follows starts with `<known_name>: `.
	 * Without that anchor we fall back to the naive split, which is
	 * correct for the overwhelming majority of attribute values
	 * (single-word labels like "Tan", "9", "Large"). Worst case in the
	 * fallback path: a comma-bearing value gets split across two
	 * options entries — agents see slightly degraded data, but every
	 * variant still emits *some* structured options rather than none,
	 * and the title is still distinct.
	 *
	 * @param string                  $variation_string Formatted "Name: Value, Name: Value" string.
	 * @param array<int, string>|null $known_attribute_names Optional anchor list — the parent's
	 *                                                       attribute names. Enables correct
	 *                                                       splitting when a value contains `, `.
	 * @return array<int, array{attribute: string, value: string}>
	 */
	private static function parse_variation_string(
		string $variation_string,
		?array $known_attribute_names = null
	): array {
		$variation_string = trim( $variation_string );
		if ( '' === $variation_string ) {
			return [];
		}

		// Anchor-aware split: walk the string, treating `, ` as a pair
		// boundary only when followed by `<known_name>: `. Falls back to
		// naive `, ` split when no anchor list is supplied or the list
		// is empty (e.g. controller didn't have parent attributes
		// handy).
		$segments = self::split_variation_segments( $variation_string, $known_attribute_names );

		$pairs = [];
		foreach ( $segments as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment ) {
				continue;
			}
			$colon_pos = strpos( $segment, ':' );
			if ( false === $colon_pos ) {
				// Malformed segment without a `Name: Value` shape — skip
				// rather than emit a half-formed entry agents can't use.
				continue;
			}
			$name  = trim( substr( $segment, 0, $colon_pos ) );
			$value = trim( substr( $segment, $colon_pos + 1 ) );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$pairs[] = [
				'attribute' => $name,
				'value'     => $value,
			];
		}

		return $pairs;
	}

	/**
	 * Split a variation string into segments using the anchor list when
	 * available. Helper for `parse_variation_string()`.
	 *
	 * @param string                  $variation_string
	 * @param array<int, string>|null $known_attribute_names
	 * @return array<int, string>
	 */
	private static function split_variation_segments(
		string $variation_string,
		?array $known_attribute_names
	): array {
		// Filter the anchor list to non-empty strings — handles `null`
		// entries from a malformed parent payload without aborting the
		// whole parse.
		$anchors = [];
		if ( is_array( $known_attribute_names ) ) {
			foreach ( $known_attribute_names as $name ) {
				if ( is_string( $name ) && '' !== trim( $name ) ) {
					$anchors[] = trim( $name );
				}
			}
		}

		if ( empty( $anchors ) ) {
			return explode( ', ', $variation_string );
		}

		// Build a regex that matches `, ` only when followed by one of
		// the known attribute names + `: `. Anchors are quoted to escape
		// regex metacharacters in attribute labels (e.g. an attribute
		// literally named "Size (cm)").
		$quoted   = array_map(
			static fn( $a ) => preg_quote( $a, '/' ),
			$anchors
		);
		$pattern  = '/, (?=(?:' . implode( '|', $quoted ) . '): )/';
		$segments = preg_split( $pattern, $variation_string );

		// preg_split returns false on regex error. Defensive fallback
		// to naive split keeps the parser from silently emitting empty
		// options on a pathological anchor (e.g. pattern length blew
		// past a PCRE limit). Same shape as the no-anchor branch above.
		if ( false === $segments ) {
			return explode( ', ', $variation_string );
		}

		return $segments;
	}

	/**
	 * Extract the UCP availability object from the WC response.
	 *
	 * UCP variant.availability has a required `available: bool` plus
	 * optional `quantity: int`. We emit `quantity` when the Store API
	 * response carries `low_stock_remaining` — which WC populates only
	 * when the merchant configured a low-stock threshold AND the
	 * variation is below it. Otherwise no quantity is emitted, which
	 * correctly signals "available but exact count unknown" rather
	 * than misleadingly emitting 0.
	 *
	 * @param array<string, mixed> $wc
	 * @return array{available: bool, quantity?: int}
	 */
	private static function extract_availability( array $wc ): array {
		$availability = [
			'available' => (bool) ( $wc['is_in_stock'] ?? true ),
		];

		if ( isset( $wc['low_stock_remaining'] ) && is_numeric( $wc['low_stock_remaining'] ) ) {
			$quantity = (int) $wc['low_stock_remaining'];
			if ( $quantity > 0 ) {
				$availability['quantity'] = $quantity;
			}
		}

		return $availability;
	}

	/**
	 * Extract barcode entries from the Store API extension payload.
	 *
	 * WC core doesn't expose `global_unique_id` on the Store API
	 * product schema yet. Our plugin registers an extension that
	 * surfaces it (plus any legacy third-party barcode keys) under
	 * `extensions.{namespace}.barcodes` as an array of `{type, value}`
	 * objects. This method copies them through verbatim — the
	 * extension is responsible for emitting the barcode `type` values
	 * (`gtin8`, `gtin12`, `gtin13`, `gtin14`, or `other`), not the
	 * translator.
	 *
	 * Returns an empty array when no barcodes are present, so the
	 * caller's `! empty()` check cleanly omits the `barcodes` key
	 * from the UCP payload for products without identifiers.
	 *
	 * @param array<string, mixed> $wc
	 * @return array<int, array{type: string, value: string}>
	 */
	private static function extract_barcodes( array $wc ): array {
		$extensions = $wc['extensions'] ?? [];
		if ( ! is_array( $extensions ) ) {
			return [];
		}

		$namespace = WC_AI_Storefront_Store_Api_Extension::NAMESPACE;
		$entry     = $extensions[ $namespace ] ?? [];
		if ( ! is_array( $entry ) ) {
			return [];
		}

		$barcodes = $entry['barcodes'] ?? [];
		if ( ! is_array( $barcodes ) ) {
			return [];
		}

		$result = [];
		foreach ( $barcodes as $barcode ) {
			if ( ! is_array( $barcode ) ) {
				continue;
			}
			$type  = (string) ( $barcode['type'] ?? '' );
			$value = (string) ( $barcode['value'] ?? '' );
			if ( '' === $type || '' === $value ) {
				continue;
			}
			$result[] = [
				'type'  => $type,
				'value' => $value,
			];
		}
		return $result;
	}
}
