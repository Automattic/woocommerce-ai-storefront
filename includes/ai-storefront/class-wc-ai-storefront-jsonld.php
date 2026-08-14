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
	 * `suggestedGender` values Google documents and accepts, in English,
	 * regardless of store language. Schema.org's own comment on
	 * `suggestedGender` gives the same three as its examples.
	 *
	 * This list controls CASING normalisation only, not whether a value
	 * emits at all: {@see build_audience_block()} lowercases a
	 * case-insensitive match against this list to Google's canonical
	 * form, but a value that isn't in this list still emits as
	 * `suggestedGender`, verbatim and trimmed. `schema:suggestedGender`
	 * is Text-ranged, so an unrecognised value is still structurally
	 * valid markup — Google's own Merchant Center / Search Console
	 * diagnostics are the intended place to tell the merchant it's
	 * wrong, not silent validation here. Contrast with
	 * {@see AUDIENCE_AGE_GROUPS}, whose keys really do gate emission,
	 * because `suggestedAge` is a `QuantitativeValue` with no honest
	 * default for an unmapped bucket. See {@see build_audience_block()}
	 * for the full reasoning — this asymmetry is deliberate, not an
	 * inconsistency to "fix".
	 *
	 * @var string[]
	 */
	private const AUDIENCE_GENDER_VALUES = array( 'male', 'female', 'unisex' );

	/**
	 * Google `age_group` buckets mapped to a Schema.org `QuantitativeValue`.
	 *
	 * Google's structured-data documentation uses `suggestedAge` (a
	 * QuantitativeValue) rather than `suggestedMinAge`/`suggestedMaxAge`,
	 * and the `unitCode` is what keeps the sub-1 buckets honest: newborn
	 * and infant are expressed in months (MON) rather than as fractions
	 * of a year, so they stay distinguishable.
	 *
	 * `adult` carries no `max` — Google's own worked example is the adult
	 * case and bounds it only from below.
	 *
	 * @var array<string, array{min: float, max: float|null, unit: string}>
	 */
	private const AUDIENCE_AGE_GROUPS = array(
		'newborn' => array(
			'min'  => 0.0,
			'max'  => 3.0,
			'unit' => 'MON',
		),
		'infant'  => array(
			'min'  => 3.0,
			'max'  => 12.0,
			'unit' => 'MON',
		),
		'toddler' => array(
			'min'  => 1.0,
			'max'  => 5.0,
			'unit' => 'ANN',
		),
		'kids'    => array(
			'min'  => 5.0,
			'max'  => 13.0,
			'unit' => 'ANN',
		),
		'adult'   => array(
			'min'  => 13.0,
			'max'  => null,
			'unit' => 'ANN',
		),
	);

	/**
	 * Attribute slugs recognised as Gender and Age group, mapped to the
	 * field they feed and their precedence when a product carries both
	 * the `pa_` and the bare form for the same field.
	 *
	 * The plugin creates and seeds `pa_gender` / `pa_age_group` itself
	 * (see {@see WC_AI_Storefront_Attribute_Seeder}) — Google treats them
	 * as required for Apparel & Accessories even though WooCommerce core
	 * takes no position on either. Because we create these two
	 * attributes, seed their terms with exactly Google's accepted
	 * values, and point merchants at them in the user guide, `pa_gender`
	 * / `pa_age_group` are constrained to valid values by construction
	 * and are the authoritative source. The bare `gender` / `age_group`
	 * forms are the compatibility path for a merchant who built (or
	 * already had) a custom product-level attribute of their own,
	 * instead of adopting the seeded ones — mirroring how
	 * CORE_ATTRIBUTE_MAP lists both `pa_color` and `color`.
	 *
	 * `priority` (lower wins) encodes that precedence: if a product
	 * carries both `pa_gender` and a bare `gender` with different
	 * values, `pa_gender` (priority 0) is the one
	 * {@see build_audience_block()} sees; the bare `gender` (priority 1)
	 * is outranked. The outranked value is NOT discarded — it cannot
	 * occupy the single `audience` slot alongside its winner, so
	 * {@see emit_attributes()} routes it to `additionalProperty` instead,
	 * keyed by its own slug so the collision never causes either value
	 * to silently vanish.
	 *
	 * Keys are compared after lowercasing and after collapsing spaces and
	 * hyphens to underscores, so a custom attribute the merchant labelled
	 * "Age group" still matches.
	 *
	 * @var array<string, array{field: string, priority: int}>
	 */
	private const AUDIENCE_ATTRIBUTE_MAP = array(
		'pa_gender'    => array(
			'field'    => 'gender',
			'priority' => 0,
		),
		'gender'       => array(
			'field'    => 'gender',
			'priority' => 1,
		),
		'pa_age_group' => array(
			'field'    => 'age_group',
			'priority' => 0,
		),
		'age_group'    => array(
			'field'    => 'age_group',
			'priority' => 1,
		),
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
	 * Transient key for the site-wide WebSite + SearchAction JSON-LD block.
	 *
	 * Registered with WC_AI_Storefront_Cache_Invalidator so it is busted
	 * on product, category, and settings changes — the block embeds the
	 * store URL and REST endpoint which change only on settings saves, but
	 * using the same invalidation path keeps cache lifetimes consistent.
	 */
	public const WEBSITE_JSONLD_CACHE_KEY     = 'wc_ai_storefront_website_jsonld';
	public const ITEMLIST_JSONLD_CACHE_PREFIX = 'wc_ai_storefront_itemlist_';

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_structured_data_product', [ $this, 'enhance_product_data' ], 20, 2 );
		add_filter( 'woocommerce_structured_data_type_for_page', [ $this, 'allow_product_group_type' ] );
		add_action( 'wp_head', [ $this, 'output_website_jsonld' ], 4 );
		add_action( 'wp_head', [ $this, 'output_store_jsonld' ], 5 );
		add_action( 'wp_head', [ $this, 'output_archive_itemlist_jsonld' ], 6 );

		// Replace WC's structured-data serializer with our own so we can
		// use JSON_HEX_AMP. WC's wc_esc_json() converts every literal '&'
		// to '&amp;' after wp_json_encode(), breaking checkout URLs for
		// non-browser consumers (curl, LLM tool calls). Our replacement
		// skips wc_esc_json() and applies JSON_HEX_AMP instead, encoding
		// '&' as '&' — safe for both browsers and raw consumers.
		// WC_Structured_Data is instantiated inside WC::init() which runs
		// on the 'init' action; we must defer until 'woocommerce_init'
		// (fired at the end of WC::init()) to access WC()->structured_data.
		// Note: output_email_structured_data() calls output_structured_data()
		// directly (not via wp_footer), so email order details are unaffected.
		add_action( 'woocommerce_init', [ $this, 'replace_wc_structured_data_output' ] );
	}

	/**
	 * Swap WC's wp_footer serializer for ours.
	 *
	 * Called on 'woocommerce_init' (after WC()->structured_data exists).
	 * Registered as a public method so it can be called by tests or removed
	 * by merchants who want to keep WC's default output intact.
	 */
	public function replace_wc_structured_data_output(): void {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! $wc || ! isset( $wc->structured_data ) ) {
			return;
		}
		remove_action( 'wp_footer', [ $wc->structured_data, 'output_structured_data' ], 10 );
		add_action( 'wp_footer', [ $this, 'output_wc_structured_data' ], 10 );
	}

	/**
	 * Re-implementation of WC_Structured_Data::output_structured_data() that
	 * skips wc_esc_json() and uses JSON_HEX_AMP so '&' is encoded as '&'
	 * instead of '&amp;'.
	 *
	 * The type-detection logic replicates WC_Structured_Data::get_data_type_for_page()
	 * (class-wc-structured-data.php, WC 9.x/10.x, unchanged since WC 3.0.0).
	 * If WC ever changes that method, update the conditional tree below to match.
	 */
	public function output_wc_structured_data(): void {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! $wc || ! isset( $wc->structured_data ) ) {
			return;
		}

		// Mirror WC_Structured_Data::get_data_type_for_page() exactly.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$types   = array();
		$types[] = ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_product' ) && is_product() ) ? 'product' : '';
		$types[] = ( function_exists( 'is_shop' ) && is_shop() ) && is_front_page() ? 'website' : '';
		$types[] = ( function_exists( 'is_product' ) && is_product() ) ? 'review' : '';
		$types[] = 'breadcrumblist';
		$types[] = 'order';
		$types   = array_filter(
			apply_filters( 'woocommerce_structured_data_type_for_page', $types )
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$data = $wc->structured_data->get_structured_data( $types );
		if ( ! $data ) {
			return;
		}

		$encoded = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
		if ( false !== $encoded ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_* applied
			echo '<script type="application/ld+json">' . $encoded . '</script>' . "\n";
		}
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

		// Skip checkout action URLs when the product itself is not
		// purchasable (no price, draft, catalog-hidden). The descriptive
		// product entity still emits — crawlers see the product exists,
		// just without a monetary action that would 4xx at checkout. For
		// variable parents, `maybe_convert_to_product_group()` later
		// overrides this by converting to ProductGroup and emitting
		// per-variant entries under `hasVariant`, each gated
		// independently (see `build_variant_entry`). (#373)
		$parent_purchasable = ( ! method_exists( $product, 'is_purchasable' ) || $product->is_purchasable() )
			&& self::is_orderable( $product );
		if ( $parent_purchasable ) {
			$this->add_buy_action( $markup, $product );
			$this->add_checkout_page_url_template( $markup, $product );
		}

		$this->add_inventory_level( $markup, $product );

		$this->add_category_path( $markup, $product );

		$this->add_dimensions( $markup, $product );

		$this->emit_attributes( $markup, $product );

		$base_location = wc_get_base_location();
		$country       = $base_location['country'] ?? '';

		$this->add_currency( $markup );
		$this->add_sale_window( $markup, $product );
		$this->add_subscription_signals( $markup, $product );
		$this->decode_seller_name( $markup );
		$this->add_shipping_details( $markup, $country, $product );
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
	 * Adds a BuyAction potentialAction pointing at the bare store-checkout
	 * URL.
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
	 * Build the checkout URL for a product or variation. Bare by design:
	 * NO utm_* params. This value is emitted on BOTH BuyAction.urlTemplate
	 * and Offer.checkoutPageURLTemplate, which search engines (Google's
	 * crawled-checkout feature) surface to human shoppers. A UTM here would
	 * (a) let a literal, unsubstituted {agent_id} land in order attribution,
	 * (b) STRICT-fire capture_ai_attribution() on utm_id, mis-labeling a
	 * human sale as an AI referral, and (c) override WooCommerce's native
	 * Origin (Organic: Google / Referral: bing.com), which is computed from
	 * the Referer whenever the URL carries no utm_* param. Agent attribution
	 * does not rely on this URL: agents identify via the UCP-Agent header on
	 * the /checkout-sessions path. Buy-link origin is captured separately
	 * from WooCommerce's session_entry meta (see the attribution class).
	 *
	 * Two emission shapes, gated by WC product type:
	 *   - Simple, variable, variation: WooCommerce Shareable Checkout URL
	 *     ({home}/checkout-link/?products=ID:1) — resolves through WC's
	 *     rewrite handler, adds the item, redirects to checkout.
	 *   - Bundle, grouped: the product permalink. The ?products=ID:1
	 *     shorthand cannot represent these (bundle needs per-item config;
	 *     grouped parent has no SKU) — the buyer lands on the PDP where WC's
	 *     configurator runs.
	 *
	 * Static so callers without a class instance (the per-variant builder
	 * under hasVariant) can build URLs uniformly.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return string The bare checkout URL.
	 */
	private static function build_checkout_url_template( $product ): string {
		// Bundle and grouped: emit the product permalink (bare). See docblock
		// for why /checkout-link/?products= can't represent these types.
		if ( $product->is_type( 'bundle' ) || $product->is_type( 'grouped' ) ) {
			return $product->get_permalink();
		}

		// Simple, variable, variation: WooCommerce Shareable Checkout URL.
		// Quantity fixed at 1 — AI-shopping flows are single-item by convention.
		return self::decode_query_url(
			array( 'products' => $product->get_id() . ':1' ),
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
	 *     components. Each entry carries `billingDuration` (ISO 8601)
	 *     and `priceComponentType: Subscription` for the recurring
	 *     price. The trial-then-paid pattern is conveyed by array
	 *     order (trial entry FIRST at `price: 0`, recurring entry
	 *     SECOND at full price) without using `billingStart` —
	 *     Schema.org types `billingStart` as `Number`, not Duration,
	 *     so a `P14D` value there would violate the spec contract.
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

		$period         = WC_Subscriptions_Product::get_period( $product );
		$interval       = WC_Subscriptions_Product::get_interval( $product );
		$length         = WC_Subscriptions_Product::get_length( $product );
		$signup_fee_str = (string) WC_Subscriptions_Product::get_sign_up_fee( $product );
		$signup_fee     = (float) $signup_fee_str;
		$trial_length   = WC_Subscriptions_Product::get_trial_length( $product );
		$trial_period   = WC_Subscriptions_Product::get_trial_period( $product );

		// Sanity-gate the recurring signal. A subscription product with
		// `interval <= 0` is corrupted (no valid recurring cadence) — the
		// trial path is symmetrically gated by `$trial_length > 0`, this
		// branch matches that discipline. Emit nothing rather than a
		// nonsensical `billingDuration: P0D`. Log as merchant-actionable
		// misconfig — same pattern as `resolve_default_variation_id()`'s
		// orphan-default breadcrumb.
		if ( $interval <= 0 ) {
			if ( class_exists( 'WC_AI_Storefront_Logger' ) && function_exists( 'apply_filters' ) ) {
				WC_AI_Storefront_Logger::debug(
					sprintf(
						'JSON-LD add_subscription_signals(%d): subscription has interval=%d (must be > 0). Skipping subscription signal emission — fix the product configuration.',
						(int) $product->get_id(),
						$interval
					)
				);
			}
			return;
		}

		// Period whitelist — `period_to_iso8601_duration()` and
		// `period_to_uncefact_code()` silently fall back to month / MON
		// for unknown periods, which is safer than fataling but masks
		// merchant-actionable misconfiguration (typo, WC Subscriptions
		// extension defining a custom period, etc.). Log so debug-mode
		// logs surface the case, then proceed with the safe defaults.
		$known_periods = array( 'day', 'week', 'month', 'year' );
		if ( ! in_array( $period, $known_periods, true )
			&& class_exists( 'WC_AI_Storefront_Logger' ) && function_exists( 'apply_filters' ) ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'JSON-LD add_subscription_signals(%d): unknown subscription period %s, falling back to month. Check the product configuration.',
					(int) $product->get_id(),
					wp_json_encode( $period )
				)
			);
		}

		// Negative sign-up fee = data-entry error. WC Subscriptions
		// accepts negative numeric input in the field but the signal
		// has no semantic meaning. Drop and log so the merchant can
		// catch it.
		if ( $signup_fee < 0 && class_exists( 'WC_AI_Storefront_Logger' ) && function_exists( 'apply_filters' ) ) {
			WC_AI_Storefront_Logger::debug(
				sprintf(
					'JSON-LD add_subscription_signals(%d): negative sign-up fee %s — dropping. Likely a merchant data-entry error.',
					(int) $product->get_id(),
					$signup_fee_str
				)
			);
		}

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

		// Trial entry, when set. Free by definition — WC Subscriptions'
		// trial period IS the free window.
		//
		// The trial-then-paid sequence is communicated by:
		//   1. Array order: trial entry FIRST, recurring entry SECOND.
		//   2. The trial entry carries `price: 0` — a consumer reading
		//      the array sees "free window for N units, then full price."
		//
		// We deliberately do NOT emit `billingStart` on the recurring
		// entry. Per https://schema.org/billingStart, billingStart is
		// typed `Number` (not Duration / ISO 8601 string), and would
		// inherit `unitCode` from the UnitPriceSpecification's own
		// unitCode property. Two complications make billingStart
		// unsuitable here:
		//   - Mixed units: a 14-day trial preceding monthly billing
		//     forces a unit-coercion choice (14/30 = 0.47 months?) with
		//     no clean answer.
		//   - Schema.org accepts Duration on `billingDuration` but not
		//     `billingStart`. Emitting `P14D` for billingStart would
		//     violate the spec's type contract.
		// Array semantics + price=0 are unambiguous for the trial case
		// without needing a numeric offset.
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

		$price_specs[] = array(
			'@type'              => 'UnitPriceSpecification',
			'priceComponentType' => 'https://schema.org/Subscription',
			'price'              => $price,
			'priceCurrency'      => $currency,
			'billingDuration'    => self::period_to_iso8601_duration( $period, $interval ),
		);

		// Sign-up fee: emit BOTH the inline `ActivationFee` priceComponent
		// AND `Offer.addOn` for compat (decision #1 — "future-ready now").
		// Inline form is semantically richer (`priceComponentType`
		// enumeration); `addOn` uses released vocabulary that broader
		// consumers will recognize today. Duplication is spec-legal.
		if ( $signup_fee > 0 ) {
			$price_specs[]                = array(
				'@type'              => 'UnitPriceSpecification',
				'priceComponentType' => 'https://schema.org/ActivationFee',
				'price'              => $signup_fee_str,
				'priceCurrency'      => $currency,
			);
			$markup['offers'][0]['addOn'] = array(
				'@type'         => 'Offer',
				'name'          => __( 'Sign-up fee', 'woocommerce-ai-storefront' ),
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
	 * `UnitPriceSpecification.billingDuration`, which accepts a Duration
	 * value (one of three valid types per https://schema.org/billingDuration:
	 * Duration | Number | QuantitativeValue). ISO 8601 strings like 'P1M'
	 * are the Duration form.
	 *
	 * Not used for `billingStart` — that field is typed `Number` only
	 * (https://schema.org/billingStart) and rejects ISO 8601 strings.
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
		$units = array(
			'day'   => 'D',
			'week'  => 'W',
			'month' => 'M',
			'year'  => 'Y',
		);
		$unit  = $units[ $period ] ?? 'M';
		return 'P' . $count . $unit;
	}

	/**
	 * Map a WC subscription period to a UN/CEFACT unit code for
	 * `QuantitativeValue.unitCode` on `Offer.eligibleDuration`.
	 *
	 * UN/CEFACT Recommendation N°20 common codes: DAY (day), WEE (week),
	 * MON (month), ANN (year). Schema.org's `QuantitativeValue.unitCode`
	 * accepts these codes as the unit identifier.
	 *
	 * Unknown periods fall back to 'MON' — same safe-default rationale
	 * as `period_to_iso8601_duration()`.
	 *
	 * @param string $period WC period — 'day' | 'week' | 'month' | 'year'.
	 * @return string UN/CEFACT unit code — 'DAY' | 'WEE' | 'MON' | 'ANN'.
	 */
	private static function period_to_uncefact_code( string $period ): string {
		$codes = array(
			'day'   => 'DAY',
			'week'  => 'WEE',
			'month' => 'MON',
			'year'  => 'ANN',
		);
		return $codes[ $period ] ?? 'MON';
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
	 * A product oversold under an allow-backorders setting carries a
	 * NEGATIVE `stock_quantity`, which is clamped to 0 here. schema.org
	 * defines `inventoryLevel` as the "current approximate inventory
	 * level", so 0 misrepresents nothing the property promised to be
	 * exact, while a negative quantity leaves an agent to guess. The
	 * "still orderable" signal is carried by `availability: BackOrder`
	 * instead — set by WC core on the parent Offer, and by
	 * `stock_status_to_schema()` on the per-variant Offers built here.
	 *
	 * Google's merchant-listing spec does not list `inventoryLevel` among
	 * the Offer properties it reads (checked 2026-07), so this field is
	 * aimed at AI agents rather than search validators.
	 *
	 * Zero itself is a meaningful level ("none on hand") and is emitted
	 * normally; only `null` (not tracked) suppresses the property.
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
				'value' => max( 0, $stock_qty ),
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
	 * Every value is cast through (float) to produce a canonical numeric
	 * value — WC persists weight and dimensions alike as free-form strings
	 * (e.g. `.5`, `10`) that strict JSON-LD parsers would see as quoted
	 * string literals. `QuantitativeValue.value` is Number-ranged.
	 *
	 * Audit bug #4 introduced the weight cast; the three dimension values
	 * carried the same defect until #613 because `get_dimensions( false )`
	 * returns the raw props untouched.
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
				'value'    => (float) $dimensions['length'],
				'unitCode' => $dimension_unit,
			);
			$markup['width']  = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $dimensions['width'],
				'unitCode' => $dimension_unit,
			);
			$markup['height'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $dimensions['height'],
				'unitCode' => $dimension_unit,
			);
		}
	}

	/**
	 * Emit each visible attribute either as its typed Schema.org property
	 * (color/size/material/pattern), the `audience` PeopleAudience block
	 * (gender/age group), or as an `additionalProperty` entry. Single
	 * pass — one `get_attribute()` lookup per attribute.
	 *
	 * Per-attribute decision tree:
	 *   1. Hidden / variation-defining / empty value → skip entirely.
	 *   2. Maps to Gender or Age group (see AUDIENCE_ATTRIBUTE_MAP) →
	 *      held in a pending collector keyed by slug (not field), so a
	 *      losing attribute in a `pa_`-vs-bare collision is judged, and
	 *      routed to `additionalProperty`, independently of its winner.
	 *      The highest-precedence (lowest `priority`) value per field is
	 *      what actually reaches {@see build_audience_block()}; routing
	 *      of every pending entry is decided after the loop, once that
	 *      call has run.
	 *   3. Maps to a typed property AND value is single-valued AND no
	 *      upstream owner of the typed key → emit as typed property,
	 *      skip additionalProperty for this slug.
	 *   4. Otherwise (unmapped, multi-value, or upstream-owns-typed) →
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
		// Highest-precedence (lowest `priority`) value seen so far, per
		// field. `priority` starts above any real value (0 or 1) so the
		// first attribute encountered for a field always wins initially.
		$audience_winners = array(
			'gender'    => array(
				'slug'     => '',
				'value'    => '',
				'priority' => PHP_INT_MAX,
			),
			'age_group' => array(
				'slug'     => '',
				'value'    => '',
				'priority' => PHP_INT_MAX,
			),
		);
		// Keyed by SLUG (not field): a `pa_`-vs-bare collision must not
		// let one attribute's pending entry overwrite the other's — each
		// is judged for additionalProperty on its own, after the loop.
		$audience_pending = array();
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

			// Gender / Age group route to the typed `audience` block rather
			// than to a Schema.org property on Product itself. Normalise
			// separators first: a custom attribute labelled "Age group"
			// arrives as `age group`.
			$audience_key = str_replace( array( ' ', '-' ), '_', $slug );
			if ( isset( self::AUDIENCE_ATTRIBUTE_MAP[ $audience_key ] ) ) {
				$field    = self::AUDIENCE_ATTRIBUTE_MAP[ $audience_key ]['field'];
				$priority = self::AUDIENCE_ATTRIBUTE_MAP[ $audience_key ]['priority'];

				// `pa_gender` / `pa_age_group` are the attributes this
				// plugin creates and seeds with Google's accepted values,
				// so they are authoritative by construction; a bare
				// `gender` / `age_group` attribute is the compatibility
				// fallback for a merchant's own pre-existing custom
				// attribute. See AUDIENCE_ATTRIBUTE_MAP for the full
				// rationale.
				if ( $priority < $audience_winners[ $field ]['priority'] ) {
					$audience_winners[ $field ] = array(
						'slug'     => $slug,
						'value'    => $value,
						'priority' => $priority,
					);
				}

				$audience_pending[ $slug ] = array(
					'@type' => 'PropertyValue',
					'name'  => wc_attribute_label( $attribute->get_name(), $product ),
					'value' => $value,
				);
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

		// Build the typed block from each field's highest-precedence
		// value. Gender always types once non-empty (see
		// build_audience_block()); age group types only when the bucket
		// is recognised — an unrecognised bucket falls back to
		// additionalProperty below like any other unmapped attribute.
		$audience = self::build_audience_block(
			$audience_winners['gender']['value'],
			$audience_winners['age_group']['value']
		);
		if ( ! empty( $audience ) ) {
			$markup['audience'] = $audience;
		}

		// A pending entry is excluded from additionalProperty only when
		// its slug is the winning slug for its field AND that field
		// actually typed. Every other pending entry — outranked by a
		// same-field sibling, or the winner itself when its field didn't
		// type — still needs to reach agents somehow.
		$typed_winner_slugs = array();
		if ( isset( $audience['suggestedGender'] ) ) {
			$typed_winner_slugs[] = $audience_winners['gender']['slug'];
		}
		if ( isset( $audience['suggestedAge'] ) ) {
			$typed_winner_slugs[] = $audience_winners['age_group']['slug'];
		}
		foreach ( $audience_pending as $slug => $property ) {
			if ( ! in_array( $slug, $typed_winner_slugs, true ) ) {
				$additional_properties[] = $property;
			}
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
	 * Builds the `Product.audience` → `PeopleAudience` block.
	 *
	 * Google requires `gender` and `age_group` on all Apparel & Accessories
	 * products, and reads them from this typed structure. A merchant's
	 * Gender attribute emitted as a generic `additionalProperty` entry is
	 * published but invisible to Google for that purpose, which for an
	 * apparel product means disapproval rather than a thinner listing.
	 *
	 * Gender and age group are gatekept asymmetrically. This is
	 * deliberate — do not "fix" it into matching, and do not remove it:
	 *
	 *   - `suggestedGender` is Text-ranged, so ANY non-empty, trimmed
	 *     value is structurally valid markup — there is nothing to
	 *     reject. A value matching {@see AUDIENCE_GENDER_VALUES}
	 *     case-insensitively is normalised to Google's canonical
	 *     lowercase form; anything else passes through exactly as the
	 *     merchant typed it, trimmed. We do not pre-validate against
	 *     Google's three accepted values before emitting: Merchant
	 *     Center / Search Console will flag an unrecognised value
	 *     directly to the merchant, and that diagnostic — plus our
	 *     documentation, plus a future Product Editor surface — is the
	 *     intended correction path. Silently dropping or guessing at the
	 *     value here would deny the merchant all three feedback
	 *     channels.
	 *   - `suggestedAge` is a `QuantitativeValue` — it needs `minValue` /
	 *     `maxValue` / `unitCode`. An unmapped bucket like "Grown-up" has
	 *     no numbers to compute, so there is nothing honest to emit for
	 *     it. This is a data-model constraint, not a validation choice:
	 *     unlike gender, there is no verbatim fallback shape for a
	 *     QuantitativeValue. The caller ({@see emit_attributes()}) routes
	 *     an unrecognised age group to `additionalProperty` instead, so
	 *     the merchant's data still reaches agents, just untyped.
	 *
	 * @param string $gender    Raw Gender attribute value — already the
	 *                          highest-precedence value when the caller
	 *                          resolved a collision between `pa_gender`
	 *                          and a bare `gender` attribute (see
	 *                          AUDIENCE_ATTRIBUTE_MAP for the precedence
	 *                          rule).
	 * @param string $age_group Raw Age group attribute value, same
	 *                          precedence contract as $gender.
	 * @return array The PeopleAudience block. Empty only when $gender is
	 *               empty (after trim) AND $age_group did not map to a
	 *               recognised bucket.
	 */
	private static function build_audience_block( string $gender, string $age_group ): array {
		$block = array();

		$gender_trimmed = trim( $gender );
		if ( '' !== $gender_trimmed ) {
			$gender_lower             = strtolower( $gender_trimmed );
			$block['suggestedGender'] = in_array( $gender_lower, self::AUDIENCE_GENDER_VALUES, true )
				? $gender_lower
				: $gender_trimmed;
		}

		$age_key = strtolower( trim( $age_group ) );
		if ( isset( self::AUDIENCE_AGE_GROUPS[ $age_key ] ) ) {
			$bucket = self::AUDIENCE_AGE_GROUPS[ $age_key ];

			$suggested_age = array(
				'@type'    => 'QuantitativeValue',
				'minValue' => $bucket['min'],
			);
			if ( null !== $bucket['max'] ) {
				$suggested_age['maxValue'] = $bucket['max'];
			}
			$suggested_age['unitCode'] = $bucket['unit'];

			$block['suggestedAge'] = $suggested_age;
		}

		if ( empty( $block ) ) {
			return array();
		}

		return array_merge( array( '@type' => 'PeopleAudience' ), $block );
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
	 * Resolves an attribute slug to the Schema.org property it varies by.
	 *
	 * Checks the four core typed slugs ({@see CORE_ATTRIBUTE_MAP}) first,
	 * then the two audience slugs ({@see AUDIENCE_ATTRIBUTE_MAP}). Google
	 * lists `suggestedAge` and `suggestedGender` among the properties
	 * valid in `variesBy`, alongside the core four, so an age- or
	 * gender-keyed variable product can advertise its real axis instead
	 * of a plain text label. Shared by both {@see detect_varies_by()}
	 * lookup sites — the parent-flagged path and the typed-slug override
	 * — so they agree on what counts as typed.
	 *
	 * @param string $slug_lower Lowercased attribute slug.
	 * @return string Schema.org property name, or '' when unmapped.
	 */
	private static function varies_by_property( string $slug_lower ): string {
		if ( isset( self::CORE_ATTRIBUTE_MAP[ $slug_lower ] ) ) {
			return self::CORE_ATTRIBUTE_MAP[ $slug_lower ];
		}

		$audience_key = str_replace( array( ' ', '-' ), '_', $slug_lower );
		if ( isset( self::AUDIENCE_ATTRIBUTE_MAP[ $audience_key ] ) ) {
			return 'gender' === self::AUDIENCE_ATTRIBUTE_MAP[ $audience_key ]['field']
				? 'suggestedGender'
				: 'suggestedAge';
		}

		return '';
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
	 * **Typed-slug override**: If a slug maps to a Schema.org typed
	 * property — a core one via {@see CORE_ATTRIBUTE_MAP} (color / size /
	 * material / pattern) or an audience one via
	 * {@see AUDIENCE_ATTRIBUTE_MAP} (gender / age group) — we also inspect
	 * the variation children's own attribute meta directly, not just the
	 * parent's `get_variation_attributes()`. WC's parent-level "Used for
	 * variations" flag gates `get_variation_attributes()` but not the
	 * underlying variation meta; merchants who configure `pa_color` (or
	 * `pa_gender`) with distinct values across variations but forget to
	 * flag it as a variation axis still get correct ProductGroup emission,
	 * because the data is right there on each child even if the parent
	 * flag is wrong. This override is intentionally limited to slugs with
	 * a canonical Schema.org typed property — they are the axes AI agents
	 * are most likely to query (and, for gender/age group, the ones
	 * Google requires for Apparel & Accessories), so getting them right
	 * matters more than honoring a likely-misconfigured parent flag. An
	 * unmapped custom axis still honors the parent flag, same as before.
	 *
	 * Slug → Schema.org property mapping uses {@see varies_by_property()},
	 * shared with the override path so both sites agree on what counts as
	 * typed. Mapped attributes emit as full Schema.org URLs (e.g.
	 * `https://schema.org/color`, `https://schema.org/suggestedGender`);
	 * unmapped attributes (custom merchant axes like "Style" or "Heel
	 * Height") emit as plain Text labels — Schema.org `variesBy` accepts
	 * both shapes.
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
			$property   = self::varies_by_property( $slug_lower );
			if ( '' !== $property ) {
				$varies_urls[] = 'https://schema.org/' . $property;
			} else {
				$varies_labels[] = function_exists( 'wc_attribute_label' )
					? wc_attribute_label( $slug, $product )
					: $slug;
			}
		}

		// Path 2: typed-slug override. For slugs with a canonical
		// Schema.org typed property — core (color / size / material /
		// pattern) or audience (gender / age group) — also peek at the
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
	 * Inspect variation children's attribute meta to find typed axes —
	 * core (color / size / material / pattern) or audience (gender / age
	 * group) — that have ≥2 distinct values across children, even if the
	 * parent's "Used for variations" flag is unset on the matching
	 * attribute.
	 *
	 * Returns Schema.org property URLs only (no Text labels), because
	 * the override is scoped to slugs with a canonical Schema.org typed
	 * property by design ({@see CORE_ATTRIBUTE_MAP} and
	 * {@see AUDIENCE_ATTRIBUTE_MAP}).
	 *
	 * @param WC_Product $product The variable product (parent).
	 * @return string[] Schema.org URLs for typed axes that factually vary.
	 */
	private static function detect_core_typed_axes_from_children( $product ): array {
		$children = $product->get_children();
		if ( ! is_array( $children ) || count( $children ) < 2 ) {
			// Need at least 2 children to compare values across.
			return array();
		}

		// Bucket: slug → set of distinct non-empty values seen. Merges
		// core-typed and audience postmeta reads — both are scoped to a
		// fixed, known slug list, so merging by slug key cannot collide.
		$values_by_slug = array();
		foreach ( $children as $child_id ) {
			$attrs = array_merge(
				self::read_variation_core_attributes( (int) $child_id ),
				self::read_variation_audience_attributes( (int) $child_id )
			);
			foreach ( $attrs as $slug_lower => $value_str ) {
				$values_by_slug[ $slug_lower ][ $value_str ] = true;
			}
		}

		$urls = array();
		foreach ( $values_by_slug as $slug_lower => $value_set ) {
			if ( count( $value_set ) < 2 ) {
				continue;
			}
			$property = self::varies_by_property( $slug_lower );
			if ( '' !== $property ) {
				$urls[] = 'https://schema.org/' . $property;
			}
		}
		return $urls;
	}

	/**
	 * Read a variation's core-typed attribute values directly from
	 * postmeta — bypassing the parent's "Used for variations" flag.
	 *
	 * Thin wrapper around {@see read_variation_attributes_from_map()}
	 * scoped to {@see CORE_ATTRIBUTE_MAP} (color / size / material /
	 * pattern) — they have canonical Schema.org typed properties;
	 * unmapped custom attributes intentionally honor the parent's flag.
	 *
	 * @param int $variation_id The variation post ID.
	 * @return array<string,string> Slug → trimmed value, only for non-empty
	 *                              core typed slugs.
	 */
	private static function read_variation_core_attributes( int $variation_id ): array {
		return self::read_variation_attributes_from_map( $variation_id, self::CORE_ATTRIBUTE_MAP );
	}

	/**
	 * Read a variation's Gender / Age group attribute values directly from
	 * postmeta — the audience counterpart to
	 * {@see read_variation_core_attributes()}.
	 *
	 * Thin wrapper around {@see read_variation_attributes_from_map()}
	 * scoped to {@see AUDIENCE_ATTRIBUTE_MAP}.
	 *
	 * Per-SLUG, not per-field: a `pa_gender`-vs-bare-`gender` collision
	 * (both present with different values) is left for the caller to
	 * resolve via {@see AUDIENCE_ATTRIBUTE_MAP}'s `priority` — this
	 * function only reports what postmeta actually holds.
	 *
	 * @param int $variation_id The variation post ID.
	 * @return array<string,string> Slug → trimmed value, only for non-empty
	 *                               recognised audience slugs.
	 */
	private static function read_variation_audience_attributes( int $variation_id ): array {
		return self::read_variation_attributes_from_map( $variation_id, self::AUDIENCE_ATTRIBUTE_MAP );
	}

	/**
	 * Read a variation's attribute values directly from postmeta for
	 * every slug in the given map — bypassing the parent's "Used for
	 * variations" flag. Shared implementation behind
	 * {@see read_variation_core_attributes()} and
	 * {@see read_variation_audience_attributes()}, which differ only in
	 * which const map they scan; collapsing them here means the postmeta
	 * key lookup (including its hyphen fallback, below) is fixed once
	 * instead of drifting between two copies.
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
	 * **Hyphen fallback**: WooCommerce builds that meta key via
	 * `wc_variation_attribute_name()` = `'attribute_' . sanitize_title( $name )`,
	 * and `sanitize_title_with_dashes()` converts whitespace to a HYPHEN,
	 * not an underscore. Our map keys use underscores (matching Google's
	 * canonical `age_group`-style names), so a merchant's multi-word
	 * custom attribute — e.g. one literally labelled "Age group" — writes
	 * its variation postmeta to `attribute_age-group`, not
	 * `attribute_age_group`. This is the exact scenario
	 * {@see AUDIENCE_ATTRIBUTE_MAP}'s own docblock cites to justify the
	 * bare-slug fallback existing at all, and it applies equally to any
	 * future multi-word CORE_ATTRIBUTE_MAP entry — none of the current
	 * four (color/size/material/pattern) happens to be multi-word, which
	 * is why this only surfaced on the audience side. We do not guess
	 * which form a given merchant's attribute used — a plain single-word
	 * key never collides (there is nothing to hyphenate), and a
	 * multi-word key probes the exact form first, falling back to the
	 * hyphenated form only when the exact form is empty.
	 *
	 * @param int                 $variation_id The variation post ID.
	 * @param array<string,mixed> $map          A const attribute-slug map
	 *                                          (only the key set is used;
	 *                                          CORE_ATTRIBUTE_MAP and
	 *                                          AUDIENCE_ATTRIBUTE_MAP have
	 *                                          different value shapes).
	 * @return array<string,string> Slug (the map's key, not the postmeta
	 *                              key that matched) → trimmed value, only
	 *                              for non-empty recognised slugs.
	 */
	private static function read_variation_attributes_from_map( int $variation_id, array $map ): array {
		if ( $variation_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$out = array();
		foreach ( $map as $slug_lower => $_unused ) {
			$value     = get_post_meta( $variation_id, 'attribute_' . $slug_lower, true );
			$value_str = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value_str && false !== strpos( $slug_lower, '_' ) ) {
				$hyphenated = str_replace( '_', '-', $slug_lower );
				$value      = get_post_meta( $variation_id, 'attribute_' . $hyphenated, true );
				$value_str  = is_string( $value ) ? trim( $value ) : '';
			}
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
			$entry = $this->build_variant_entry( $variation, $product, $settings, $country );
			// Inherit the parent ProductGroup's WC-core base fields
			// (description, brand, category, offer seller/priceValidUntil)
			// onto the from-scratch variant node. MUST run while
			// `$markup` still holds the parent `offers` block — the
			// `unset()` below drops it. (#variant-completeness)
			$this->add_inherited_variant_fields( $entry, $variation, $markup );
			$has_variant[] = $entry;
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
	 * specific attribute selections), its own resolved `audience`
	 * (gender/age group, when this variation carries one of its own —
	 * {@see add_variant_audience()}; a variant without its own value
	 * inherits per-field from the parent later, in
	 * {@see add_inherited_variant_fields()}), an `offers[0]` Offer block
	 * with price/availability/currency/inventory/shipping/return-policy,
	 * the variation's `BuyAction`, and `Offer.checkoutPageURLTemplate`.
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
		$this->add_variant_audience( $entry, $variation );

		$entry['offers'] = array( $this->build_variant_offer_skeleton( $variation ) );

		// All of these operate on `$entry['offers'][0]`; pass the variation
		// as `$product` so per-variant data (stock, price) flows through.
		// Return policy uses the parent — variants inherit, no per-variant
		// final-sale override (deferred — Pattern B in the meta-box design).
		$this->add_inventory_level( $entry, $variation );
		$this->add_currency( $entry );
		$this->add_subscription_signals( $entry, $variation );
		$this->add_shipping_details( $entry, $country, $variation );
		$this->add_handling_time( $entry, $settings );
		$this->add_return_policy( $entry, $parent_product, $settings, $country );

		// BuyAction + checkoutPageURLTemplate both use the VARIATION ID
		// (not the parent product ID) so the URL drops the buyer on
		// checkout with the specific SKU.
		//
		// Skip BOTH when the variation is not purchasable (e.g. no
		// price set, draft, catalog-hidden). Emitting a Shareable
		// Checkout URL for an unpurchasable SKU hands SEO crawlers and
		// non-UCP agents a URL that WC will refuse at checkout. The
		// descriptive variant entry (@id, name, sku, image) still
		// emits — agents see the variant exists, just without a
		// monetary action attached. (#373)
		if (
			( ! method_exists( $variation, 'is_purchasable' ) || $variation->is_purchasable() )
			&& self::is_orderable( $variation )
		) {
			$this->add_buy_action( $entry, $variation );
			$this->add_checkout_page_url_template( $entry, $variation );
		}

		return $entry;
	}

	/**
	 * Inherit the parent ProductGroup's WC-core base fields onto a
	 * from-scratch variant `Product` node.
	 *
	 * WHY this exists: {@see build_variant_entry()} rebuilds each variant
	 * node from scratch (`@id`, `url`, `name`, `sku`, `image`, typed
	 * properties, a fresh `offers[0]`, `potentialAction`). That rebuild
	 * deliberately ignores the parent markup — but the parent markup is
	 * the full WooCommerce-core Product shape, carrying `description`,
	 * `brand`, `category`, and an `offers[0]` with `seller` and
	 * `priceValidUntil`. A SIMPLE product keeps all of those (it never
	 * goes through the rebuild). Variants, going through the rebuild,
	 * silently DROP them. Symptom observed live: Google Search Console
	 * reports every variant as having "no description" and missing
	 * `priceValidUntil`, and the variant nodes lack `brand`/`category`
	 * and the offer's `seller`. This method is the variant counterpart
	 * to the simple-product path: it copies those WC-core base fields
	 * from the parent onto the variant so variants reach feed parity
	 * with simple products.
	 *
	 * Must be called from {@see maybe_convert_to_product_group()} while
	 * the parent `$markup` still holds its `offers` block — that method
	 * later `unset()`s `$markup['offers']`, so reading the parent offer
	 * here is order-dependent.
	 *
	 * Robustness: every read is guarded with `isset()`/`empty()`. A
	 * parent markup that lacks any of these fields (minimal input,
	 * unconfigured store) produces no PHP warnings and simply leaves the
	 * variant without that field — never an empty or synthesized value.
	 * A value the variant already set is never overwritten.
	 *
	 * Field-by-field:
	 *
	 *   - `description`: prefer the variation's OWN description, formatted
	 *     exactly like WC core (`wp_strip_all_tags( do_shortcode( ... ) )`)
	 *     so rich-text-editor markup and shortcodes resolve identically
	 *     to a simple product's. If the variation has none (the common
	 *     case — most merchants leave per-variation descriptions blank),
	 *     fall back to the parent's already-WC-core-formatted
	 *     `description`. Only emitted when non-empty.
	 *   - `brand` / `category`: copied verbatim from the parent when set
	 *     and not already present on the variant.
	 *   - `audience`: merged per Schema.org sub-property (`suggestedGender`,
	 *     `suggestedAge`) rather than copied wholesale, so a variant whose
	 *     own axis is (say) age group alone still inherits the parent's
	 *     constant gender instead of losing it. See
	 *     {@see add_variant_audience()} for where the variant's own value
	 *     is set first.
	 *   - offer `seller`: copied from the parent `offers[0]` when present
	 *     and not already on the variant offer (the seller IS store-level).
	 *   - offer `priceValidUntil`: NOT store-level. WC core derives the
	 *     parent's from the parent product's sale-end date (or a store
	 *     default end-of-next-year when there's no sale window), and each
	 *     variation can carry its OWN sale window. So prefer the
	 *     variation's own sale-end date; fall back to the parent's value
	 *     (the store default, in the common no-per-variation-sale case)
	 *     only when the variation has no sale window of its own.
	 *   - offer `url`: set to the variant entry's own `url` when the
	 *     offer has none — so an agent reading `offers[0].url` lands on
	 *     the specific variant rather than nowhere.
	 *
	 * @param array      $entry         Variant markup, modified by reference.
	 * @param WC_Product $variation     The variation (for its own description).
	 * @param array      $parent_markup The parent ProductGroup markup (source of inherited fields; still carrying `offers`).
	 */
	private function add_inherited_variant_fields( array &$entry, $variation, array $parent_markup ): void {
		// description: variation's own first, parent's as fallback.
		if ( ! isset( $entry['description'] ) || '' === $entry['description'] ) {
			$own_description = '';
			if ( method_exists( $variation, 'get_description' ) ) {
				// Format identically to WC core's simple-product path so
				// shortcodes resolve and markup is stripped the same way.
				$own_description = wp_strip_all_tags( do_shortcode( (string) $variation->get_description() ) );
			}
			if ( '' !== $own_description ) {
				$entry['description'] = $own_description;
			} elseif ( ! empty( $parent_markup['description'] ) ) {
				// Parent's description is already WC-core-formatted.
				$entry['description'] = $parent_markup['description'];
			}
		}

		// brand / category: copy from parent when present and absent here.
		if ( ! isset( $entry['brand'] ) && ! empty( $parent_markup['brand'] ) ) {
			$entry['brand'] = $parent_markup['brand'];
		}
		if ( ! isset( $entry['category'] ) && ! empty( $parent_markup['category'] ) ) {
			$entry['category'] = $parent_markup['category'];
		}

		// audience: merged per Schema.org sub-property, not copied
		// wholesale. `add_variant_audience()` (called earlier, in
		// `build_variant_entry()`) already set whichever sub-property
		// this variation resolved on its own — e.g. `suggestedAge`, when
		// age group is the actual variation axis. This step fills in
		// only the sub-property the variant did NOT resolve, from the
		// parent's own `audience` (e.g. a `suggestedGender` that's
		// constant across every variant). Google requires both fields
		// for Apparel & Accessories, so a variant whose own axis covers
		// just one of the two still needs the other merged in, not
		// silently dropped.
		if ( ! empty( $parent_markup['audience'] ) && is_array( $parent_markup['audience'] ) ) {
			$audience = ( isset( $entry['audience'] ) && is_array( $entry['audience'] ) ) ? $entry['audience'] : array();
			foreach ( array( 'suggestedGender', 'suggestedAge' ) as $sub_property ) {
				if ( ! isset( $audience[ $sub_property ] ) && isset( $parent_markup['audience'][ $sub_property ] ) ) {
					$audience[ $sub_property ] = $parent_markup['audience'][ $sub_property ];
				}
			}
			if ( ! empty( $audience ) ) {
				// `@type` first, matching build_audience_block()'s own
				// key order — array_merge() keeps a colliding string
				// key's ORIGINAL position (here, first) while still
				// taking the later array's value, so this is a no-op
				// re-confirmation when `$audience` already carries
				// `@type` from its own resolution.
				$entry['audience'] = array_merge( array( '@type' => 'PeopleAudience' ), $audience );
			}
		}

		// Offer-level inheritance operates on the variant's own
		// `offers[0]` only. Bail when the variant has no offer (e.g. an
		// unpurchasable variation whose builder still emitted no offer
		// shape) — there's nothing to enrich.
		if ( ! isset( $entry['offers'][0] ) || ! is_array( $entry['offers'][0] ) ) {
			return;
		}
		$parent_offer = ( isset( $parent_markup['offers'][0] ) && is_array( $parent_markup['offers'][0] ) )
			? $parent_markup['offers'][0]
			: array();

		if ( ! isset( $entry['offers'][0]['seller'] ) && ! empty( $parent_offer['seller'] ) ) {
			$entry['offers'][0]['seller'] = $parent_offer['seller'];
		}
		// priceValidUntil is NOT store-level: WC core derives the PARENT's from the
		// parent product's sale-end date (or a store default end-of-next-year when
		// there's no sale window). Each variation can carry its OWN sale window, so
		// copying the parent's verbatim would emit a wrong-but-plausible date on a
		// variation whose sale differs. Prefer the variation's own sale-end; fall back
		// to the parent's value (the store default in the common no-per-variation-sale
		// case) only when the variation has no sale window of its own.
		if ( ! isset( $entry['offers'][0]['priceValidUntil'] ) ) {
			$variant_valid_until = '';
			if ( method_exists( $variation, 'get_date_on_sale_to' ) ) {
				// `get_date_on_sale_to()` returns a `WC_DateTime` (a
				// `DateTime`/`DateTimeInterface` subclass) or null. Guard
				// with `instanceof \DateTimeInterface` — the same
				// PHPStan-clean idiom used for `get_date_created()` in the
				// Store API extension — before reading the timestamp.
				$sale_to = $variation->get_date_on_sale_to();
				if ( $sale_to instanceof \DateTimeInterface ) {
					$variant_valid_until = gmdate( 'Y-m-d', $sale_to->getTimestamp() );
				}
			}
			if ( '' === $variant_valid_until && ! empty( $parent_offer['priceValidUntil'] ) ) {
				$variant_valid_until = $parent_offer['priceValidUntil'];
			}
			if ( '' !== $variant_valid_until ) {
				$entry['offers'][0]['priceValidUntil'] = $variant_valid_until;
			}
		}
		// Sale window (validFrom / validThrough): each variation carries its
		// OWN schedule. Unlike priceValidUntil (which falls back to the
		// parent's store-default end date), an absent variation window means
		// "no sale on this variant" — inheriting the parent's window would
		// emit a wrong-but-plausible sale period. So we read the variation's
		// own dates only, with no parent fallback, and only when the
		// variation is actually on sale.
		if (
			method_exists( $variation, 'is_on_sale' ) && $variation->is_on_sale()
		) {
			if (
				! isset( $entry['offers'][0]['validFrom'] ) &&
				method_exists( $variation, 'get_date_on_sale_from' )
			) {
				$variant_from = $this->iso8601_or_empty( $variation->get_date_on_sale_from() );
				if ( '' !== $variant_from ) {
					$entry['offers'][0]['validFrom'] = $variant_from;
				}
			}
			if (
				! isset( $entry['offers'][0]['validThrough'] ) &&
				method_exists( $variation, 'get_date_on_sale_to' )
			) {
				$variant_through = $this->iso8601_or_empty( $variation->get_date_on_sale_to() );
				if ( '' !== $variant_through ) {
					$entry['offers'][0]['validThrough'] = $variant_through;
				}
			}
		}
		// Point the offer at the variant's own URL when it has none.
		if ( ! isset( $entry['offers'][0]['url'] ) && ! empty( $entry['url'] ) ) {
			$entry['offers'][0]['url'] = $entry['url'];
		}
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
			$permalink = self::decode_query_url( $query_args, $parent_permalink );
		}

		if ( is_string( $permalink ) && '' !== $permalink ) {
			// WC's get_permalink() may itself contain '&amp;' when a
			// third-party filter HTML-escapes the URL before we see it.
			$permalink    = html_entity_decode( $permalink, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
	 * Emit `audience` on a variant entry from the variation's own resolved
	 * Gender / Age group attribute values.
	 *
	 * **Why not {@see emit_attributes()}**: that method assumes
	 * `$product->get_attributes()` returns `WC_Product_Attribute[]`
	 * (visible/variation-flag-bearing objects), which holds for a parent
	 * `WC_Product` but not for a `WC_Product_Variation` — the latter's
	 * `get_attributes()` is inherited, unoverridden, from the base class
	 * and returns a flat `slug => value` STRING map instead (WC core's
	 * own `WC_Product_Variation::get_variation_attributes()` iterates
	 * that same prop as plain key/value pairs, and feeds the values
	 * straight into `add_query_arg()` / `urlencode()` — never through an
	 * object). Calling `emit_attributes()` against a variation would
	 * fatal the first time it tries `$attribute->get_visible()` on a
	 * string. Reading postmeta directly via
	 * {@see read_variation_audience_attributes()} — the same approach
	 * {@see read_variation_core_attributes()} already uses for
	 * color/size/material/pattern — sidesteps the shape mismatch
	 * entirely.
	 *
	 * A variation that does not itself carry a recognised Gender / Age
	 * group value (the common case: these are usually constant across a
	 * product's variants, with size/color as the actual variation axes)
	 * emits nothing here; {@see add_inherited_variant_fields()} fills the
	 * gap from the parent's own resolved `audience` afterwards, per
	 * Schema.org sub-property.
	 *
	 * @param array      $entry     Variant markup, modified by reference.
	 * @param WC_Product $variation The variation.
	 */
	private function add_variant_audience( array &$entry, $variation ): void {
		if ( ! method_exists( $variation, 'get_id' ) ) {
			return;
		}

		// Same pa_-vs-bare precedence as emit_attributes() (see
		// AUDIENCE_ATTRIBUTE_MAP): the winning value per field is
		// whichever slug has the lowest `priority` among the slugs
		// actually present on this variation's own postmeta.
		$winners = array(
			'gender'    => array(
				'value'    => '',
				'priority' => PHP_INT_MAX,
			),
			'age_group' => array(
				'value'    => '',
				'priority' => PHP_INT_MAX,
			),
		);
		foreach ( self::read_variation_audience_attributes( (int) $variation->get_id() ) as $slug_lower => $value ) {
			$field    = self::AUDIENCE_ATTRIBUTE_MAP[ $slug_lower ]['field'];
			$priority = self::AUDIENCE_ATTRIBUTE_MAP[ $slug_lower ]['priority'];
			if ( $priority < $winners[ $field ]['priority'] ) {
				$winners[ $field ] = array(
					'value'    => $value,
					'priority' => $priority,
				);
			}
		}

		$audience = self::build_audience_block( $winners['gender']['value'], $winners['age_group']['value'] );
		if ( ! empty( $audience ) ) {
			$entry['audience'] = $audience;
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
	 * Map a product's WC stock status onto a schema.org availability term.
	 *
	 * WC tracks three stock states — `instock`, `outofstock` and
	 * `onbackorder` — but `is_in_stock()` collapses them to a bool that is
	 * TRUE for backorders: it returns `'outofstock' !== stock_status`
	 * passed through the `woocommerce_product_is_in_stock` filter.
	 * Branching on that bool alone publishes `InStock` for a backordered
	 * variant, which contradicts the `inventoryLevel` the same Offer
	 * carries: an oversold variation ships `InStock` next to a negative
	 * quantity. `BackOrder` keeps the two fields telling one story and
	 * still marks the variant as orderable.
	 *
	 * The out-of-stock branch is checked FIRST and wins outright. Because
	 * `is_in_stock()` runs through that filter, a third party (multi-
	 * warehouse inventory, role-based catalogs, availability windows) can
	 * legitimately force the bool false while `stock_status` still reads
	 * `onbackorder`. Ordering it this way stops that combination from
	 * being upgraded to a purchasable-sounding `BackOrder`.
	 *
	 * Semantically equivalent to WC core's own
	 * `WC_Structured_Data::generate_product_data()` — core nests the
	 * backorder ternary inside `if ( is_in_stock() )` where this
	 * early-returns the out-of-stock case. Core has done this since WC
	 * 7.8, so it predates this plugin's declared WC floor and applies to
	 * the parent Offer core builds; this is the equivalent for the
	 * per-variant Offers built here.
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
	 * @return string Unqualified schema.org term: InStock, OutOfStock or BackOrder.
	 */
	/**
	 * Whether WC's cart would actually accept this product right now.
	 *
	 * Gates the two buy-link fields (`potentialAction` / BuyAction and
	 * `offers[].checkoutPageURLTemplate`). `is_purchasable()` alone is
	 * NOT sufficient: core defines it as `exists && published && has a
	 * price` and never consults stock, while `WC_Cart::add_to_cart()`
	 * rejects on `! is_in_stock()`. An out-of-stock-but-priced product
	 * therefore satisfied the #373 purchasable gate and advertised a
	 * checkout URL that dumps the agent on an empty cart carrying "You
	 * cannot add … because the product is out of stock" (#606).
	 *
	 * Deliberately `is_in_stock()` and NOT a quantity test: a
	 * backordered product reports `is_in_stock() === true`, the cart
	 * accepts it, and its buy link must keep emitting — gating on stock
	 * quantity would suppress exactly the variants #601 taught to
	 * advertise themselves as `BackOrder`. This predicate is therefore
	 * equivalent to "availability is not OutOfStock" on both the parent
	 * and per-variant paths, since `stock_status_to_schema()` returns
	 * `OutOfStock` iff `! is_in_stock()` and WC core does the same when
	 * it builds the parent Offer.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return bool True when a buy link would resolve rather than error.
	 */
	private static function is_orderable( $product ): bool {
		return ! method_exists( $product, 'is_in_stock' ) || (bool) $product->is_in_stock();
	}

	private static function stock_status_to_schema( $product ): string {
		if ( ! $product->is_in_stock() ) {
			return 'OutOfStock';
		}
		return 'onbackorder' === $product->get_stock_status() ? 'BackOrder' : 'InStock';
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
		$availability = 'https://schema.org/' . self::stock_status_to_schema( $variation );

		return array(
			'@type'         => 'Offer',
			'price'         => $price,
			'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'availability'  => $availability,
		);
	}

	/**
	 * Hoists the current price and priceCurrency from priceSpecification[0]
	 * to the outer Offer level.
	 *
	 * Recent WooCommerce core builds the product Offer with the price ONLY
	 * inside `priceSpecification` (an array of `UnitPriceSpecification`) and
	 * sets no flat `offers.price`; it likewise writes `priceCurrency` under
	 * `priceSpecification[0]`. Google's merchant listing reads the flat
	 * `offers.price` / `offers.priceCurrency`, so the missing fields surface
	 * as a "Missing field price" Rich Results error (WooCommerce ref:
	 * woocommerce/woocommerce#55043). We copy both up from
	 * `priceSpecification[0]`, which WC core guarantees is the CURRENT price
	 * (on sale, the sale price is placed at index 0 — for simple/grouped
	 * products WC `array_unshift()`es it ahead of the regular
	 * `priceType: ListPrice` entry — so index 0 is never the higher list
	 * price). Neither field overwrites an existing top-level value, and
	 * `priceSpecification` is left untouched so the sale display survives.
	 *
	 * The price hoist is limited to a plain `Offer`: WC core emits an
	 * `AggregateOffer` (with `lowPrice`/`highPrice`) for a variable product
	 * spanning a price range, where a scalar `price` would be redundant and
	 * Google-invalid. The currency hoist is safe for both. Audit bug #5
	 * (currency); #502 (price).
	 *
	 * @param array $markup Markup array, modified by reference.
	 */
	private function add_currency( array &$markup ): void {
		if ( ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}
		$spec = null;
		if (
			isset( $markup['offers'][0]['priceSpecification'] ) &&
			is_array( $markup['offers'][0]['priceSpecification'] ) &&
			isset( $markup['offers'][0]['priceSpecification'][0] ) &&
			is_array( $markup['offers'][0]['priceSpecification'][0] )
		) {
			$spec = $markup['offers'][0]['priceSpecification'][0];
		}
		if ( null === $spec ) {
			return;
		}
		if ( isset( $spec['priceCurrency'] ) && ! isset( $markup['offers'][0]['priceCurrency'] ) ) {
			$markup['offers'][0]['priceCurrency'] = $spec['priceCurrency'];
		}
		// Flat price only for a plain Offer — an AggregateOffer expresses its
		// price via lowPrice/highPrice and must not also carry a scalar price.
		$offer_type = $markup['offers'][0]['@type'] ?? 'Offer';
		if ( 'Offer' === $offer_type && isset( $spec['price'] ) && ! isset( $markup['offers'][0]['price'] ) ) {
			$markup['offers'][0]['price'] = $spec['price'];
		}
	}

	/**
	 * Formats a WC sale-window date as a full ISO 8601 string, or ''.
	 *
	 * `WC_Product::get_date_on_sale_from()` / `get_date_on_sale_to()` return a
	 * `WC_DateTime` (a `DateTime` subclass) or null. We emit ISO 8601 carrying
	 * the store's UTC offset (e.g. `2026-07-31T23:59:59+01:00`), which is what
	 * Google's Merchant Listing guidance recommends for sale windows "for
	 * accuracy in Google systems".
	 *
	 * We deliberately do NOT use `$date->format( 'c' )`. `WC_DateTime` models
	 * a store's timezone in two shapes: a named zone (`timezone_string`, e.g.
	 * `Europe/Berlin`) OR a fixed manual offset (`gmt_offset`, e.g. "UTC+1")
	 * stored in a detached `utc_offset` property. `WC_DateTime` does NOT
	 * override `format()`, so in the manual-offset shape `format( 'c' )`
	 * reflects only the underlying `DateTime`'s timezone — which WC leaves at
	 * UTC — emitting BOTH a wrong `+00:00` suffix AND a wall-clock shifted off
	 * the merchant's local time. `WC_DateTime::getOffset()` returns the
	 * correct offset in both shapes (the stored `utc_offset`, or the named
	 * zone's live offset), so we build a `DateTimeZone` from it and format the
	 * instant in that zone. For a plain `DateTime`/`DateTimeImmutable` (no
	 * `getOffset()` override) this reduces to the object's own offset, which
	 * is already correct. The `instanceof \DateTimeInterface` guard mirrors
	 * the existing per-variant `priceValidUntil` idiom.
	 *
	 * @param \DateTimeInterface|null $date Sale-window boundary, or null.
	 * @return string ISO 8601 datetime, or '' when no date is set.
	 */
	private function iso8601_or_empty( $date ): string {
		if ( ! $date instanceof \DateTimeInterface ) {
			return '';
		}
		$offset = $date->getOffset();
		$sign   = $offset < 0 ? '-' : '+';
		$abs    = abs( $offset );
		$tz     = new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, intdiv( $abs, 3600 ), intdiv( $abs % 3600, 60 ) ) );
		return ( new \DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( $tz )->format( 'c' );
	}

	/**
	 * Adds the sale window (`validFrom` / `validThrough`) to the Offer.
	 *
	 * Google's Merchant Listing structured data accepts `validFrom` and
	 * `validThrough` on the Offer to define when a sale price is active,
	 * complementing the `priceValidUntil` (date-only) that WC core already
	 * emits. We source both boundaries from the product's WooCommerce sale
	 * schedule (`get_date_on_sale_from()` / `get_date_on_sale_to()`).
	 *
	 * Emission rules:
	 *   - Only when the product is actually on sale (`is_on_sale()`), so we
	 *     never advertise a window for a schedule that has expired or not yet
	 *     started.
	 *   - Each field independently — WooCommerce allows an open-ended sale
	 *     with only a start OR only an end date.
	 *   - Never overwrite a value an upstream filter already set.
	 *   - Skip an `AggregateOffer` (variable-parent price range): a single
	 *     window on a range offer is ambiguous. Per-variant windows are
	 *     handled in `add_inherited_variant_fields()`.
	 *
	 * @param array      $markup  Markup array, modified by reference.
	 * @param WC_Product $product The product object.
	 */
	private function add_sale_window( array &$markup, $product ): void {
		if ( ! isset( $markup['offers'][0] ) || ! is_array( $markup['offers'][0] ) ) {
			return;
		}
		$offer_type = $markup['offers'][0]['@type'] ?? 'Offer';
		if ( 'Offer' !== $offer_type ) {
			return;
		}
		if ( ! method_exists( $product, 'is_on_sale' ) || ! $product->is_on_sale() ) {
			return;
		}

		if (
			! isset( $markup['offers'][0]['validFrom'] ) &&
			method_exists( $product, 'get_date_on_sale_from' )
		) {
			$from = $this->iso8601_or_empty( $product->get_date_on_sale_from() );
			if ( '' !== $from ) {
				$markup['offers'][0]['validFrom'] = $from;
			}
		}

		if (
			! isset( $markup['offers'][0]['validThrough'] ) &&
			method_exists( $product, 'get_date_on_sale_to' )
		) {
			$through = $this->iso8601_or_empty( $product->get_date_on_sale_to() );
			if ( '' !== $through ) {
				$markup['offers'][0]['validThrough'] = $through;
			}
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
	/**
	 * Appends query args to a URL and decodes any HTML entities in the result.
	 *
	 * WordPress's add_query_arg() returns plain '&' separators, but a
	 * third-party filter on `the_permalink` or similar hooks may have
	 * HTML-escaped the incoming URL (e.g. via esc_url()). That would
	 * cause add_query_arg() to inherit '&amp;' separators and embed them
	 * verbatim in the JSON string — non-browser consumers (curl, LLM tool
	 * calls) would then receive broken checkout URLs. Decoding before
	 * storing is the safe default regardless of what filters have done
	 * upstream. Flags match the existing html_entity_decode() convention
	 * in this class (ENT_QUOTES | ENT_HTML5, UTF-8).
	 *
	 * @param array  $args Query arguments.
	 * @param string $url  Base URL.
	 * @return string URL with query args appended, HTML entities decoded.
	 */
	private static function decode_query_url( array $args, string $url ): string {
		return html_entity_decode( add_query_arg( $args, $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

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
	 * Adds shippingDetails to offers[0] when the product ships and a store
	 * country is known.
	 *
	 * Virtual / downloadable products have no shipping, so no block is emitted
	 * for them — a shippingDetails on a no-ship product is contradictory and
	 * mismatches the products feed, which already gates on `needs_shipping()`
	 * (`requires_shipping`). The defensive `method_exists()` mirrors the feed:
	 * when the method is unavailable we fail safe and still emit, never
	 * suppressing shipping for a real product. (#504)
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
	 * @param array      $markup  Markup array, modified by reference.
	 * @param string     $country ISO country code from the WC store base location.
	 * @param WC_Product $product The product (or variation) the offer describes.
	 */
	private function add_shipping_details( array &$markup, string $country, $product ): void {
		if ( method_exists( $product, 'needs_shipping' ) && ! $product->needs_shipping() ) {
			return;
		}
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
	 * `sameAs` (social profiles) is auto-sourced from common providers —
	 * Jetpack Publicize connections, Yoast `wpseo_social`, and RankMath
	 * social settings — by `collect_same_as()` and set before the
	 * `wc_ai_storefront_jsonld_store` filter, so a merchant's filter still
	 * overrides or augments it. Each provider is independently guarded
	 * (absent/odd-shaped provider → skipped silently). Omitted when no
	 * provider yields a usable URL. `contactPoint.telephone` is still NOT
	 * emitted (WC has no canonical phone source) — ecosystem plugins can
	 * inject it via the same filter; see the filter docblock for an
	 * example.
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
			// Always emitted — even on single-currency stores — because Schema.org
			// `currenciesAccepted` is a positive declarative claim ("this store
			// accepts X") rather than a multi-currency advertising flag. This
			// intentionally diverges from the UCP manifest and llms.txt, which
			// both omit `accepted_currencies` on single-currency stores to avoid
			// falsely advertising multi-currency support to agent consumers.
			'currenciesAccepted' => implode( ' ', WC_AI_Storefront_Multi_Currency::get_accepted_currencies() ),
			'potentialAction'    => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					// Bare target (no UTMs) — a human clicking through
					// a sitelinks search box lands on a normal search
					// results page, and if that visit later converts
					// it is attributed natively by WooCommerce, same
					// as any other organic visit. `{search_term}` is
					// the only placeholder here, and it is required:
					// a consumer MUST substitute it to run a search.
					'urlTemplate' => home_url( '/?s={search_term}&post_type=product' ),
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
		// `mode='details'` with `category='returns_accepted'` is paired
		// with an empty country (a return-window declaration without a
		// target region is useless to validators). For `mode='details'`
		// with `category='final_sale'` the builder emits a
		// `MerchantReturnNotPermitted` block regardless of country —
		// "no returns" is a globally meaningful claim. All of those
		// outcomes are funneled through the `null !== $org_policy_block`
		// check below — the gate emits when the builder produced a
		// block, suppresses when it didn't.
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

		// `sameAs` (social profile URLs) — auto-sourced from common
		// providers (Jetpack Publicize, Yoast, RankMath). Set BEFORE the
		// `wc_ai_storefront_jsonld_store` filter below so a merchant's
		// filter still has the final say: it can replace the array
		// wholesale, append to it, or clear it. Omitted entirely when no
		// provider yields a usable URL (omit-when-empty, like the
		// identity fields). See `collect_same_as()` for the per-provider
		// guards and sourcing rationale.
		$same_as = $this->collect_same_as();
		if ( ! empty( $same_as ) ) {
			$store_data['sameAs'] = $same_as;
		}

		/**
		 * Filter the store-level JSON-LD data.
		 *
		 * `sameAs` (social profile URLs) is already auto-sourced by the
		 * plugin from Jetpack Publicize, Yoast, and RankMath (see
		 * `collect_same_as()`), and merged in BEFORE this filter runs —
		 * so a merchant can override or extend it here. Replace the array
		 * wholesale, append to it, or clear it:
		 *
		 *     add_filter( 'wc_ai_storefront_jsonld_store', function( $data ) {
		 *         $existing      = $data['sameAs'] ?? array();
		 *         $existing[]    = 'https://mastodon.example/@store';
		 *         $data['sameAs'] = array_values( array_unique( $existing ) );
		 *         return $data;
		 *     } );
		 *
		 * The same hook is the injection point for `contactPoint.telephone`
		 * (which the plugin does NOT auto-source) and any other Schema.org
		 * Organization field a plugin in the ecosystem already captures.
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
	 * Phone (`contactPoint.telephone`) is intentionally NOT emitted from
	 * this method — WC has no canonical phone source, so ecosystem
	 * plugins inject it via the `wc_ai_storefront_jsonld_store` filter
	 * (see the filter docblock at the call site for an example). Social
	 * profiles (`sameAs`) are NOT built here either, but they ARE
	 * auto-sourced — by the sibling `collect_same_as()` method (Jetpack
	 * Publicize / Yoast / RankMath), merged into the store data before
	 * the same filter so a merchant override still wins.
	 *
	 * @return array Identity fields, possibly empty when nothing is
	 *               configured (no logo, no WC address, no sender
	 *               email).
	 *
	 * Visibility note (issue #398): private→public so
	 * `WC_AI_Storefront_Llms_Txt::generate()` can call it directly,
	 * keeping the homepage `OnlineBusiness` JSON-LD and the new
	 * `## Store` section in llms.txt drawing on the same single source
	 * of truth for logo / address / support-email resolution.
	 */
	public function build_identity_fields(): array {
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
	 * Collect social-profile URLs for the homepage `OnlineBusiness`
	 * `sameAs` array, auto-sourced from the social/SEO plugins a Woo
	 * merchant is most likely to already have configured.
	 *
	 * Each provider lives in its OWN defensively-guarded block: a
	 * provider that isn't installed, isn't configured, or stores its
	 * data in a different shape than expected is skipped silently — it
	 * never warns, throws, or short-circuits the other providers. The
	 * homepage JSON-LD runs on `wp_head` on every front-page/shop render,
	 * so this method must stay cheap and side-effect-free.
	 *
	 * Providers (in priority order; dedup keeps the first occurrence):
	 *
	 *   1. Jetpack Publicize. Jetpack stores the merchant's connected
	 *      social accounts (each with a public `profile_link`) via
	 *      `Automattic\Jetpack\Publicize\Connections`. We read the
	 *      ALREADY-CACHED connection list from Jetpack's own transient
	 *      (`jetpack_social_connections_list`) rather than calling
	 *      `Connections::get_all()` directly: on a self-hosted (non-WPCOM)
	 *      site `get_all()` falls through to `fetch_and_cache_connections()`,
	 *      which makes a BLOCKING WordPress.com REST API call on a cold
	 *      cache. Triggering a remote fetch inside `wp_head` would add
	 *      unbounded latency to every homepage render — unacceptable on a
	 *      page-render hook. Reading the transient gives live data
	 *      whenever Jetpack has already populated it (Jetpack refreshes it
	 *      on its own schedule and on connection changes) with zero
	 *      network cost here. The `class_exists` guard ties the behaviour
	 *      to Jetpack Publicize actually being present, and every field
	 *      read is shape-checked. If Jetpack ever ships a synchronous,
	 *      no-remote local accessor we can switch to it; until then the
	 *      transient read is the stable, render-safe seam.
	 *
	 *   2. Yoast SEO — the `wpseo_social` option (an array). Yoast keeps
	 *      `facebook_site` / `instagram_url` / `linkedin_url` /
	 *      `youtube_url` / `pinterest_url` / `wikipedia_url` /
	 *      `myspace_url` / `mastodon_url` (all full URLs), `twitter_site`
	 *      (a bare handle, expanded to `https://twitter.com/{handle}` —
	 *      note `twitter` itself is a boolean card toggle, NOT the handle),
	 *      plus an `other_social_urls` array for extras.
	 *
	 *   3. RankMath — the `rank-math-options-titles` option (an array).
	 *      Dedicated per-network URL fields (`social_url_facebook`, etc.;
	 *      there is no `social_url_twitter`), the Twitter/X handle under
	 *      `twitter_author_names`, and the merchant's explicit `sameAs`
	 *      set under `social_additional_profiles` (newline-separated URLs).
	 *
	 * After collection every candidate is run through `esc_url_raw`,
	 * filtered to `http`/`https` schemes only (a `sameAs` must be a real
	 * web URL — a stray `javascript:`/`mailto:` value from a
	 * misconfigured field is dropped), and de-duplicated. Returns `[]`
	 * when nothing usable was found, so the caller omits the `sameAs`
	 * key entirely.
	 *
	 * @return array<int, string> Unique http/https profile URLs, possibly
	 *                            empty.
	 */
	private function collect_same_as(): array {
		$candidates = array();

		// ---- Provider 1: Jetpack Publicize (render-safe transient read) ----
		// Guard on the Publicize Connections class so we only engage when
		// Jetpack's social module is actually loaded. We deliberately do
		// NOT call Connections::get_all() (it can trigger a blocking
		// WPCOM REST fetch on a cold cache — see method docblock); we
		// read Jetpack's own already-populated transient instead.
		if ( class_exists( '\Automattic\Jetpack\Publicize\Connections' ) ) {
			$connections = get_transient( 'jetpack_social_connections_list' );
			if ( is_array( $connections ) ) {
				foreach ( $connections as $connection ) {
					if ( is_array( $connection ) && ! empty( $connection['profile_link'] ) && is_string( $connection['profile_link'] ) ) {
						$candidates[] = $connection['profile_link'];
					}
				}
			}
		}

		// ---- Provider 2: Yoast SEO (`wpseo_social` option array) ----
		$wpseo_social = get_option( 'wpseo_social' );
		if ( is_array( $wpseo_social ) ) {
			// Keys that already hold a full URL. NOTE: the Twitter/X handle
			// is NOT under `twitter` — that key is a boolean toggle (Twitter
			// card on/off, default true) in Yoast's `wpseo_social` schema, so
			// reading it as a handle never works. The handle lives under
			// `twitter_site` and is expanded below. `mastodon_url` is a full
			// URL Yoast added for the fediverse.
			$url_keys = array(
				'facebook_site',
				'instagram_url',
				'linkedin_url',
				'youtube_url',
				'pinterest_url',
				'wikipedia_url',
				'myspace_url',
				'mastodon_url',
			);
			foreach ( $url_keys as $key ) {
				if ( ! empty( $wpseo_social[ $key ] ) && is_string( $wpseo_social[ $key ] ) ) {
					$candidates[] = $wpseo_social[ $key ];
				}
			}

			// `twitter_site` is a bare handle, not a URL — expand it. Strip a
			// leading `@` if the merchant typed one.
			if ( ! empty( $wpseo_social['twitter_site'] ) && is_string( $wpseo_social['twitter_site'] ) ) {
				$handle = ltrim( trim( $wpseo_social['twitter_site'] ), '@' );
				if ( '' !== $handle ) {
					$candidates[] = 'https://twitter.com/' . $handle;
				}
			}

			// `other_social_urls` is an array of additional profile URLs.
			if ( ! empty( $wpseo_social['other_social_urls'] ) && is_array( $wpseo_social['other_social_urls'] ) ) {
				foreach ( $wpseo_social['other_social_urls'] as $other_url ) {
					if ( ! empty( $other_url ) && is_string( $other_url ) ) {
						$candidates[] = $other_url;
					}
				}
			}
		}

		// ---- Provider 3: RankMath (`rank-math-options-titles` option) ----
		// RankMath stores knowledge-graph social URLs under `social_url_*`
		// keys, but ONLY for the networks it ships a dedicated field for
		// (e.g. `social_url_facebook`). There is NO `social_url_twitter` —
		// the Twitter/X handle lives under `twitter_author_names`, and the
		// merchant's canonical extra profiles (the set RankMath explicitly
		// designates for the schema `sameAs` property) live under
		// `social_additional_profiles` (newline-separated URLs). All three
		// sources are read, best-effort and fully guarded.
		$rankmath = get_option( 'rank-math-options-titles' );
		if ( is_array( $rankmath ) ) {
			// Dedicated per-network URL fields (`social_url_facebook`, etc.).
			foreach ( $rankmath as $key => $value ) {
				if ( is_string( $key ) && 0 === strpos( $key, 'social_url_' ) && ! empty( $value ) && is_string( $value ) ) {
					$candidates[] = $value;
				}
			}

			// `twitter_author_names` is a bare handle — expand like Yoast.
			if ( ! empty( $rankmath['twitter_author_names'] ) && is_string( $rankmath['twitter_author_names'] ) ) {
				$rm_handle = ltrim( trim( $rankmath['twitter_author_names'] ), '@' );
				if ( '' !== $rm_handle ) {
					$candidates[] = 'https://twitter.com/' . $rm_handle;
				}
			}

			// `social_additional_profiles` is the merchant's explicit
			// `sameAs` list. RankMath stores it as a newline-separated
			// string in the option, but tolerate an already-split array
			// too (some versions / programmatic writes). Each line/entry
			// is a profile URL.
			if ( ! empty( $rankmath['social_additional_profiles'] ) ) {
				$additional = $rankmath['social_additional_profiles'];
				if ( is_string( $additional ) ) {
					$additional = preg_split( '/[\r\n]+/', $additional );
				}
				if ( is_array( $additional ) ) {
					foreach ( $additional as $profile_url ) {
						if ( ! empty( $profile_url ) && is_string( $profile_url ) ) {
							$candidates[] = trim( $profile_url );
						}
					}
				}
			}
		}

		// Sanitize, keep only http/https, and de-duplicate (first wins).
		$clean = array();
		foreach ( $candidates as $candidate ) {
			$url = esc_url_raw( $candidate );
			if ( '' === $url ) {
				continue;
			}
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				continue;
			}
			$clean[] = $url;
		}

		return array_values( array_unique( $clean ) );
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
	 * once registered). The seam is package-internal; subclass
	 * overrides must match the parent's visibility (PHP LSP), so when
	 * this method's scope was widened to `public` for issue #398
	 * the test-subclass override in JsonLdTest had to widen too.
	 *
	 * Visibility note (issue #398): protected→public so
	 * `WC_AI_Storefront_Llms_Txt::generate()` can call it directly to
	 * build the `## Store` location line in llms.txt from the same
	 * source as the JSON-LD `address` field.
	 *
	 * @return array<string, string> The PostalAddress block, or [] when
	 *                               WC has no base country configured.
	 */
	public function build_postal_address(): array {
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
	 * page loads don't issue a `get_terms()` DB query on every request.
	 * Invalidated by `WC_AI_Storefront_Cache_Invalidator::invalidate()`.
	 *
	 * Visibility note (issue #398): private→public so
	 * `WC_AI_Storefront_Llms_Txt::generate()` can build the new
	 * `## Catalog` section from the same cached top-10-by-product-count
	 * result that drives the homepage's `hasOfferCatalog` JSON-LD. One
	 * cache miss serves both surfaces.
	 *
	 * @return array Top 10 product categories by count, formatted as
	 *               Schema.org OfferCatalog entries (each item is an
	 *               array with `@type`, `name`, `numberOfItems`, `url`
	 *               string keys). Consumers wanting only the names can
	 *               `array_column($result, 'name')`.
	 */
	public function get_catalog_summary() {
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
	 * Build the `hasMerchantReturnPolicy` structured-data block for an offer.
	 *
	 * Implements the Option A / Option B separation from Google's return-policy
	 * spec:
	 *   - mode='link'    → Option B: `merchantReturnLink` only, no category.
	 *   - mode='details' → Option A: inline `returnPolicyCategory` + country;
	 *                      sub-choice: category='returns_accepted' (+ days/fees/methods)
	 *                      or category='final_sale' (no-return claim).
	 *   - mode='unconfigured' → null (emit nothing).
	 *
	 * Per-product final-sale override runs first. If the product is flagged and
	 * the store is mode='link' with a resolved page, the link wins (the "no
	 * returns" page documents what is still covered). Otherwise the override
	 * emits MerchantReturnNotPermitted directly.
	 *
	 * @param array<string, mixed> $policy     Sanitized return-policy settings.
	 * @param string               $country    Store base country (ISO 3166-1 alpha-2).
	 * @param int|null             $product_id Product ID for per-product override lookup,
	 *                                         or null to skip override (store-wide only).
	 * @return array<string, mixed>|null
	 */
	protected function build_return_policy_block( array $policy, string $country, ?int $product_id = null ): ?array {
		$mode = $policy['mode'] ?? 'unconfigured';

		// Resolve the link-mode URL now so the per-product override can reuse
		// it. page_id is only present in the persisted shape when mode='link';
		// for every other mode there is no page to link, so $link stays empty.
		$link = '';
		if ( 'link' === $mode ) {
			$page_id = isset( $policy['page_id'] ) ? (int) $policy['page_id'] : 0;
			$link    = self::resolve_merchant_return_link( $page_id );
		}

		// Per-product final-sale override. Runs before store-wide mode logic
		// (including the `unconfigured` short-circuit) so a flagged product
		// emits a structured "no returns" claim even when the merchant left
		// the store-wide policy unconfigured — the override is the merchant's
		// most-specific intent.
		if ( null !== $product_id && WC_AI_Storefront_Product_Meta_Box::is_final_sale( $product_id ) ) {
			if ( '' !== $link ) {
				// Store is mode='link' and page resolves: the link describes
				// what is still covered (defective goods, statutory rights).
				return array(
					'@type'              => 'MerchantReturnPolicy',
					'merchantReturnLink' => $link,
				);
			}
			// No link available: emit bare NotPermitted.
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			);
			if ( '' !== $country ) {
				$block['applicableCountry'] = $country;
			}
			return $block;
		}

		if ( 'unconfigured' === $mode ) {
			return null;
		}

		// mode='link': Option B — link only, no category, no country.
		if ( 'link' === $mode ) {
			if ( '' === $link ) {
				// page_id=0 or page not published: emit nothing.
				return null;
			}
			return array(
				'@type'              => 'MerchantReturnPolicy',
				'merchantReturnLink' => $link,
			);
		}

		// mode='details': Option A — inline detail.
		if ( 'details' !== $mode ) {
			// Fail closed for any unrecognised mode.
			return null;
		}

		$category = $policy['category'] ?? '';

		if ( 'final_sale' === $category ) {
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			);
			if ( '' !== $country ) {
				$block['applicableCountry'] = $country;
			}
			return $block;
		}

		if ( 'returns_accepted' !== $category ) {
			// Unknown category: fail closed.
			return null;
		}

		// details + returns_accepted requires a country.
		if ( '' === $country ) {
			return null;
		}

		$days = isset( $policy['days'] ) ? (int) $policy['days'] : 0;
		if ( $days > 0 ) {
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
				'merchantReturnDays'   => $days,
			);
		} else {
			$block = array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnUnspecified',
			);
		}

		$allowed_fees        = array( 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'OriginalShippingFees', 'RestockingFees' );
		$fees                = isset( $policy['fees'] ) && is_string( $policy['fees'] ) && in_array( $policy['fees'], $allowed_fees, true )
			? $policy['fees']
			: 'FreeReturn';
		$block['returnFees'] = 'https://schema.org/' . $fees;

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
	 * Emit a WebSite + SearchAction JSON-LD block on every page.
	 *
	 * Hooked to `wp_head` at priority 4 (before output_store_jsonld at 5).
	 * Fires on every page when the plugin is enabled — agents that scan
	 * <head> for structured data find a machine-readable search endpoint
	 * without having to follow any discovery links.
	 *
	 * Two potentialAction entries:
	 *   1. Native WP search (HTML result page).
	 *   2. UCP GET catalog/search (REST JSON — uses the public GET route).
	 *
	 * The `wc_ai_storefront_jsonld_website` filter allows Yoast/RankMath to
	 * suppress the block (return false/null) if they are already emitting
	 * their own WebSite block, preventing duplication.
	 *
	 * Results are cached in a site-wide transient (1-hour TTL) because the
	 * block content depends only on store URL and settings, not on the
	 * current page or user.
	 */
	public function output_website_jsonld(): void {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}

		$cached = get_transient( self::WEBSITE_JSONLD_CACHE_KEY );
		if ( false !== $cached ) {
			$encoded = wp_json_encode( $cached, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false !== $encoded ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output
				echo '<script type="application/ld+json">' . $encoded . '</script>' . "\n";
			}
			return;
		}

		$home_url  = trailingslashit( home_url() );
		$rest_base = rest_url( WC_AI_Storefront_UCP_REST_Controller::NAMESPACE . '/catalog/search' );

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'url'             => $home_url,
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => $home_url . '?s={search_term}&post_type=product',
					),
					'query-input' => 'required name=search_term',
				),
				array(
					'@type'       => 'SearchAction',
					'name'        => 'Product catalog API',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => $rest_base . '?q={search_term}',
					),
					'query-input' => 'required name=search_term',
				),
			),
		);

		/**
		 * Filter the WebSite JSON-LD data before caching and output.
		 *
		 * Return false or null to suppress the block entirely (e.g. when
		 * another SEO plugin already emits a WebSite block).
		 *
		 * @param array $data The structured data array.
		 */
		$data = apply_filters( 'wc_ai_storefront_jsonld_website', $data );
		if ( empty( $data ) ) {
			return;
		}

		set_transient( self::WEBSITE_JSONLD_CACHE_KEY, $data, HOUR_IN_SECONDS );
		$encoded = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false !== $encoded ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output
			echo '<script type="application/ld+json">' . $encoded . '</script>' . "\n";
		}
	}

	/**
	 * Emit an ItemList JSON-LD block on shop/archive/search pages.
	 *
	 * Hooked to `wp_head` at priority 6 (after `output_store_jsonld` at 5).
	 * Fires on:
	 *   - Shop front          is_shop() (incl. when the shop is the front page)
	 *   - Category archives   is_product_category()
	 *   - Tag archives        is_product_tag()
	 *   - Search results      is_search() && 'product' === get_query_var( 'post_type' )
	 *
	 * Each itemListElement is a summary-page ListItem: `position`, `name`, and
	 * `url` only. The linked product page carries the full Product node (price,
	 * offers, size/color, BuyAction, and every other enrichment), so Google
	 * validates completeness on the product pages — not archive pages — and the
	 * archive list is never flagged for missing product fields.
	 *
	 * Results are cached per [page_type]_[term_id|search_query]_[page_num]
	 * (1-hour TTL). Cache is purged by WC_AI_Storefront_Cache_Invalidator on
	 * any product or category change.
	 */
	public function output_archive_itemlist_jsonld(): void {
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}

		// Shop archive (incl. when the shop IS the front page): emit the product ItemList alongside the front page's OnlineBusiness block, so agents fetching the root get product links, not just navigational data.
		$on_shop     = function_exists( 'is_shop' ) && is_shop();
		$on_category = function_exists( 'is_product_category' ) && is_product_category();
		$on_tag      = function_exists( 'is_product_tag' ) && is_product_tag();
		// A product search is `/?s=foo&post_type=product`. is_woocommerce() is
		// is_shop() || is_product_taxonomy() || is_product() — all false on a
		// search results page — so gating on it would make this branch dead.
		// Key on the searched post type instead, which is what actually
		// distinguishes a product search from a blog/post search.
		$on_search = is_search() && 'product' === get_query_var( 'post_type' );

		if ( ! $on_shop && ! $on_category && ! $on_tag && ! $on_search ) {
			return;
		}

		// Build a stable cache key scoped to this exact page view.
		//
		// Search pages are deliberately NOT cached. A search transient key is
		// keyed on md5(get_search_query()), whose cardinality is bounded only by
		// the distinct ?s= values an unauthenticated visitor (or scraper) sends
		// — caching each would flood wp_options with one row-pair per unique
		// query for the full TTL. The page itself is cheap to recompute (one
		// wc_get_products page query). Shop/category/tag keys ARE cached because
		// their cardinality is bounded by catalog size.
		$cache_enabled = ! $on_search;
		$paged_raw     = get_query_var( 'paged' );
		$paged         = $paged_raw ? (int) $paged_raw : 1;
		$term          = null;
		$cache_key     = '';
		if ( $on_category || $on_tag ) {
			$term      = get_queried_object();
			$term_id   = ( $term && isset( $term->term_id ) ) ? (int) $term->term_id : 0;
			$cache_key = self::ITEMLIST_JSONLD_CACHE_PREFIX . ( $on_category ? 'cat' : 'tag' ) . '_' . $term_id . '_' . $paged;
		} elseif ( ! $on_search ) {
			$cache_key = self::ITEMLIST_JSONLD_CACHE_PREFIX . 'shop_' . $paged;
		}

		if ( $cache_enabled ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				$encoded = wp_json_encode( $cached, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
				// Guard against wp_json_encode() returning false (e.g. malformed
				// UTF-8): emit nothing rather than an empty, invalid ld+json
				// island. Mirrors output_website_jsonld(). On the cache-hit path
				// a false encode means the cached payload is corrupt — return
				// without falling through to a redundant fresh build.
				if ( false !== $encoded ) {
					echo '<script type="application/ld+json">' . $encoded . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode applied.
				}
				return;
			}
		}

		// Prepare the fallback product-query args (used only when the main query yields no products).
		$per_page   = (int) get_option( 'posts_per_page', 12 );
		$query_args = array(
			'status'  => 'publish',
			'limit'   => min( $per_page, 100 ),
			'page'    => $paged,
			'orderby' => 'menu_order',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		// Total-count args: same filters, no pagination, IDs only.
		$count_args = array(
			'status' => 'publish',
			'limit'  => -1,
			'return' => 'ids',
		);

		if ( $on_category && $term ) {
			$query_args['category'] = array( $term->slug );
			$count_args['category'] = array( $term->slug );
			// term->count is the fastest total for categories/tags:
			// it's stored in the term row and updated by WP on every
			// save, so no extra DB query is needed.
			$total_products = isset( $term->count ) ? (int) $term->count : null;
			$list_name      = $term->name ?? '';
			$list_url       = get_term_link( $term );
			$list_url       = is_wp_error( $list_url ) ? '' : $list_url;
		} elseif ( $on_tag && $term ) {
			$query_args['tag'] = array( $term->slug );
			$count_args['tag'] = array( $term->slug );
			$total_products    = isset( $term->count ) ? (int) $term->count : null;
			$list_name         = $term->name ?? '';
			$list_url          = get_term_link( $term );
			$list_url          = is_wp_error( $list_url ) ? '' : $list_url;
		} elseif ( $on_search ) {
			$search_query    = get_search_query();
			$query_args['s'] = $search_query;
			$count_args['s'] = $search_query;
			$total_products  = null; // resolved below after count query.
			$list_name       = $search_query;
			$list_url        = home_url( '/?s=' . rawurlencode( $search_query ) . '&post_type=product' );
		} else {
			$total_products = null; // resolved below after count query.
			$list_name      = (string) get_bloginfo( 'name' );
			$shop_page_id   = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
			$list_url       = $shop_page_id > 0 ? get_permalink( $shop_page_id ) : home_url( '/' );
			$list_url       = $list_url ? $list_url : '';
		}

		// Fall back to a count query when term->count isn't available
		// (search + shop pages) or when $term was not resolved.
		// Prefer wp_query->found_posts to avoid loading all IDs into memory.
		$main_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
		if ( null === $total_products ) {
			if ( $main_query && isset( $main_query->found_posts ) && $main_query->found_posts > 0 ) {
				$total_products = (int) $main_query->found_posts;
			} else {
				$total_products = count( wc_get_products( $count_args ) );
			}
		}

		// Prefer the products the page's main query actually rendered, so the
		// list matches the visible page on ANY theme — a classic loop, a
		// `loop_shop_per_page` filter, or a block theme's Product Collection
		// block, whose per-page can differ from the site-wide `posts_per_page`
		// option a standalone query would use (#559). Fall back to a direct
		// query only when the main query yields no WC_Product objects (no
		// posts, or all posts are non-products).
		//
		// Note: this approach assumes the main query reflects the rendered
		// products — true for the default Product Collection block (inherit-
		// query ON, verified). A block with a custom non-inherited query is an
		// edge case where the list will follow the archive query instead.
		$products       = array();
		$had_main_posts = $main_query && ! empty( $main_query->posts ) && is_array( $main_query->posts );
		if ( $had_main_posts && function_exists( 'wc_get_product' ) ) {
			foreach ( $main_query->posts as $main_post ) {
				$main_product = wc_get_product( $main_post );
				if ( $main_product instanceof WC_Product ) {
					$products[] = $main_product;
				}
			}
			if ( is_callable( array( $main_query, 'get' ) ) ) {
				$query_pp = (int) $main_query->get( 'posts_per_page' );
				// Exclude -1 (show-all): with -1, `paged` is always 1, so
				// the position offset is unaffected — leave $per_page as-is.
				if ( $query_pp > 0 ) {
					$per_page = $query_pp;
				}
			}
		}
		if ( empty( $products ) ) {
			if ( $had_main_posts ) {
				// The main query had posts but none resolved to a WC_Product.
				// The fallback re-queries — log so this silent mismatch is visible.
				WC_AI_Storefront_Logger::debug(
					'ItemList JSON-LD: main query had posts but none resolved to WC_Product; falling back to direct product query.'
				);
			}
			$products = wc_get_products( $query_args );
		}
		if ( empty( $products ) ) {
			return;
		}

		$items          = array();
		$effective_page = min( $per_page, 100 );
		$position       = ( ( $paged - 1 ) * $effective_page ) + 1;

		foreach ( $products as $product ) {
			if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
				continue;
			}

			$name = (string) $product->get_name();
			$url  = (string) get_permalink( $product->get_id() );

			// Summary-page carousel entry: position + name + url only. The
			// product page this links to carries the full Product node (price,
			// size/color, offers), so Google validates the product pages — not
			// these list entries — and the archive list is never flagged for a
			// missing product field. A ListItem with no resolvable name/url is a
			// Google "Unnamed item" critical error, so skip rather than emit one.
			if ( '' === $name || '' === $url ) {
				WC_AI_Storefront_Logger::debug(
					sprintf(
						'ItemList JSON-LD: skipping product #%d — %s is empty.',
						(int) $product->get_id(),
						'' === $name ? 'name' : 'url'
					)
				);
				continue;
			}

			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $name,
				'url'      => $url,
			);
		}

		if ( empty( $items ) ) {
			return;
		}

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $list_name,
			'itemListElement' => $items,
		);
		// numberOfItems is the count of items in THIS list. It is only honest
		// when every queried product is syndicated — i.e. selection mode 'all'.
		// In 'selected'/'by_taxonomy' mode the itemListElement above is a
		// filtered subset, but $total_products counts all published products
		// (no cheap syndication-aware count exists). Emitting that inflated
		// figure would mislead agents paginating on numberOfItems and disclose
		// the non-syndicated count, so omit the optional field instead.
		$selection_mode = $settings['product_selection_mode'] ?? 'all';
		if ( 'all' === $selection_mode ) {
			$data['numberOfItems'] = $total_products;
		}
		if ( '' !== $list_url ) {
			$data['url'] = $list_url;
		}

		$encoded = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		// Guard against a false return (malformed UTF-8 in a product field):
		// emit nothing, and don't cache a payload we couldn't even encode.
		if ( false === $encoded ) {
			WC_AI_Storefront_Logger::debug( 'ItemList JSON-LD: wp_json_encode failed for cache key ' . $cache_key . '; block suppressed.' );
			return;
		}

		// Search pages skip the write (see $cache_enabled rationale above).
		if ( $cache_enabled ) {
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		}

		echo '<script type="application/ld+json">' . $encoded . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode applied.
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
