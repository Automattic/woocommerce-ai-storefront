<?php
/**
 * AI Syndication: WC Product → UCP Product Translator
 *
 * Converts a WooCommerce Store API product response (as returned by
 * `rest_do_request( GET /wc/store/v1/products/{id} )`) into a UCP
 * product object conforming to:
 *
 *     source/schemas/shopping/types/product.json
 *
 * Required UCP fields: id, title, description, price_range, variants.
 * Prices are integer minor units (read directly from WC's
 * `prices.price` — no float math). Variants is minItems 1; simple
 * products emit one default variant, variable products emit one
 * per WC variation.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates WooCommerce Store API product responses into UCP product objects.
 */
class WC_AI_Syndication_UCP_Product_Translator {

	/**
	 * UCP product ID prefix. `prod_{wc_id}` distinguishes product IDs
	 * from variant IDs (`var_{wc_variation_id}`) at the UCP layer,
	 * letting `/catalog/lookup` handle both types through one route.
	 */
	const PRODUCT_ID_PREFIX = 'prod_';

	/**
	 * Translate a single WC Store API product response into a UCP product.
	 *
	 * Variant expansion is caller-driven. The translator stays a pure
	 * data-shape function and does NOT dispatch `rest_do_request` itself:
	 *
	 *   - Simple products: caller passes `$wc_variations = []` (or omits
	 *     it). The translator emits one synthesized default variant to
	 *     satisfy the UCP schema's `variants` minItems:1 requirement.
	 *   - Variable products: caller pre-fetches each WC variation via
	 *     `rest_do_request( GET /wc/store/v1/products/{variation_id} )`
	 *     and passes the decoded responses as `$wc_variations`. The
	 *     translator emits one real UCP variant per entry.
	 *
	 * Why pre-fetched rather than self-dispatching: keeps the translator
	 * pure + hermetically testable without stubbing WP's REST
	 * infrastructure. Orchestration (detect type, fetch variations)
	 * lives in the REST controller's search/lookup handlers.
	 *
	 * @param array<string, mixed>             $wc_product    Decoded Store API product response.
	 * @param array<int, array<string, mixed>> $wc_variations Optional pre-fetched Store API
	 *                                                        variation responses. Empty = fall
	 *                                                        back to synthesized default.
	 * @return array<string, mixed>                           UCP product shape.
	 */
	public static function translate( array $wc_product, array $wc_variations = [] ): array {
		$id = (int) ( $wc_product['id'] ?? 0 );

		$product = [
			'id'          => self::PRODUCT_ID_PREFIX . $id,
			'title'       => $wc_product['name'] ?? '',
			'description' => self::extract_description( $wc_product ),
			'price_range' => self::extract_price_range( $wc_product ),
			'variants'    => self::extract_variants( $wc_product, $wc_variations ),
		];

		// Optional fields — only emit when source has a non-empty value.
		if ( ! empty( $wc_product['slug'] ) ) {
			$product['handle'] = $wc_product['slug'];
		}

		if ( ! empty( $wc_product['permalink'] ) ) {
			$product['url'] = $wc_product['permalink'];
		}

		// Categories + tags are both emitted into `categories` with
		// distinct `taxonomy` values ("merchant" for WC categories,
		// "tag" for WC tags). UCP's categories shape accepts this
		// polymorphism — a single flat list indexed by taxonomy lets
		// agents filter on either axis without a separate schema.
		$taxonomies = self::extract_taxonomies( $wc_product );
		if ( ! empty( $taxonomies ) ) {
			$product['categories'] = $taxonomies;
		}

		if ( ! empty( $wc_product['images'] ) ) {
			$product['media'] = self::extract_media( $wc_product['images'] );
		}

		// Product-level attributes — "Material: Cotton", "Fit: Slim",
		// etc. For variable products WC surfaces the same attributes
		// on each variation (as `options`, handled in the variant
		// translator), but simple products store attributes here too.
		// Emitting them at product level lets agents filter "show me
		// only cotton items" without walking variants.
		$attributes = self::extract_attributes( $wc_product );
		if ( ! empty( $attributes ) ) {
			$product['attributes'] = $attributes;
		}

		// Ratings + review count land in the
		// `com.woocommerce.ai_syndication` extension rather than at
		// the canonical product level because UCP's shopping schema
		// doesn't have a standardized ratings field yet (the UCP spec
		// is intentionally minimal; ratings are a "nice to have" that
		// vendors wire through extension capabilities). The same
		// extension namespace carries store_context + attribution at
		// the manifest level (see WC_AI_Syndication_UCP::build_*);
		// per-product data like ratings is the product-scoped
		// counterpart. Emitted only when reviews exist.
		$ratings = self::extract_ratings( $wc_product );
		if ( null !== $ratings ) {
			$product['extensions'] = [
				'com.woocommerce.ai_syndication' => [
					'ratings' => $ratings,
				],
			];
		}

		return $product;
	}

