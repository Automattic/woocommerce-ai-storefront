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
	 * @param array<string, mixed>|null        $seller        Optional seller block to copy onto
	 *                                                        every product. Same for every product
	 *                                                        in a request, so the controller
	 *                                                        computes it once and passes it in —
	 *                                                        keeps the translator WP-unaware.
	 * @return array<string, mixed>                           UCP product shape.
	 */
	public static function translate( array $wc_product, array $wc_variations = [], ?array $seller = null ): array {
		$id = (int) ( $wc_product['id'] ?? 0 );

		$product = [
			'id'          => self::PRODUCT_ID_PREFIX . $id,
			'title'       => $wc_product['name'] ?? '',
			'description' => self::extract_description( $wc_product ),
			'price_range' => self::extract_price_range( $wc_product ),
			'variants'    => self::extract_variants( $wc_product, $wc_variations ),
		];

		// Spec metadata fields — additive, non-breaking.
		//
		// `status` is a fixed literal "published": our catalog handlers
		// only emit products returned by the Store API, which already
		// filters to published (we don't syndicate drafts/private).
		// Emitting the key anyway communicates the posture to agents
		// so "why didn't I find product X?" is traceable back to
		// "its status isn't in your result set".
		$product['status'] = 'published';

		// `published_at` / `updated_at` — ISO 8601 timestamps from the
		// Store API. Older WC versions emit a `{raw, format_to_edit}`
		// object; 9.5+ emits the ISO string directly. Coerce both.
		$timestamps = self::extract_timestamps( $wc_product );
		if ( isset( $timestamps['published_at'] ) ) {
			$product['published_at'] = $timestamps['published_at'];
		}
		if ( isset( $timestamps['updated_at'] ) ) {
			$product['updated_at'] = $timestamps['updated_at'];
		}

		// Seller — controller-computed once per request (same for every
		// product in a single-merchant store). Spec-expected even for
		// single-merchant plugins; omitting it fails strict validators.
		if ( null !== $seller && ! empty( $seller ) ) {
			$product['seller'] = $seller;
		}

		// Optional fields — only emit when source has a non-empty value.
		if ( ! empty( $wc_product['slug'] ) ) {
			$product['handle'] = $wc_product['slug'];
		}

		if ( ! empty( $wc_product['permalink'] ) ) {
			$product['url'] = $wc_product['permalink'];
		}

		// Taxonomies split (2.0.0+):
		//   - `categories[]` carries hierarchical/brand taxonomies —
		//     WC categories (with `taxonomy: "merchant"`) and WC brands
		//     (with `taxonomy: "brand"`).
		//   - `tags[]` gets its own top-level array (plain strings, no
		//     wrapper object) per the UCP core product shape.
		// Pre-2.0 we folded everything into categories[] with a
		// `taxonomy` discriminator; that was spec-technically valid but
		// made `filters.tags[]` vs `filters.category[]` feel
		// asymmetric and forced agents to walk the full categories
		// array to discover tags. Splitting matches the spec exactly.
		$taxonomies = self::extract_taxonomies( $wc_product );
		if ( ! empty( $taxonomies['categories'] ) ) {
			$product['categories'] = $taxonomies['categories'];
		}
		if ( ! empty( $taxonomies['tags'] ) ) {
			$product['tags'] = $taxonomies['tags'];
		}

		if ( ! empty( $wc_product['images'] ) ) {
			$product['media'] = self::extract_media( $wc_product['images'] );
		}

		// Attributes split (2.0.0+):
		//
		//   - `options[]` — variation axes. Each entry advertises the
		//      set of values the merchant has defined for a selectable
		//      dimension ("Size: [S, M, L]"). Spec shape is
		//      `{name, values: string[]}`. Identified in WC via the
		//      Store API's per-attribute `has_variations: true` flag.
		//   - `metadata.attributes` — informational. Material, origin,
		//      fit details — things that apply uniformly across
		//      variants (or to simple products). Spec treats these as
		//      vendor-extension data under `metadata`, not a first-
		//      class filterable axis.
		//
		// Pre-2.0.0 both shapes collapsed into `product.attributes[]`
		// with no distinction; strict UCP consumers couldn't tell
		// "selectable axes" from "descriptive metadata". The split
		// enables client-side variant pickers (walk options, render
		// select UI) and informational panels (render metadata) via
		// different code paths.
		$classified = self::extract_classified_attributes( $wc_product );
		if ( ! empty( $classified['options'] ) ) {
			$product['options'] = $classified['options'];
		}
		if ( ! empty( $classified['metadata_attributes'] ) ) {
			// Nothing else writes into product-level metadata today, so
			// a straight assignment is safe. If a future field also
			// writes under `metadata` (currently only variants do), this
			// needs to switch to merge-style to preserve sibling keys.
			$product['metadata'] = [
				'attributes' => $classified['metadata_attributes'],
			];
		}

		// Rating + review count — emitted under core `product.rating`
		// (2.0.0+). Previously (1.x) under the vendor extension
		// namespace `extensions.com.woocommerce.ai_syndication.ratings`;
		// relocated to the canonical UCP core shape for spec parity.
		// Shape is `{average, count}` — `average` (not `value`) is
		// explicit about what the number represents, which matters for
		// stores that may later carry distribution data alongside.
		// Emitted only when reviews exist — no reviews = no rating key.
		$rating = self::extract_rating( $wc_product );
		if ( null !== $rating ) {
			$product['rating'] = $rating;
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
	 * Extract `published_at` / `updated_at` ISO 8601 timestamps from a
	 * WC Store API product response.
	 *
	 * Shape drift across WC versions:
	 *   - WC 9.5+ emits `date_created` / `date_modified` as ISO 8601
	 *     strings directly (e.g. `"2026-04-20T10:00:00"`).
	 *   - Older WC versions (≤ 9.4 on some configurations) emit an
	 *     object `{raw: "...", format_to_edit: "..."}` where `raw`
	 *     carries the MySQL datetime. Not ISO 8601, but close enough
	 *     that agents parsing it with a lenient date parser won't break.
	 *
	 * We accept both shapes and return the best representation available.
	 * Agents get whichever form the source emits — if the `raw` variant
	 * is MySQL datetime (space-separated), it's still monotonically
	 * comparable for "is this newer than my last sync?", which is the
	 * primary use case.
	 *
	 * Returns an array with keys `published_at` / `updated_at` only
	 * when the corresponding source field is present and non-empty.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{published_at?: string, updated_at?: string}
	 */
	private static function extract_timestamps( array $wc_product ): array {
		$map = [
			'date_created'  => 'published_at',
			'date_modified' => 'updated_at',
		];

		$out = [];
		foreach ( $map as $wc_key => $ucp_key ) {
			$raw = $wc_product[ $wc_key ] ?? null;

			// Object form — prefer `raw` (MySQL datetime), fall back
			// to `format_to_edit` which is the same value formatted.
			if ( is_array( $raw ) ) {
				$raw = $raw['raw'] ?? ( $raw['format_to_edit'] ?? null );
			}

			if ( is_string( $raw ) && '' !== $raw ) {
				$out[ $ucp_key ] = $raw;
			}
		}

		return $out;
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
	 * Extract the combined taxonomies list (categories + tags + brands).
	 *
	 * All three come back as UCP category entries (`{value, taxonomy}`)
	 * with distinct `taxonomy` slugs:
	 *   - `categories[]` — objects with `taxonomy: "merchant"` (WC
	 *     categories) and `taxonomy: "brand"` (WC `product_brand`
	 *     taxonomy, native in WC 9.5+).
	 *   - `tags[]` — plain strings per the UCP core product.tags
	 *     shape. Cross-cutting discovery signals ("summer",
	 *     "eco-friendly") that don't carry a hierarchy.
	 *
	 * Pre-2.0.0 returned a single flat list with a `taxonomy`
	 * discriminator. Split in 2.0.0 so tags reach agents via the
	 * core `product.tags` field — symmetric with `filters.tags[]`
	 * on the request side, and matches what strict UCP consumers
	 * expect.
	 *
	 * Brands surface via `brands` on the Store API product response
	 * when the merchant has the taxonomy registered. Shape is
	 * `[{id, name, slug}, ...]` — mechanical extraction.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{categories: array<int, array{value: string, taxonomy: string}>, tags: array<int, string>}
	 */
	private static function extract_taxonomies( array $wc_product ): array {
		$categories = [];
		$tags       = [];

		if ( ! empty( $wc_product['categories'] ) && is_array( $wc_product['categories'] ) ) {
			foreach ( $wc_product['categories'] as $cat ) {
				if ( is_array( $cat ) && ! empty( $cat['name'] ) ) {
					$categories[] = [
						'value'    => (string) $cat['name'],
						'taxonomy' => 'merchant',
					];
				}
			}
		}

		if ( ! empty( $wc_product['tags'] ) && is_array( $wc_product['tags'] ) ) {
			foreach ( $wc_product['tags'] as $tag ) {
				if ( is_array( $tag ) && ! empty( $tag['name'] ) ) {
					$tags[] = (string) $tag['name'];
				}
			}
		}

		if ( ! empty( $wc_product['brands'] ) && is_array( $wc_product['brands'] ) ) {
			foreach ( $wc_product['brands'] as $brand ) {
				if ( is_array( $brand ) && ! empty( $brand['name'] ) ) {
					$categories[] = [
						'value'    => (string) $brand['name'],
						'taxonomy' => 'brand',
					];
				}
			}
		}

		return [
			'categories' => $categories,
			'tags'       => $tags,
		];
	}

	/**
	 * Extract product-level attributes for discovery filtering.
	 *
	 * WC Store API returns attributes as a flat array on the product
	 * response. Each entry has `name` (display label, e.g. "Material"),
	 * `taxonomy` (slug, e.g. "pa_material"), `terms` (the values the
	 * merchant has tagged this product with), and `has_variations`
	 * (true when this attribute drives variant selection).
	 *
	 * Two output buckets:
	 *   - `options[]` — variation axes (`has_variations: true`).
	 *      Shape `{name, values: string[]}`, matching UCP core
	 *      `product.options` exactly. Consumed by variant-picker UIs.
	 *   - `metadata_attributes[]` — informational attributes
	 *      (`has_variations: false` or missing). Same shape, but
	 *      nested under `metadata.attributes` on the emitted product
	 *      so strict consumers don't confuse them with selectable
	 *      variant axes.
	 *
	 * Entries with no terms in either bucket are skipped entirely —
	 * an attribute the merchant declared but never assigned to this
	 * product contributes nothing to the agent-facing payload.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{options: array<int, array{name: string, values: array<int, string>}>, metadata_attributes: array<int, array{name: string, values: array<int, string>}>}
	 */
	private static function extract_classified_attributes( array $wc_product ): array {
		$attributes = $wc_product['attributes'] ?? [];
		if ( ! is_array( $attributes ) ) {
			return [
				'options'             => [],
				'metadata_attributes' => [],
			];
		}

		$options  = [];
		$metadata = [];

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
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

			$entry = [
				'name'   => $name,
				'values' => $values,
			];

			if ( ! empty( $attribute['has_variations'] ) ) {
				$options[] = $entry;
			} else {
				$metadata[] = $entry;
			}
		}

		return [
			'options'             => $options,
			'metadata_attributes' => $metadata,
		];
	}

	/**
	 * Extract the core `product.rating` payload.
	 *
	 * Returns a compact `{average, count}` shape when the merchant
	 * has at least one review, otherwise null (caller omits the
	 * `rating` key rather than emitting zeros — no reviews ≠ 0.0
	 * stars, and conflating them would mislead agents). Average
	 * rating is a string in the Store API response (e.g. "4.67");
	 * we coerce to float for agents that do numeric comparisons.
	 * Review count is already an int.
	 *
	 * Agents recommending products benefit enormously from rating
	 * data — "customers rate it 4.7 / 2,384 reviews" is dominant
	 * social proof that converts. The data is already computed by
	 * WC; we just forward it.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{average: float, count: int}|null
	 */
	private static function extract_rating( array $wc_product ): ?array {
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
