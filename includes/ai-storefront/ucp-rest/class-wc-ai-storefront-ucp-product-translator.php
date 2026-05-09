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
 * @package WooCommerce_AI_Storefront
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates WooCommerce Store API product responses into UCP product objects.
 */
class WC_AI_Storefront_UCP_Product_Translator {

	/**
	 * UCP product ID prefix. `prod_{wc_id}` distinguishes product IDs
	 * from variant IDs (`var_{wc_variation_id}`) at the UCP layer,
	 * letting `/catalog/lookup` handle both types through one route.
	 */
	const PRODUCT_ID_PREFIX = 'prod_';

	/**
	 * Schema.org reserved variant attributes.
	 *
	 * These four names are the only WC attributes on a simple product that
	 * signal "this product should be a variable product" — they map directly
	 * to schema.org/Product variant properties (color, size, pattern, material).
	 * When a simple product carries any of them, we promote the product to a
	 * single-member product group so the UCP shape is consistent.
	 *
	 * Comparison is case-insensitive — see the `strtolower(...)`/`in_array(...)`
	 * check inside `translate()` where this list is consumed.
	 */
	const SCHEMA_VARIANT_ATTRIBUTES = [ 'color', 'size', 'pattern', 'material' ];

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
	 * UTM attribution stamping is intentionally NOT done here. Callers
	 * that operate in an agent context apply
	 * `WC_AI_Storefront_Attribution::with_woo_ucp_utm()` to `$product['url']`
	 * after calling this method. Keeping stamping out of the translator
	 * preserves the pure-function contract — the output is fully
	 * determined by the inputs, with no side-effectful URL rewriting.
	 *
	 * @param array<string, mixed>             $wc_product    Decoded Store API product response.
	 * @param array<int, array<string, mixed>> $wc_variations Optional pre-fetched Store API
	 *                                                        variation responses. Empty = fall
	 *                                                        back to synthesized default.
	 * @param array<string, mixed>|null        $seller        Optional seller block, threaded
	 *                                                        through to every emitted variant
	 *                                                        (UCP 2026-04-08 defines `seller`
	 *                                                        inline on `variant.json` only —
	 *                                                        no `product.seller` field exists,
	 *                                                        so this is NOT copied onto the
	 *                                                        product itself). Same seller for
	 *                                                        every product in a request, so the
	 *                                                        controller computes it once and
	 *                                                        passes it in — keeps the translator
	 *                                                        WP-unaware. See `extract_variants()`
	 *                                                        for where the seller lands.
	 * @param array<int, string>|null          $category_paths Optional per-category-id
	 *                                                        `>`-delimited hierarchy strings
	 *                                                        (e.g. `[42 => "Clothing > Tshirts"]`).
	 *                                                        Pre-built by the controller from
	 *                                                        `/products/categories` once per
	 *                                                        request and threaded through;
	 *                                                        keeps the translator WP-unaware.
	 *                                                        When supplied, emitted
	 *                                                        `category.value` is the path
	 *                                                        string instead of the bare name.
	 *                                                        Per UCP `category.json` (2026-04-08).
	 * @return array<string, mixed>                           UCP product shape.
	 */
	public static function translate(
		array $wc_product,
		array $wc_variations = array(),
		?array $seller = null,
		?array $category_paths = null
	): array {
		$id = (int) ( $wc_product['id'] ?? 0 );

		// Bundle-product detection. WooCommerce Product Bundles (paid
		// extension) emits `type: "bundle"` and exposes the bundle
		// structure under `extensions.bundles`. We use the bundle's
		// computed price range and config metadata in the catalog
		// response so agents see the actual buyable range (which can
		// span optional add-ons and per-child discounts) rather than
		// the parent's flat `prices.price` value. See #358.
		$bundle_data = self::extract_bundle_data( $wc_product );

		$product = [
			'id'          => self::PRODUCT_ID_PREFIX . $id,
			'title'       => self::decode( (string) ( $wc_product['name'] ?? '' ) ),
			'description' => self::extract_description( $wc_product ),
			'price_range' => null !== $bundle_data
				? self::extract_bundle_price_range( $bundle_data, $wc_product )
				: self::extract_price_range( $wc_product ),
		];

		// `list_price_range` — UCP core optional field carrying the
		// pre-discount price range for strikethrough rendering.
		// Emitted when at least one observed variant (or the simple
		// product itself) has `regular_price > price`. Omitted when
		// nothing is on sale, when regular_price is unavailable, or
		// when the variation set is partial (count mismatch between
		// parent `variations[]` pointers and pre-fetched bodies).
		// See `extract_list_price_range` for the full rule set.
		//
		// Bundles read from `extensions.bundles.bundle_price.regular_price`
		// instead — the parent's `prices.regular_price` reflects only
		// the bundle base, not the fully-configured pre-discount range.
		$list_price_range = null !== $bundle_data
			? self::extract_bundle_list_price_range( $bundle_data, $wc_product )
			: self::extract_list_price_range( $wc_product, $wc_variations );
		if ( null !== $list_price_range ) {
			$product['list_price_range'] = $list_price_range;
		}

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

		// Seller is no longer attached at the product level. UCP
		// `variant.json` defines `seller` inline only on variants
		// (no `product.seller` field anywhere in the spec tree). The
		// controller still passes `$seller` here; we route it through
		// `extract_variants()` so every emitted variant carries it.
		// See `extract_variants()` below.

		// Optional fields — only emit when source has a non-empty value.
		if ( ! empty( $wc_product['slug'] ) ) {
			$product['handle'] = $wc_product['slug'];
		}

		if ( ! empty( $wc_product['permalink'] ) ) {
			// Emit the bare permalink. UTM attribution is stamped by the
			// controller after translation via
			// `WC_AI_Storefront_Attribution::with_woo_ucp_utm()`, keeping
			// this translator a pure function whose output depends only
			// on its inputs. See the controller's `translate_products_for_search`
			// and the catalog/lookup handler for the stamping call sites.
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
		$taxonomies = self::extract_taxonomies( $wc_product, $category_paths );
		if ( ! empty( $taxonomies['categories'] ) ) {
			$product['categories'] = $taxonomies['categories'];
		}
		if ( ! empty( $taxonomies['tags'] ) ) {
			$product['tags'] = $taxonomies['tags'];
		}

		if ( ! empty( $wc_product['images'] ) ) {
			$product['media'] = self::extract_media( $wc_product['images'] );
		}

		// Attributes split:
		//
		//   - `options[]` — variation axes. Identified by `has_variations:
		//      true` on variable products. On simple products, the four
		//      schema.org reserved variant attributes (Color, Size, Pattern,
		//      Material) are also promoted here when present — they signal
		//      "this product should be variable" regardless of how the
		//      merchant configured WC. Keeps the product-group shape
		//      consistent for agents.
		//   - `metadata.attributes` — everything else: informational facts
		//      like Fabric Weight or Origin that don't narrow variant
		//      selection.
		$classified = self::extract_classified_attributes( $wc_product );
		// Promote metadata_attributes to options[] only when the product
		// has no true variation axes (has_variations:true). A simple product
		// with e.g. Color=White, Size=L should look like a single-member
		// product group. Variable products (any has_variations:true axis)
		// leave metadata_attributes in metadata — those are informational
		// facts that apply across all variants, not selection axes.
		$has_variation_axes = ! empty( $classified['options'] );
		// On simple products (no has_variations:true axis), promote only the
		// four schema.org reserved variant attributes — Color, Size, Pattern,
		// Material — to options[]. These are the only names that signal
		// "this product should be variable". Informational attributes like
		// Fabric Weight or Origin stay in metadata regardless.
		$promote_to_options = [];
		if ( ! $has_variation_axes ) {
			foreach ( $classified['metadata_attributes'] as $attr ) {
				if ( in_array( strtolower( $attr['name'] ?? '' ), self::SCHEMA_VARIANT_ATTRIBUTES, true ) ) {
					// Trim to the first non-empty value so product.options[].values[]
					// matches the one concrete combination on the synthesized variant.
					$first_value = null;
					foreach ( $attr['values'] ?? [] as $v ) {
						if ( '' !== (string) ( $v['label'] ?? '' ) ) {
							$first_value = $v;
							break;
						}
					}
					if ( null !== $first_value ) {
						$promote_to_options[] = [
							'name'   => $attr['name'],
							'values' => [ $first_value ],
						];
					}
				}
			}
		}

		// `variants` must come after attribute classification so that the
		// promoted options can be threaded into synthesize_default().
		$product['variants'] = self::extract_variants( $wc_product, $wc_variations, $seller, $promote_to_options );

		if ( ! empty( $classified['options'] ) ) {
			$product['options'] = $classified['options'];
		} elseif ( ! empty( $promote_to_options ) ) {
			// Simple product whose attributes all have has_variations:false —
			// promote them to options[] so the product looks like a single-
			// member product group. The synthesized default variant carries
			// matching options[] entries (see extract_variants / synthesize_default).
			$product['options'] = $promote_to_options;
		}

		// metadata.attributes: attributes that were NOT promoted to options[].
		// On variable products: the non-axis (has_variations:false) attributes.
		// On simple products: whatever didn't match SCHEMA_VARIANT_ATTRIBUTES.
		$promoted_names    = array_map( static fn( $a ) => $a['name'], $promote_to_options );
		$residual_metadata = $has_variation_axes
			? $classified['metadata_attributes']
			: array_values(
				array_filter(
					$classified['metadata_attributes'],
					static fn( $a ) => ! in_array( $a['name'], $promoted_names, true )
				)
			);
		$metadata          = [];
		if ( ! empty( $residual_metadata ) ) {
			$metadata['attributes'] = $residual_metadata;
		}
		// Bundle structure under `metadata.bundle` (issue #358). Lets agents
		// describe the bundle accurately and gives them the data needed to
		// distinguish "deterministic bundle the buyer can complete via
		// continue_url" from "configurable bundle requiring on-site
		// configuration." UCP core has no typed bundle field; metadata is
		// the spec's allowed extension surface.
		if ( null !== $bundle_data ) {
			$bundle_metadata = self::extract_bundle_metadata( $bundle_data );
			if ( null !== $bundle_metadata ) {
				$metadata['bundle'] = $bundle_metadata;
			}
		}
		if ( ! empty( $metadata ) ) {
			$product['metadata'] = $metadata;
		}

		// Rating + review count — emitted under core `product.rating`
		// (2.0.0+). Previously (1.x) under the vendor extension
		// namespace `extensions.com.woocommerce.ai_storefront.ratings`;
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
	 *     translated via `WC_AI_Storefront_UCP_Variant_Translator::translate()`.
	 *     Variant IDs are `var_{variation_id}` (no `_default` suffix — that
	 *     marker is reserved for synthesized placeholders).
	 *   - `$wc_variations` is empty (simple product, or variable product
	 *     where caller did not pre-fetch): emit one synthesized default
	 *     variant via `WC_AI_Storefront_UCP_Variant_Translator::synthesize_default()`
	 *     so the minItems:1 constraint is still satisfied. This is the safety-
	 *     net path — callers emitting a variable product without variations
	 *     get a defensive fallback rather than a schema-violating empty
	 *     array, but the `_default` suffix signals the shape is degraded.
	 *
	 * @param array<string, mixed>             $wc_product    Decoded Store API response.
	 * @param array<int, array<string, mixed>> $wc_variations Pre-fetched variation responses.
	 * @param array<string, mixed>|null        $seller        Seller block threaded to each variant.
	 * @param array<int, array<string, mixed>> $simple_options Promoted options for synthesized default.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_variants(
		array $wc_product,
		array $wc_variations,
		?array $seller = null,
		array $simple_options = []
	): array {
		if ( ! empty( $wc_variations ) ) {
			// The variant translator can't read parent product data on
			// its own without breaking the pure-function contract, but
			// it needs the parent's attribute names to disambiguate
			// comma-in-value cases when parsing the variation's
			// formatted `variation` string (the Store API always leaves
			// `attributes[]` empty for variable-product variations as of
			// WC 9.x — see issue #347). Extract once here and pass
			// down — every variation in the set shares the same parent
			// axis names, so this is O(parent.attributes), not
			// O(variations × attributes).
			$parent_attribute_names = self::extract_parent_attribute_names( $wc_product );

			// Term-slug map for `selected_option.id` enrichment (issue
			// #350). Pre-built once per product from
			// `attributes[].terms[].{name,slug}` and threaded down to
			// every variation. Pure-function contract preserved — same
			// pattern as `parent_attribute_names`.
			$term_slug_map = self::build_term_slug_map( $wc_product );

			$variants = array();
			foreach ( $wc_variations as $wc_variation ) {
				$variants[] = WC_AI_Storefront_UCP_Variant_Translator::translate(
					$wc_variation,
					$parent_attribute_names,
					$seller,
					$term_slug_map
				);
			}
			return $variants;
		}

		return array(
			WC_AI_Storefront_UCP_Variant_Translator::synthesize_default( $wc_product, $seller, $simple_options ),
		);
	}

	/**
	 * Build the per-axis term-slug lookup map for `selected_option.id`
	 * emission on each variant.
	 *
	 * Shape: `[axis_label => ['taxonomy' => string, 'slugs' => [label => slug]]]`.
	 * Example for a Color/Size product:
	 *
	 *   [
	 *     'Color' => [
	 *       'taxonomy' => 'pa_color',
	 *       'slugs'    => [ 'Black' => 'black', 'Green' => 'green' ],
	 *     ],
	 *     'Size'  => [
	 *       'taxonomy' => 'pa_size',
	 *       'slugs'    => [ 'M' => 'm', 'L' => 'l' ],
	 *     ],
	 *   ]
	 *
	 * Only included when the attribute taxonomy starts with `pa_` (i.e.
	 * a real WC taxonomy with stable slugs). Excludes:
	 *   - Custom inline attributes (no taxonomy) — no canonical id
	 *     available; variant translator omits `selected_option.id`.
	 *   - Third-party non-`pa_` product-attribute taxonomies (e.g.
	 *     `pwb-brand` from Perfect Brands, WCFM brand attributes) —
	 *     these don't follow WC's `wc_attribute_taxonomy_name()`
	 *     convention, so we can't compose a stable
	 *     `<taxonomy>:<slug>` identifier that downstream agents would
	 *     reliably interpret. Same graceful-degradation as custom
	 *     inline attributes: emit `label`, omit `id`.
	 *
	 * The structured `{taxonomy, slugs}` shape (vs. the earlier
	 * `__tax__` sentinel-key approach) eliminates an entire collision
	 * class — WordPress doesn't restrict double-underscore in term
	 * *names* (only slugs go through `sanitize_title`), so a term
	 * literally named `__tax__` would have overwritten a sentinel
	 * key. The parallel-arrays-per-axis approach is unambiguous.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array<string, array{taxonomy: string, slugs: array<string, string>}>
	 */
	private static function build_term_slug_map( array $wc_product ): array {
		$attributes = $wc_product['attributes'] ?? [];
		if ( ! is_array( $attributes ) ) {
			return [];
		}

		$map = [];
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$axis_label = self::decode( (string) ( $attribute['name'] ?? '' ) );
			$taxonomy   = (string) ( $attribute['taxonomy'] ?? '' );
			$terms      = $attribute['terms'] ?? [];
			// Excludes custom inline attributes (no `taxonomy`) AND
			// third-party non-`pa_` product-attribute taxonomies.
			// See docblock above for the rationale.
			if ( '' === $axis_label || '' === $taxonomy || ! str_starts_with( $taxonomy, 'pa_' ) ) {
				continue;
			}
			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$slugs = [];
			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}
				$name = self::decode( (string) ( $term['name'] ?? '' ) );
				$slug = (string) ( $term['slug'] ?? '' );
				if ( '' === $name || '' === $slug ) {
					continue;
				}
				$slugs[ $name ] = $slug;
			}

			// Skip axes whose terms all lacked usable name/slug pairs.
			if ( ! empty( $slugs ) ) {
				$map[ $axis_label ] = [
					'taxonomy' => $taxonomy,
					'slugs'    => $slugs,
				];
			}
		}
		return $map;
	}

	/**
	 * Pull the human-readable names of the parent's variation-axis
	 * attributes from the Store API `attributes[]` array.
	 *
	 * Used by `extract_variants()` to give the variant translator the
	 * anchor list it needs for parsing each variation's formatted
	 * `variation` string. Only attributes flagged
	 * `has_variations: true` make it into the anchor list — purely
	 * informational attributes (Material, Brand, Origin) never appear
	 * in a variation string, so including them would just bloat the
	 * regex with no-op anchors.
	 *
	 * Returns an empty array when the parent has no variation
	 * attributes (e.g. a misconfigured variable product) — the
	 * translator falls back to a naive split in that case.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array<int, string>
	 */
	private static function extract_parent_attribute_names( array $wc_product ): array {
		$attributes = $wc_product['attributes'] ?? [];
		if ( ! is_array( $attributes ) ) {
			return [];
		}

		$names = [];
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			// Strict `=== true` rather than `! empty()` mirrors the
			// classifier in `extract_classified_attributes()` — `empty()`
			// would treat the literal string `"false"` as truthy
			// (PHP footgun), misclassifying an informational attribute
			// as a variation axis.
			if ( true !== ( $attribute['has_variations'] ?? false ) ) {
				continue;
			}
			$name = $attribute['name'] ?? '';
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				$names[] = trim( $name );
			}
		}
		return $names;
	}

	/**
	 * Extract `published_at` / `updated_at` timestamps from a WC Store
	 * API product response.
	 *
	 * Source location: our own Store API extension (registered in
	 * `WC_AI_Storefront_Store_Api_Extension`). WC 9.5+ strips
	 * `date_created` / `date_modified` from Store API product
	 * responses by default — verified against a live catalog where
	 * not a single product had those keys at the top level. Our
	 * extension re-exposes them under
	 * `extensions[com-woocommerce-ai-storefront].{date_created,date_modified}`,
	 * already formatted as RFC 3339 UTC strings (`Y-m-d\TH:i:s\Z`),
	 * which matches the UCP core product shape directly.
	 *
	 * Defensive fallback: if the extension payload is absent (e.g.
	 * Blocks inactive, our plugin not yet registered, direct fixture
	 * in a test), we also check the top-level keys for
	 * forward-compat in case WC ever starts emitting them natively.
	 * Omits the key rather than synthesizing when no source is available.
	 *
	 * Returns an array with keys `published_at` / `updated_at` only
	 * when the corresponding source field is present and non-empty.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{published_at?: string, updated_at?: string}
	 */
	private static function extract_timestamps( array $wc_product ): array {
		// Store API registers extension data under a hyphenated
		// namespace (`com-woocommerce-ai-storefront`), distinct from
		// the dotted UCP-level namespace (`com.woocommerce.ai_storefront`).
		// Pulled from the extension class constant so the two surfaces
		// stay linked — the extension class is `require_once`'d
		// during `WC_AI_Storefront::load_dependencies()` at plugin
		// bootstrap (this plugin doesn't use PSR-4 autoload), so
		// referencing the constant here doesn't introduce any new
		// load step; the class is already resolved by the time any
		// translator method runs.
		//
		// Defensive `is_array` guards at each layer — a third-party
		// plugin could collide on the `extensions` or namespace key
		// and write a non-array. Without these guards, `$ext[$key]`
		// would fatal ("cannot use object/string as array"). Mirrors
		// the same pattern in `UCP_Variant_Translator::extract_barcodes`
		// so both translators degrade identically on filter-poisoned
		// Store API responses.
		$extensions = $wc_product['extensions'] ?? [];
		$ext        = [];
		if ( is_array( $extensions ) ) {
			$namespace = WC_AI_Storefront_Store_Api_Extension::NAMESPACE;
			$candidate = $extensions[ $namespace ] ?? [];
			if ( is_array( $candidate ) ) {
				$ext = $candidate;
			}
		}

		$map = [
			'date_created'  => 'published_at',
			'date_modified' => 'updated_at',
		];

		$out = [];
		foreach ( $map as $wc_key => $ucp_key ) {
			// Prefer the extension-sourced value (our Store API
			// extension formats these as RFC 3339 / ISO 8601 UTC
			// already). Fall back to the top-level key for
			// forward-compat with any future WC version that
			// re-adds native date emission to Store API.
			$raw = $ext[ $wc_key ] ?? ( $wc_product[ $wc_key ] ?? null );

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
	 * Extract the pre-discount `list_price_range` for strikethrough
	 * display — UCP core's optional product-level counterpart to
	 * `list_price` on variants.
	 *
	 * Emission rule: emit iff at least one observed variant (or, for
	 * simple products, the product itself) has `regular_price > price`.
	 * That's the direct signal for "something is on sale" — stronger
	 * than comparing aggregated min/max against the active range,
	 * which misses mid-priced discounts (a variant discounted between
	 * the cheapest and most expensive leaves the overall range
	 * unchanged). Previous versions used min/max equality as a
	 * discount proxy; this refactor makes the per-variant comparison
	 * authoritative and locks the range-computation independent of
	 * the emission decision.
	 *
	 * Paths:
	 *   - Variable products with variations pre-fetched (count
	 *     matches the parent's declared `variations[]` pointer list):
	 *     walk each variation's `{regular_price, price}` pair.
	 *   - Simple products (no `variations[]` declared on the parent):
	 *     fall back to product-level `{regular_price, price}` as a
	 *     single-point range.
	 *
	 * Partial-variation guard: runs FIRST, before either path above.
	 * When the parent declares `variations[]` but we received fewer
	 * full bodies (controller capped via MAX_VARIATIONS_PER_PRODUCT,
	 * individual fetches failed, or caller passed an empty
	 * `$wc_variations` for a variable product), the derived range
	 * would be based on incomplete data. We omit `list_price_range`
	 * entirely rather than ship a misleading value — and variable
	 * products with no variations passed at all fall under this
	 * guard too (count mismatch 0 < N → null), so the
	 * product-level fallback below is only reached for genuine
	 * simple products. Agents who see the controller's
	 * `partial_variants` warning already know variant data is
	 * incomplete; dropping list_price_range alongside is the
	 * honest posture.
	 *
	 * Returns null when:
	 *   - No regular_price is available anywhere (data anomaly); OR
	 *   - No variant is observably on sale (`regular <= price` for
	 *     all observed variants); OR
	 *   - Variation set is partial (see above).
	 *
	 * @param array<string, mixed>             $wc_product
	 * @param array<int, array<string, mixed>> $wc_variations  Pre-fetched variations.
	 * @return array<string, mixed>|null UCP price_range object, or null when the field carries no useful signal.
	 */
	private static function extract_list_price_range(
		array $wc_product,
		array $wc_variations
	): ?array {
		// Partial-variation guard — a variable product whose parent
		// declares N pointers but we only received M<N full bodies
		// can't reliably compute either the discount signal or the
		// full range. Omit cleanly; the controller's `partial_variants`
		// message already informs agents that variant data is partial.
		$declared_variations = $wc_product['variations'] ?? null;
		if (
			is_array( $declared_variations )
			&& count( $declared_variations ) > count( $wc_variations )
		) {
			return null;
		}

		$prices   = $wc_product['prices'] ?? [];
		$currency = $prices['currency_code'] ?? 'USD';

		// Walk observed variants, collecting regular prices and
		// tracking whether any one of them is on sale
		// (regular > price). The on-sale boolean drives emission;
		// the regular-price array drives the range.
		$regular_prices = [];
		$any_on_sale    = false;

		if ( ! empty( $wc_variations ) ) {
			foreach ( $wc_variations as $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}
				$vp      = $variation['prices'] ?? [];
				$regular = isset( $vp['regular_price'] ) && '' !== $vp['regular_price']
					? (int) $vp['regular_price']
					: null;
				$active  = isset( $vp['price'] ) && '' !== $vp['price']
					? (int) $vp['price']
					: null;

				if ( null !== $regular ) {
					$regular_prices[] = $regular;
					if ( null !== $active && $regular > $active ) {
						$any_on_sale = true;
					}
				}
			}
		} elseif ( isset( $prices['regular_price'] ) && '' !== $prices['regular_price'] ) {
			// Simple-product fallback: one-point range derived from
			// the product-level prices block.
			$regular = (int) $prices['regular_price'];
			$active  = isset( $prices['price'] ) && '' !== $prices['price']
				? (int) $prices['price']
				: null;

			$regular_prices[] = $regular;
			if ( null !== $active && $regular > $active ) {
				$any_on_sale = true;
			}
		}

		if ( empty( $regular_prices ) || ! $any_on_sale ) {
			return null;
		}

		return [
			'min' => [
				'amount'   => min( $regular_prices ),
				'currency' => $currency,
			],
			'max' => [
				'amount'   => max( $regular_prices ),
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
	 * Extract taxonomies into two separate buckets — categories (with
	 * brands folded in under `taxonomy:"brand"`) and tags.
	 *
	 * Return shape is a split structure, NOT a combined list:
	 *   - `categories[]` — objects `{value, taxonomy}` covering
	 *      WC categories (`taxonomy:"merchant"`) and brands
	 *      (`taxonomy:"brand"`, from the `product_brand` taxonomy
	 *      native in WC 9.5+).
	 *   - `tags[]` — plain strings per the UCP core `product.tags`
	 *      shape. Cross-cutting discovery signals ("summer",
	 *      "eco-friendly") that don't carry a hierarchy.
	 *
	 * Pre-2.0.0 this returned a single flat list with a `taxonomy`
	 * discriminator covering all three. Split in 2.0.0 so tags
	 * reach agents via the core `product.tags` field — symmetric
	 * with `filters.tags[]` on the request side, and matches what
	 * strict UCP consumers expect.
	 *
	 * Brands surface via `brands` on the Store API product response
	 * when the merchant has the taxonomy registered. Shape is
	 * `[{id, name, slug}, ...]` — mechanical extraction. **Brands
	 * stay flat** even when `$category_paths` is supplied — the WC
	 * `product_brand` taxonomy has no native hierarchy in the data
	 * model, and the spec convention for `taxonomy:"brand"` entries
	 * is a flat brand label.
	 *
	 * Hierarchical category emission (#350): when `$category_paths`
	 * is supplied, replace each merchant category's `value` with the
	 * pre-computed `>`-delimited path string (e.g.
	 * `"Clothing > Tshirts"` for a child category). Categories
	 * without a map entry fall back to the bare `name` for graceful
	 * degradation. Per UCP `category.json` (release/2026-04-08), the
	 * canonical hierarchy encoding is a `>`-delimited string in the
	 * `value` field — there is no `parent` / `path` / `breadcrumbs`
	 * structured field.
	 *
	 * @param array<string, mixed>          $wc_product
	 * @param array<int, string>|null       $category_paths Per-category-id `>`-delimited
	 *                                                      hierarchy strings, pre-built by
	 *                                                      the controller. Map keyed by
	 *                                                      WC term ID. Null means no
	 *                                                      hierarchy data available — emit
	 *                                                      bare names (legacy behavior).
	 * @return array{categories: array<int, array{value: string, taxonomy: string}>, tags: array<int, string>}
	 */
	private static function extract_taxonomies(
		array $wc_product,
		?array $category_paths = null
	): array {
		$categories = [];
		$tags       = [];

		if ( ! empty( $wc_product['categories'] ) && is_array( $wc_product['categories'] ) ) {
			foreach ( $wc_product['categories'] as $cat ) {
				if ( ! is_array( $cat ) || empty( $cat['name'] ) ) {
					continue;
				}
				$cat_id = (int) ( $cat['id'] ?? 0 );
				$value  = self::decode( (string) $cat['name'] );
				if ( null !== $category_paths && $cat_id > 0 && isset( $category_paths[ $cat_id ] ) ) {
					$path = (string) $category_paths[ $cat_id ];
					if ( '' !== $path ) {
						$value = $path;
					}
				}
				$categories[] = [
					'value'    => $value,
					'taxonomy' => 'merchant',
				];
			}
		}

		if ( ! empty( $wc_product['tags'] ) && is_array( $wc_product['tags'] ) ) {
			foreach ( $wc_product['tags'] as $tag ) {
				if ( is_array( $tag ) && ! empty( $tag['name'] ) ) {
					$tags[] = self::decode( (string) $tag['name'] );
				}
			}
		}

		if ( ! empty( $wc_product['brands'] ) && is_array( $wc_product['brands'] ) ) {
			foreach ( $wc_product['brands'] as $brand ) {
				if ( is_array( $brand ) && ! empty( $brand['name'] ) ) {
					// Brands stay flat — no `$category_paths` lookup
					// (WC `product_brand` taxonomy has no native
					// hierarchy in the data model).
					$categories[] = [
						'value'    => self::decode( (string) $brand['name'] ),
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
	 *      Shape `{name, values: [{label: string}, ...]}` per UCP
	 *      `option_value.json` (release/2026-04-08): each value is
	 *      an object with a required `label`, not a bare string.
	 *      Consumed by variant-picker UIs.
	 *   - `metadata_attributes[]` — informational attributes
	 *      (`has_variations: false` or missing). Same `values[]`
	 *      object shape, nested under `metadata.attributes` on the
	 *      emitted product so strict consumers don't confuse them
	 *      with selectable variant axes.
	 *
	 * Entries with no terms in either bucket are skipped entirely —
	 * an attribute the merchant declared but never assigned to this
	 * product contributes nothing to the agent-facing payload.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{options: array<int, array{name: string, values: array<int, array{label: string, id?: string}>}>, metadata_attributes: array<int, array{name: string, values: array<int, array{label: string, id?: string}>}>}
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

			$name     = self::decode( (string) ( $attribute['name'] ?? '' ) );
			$taxonomy = (string) ( $attribute['taxonomy'] ?? '' );
			$terms    = $attribute['terms'] ?? [];
			if ( '' === $name || ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			// Per `option_value.json` (UCP 2026-04-08): required `label`,
			// optional `id`. We emit `id` only for taxonomy-backed
			// attributes (slug starts with `pa_`) where each term has
			// a stable URL-safe slug. Excluded from `id` emission:
			//   - Custom inline attributes (no taxonomy registration,
			//     no canonical identifier).
			//   - Third-party non-`pa_` product-attribute taxonomies
			//     (e.g. `pwb-brand` from Perfect Brands, WCFM brand
			//     attributes) — these don't follow WC's
			//     `wc_attribute_taxonomy_name()` convention, so we
			//     can't compose a stable `<taxonomy>:<slug>` agents
			//     can rely on. Same graceful-degradation as custom
			//     inline attributes: emit `label`, omit `id`.
			//
			// `id` format: `<taxonomy>:<slug>` (e.g. `pa_color:black`).
			// Colon separator is unambiguous because both halves are
			// URL-safe / hyphen-delimited and never contain colons.
			// Agents can echo the `id` back via `selected_option.id`
			// for stable cross-locale variant matching (UCP 2026-04-08
			// `selected_option.id` semantics: "server SHOULD use it for
			// matching; name and label remain required for display").
			$is_taxonomy = '' !== $taxonomy && str_starts_with( $taxonomy, 'pa_' );

			$values = [];
			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) || empty( $term['name'] ) ) {
					continue;
				}
				$value = [ 'label' => self::decode( (string) $term['name'] ) ];
				if ( $is_taxonomy ) {
					$slug = $term['slug'] ?? '';
					if ( is_string( $slug ) && '' !== $slug ) {
						$value['id'] = $taxonomy . ':' . $slug;
					}
				}
				$values[] = $value;
			}
			if ( empty( $values ) ) {
				continue;
			}

			$entry = [
				'name'   => $name,
				'values' => $values,
			];

			// Strict `=== true` rather than `! empty()` because
			// `empty()` treats string `"false"` (a real PHP footgun:
			// non-empty string → truthy, but that's exactly the value
			// an upstream field might carry) as truthy — which would
			// misclassify a non-variation attribute as a variation
			// axis. On older WC where the field is genuinely missing,
			// the attribute gets routed to `metadata_attributes`
			// (informational) rather than `options[]` — conservative
			// default that prevents broken variant pickers on legacy
			// installations.
			if ( true === ( $attribute['has_variations'] ?? false ) ) {
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
	 * Extract the core `product.rating` payload per UCP `rating.json`.
	 *
	 * Returns `{value, scale_min, scale_max, count}` when the merchant
	 * has at least one review, otherwise null (caller omits the
	 * `rating` key rather than emitting zeros — no reviews ≠ 0.0
	 * stars, and conflating them would mislead agents).
	 *
	 * `value` is the average rating, coerced to float (the Store API
	 * returns it as a string like "4.67"). `count` is review_count.
	 * `scale_min` / `scale_max` are hardcoded 1 and 5 because WC core
	 * uses an inflexible 1-5 star scale — the bounds aren't surfaced
	 * by the Store API because they're not configurable. Custom
	 * review plugins that override the scale (rare, e.g. 0-10) would
	 * misrepresent here, but the spec field is required and stock WC
	 * is the overwhelming case; revisit with a filter only if a real
	 * deployment surfaces the need.
	 *
	 * @param array<string, mixed> $wc_product
	 * @return array{value: float, scale_min: int, scale_max: int, count: int}|null
	 */
	private static function extract_rating( array $wc_product ): ?array {
		$count = isset( $wc_product['review_count'] )
			? (int) $wc_product['review_count']
			: 0;

		if ( $count <= 0 ) {
			return null;
		}

		return [
			'value'     => isset( $wc_product['average_rating'] )
				? (float) $wc_product['average_rating']
				: 0.0,
			'scale_min' => 1,
			'scale_max' => 5,
			'count'     => $count,
		];
	}

	/**
	 * Decode HTML entities from a Store API string field.
	 *
	 * The WC Store API returns product/term names with HTML entities
	 * intact (e.g. `&#8211;` for en-dash, `&amp;` for ampersand). UCP
	 * responses are JSON consumed by agents and must carry plain Unicode
	 * strings, not HTML-encoded text.
	 *
	 * Tags are stripped after decoding so that encoded markup
	 * (e.g. `&lt;strong&gt;`) cannot reintroduce HTML elements in the
	 * output.
	 *
	 * @param string $value Raw Store API string.
	 * @return string       Plain-text string: entities decoded, tags stripped.
	 */
	private static function decode( string $value ): string {
		return wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Extract the WC Product Bundles plugin's bundle structure from a
	 * Store API product response.
	 *
	 * The Bundles plugin (paid extension) registers a Store API
	 * extension at `extensions.bundles` carrying:
	 *
	 *   - Bundle-level config: `bundle_min_size`, `bundle_max_size`,
	 *     `bundle_stock_status`, `bundle_stock_quantity`, `bundle_price`
	 *     (a `{price, regular_price}` object with `min` / `max` legs in
	 *     minor units, identical shape to WC's standard `prices`).
	 *   - Per-bundled-item array `bundled_items[]`, one entry per
	 *     bundled child product. Each entry includes `bundled_item_id`
	 *     (the index used in the add-to-cart URL params), `product_id`
	 *     (the actual child WC product ID), `quantity_default`,
	 *     `optional`, `priced_individually`, `discount`,
	 *     `override_default_variation_attributes`, `default_variation_attributes`.
	 *
	 * Detection requires BOTH `type === 'bundle'` AND a non-empty
	 * `extensions.bundles` block. If `type === 'bundle'` but the
	 * extension didn't render (Bundles plugin deactivated, or store
	 * misconfigured), the method returns null — the caller falls back
	 * to the simple-product translation path so the bundle still emits
	 * a valid (if minimal) UCP shape.
	 *
	 * @param  array<string, mixed>      $wc_product Store API product response.
	 * @return array<string, mixed>|null            Bundle extension data, or
	 *                                              null when the product is
	 *                                              not a bundle or the
	 *                                              extension is missing.
	 */
	private static function extract_bundle_data( array $wc_product ): ?array {
		if ( 'bundle' !== ( $wc_product['type'] ?? '' ) ) {
			return null;
		}
		// Defensive: a third-party `woocommerce_store_api_*` filter or a
		// future Store API revision could set `extensions` to a non-array
		// value. Guard before indexing so the translator doesn't raise
		// warnings on filtered payloads.
		$extensions = $wc_product['extensions'] ?? null;
		if ( ! is_array( $extensions ) ) {
			return null;
		}
		$bundle = $extensions['bundles'] ?? null;
		if ( ! is_array( $bundle ) || empty( $bundle ) ) {
			return null;
		}
		return $bundle;
	}

	/**
	 * Build the `price_range` for a bundle from its `bundle_price.price`
	 * min/max range.
	 *
	 * The bundle's `prices.price` (parent-level) reflects only the bundle
	 * base before any optional add-ons or per-child discounts apply. The
	 * accurate buyable range lives in
	 * `extensions.bundles.bundle_price.price.{min,max}` — this is what
	 * WC renders as "From: $20.00" on the storefront when the bundle
	 * has optional items or priced-individually children.
	 *
	 * Falls back to the standard simple-product price range when
	 * `bundle_price.price` is missing or malformed (defensive — older
	 * Bundles plugin versions may not emit it).
	 *
	 * @param  array<string, mixed> $bundle_data Bundle extension data.
	 * @param  array<string, mixed> $wc_product  Store API product response (for currency + fallback).
	 * @return array<string, mixed>              UCP price_range shape.
	 */
	private static function extract_bundle_price_range( array $bundle_data, array $wc_product ): array {
		$bundle_price = $bundle_data['bundle_price'] ?? [];
		$price        = $bundle_price['price'] ?? [];
		$currency     = (string) ( $bundle_price['currency_code'] ?? $wc_product['prices']['currency_code'] ?? 'USD' );

		$min = isset( $price['min']['excl_tax'] ) ? (int) $price['min']['excl_tax'] : null;
		$max = isset( $price['max']['excl_tax'] ) ? (int) $price['max']['excl_tax'] : null;

		if ( null === $min ) {
			return self::extract_price_range( $wc_product );
		}

		return [
			'min' => [
				'amount'   => $min,
				'currency' => $currency,
			],
			'max' => [
				'amount'   => null !== $max ? $max : $min,
				'currency' => $currency,
			],
		];
	}

	/**
	 * Build the optional `list_price_range` (pre-discount strikethrough)
	 * for a bundle from its `bundle_price.regular_price` min/max range.
	 *
	 * Returns null when the regular_price range equals the live price
	 * range (nothing on sale), when the regular_price block is missing,
	 * or when a min could not be parsed. Same emission rule as the
	 * non-bundle path: list_price_range is only emitted when there's
	 * an actual pre-discount value to communicate.
	 *
	 * Currency fallback chain mirrors `extract_bundle_price_range()`:
	 * `bundle_price.currency_code` → parent `prices.currency_code` →
	 * literal `USD`. Hard-coded USD is reached only when both are
	 * missing — defensive last resort.
	 *
	 * @param  array<string, mixed>      $bundle_data Bundle extension data.
	 * @param  array<string, mixed>      $wc_product  Store API product response (for currency fallback).
	 * @return array<string, mixed>|null              UCP price_range shape, or null.
	 */
	private static function extract_bundle_list_price_range( array $bundle_data, array $wc_product ): ?array {
		$bundle_price = $bundle_data['bundle_price'] ?? [];
		$regular      = $bundle_price['regular_price'] ?? [];
		$live         = $bundle_price['price'] ?? [];
		$currency     = (string) ( $bundle_price['currency_code'] ?? $wc_product['prices']['currency_code'] ?? 'USD' );

		$reg_min  = isset( $regular['min']['excl_tax'] ) ? (int) $regular['min']['excl_tax'] : null;
		$reg_max  = isset( $regular['max']['excl_tax'] ) ? (int) $regular['max']['excl_tax'] : null;
		$live_min = isset( $live['min']['excl_tax'] ) ? (int) $live['min']['excl_tax'] : null;
		$live_max = isset( $live['max']['excl_tax'] ) ? (int) $live['max']['excl_tax'] : null;

		if ( null === $reg_min ) {
			return null;
		}

		// Both legs of the live range must be parseable for the
		// "nothing on sale" suppression check to be meaningful. If the
		// extension is missing or malformed on the live side, we can't
		// tell whether the regular range is a strikethrough or just an
		// echo of the live range — bail rather than emit a possibly
		// misleading list_price_range.
		if ( null === $live_min ) {
			return null;
		}

		// Normalize null `max` legs to their `min` counterpart, matching
		// the way `extract_bundle_price_range()` emits flat-price bundles
		// (`max` defaults to `min` when missing). Without this, a
		// flat-price bundle whose `bundle_price.price.max.excl_tax` is
		// omitted but `regular_price.max.excl_tax` is present (and equals
		// `regular_price.min`) would skip the "nothing on sale"
		// suppression and emit a phantom strikethrough. Apply the same
		// normalization to `$reg_max` for symmetry — strict-equality
		// comparison below assumes both legs are present.
		if ( null === $live_max ) {
			$live_max = $live_min;
		}
		if ( null === $reg_max ) {
			$reg_max = $reg_min;
		}

		// Nothing on sale: regular range equals live range. Suppress
		// the strikethrough field — same rule as non-bundle path.
		if ( $reg_min === $live_min && $reg_max === $live_max ) {
			return null;
		}

		// `$reg_max` was normalized to `$reg_min` above when null, so
		// it's guaranteed int here.
		return [
			'min' => [
				'amount'   => $reg_min,
				'currency' => $currency,
			],
			'max' => [
				'amount'   => $reg_max,
				'currency' => $currency,
			],
		];
	}

	/**
	 * Build the `metadata.bundle` structure exposing bundle config to
	 * agents.
	 *
	 * Shape:
	 *   {
	 *     "min_size": int|null,           // bundle_min_size
	 *     "max_size": int|null,           // bundle_max_size
	 *     "items": [
	 *       {
	 *         "bundled_item_id": int,     // index used by URL params
	 *         "product_id": int,          // child WC product ID
	 *         "quantity_default": int,
	 *         "optional": bool,
	 *         "discount": string|null,    // percent string (e.g. "10")
	 *         "has_default_variation": bool  // override_default_variation_attributes
	 *       },
	 *       ...
	 *     ]
	 *   }
	 *
	 * Lets agents:
	 *   - Render an accurate description ("3-item bundle, 1 optional add-on")
	 *   - Decide whether to show "Configure on merchant site" vs an
	 *     attempt at direct purchase
	 *   - Cross-reference `product_id`s to other catalog entries the
	 *     agent has already cached
	 *
	 * Returns null when the bundled_items array is missing or empty —
	 * a bundle with no children is a misconfiguration on the merchant
	 * side, not something the metadata block should advertise.
	 *
	 * @param  array<string, mixed>      $bundle_data Bundle extension data.
	 * @return array<string, mixed>|null              metadata.bundle shape, or null.
	 */
	private static function extract_bundle_metadata( array $bundle_data ): ?array {
		$items = $bundle_data['bundled_items'] ?? [];
		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}

		$out_items = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$bundled_item_id = (int) ( $item['bundled_item_id'] ?? 0 );
			$product_id      = (int) ( $item['product_id'] ?? 0 );
			// Skip entries with invalid identifiers. A bundled item
			// without a `bundled_item_id` can't be addressed in the
			// add-to-cart URL params (`bundle_quantity_<bid>` etc.),
			// and one without a `product_id` doesn't reference a real
			// child product. Either signals merchant misconfiguration;
			// emitting them as `0` would mislead agents that try to
			// cross-reference the IDs.
			if ( $bundled_item_id <= 0 || $product_id <= 0 ) {
				continue;
			}
			$out_items[] = [
				'bundled_item_id'       => $bundled_item_id,
				'product_id'            => $product_id,
				'quantity_default'      => (int) ( $item['quantity_default'] ?? 1 ),
				'optional'              => (bool) ( $item['optional'] ?? false ),
				'discount'              => '' === (string) ( $item['discount'] ?? '' ) ? null : (string) $item['discount'],
				'has_default_variation' => (bool) ( $item['override_default_variation_attributes'] ?? false ),
			];
		}

		// `bundled_items` was non-empty but every entry was malformed
		// (non-array, or missing bundled_item_id / product_id). Per the
		// docblock contract, return null rather than emitting
		// `metadata.bundle.items: []` — an empty list signals "merchant
		// misconfigured a bundle with no children," which agents
		// shouldn't try to render or describe.
		if ( empty( $out_items ) ) {
			return null;
		}

		return [
			'min_size' => isset( $bundle_data['bundle_min_size'] ) && '' !== (string) $bundle_data['bundle_min_size']
				? (int) $bundle_data['bundle_min_size']
				: null,
			'max_size' => isset( $bundle_data['bundle_max_size'] ) && '' !== (string) $bundle_data['bundle_max_size']
				? (int) $bundle_data['bundle_max_size']
				: null,
			'items'    => $out_items,
		];
	}

	/**
	 * Public entry to bundle data extraction — used by the REST controller
	 * to read the bundle's `extensions.bundles` block once, without
	 * duplicating type-detection or empty-extension fallback logic.
	 *
	 * Returns the same shape as the private `extract_bundle_data()` —
	 * the bundle extension array, or null when the product is not a
	 * bundle or the extension is missing. Pure-function contract: no
	 * WP API calls.
	 *
	 * @param  array<string, mixed>      $wc_product Decoded Store API product response.
	 * @return array<string, mixed>|null
	 */
	public static function read_bundle_data( array $wc_product ): ?array {
		return self::extract_bundle_data( $wc_product );
	}

	/**
	 * Build the URL-query parameter array for a deterministic Product
	 * Bundle's `/checkout/?add-to-cart=BUNDLE&...` continue_url.
	 *
	 * A bundle is *deterministic* when every `bundled_items[i]` satisfies
	 * BOTH:
	 *   1. `optional: false` — the buyer has no opt-in/opt-out toggle.
	 *   2. The child product is `simple`, OR the child is `variable`
	 *      AND the bundle author pre-set the variation via
	 *      `override_default_variation_attributes: true` AND the
	 *      `default_variation_attributes` map covers every attribute
	 *      axis defined on the child.
	 *
	 * For deterministic bundles we can construct a fully-configured
	 * `?add-to-cart=` URL server-side, so the buyer skips PDP
	 * configuration and lands directly on `/checkout/`. The 2-step
	 * URL-handler dance (navigate → handler intercepts → redirect to
	 * /checkout/ with toast) is internal — buyer sees one navigation.
	 *
	 * Returns null when the bundle is configurable. The caller falls
	 * back to the bundle's product permalink + the spec-defined
	 * `field_required` error message (UCP checkout error-handling).
	 *
	 * Lazy I/O: the caller supplies a fetcher callable instead of a
	 * pre-built map. Children are fetched on demand and the iteration
	 * short-circuits on the first disqualifying entry — a 5-item bundle
	 * whose first variable child lacks override defaults will fetch
	 * just one Store API record (not five). The fetcher receives an
	 * `int $product_id` and returns a Store API response array or
	 * null when the lookup fails (which itself is a disqualifier).
	 *
	 * @param  array<string, mixed>                    $bundle_data    Bundle's `extensions.bundles` block.
	 * @param  callable(int): (array<string,mixed>|null) $child_fetcher  Lazy resolver; called with `product_id`.
	 * @return array<string, string>|null                                URL query params keyed for `http_build_query()`,
	 *                                                                   or null when the bundle is configurable.
	 */
	public static function build_bundle_url_query( array $bundle_data, callable $child_fetcher ): ?array {
		$items = $bundle_data['bundled_items'] ?? [];
		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}

		$params      = [];
		$child_cache = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return null;
			}

			$bid = (int) ( $item['bundled_item_id'] ?? 0 );
			if ( $bid <= 0 ) {
				return null;
			}

			// Optional items: configurable. Buyer must decide opt-in.
			if ( ! empty( $item['optional'] ) ) {
				return null;
			}

			$pid = (int) ( $item['product_id'] ?? 0 );
			$qty = (int) ( $item['quantity_default'] ?? 1 );
			if ( $qty < 1 ) {
				$qty = 1;
			}

			$params[ 'bundle_quantity_' . $bid ] = (string) $qty;

			// Resolve child via the supplied fetcher; cache so a bundle
			// that references the same product_id twice doesn't double-fetch.
			if ( ! array_key_exists( $pid, $child_cache ) ) {
				$child_cache[ $pid ] = $child_fetcher( $pid );
			}
			$child = $child_cache[ $pid ];
			if ( ! is_array( $child ) ) {
				// Couldn't resolve the child — can't classify. Conservative
				// fallback to "configurable" so the buyer ends up on the
				// PDP rather than getting a half-configured cart-add.
				return null;
			}

			$child_type = (string) ( $child['type'] ?? '' );

			if ( 'simple' === $child_type ) {
				continue;
			}

			if ( 'variable' === $child_type ) {
				// Bundle author must have pre-set the variation AND that
				// pre-set must cover every attribute axis the child
				// declares. Otherwise the buyer needs to pick something
				// the URL doesn't carry.
				if ( empty( $item['override_default_variation_attributes'] ) ) {
					return null;
				}
				$defaults = $item['default_variation_attributes'] ?? [];
				if ( ! is_array( $defaults ) || empty( $defaults ) ) {
					return null;
				}
				$child_axes = self::axis_slugs_for_variable_child( $child );
				if ( empty( $child_axes ) ) {
					// Variable child with no axes is itself a misconfiguration;
					// don't trust it for deterministic emission.
					return null;
				}

				$default_by_slug = [];
				foreach ( $defaults as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$slug  = strtolower( trim( (string) ( $entry['name'] ?? '' ) ) );
					$value = (string) ( $entry['value'] ?? '' );
					if ( '' === $slug || '' === $value ) {
						continue;
					}
					$default_by_slug[ $slug ] = $value;
				}

				foreach ( $child_axes as $axis_slug ) {
					if ( ! isset( $default_by_slug[ $axis_slug ] ) ) {
						return null; // axis not covered by override
					}
					$params[ 'bundle_attribute_' . $axis_slug . '_' . $bid ] = $default_by_slug[ $axis_slug ];
				}
				continue;
			}

			// Unknown / unsupported child type (grouped, external,
			// subscription, bundle-in-bundle, etc.). Treat as configurable
			// to avoid emitting a URL that may produce a broken cart.
			return null;
		}

		return $params;
	}

	/**
	 * Lower-case attribute axis slugs declared on a variable child product.
	 *
	 * Used by `build_bundle_url_query()` to verify the bundle's
	 * `default_variation_attributes` covers every axis. WC's Store API
	 * exposes attributes as `[{name, taxonomy?, terms[]}, ...]`:
	 *   - `pa_*` taxonomy attributes: slug is the post-`pa_` segment.
	 *   - Inline custom attributes: slug is `sanitize_title( name )` — same
	 *     transform WC uses when building the variation lookup key, so
	 *     names like `Volume (mL)`, `Color & Pattern`, or `Pâte` round-trip
	 *     to the slugs the bundle's `default_variation_attributes` keys
	 *     are stored under.
	 *
	 * @param  array<string, mixed> $wc_child Child product Store API response.
	 * @return array<int, string>             Lowercased axis slug list.
	 */
	private static function axis_slugs_for_variable_child( array $wc_child ): array {
		$attributes = $wc_child['attributes'] ?? [];
		if ( ! is_array( $attributes ) ) {
			return [];
		}

		$slugs = [];
		foreach ( $attributes as $attr ) {
			if ( ! is_array( $attr ) ) {
				continue;
			}
			// `has_variations` is the Store API's flag for "this attribute
			// is a variation axis." Non-variation attributes (e.g.
			// informational facts on a variable product) don't need
			// defaults.
			if ( empty( $attr['has_variations'] ) ) {
				continue;
			}

			$taxonomy = (string) ( $attr['taxonomy'] ?? '' );
			if ( '' !== $taxonomy && str_starts_with( $taxonomy, 'pa_' ) ) {
				// Strip the `pa_` prefix to match the bundle defaults' slug shape.
				$slugs[] = strtolower( substr( $taxonomy, 3 ) );
				continue;
			}

			// Custom inline attribute — match WC's slug transform exactly.
			// `sanitize_title()` strips parens, ampersands, slashes, accents,
			// and other punctuation that a regex-only approximation would
			// retain, so display names like `Volume (mL)` produce `volume-ml`
			// (matching the bundle's stored default slug) rather than
			// `volume-(ml)` (which would never match anything).
			$name = (string) ( $attr['name'] ?? '' );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$slugs[] = sanitize_title( $name );
		}

		return array_values( array_filter( $slugs, static fn( $s ) => '' !== $s ) );
	}
}
