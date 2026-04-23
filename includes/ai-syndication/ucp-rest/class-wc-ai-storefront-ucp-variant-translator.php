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
 * Required UCP fields: id, title, description, list_price.
 * (`list_price` replaced the 1.x `price` field in 2.0.0 — it carries
 * the current/cart amount from WC's `prices.price`; on-sale variants
 * additionally emit `compare_at_price` from `prices.regular_price`
 * for strikethrough rendering.) Variants also carry `options`
 * (selected option values like "Color: Blue, Size: Large"),
 * `availability`, and optional `sku`, `barcodes`, `media`.
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
	 * @param array<string, mixed> $wc_variation Decoded Store API response.
	 * @return array<string, mixed>              UCP variant shape.
	 */
	public static function translate( array $wc_variation ): array {
		$id      = (int) ( $wc_variation['id'] ?? 0 );
		$variant = [
			'id'          => self::VARIANT_ID_PREFIX . $id,
			'title'       => self::extract_title( $wc_variation ),
			'description' => self::extract_description( $wc_variation ),
			// `list_price` is the UCP core name for the current
			// purchasable price — sourced from WC's `prices.price`
			// (the active amount that lands in the cart, which on a
			// sale variant is the discounted price, not the regular
			// one). Previously emitted as `price`; renamed in 2.0.0
			// for spec parity. On-sale variants additionally emit
			// `compare_at_price` as the pre-discount amount from
			// WC's `prices.regular_price`, letting agents render
			// strike-through pricing ("was $X, now $Y").
			'list_price'  => self::extract_price( $wc_variation ),
		];

		// Structured options — the {attribute, value} pairs that
		// distinguish this variant from siblings (e.g. "Color: Blue,
		// Size: M"). Already implied by `title` for human display, but
		// agents that want to filter or match by attribute need them
		// structured. UCP v2026-04-08 variant schema carries
		// `options` exactly for this.
		$options = self::extract_options( $wc_variation );
		if ( ! empty( $options ) ) {
			$variant['options'] = $options;
		}

		// Sale pricing — agents showing "was $X, now $Y" need
		// compare_at_price alongside the canonical `list_price`. WC
		// marks this via the `on_sale` flag plus `prices.regular_price`
		// (higher) vs `prices.price` (the active/sale value). Only
		// emit when actually on sale so non-sale variants stay
		// clean. `compare_at_price` is non-spec but widely understood
		// (Shopify convention) — retained for agents rendering
		// strike-through pricing; spec-strict consumers ignore it.
		if ( ! empty( $wc_variation['on_sale'] ) ) {
			$compare_at = self::extract_compare_at_price( $wc_variation );
			if ( null !== $compare_at ) {
				$variant['compare_at_price'] = $compare_at;
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
			// `list_price` (renamed from `price` in 2.0.0) — see the
			// translate() method above for the naming rationale.
			'list_price'  => self::extract_price( $wc_product ),
		];

		// Sale pricing carries through the simple-product path too
		// (a discounted simple product has on_sale + regular_price
		// just like a variation).
		if ( ! empty( $wc_product['on_sale'] ) ) {
			$compare_at = self::extract_compare_at_price( $wc_product );
			if ( null !== $compare_at ) {
				$variant['compare_at_price'] = $compare_at;
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
	 * For a real WC variation, the `name` field is typically the parent
	 * product name; the meaningful title comes from the variation's
	 * attributes (e.g. "Blue / Large"). Fall back to `name` when
	 * attributes are absent.
	 *
	 * @param array<string, mixed> $wc_variation
	 */
	private static function extract_title( array $wc_variation ): string {
		$attributes = $wc_variation['attributes'] ?? [];
		$values     = [];

		foreach ( $attributes as $attribute ) {
			if ( ! empty( $attribute['value'] ) ) {
				$values[] = $attribute['value'];
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
	 * Extract the compare-at price (the pre-sale price), or null when
	 * the variation isn't on sale or the regular_price isn't higher.
	 *
	 * WC sale convention: `prices.price` is the currently-charged
	 * amount (sale price when on_sale is true, regular price otherwise).
	 * `prices.regular_price` is the "was" value. When on_sale is true
	 * AND regular > price, we emit `compare_at_price` so agents can
	 * render "was $X, now $Y" or compute a savings percent.
	 *
	 * Defensive against data oddities: if regular_price somehow equals
	 * or is less than price while on_sale is flagged (inconsistent
	 * state from third-party plugins), we return null rather than
	 * emit a nonsensical "was $10, now $10" comparison.
	 *
	 * @param array<string, mixed> $wc
	 * @return array{amount: int, currency: string}|null
	 */
	private static function extract_compare_at_price( array $wc ): ?array {
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
	 * WC Store API returns each variation's attributes as an array of
	 * objects shaped `{ name, value, taxonomy }`. UCP's `options`
	 * field expects `[{ attribute, value }]` — one entry per defining
	 * attribute with the human-readable label and the selected value.
	 *
	 * We use `name` (the attribute label) rather than `taxonomy` (the
	 * slug like `pa_color`) because agents display this to buyers —
	 * "Color: Blue" is merchant-readable, "pa_color: Blue" is not.
	 * Empty-value entries are skipped to match the title-extraction
	 * behavior.
	 *
	 * @param array<string, mixed> $wc_variation
	 * @return array<int, array{attribute: string, value: string}>
	 */
	private static function extract_options( array $wc_variation ): array {
		$attributes = $wc_variation['attributes'] ?? [];
		$options    = [];

		if ( ! is_array( $attributes ) ) {
			return $options;
		}

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$value = $attribute['value'] ?? '';
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
				'value'     => (string) $value,
			];
		}

		return $options;
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
