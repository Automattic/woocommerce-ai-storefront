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

		// Add purchase action pointing to store checkout with attribution placeholders.
		// Canonical UTM shape (0.5.0+): `utm_medium=referral` is
		// Google-canonical, `utm_id=woo_ucp` flags "we routed this"
		// without overloading the medium field. See
		// `WC_AI_Storefront_UCP_REST_Controller::build_continue_url()`
		// for the full rationale; the JSON-LD potentialAction emits
		// the same shape so an agent that constructs the URL from
		// the schema (rather than calling /checkout-sessions) lands
		// on STRICT attribution via the same `utm_id` path.
		$markup['potentialAction'] = [
			'@type'  => 'BuyAction',
			'target' => [
				'@type'          => 'EntryPoint',
				'urlTemplate'    => add_query_arg(
					[
						'add-to-cart'   => $product->get_id(),
						'utm_source'    => '{agent_id}',
						'utm_medium'    => 'referral',
						// Reference the constant rather than the literal
						// `'woo_ucp'` so a future rename can't silently
						// drift between this emit site and the matcher
						// in `WC_AI_Storefront_Attribution::capture_ai_attribution()`.
						'utm_id'        => WC_AI_Storefront_Attribution::WOO_UCP_ID,
						'ai_session_id' => '{session_id}',
					],
					$product->get_permalink()
				),
				'actionPlatform' => [
					'https://schema.org/DesktopWebPlatform',
					'https://schema.org/MobileWebPlatform',
				],
			],
		];

		// Inventory detail at Offer level. In the markup filtered by
		// `woocommerce_structured_data_product`, `offers` is emitted
		// as a list, so the assignment targets `offers[0]`, not
		// `offers` directly. Mirrors the `isset() && is_array()`
		// guard the priceCurrency + hasMerchantReturnPolicy +
		// shippingDetails emissions later in this method use;
		// consider consolidating into one Offer-level block in a
		// future cleanup. Regression locked by JsonLdTest.
		if ( $product->managing_stock() ) {
			$stock_qty = $product->get_stock_quantity();
			if (
				null !== $stock_qty
				&& isset( $markup['offers'][0] )
				&& is_array( $markup['offers'][0] )
			) {
				$markup['offers'][0]['inventoryLevel'] = [
					'@type' => 'QuantitativeValue',
					'value' => $stock_qty,
				];
			}
		}

		// Add category breadcrumb path.
		$categories = wc_get_product_cat_ids( $product->get_id() );
		if ( ! empty( $categories ) ) {
			// Prime the term object cache for all category IDs and their ancestors
			// so the get_ancestors() + get_term() calls inside the loop hit the
			// cache instead of issuing one DB query per term.
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

		// Weight and dimensions using store-configured units.
		if ( $product->has_weight() ) {
			// Normalize the WC-stored weight to a numeric form. WC
			// persists weight as a free-form string (often a leading-
			// dot value like `.5` saved by the product editor without
			// the leading zero). Casting through `(float)` produces a
			// canonical `0.5` numeric so consumers parsing JSON-LD with
			// strict number deserializers (Google's Rich Results test,
			// AI agents JSON-parsing the markup) see a well-formed
			// number instead of a string that round-trips to a quoted
			// `".5"` literal. Audit bug #4.
			$markup['weight'] = [
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $product->get_weight(),
				'unitCode' => $this->get_weight_unit_code(),
			];
		}

		if ( $product->has_dimensions() ) {
			$dimensions       = $product->get_dimensions( false );
			$dimension_unit   = $this->get_dimension_unit_code();
			$markup['depth']  = [
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['length'],
				'unitCode' => $dimension_unit,
			];
			$markup['width']  = [
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['width'],
				'unitCode' => $dimension_unit,
			];
			$markup['height'] = [
				'@type'    => 'QuantitativeValue',
				'value'    => $dimensions['height'],
				'unitCode' => $dimension_unit,
			];
		}

		// Product attributes as additionalProperty for semantic matching.
		$attributes = $product->get_attributes();
		if ( ! empty( $attributes ) ) {
			$additional_properties = [];
			foreach ( $attributes as $attribute ) {
				if ( ! $attribute->get_visible() ) {
					continue;
				}

				$name  = wc_attribute_label( $attribute->get_name(), $product );
				$value = $product->get_attribute( $attribute->get_name() );

				if ( $value ) {
					$additional_properties[] = [
						'@type' => 'PropertyValue',
						'name'  => $name,
						'value' => $value,
					];
				}
			}
			if ( ! empty( $additional_properties ) ) {
				$markup['additionalProperty'] = $additional_properties;
			}
		}

		// Shipping + return policy live at the Offer level (Schema.org/
		// Google preferred placement). Before the policies-tab refactor
		// these blocks were written at the Product level; that placement
		// was historically tolerated but is no longer the documented
		// best location. Moved here as part of the same refactor that
		// made the return-policy emission settings-driven and
		// structurally valid.
		$base_location = wc_get_base_location();
		$country       = $base_location['country'] ?? '';

		// `priceCurrency` at Offer level — Google's preferred top-level
		// placement. WC core writes the currency under the nested
		// `priceSpecification[0].priceCurrency`; copy it up to the
		// outer Offer dict so consumers reading from either location
		// resolve a value. We never overwrite an existing top-level
		// `priceCurrency` (defensive against a future WC core change
		// or a third-party filter that already populated it). Audit
		// bug #5.
		if ( isset( $markup['offers'][0] ) && is_array( $markup['offers'][0] ) ) {
			// Drill into priceSpecification with explicit is_array
			// guards at every level. PHP 8's null-coalescing on a
			// chained subscript would short-circuit safely on missing
			// keys, but a third-party filter or future WC core change
			// could plausibly produce a non-list scalar / object at
			// any level — `is_array` narrows that down to "list of
			// arrays, with index 0" before we read the leaf.
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

			// `seller.name` double-encoding fix. WC core writes the
			// store name through esc_html() into the structured-data
			// markup, but the call site sometimes feeds an already-
			// encoded value (e.g. `Piero&amp;#039;s` for a name
			// containing an apostrophe), producing visible literal
			// `&amp;` and `&#039;` in JSON-LD that AI agents parse
			// verbatim. We decode twice to handle this double-encoded
			// case in one pass: first decode peels the outer `&amp;`,
			// second decode resolves the now-visible `&#039;` (or
			// other inner entities). Idempotent for already-clean
			// input — `html_entity_decode` of a string with no
			// entities is the identity function. Audit bug #3.
			if ( isset( $markup['offers'][0]['seller']['name'] ) && is_string( $markup['offers'][0]['seller']['name'] ) ) {
				$decoded                               = html_entity_decode(
					$markup['offers'][0]['seller']['name'],
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				);
				$markup['offers'][0]['seller']['name'] = html_entity_decode(
					$decoded,
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				);
			}
		}

		// shippingDetails requires a known store country — a
		// DefinedRegion without addressCountry is meaningless.
		if ( $country && isset( $markup['offers'][0] ) && is_array( $markup['offers'][0] ) ) {
			$markup['offers'][0]['shippingDetails'] = [
				'@type'               => 'OfferShippingDetails',
				'shippingDestination' => [
					'@type'          => 'DefinedRegion',
					'addressCountry' => $country,
				],
			];
		}

		// Return policy runs independently of shippingDetails.
		// MerchantReturnNotPermitted (per-product final-sale override
		// and store-wide final_sale mode) is valid without
		// applicableCountry — Schema.org marks the field as
		// recommended, not required, for no-return policies.
		// build_return_policy_block omits applicableCountry when
		// $country is empty for those paths. Returns-accepted mode
		// still returns null when country is absent (a return window
		// is meaningless without a target region).
		if ( isset( $markup['offers'][0] ) && is_array( $markup['offers'][0] ) ) {
			$policy = isset( $settings['return_policy'] ) && is_array( $settings['return_policy'] )
				? $settings['return_policy']
				: [ 'mode' => 'unconfigured' ];
			// Resolve the per-product override-flag scope. Variations
			// inherit from their parent — a merchant flagging a parent
			// "Final sale" expects every color/size variant to inherit
			// that posture without re-flagging each one. WC stores
			// variations as posts whose `post_parent` is the parent
			// product's ID; `wp_get_post_parent_id()` returns 0 for
			// non-variation products (simple, grouped, external), so
			// the same call works uniformly. Use the parent ID when
			// present so the flag is read off the parent's meta;
			// fall back to the product's own ID otherwise.
			//
			// `wp_get_post_parent_id` (rather than
			// `WC_Product::get_parent_id`) so PHPStan's WC stubs don't
			// flag the call — `get_parent_id` exists on WC_Product but
			// isn't in `php-stubs/woocommerce-stubs` at the version we
			// pin. Same wire-level result either way.
			$policy_product_id = null;
			if ( $product instanceof WC_Product ) {
				$parent_id         = wp_get_post_parent_id( $product->get_id() );
				$policy_product_id = $parent_id > 0 ? $parent_id : $product->get_id();
			}
			$policy_block = $this->build_return_policy_block(
				$policy,
				$country,
				$policy_product_id
			);
			if ( null !== $policy_block ) {
				$markup['offers'][0]['hasMerchantReturnPolicy'] = $policy_block;
			}
		}

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
		$settings_subset = [
			'enabled'                => $settings['enabled'] ?? 'no',
			'product_selection_mode' => $settings['product_selection_mode'] ?? 'all',
			'return_policy'          => $settings['return_policy'] ?? [],
		];
		return apply_filters( 'wc_ai_storefront_jsonld_product', $markup, $product, $settings_subset );
	}

	/**
	 * Output store-level JSON-LD on the homepage/shop page.
	 */
	public function output_store_jsonld() {
		if ( ! is_front_page() && ! is_shop() ) {
			return;
		}

		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return;
		}

		$store_data = [
			'@context'           => 'https://schema.org',
			'@type'              => 'Store',
			'name'               => get_bloginfo( 'name' ),
			'description'        => get_bloginfo( 'description' ),
			'url'                => home_url( '/' ),
			'currenciesAccepted' => get_woocommerce_currency(),
			'potentialAction'    => [
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					// Canonical UTM shape (0.5.0+) — see BuyAction
					// urlTemplate above for rationale. The `utm_id`
					// value comes from the constant rather than the
					// literal string for the same drift-prevention
					// reason documented at the BuyAction emit site.
					'urlTemplate' => home_url(
						'/?s={search_term}&post_type=product&utm_source={agent_id}&utm_medium=referral&utm_id=' . WC_AI_Storefront_Attribution::WOO_UCP_ID
					),
				],
				'query-input' => 'required name=search_term',
			],
			'hasOfferCatalog'    => [
				'@type'           => 'OfferCatalog',
				'name'            => __( 'Products', 'woocommerce-ai-storefront' ),
				'itemListElement' => $this->get_catalog_summary(),
			],
		];

		/**
		 * Filter the store-level JSON-LD data.
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
		$settings_subset = [
			'enabled'                => $settings['enabled'] ?? 'no',
			'product_selection_mode' => $settings['product_selection_mode'] ?? 'all',
			'return_policy'          => $settings['return_policy'] ?? [],
		];
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
			$block = [
				'@type'                => 'MerchantReturnPolicy',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			];
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
			$block = [
				'@type'                => 'MerchantReturnPolicy',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			];
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
			$block = [
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
				'merchantReturnDays'   => $days,
			];
		} else {
			// Smart-degrade: no days configured → declare Unspecified
			// rather than emit a FiniteReturnWindow without days.
			$block = [
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $country,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnUnspecified',
			];
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
		$allowed_fees        = [ 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'OriginalShippingFees', 'RestockingFees' ];
		$fees                = isset( $policy['fees'] ) && is_string( $policy['fees'] ) && in_array( $policy['fees'], $allowed_fees, true )
			? $policy['fees']
			: 'FreeReturn';
		$block['returnFees'] = 'https://schema.org/' . $fees;

		// returnMethod: scalar string when 1 method selected, array
		// when 2+, omitted when none. Methods are also allow-list
		// validated at emission time for the same reason as fees above.
		$allowed_methods = [ 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' ];
		$methods         = isset( $policy['methods'] ) && is_array( $policy['methods'] )
			? array_values(
				array_unique(
					array_filter( $policy['methods'], static fn( $m ) => in_array( $m, $allowed_methods, true ) )
				)
			)
			: [];
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
