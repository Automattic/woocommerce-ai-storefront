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
	 * @param array<string, mixed>|null $seller               Seller block to attach as
	 *                                                        `variant.seller` per UCP
	 *                                                        `variant.json` (the spec defines
	 *                                                        seller inline on variants only —
	 *                                                        no `product.seller` field).
	 *                                                        Same value passed for every
	 *                                                        variant in a single-merchant
	 *                                                        store; controller computes
	 *                                                        once and threads through.
	 *                                                        Omit when null/empty.
	 * @param array<string, array{taxonomy: string, slugs: array<string, string>}>|null $term_slug_map
	 *                                                        Per-attribute term slug lookup,
	 *                                                        nested as
	 *                                                        `[axis_label => {taxonomy, slugs:
	 *                                                        [value_label => slug]}]`
	 *                                                        (e.g. `["Color" => ["taxonomy" =>
	 *                                                        "pa_color", "slugs" => ["Black" =>
	 *                                                        "black"]]]`).
	 *                                                        Pre-built by the product translator
	 *                                                        from the parent's
	 *                                                        `attributes[].terms[].{name,slug}`.
	 *                                                        Used to emit
	 *                                                        `selected_option.id` (UCP 2026-04-08
	 *                                                        optional field) for stable cross-locale
	 *                                                        variant matching. Format of `id`:
	 *                                                        `<taxonomy>:<slug>`. Omit `id` per-pair
	 *                                                        when the slug isn't found in the map.
	 * @return array<string, mixed>                           UCP variant shape.
	 */
	public static function translate(
		array $wc_variation,
		?array $parent_attribute_names = null,
		?array $seller = null,
		?array $term_slug_map = null
	): array {
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

		$variant = array(
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
		);

		// Structured options — the {name, label} pairs that
		// distinguish this variant from siblings (e.g. "Color: Blue,
		// Size: M"). Already implied by `title` for human display, but
		// agents that want to filter or match by attribute need them
		// structured. UCP v2026-04-08 `selected_option.json` carries
		// `options` exactly for this.
		$options = self::extract_options( $wc_variation, $pre_parsed_pairs, $term_slug_map );
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
			$variant['metadata'] = array(
				'shipping' => $shipping,
			);
		}

		// Seller — UCP `variant.json` defines `seller` inline on
		// variants only (optional, no required subfields). Same value
		// shared across every variant in a single-merchant store; the
		// controller computes once via `build_seller()` and threads
		// the same value to each variant translation.
		if ( null !== $seller && ! empty( $seller ) ) {
			$variant['seller'] = $seller;
		}

		return $variant;
	}

	/**
	 * Synthesize a default variant for any product where
	 * `extract_variants()` doesn't have real variations to expand.
	 * Two callers reach this path:
	 *
	 *   1. **Non-variable products** (simple, bundle, grouped) — by
	 *      definition no `has_variations: true` axes, so no real
	 *      variations exist to expand.
	 *   2. **Variable products without pre-fetched variations** — the
	 *      safety-net path documented on `extract_variants()`. Real
	 *      variations exist in WC, but the caller didn't pre-fetch
	 *      them via `rest_do_request`, so we emit a single placeholder
	 *      to satisfy UCP's `variants[] minItems: 1` rather than a
	 *      schema-violating empty array.
	 *
	 * In both cases we emit one variant representing the product
	 * itself: same price, same availability, id suffixed with
	 * `_default` so it's distinguishable from a real variation.
	 *
	 * No `options[]` is emitted on the synthesized variant. (The
	 * `variant.options` array — whose elements conform to UCP
	 * `selected_option.json` — locks in a specific concrete combination
	 * of variant axes for a buyer.) The reason the synthesized variant
	 * has no concrete combination to lock in differs by caller:
	 *
	 *   - Non-variable: there's no selection axis at all. UCP
	 *     `product_option.json` characterizes options by example as
	 *     size, color, or material — variant-selection axes a buyer
	 *     chooses between, not descriptive properties. The schema.org
	 *     reserved Color/Size/Pattern/Material descriptive attributes
	 *     live in the parent product's `metadata.attributes` instead.
	 *   - Variable + no-prefetch: selection axes exist on the parent
	 *     `options[]`, but the synthesized fallback doesn't represent
	 *     any one concrete combination — emitting a `selected_option`
	 *     would be a fabrication.
	 *
	 * @param array<string, mixed>      $wc_product Decoded Store API response.
	 * @param array<string, mixed>|null $seller     Seller block to attach as
	 *                                              `variant.seller`. See `translate()`.
	 * @return array<string, mixed>                 UCP variant shape.
	 */
	public static function synthesize_default(
		array $wc_product,
		?array $seller = null
	): array {
		$id = (int) ( $wc_product['id'] ?? 0 );

		$variant = array(
			'id'          => self::VARIANT_ID_PREFIX . $id . self::DEFAULT_VARIANT_SUFFIX,
			'title'       => self::decode( (string) ( $wc_product['name'] ?? '' ) ),
			// Carry the parent's short_description through to the
			// synthesized variant (#375). For a UCP agent that drills
			// into a variant ID directly, the variant entity IS what
			// they see — emitting an empty description when the parent
			// has useful copy made the variant look uninformative even
			// though the data existed one level up. Uses the same
			// `extract_description()` helper as the real-variation
			// path, so a missing short_description still degrades
			// gracefully to `description.plain = ""`.
			'description' => self::extract_description( $wc_product ),
			// `price` — UCP-required active price. See translate() above.
			'price'       => self::extract_price( $wc_product ),
		);

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
			$variant['metadata'] = array(
				'shipping' => $shipping,
			);
		}

		// Seller — same routing as translate() above. Per-variant
		// emission per UCP `variant.json`; the synthesized default
		// satisfies the schema's minItems-1 requirement and carries
		// seller alongside any real variant in the same response.
		// External products re-point both seller and url; see the helper.
		$variant = self::apply_external_seller( $variant, $wc_product, $seller );

		return $variant;
	}

	/**
	 * Name the real seller for `type: external`, without diverting the click.
	 *
	 * `build_seller()` is store-wide — one value threaded to every variant of
	 * every product. For an external / affiliate product that value states
	 * something false: it names this store as the seller of an item this store
	 * does not sell. WooCommerce marks these `is_purchasable: false` and
	 * renders a "Buy on ..." button pointing wherever the merchant chose, which
	 * the Store API surfaces as `add_to_cart.url` (#657).
	 *
	 * The seller name becomes the destination HOST. A real business name is not
	 * derivable from the URL, and the alternatives are worse: keeping the store
	 * name asserts something untrue, and parsing WooCommerce's button text
	 * ("Buy on the WordPress swag store!") is merchant-authored prose that
	 * breaks the moment someone writes "Buy now".
	 *
	 * `availability` is deliberately untouched. An external product IS
	 * obtainable — just elsewhere — and `availability.available` is defined by
	 * the spec as "whether this can be obtained", so `true` is correct.
	 *
	 * @param array      $variant    Variant under construction.
	 * @param array      $wc_product Store API product payload.
	 * @param array|null $seller     Store-wide seller from build_seller().
	 * @return array Variant, with seller re-pointed when external and a destination exists.
	 */
	private static function apply_external_seller( array $variant, array $wc_product, ?array $seller ): array {
		if ( 'external' !== ( $wc_product['type'] ?? '' ) ) {
			if ( null !== $seller && ! empty( $seller ) ) {
				$variant['seller'] = $seller;
			}
			return $variant;
		}

		$external_url = trim( (string) ( $wc_product['add_to_cart']['url'] ?? '' ) );
		$host         = '' !== $external_url ? wp_parse_url( $external_url, PHP_URL_HOST ) : null;

		// No destination configured, or one we cannot parse: fall back to the
		// store-wide seller rather than emitting a seller with no name. The
		// product translator makes the matching call for `url`.
		if ( ! is_string( $host ) || '' === $host ) {
			if ( null !== $seller && ! empty( $seller ) ) {
				$variant['seller'] = $seller;
			}
			return $variant;
		}

		// No `url` override here, for the same reason the product translator
		// keeps its permalink: diverting the shopper past the merchant's own
		// page strips the referral click-through the external product exists
		// to earn. The destination travels in `seller.links` instead, so an
		// agent can name and reach the real seller without routing around the
		// merchant.
		$variant['seller'] = array(
			'name'  => $host,
			'links' => array(
				array(
					// `link.json` requires {type, url}. No well-known value
					// covers "where this is actually sold", so a descriptive
					// type is used rather than forcing an ill-fitting one.
					'type' => 'seller_product_page',
					'url'  => $external_url,
				),
			),
		);

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
		$attributes = $wc_variation['attributes'] ?? array();
		$values     = array();

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
				$value = self::decode( (string) ( $attribute['value'] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				$values[] = $value;
			}
		}

		if ( empty( $values ) && is_array( $pre_parsed_pairs ) ) {
			foreach ( $pre_parsed_pairs as $pair ) {
				$values[] = self::decode( $pair['value'] );
			}
		}

		if ( ! empty( $values ) ) {
			return implode( ' / ', $values );
		}

		return self::decode( (string) ( $wc_variation['name'] ?? '' ) );
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
		$images = $wc_variation['images'] ?? array();
		if ( ! is_array( $images ) ) {
			return array();
		}
		$result = array();
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) || empty( $image['src'] ) ) {
				continue;
			}
			$media = array(
				'type' => 'image',
				'url'  => (string) $image['src'],
			);
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
		$result = array();

		$weight = $wc_variation['weight'] ?? '';
		if ( is_string( $weight ) && '' !== trim( $weight ) ) {
			$result['weight'] = $weight;
		}

		$dimensions = $wc_variation['dimensions'] ?? array();
		$dim_result = array();
		if ( is_array( $dimensions ) ) {
			foreach ( array( 'length', 'width', 'height' ) as $key ) {
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
		return array( 'plain' => $plain );
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
		$prices = $wc['prices'] ?? array();
		return array(
			'amount'   => (int) ( $prices['price'] ?? 0 ),
			'currency' => $prices['currency_code'] ?? 'USD',
		);
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
		$prices  = $wc['prices'] ?? array();
		$regular = isset( $prices['regular_price'] ) ? (int) $prices['regular_price'] : 0;
		$current = (int) ( $prices['price'] ?? 0 );

		if ( $regular <= 0 || $regular <= $current ) {
			return null;
		}

		return array(
			'amount'   => $regular,
			'currency' => $prices['currency_code'] ?? 'USD',
		);
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
	 *        or null if the array path is the live one. Parser uses the
	 *        internal `{attribute, value}` shape; this method renames to
	 *        the spec's `{name, label}` shape on emission.
	 * @param array<string, array{taxonomy: string, slugs: array<string, string>}>|null $term_slug_map
	 *        Per-axis term slug lookup, nested as
	 *        `[axis_label => {taxonomy, slugs: [value_label => slug]}]`.
	 *        When supplied, emit `selected_option.id` as
	 *        `<taxonomy>:<slug>` for matching axis/value pairs. Omit `id`
	 *        per-pair when no match is found (case-sensitive lookup —
	 *        labels come from the same WC source on both sides).
	 * @return array<int, array{name: string, label: string, id?: string}>
	 */
	private static function extract_options(
		array $wc_variation,
		?array $pre_parsed_pairs = null,
		?array $term_slug_map = null
	): array {
		$attributes = $wc_variation['attributes'] ?? array();
		$options    = array();

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
				$value = self::decode( (string) ( $attribute['value'] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				// Skip entries missing a human-readable axis name. Emitting
				// `{name: "", label: "Blue"}` conveys no option axis
				// to the agent — worse than dropping the entry because it
				// pollutes the options list with an unlabeled row that
				// can't be filtered or displayed meaningfully. Parallel to
				// the empty-value skip above.
				$label = self::decode( (string) ( $attribute['name'] ?? '' ) );
				if ( '' === $label ) {
					continue;
				}
				// Array path: try to source the slug from the variation's
				// own `taxonomy` field first (when the WC Store API
				// populates it), then fall back to the threaded
				// $term_slug_map for the parsed-string path.
				$option = array(
					'name'  => $label,
					'label' => $value,
				);
				$id     = self::lookup_option_value_id(
					$label,
					$value,
					(string) ( $attribute['taxonomy'] ?? '' ),
					$term_slug_map
				);
				if ( null !== $id ) {
					$option['id'] = $id;
				}
				$options[] = $option;
			}
		}

		if ( ! empty( $options ) ) {
			return $options;
		}

		if ( ! is_array( $pre_parsed_pairs ) ) {
			return array();
		}

		foreach ( $pre_parsed_pairs as $pair ) {
			$name   = self::decode( $pair['attribute'] );
			$label  = self::decode( $pair['value'] );
			$option = array(
				'name'  => $name,
				'label' => $label,
			);
			// String path: the parsed pair carries no taxonomy info —
			// rely entirely on the threaded $term_slug_map to lookup
			// both the taxonomy slug and the term slug.
			$id = self::lookup_option_value_id( $name, $label, '', $term_slug_map );
			if ( null !== $id ) {
				$option['id'] = $id;
			}
			$options[] = $option;
		}

		return $options;
	}

	/**
	 * Build the optional `selected_option.id` (UCP 2026-04-08).
	 *
	 * `id` format: `<taxonomy>:<slug>` (e.g. `pa_color:black`). Two
	 * resolution strategies in priority order:
	 *
	 *   1. **Variation-supplied taxonomy** (array path): the WC Store API
	 *      sometimes carries `taxonomy` directly on each variation
	 *      attribute entry. When present and prefixed `pa_`, lookup the
	 *      term slug from `$term_slug_map[$axis_label]['slugs'][$value_label]`
	 *      and combine with the variation's taxonomy.
	 *   2. **Map-supplied taxonomy** (string path or array path with
	 *      missing taxonomy): read the axis's taxonomy from
	 *      `$term_slug_map[$axis_label]['taxonomy']` so the string-parse
	 *      path (which has no per-attribute taxonomy field) can still
	 *      emit `id`.
	 *
	 * Returns `null` whenever any required piece is missing (no map,
	 * no axis entry, no value entry, non-pa_ taxonomy). The optional
	 * `id` is omitted from the emitted pair in that case.
	 *
	 * @param string $axis_label  Axis name (e.g. "Color").
	 * @param string $value_label Value name (e.g. "Black").
	 * @param string $taxonomy    Taxonomy slug from variation attribute
	 *                            when available; "" otherwise.
	 * @param array<string, array{taxonomy: string, slugs: array<string, string>}>|null $term_slug_map
	 *                            Per-axis lookup map.
	 */
	private static function lookup_option_value_id(
		string $axis_label,
		string $value_label,
		string $taxonomy,
		?array $term_slug_map
	): ?string {
		if ( ! is_array( $term_slug_map ) ) {
			return null;
		}
		$axis_entry = $term_slug_map[ $axis_label ] ?? null;
		if ( ! is_array( $axis_entry ) ) {
			return null;
		}
		$slugs = $axis_entry['slugs'] ?? array();
		if ( ! is_array( $slugs ) ) {
			return null;
		}
		$slug = $slugs[ $value_label ] ?? '';
		if ( ! is_string( $slug ) || '' === $slug ) {
			return null;
		}
		// Resolve the taxonomy half. Variation-supplied wins; otherwise
		// fall back to the map's `taxonomy` field so the string-parse
		// path can still emit `id` even though it has no per-attribute
		// `taxonomy` field.
		if ( '' === $taxonomy ) {
			$taxonomy = (string) ( $axis_entry['taxonomy'] ?? '' );
		}
		if ( '' === $taxonomy || ! str_starts_with( $taxonomy, 'pa_' ) ) {
			return null;
		}
		return $taxonomy . ':' . $slug;
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
	public static function parse_variation_string(
		string $variation_string,
		?array $known_attribute_names = null
	): array {
		$variation_string = trim( $variation_string );
		if ( '' === $variation_string ) {
			return array();
		}

		// Anchor-aware split: walk the string, treating `, ` as a pair
		// boundary only when followed by `<known_name>: `. Falls back to
		// naive `, ` split when no anchor list is supplied or the list
		// is empty (e.g. controller didn't have parent attributes
		// handy).
		$segments = self::split_variation_segments( $variation_string, $known_attribute_names );

		$pairs = array();
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
			$pairs[] = array(
				'attribute' => $name,
				'value'     => $value,
			);
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
		$anchors = array();
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
		// Two WooCommerce signals answer "can this be had", and they can
		// disagree. `is_in_stock` is a boolean that aggregates optimistically
		// on a variable parent — it reads `true` even when every variation is
		// unpurchasable — while `stock_availability.class` carries what the
		// shopper is actually shown on the product page. Observed live: a
		// variable parent reporting `is_in_stock: true` alongside
		// `stock_availability.class: 'out-of-stock'`, syndicated to agents as
		// available while the storefront told shoppers otherwise (#658).
		// When the two conflict, the shopper-facing one is the honest answer.
		//
		// Deliberately NOT gating on `is_purchasable`: external / affiliate
		// products read `false` there because they cannot enter THIS store's
		// cart, but the UCP spec defines `available` as "whether this can be
		// obtained" — and they can be, from the seller they link out to
		// (#657). Gating on it would hide real inventory.
		//
		// A missing `stock_availability` (older WC, or a payload that omits
		// it) leaves `$stock_class` empty, so the check falls through to
		// `is_in_stock` rather than failing the product outright.
		// `is_array()` before the nested offset. The Store API builds this
		// field as `(object) array( … )` (ProductSchema), and
		// `isset( $obj['class'] )` on a stdClass is a fatal, not a false —
		// unlike a string/int/bool/null, which all return false harmlessly.
		// `normalize_store_api_data()` converts those objects upstream, and
		// its own docblock cites this exact fatal, so this is defence in
		// depth against a payload reaching us by some other route rather
		// than a live bug. Matches the guarding style in extract_barcodes().
		$stock_class = '';
		if ( isset( $wc['stock_availability'] ) && is_array( $wc['stock_availability'] )
			&& isset( $wc['stock_availability']['class'] ) && is_string( $wc['stock_availability']['class'] ) ) {
			$stock_class = $wc['stock_availability']['class'];
		}

		// Both defaults fail closed. `?? true` previously advertised a product
		// as buyable when the payload carried no stock evidence at all.
		$availability = array(
			'available' => 'out-of-stock' !== $stock_class && (bool) ( $wc['is_in_stock'] ?? false ),
		);

		// Gated on `available`. Before the out-of-stock-class check above,
		// reaching `available: false` required `is_in_stock: false`, which
		// forces `low_stock_remaining` to 0 and made the `> 0` guard drop the
		// quantity on its own. The new path keeps `is_in_stock: true`, so a
		// positive count can survive alongside an unavailable verdict —
		// `{available: false, quantity: 2}` tells an agent two contradictory
		// things at once.
		if ( $availability['available'] && isset( $wc['low_stock_remaining'] ) && is_numeric( $wc['low_stock_remaining'] ) ) {
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
		$extensions = $wc['extensions'] ?? array();
		if ( ! is_array( $extensions ) ) {
			return array();
		}

		$namespace = WC_AI_Storefront_Store_Api_Extension::NAMESPACE;
		$entry     = $extensions[ $namespace ] ?? array();
		if ( ! is_array( $entry ) ) {
			return array();
		}

		$barcodes = $entry['barcodes'] ?? array();
		if ( ! is_array( $barcodes ) ) {
			return array();
		}

		$result = array();
		foreach ( $barcodes as $barcode ) {
			if ( ! is_array( $barcode ) ) {
				continue;
			}
			$type  = (string) ( $barcode['type'] ?? '' );
			$value = (string) ( $barcode['value'] ?? '' );
			if ( '' === $type || '' === $value ) {
				continue;
			}
			$result[] = array(
				'type'  => $type,
				'value' => $value,
			);
		}
		return $result;
	}

	/**
	 * Decode HTML entities from Store API string fields.
	 *
	 * The WC Store API returns `name` values with HTML entities intact
	 * (e.g. `Shirt &#8211; Green`). UCP JSON must emit plain Unicode.
	 *
	 * Tags are stripped after decoding so that encoded markup
	 * (e.g. `&lt;strong&gt;`) cannot reintroduce HTML elements in the
	 * output.
	 *
	 * @param string $value Raw string from the Store API.
	 * @return string       Plain-text string: entities decoded, tags stripped.
	 */
	private static function decode( string $value ): string {
		return wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
