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

		if ( ! empty( $wc_product['categories'] ) ) {
			$product['categories'] = self::extract_categories(
				$wc_product['categories']
			);
		}

		if ( ! empty( $wc_product['images'] ) ) {
			$product['media'] = self::extract_media( $wc_product['images'] );
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
	 * Map WC category objects to UCP category entries with merchant
	 * taxonomy tagging.
	 *
	 * UCP `category` type has `value` (the category name/ID) and
	 * `taxonomy` (which taxonomy the value belongs to). Standard
	 * taxonomies include `google_product_category`, `shopify`, and
	 * `merchant` for business-specific values. Our WC categories are
	 * merchant-defined, so we tag them `merchant`.
	 *
	 * @param array<int, array<string, mixed>> $wc_categories
	 * @return array<int, array{value: string, taxonomy: string}>
	 */
	private static function extract_categories( array $wc_categories ): array {
		$result = [];
		foreach ( $wc_categories as $cat ) {
			if ( ! empty( $cat['name'] ) ) {
				$result[] = [
					'value'    => $cat['name'],
					'taxonomy' => 'merchant',
				];
			}
		}
		return $result;
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
	 * @param array<string, mixed> $wc_product
	 * @return array{plain: string}
	 */
	private static function extract_description( array $wc_product ): array {
		$raw = $wc_product['short_description'] ?? '';
		// wp_strip_all_tags() rationale documented on the companion
		// method in UCP Variant Translator (::extract_description).
		$plain = html_entity_decode(
			wp_strip_all_tags( (string) $raw ),
			ENT_QUOTES,
			'UTF-8'
		);
		return [ 'plain' => $plain ];
	}
}
