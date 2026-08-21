<?php
/**
 * Product fact resolvers shared across output vocabularies.
 *
 * `WC_AI_Storefront_JsonLd` used to keep stock and Condition resolution as
 * private static methods of its own, which meant only schema.org's JSON-LD
 * output could ever read them. The Open Graph / meta-tags emitter
 * (`WC_AI_Storefront_Meta_Tags`) needs the SAME three-way stock answer and
 * the SAME Condition value, translated into different vocabularies —
 * `og:availability` / `product:condition` instead of schema.org's
 * `InStock` / `NewCondition`. Duplicating the resolution logic per
 * vocabulary would let the two emitters disagree about the same product,
 * so this class holds ONE implementation of each and both emitters
 * translate its neutral output into their own terms.
 *
 * Every method here answers a fact about a `WC_Product`, not a fact about
 * schema.org or Open Graph — vocabulary translation stays with the
 * emitter that needs it.
 *
 * @package WooCommerce_AI_Storefront
 * @since 0.39.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves stock and Condition facts from a WC_Product, vocabulary-neutral.
 */
class WC_AI_Storefront_Product_Facts {

	/**
	 * Attribute slugs that supply a product's Condition, in precedence
	 * order.
	 *
	 * Moved here from `WC_AI_Storefront_JsonLd::CONDITION_ATTRIBUTE_MAP`
	 * (#679) so `collect_condition_candidates()` is reachable from any
	 * emitter. `pa_condition` is the attribute this plugin seeds with
	 * recognised values, so it is authoritative by construction, while a
	 * bare `condition` is the compatibility fallback for a merchant's own
	 * pre-existing custom attribute.
	 *
	 * @var array<string, array{priority: int}>
	 */
	const CONDITION_ATTRIBUTE_MAP = array(
		'pa_condition' => array( 'priority' => 0 ),
		'condition'    => array( 'priority' => 1 ),
	);

	/**
	 * The only Condition values a candidate's text can normalize to.
	 *
	 * Deliberately just these three: schema.org's OfferItemCondition also
	 * has DamagedCondition, but Google ignores it, and a merchant who
	 * picked it would believe they had declared a condition and would
	 * have declared nothing. Keeping the neutral list this narrow is what
	 * lets every vocabulary this class feeds treat the three values as
	 * exhaustive.
	 *
	 * @var string[]
	 */
	const CONDITION_SLUGS = array( 'new', 'refurbished', 'used' );

	/**
	 * Resolve a product's stock state as a stable internal value.
	 *
	 * WC tracks three stock states — `instock`, `outofstock` and
	 * `onbackorder` — but `is_in_stock()` collapses them to a bool that is
	 * TRUE for backorders: it returns `'outofstock' !== stock_status`
	 * passed through the `woocommerce_product_is_in_stock` filter.
	 * Branching on that bool alone reports a backordered variant as
	 * simply "in stock", which contradicts an `inventoryLevel` (JSON-LD)
	 * or quantity (Open Graph) the same offer carries elsewhere: an
	 * oversold variation would report in-stock next to a negative
	 * quantity. Returning `onbackorder` keeps every field fed by this
	 * method telling one story while still marking the variant orderable.
	 *
	 * The out-of-stock branch is checked FIRST and wins outright. Because
	 * `is_in_stock()` runs through that filter, a third party (multi-
	 * warehouse inventory, role-based catalogs, availability windows) can
	 * legitimately force the bool false while `stock_status` still reads
	 * `onbackorder`. Ordering it this way stops that combination from
	 * being reported as a purchasable-sounding backorder — the same
	 * "shopper-facing signal wins on disagreement" principle #662
	 * established for the UCP catalog path, applied here to the
	 * `WC_Product` object directly rather than to Store API payload
	 * shape.
	 *
	 * Semantically equivalent to WC core's own
	 * `WC_Structured_Data::generate_product_data()` — core nests the
	 * backorder ternary inside `if ( is_in_stock() )` where this
	 * early-returns the out-of-stock case. Core has done this since WC
	 * 7.8, so it predates this plugin's declared WC floor and applies to
	 * the parent Offer core builds; this is the equivalent for every
	 * per-variant and Open Graph read built here.
	 *
	 * The status is compared as a literal rather than via
	 * `Automattic\WooCommerce\Enums\ProductStockStatus::ON_BACKORDER`
	 * (which does exist at our WC floor) because the value is frozen
	 * public API — core itself wrote this comparison as a bare
	 * `'onbackorder'` literal through WC 8.x — and a literal keeps the
	 * unit-test doubles free of the `Automattic\WooCommerce\Enums`
	 * namespace.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return string One of `instock`, `outofstock`, `onbackorder` — WC's
	 *                own stock-status vocabulary, not a schema.org or
	 *                Open Graph term.
	 */
	public static function stock_state( $product ): string {
		if ( ! $product->is_in_stock() ) {
			return 'outofstock';
		}
		return 'onbackorder' === $product->get_stock_status() ? 'onbackorder' : 'instock';
	}

