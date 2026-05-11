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
	 * Hard cap on per-property entries emitted under
	 * {@see add_related_products()} — `isRelatedTo` and `isSimilarTo`
	 * are each capped independently. A merchant who has 100 cross-sell
	 * IDs configured on a single product would otherwise inflate the
	 * JSON-LD payload with 100 reference blocks per property; agents
	 * only need a few signal-rich pointers, not an exhaustive list.
	 *
	 * Not exposed as a filter today — YAGNI until a real merchant need
	 * surfaces. The constant is the single tuning knob.
	 */
	private const MAX_RELATED_PRODUCT_REFS = 10;

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_structured_data_product', [ $this, 'enhance_product_data' ], 20, 2 );
		add_filter( 'woocommerce_structured_data_type_for_page', [ $this, 'allow_product_group_type' ] );
		add_action( 'wp_head', [ $this, 'output_store_jsonld' ], 5 );
	}

	/**
	 * Register `productgroup` as a renderable structured-data type on
	 * single-product pages.
	 *
	 * WC core's `WC_Structured_Data::get_structured_data()` keys the
	 * generated markup by `strtolower( $value['@type'] )` and intersects
	 * the result with the per-page allow-list returned by
	 * `get_data_type_for_page()`. That list ships only `product`,
	 * `breadcrumblist`, `review`, `order` — so when our enhancer rewrites
	 * `@type` from `Product` to `ProductGroup` for variable products
	 * (PR #328 / `maybe_convert_to_product_group()`), the entire block
	 * silently drops out of the emitted `<script type="application/ld+json">`.
	 *
	 * Adding `productgroup` to the allow-list keeps the block in the
	 * output without disturbing anything else — `Product` stays allowed
	 * for simple products, and the new type only matters when our
	 * filter has actually rewritten `@type`.
	 *
	 * @param array $types Allow-listed structured-data types for the page.
	 * @return array
	 */
	public function allow_product_group_type( $types ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $types;
		}
		// Guard against duplicate appends — another plugin (or this
		// filter running multiple times against the same `$types`
		// array) may have already added `productgroup`. Duplicates
		// don't break WC core's allow-list intersection but they're
		// noise and a future debugger reading the type list would
		// rightly wonder why.
		if ( in_array( 'productgroup', $types, true ) ) {
			return $types;
		}
		$types[] = 'productgroup';
		return $types;
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

		$this->add_checkout_page_url_template( $markup, $product );

		$this->add_inventory_level( $markup, $product );

		$this->add_category_path( $markup, $product );

		$this->add_dimensions( $markup, $product );

		$this->emit_attributes( $markup, $product );

		$base_location = wc_get_base_location();
		$country       = $base_location['country'] ?? '';

		$this->add_currency( $markup );
		$this->add_subscription_signals( $markup, $product );
		$this->decode_seller_name( $markup );
		$this->add_shipping_details( $markup, $country );
		$this->add_handling_time( $markup, $settings );
		$this->add_return_policy( $markup, $product, $settings, $country );

		$this->add_related_products( $markup, $product, $settings );

		$this->maybe_convert_to_product_group( $markup, $product, $settings, $country );

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
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function add_buy_action( array &$markup, $product ): void {
		$markup['potentialAction'] = array(
			'@type'  => 'BuyAction',
			'target' => array(
				'@type'          => 'EntryPoint',
				'urlTemplate'    => self::build_checkout_url_template( $product ),
				'actionPlatform' => array(
					'https://schema.org/DesktopWebPlatform',
					'https://schema.org/MobileWebPlatform',
				),
			),
		);
	}

	/**
	 * Build the BuyAction / `Offer.checkoutPageURLTemplate` URL for a
	 * product or variation, with the canonical UTM-attribution
	 * placeholders so an AI agent or rich-result consumer can substitute
	 * its identity at click time.
	 *
	 * Two emission shapes, gated by WC product type:
	 *
	 *   - **Simple, variable, variation** — WooCommerce Shareable Checkout
	 *     form (`{home}/checkout-link/?products={id}:1&utm_*`). For
	 *     variations the caller passes the variation product (so the
	 *     specific SKU pre-selects in the cart); for simple/variable
	 *     parents the product ID alone resolves correctly through WC's
	 *     `/checkout-link/` rewrite handler.
	 *   - **Bundle, grouped** — the product's permalink (`{permalink}?utm_*`).
	 *     The `?products=ID:1` shorthand can't represent these:
	 *     `/checkout-link/?products=BUNDLE_ID:1` would attempt to add the
	 *     bundle parent without the per-bundled-item configuration WC
	 *     requires; `?products=GROUPED_ID:1` would attempt to add the
	 *     grouped UX-wrapper parent (which has no SKU or inventory of
	 *     its own — only the children do). The deterministic
	 *     `/checkout/?add-to-cart=BUNDLE&bundle_quantity_<bid>=…` form
	 *     used by the UCP REST controller (`build_continue_url()`)
	 *     would require child-resolution plumbing not present on the
	 *     JSON-LD path, and would still fall back to the permalink for
	 *     the configurable case (any optional bundled item or any
	 *     variable child without bundle-author-set defaults). Permalink
	 *     handles both the deterministic and configurable cases with
	 *     one shape: the buyer lands on the merchant PDP where WC's
	 *     existing configurator runs, and UTM attribution still flows
	 *     through.
	 *
	 * Canonical UTM shape (0.5.0+): `utm_medium=referral` is Google-
	 * canonical; `utm_id=woo_ucp` flags AI-routed traffic via the
	 * `WOO_UCP_ID` constant so a future rename stays consistent with
	 * the attribution matcher.
	 *
	 * Static so callers without a class instance (e.g. the per-variant
	 * builder under `hasVariant`) can build URLs uniformly.
	 *
	 * @param WC_Product $product The product or variation. WC core
	 *                            variations have `type === 'variation'`,
	 *                            distinct from `bundle`/`grouped`, so
	 *                            variation entries under `hasVariant`
	 *                            fall through to the Shareable Checkout
	 *                            form.
	 * @return string The full URL with `{agent_id}` and `{session_id}`
	 *                placeholders ready for the agent to substitute.
	 */
	private static function build_checkout_url_template( $product ): string {
		$utm_args = array(
			'utm_source'    => '{agent_id}',
			'utm_medium'    => 'referral',
			'utm_id'        => WC_AI_Storefront_Attribution::WOO_UCP_ID,
			'ai_session_id' => '{session_id}',
		);

		// Bundle and grouped: emit the product permalink with UTM
		// attribution. See docblock for why `/checkout-link/?products=`
		// can't represent these types.
		if ( $product->is_type( 'bundle' ) || $product->is_type( 'grouped' ) ) {
			return add_query_arg( $utm_args, $product->get_permalink() );
		}

		// Simple, variable, variation: WooCommerce Shareable Checkout URL
		// — `?products=ID:QUANTITY` resolves through WC's `/checkout-link/`
		// rewrite handler, adds the item to the cart, redirects to
		// checkout. Quantity fixed at 1 — AI-shopping flows are
		// single-item by convention.
		return add_query_arg(
			array_merge( array( 'products' => $product->get_id() . ':1' ), $utm_args ),
			home_url( '/checkout-link/' )
		);
	}

	/**
	 * Adds subscription-billing signals to `offers[0]` when WC Subscriptions
	 * is active and the product is a recurring-billing product.
	 *
	 * Emits these Schema.org fields on the Offer (per
	 * https://schema.org/UnitPriceSpecification and related):
	 *
	 *   - `priceSpecification` — an array of one or more
	 *     `UnitPriceSpecification` entries describing recurring price
	 *     components. Each entry carries `billingDuration` (ISO 8601),
	 *     `priceComponentType: Subscription` for the recurring price,
	 *     and an optional `billingStart` for the trial-then-paid pattern.
	 *     A separate entry with `priceComponentType: ActivationFee`
	 *     carries the one-shot sign-up fee when set.
	 *   - `addOn` — a one-shot `Offer` carrying the sign-up fee, emitted
	 *     alongside the inline `ActivationFee` `UnitPriceSpecification`
	 *     for backward compatibility with consumers that don't
	 *     recognize the `priceComponentType` enumeration (which is still
	 *     marked "new" per Schema.org's own framing).
	 *   - `eligibleDuration` — a `QuantitativeValue` carrying the total
	 *     duration when the merchant set a finite subscription length
	 *     (`get_length() > 0`); omitted for indefinite subscriptions.
	 *
	 * Gating: `function_exists('wcs_is_subscription')` + the
	 * `WC_Subscriptions_Product` class check are both required — the
	 * helper is a no-op for stores without WC Subscriptions active.
	 *
	 * Tax handling: this helper reads raw `get_sign_up_fee()` (no
	 * include/exclude-tax variant). Mirrors what the existing
	 * `build_variant_offer_skeleton` does for the variant price — the
	 * JSON-LD output stays consistent in its tax-inclusivity stance
	 * across all fields.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product (simple subscription) or
	 *                            variation (subscription_variation under
	 *                            a variable-subscription parent).
	 */
	private function add_subscription_signals( array &$markup, $product ): void {
		// Fail-closed if WC Subscriptions isn't active — every call to
		// `WC_Subscriptions_Product::*` would otherwise fatal.
		if ( ! function_exists( 'wcs_is_subscription' ) || ! class_exists( 'WC_Subscriptions_Product', false ) ) {
			return;
		}
		if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			return;
		}
		if ( ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}

		$period       = WC_Subscriptions_Product::get_period( $product );
		$interval     = WC_Subscriptions_Product::get_interval( $product );
		$length       = WC_Subscriptions_Product::get_length( $product );
		$signup_fee   = (float) WC_Subscriptions_Product::get_sign_up_fee( $product );
		$trial_length = WC_Subscriptions_Product::get_trial_length( $product );
		$trial_period = WC_Subscriptions_Product::get_trial_period( $product );

		// Read currency from the already-hoisted top-level Offer field
		// (`add_currency()` runs before this enricher). Fall back to
		// `get_woocommerce_currency()` for the rare case where
		// `add_currency` didn't hoist — usually because there's no
		// price for it to read.
		$currency = (string) ( $markup['offers'][0]['priceCurrency'] ?? '' );
		if ( '' === $currency ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		}
		$price = (string) ( $markup['offers'][0]['price'] ?? '0' );

		$price_specs = array();

		// Trial entry: emitted when the merchant set a trial. Free by
		// definition — WC Subscriptions' trial period IS the free
		// window. The recurring entry below picks up `billingStart`
		// pointed at the end of this trial.
		$has_trial = $trial_length > 0;
		if ( $has_trial ) {
			$price_specs[] = array(
				'@type'              => 'UnitPriceSpecification',
				'priceComponentType' => 'https://schema.org/Subscription',
				'price'              => '0',
				'priceCurrency'      => $currency,
				'billingDuration'    => self::period_to_iso8601_duration( $trial_period, $trial_length ),
			);
		}

		// Recurring entry: always present for a subscription product.
		$recurring = array(
			'@type'              => 'UnitPriceSpecification',
			'priceComponentType' => 'https://schema.org/Subscription',
			'price'              => $price,
			'priceCurrency'      => $currency,
			'billingDuration'    => self::period_to_iso8601_duration( $period, $interval ),
		);
		if ( $has_trial ) {
			$recurring['billingStart'] = self::period_to_iso8601_duration( $trial_period, $trial_length );
		}
		$price_specs[] = $recurring;

		// Sign-up fee: emit BOTH the inline `ActivationFee` priceComponent
		// AND `Offer.addOn` for compat (decision #1 — "future-ready now").
		// Inline form is semantically richer (`priceComponentType`
		// enumeration); `addOn` uses released vocabulary that broader
		// consumers will recognize today. Duplication is spec-legal.
		if ( $signup_fee > 0 ) {
			$signup_fee_str = (string) WC_Subscriptions_Product::get_sign_up_fee( $product );
			$price_specs[]  = array(
				'@type'              => 'UnitPriceSpecification',
				'priceComponentType' => 'https://schema.org/ActivationFee',
				'price'              => $signup_fee_str,
				'priceCurrency'      => $currency,
			);
			$markup['offers'][0]['addOn'] = array(
				'@type'         => 'Offer',
				'name'          => 'Sign-up fee',
				'price'         => $signup_fee_str,
				'priceCurrency' => $currency,
			);
		}

		$markup['offers'][0]['priceSpecification'] = $price_specs;

		// Finite-length subscription: emit `eligibleDuration` carrying
		// the total number of recurring periods. Indefinite
		// subscriptions (length === 0) omit this field per
		// QuantitativeValue's semantics — no duration to express.
		if ( $length > 0 ) {
			$markup['offers'][0]['eligibleDuration'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => $length,
				'unitCode' => self::period_to_uncefact_code( $period ),
			);
		}
	}

	/**
	 * Map a WC subscription period ('day', 'week', 'month', 'year') and a
	 * count to an ISO 8601 duration string (e.g., 'P1M', 'P3M', 'P14D').
	 *
	 * Used by the subscription-signal emitter to fill
	 * `UnitPriceSpecification.billingDuration` and `billingStart` per
	 * Schema.org. Both fields accept Duration values in ISO 8601 form
	 * (verified verbatim against
	 * `Universal-Commerce-Protocol/ucp` is unrelated — this is pure
	 * Schema.org vocabulary, see https://schema.org/billingDuration).
	 *
	 * Pure mapping — no I/O, no validation beyond a strict period whitelist.
	 * Returns 'P1M' for unknown periods as a safe default rather than
	 * throwing; subscription products without a valid period are vanishingly
	 * rare and we'd rather emit a slightly-wrong duration than fatal the
	 * JSON-LD render.
	 *
	 * @param string $period WC period — 'day' | 'week' | 'month' | 'year'.
	 * @param int    $count  Number of periods; must be > 0.
	 * @return string ISO 8601 duration string.
	 */
	private static function period_to_iso8601_duration( string $period, int $count ): string {
		if ( $count <= 0 ) {
			return 'P0D';
		}
		$unit = [
			'day'   => 'D',
			'week'  => 'W',
			'month' => 'M',
			'year'  => 'Y',
		][ $period ] ?? 'M';
		return 'P' . $count . $unit;
	}

	/**
	 * Map a WC subscription period to a UN/CEFACT unit code for
	 * `QuantitativeValue.unitCode` on `Offer.eligibleDuration`.
	 *
	 * Schema.org's `QuantitativeValue.unitCode` accepts UN/CEFACT Common
	 * Code (Recommendation N°20). The mapping used here matches what
	 * Google Merchant Center and other major consumers use for
	 * date/duration units.
	 *
	 * Unknown periods fall back to 'MON' (month) — same safe-default
	 * rationale as `period_to_iso8601_duration()`. Logger::debug
	 * call site is the caller's responsibility (this helper stays pure).
	 *
	 * @param string $period WC period — 'day' | 'week' | 'month' | 'year'.
	 * @return string UN/CEFACT unit code — 'DAY' | 'WEE' | 'MON' | 'ANN'.
	 */
	private static function period_to_uncefact_code( string $period ): string {
		return [
			'day'   => 'DAY',
			'week'  => 'WEE',
			'month' => 'MON',
			'year'  => 'ANN',
		][ $period ] ?? 'MON';
	}

	/**
	 * Adds `checkoutPageURLTemplate` to `offers[0]` with the same
	 * Shareable Checkout URL as `BuyAction.urlTemplate`.
	 *
	 * Schema.org `Offer.checkoutPageURLTemplate` is the modern dedicated
	 * e-commerce property — emitted alongside (NOT instead of)
	 * `Product.potentialAction.BuyAction`. The two cover different
	 * consumer paths:
	 *
	 *   - `BuyAction` is the Action-vocabulary signal recognized by
	 *     older / cross-domain consumers; supports `actionPlatform`,
	 *     `result`, etc.
	 *   - `checkoutPageURLTemplate` lives directly on Offer with
	 *     native per-offer scope — the right fit for variant-level
	 *     emission (#328) and modern e-commerce-aware AI agents.
	 *
	 * Both emit the same URL value. See
	 * [SCHEMA-ORG-COVERAGE.md](docs/engineering/SCHEMA-ORG-COVERAGE.md)
	 * for the keep-both rationale.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function add_checkout_page_url_template( array &$markup, $product ): void {
		if ( ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}
		$markup['offers'][0]['checkoutPageURLTemplate'] = self::build_checkout_url_template( $product );
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
			// as a single value or an array; normalize to a re-keyed
			// list before merging.
			$existing = $markup['additionalProperty'] ?? array();
			if ( ! is_array( $existing ) ) {
				// Null or scalar — treat as empty so we never insert
				// a stray null/scalar entry into the output array.
				$existing = array();
			} elseif ( isset( $existing['@type'] ) ) {
				// Single PropertyValue object — wrap as a one-element list.
				$existing = array( $existing );
			} else {
				// Already a list of entries. `array_values()` re-keys —
				// `array_is_list()` is too strict for arrays whose keys
				// have been disturbed by `array_filter()` upstream.
				$existing = array_values( $existing );
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
	 * Builds the `variesBy` array for a `ProductGroup` — Schema.org property
	 * URLs (or short labels for unmapped attributes) for the dimensions that
	 * actually vary across this product's variations.
	 *
	 * "Actually vary" means the variation set has more than one distinct
	 * non-empty value for the axis. If all variations share the same color
	 * and only differ by size, color is uniform and only `size` should appear
	 * in `variesBy`. This matters because Google's variant rich result keys
	 * on `variesBy` to know which dimensions a buyer can choose between.
	 *
	 * **Core typed override**: If a slug maps to a Schema.org typed property
	 * via {@see CORE_ATTRIBUTE_MAP} (color / size / material / pattern), we
	 * also inspect the variation children's own attribute meta directly —
	 * not just the parent's `get_variation_attributes()`. WC's parent-level
	 * "Used for variations" flag gates `get_variation_attributes()` but not
	 * the underlying variation meta; merchants who configure `pa_color`
	 * with distinct values across variations but forget to flag it as a
	 * variation axis still get correct ProductGroup emission, because the
	 * data is right there on each child even if the parent flag is wrong.
	 * This override is intentionally limited to the four core typed slugs
	 * — they have canonical Schema.org type mappings and are the axes AI
	 * agents are most likely to query, so getting them right matters more
	 * than honoring a likely-misconfigured parent flag.
	 *
	 * Slug → Schema.org URL mapping uses {@see CORE_ATTRIBUTE_MAP} (the same
	 * lookup #331 uses for typed-property emission). Mapped attributes emit
	 * as full Schema.org URLs (e.g. `https://schema.org/color`); unmapped
	 * attributes (custom merchant axes like "Style" or "Heel Height") emit
	 * as plain Text labels — Schema.org `variesBy` accepts both shapes.
	 *
	 * @param WC_Product $product The variable product.
	 * @return string[] List of Schema.org URLs and/or Text labels for axes
	 *                 that vary. Empty array for non-variable products or
	 *                 variable products with uniform variation values.
	 */
	private static function detect_varies_by( $product ): array {
		if ( ! method_exists( $product, 'get_variation_attributes' ) ) {
			return array();
		}

		$varies_urls   = array();
		$varies_labels = array();

		// Path 1: parent-flagged variation attributes (canonical WC route).
		$variation_attrs = $product->get_variation_attributes();
		foreach ( (array) $variation_attrs as $slug => $values ) {
			$distinct = array_filter(
				array_unique( (array) $values ),
				static fn( $v ) => '' !== (string) $v
			);
			if ( count( $distinct ) <= 1 ) {
				continue;
			}
			$slug_lower = strtolower( (string) $slug );
			if ( isset( self::CORE_ATTRIBUTE_MAP[ $slug_lower ] ) ) {
				$varies_urls[] = 'https://schema.org/' . self::CORE_ATTRIBUTE_MAP[ $slug_lower ];
			} else {
				$varies_labels[] = function_exists( 'wc_attribute_label' )
					? wc_attribute_label( $slug, $product )
					: $slug;
			}
		}

		// Path 2: core-typed override. For the four canonical Schema.org
		// slugs (color / size / material / pattern), also peek at the
		// variation children directly. This catches the misconfigured
		// case where a merchant set up variations with real per-child
		// values but didn't flag the parent attribute "Used for
		// variations". Schema.org rich results care that the typed axis
		// is advertised correctly; the parent flag is incidental.
		$override_urls = self::detect_core_typed_axes_from_children( $product );
		foreach ( $override_urls as $url ) {
			$varies_urls[] = $url;
		}

		return array_values( array_unique( array_merge( $varies_urls, $varies_labels ) ) );
	}

	/**
	 * Inspect variation children's attribute meta to find core-typed axes
	 * (color / size / material / pattern) that have ≥2 distinct values
	 * across children — even if the parent's "Used for variations" flag
	 * is unset on the matching attribute.
	 *
	 * Returns Schema.org property URLs only (no Text labels), because
	 * the override is scoped to the four core typed slugs by design.
	 *
	 * @param WC_Product $product The variable product (parent).
	 * @return string[] Schema.org URLs for core-typed axes that factually vary.
	 */
	private static function detect_core_typed_axes_from_children( $product ): array {
		$children = $product->get_children();
		if ( ! is_array( $children ) || count( $children ) < 2 ) {
			// Need at least 2 children to compare values across.
			return array();
		}

		// Bucket: core slug → set of distinct non-empty values seen.
		$values_by_core_slug = array();
		foreach ( $children as $child_id ) {
			$attrs = self::read_variation_core_attributes( (int) $child_id );
			foreach ( $attrs as $slug_lower => $value_str ) {
				$values_by_core_slug[ $slug_lower ][ $value_str ] = true;
			}
		}

		$urls = array();
		foreach ( $values_by_core_slug as $slug_lower => $value_set ) {
			if ( count( $value_set ) >= 2 ) {
				$urls[] = 'https://schema.org/' . self::CORE_ATTRIBUTE_MAP[ $slug_lower ];
			}
		}
		return $urls;
	}

	/**
	 * Read a variation's core-typed attribute values directly from
	 * postmeta — bypassing the parent's "Used for variations" flag.
	 *
	 * `WC_Product_Variation::get_attributes()` (and its
	 * `get_variation_attributes()` wrapper) only surface attributes
	 * whose parent has `is_variation: 1`. The per-variation postmeta
	 * key `attribute_<slug>` is populated whenever the merchant entered
	 * a value on the variation form, regardless of the parent flag —
	 * so reading meta directly is the only path to surface the data
	 * when the merchant configured variations correctly but forgot to
	 * flag the parent attribute.
	 *
	 * Scoped to the four core typed slugs ({@see CORE_ATTRIBUTE_MAP})
	 * because they have canonical Schema.org typed properties; unmapped
	 * custom attributes intentionally honor the parent's flag.
	 *
	 * @param int $variation_id The variation post ID.
	 * @return array<string,string> Slug → trimmed value, only for non-empty
	 *                              core typed slugs.
	 */
	private static function read_variation_core_attributes( int $variation_id ): array {
		if ( $variation_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$out = array();
		foreach ( self::CORE_ATTRIBUTE_MAP as $slug_lower => $_schema_property ) {
			$value     = get_post_meta( $variation_id, 'attribute_' . $slug_lower, true );
			$value_str = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value_str ) {
				continue;
			}
			$out[ $slug_lower ] = $value_str;
		}
		return $out;
	}

	/**
	 * Convert a variable product's markup to Schema.org `ProductGroup`
	 * shape with `hasVariant` entries. No-op for simple/grouped/external
	 * products and for variable products with zero variation children.
	 *
	 * Per the locked design (issue #328):
	 *
	 *   - Variable + ≥1 variation child + ≥1 attribute marked
	 *     "Used for variations" → emit `@type: ProductGroup` with
	 *     `productGroupID`, `variesBy`, and `hasVariant: [...]`. Drop the
	 *     parent's `offers[]` and `potentialAction` (the variants own
	 *     them — buyers can't buy the parent of a variable product).
	 *   - Variable + 0 children → leave as `@type: Product` (today's
	 *     simple-product shape). Edge case; `BuyAction` URL with the
	 *     parent ID may not resolve, but mirrors today's pre-#328
	 *     behavior for misconfigured stores.
	 *   - Variable + ≥1 child but **no** attribute is flagged "Used for
	 *     variations" (Product 16 / Hoodie territory in the dev
	 *     fixtures) → fall back to simple-Product shape. We have no
	 *     `variesBy` to advertise, so a `hasVariant` block of N
	 *     near-identical entries would just confuse agents. Honor the
	 *     merchant-typed-it-wrong reality: emit what works for a single
	 *     SKU and let the merchant fix the variation flag.
	 *
	 * @param array      $markup   Markup array, modified by reference.
	 * @param WC_Product $product  The product object.
	 * @param array      $settings Plugin settings (passed to per-variant builder).
	 * @param string     $country  Store base country (passed to per-variant builder).
	 */
	private function maybe_convert_to_product_group( array &$markup, $product, array $settings, string $country ): void {
		// Capability gate: only WC_Product_Variable (and subclasses) have
		// variation_attributes. Same gate as `get_variation_attribute_slugs`.
		if ( ! method_exists( $product, 'get_variation_attributes' ) ) {
			return;
		}

		$children = $product->get_children();
		if ( ! is_array( $children ) || empty( $children ) ) {
			// No variations to nest under hasVariant — keep simple-Product
			// shape (locked decision for the zero-children edge case).
			return;
		}

		// Prime the post + meta caches for all children in a single
		// query each, before any per-child read. Both `detect_varies_by()`
		// (via `read_variation_core_attributes()`) and the per-variant
		// build loop below touch every child; without priming, a 50-
		// variation product would issue 50+ separate `get_post_meta()`
		// queries plus 50+ `wc_get_product()` lookups during one page
		// render. `_prime_post_caches` keeps the WC product factory's
		// cache hot too — `wc_get_product()` reads from the WP post
		// cache before falling through to the database.
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $children, false, false );
		}
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $children );
		}

		$varies_by = self::detect_varies_by( $product );
		if ( empty( $varies_by ) ) {
			// Variations exist but nothing is flagged "Used for variations"
			// — there are no axes to advertise. Emitting `hasVariant`
			// without `variesBy` would just hand agents N near-identical
			// blocks with no way to tell them apart. Fall back to
			// simple-Product shape; the merchant's WC variation-config
			// gap belongs in their editor, not in our schema output.
			return;
		}

		// Build the variant entries FIRST. Only commit the conversion
		// (rewrite `@type`, drop parent `offers` + `potentialAction`,
		// add `productGroupID` / `variesBy` / `hasVariant`) once we
		// know we have at least one buildable variant.
		//
		// Why: `get_children()` can contain stale post IDs (data
		// corruption, soft-deleted variations, or a transient WP
		// cache miss). If `wc_get_product()` returns false for every
		// child, an unconditional convert would emit a `ProductGroup`
		// with no `hasVariant` AND no `offers`/`potentialAction` —
		// strictly worse than the simple-Product fallback for AI
		// agents trying to deep-link or buy. Building first lets the
		// fallback fire when the variations are unrecoverable.
		$has_variant = array();
		foreach ( $children as $child_id ) {
			$variation = $this->resolve_variation( (int) $child_id );
			if ( null === $variation ) {
				continue;
			}
			$has_variant[] = $this->build_variant_entry( $variation, $product, $settings, $country );
		}
		if ( empty( $has_variant ) ) {
			return;
		}

		$markup['@type']          = 'ProductGroup';
		$sku                      = $product->get_sku();
		$markup['productGroupID'] = '' !== $sku ? $sku : (string) $product->get_id();
		$markup['variesBy']       = $varies_by;
		// Buyers can't purchase the parent of a variable product, so a
		// parent-level `BuyAction` or `offers[]` block would point at
		// an unbuyable entity. Per Schema.org, `ProductGroup`
		// represents the abstract group; concrete offers live on the
		// `hasVariant` Product entries.
		unset( $markup['offers'], $markup['potentialAction'] );
		$markup['hasVariant'] = $has_variant;
	}

	/**
	 * Resolve a variation post ID to a WC_Product instance.
	 *
	 * Wraps `wc_get_product()` with the null/falsy guard agents need —
	 * `wc_get_product()` returns `false` for non-product IDs (e.g. data
	 * corruption where `get_children()` returned a stale ID). Returns
	 * `null` for callers to short-circuit.
	 *
	 * @param int $variation_id The variation post ID.
	 * @return WC_Product|null The variation product object, or null if
	 *                         not resolvable.
	 */
	private function resolve_variation( int $variation_id ) {
		if ( $variation_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$variation = wc_get_product( $variation_id );
		return ( $variation && is_object( $variation ) ) ? $variation : null;
	}

	/**
	 * Build one `hasVariant` Product entry from a `WC_Product_Variation`.
	 *
	 * The entry is a standalone Schema.org Product block describing the
	 * specific variation: SKU, image (with parent fallback), per-variant
	 * typed properties (color/size/material/pattern from the variation's
	 * specific attribute selections), an `offers[0]` Offer block with
	 * price/availability/currency/inventory/shipping/return-policy, the
	 * variation's `BuyAction`, and `Offer.checkoutPageURLTemplate`.
	 *
	 * Both URL fields point at the WC Shareable Checkout URL using the
	 * **variation ID** so AI-routed traffic lands on checkout with the
	 * right SKU pre-selected — no "choose your color" detour.
	 *
	 * Existing top-level enrichers (`add_inventory_level`, `add_currency`,
	 * `add_shipping_details`, `add_handling_time`, `add_return_policy`,
	 * `add_buy_action`, `add_checkout_page_url_template`) all operate on
	 * `$markup['offers'][0]` — we reuse them by passing the variant's
	 * own markup as `$markup` and the variation as `$product`. The
	 * return policy is read from the parent (variants inherit the
	 * parent's policy + final-sale flag, not their own).
	 *
	 * @param WC_Product $variation      The variation (a `WC_Product_Variation` at runtime; typed as `WC_Product` since the variation subclass isn't in PHPStan's stubs and the variation API used here is on the base class).
	 * @param WC_Product $parent_product The variable parent (for image fallback + return-policy meta).
	 * @param array      $settings       Plugin settings (for return policy + handling time).
	 * @param string     $country        Store base country (for shipping).
	 * @return array Schema.org Product entry suitable for `hasVariant[]`.
	 */
	private function build_variant_entry( $variation, $parent_product, array $settings, string $country ): array {
		$entry = array( '@type' => 'Product' );

		$this->add_variant_basics( $entry, $variation, $parent_product );

		$entry['offers'] = array( $this->build_variant_offer_skeleton( $variation ) );

		// All of these operate on `$entry['offers'][0]`; pass the variation
		// as `$product` so per-variant data (stock, price) flows through.
		// Return policy uses the parent — variants inherit, no per-variant
		// final-sale override (deferred — Pattern B in the meta-box design).
		$this->add_inventory_level( $entry, $variation );
		$this->add_currency( $entry );
		$this->add_subscription_signals( $entry, $variation );
		$this->add_shipping_details( $entry, $country );
		$this->add_handling_time( $entry, $settings );
		$this->add_return_policy( $entry, $parent_product, $settings, $country );

		// BuyAction + checkoutPageURLTemplate both use the VARIATION ID
		// (not the parent product ID) so the URL drops the buyer on
		// checkout with the specific SKU.
		$this->add_buy_action( $entry, $variation );
		$this->add_checkout_page_url_template( $entry, $variation );

		return $entry;
	}

	/**
	 * Populate a variant entry with `@id`, `url`, `name`, `sku`, `image`
	 * (with parent fallback), and per-variant typed Schema.org
	 * properties from the variation's specific attribute selections.
	 *
	 * **Typed-property source**: read postmeta directly via
	 * {@see read_variation_core_attributes()} rather than through
	 * `WC_Product_Variation::get_variation_attributes()`. The latter
	 * is gated by the parent's "Used for variations" flag — it
	 * silently returns empty when that flag is unset on a core typed
	 * attribute (e.g. `pa_color`), even when each variation child
	 * carries a real value in its `attribute_<slug>` postmeta. The
	 * misconfigured-variable `ProductGroup` override in
	 * {@see detect_varies_by()} depends on those typed values
	 * reaching the variant entry, so the same direct-postmeta path is
	 * the source of truth here too.
	 *
	 * Scoped to the four core typed slugs in
	 * {@see CORE_ATTRIBUTE_MAP} — unmapped custom attributes
	 * (Style, Heel Height, Logo) intentionally honor the parent flag.
	 *
	 * @param array      $entry          Variant markup, modified by reference.
	 * @param WC_Product $variation      The variation.
	 * @param WC_Product $parent_product The parent (image fallback only).
	 */
	private function add_variant_basics( array &$entry, $variation, $parent_product ): void {
		// `@id` and `name` are Schema.org Product fundamentals — agents
		// dereference `@id` to fetch the variant's own page, and `name`
		// is the human-readable label that surfaces in rich results.
		// We use the variation permalink as `@id` (so it round-trips to
		// a real WC URL) and WC's own variation name (e.g. "Hoodie -
		// Blue, Logo: Yes") which already encodes the differentiating
		// attribute values per the merchant's variation form.
		//
		// Override the bare parent URL when WC's `get_permalink()` fell
		// through. `WC_Product_Variation::get_permalink()` is gated by
		// the parent's `is_variation` flag — when that flag is unset on
		// every variation attribute, the method returns the bare parent
		// URL instead of the parent + `?attribute_<slug>=value` query
		// args. Symptom: every variant's `@id` collapses to the same
		// URL, breaking variant-graph traversal for AI agents. Detect
		// the fall-through by comparing the two permalinks; if equal,
		// synthesize the URL ourselves from the same postmeta source
		// `read_variation_core_attributes()` already reads for the
		// override-path typed-property emission. Same scope: core
		// slugs only. Variants differing only by an unmapped attribute
		// keep the bare parent URL — surfacing variation noise the
		// merchant intentionally hid would over-step the override's
		// narrow scope. (#341)
		// Read variation core-typed attributes once. Both the @id
		// fall-through override below AND the per-variant typed-property
		// emission further down consume this — calling it twice would
		// duplicate up to 4 `get_post_meta()` reads per variation on
		// the override path. (Postmeta hits are cache-primed by
		// `maybe_convert_to_product_group()` upstream, so each call
		// is fast — but redundant work on a per-variant loop adds up
		// on stores with many variations.)
		if ( ! method_exists( $variation, 'get_id' ) ) {
			return;
		}
		$core_attrs = self::read_variation_core_attributes( (int) $variation->get_id() );

		$permalink        = $variation->get_permalink();
		$parent_permalink = $parent_product->get_permalink();
		if (
			is_string( $permalink ) && '' !== $permalink
			&& $permalink === $parent_permalink
			&& ! empty( $core_attrs )
		) {
			$query_args = array();
			foreach ( $core_attrs as $slug => $value ) {
				$query_args[ 'attribute_' . $slug ] = $value;
			}
			$permalink = add_query_arg( $query_args, $parent_permalink );
		}

		if ( is_string( $permalink ) && '' !== $permalink ) {
			$entry['@id'] = $permalink;
			$entry['url'] = $permalink;
		}
		if ( method_exists( $variation, 'get_name' ) ) {
			$name = $variation->get_name();
			if ( is_string( $name ) && '' !== $name ) {
				$entry['name'] = $name;
			}
		}

		$sku = $variation->get_sku();
		if ( ! $sku ) {
			// Mirror WC core's fallback: when no SKU is set, use the
			// post ID. Schema.org requires an `sku` for AI-shopping rich
			// results to fire.
			$sku = (string) $variation->get_id();
		}
		$entry['sku'] = $sku;

		$image_url = $this->get_variant_image_url( $variation, $parent_product );
		if ( '' !== $image_url ) {
			$entry['image'] = $image_url;
		}

		// Per-variant typed properties (color/size/material/pattern from
		// the variation's specific attribute selections). Reuses
		// `$core_attrs` read at the top of this method — the same
		// postmeta source feeds both the @id fall-through override
		// (via `add_query_arg`) and the typed-property emission below.
		// Reading via `read_variation_core_attributes()` rather than
		// `get_variation_attributes()` is the workaround for the
		// parent's "Used for variations" flag — the WC API silently
		// returns empty when that flag is unset, even if per-variation
		// postmeta is populated. The misconfigured-variable
		// `ProductGroup` override (see `detect_varies_by()`) depends
		// on the typed value reaching the variant entry, so the same
		// fallback path is the source of truth here too.
		foreach ( $core_attrs as $slug => $value ) {
			$schema_prop = self::CORE_ATTRIBUTE_MAP[ $slug ];
			if ( array_key_exists( $schema_prop, $entry ) ) {
				continue;
			}
			$entry[ $schema_prop ] = $this->display_name_for_attribute_value( $slug, $value );
		}
	}

	/**
	 * Resolve the image URL for a variant, falling back to the parent.
	 *
	 * WC's variation editor lets merchants upload a variation-specific
	 * image; if none is set, the front-end falls back to the parent
	 * product's image. Schema.org JSON-LD should mirror this behavior so
	 * agents see a consistent image regardless of which variations were
	 * photographed.
	 *
	 * @param WC_Product $variation      The variation.
	 * @param WC_Product $parent_product The variable parent (fallback).
	 * @return string Absolute image URL, or empty string when neither has one.
	 */
	private function get_variant_image_url( $variation, $parent_product ): string {
		$image_id = $variation->get_image_id();
		if ( ! $image_id && $parent_product ) {
			$image_id = $parent_product->get_image_id();
		}
		if ( ! $image_id || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		$image_url = wp_get_attachment_image_url( $image_id, 'full' );
		return is_string( $image_url ) ? $image_url : '';
	}

	/**
	 * Convert a variation attribute value to its display form.
	 *
	 * For taxonomy attributes (`pa_*`), the variation stores the **term
	 * slug** (e.g. `white`); we resolve it to the display name (`White`)
	 * via `get_term_by()`. For free-text custom attributes, the value is
	 * stored as-is and used directly.
	 *
	 * @param string $slug  Attribute slug (e.g. `pa_color`).
	 * @param string $value The raw value (term slug for taxonomy, literal for free-text).
	 * @return string Display-name form, or the input value as fallback.
	 */
	private function display_name_for_attribute_value( string $slug, string $value ): string {
		if ( ! str_starts_with( $slug, 'pa_' ) || ! function_exists( 'get_term_by' ) ) {
			return $value;
		}
		$term = get_term_by( 'slug', $value, $slug );
		if ( $term instanceof \WP_Term && '' !== $term->name ) {
			return $term->name;
		}
		return $value;
	}

	/**
	 * Build the bare-minimum Offer skeleton for a variant — price,
	 * priceCurrency, availability. The remaining fields (inventory,
	 * shipping, return policy, checkoutPageURLTemplate) are layered on
	 * by the existing enrichers, exactly as they are for the parent
	 * Product's offer.
	 *
	 * @param WC_Product $variation The variation.
	 * @return array Single Offer block.
	 */
	private function build_variant_offer_skeleton( $variation ): array {
		$price        = function_exists( 'wc_format_decimal' ) && function_exists( 'wc_get_price_decimals' )
			? wc_format_decimal( $variation->get_price(), wc_get_price_decimals() )
			: (string) $variation->get_price();
		$availability = $variation->is_in_stock()
			? 'https://schema.org/InStock'
			: 'https://schema.org/OutOfStock';

		return array(
			'@type'         => 'Offer',
			'price'         => $price,
			'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'availability'  => $availability,
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
	 * Emit Schema.org `isRelatedTo` (cross-sells) and `isSimilarTo`
	 * (upsells) as `{"@id": permalink}` reference arrays on the
	 * top-level Product.
	 *
	 * **Schema.org mapping** (verified against the spec):
	 *   - {@link https://schema.org/isRelatedTo} = "A pointer to another,
	 *     somehow related product (or multiple products)." → WC
	 *     **cross-sells** (`get_cross_sell_ids()`) — the cart-page
	 *     complementary purchases.
	 *   - {@link https://schema.org/isSimilarTo} = "A pointer to
	 *     another, functionally similar product (or multiple products)."
	 *     → WC **upsells** (`get_upsell_ids()`) — the
	 *     premium / alternate version of the same item.
	 *
	 * **Reference-only emission**: each entry is `["@id" => permalink]`,
	 * NOT a full Product block. Full blocks would 5×+ the markup size
	 * for AI agents that already dereference `@id` to fetch the linked
	 * product's own structured data.
	 *
	 * **Three guards**:
	 *   1. Per-product visibility — IDs failing
	 *      {@see WC_AI_Storefront::is_product_syndicated()} are dropped
	 *      so excluded products aren't reachable via graph traversal
	 *      either. Consistent with the rest of the plugin's
	 *      visibility model.
	 *   2. Deleted/trashed products — `wc_get_product()` returns
	 *      `false`; we skip those IDs. WC doesn't auto-prune stale
	 *      cross-sell/upsell IDs when a referenced product is deleted,
	 *      so this case is common on older stores.
	 *   3. Hard cap of {@see MAX_RELATED_PRODUCT_REFS} (10) per
	 *      property. A merchant with 100 cross-sells doesn't need 100
	 *      reference blocks per product page; agents only need a few
	 *      signal-rich pointers.
	 *
	 * **Existing-key preservation**: if `$markup` already carries
	 * `isRelatedTo` or `isSimilarTo` (set by WC core or another
	 * plugin's filter at higher priority), don't overwrite. Same
	 * deference pattern as the typed-property emission for
	 * `color`/`size`/`material`/`pattern`. The `isset()` check
	 * intentionally treats `isRelatedTo => array()` as "caller already
	 * decided" — emitting nothing is a valid caller choice and we
	 * shouldn't quietly fill it in with our cross-sell list.
	 *
	 * Runs before `maybe_convert_to_product_group()` so the references
	 * survive the ProductGroup rewrite — Schema.org's `ProductGroup`
	 * is a Product subtype, both properties are valid there.
	 *
	 * @param array      $markup   Markup array, modified by reference.
	 * @param WC_Product $product  The product object.
	 * @param array      $settings Plugin settings (passed to the
	 *                             per-ID syndication check).
	 */
	private function add_related_products( array &$markup, $product, array $settings ): void {
		// Short-circuit if BOTH keys are already populated — no work to
		// do. WC core doesn't currently set either, but a third-party
		// filter at higher priority might. Avoids the
		// `get_cross_sell_ids()` + `get_upsell_ids()` reads, the slice,
		// the candidate-ID merge, and the three cache-priming calls
		// when none of them would be put to use.
		$skip_related = isset( $markup['isRelatedTo'] );
		$skip_similar = isset( $markup['isSimilarTo'] );
		if ( $skip_related && $skip_similar ) {
			return;
		}

		// Only fetch the lists we'll actually consume — if the caller
		// already set `isRelatedTo`, we don't need cross-sells; if they
		// already set `isSimilarTo`, we don't need upsells.
		$cross_sells = ( ! $skip_related && method_exists( $product, 'get_cross_sell_ids' ) )
			? (array) $product->get_cross_sell_ids()
			: array();
		$upsells     = ( ! $skip_similar && method_exists( $product, 'get_upsell_ids' ) )
			? (array) $product->get_upsell_ids()
			: array();

		// De-duplicate each list before slicing + the downstream loop.
		// WC's editor doesn't enforce uniqueness on cross/upsell ID
		// storage, and corrupted or imported postmeta can carry the
		// same ID multiple times. Without this, `[101, 101, 101, ...]`
		// would emit ten identical `@id` entries instead of falling
		// through to distinct products. `array_unique()` preserves
		// first-seen order via PHP's default key behavior;
		// `array_values()` re-keys the result so the subsequent slice
		// operates on a 0-indexed list.
		$cross_sells = array_values( array_unique( $cross_sells ) );
		$upsells     = array_values( array_unique( $upsells ) );

		// Cap each list at 2× the emission cap before priming + the
		// downstream loop. The output cap is MAX_RELATED_PRODUCT_REFS
		// (10), but some candidates fall out at the deleted-product
		// or syndication-exclusion guards — 2× gives breathing room
		// for typical failure rates while preventing pathological
		// cases (a merchant with 1000 cross-sells) from bulk-priming
		// thousands of posts when only ~10 will be emitted. Trades a
		// rare edge case (>50% of the first 20 candidates fail
		// validation) for a much bigger perf win on the common path.
		$slice_cap   = self::MAX_RELATED_PRODUCT_REFS * 2;
		$cross_sells = array_slice( $cross_sells, 0, $slice_cap );
		$upsells     = array_slice( $upsells, 0, $slice_cap );

		// Prime post, meta, and (in by_taxonomy mode) term-relationship
		// caches in batched queries, before the per-ID loops issue
		// up to 40 separate `wc_get_product()` + `is_product_syndicated()`
		// lookups. Same shape as the priming in
		// `maybe_convert_to_product_group()` for variation children;
		// `prime_syndication_cache()` is no-op in `all` and `selected`
		// modes.
		$candidate_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', array_merge( $cross_sells, $upsells ) ),
					static fn( $id ) => $id > 0
				)
			)
		);
		if ( ! empty( $candidate_ids ) ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $candidate_ids, false, false );
			}
			if ( function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'post', $candidate_ids );
			}
			WC_AI_Storefront::prime_syndication_cache( $candidate_ids, $settings );
		}

		if ( ! $skip_related ) {
			$related = $this->build_related_product_refs( $cross_sells, $settings );
			if ( ! empty( $related ) ) {
				$markup['isRelatedTo'] = $related;
			}
		}
		if ( ! $skip_similar ) {
			$similar = $this->build_related_product_refs( $upsells, $settings );
			if ( ! empty( $similar ) ) {
				$markup['isSimilarTo'] = $similar;
			}
		}
	}

	/**
	 * Build the `[{"@id": permalink}, ...]` array for a list of related
	 * product IDs, applying syndication-visibility, deleted-product,
	 * and cardinality-cap guards.
	 *
	 * Caller is responsible for the existing-markup-key guard — this
	 * helper unconditionally builds, returns empty array on no
	 * survivors.
	 *
	 * @param int[] $product_ids Candidate product IDs (raw from WC
	 *                           `get_cross_sell_ids()` /
	 *                           `get_upsell_ids()`).
	 * @param array $settings    Plugin settings (passed through to the
	 *                           per-ID syndication check).
	 * @return array<int,array<string,string>> List of `["@id" => url]`
	 *                                         entries, capped at
	 *                                         MAX_RELATED_PRODUCT_REFS.
	 */
	private function build_related_product_refs( array $product_ids, array $settings ): array {
		if ( empty( $product_ids ) || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$refs = array();
		foreach ( $product_ids as $candidate_id ) {
			if ( count( $refs ) >= self::MAX_RELATED_PRODUCT_REFS ) {
				break;
			}
			$candidate_id = (int) $candidate_id;
			if ( $candidate_id <= 0 ) {
				continue;
			}
			$candidate = wc_get_product( $candidate_id );
			// `wc_get_product()` returns false for deleted/trashed IDs
			// — WC doesn't auto-prune stale cross-sell/upsell IDs.
			if ( ! is_object( $candidate ) ) {
				continue;
			}
			if ( ! WC_AI_Storefront::is_product_syndicated( $candidate, $settings ) ) {
				continue;
			}
			$permalink = method_exists( $candidate, 'get_permalink' )
				? $candidate->get_permalink()
				: '';
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}
			$refs[] = array( '@id' => $permalink );
		}
		return $refs;
	}

	/**
	 * Output store-level JSON-LD on the homepage/shop page.
	 *
	 * `@type: OnlineBusiness` (an `Organization` subtype). Previously
	 * `OnlineStore` (a sub-subtype, "an eCommerce site"), which is too
	 * narrow for WC's full install base — services, subscriptions,
	 * donations, lead-gen, digital downloads, and traditional retail
	 * all emit the same homepage block. `OnlineBusiness` is the
	 * Schema.org parent in `Thing → Organization → OnlineBusiness →
	 * OnlineStore` and accurately describes any WC merchant doing
	 * business online without claiming product retail. All previously-
	 * emitted properties — `name`, `description`, `url`,
	 * `potentialAction`, `hasOfferCatalog`, identity fields — are
	 * defined on `Organization` (or `Thing`) and apply cleanly to
	 * `OnlineBusiness` via standard parent-to-child inheritance.
	 *
	 * Caveat: `currenciesAccepted` is defined on `OnlineStore` per the
	 * Schema.org spec — not on the `OnlineBusiness` parent. (Schema.org
	 * inheritance flows parent → child only; a property scoped to a
	 * subtype is NOT inherited "upward" by its parent.) We continue to
	 * emit `currenciesAccepted` despite the domain mismatch because:
	 * (1) it carries meaningful machine-readable signal that AI agents
	 * and search consumers parse for currency context regardless of
	 * the enclosing type, and (2) most consumers tolerate the pairing
	 * even when strict validators flag a non-fatal "unrecognized
	 * property for this type" warning. This is an accepted intentional
	 * non-domain pairing — stripping the signal to silence a warning
	 * would be a regression.
	 *
	 * `knowsAbout` (the array of top product category names) emits
	 * after the base shape and before the identity merge. It reuses
	 * the cached `get_catalog_summary()` data — no new query, no new
	 * cache. Omitted when the catalog is empty.
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

		// Hold the catalog summary in a local so it can drive both
		// `hasOfferCatalog.itemListElement` and the new `knowsAbout`
		// without `get_catalog_summary()` running twice. Pre-#334 the
		// call was inlined inside the array literal; the refactor
		// keeps both call sites pointed at one cache hit per page
		// render.
		//
		// `is_array()` normalization at the source: `get_catalog_summary()`
		// returns the raw transient value via `get_transient()`, which
		// can in principle hand back a non-array if the cache was
		// corrupted by external code or a stale value from a prior
		// schema. Funneling a scalar through to either consumer would
		// emit invalid Schema.org shape — `hasOfferCatalog.itemListElement`
		// expects an array of OfferCatalog entries, and `array_column()`
		// for `knowsAbout` would TypeError under PHP 8.1+. Coerce to
		// `array()` at the source so all downstream consumers are safe.
		$catalog = $this->get_catalog_summary();
		if ( ! is_array( $catalog ) ) {
			$catalog = array();
		}

		$store_data = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'OnlineBusiness',
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
				'itemListElement' => $catalog,
			),
		);

		// `knowsAbout` is Schema.org Organization's "what this org
		// knows about" pointer — emitted as an array of Text values
		// (top product category names). Sourced from the same
		// `get_catalog_summary()` data that drives `hasOfferCatalog`,
		// so the signal stays in sync with the actual catalog
		// composition. Omitted when the catalog is empty (or when
		// `get_catalog_summary()` returned an error WP_Error and
		// resolved to []) — no point claiming the org "knows about"
		// nothing. The `is_array()` normalization above guarantees
		// `array_column()` is type-safe here.
		if ( ! empty( $catalog ) ) {
			$store_data['knowsAbout'] = array_column( $catalog, 'name' );
		}

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

		// Organization-level return policy emission (#337 phase 1).
		//
		// Schema.org consumers read `Organization.hasMerchantReturnPolicy`
		// as the canonical store-wide commitment — the merchant's
		// "all our products follow this return policy unless an
		// individual offer overrides it" claim. Per-Offer emission
		// (in `add_return_policy()`) is the per-product override
		// surface and remains unchanged in this PR.
		//
		// `null` is passed for `$product_id` because Org-level
		// emission has no per-product context — `build_return_policy_block()`
		// already handles `null` correctly (skips the
		// `is_final_sale()` override branch). The shared builder
		// returns `null` when policy `mode` is `unconfigured` OR when
		// `mode: returns_accepted` is paired with an empty country
		// (a return-window declaration without a target region is
		// useless to validators). For `mode: final_sale` the builder
		// emits a `MerchantReturnNotPermitted` block regardless of
		// country — "no returns" is a globally meaningful claim. All
		// of those outcomes are funneled through the
		// `null !== $org_policy_block` check below — the gate emits
		// when the builder produced a block, suppresses when it didn't.
		//
		// Phase 2 (making per-Offer emission conditional on the
		// per-product final-sale override only) is deferred to a
		// separate PR — keeping this one purely additive.
		$policy           = isset( $settings['return_policy'] ) && is_array( $settings['return_policy'] )
			? $settings['return_policy']
			: array( 'mode' => 'unconfigured' );
		$base_location    = wc_get_base_location();
		$store_country    = $base_location['country'] ?? '';
		$org_policy_block = $this->build_return_policy_block( $policy, $store_country, null );
		if ( null !== $org_policy_block ) {
			$store_data['hasMerchantReturnPolicy'] = $org_policy_block;
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
	protected function build_return_policy_block( array $policy, string $country, ?int $product_id = null ): ?array {
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