	/**
	 * Extract the variants array for a product.
	 *
	 * UCP schema requires `variants` with `minItems: 1`. Two paths:
	 *
	 *   - Caller supplied `$wc_variations` (variable product, pre-fetched
	 *     by the REST controller): emit one real UCP variant per entry,
	 *     translated via `WC_AI_Syndication_UCP_Variant_Translator::translate()`.
	 *     Variant IDs are `var_{variation_id}` (no `_default` suffix — that
	 *     marker is reserved for synthesized placeholders).
	 *   - `$wc_variations` is empty (simple product, or variable product
	 *     where caller did not pre-fetch): emit one synthesized default
	 *     variant via `WC_AI_Syndication_UCP_Variant_Translator::synthesize_default()`
	 *     so the minItems:1 constraint is still satisfied. This is the safety-
	 *     net path — callers emitting a variable product without variations
	 *     get a defensive fallback rather than a schema-violating empty
	 *     array, but the `_default` suffix signals the shape is degraded.
	 *
	 * @param array<string, mixed>             $wc_product
	 * @param array<int, array<string, mixed>> $wc_variations Pre-fetched variation responses.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_variants( array $wc_product, array $wc_variations ): array {
		if ( ! empty( $wc_variations ) ) {
			$variants = [];
			foreach ( $wc_variations as $wc_variation ) {
				$variants[] = WC_AI_Syndication_UCP_Variant_Translator::translate( $wc_variation );
			}
			return $variants;
		}

		return [
			WC_AI_Syndication_UCP_Variant_Translator::synthesize_default( $wc_product ),
		];
	}

	/**
	 * Extract a UCP price_range object from the WC response.
	 *
	 * Variable products: use `prices.price_range.min_amount` /
	 * `max_amount` if present (WC supplies this when the product has
	 * variations at different prices).
	 *
	 * Simple products (or variable products with all variations at the
	 * same price): use `prices.price`, with min == max.
	 *
	 * All values are integer minor units (no float math needed —
	 * WC already computed them correctly).
	 *
	 * @param array<string, mixed> $wc_product
	 */
	private static function extract_price_range( array $wc_product ): array {
		$prices   = $wc_product['prices'] ?? [];
		$currency = $prices['currency_code'] ?? 'USD';

		$range = $prices['price_range'] ?? null;
		if ( is_array( $range ) && ! empty( $range['min_amount'] ) ) {
			return [
				'min' => [
					'amount'   => (int) $range['min_amount'],
					'currency' => $currency,
				],
				'max' => [
					'amount'   => (int) ( $range['max_amount'] ?? $range['min_amount'] ),
					'currency' => $currency,
				],
			];
		}

		$amount = (int) ( $prices['price'] ?? 0 );
		return [
			'min' => [
				'amount'   => $amount,
				'currency' => $currency,
			],
			'max' => [
				'amount'   => $amount,
				'currency' => $currency,
			],
		];
	}

	/**
	 * Map WC image objects to UCP media entries.
	 *
	 * UCP media shape: `{type, url, alt_text}`. v1 handles image
	 * media only; future expansion could add video/3D model types
	 * from WC gallery attachments.
	 *
	 * @param array<int, array<string, mixed>> $wc_images
	 * @return array<int, array<string, string>>
	 */
	private static function extract_media( array $wc_images ): array {
		$result = [];
		foreach ( $wc_images as $image ) {
			if ( empty( $image['src'] ) ) {
				continue;
			}
			$media = [
				'type' => 'image',
				'url'  => $image['src'],
			];
			if ( ! empty( $image['alt'] ) ) {
				$media['alt_text'] = $image['alt'];
			}
			$result[] = $media;
		}
		return $result;
	}

	/**
	 * Extract a UCP description object from the WC response.
	 *
	 * Emits BOTH `plain` and `html` when the source short_description
	 * has structure worth preserving. The `plain` form strips all
	 * tags for agents that want flat text; `html` preserves lists,
	 * emphasis, line breaks for agents that can render. UCP's
	 * description object accepts either or both.
	 *
	 * Falls back to only `plain` when short_description is empty or
	 * is already plain text (no tags detected). Avoids emitting a
	 * redundant `html` key that carries identical content.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{plain: string, html?: string}
	 */
	private static function extract_description( array $wc_product ): array {
		$raw = (string) ( $wc_product['short_description'] ?? '' );
		// wp_strip_all_tags() rationale documented on the companion
		// method in UCP Variant Translator (::extract_description).
		$stripped = wp_strip_all_tags( $raw );
		$plain    = html_entity_decode( $stripped, ENT_QUOTES, 'UTF-8' );

		$description = [ 'plain' => $plain ];

		// Only include HTML if the source actually contains markup.
		// Compare `$stripped` to `trim( $raw )` (both before entity
		// decoding) — if they match, there were no tags.
		//
		// Why trim: wp_strip_all_tags() trims leading/trailing
		// whitespace as a side-effect, so comparing against an
		// un-trimmed `$raw` would false-positive on plain text with
		// trailing newlines.
		// Why pre-decode: comparing against the entity-decoded `$plain`
		// would false-positive on plain text like "Fish &amp; Chips"
		// (entities != markup).
		if ( '' !== $raw && trim( $raw ) !== $stripped ) {
			$description['html'] = $raw;
		}

		return $description;
	}