	/**
	 * Collect Condition candidates from a product's visible attributes.
	 *
	 * Applies the same three filters `WC_AI_Storefront_JsonLd::emit_attributes()`
	 * applies — visible, not a variation axis, non-empty — so every
	 * emitter that calls this sees the same candidate set JSON-LD's own
	 * additionalProperty bookkeeping sees. Variation axes are excluded
	 * because the parent has no single value for them; a per-variation
	 * read is each emitter's own concern.
	 *
	 * Moved here from `WC_AI_Storefront_JsonLd::collect_condition_candidates()`
	 * (#679). Uses its own `variation_attribute_slugs()` rather than
	 * reaching back into `WC_AI_Storefront_JsonLd` for the equivalent
	 * helper — a neutral product-facts class depending on the JSON-LD
	 * emitter would invert the dependency this extraction exists to fix.
	 *
	 * @param WC_Product $product The product.
	 * @return array<int, array{slug: string, value: string, priority: int}>
	 */
	public static function collect_condition_candidates( $product ): array {
		$attributes = $product->get_attributes();
		if ( empty( $attributes ) ) {
			// Bail before touching get_variation_attributes() — most
			// products have no attributes at all, and this method now
			// runs for every one of them.
			return array();
		}

		// Resolved lazily, only once a Condition attribute is actually
		// present — the lookup is only needed to exclude variation axes.
		$variation_attrs = null;
		$candidates      = array();

		foreach ( $attributes as $attribute ) {
			if ( ! $attribute->get_visible() ) {
				continue;
			}
			$slug = strtolower( $attribute->get_name() );
			if ( ! isset( self::CONDITION_ATTRIBUTE_MAP[ $slug ] ) ) {
				continue;
			}
			if ( null === $variation_attrs ) {
				$variation_attrs = self::variation_attribute_slugs( $product );
			}
			if ( in_array( $slug, $variation_attrs, true ) ) {
				continue;
			}
			$value = trim( (string) $product->get_attribute( $attribute->get_name() ) );
			if ( '' === $value ) {
				continue;
			}
			$candidates[] = array(
				'slug'     => $slug,
				'value'    => $value,
				'priority' => self::CONDITION_ATTRIBUTE_MAP[ $slug ]['priority'],
			);
		}

		return $candidates;
	}

	/**
	 * Pick the winning Condition candidate and normalize it to a neutral
	 * slug.
	 *
	 * Lowest priority number wins, and a `pa_` value that cannot be typed
	 * falls through to the next candidate rather than blocking resolution
	 * — the same rule JSON-LD's audience fields use.
	 *
	 * Returns the neutral condition value (`new` / `refurbished` /
	 * `used`), never a schema.org URL or an Open Graph token — see this
	 * class's docblock for why vocabulary translation stays with the
	 * caller. `slug` in the return value is the WINNING ATTRIBUTE's slug
	 * (e.g. `pa_condition`), kept because `WC_AI_Storefront_JsonLd` needs
	 * it to decide whether that attribute also belongs in
	 * `additionalProperty`; a caller that only wants the condition value
	 * can use {@see condition_slug()} instead.
	 *
	 * Moved here from `WC_AI_Storefront_JsonLd::resolve_condition()`
	 * (#679).
	 *
	 * @param array<int, array{slug: string, value: string, priority: int}> $candidates Collected candidates.
	 * @return array{slug: string, condition: string} Empty strings when nothing types.
	 */
	public static function resolve_condition( array $candidates ): array {
		usort(
			$candidates,
			static fn( $a, $b ) => $a['priority'] <=> $b['priority']
		);
		foreach ( $candidates as $candidate ) {
			$key = strtolower( trim( $candidate['value'] ) );
			if ( in_array( $key, self::CONDITION_SLUGS, true ) ) {
				return array(
					'slug'      => $candidate['slug'],
					'condition' => $key,
				);
			}
			// Unrecognised, or multi-value. WooCommerce joins TAXONOMY
			// terms with ', ' and CUSTOM attribute values with ' | '
			// (WC_DELIMITER) — do not assume a comma if this is ever
			// split. Falls through to the next candidate.
		}
		return array(
			'slug'      => '',
			'condition' => '',
		);
	}

	/**
	 * A product's Condition, collected and resolved in one call.
	 *
	 * The entry point Open Graph (and any future vocabulary) should call:
	 * given a product, the recognised Condition value, or '' when nothing
	 * types. Wraps {@see collect_condition_candidates()} and
	 * {@see resolve_condition()} so a caller that only wants the neutral
	 * value never has to know about the attribute-slug bookkeeping
	 * `resolve_condition()` returns for JSON-LD's benefit.
	 *
	 * @param WC_Product $product The product.
	 * @return string One of `new`, `refurbished`, `used`, or '' when the
	 *                product carries no recognised Condition value.
	 */
	public static function condition_slug( $product ): string {
		return self::resolve_condition( self::collect_condition_candidates( $product ) )['condition'];
	}

	/**
	 * Returns the lowercased slugs of attributes that drive variations on
	 * this product. Empty array for non-variable products.
	 *
	 * Own copy of the equivalent private helper on `WC_AI_Storefront_JsonLd`
	 * rather than a shared one: JSON-LD's copy also serves attribute
	 * families this class does not resolve (audience, core typed
	 * properties), inside `emit_attributes()` — the largest, most
	 * sensitive method in that file. Reaching into it from here, or
	 * pulling it out from under it, would touch that method for a change
	 * this task does not need. Both copies are a direct, five-line
	 * `WC_Product` API wrapper with no business policy of its own, so the
	 * duplication is not the kind #679 exists to eliminate — see this
	 * class's docblock.
	 *
	 * @param WC_Product $product The product object.
	 * @return string[]
	 */
	private static function variation_attribute_slugs( $product ): array {
		// `get_variation_attributes()` is defined on `WC_Product_Variable`,
		// not the `WC_Product` base — calling it unconditionally fatals
		// on simple/grouped/external products.
		if ( ! method_exists( $product, 'get_variation_attributes' ) ) {
			return array();
		}
		return array_map(
			'strtolower',
			array_keys( $product->get_variation_attributes() )
		);
	}
}
