<?php
/**
 * AI Syndication: Enhanced JSON-LD
 *
 * Outputs deep semantic Schema.org Product markup on product pages
 * so AI agents can recommend products for specific use cases.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enhances WooCommerce JSON-LD output with AI-optimized structured data.
 */
class WC_AI_Storefront_JsonLd {

	/**
	 * Cached WooCommerce weight unit code for this request.
	 *
	 * @var string|null
	 */
	private $weight_unit_code_cache = null;

	/**
	 * Cached WooCommerce dimension unit code for this request.
	 *
	 * @var string|null
	 */
	private $dimension_unit_code_cache = null;

	/**
	 * Per-request cache for the free-shipping lookup keyed by country code.
	 * `true` = unconditional free shipping found, `false` = not found.
	 *
	 * @var array<string, bool>
	 */
	private array $free_shipping_cache = array();

	/**
	 * Maps WC attribute slugs to their typed Schema.org Product properties.
	 * Schema.org's directive — "use specific schema.org properties when they
	 * exist" — supersedes the generic additionalProperty fallback for these.
	 * All targets are `Text`-typed per spec; multi-value inputs skip emission
	 * entirely (no honest single-value claim available) and fall back to
	 * additionalProperty. See #327.
	 */
	private const CORE_ATTRIBUTE_MAP = array(
		'pa_color'    => 'color',
		'color'       => 'color',
		'pa_colour'   => 'color',
		'colour'      => 'color',
		'pa_size'     => 'size',
		'size'        => 'size',
		'pa_material' => 'material',
		'material'    => 'material',
		'pa_pattern'  => 'pattern',
		'pattern'     => 'pattern',
	);

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_structured_data_product', [ $this, 'enhance_product_data' ], 20, 2 );
		add_action( 'wp_head', [ $this, 'output_store_jsonld' ], 5 );
	}

	/**
	 * Enhance WooCommerce product JSON-LD with AI-optimized fields.
	 *
	 * @param array      $markup  Existing product structured data.
	 * @param WC_Product $product The product object.
	 * @return array Enhanced markup.
	 */
	public function enhance_product_data( $markup, $product ) {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $markup;
		}

		if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
			return $markup;
		}

		$this->add_buy_action( $markup, $product );

		$this->add_inventory_level( $markup, $product );

		$this->add_category_path( $markup, $product );

		$this->add_dimensions( $markup, $product );

		$this->emit_attributes( $markup, $product );

		$base_location = wc_get_base_location();
		$country       = $base_location['country'] ?? '';

		$this->add_currency( $markup );
		$this->decode_seller_name( $markup );
		$this->add_shipping_details( $markup, $country );
		$this->add_handling_time( $markup, $settings );
		$this->add_return_policy( $markup, $product, $settings, $country );

		/**
		 * Filter the enhanced JSON-LD product data.
		 *
		 * @since 1.0.0
		 * @param array      $markup          The enhanced product structured data.
		 * @param WC_Product $product         The product.
		 * @param array      $settings_subset Minimal safe subset of settings:
		 *                                    `enabled`, `product_selection_mode`,
		 *                                    `return_policy`. Security-sensitive
		 *                                    fields (rate limits, access-control
		 *                                    flags, crawler allow-lists) are
		 *                                    intentionally excluded.
		 */
		$settings_subset = array(
			'enabled'                => $settings['enabled'] ?? 'no',
			'product_selection_mode' => $settings['product_selection_mode'] ?? 'all',
			'return_policy'          => $settings['return_policy'] ?? array(),
		);
		return apply_filters( 'wc_ai_storefront_jsonld_product', $markup, $product, $settings_subset );
	}

	/**
	 * Adds a BuyAction potentialAction pointing at the store checkout with
	 * attribution placeholders.
	 *
	 * Canonical UTM shape (0.5.0+): utm_medium=referral is Google-canonical;
	 * utm_id=woo_ucp flags AI-routed traffic via the constant so a future
	 * rename stays consistent with the attribution matcher.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function add_buy_action( array &$markup, $product ): void {
		$markup['potentialAction'] = array(
			'@type'  => 'BuyAction',
			'target' => array(
				'@type'          => 'EntryPoint',
				'urlTemplate'    => add_query_arg(
					array(
						'add-to-cart'   => $product->get_id(),
						'utm_source'    => '{agent_id}',
						'utm_medium'    => 'referral',
						'utm_id'        => WC_AI_Storefront_Attribution::WOO_UCP_ID,
						'ai_session_id' => '{session_id}',
					),
					$product->get_permalink()
				),
				'actionPlatform' => array(
					'https://schema.org/DesktopWebPlatform',
					'https://schema.org/MobileWebPlatform',
				),
			),
		);
	}

	/**
	 * Adds inventoryLevel to offers[0] when the product manages stock.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function add_inventory_level( array &$markup, $product ): void {
		if ( ! $product->managing_stock() ) {
			return;
		}
		$stock_qty = $product->get_stock_quantity();
		if (
			null !== $stock_qty
			&& isset( $markup['offers'][0] )
			&& is_array( $markup['offers'][0] )
		) {
			$markup['offers'][0]['inventoryLevel'] = array(
				'@type' => 'QuantitativeValue',
				'value' => $stock_qty,
			);
		}
	}

	/**
	 * Adds the primary category breadcrumb path to $markup['category'].
	 *
	 * Primes the term object cache for all category IDs and their ancestors
	 * before the path-building loop so each get_term() call is a cache hit
	 * rather than a separate DB query.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function add_category_path( array &$markup, $product ): void {
		$categories = wc_get_product_cat_ids( $product->get_id() );
		if ( empty( $categories ) ) {
			return;
		}

		$all_term_ids = array();
		foreach ( $categories as $cat_id ) {
			$all_term_ids[] = $cat_id;
			$ancestors      = get_ancestors( $cat_id, 'product_cat', 'taxonomy' );
			foreach ( $ancestors as $ancestor_id ) {
				$all_term_ids[] = $ancestor_id;
			}
		}
		if ( ! empty( $all_term_ids ) ) {
			_prime_term_caches( array_unique( $all_term_ids ) );
		}

		$cat_paths = array();
		foreach ( $categories as $cat_id ) {
			$ancestors = get_ancestors( $cat_id, 'product_cat', 'taxonomy' );
			$path      = array();
			foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$path[] = $ancestor->name;
				}
			}
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$path[]      = $term->name;
				$cat_paths[] = implode( ' > ', $path );
			}
		}
		if ( ! empty( $cat_paths ) ) {
			$markup['category'] = $cat_paths[0];
		}
	}

	/**
	 * Adds weight and depth/width/height QuantitativeValue blocks.
	 *
	 * Casts weight through (float) to produce a canonical numeric value —
	 * WC persists weight as a free-form string (e.g. `.5`) that strict
	 * JSON-LD parsers would see as a quoted string literal. Audit bug #4.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function add_dimensions( array &$markup, $product ): void {
		if ( $product->has_weight() ) {
			$markup['weight'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $product->get_weight(),
				'unitCode' => $this->get_weight_unit_code(),
			);
		}

		if ( $product->has_dimensions() ) {
			$dimensions       = $product->get_dimensions( false );
			$dimension_unit   = $this->get_dimension_unit_code();
			$markup['depth']  = array(
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['length'],
				'unitCode' => $dimension_unit,
			);
			$markup['width']  = array(
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['width'],
				'unitCode' => $dimension_unit,
			);
			$markup['height'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['height'],
				'unitCode' => $dimension_unit,
			);
		}
	}

	/**
	 * Emit each visible attribute either as its typed Schema.org property
	 * (color/size/material/pattern) or as an `additionalProperty` entry.
	 * Single pass — one `get_attribute()` lookup per attribute.
	 *
	 * Per-attribute decision tree:
	 *   1. Hidden / variation-defining / empty value → skip entirely.
	 *   2. Maps to a typed property AND value is single-valued AND no
	 *      upstream owner of the typed key → emit as typed property,
	 *      skip additionalProperty for this slug.
	 *   3. Otherwise (unmapped, multi-value, or upstream-owns-typed) →
	 *      emit to additionalProperty.
	 *
	 * Caller control: when an upstream filter has already set the typed
	 * property (e.g. `$markup['color']`), we defer on the typed side AND
	 * still emit the merchant's attribute to `additionalProperty`. This
	 * preserves the merchant's data signal even if it differs from what
	 * upstream chose to claim.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function emit_attributes( array &$markup, $product ): void {
		$attributes = $product->get_attributes();
		if ( empty( $attributes ) ) {
			return;
		}
		$variation_attrs = self::get_variation_attribute_slugs( $product );

		$additional_properties = array();
		foreach ( $attributes as $attribute ) {
			if ( ! $attribute->get_visible() ) {
				continue;
			}
			$slug = strtolower( $attribute->get_name() );
			if ( in_array( $slug, $variation_attrs, true ) ) {
				continue;
			}
			$value = trim( (string) $product->get_attribute( $attribute->get_name() ) );
			if ( '' === $value ) {
				continue;
			}

			if ( isset( self::CORE_ATTRIBUTE_MAP[ $slug ] ) ) {
				$schema_prop   = self::CORE_ATTRIBUTE_MAP[ $slug ];
				$upstream_owns = array_key_exists( $schema_prop, $markup );
				// Multi-value detection: either WC delimiter present means
				// the value can't be honestly carried by a Text-typed
				// property — fall back to additionalProperty.
				$is_multi_value = false !== strpbrk( $value, '|,' );

				if ( ! $is_multi_value && ! $upstream_owns ) {
					$markup[ $schema_prop ] = $value;
					continue;
				}
				// Multi-value or upstream-owns: fall through to
				// additionalProperty so the merchant's data still reaches
				// agents in some form.
			}

			$additional_properties[] = array(
				'@type' => 'PropertyValue',
				'name'  => wc_attribute_label( $attribute->get_name(), $product ),
				'value' => $value,
			);
		}
		if ( ! empty( $additional_properties ) ) {
			// Merge with any pre-existing entries (WC core or another
			// plugin filtered `woocommerce_structured_data_product` and
			// added their own). Schema.org allows `additionalProperty`
			// as a single value or an array; normalize to array form
			// before merging.
			$existing = $markup['additionalProperty'] ?? array();
			if ( ! is_array( $existing ) || ! array_is_list( $existing ) ) {
				$existing = array( $existing );
			}
			$markup['additionalProperty'] = array_merge( $existing, $additional_properties );
		}
	}

	/**
	 * Returns the lowercased slugs of attributes that drive variations on
	 * this product. Empty array for non-variable products. Lowercasing
	 * matches the case-insensitive comparison the caller uses against
	 * `$attribute->get_name()`.
	 *
	 * @param WC_Product $product The product object.
	 * @return string[]
	 */
	private static function get_variation_attribute_slugs( $product ): array {
		// `get_variation_attributes()` is defined on `WC_Product_Variable`,
		// not the `WC_Product` base — calling it unconditionally fatals
		// on simple/grouped/external products. `method_exists()` is the
		// right capability gate: true for `WC_Product_Variable` and any
		// subclass (variable-subscription, variable-bundle, etc.), false
		// for everyone else. This catches the extension product types
		// that an `is_type('variable')` string-comparison gate would miss.
		if ( ! method_exists( $product, 'get_variation_attributes' ) ) {
			return array();
		}
		return array_map(
			'strtolower',
			array_keys( $product->get_variation_attributes() )
		);
	}

	/**
	 * Hoists priceCurrency from priceSpecification[0] to the outer Offer level.
	 *
	 * WC core writes priceCurrency under priceSpecification[0]. Google and
	 * Schema.org consumers prefer it at the outer Offer level. We copy it up
	 * without overwriting an existing top-level value. Audit bug #5.
	 *
	 * @param array $markup Markup array, modified by reference.
	 */
	private function add_currency( array &$markup ): void {
		if ( ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}
		$nested_currency = null;
		if (
			isset( $markup['offers'][0]['priceSpecification'] ) &&
			is_array( $markup['offers'][0]['priceSpecification'] ) &&
			isset( $markup['offers'][0]['priceSpecification'][0] ) &&
			is_array( $markup['offers'][0]['priceSpecification'][0] )
		) {
			$nested_currency = $markup['offers'][0]['priceSpecification'][0]['priceCurrency'] ?? null;
		}
		if ( null !== $nested_currency && ! isset( $markup['offers'][0]['priceCurrency'] ) ) {
			$markup['offers'][0]['priceCurrency'] = $nested_currency;
		}
	}

	/**
	 * Fixes double-encoded HTML entities in the seller name field.
	 *
	 * WC core runs esc_html() on the store name, but the value sometimes
	 * arrives already encoded, producing `&amp;#039;` for an apostrophe.
	 * Two html_entity_decode() passes resolve the nesting. Idempotent for
	 * clean input. Audit bug #3.
	 *
	 * @param array $markup Markup array, modified by reference.
	 */
	private function decode_seller_name( array &$markup ): void {
		if ( ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}
		if ( ! isset( $markup['offers'][0]['seller']['name'] ) || ! is_string( $markup['offers'][0]['seller']['name'] ) ) {
			return;
		}
		$decoded                               = html_entity_decode( $markup['offers'][0]['seller']['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$markup['offers'][0]['seller']['name'] = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Adds shippingDetails to offers[0] when a store country is known.
	 *
	 * A DefinedRegion without addressCountry is meaningless — no emission
	 * when $country is empty.
	 *
	 * Emits shippingRate (value: 0) when an unconditionally free shipping
	 * method (WC_Shipping_Free_Shipping with requires === '') exists in any
	 * zone that covers the store's base country or has no location
	 * restrictions (Rest of World zone). Threshold-gated free shipping
	 * (requires: 'min_amount') is intentionally excluded — it is not
	 * unconditionally free.
	 *
	 * @param array  $markup  Markup array, modified by reference.
	 * @param string $country ISO country code from the WC store base location.
	 */
	private function add_shipping_details( array &$markup, string $country ): void {
		if ( ! $country || ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}

		$block = array(
			'@type'               => 'OfferShippingDetails',
			'shippingDestination' => array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => $country,
			),
		);

		if ( $this->has_unconditional_free_shipping( $country ) ) {
			$block['shippingRate'] = array(
				'@type'    => 'MonetaryAmount',
				'value'    => 0,
				'currency' => get_woocommerce_currency(),
			);
		}

		$markup['offers'][0]['shippingDetails'] = $block;
	}

	/**
	 * Returns true when an unconditionally free shipping method exists for
	 * the given country. Result is cached per country for the request
	 * lifetime so archive pages with many products don't re-walk zones.
	 *
	 * @param string $country ISO country code.
	 * @return bool
	 */
	private function has_unconditional_free_shipping( string $country ): bool {
		if ( array_key_exists( $country, $this->free_shipping_cache ) ) {
			return $this->free_shipping_cache[ $country ];
		}

		$found = false;
		foreach ( $this->get_shipping_zones() as $zone ) {
			if ( ! ( $zone instanceof WC_Shipping_Zone ) ) {
				continue;
			}
			if ( ! $this->zone_covers_country( $zone, $country ) ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if (
					$method instanceof WC_Shipping_Free_Shipping
					&& '' === $method->requires
				) {
					$found = true;
					break 2;
				}
			}
		}

		$this->free_shipping_cache[ $country ] = $found;
		return $found;
	}

	/**
	 * Returns true when the zone covers the given country or has no
	 * location restrictions (Rest of World zone).
	 *
	 * Known gap: continent-type locations (type === 'continent') are not
	 * matched. A continent zone whose continent contains the store country
	 * would be skipped here. Continent matching requires resolving the
	 * country to its WC continent code via WC_Countries and is left for
	 * a follow-up.
	 *
	 * @param WC_Shipping_Zone $zone    Shipping zone.
	 * @param string           $country ISO country code.
	 * @return bool
	 */
	private function zone_covers_country( WC_Shipping_Zone $zone, string $country ): bool {
		$locations = $zone->get_zone_locations();
		if ( empty( $locations ) ) {
			return true; // Rest of World — covers everything.
		}
		foreach ( $locations as $location ) {
			if ( 'country' === $location->type && $country === $location->code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns all shipping zones including the Rest of World zone (id 0).
	 *
	 * Extracted as a protected method so tests can override it without
	 * needing to call the static WC_Shipping_Zones API.
	 *
	 * @return WC_Shipping_Zone[]
	 */
	protected function get_shipping_zones(): array {
		// `get_shipping_zones()` returns WC_Shipping_Zone objects keyed by id.
		// `get_zones()` returns data arrays (used by the admin UI) and must NOT
		// be used here — those arrays fail `instanceof WC_Shipping_Zone`.
		$zones   = array_values( WC_Shipping_Zones::get_shipping_zones() );
		$zones[] = new WC_Shipping_Zone( 0 ); // Rest of World.
		return $zones;
	}

	/**
	 * Enriches an existing shippingDetails block with a handlingTime QuantitativeValue.
	 *
	 * Requires that `add_shipping_details()` has already placed
	 * `offers[0]['shippingDetails']`. If that key is absent (e.g. no
	 * store country set), this method is a no-op. Emits nothing when
	 * either min or max is 0 (unconfigured) or when min > max (invalid pair).
	 *
	 * @param array $markup   Markup array, modified by reference.
	 * @param array $settings Full plugin settings array.
	 */
	private function add_handling_time( array &$markup, array $settings ): void {
		if (
			! isset( $markup['offers'][0] ) ||
			! is_array( $markup['offers'][0] ) ||
			! isset( $markup['offers'][0]['shippingDetails'] )
		) {
			return;
		}

		$ht  = $settings['handling_time'] ?? [];
		$min = isset( $ht['min'] ) ? (int) $ht['min'] : 0;
		$max = isset( $ht['max'] ) ? (int) $ht['max'] : 0;

		if ( $min <= 0 || $max <= 0 || $min > $max ) {
			return;
		}

		$markup['offers'][0]['shippingDetails']['deliveryTime'] = array(
			'@type'        => 'ShippingDeliveryTime',
			'handlingTime' => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => $min,
				'maxValue' => $max,
				'unitCode' => 'DAY',
			),
		);
	}

	/**
	 * Adds hasMerchantReturnPolicy to offers[0] from the saved policy settings.
	 *
	 * Resolves the per-product final-sale override product ID (variations
	 * inherit from their parent) and delegates block construction to
	 * build_return_policy_block(). Emits nothing when that method returns null.
	 *
	 * @param array      $markup   Markup array, modified by reference.
	 * @param WC_Product $product  The product object.
	 * @param array      $settings Full plugin settings array.
	 * @param string     $country  ISO country code from the WC store base location.
	 */
	private function add_return_policy( array &$markup, $product, array $settings, string $country ): void {
		if ( ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}
		$policy = isset( $settings['return_policy'] ) && is_array( $settings['return_policy'] )
			? $settings['return_policy']
			: array( 'mode' => 'unconfigured' );
		// Resolve per-product override scope. Variations inherit from their
		// parent — use wp_get_post_parent_id() (vs WC_Product::get_parent_id)
		// to avoid a PHPStan stubs gap in the pinned woocommerce-stubs version.
		$policy_product_id = null;
		if ( $product instanceof WC_Product ) {
			$parent_id         = wp_get_post_parent_id( $product->get_id() );
			$policy_product_id = $parent_id > 0 ? $parent_id : $product->get_id();
		}
		$policy_block = $this->build_return_policy_block( $policy, $country, $policy_product_id );
		if ( null !== $policy_block ) {
			$markup['offers'][0]['hasMerchantReturnPolicy'] = $policy_block;
		}
	}

	/**
	 * Output store-level JSON-LD on the homepage/shop page.
	 *
	 * `@type: OnlineStore` (an `Organization` subtype). Previously
	 * `Store` which extends `LocalBusiness`/`Place` and is not an
	 * `Organization`. The switch satisfies AI-readiness audits that
	 * look specifically for an `Organization`-shaped entity to verify
	 * brand identity. `OnlineStore` is the most accurate type for the
	 * merchant — they're definitionally an online retailer — and
	 * inherits all the descriptive fields (`name`, `url`,
	 * `description`) that `Store` carried. The `potentialAction` and
	 * `hasOfferCatalog` blocks are valid on `OnlineStore` exactly as
	 * they were on `Store`, so existing crawlers parsing those keys
	 * see no change.
	 *
	 * Brand identity fields (`logo`, `address`, `contactPoint`) are
	 * appended via `build_identity_fields()` with omit-when-empty
	 * semantics. The fields are auto-sourced from existing WP/WC data
	 * (custom-logo theme mod, `WC()->countries->get_base_*`, WC sender
	 * email options) — no merchant settings, no admin UI. An
	 * unconfigured merchant publishes the same `name + url +
	 * description + currency + search + catalog` shape they did
	 * before, plus the new `@type`. A merchant whose underlying
	 * WP/WC data is filled in gets the additional keys.
	 *
	 * `sameAs` (social profiles) and `contactPoint.telephone` are NOT
	 * emitted from this method — neither has a canonical WP/WC source
	 * today. Ecosystem plugins (Jetpack, Yoast, etc.) that capture
	 * those fields can inject them via the `wc_ai_storefront_jsonld_store`
	 * filter applied below; see the filter docblock for an example.
	 */
	public function output_store_jsonld() {
		if ( ! is_front_page() && ! is_shop() ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}

		$store_data = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'OnlineStore',
			'name'               => get_bloginfo( 'name' ),
			'description'        => get_bloginfo( 'description' ),
			'url'                => home_url( '/' ),
			'currenciesAccepted' => get_woocommerce_currency(),
			'potentialAction'    => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					// Canonical UTM shape (0.5.0+) — see BuyAction
					// urlTemplate above for rationale. The `utm_id`
					// value comes from the constant rather than the
					// literal string for the same drift-prevention
					// reason documented at the BuyAction emit site.
					'urlTemplate' => home_url(
						'/?s={search_term}&post_type=product&utm_source={agent_id}&utm_medium=referral&utm_id=' . WC_AI_Storefront_Attribution::WOO_UCP_ID
					),
				),
				'query-input' => 'required name=search_term',
			),
			'hasOfferCatalog'    => array(
				'@type'           => 'OfferCatalog',
				'name'            => __( 'Products', 'woocommerce-ai-storefront' ),
				'itemListElement' => $this->get_catalog_summary(),
			),
		);

		// Merge identity fields after the base shape so they sit at
		// the end of the JSON-LD output — easier for crawlers tailing
		// for `logo` / `address` / `contactPoint` to find them, and
		// keeps the static base fields (`@context` through
		// `hasOfferCatalog`) at the top of the script tag where most
		// agents focus their parsing budget.
		$identity_fields = $this->build_identity_fields();
		if ( ! empty( $identity_fields ) ) {
			$store_data = array_merge( $store_data, $identity_fields );
		}

		/**
		 * Filter the store-level JSON-LD data.
		 *
		 * Plugins that capture social profile URLs (Jetpack, Yoast,
		 * etc.) can inject `sameAs` here without the plugin owning a
		 * UI for it:
		 *
		 *     add_filter( 'wc_ai_storefront_jsonld_store', function( $data ) {
		 *         $profiles = jetpack_get_social_profiles(); // hypothetical
		 *         if ( ! empty( $profiles ) ) {
		 *             $data['sameAs'] = array_values( $profiles );
		 *         }
		 *         return $data;
		 *     } );
		 *
		 * Same hook works for `contactPoint.telephone` and any other
		 * Schema.org Organization field a plugin in the ecosystem
		 * already captures.
		 *
		 * @since 1.0.0
		 * @param array $store_data      The store structured data.
		 * @param array $settings_subset Minimal safe subset of settings:
		 *                               `enabled`, `product_selection_mode`,
		 *                               `return_policy`. Security-sensitive
		 *                               fields (rate limits, access-control
		 *                               flags, crawler allow-lists) are
		 *                               intentionally excluded.
		 */
		$settings_subset = array(
			'enabled'                => $settings['enabled'] ?? 'no',
			'product_selection_mode' => $settings['product_selection_mode'] ?? 'all',
			'return_policy'          => $settings['return_policy'] ?? array(),
		);
		$store_data      = apply_filters( 'wc_ai_storefront_jsonld_store', $store_data, $settings_subset );

		// `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
		// over the previous `JSON_UNESCAPED_SLASHES` flag: ensures
		// `<`, `>`, `&`, `'`, `"` in any string field serialize as
		// Unicode escape sequences (`\u003C`, `\u003E`, `\u0026`,
		// `\u0027`, `\u0022`),
		// which closes the `</script>` breakout class — a category
		// name like `</script><script>alert(1)</script>` (creatable
		// by any user with `manage_categories`, typically Editor role)
		// would otherwise survive `JSON_UNESCAPED_SLASHES` and break
		// out of the JSON-LD script-tag CDATA context. The HEX flags
		// also pre-emptively close adjacent injection vectors
		// (attribute-breakout via quotes, comment injection via `&`).
		// `JSON_UNESCAPED_UNICODE` retained so non-ASCII strings
		// (international product / brand / description text) don't
		// bloat into `\uXXXX` sequences. Schema.org parsers and
		// Google's structured-data validator handle hex-escaped
		// characters correctly per the JSON spec.
		echo '<script type="application/ld+json">' . wp_json_encode( $store_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/**
	 * Build the brand-identity sub-fields appended to the homepage
	 * `OnlineStore` JSON-LD. Three fields, all auto-sourced from
	 * existing WP/WC data — no plugin-owned merchant settings, no
	 * admin UI, no new options.
	 *
	 * Each field follows omit-when-empty semantics so an unconfigured
	 * merchant publishes none of these keys:
	 *
	 *   - `logo`: resolved from the WP custom-logo theme mod first
	 *     (more visually intentional — the merchant explicitly chose
	 *     a brand mark for the storefront), with site-icon as fallback
	 *     (a pre-WC-era favicon-style asset, less brand-shaped but
	 *     still better than nothing). Omitted entirely when neither
	 *     is set — Schema.org's `logo` field is meant to carry the
	 *     merchant's primary brand mark, and emitting a default WP
	 *     favicon URL would mislead crawlers about brand identity.
	 *
	 *   - `address`: `PostalAddress` block built from
	 *     `WC()->countries->get_base_*` (the source of truth WC
	 *     populates from the WooCommerce > Settings > General
	 *     "Store Address" form). `addressLocality`, `postalCode`,
	 *     `addressRegion`, and `addressCountry` are emitted only when
	 *     WC has a non-empty value; the whole block is omitted when
	 *     WC has no country configured (the minimum viable address
	 *     signal). Auto-sourcing means there's nothing to maintain
	 *     inside this plugin — when the merchant updates their WC
	 *     store address, the JSON-LD picks up the change on the next
	 *     homepage load.
	 *
	 *     `streetAddress` is intentionally suppressed even when WC
	 *     has it. For an `OnlineStore` (vs. a `LocalBusiness`), the
	 *     street address adds little verification value — buyers
	 *     don't visit — but the privacy/safety risk is real: many
	 *     small Woo merchants populate the WC base address with their
	 *     home address and don't realize saving that field publishes
	 *     it in machine-readable form on the homepage. City + region
	 *     + postcode + country preserve every meaningful signal
	 *     (jurisdiction, shipping origin, fraud-check
	 *     disambiguation) without the residential-address leak. See
	 *     `build_postal_address()` for the suppression detail.
	 *
	 *   - `contactPoint.email`: two-stage resolution that mirrors how
	 *     WC itself decides where customer replies should land:
	 *
	 *       1. `woocommerce_email_reply_to_address` when
	 *          `woocommerce_email_reply_to_enabled === 'yes'`. This is
	 *          WC's purpose-built "where customers should reach me"
	 *          field, set explicitly when the merchant wants replies
	 *          routed somewhere other than the From address.
	 *       2. `woocommerce_email_from_address` as a fallback, *but*
	 *          rejected when its local-part matches a noreply pattern
	 *          (`noreply@`, `no-reply@`, `donotreply@`,
	 *          `do-not-reply@`, case-insensitive). Many merchants set
	 *          From to a noreply address to avoid bounce-handling;
	 *          publishing that as a customer-facing contact would
	 *          route real questions into a black hole.
	 *
	 *     Each candidate is validated via `is_email` before being
	 *     accepted. If neither stage produces a usable address, the
	 *     whole `contactPoint` block is omitted. We deliberately do
	 *     NOT fall back to `admin_email` — admin email is
	 *     intentionally private (password resets, security
	 *     notifications) and merchants do not expect it to be
	 *     published in JSON-LD.
	 *
	 * Phone (`contactPoint.telephone`) and social profiles (`sameAs`)
	 * are intentionally NOT emitted from this method. Neither has a
	 * canonical WP/WC source today, and ecosystem plugins (Jetpack,
	 * Yoast, etc.) already capture them via their own settings. The
	 * `wc_ai_storefront_jsonld_store` filter is the documented
	 * injection point — see the filter docblock at the call site for
	 * an example.
	 *
	 * @return array Identity fields, possibly empty when nothing is
	 *               configured (no logo, no WC address, no sender
	 *               email).
	 */
	private function build_identity_fields(): array {
		$fields = array();

		// `logo` — prefer custom-logo theme mod over site-icon. The
		// custom-logo flow is brand-intentional (merchant uploaded a
		// logo for the storefront header); site-icon is a smaller
		// favicon-shaped asset commonly used for browser tabs. Either
		// is acceptable Schema.org `logo` content, but the brand mark
		// is the more honest signal when present.
		$logo_url = '';
		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$logo_src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( is_array( $logo_src ) && ! empty( $logo_src[0] ) ) {
				$logo_url = (string) $logo_src[0];
			}
		}
		if ( '' === $logo_url ) {
			$site_icon = get_site_icon_url();
			if ( is_string( $site_icon ) && '' !== $site_icon ) {
				$logo_url = $site_icon;
			}
		}
		if ( '' !== $logo_url ) {
			$fields['logo'] = $logo_url;
		}

		// `address` — auto-sourced from the WC base address. Helper
		// is `protected` (rather than inlined) so unit tests can
		// subclass with a fixture instead of mocking `WC()` globally,
		// which Brain Monkey would leak across the suite. See the
		// `build_postal_address()` docblock for the test-seam detail.
		$address = $this->build_postal_address();
		if ( ! empty( $address ) ) {
			$fields['address'] = $address;
		}

		// `contactPoint.email` — two-stage resolution: WC's reply-to
		// address (when enabled), then From (when not noreply-shaped).
		// See `get_validated_contact_email()` for the precedence
		// rationale. NEVER falls back to `admin_email` (private).
		$email = $this->get_validated_contact_email();
		if ( '' !== $email ) {
			$fields['contactPoint'] = array(
				'@type'       => 'ContactPoint',
				'contactType' => 'Customer Service',
				'email'       => $email,
			);
		}

		return $fields;
	}

	/**
	 * Build a Schema.org `PostalAddress` block from WC's base-address
	 * settings. Returns an empty array when WC has no country
	 * configured (the minimum viable address signal — addresses
	 * without a country are crawler-noise).
	 *
	 * Each optional sub-key is omitted when WC has no value, so a
	 * merchant who configured only country + city emits both fields
	 * and skips `streetAddress` / `postalCode` / `addressRegion`
	 * cleanly rather than emitting empty strings.
	 *
	 * Source: `WC()->countries->get_base_*`. These are the same
	 * values WC's "Store Address" form (WooCommerce > Settings >
	 * General) writes into. Reads happen per request — no
	 * computation, no DB query, no cache needed.
	 *
	 * `streetAddress` is intentionally suppressed even when WC has
	 * the `get_base_address()` / `get_base_address_2()` values
	 * populated. For an `OnlineStore` (vs. a `LocalBusiness`) the
	 * field has low signal value — buyers transact remotely and don't
	 * visit the store — but the privacy/safety risk is real. Many
	 * small Woo merchants populate WooCommerce > Settings > General
	 * with their home address (the field is required at WC setup so
	 * tax calculations work) and don't realize that saving it
	 * publishes the address in machine-readable form on the
	 * homepage's JSON-LD. By emitting only `addressLocality`,
	 * `addressRegion`, `postalCode`, and `addressCountry`, we
	 * preserve every meaningful identity signal (jurisdiction,
	 * shipping origin, fraud-check disambiguation) without leaking a
	 * residential street address to AI agents.
	 *
	 * Marked `protected` (rather than `private`) for the same reason
	 * `get_shipping_zones()` above is — unit tests subclass the
	 * emitter and override this method to inject a fixture, avoiding
	 * the need to globally stub `WC()` (which Brain Monkey leaks
	 * into other tests in the suite as `MissingFunctionExpectations`
	 * once registered). The seam is package-internal; the protected
	 * scope keeps it out of the public API while making it test-
	 * accessible.
	 *
	 * @return array<string, string> The PostalAddress block, or [] when
	 *                               WC has no base country configured.
	 */
	protected function build_postal_address(): array {
		$countries = $this->get_wc_countries();
		if ( null === $countries ) {
			return array();
		}

		$country = (string) $countries->get_base_country();
		if ( '' === $country ) {
			return array();
		}

		$address = array(
			'@type'          => 'PostalAddress',
			'addressCountry' => $country,
		);

		// `streetAddress` deliberately omitted — see method docblock for
		// the privacy / online-vs-local-business rationale. Note we do
		// NOT read `get_base_address()` / `get_base_address_2()` at all,
		// so even a future filter that thinks it can re-emit street
		// would have to source it independently.

		$city = (string) $countries->get_base_city();
		if ( '' !== $city ) {
			$address['addressLocality'] = $city;
		}

		$state = (string) $countries->get_base_state();
		if ( '' !== $state ) {
			$address['addressRegion'] = $state;
		}

		$postcode = (string) $countries->get_base_postcode();
		if ( '' !== $postcode ) {
			$address['postalCode'] = $postcode;
		}

		return $address;
	}

	/**
	 * Resolve the live `WC_Countries` instance, or null when WC isn't
	 * loaded (e.g. unit-test environments where `WC()` is undefined).
	 *
	 * Extracted so unit tests can subclass and inject a stub-shaped
	 * object exposing only the `get_base_*()` methods that
	 * `build_postal_address()` reads. This avoids globally stubbing
	 * `WC()` via Brain Monkey, which leaks across the test suite as
	 * `MissingFunctionExpectations` for unrelated tests that call
	 * real `WC()` (UcpTest, UcpCatalogLookupTest, etc.).
	 *
	 * @return object|null `WC_Countries` (or shape-compatible stub),
	 *                     or null when WC isn't available.
	 */
	protected function get_wc_countries() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( ! $woocommerce || ! isset( $woocommerce->countries ) || ! is_object( $woocommerce->countries ) ) {
			return null;
		}
		return $woocommerce->countries;
	}

	/**
	 * Resolve a customer-facing contact email for emit as
	 * `contactPoint.email`. Two-stage resolution mirrors WC's own
	 * "where do customer replies land" logic (see `WC_Email::headers()`
	 * lines ~687 in plugins/woocommerce/includes/emails/class-wc-email.php):
	 *
	 *   1. `woocommerce_email_reply_to_address` when
	 *      `woocommerce_email_reply_to_enabled === 'yes'`. WC's
	 *      purpose-built field for "where customers should reach me",
	 *      set explicitly when the merchant routes replies somewhere
	 *      other than the From address.
	 *
	 *   2. `woocommerce_email_from_address` as a fallback, *but*
	 *      rejected when its local-part matches a noreply pattern.
	 *      Many merchants set From to `noreply@store.com` to avoid
	 *      bounce-handling; publishing that as a customer-facing
	 *      contact would route real questions into a black hole.
	 *
	 * Each candidate is validated via `is_email` before being accepted.
	 * Returns '' when neither stage produces a usable address — the
	 * emitter then omits the whole `contactPoint` block.
	 *
	 * Deliberately does NOT fall back to `admin_email` — admin email
	 * is intentionally private (password resets, security
	 * notifications) and merchants do not expect it to be published
	 * in JSON-LD.
	 *
	 * @return string Validated email address, or '' when missing /
	 *                invalid / noreply-shaped.
	 */
	private function get_validated_contact_email(): string {
		// Stage 1: Reply-to, but only when the merchant explicitly
		// enabled it via WC settings. The 'yes'/'no' string check
		// matches WC's own runtime logic at WC_Email::headers().
		if ( 'yes' === (string) get_option( 'woocommerce_email_reply_to_enabled', 'no' ) ) {
			$reply_to = $this->validate_email_string(
				(string) get_option( 'woocommerce_email_reply_to_address', '' )
			);
			if ( '' !== $reply_to ) {
				return $reply_to;
			}
			// Reply-to enabled but address blank/invalid — fall through
			// to From rather than omit. The merchant clearly intended a
			// public contact channel; the configuration error
			// shouldn't punish the JSON-LD output. From may itself be
			// a noreply, in which case we omit at stage 2.
		}

		// Stage 2: From address, with a noreply-pattern guard so we
		// don't publish a "don't reply to me" address as a public
		// customer-service contact.
		$from_address = $this->validate_email_string(
			(string) get_option( 'woocommerce_email_from_address', '' )
		);
		if ( '' !== $from_address && ! self::is_noreply_email( $from_address ) ) {
			return $from_address;
		}

		return '';
	}

	/**
	 * Sanitize and validate a raw email-string from a WC option.
	 * Returns the valid address or '' when the input is empty,
	 * malformed, or fails `is_email`'s structural check.
	 *
	 * Centralized so both stages of the contact-email resolver use
	 * identical validation rules — a future tightening (e.g. blocking
	 * specific TLDs) only needs to change one method.
	 *
	 * @param string $raw Raw option value.
	 * @return string     Validated email or ''.
	 */
	private function validate_email_string( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		$email = sanitize_email( trim( $raw ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * Detect noreply-shaped local-parts so we don't publish an address
	 * the merchant intends as one-way. Matches the four common shapes
	 * (`noreply`, `no-reply`, `donotreply`, `do-not-reply`) at the
	 * **start** of the local-part, case-insensitive. The match includes
	 * the local-part as a whole (`noreply@store.com`) AND any
	 * RFC 5233 plus-addressed variant (`noreply+orders@store.com`,
	 * `no-reply+tag@store.com`) — both routes deliver to the same
	 * underlying mailbox at most providers, so a `+`-tagged noreply is
	 * still a noreply for publishing purposes.
	 *
	 * Examples that match:
	 *   - `noreply@store.com`
	 *   - `NoReply@store.com` (case-insensitive)
	 *   - `do-not-reply@store.com`
	 *   - `noreply+orders@store.com` (plus-addressing variant)
	 *   - `no-reply+customer-service@store.com`
	 *
	 * Examples that do NOT match:
	 *   - `support@noreply.example.com` — local-part is `support`;
	 *     we only inspect the local-part. Local-part-only matching
	 *     avoids false-positives on legitimate customer-service
	 *     mailboxes that happen to be hosted on a `noreply.*`
	 *     subdomain (rare but not impossible).
	 *   - `noreplies@store.com` — `noreplies` is not in the prefix
	 *     list and doesn't have a `+` separator, so the whole
	 *     local-part is checked against the exact patterns.
	 *   - `notifications@store.com` — we can't infer intent without
	 *     a broader deny-list, and erring on the side of publishing
	 *     legitimate addresses is the right default.
	 *
	 * @param string $email A sanitized, is_email-validated address.
	 * @return bool         True if the local-part starts with a
	 *                      noreply pattern (with optional `+tag`
	 *                      suffix).
	 */
	private static function is_noreply_email( string $email ): bool {
		$at = strpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			// Defensive: is_email already rejected this shape, but if
			// a future caller skips validation, fall through as not-
			// noreply rather than triggering a substring on garbage.
			return false;
		}

		// Strip plus-addressing tag (RFC 5233) so `noreply+orders` and
		// `noreply` are treated identically. Most providers (Gmail,
		// Outlook, Postfix, etc.) route plus-tagged variants to the
		// base local-part's mailbox, so a `+`-tagged noreply is still
		// a noreply for publishing purposes.
		$local         = strtolower( substr( $email, 0, $at ) );
		$plus_position = strpos( $local, '+' );
		if ( false !== $plus_position ) {
			$local = substr( $local, 0, $plus_position );
		}

		return 'noreply' === $local
			|| 'no-reply' === $local
			|| 'donotreply' === $local
			|| 'do-not-reply' === $local;
	}

	/**
	 * Get a catalog summary for JSON-LD.
	 *
	 * Result is cached in a transient for one hour so repeated homepage/shop
	 * page loads don't issue a get_terms() DB query on every request.
	 * Invalidated by WC_AI_Storefront_Cache_Invalidator::invalidate().
	 *
	 * @return array
	 */
	private function get_catalog_summary() {
		$transient_key = 'wc_ai_storefront_catalog_summary';
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'number'     => 10,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		if ( is_wp_error( $categories ) ) {
			return array();
		}

		$items = array();
		foreach ( $categories as $category ) {
			$link = get_term_link( $category );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items[] = array(
				'@type'         => 'OfferCatalog',
				'name'          => $category->name,
				'numberOfItems' => $category->count,
				'url'           => $link,
			);
		}

		set_transient( $transient_key, $items, HOUR_IN_SECONDS );
		return $items;
	}

	/**
	 * Build the `hasMerchantReturnPolicy` structured-data block from
	 * the merchant's saved return-policy settings.
	 *
	 * Three modes:
	 *
	 *   - `unconfigured` → returns `null`. Caller omits the
	 *     `hasMerchantReturnPolicy` field entirely. Removes today's
	 *     structurally invalid emission on every existing install
	 *     until the merchant explicitly opts into one of the modes
	 *     below.
	 *
	 *   - `returns_accepted` → emits a `MerchantReturnPolicy` with
	 *     `applicableCountry`, `returnPolicyCategory` (smart-degrade:
	 *     `MerchantReturnFiniteReturnWindow` + `merchantReturnDays`
	 *     when days > 0; `MerchantReturnUnspecified` otherwise — never
	 *     emit `FiniteReturnWindow` without the days field, which
	 *     Google validators reject), `returnFees`, `merchantReturnLink`
	 *     (only when a published page is configured), and `returnMethod`
	 *     (scalar string when one method is selected, array when
	 *     multiple — Schema.org accepts both forms; cleaner JSON for
	 *     the common single-method case). Returns `null` when `$country`
	 *     is empty: a return-window declaration without a target region
	 *     is not useful to validators or agents.
	 *
	 *   - `final_sale` → emits `MerchantReturnPolicy` with
	 *     `returnPolicyCategory: NotPermitted`. `merchantReturnLink`
	 *     attached when a page is configured (so merchants can link
	 *     to a "no returns" explainer). No `returnFees`/`returnMethod`
	 *     because the policy precludes returns.
	 *
	 * @param array    $policy     Sanitized return-policy settings.
	 * @param string   $country    ISO country code from the WC store base.
	 * @param int|null $product_id Optional product ID for per-product
	 *                             override lookup. When non-null AND the
	 *                             product is flagged final-sale via
	 *                             `WC_AI_Storefront_Product_Meta_Box::is_final_sale()`
	 *                             (which reads
	 *                             `WC_AI_Storefront_Product_Meta_Box::META_KEY` —
	 *                             `_wc_ai_storefront_final_sale`), the
	 *                             store-wide policy is bypassed and a
	 *                             `MerchantReturnNotPermitted` block is
	 *                             emitted regardless of mode. `null`
	 *                             skips the override lookup (used by
	 *                             store-wide preview rendering or unit
	 *                             tests that exercise the store-wide
	 *                             logic in isolation).
	 * @return array<string, mixed>|null Structured-data block, or null when the
	 *                                   policy is `unconfigured`, or when mode is
	 *                                   `returns_accepted` and `$country` is empty
	 *                                   (caller skips emission in all null cases).
	 */
	private function build_return_policy_block( array $policy, string $country, ?int $product_id = null ): ?array {
		// Per-product final-sale override (highest-priority gate). A
		// flagged product emits MerchantReturnNotPermitted regardless
		// of the store-wide mode — including when the store-wide mode
		// is `unconfigured` (the override forces a structured claim
		// even when the merchant otherwise opted out of exposing one).
		// Unflagged products fall through to the store-wide logic
		// below.
		//
		// The override deliberately ignores the store-wide `days` /
		// `fees` / `methods` settings — those describe an
		// accepts-returns posture, which is the exact opposite of
		// what the override declares. Keeping the override block
		// minimal also avoids surprising merchants who flagged a
		// product expecting "no returns" and got an emission that
		// somehow includes a return-window number.
		//
		// `merchantReturnLink` is reused from the store-wide policy
		// page when configured — a "no returns" page typically
		// documents what's covered (defective goods, statutory
		// rights), so reusing the link beats omission. If the
		// merchant hasn't configured a policy page, the override
		// emits the bare-bones block without a link.
		if ( null !== $product_id && WC_AI_Storefront_Product_Meta_Box::is_final_sale( $product_id ) ) {
			// applicableCountry is recommended, not required, for
			// MerchantReturnNotPermitted — omit when the store's base
			// country is unset so the block still emits. Merchants who
			// flag a product final-sale are expressing a clear
			// structured intent; losing the entire block because the
			// store address is missing would silently discard it.
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			);
			if ( '' !== $country ) {
				$block['applicableCountry'] = $country;
			}
			$page_id = isset( $policy['page_id'] ) ? (int) $policy['page_id'] : 0;
			$link    = self::resolve_merchant_return_link( $page_id );
			if ( '' !== $link ) {
				$block['merchantReturnLink'] = $link;
			}
			return $block;
		}

		$mode = $policy['mode'] ?? 'unconfigured';

		if ( 'unconfigured' === $mode ) {
			return null;
		}

		if ( 'final_sale' === $mode ) {
			// Same applicableCountry omission rationale as the
			// per-product override above.
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			);
			if ( '' !== $country ) {
				$block['applicableCountry'] = $country;
			}
			$page_id = isset( $policy['page_id'] ) ? (int) $policy['page_id'] : 0;
			$link    = self::resolve_merchant_return_link( $page_id );
			if ( '' !== $link ) {
				$block['merchantReturnLink'] = $link;
			}
			return $block;
		}

		// Fail closed for any mode the sanitizer doesn't recognize.
		// `get_settings()` doesn't run `return_policy` through the
		// sanitizer on read — a corrupted/legacy/filter-mutated
		// `mode` value would otherwise fall through to the
		// `returns_accepted` branch below and silently emit a
		// returns-accepted policy block. Defense in depth: only
		// emit when the mode is explicitly `returns_accepted`.
		// `unconfigured` and `final_sale` were handled above.
		if ( 'returns_accepted' !== $mode ) {
			return null;
		}

		// Returns-accepted mode requires a country — a return window
		// without a target region is not useful to validators or
		// agents. Return null (same as before this refactor) so the
		// block is omitted when the store address is unset.
		if ( '' === $country ) {
			return null;
		}

		// Mode: returns_accepted.
		$days = isset( $policy['days'] ) ? (int) $policy['days'] : 0;
		if ( $days > 0 ) {
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
				'merchantReturnDays'   => $days,
			);
		} else {
			// Smart-degrade: no days configured → declare Unspecified
			// rather than emit a FiniteReturnWindow without days.
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnUnspecified',
			);
		}

		$page_id = isset( $policy['page_id'] ) ? (int) $policy['page_id'] : 0;
		$link    = self::resolve_merchant_return_link( $page_id );
		if ( '' !== $link ) {
			$block['merchantReturnLink'] = $link;
		}

		// Always emit returnFees (sanitization defaults to FreeReturn
		// when unset). Allow-list validated here at emission time as a
		// second gate — save-time sanitization is the primary defence,
		// but a future DB import or direct option write could bypass it.
		$allowed_fees        = array( 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'OriginalShippingFees', 'RestockingFees' );
		$fees                = isset( $policy['fees'] ) && is_string( $policy['fees'] ) && in_array( $policy['fees'], $allowed_fees, true )
			? $policy['fees']
			: 'FreeReturn';
		$block['returnFees'] = 'https://schema.org/' . $fees;

		// returnMethod: scalar string when 1 method selected, array
		// when 2+, omitted when none. Methods are also allow-list
		// validated at emission time for the same reason as fees above.
		$allowed_methods = array( 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' );
		$methods         = isset( $policy['methods'] ) && is_array( $policy['methods'] )
			? array_values(
				array_unique(
					array_filter( $policy['methods'], static fn( $m ) => in_array( $m, $allowed_methods, true ) )
				)
			)
			: array();
		if ( count( $methods ) === 1 ) {
			$block['returnMethod'] = 'https://schema.org/' . $methods[0];
		} elseif ( count( $methods ) >= 2 ) {
			$block['returnMethod'] = array_map(
				static fn( $m ) => 'https://schema.org/' . $m,
				$methods
			);
		}

		return $block;
	}

	/**
	 * Resolve the `merchantReturnLink` URL for a configured policy page.
	 *
	 * Re-validates the page is currently published before emitting the
	 * link. Sanitization on save already enforces the same gate, but
	 * `get_post_status()` can flip from `publish` to `draft` / `trash`
	 * any time after the merchant saves — without this re-check, a
	 * subsequent unpublish would leave the JSON-LD pointing at a stale
	 * URL while the JS preview (which filters `?status=publish`)
	 * correctly omits the link, producing visible drift between
	 * preview and emission.
	 *
	 * Returns an empty string in any of these cases (caller skips the
	 * `merchantReturnLink` field):
	 *   - `$page_id` is non-positive
	 *   - `get_post_status()` is missing or returns anything other than
	 *     `publish`
	 *   - `get_permalink()` is missing or returns a falsy/non-string
	 *
	 * @param int $page_id Sanitized policy page ID (0 = no page configured).
	 * @return string Permalink URL when the page is currently published,
	 *                empty string otherwise.
	 */
	private static function resolve_merchant_return_link( int $page_id ): string {
		if ( $page_id <= 0 ) {
			return '';
		}
		// Re-check published status AND post type — both are enforced
		// at save-time by the sanitizer (`get_post_status === 'publish'`
		// AND `get_post_type === 'page'`), but emission must mirror
		// to handle three drift cases:
		//   1. Page unpublished after save (status flips publish → draft).
		//   2. Page deleted after save (status returns false / post type
		//      returns false).
		//   3. Settings corrupted/bypassed by direct DB write or a
		//      future UI that writes a non-page post ID.
		// All three should produce no link rather than emit a stale
		// or wrong-shape URL.
		if ( ! function_exists( 'get_post_status' ) ) {
			return '';
		}
		if ( 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		if ( ! function_exists( 'get_post_type' ) ) {
			return '';
		}
		if ( 'page' !== get_post_type( $page_id ) ) {
			return '';
		}
		$link = function_exists( 'get_permalink' ) ? get_permalink( $page_id ) : '';
		return is_string( $link ) ? $link : '';
	}

	/**
	 * Map WooCommerce weight unit to UN/CEFACT unit code.
	 *
	 * Result is instance-cached so get_option() is called at most once
	 * per request even when multiple products are output on the same page.
	 *
	 * @return string
	 */
	private function get_weight_unit_code() {
		if ( null === $this->weight_unit_code_cache ) {
			$unit_map                     = array(
				'kg'  => 'KGM',
				'g'   => 'GRM',
				'lbs' => 'LBR',
				'oz'  => 'ONZ',
			);
			$wc_unit                      = get_option( 'woocommerce_weight_unit', 'kg' );
			$this->weight_unit_code_cache = isset( $unit_map[ $wc_unit ] ) ? $unit_map[ $wc_unit ] : 'KGM';
		}
		return $this->weight_unit_code_cache;
	}

	/**
	 * Map WooCommerce dimension unit to UN/CEFACT unit code.
	 *
	 * Result is instance-cached so get_option() is called at most once
	 * per request even when multiple products are output on the same page.
	 *
	 * @return string
	 */
	private function get_dimension_unit_code() {
		if ( null === $this->dimension_unit_code_cache ) {
			$unit_map                        = array(
				'cm' => 'CMT',
				'm'  => 'MTR',
				'mm' => 'MMT',
				'in' => 'INH',
				'yd' => 'YRD',
			);
			$wc_unit                         = get_option( 'woocommerce_dimension_unit', 'cm' );
			$this->dimension_unit_code_cache = isset( $unit_map[ $wc_unit ] ) ? $unit_map[ $wc_unit ] : 'CMT';
		}
		return $this->dimension_unit_code_cache;
	}
}