	/**
	 * Extract the combined taxonomies list (categories + tags).
	 *
	 * Both come back as UCP category entries (`{value, taxonomy}`)
	 * with distinct `taxonomy` slugs: "merchant" for WC categories
	 * (our pre-existing convention), "tag" for WC tags. Tags are an
	 * optional cross-cutting discovery signal ("summer",
	 * "eco-friendly") that's orthogonal to categorical hierarchy;
	 * merging them into one `categories` list keeps the UCP product
	 * schema flat while letting agents filter/match on either axis.
	 *
	 * Tags emit only when present; merchants who don't use tags
	 * pay zero extra payload.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array<int, array{value: string, taxonomy: string}>
	 */
	private static function extract_taxonomies( array $wc_product ): array {
		$result = [];

		if ( ! empty( $wc_product['categories'] ) && is_array( $wc_product['categories'] ) ) {
			foreach ( $wc_product['categories'] as $cat ) {
				if ( is_array( $cat ) && ! empty( $cat['name'] ) ) {
					$result[] = [
						'value'    => (string) $cat['name'],
						'taxonomy' => 'merchant',
					];
				}
			}
		}

		if ( ! empty( $wc_product['tags'] ) && is_array( $wc_product['tags'] ) ) {
			foreach ( $wc_product['tags'] as $tag ) {
				if ( is_array( $tag ) && ! empty( $tag['name'] ) ) {
					$result[] = [
						'value'    => (string) $tag['name'],
						'taxonomy' => 'tag',
					];
				}
			}
		}

		return $result;
	}

	/**
	 * Extract product-level attributes for discovery filtering.
	 *
	 * WC Store API returns attributes as a flat array on the product
	 * response. Each entry has `name` (display label, e.g. "Material"),
	 * `taxonomy` (slug, e.g. "pa_material"), and `terms` (the values
	 * the merchant has tagged this product with — a product might
	 * be both "Cotton" and "Recycled").
	 *
	 * Shape: `[{ name, values: [string] }]`. Only attributes with at
	 * least one term are emitted; empty-term entries produce no
	 * payload. Attributes that ARE variation axes (identified by WC's
	 * `has_variations: true` flag) are SKIPPED at the product level —
	 * those belong on variant `options`, not here. Non-variation
	 * attributes (things that apply uniformly to all variants, or to
	 * simple products) land here.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array<int, array{name: string, values: array<int, string>}>
	 */
	private static function extract_attributes( array $wc_product ): array {
		$attributes = $wc_product['attributes'] ?? [];
		if ( ! is_array( $attributes ) ) {
			return [];
		}

		$result = [];
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			// Skip variation-defining attributes; those belong on
			// variant `options`, not at product level.
			if ( ! empty( $attribute['has_variations'] ) ) {
				continue;
			}

			$name  = (string) ( $attribute['name'] ?? '' );
			$terms = $attribute['terms'] ?? [];
			if ( '' === $name || ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$values = [];
			foreach ( $terms as $term ) {
				if ( is_array( $term ) && ! empty( $term['name'] ) ) {
					$values[] = (string) $term['name'];
				}
			}
			if ( empty( $values ) ) {
				continue;
			}

			$result[] = [
				'name'   => $name,
				'values' => $values,
			];
		}

		return $result;
	}

	/**
	 * Extract ratings for the extension capability.
	 *
	 * Returns a compact `{average, count}` shape when the merchant
	 * has at least one review, otherwise null (caller skips the
	 * extension payload). Average rating is a string in the Store
	 * API response (e.g. "4.67"); we coerce to float for agents that
	 * do numeric comparisons. Review count is already an int.
	 *
	 * Agents recommending products benefit enormously from rating
	 * data — "customers rate it 4.7 / 2,384 reviews" is dominant
	 * social proof that converts. The data is already computed by
	 * WC; we just forward it.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{average: float, count: int}|null
	 */
	private static function extract_ratings( array $wc_product ): ?array {
		$count = isset( $wc_product['review_count'] )
			? (int) $wc_product['review_count']
			: 0;

		if ( $count <= 0 ) {
			return null;
		}

		return [
			'average' => isset( $wc_product['average_rating'] )
				? (float) $wc_product['average_rating']
				: 0.0,
			'count'   => $count,
		];
	}
}
