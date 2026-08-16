<?php
/**
 * Builds Google's Organization-level shipping policy from WooCommerce zones.
 *
 * WooCommerce prices shipping by zone AND order value. Google's
 * `ShippingConditions` encodes exactly that pair, which product-level
 * `OfferShippingDetails.shippingRate` — a single `MonetaryAmount` — cannot.
 * On a store offering "free over $20, otherwise $20" there is no honest
 * product-level number: 0 lies to a $10 basket and 20 lies to a $30 one.
 * That is why this surface exists alongside the per-product one rather than
 * replacing it.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zones in, `hasShippingService` out.
 */
class WC_AI_Storefront_Shipping_Policy {

	/**
	 * First WooCommerce release with `WC_Shipping_Zones::get_shipping_zones()`
	 * — the plural form returning objects. Commit 205db58026, September 2025.
	 */
	const ZONES_MIN_WC = '10.3';


	/**
	 * Parse a WooCommerce shipping cost string into something publishable.
	 *
	 * `WC_Shipping_Flat_Rate::$cost` is an expression, not a number. It
	 * supports `[qty]`, `[cost]` and `[fee percent="10" min_fee="4"]`, all
	 * evaluated against a real cart at checkout time. Only two forms can be
	 * stated publicly without knowing the basket:
	 *
	 *   - a literal number, and
	 *   - a bare percentage of order value, which Google models as
	 *     `orderPercentage` (a fraction: 0.10 means 10%).
	 *
	 * Everything else returns null and the caller drops the condition.
	 * Publishing a guess would put a price in front of shoppers that
	 * checkout then contradicts, which costs more than saying nothing.
	 *
	 * @param string $cost Raw cost expression.
	 * @return array{type: string, value: float}|null
	 */
	public static function parse_cost( string $cost ): ?array {
		$cost = trim( $cost );
		if ( '' === $cost ) {
			return null;
		}

		if ( is_numeric( $cost ) ) {
			// Negatives are not a shipping price, and `is_numeric()` also
			// accepts exponential notation ('1e3'), so parse to float and
			// reject anything below zero rather than publishing it.
			$value = (float) $cost;
			if ( $value < 0 ) {
				return null;
			}
			return array(
				'type'  => 'literal',
				'value' => $value,
			);
		}

		// A bare fee shortcode and nothing else. A percentage COMBINED with
		// other terms (e.g. `5 + [fee percent="10"]`) falls through to null:
		// Google expresses a flat rate or a percentage, never their sum, so
		// publishing either half alone would misstate the cost.
		//
		// Quotes are optional because WordPress's shortcode parser accepts
		// `[fee percent=10]`, and that is a cost a merchant can genuinely
		// have stored.
		if ( ! preg_match( '/^\[fee\s+([^\]]*)\]$/', $cost, $matches ) ) {
			return null;
		}

		$attributes = $matches[1];

		// `min_fee` and `max_fee` clamp the computed amount, and
		// `orderPercentage` has no way to express a floor or a ceiling.
		// A floor in particular dominates rather than trimming an edge:
		// `[fee percent="10" min_fee="20"]` — WooCommerce's own example in
		// the cost-field help text — charges a flat 20 on every order below
		// 200, so publishing 10% would understate most real baskets.
		if ( preg_match_all( '/(?:min|max)_fee=(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]*))/', $attributes, $clamps, PREG_SET_ORDER ) ) {
			foreach ( $clamps as $clamp ) {
				// WooCommerce's own default is `max_fee=""`, which imposes
				// nothing. Only a clamp with a value changes the price.
				// PREG_SET_ORDER omits trailing unmatched groups entirely, so
				// every alternative needs a default rather than just the last.
				$value = ( $clamp[1] ?? '' ) . ( $clamp[2] ?? '' ) . ( $clamp[3] ?? '' );
				if ( '' !== trim( $value ) ) {
					return null;
				}
			}
		}

		if ( ! preg_match( '/(?:^|\s)percent=(["\']?)([0-9.]+)\1(?:\s|$)/', $attributes, $percent ) ) {
			return null;
		}

		return array(
			'type'  => 'percent',
			'value' => (float) $percent[2] / 100,
		);
	}

	/**
	 * The whole `ShippingService` block, or null when there is nothing
	 * honest to say.
	 *
	 * Returns null rather than an empty `shippingConditions` array: an empty
	 * array is a positive claim that the store ships nowhere, which is the
	 * opposite of "we could not derive the rates".
	 *
	 * Note `handlingTime` is a `ServicePeriod` here, while the product-level
	 * block emits a bare `QuantitativeValue`. That is not an inconsistency to
	 * tidy up — Google's own examples differ per surface, and each side
	 * matches the one it appears on.
	 *
	 * @param array|null $settings Plugin settings; read when omitted.
	 * @return array|null
	 */
	public function build( ?array $settings = null ): ?array {
		// A merchant who switches to digital-only keeps their zones — the
		// onboarding wizard created them and nothing removes them. Reading
		// zones directly would publish delivery this store no longer offers.
		if ( function_exists( 'wc_shipping_enabled' ) && ! wc_shipping_enabled() ) {
			return null;
		}

		$conditions = $this->build_conditions();
		if ( empty( $conditions ) ) {
			// Silence is correct — no rate could be stated honestly — but a
			// merchant with configured zones and no markup needs to know why.
			// Matches the debug-and-continue pattern used across this plugin's
			// other emitters rather than failing quietly with no trace.
			if ( class_exists( 'WC_AI_Storefront_Logger' ) ) {
				WC_AI_Storefront_Logger::debug(
					'Shipping policy: no publishable conditions. Every zone had only cart-dependent costs (e.g. [qty]), per-shipping-class costs, or live-carrier methods, whose price cannot be stated without a real address and basket.'
				);
			}
			return null;
		}

		$block = array( '@type' => 'ShippingService' );

		if ( null === $settings && class_exists( 'WC_AI_Storefront' ) ) {
			$settings = WC_AI_Storefront::get_settings();
		}

		$handling = $this->handling_time_block( is_array( $settings ) ? $settings : array() );
		if ( null !== $handling ) {
			$block['handlingTime'] = $handling;
		}

		$block['shippingConditions'] = $conditions;

		return $block;
	}

	/**
	 * Handling time as a `ServicePeriod`, or null when unconfigured.
	 *
	 * Applies the same `min > 0 && max > 0 && min <= max` guard the product
	 * block uses, so the two surfaces can never disagree about how long the
	 * store takes to dispatch.
	 *
	 * `businessDays` and `cutoffTime` are the other two ServicePeriod
	 * properties. Both are Recommended and WooCommerce stores neither, so
	 * they stay absent until there is a setting behind them.
	 *
	 * @param array $settings Plugin settings.
	 * @return array|null
	 */
	private function handling_time_block( array $settings ): ?array {
		$handling = $settings['handling_time'] ?? array();
		$min      = isset( $handling['min'] ) ? (int) $handling['min'] : 0;
		$max      = isset( $handling['max'] ) ? (int) $handling['max'] : 0;

		if ( $min <= 0 || $max <= 0 || $min > $max ) {
			return null;
		}

		return array(
			'@type'    => 'ServicePeriod',
			'duration' => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => $min,
				'maxValue' => $max,
				'unitCode' => 'DAY',
			),
		);
	}

	/**
	 * Every zone's shipping rules as Google `ShippingConditions`.
	 *
	 * A zone yields either one condition (a single price for that
	 * destination) or two, when free shipping is gated on an order minimum.
	 * The pair is the whole point of this class:
	 *
	 *     orderValue { minValue: 20 }    -> shippingRate { value: 0 }
	 *     orderValue { maxValue: 19.99 } -> shippingRate { value: 20 }
	 *
	 * Google applies the lowest matching rate, so only the cheapest paid
	 * method in a zone is published — a dearer one for the same destination
	 * and band adds noise and never information.
	 *
	 * @return array
	 */
	public function build_conditions(): array {
		if ( ! $this->zones_readable() ) {
			if ( class_exists( 'WC_AI_Storefront_Logger' ) ) {
				WC_AI_Storefront_Logger::debug(
					'Shipping zones unavailable: WC_Shipping_Zones::get_shipping_zones() requires WooCommerce ' . self::ZONES_MIN_WC . '+.'
				);
			}
			return array();
		}

		$conditions = array();

		foreach ( $this->get_shipping_zones() as $zone ) {
			if ( ! $zone instanceof WC_Shipping_Zone ) {
				continue;
			}
			foreach ( $this->zone_conditions( $zone ) as $condition ) {
				$conditions[] = $condition;
			}
		}

		return $conditions;
	}

	/**
	 * One zone's conditions.
	 *
	 * @param WC_Shipping_Zone $zone The zone.
	 * @return array
	 */
	private function zone_conditions( WC_Shipping_Zone $zone ): array {
		$destinations = $this->zone_destinations( $zone );
		if ( null === $destinations ) {
			// Locations we cannot express — a continent, or postcodes with no
			// country. Emitting these with no shippingDestination would
			// publish the zone's rate as the store's worldwide rate.
			return array();
		}
		$rates       = array(); // Every publishable paid rate in this zone.
		$free_from   = null;   // Lowest order minimum that unlocks free shipping.
		$free_always = false;

		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( ! $this->method_enabled( $method ) ) {
				continue;
			}

			if ( $method instanceof WC_Shipping_Free_Shipping ) {
				if ( '' === $method->requires ) {
					$free_always = true;
					continue;
				}
				// Modes where the order amount ALONE unlocks free shipping.
				//
				// 'both' is deliberately excluded. WooCommerce evaluates it as
				// `$has_met_min_amount && $has_coupon` (see
				// WC_Shipping_Free_Shipping::is_available()), so a qualifying
				// order still pays full shipping without a free-shipping
				// coupon. Publishing a free band for it would be a plain false
				// claim — and worse, the paid band would be capped just below
				// the threshold, leaving every larger order matching only the
				// fabricated free condition.
				//
				// 'either' is included because the amount by itself suffices
				// there. The coupon half is not a property of the store's
				// standing shipping policy.
				if ( in_array( $method->requires, array( 'min_amount', 'either' ), true ) ) {
					$amount = is_numeric( $method->min_amount ) ? (float) $method->min_amount : null;
					if ( null === $amount ) {
						continue;
					}
					if ( $amount <= 0 ) {
						// WooCommerce defaults min_amount to '0' and the admin
						// UI lets it save that way. `is_available()` then tests
						// `$total >= 0`, which is always true — the store ships
						// free on every order. Discarding this would publish
						// the zone's flat rate instead, inverting the policy.
						$free_always = true;
						continue;
					}
					if ( null === $free_from || $amount < $free_from ) {
						// WooCommerce permits several min_amount methods in
						// one zone. The lowest threshold is the one a shopper
						// actually hits first, and overlapping bands would be
						// worse than collapsing them.
						$free_from = $amount;
					}
				}
				continue;
			}

			$parsed = $this->method_cost( $method );
			if ( null === $parsed ) {
				continue;
			}
			$rates[] = $parsed;
		}

		if ( $free_always ) {
			return $this->fan_out(
				$destinations,
				$this->rate_block(
					array(
						'type'  => 'literal',
						'value' => 0.0,
					)
				)
			);
		}

		$cheapest = $this->cheapest_rate( $rates );

		if ( null !== $free_from && null !== $cheapest ) {
			return array_merge(
				$this->fan_out(
					$destinations,
					$this->rate_block(
						array(
							'type'  => 'literal',
							'value' => 0.0,
						)
					),
					array( 'minValue' => $free_from )
				),
				$this->fan_out(
					$destinations,
					$this->rate_block( $cheapest ),
					array( 'maxValue' => $this->band_ceiling( $free_from ) )
				)
			);
		}

		if ( null !== $free_from ) {
			// Free above the threshold, and nothing publishable below it.
			// Stating only the free half would imply shipping is always free.
			return array();
		}

		if ( null === $cheapest ) {
			return array();
		}

		return $this->fan_out( $destinations, $this->rate_block( $cheapest ) );
	}

	/**
	 * One condition per destination, all carrying the same rate.
	 *
	 * An empty destination list means the catch-all zone, which yields a
	 * single condition with no `shippingDestination`.
	 *
	 * @param array $destinations DefinedRegion blocks; empty for catch-all.
	 * @param array $rate         shippingRate block.
	 * @param array $order_value  minValue/maxValue pair, or empty.
	 * @return array
	 */
	private function fan_out( array $destinations, array $rate, array $order_value = array() ): array {
		if ( empty( $destinations ) ) {
			return array( $this->condition( null, $rate, $order_value ) );
		}

		$conditions = array();
		foreach ( $destinations as $destination ) {
			$conditions[] = $this->condition( $destination, $rate, $order_value );
		}
		return $conditions;
	}

	/**
	 * Assemble one condition, omitting keys that carry no meaning.
	 *
	 * @param array|null $destination DefinedRegion, or null for the catch-all zone.
	 * @param array      $rate        shippingRate block.
	 * @param array      $order_value minValue/maxValue pair, or empty.
	 * @return array
	 */
	private function condition( ?array $destination, array $rate, array $order_value = array() ): array {
		$condition = array( '@type' => 'ShippingConditions' );

		if ( null !== $destination ) {
			$condition['shippingDestination'] = $destination;
		}
		if ( ! empty( $order_value ) ) {
			$condition['orderValue'] = array_merge(
				array(
					'@type'    => 'MonetaryAmount',
					'currency' => get_woocommerce_currency(),
				),
				$order_value
			);
		}
		$condition['shippingRate'] = $rate;

		return $condition;
	}

	/**
	 * A parsed cost as a `shippingRate` block.
	 *
	 * A percentage becomes `orderPercentage` on a MonetaryAmount rather than
	 * a `value`, which is how Google models "shipping costs 10% of the order".
	 *
	 * @param array $parsed Output of parse_cost().
	 * @return array
	 */
	private function rate_block( array $parsed ): array {
		if ( 'percent' === $parsed['type'] ) {
			return array(
				'@type'           => 'MonetaryAmount',
				'currency'        => get_woocommerce_currency(),
				'orderPercentage' => $parsed['value'],
			);
		}

		return array(
			'@type'    => 'MonetaryAmount',
			'value'    => $parsed['value'],
			'currency' => get_woocommerce_currency(),
		);
	}

	/**
	 * The cheapest publishable rate in a zone, or null.
	 *
	 * Google applies the lowest matching rate, so a dearer method serving the
	 * same destination and band is noise.
	 *
	 * A zone mixing a literal and a percentage returns null. The two cannot
	 * be ranked without an order total — a flat 100 alongside
	 * `[fee percent="1"]` is dearer on every basket under 10,000 — so picking
	 * either would risk publishing a price the checkout undercuts. Saying
	 * nothing for that zone is the consistent choice.
	 *
	 * @param array $rates Parsed costs.
	 * @return array|null
	 */
	private function cheapest_rate( array $rates ): ?array {
		if ( empty( $rates ) ) {
			return null;
		}

		$types = array_unique( array_column( $rates, 'type' ) );
		if ( count( $types ) > 1 ) {
			return null;
		}

		$cheapest = $rates[0];
		foreach ( $rates as $rate ) {
			if ( $rate['value'] < $cheapest['value'] ) {
				$cheapest = $rate;
			}
		}

		return $cheapest;
	}

	/**
	 * The top of the paid band, one smallest currency unit below the
	 * free-shipping threshold.
	 *
	 * Google's ranges are inclusive, so the paid band must stop short of the
	 * threshold rather than at it. The step follows the store's configured
	 * decimals: 0.01 hardcoded would leave 19.995 matching neither band on a
	 * three-decimal currency, and would name an unrepresentable amount on a
	 * zero-decimal one.
	 *
	 * @param float $threshold Free-shipping minimum.
	 * @return float
	 */
	private function band_ceiling( float $threshold ): float {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$decimals = max( 0, min( 6, $decimals ) );
		$step     = pow( 10, -$decimals );

		return round( $threshold - $step, $decimals );
	}

	/**
	 * A zone's locations as Google `DefinedRegion` blocks.
	 *
	 * Returns a LIST, because Google's `addressCountry` is singular while a
	 * WooCommerce zone may name several countries. A multi-country zone
	 * becomes one condition per country carrying the same rate, rather than
	 * silently collapsing to the first — which on a `{US, CA, MX}` zone would
	 * hand Canada whatever the catch-all zone charges.
	 *
	 * Three distinct outcomes, and conflating any two of them publishes a
	 * false claim:
	 *
	 *   - `array()`     — zone 0, the catch-all. Emit a condition with NO
	 *                     `shippingDestination`, which Google reads as
	 *                     "anywhere else".
	 *   - a non-empty list — ordinary zones.
	 *   - `null`        — locations we cannot express. Skip the zone entirely.
	 *
	 * That last case is why this cannot simply return null for "no
	 * destination". `continent` is a first-class WooCommerce location type
	 * with no Google equivalent, and postcode-only zones give no country to
	 * scope against. Treating either as the catch-all would publish a Europe
	 * zone's rate as the store's worldwide rate.
	 *
	 * @param WC_Shipping_Zone $zone The zone.
	 * @return array|null
	 */
	public function zone_destinations( WC_Shipping_Zone $zone ): ?array {
		// Identify the catch-all positively by id. An empty location list is
		// also how an unsaved or misconfigured zone looks, but zone 0 is the
		// only one WooCommerce genuinely means as "everywhere else".
		if ( 0 === $zone->get_id() ) {
			return array();
		}

		$locations = $zone->get_zone_locations();
		if ( empty( $locations ) ) {
			return null;
		}

		// Regions and postcodes are kept per country so a zone holding
		// `US:NY` and `CA:ON` does not emit Ontario as a US region.
		$by_country = array();
		$postcodes  = array();

		foreach ( $locations as $location ) {
			switch ( $location->type ) {
				case 'country':
					$by_country[ $location->code ] = $by_country[ $location->code ] ?? array();
					break;
				case 'state':
					// WooCommerce stores states as `US:NY`.
					$parts = explode( ':', $location->code );
					if ( 2 === count( $parts ) ) {
						$by_country[ $parts[0] ][] = $parts[1];
					}
					break;
				case 'postcode':
					$postcodes[] = $location->code;
					break;
				// 'continent' has no Google equivalent and is handled by the
				// emptiness check below rather than guessed at.
			}
		}

		if ( empty( $by_country ) ) {
			return null;
		}

		$destinations = array();
		foreach ( $by_country as $country => $regions ) {
			$region  = array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => $country,
			);
			$regions = array_values( array_unique( $regions ) );
			if ( ! empty( $regions ) ) {
				$region['addressRegion'] = $regions;
			}
			// Postcodes are not country-tagged in WooCommerce, so they only
			// attach unambiguously when the zone names a single country.
			if ( ! empty( $postcodes ) && 1 === count( $by_country ) ) {
				$region['postalCode'] = array_values( array_unique( $postcodes ) );
			}
			$destinations[] = $region;
		}

		return $destinations;
	}

	/**
	 * A method's parsed cost, or null when it has none we can publish.
	 *
	 * Only flat rate carries a static cost. Local pickup is not shipping, and
	 * third-party table-rate and live-carrier methods compute against a real
	 * address at request time.
	 *
	 * Shipping-class costs disqualify the method. `calculate_shipping()` adds
	 * them ON TOP of the base cost — `$rate['cost'] += $class_cost` for type
	 * 'class', or the highest class cost for type 'order' — and which class
	 * applies depends on what is in the cart. A store with `cost = 5` and
	 * `class_cost_12 = 15` charges 20, so publishing 5 would understate it by
	 * more than the base rate itself.
	 *
	 * @param object $method Shipping method instance.
	 * @return array|null
	 */
	private function method_cost( $method ): ?array {
		if ( ! $method instanceof WC_Shipping_Flat_Rate ) {
			return null;
		}

		if ( $this->has_shipping_class_costs( $method ) ) {
			return null;
		}

		return self::parse_cost( (string) $method->cost );
	}

	/**
	 * Whether any per-shipping-class cost is configured on a flat rate.
	 *
	 * Settings are keyed `class_cost_<term_id>`, with `no_class_cost` for
	 * products in no class. An empty string means "not set" and adds nothing.
	 *
	 * @param object $method Flat-rate method instance.
	 * @return bool
	 */
	private function has_shipping_class_costs( $method ): bool {
		$settings = array();
		if ( property_exists( $method, 'instance_settings' ) && is_array( $method->instance_settings ) ) {
			$settings = $method->instance_settings;
		}

		foreach ( $settings as $key => $value ) {
			if ( 'no_class_cost' !== $key && 0 !== strpos( (string) $key, 'class_cost_' ) ) {
				continue;
			}
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a method is enabled.
	 *
	 * `WC_Shipping_Method::$enabled` is the STRING 'yes' or 'no', not a
	 * boolean — WC_Shipping_Zone assigns it as `$raw->is_enabled ? 'yes' :
	 * 'no'`. A truthiness check would therefore pass 'no' straight through,
	 * since every non-empty string is truthy.
	 *
	 * `get_shipping_methods( true )` already filters at the SQL level, so
	 * this guard should never fire in production. It exists for callers
	 * passing an unfiltered list, and it needs to be right for that case.
	 *
	 * @param object $method Shipping method instance.
	 * @return bool
	 */
	private function method_enabled( $method ): bool {
		if ( ! property_exists( $method, 'enabled' ) ) {
			return true;
		}
		return 'no' !== $method->enabled && false !== $method->enabled;
	}

	/**
	 * Every shipping zone, including the catch-all.
	 *
	 * `get_shipping_zones()` returns WC_Shipping_Zone objects; `get_zones()`
	 * returns data arrays for the admin UI and must NOT be used — those fail
	 * `instanceof`. Zone 0 is appended because WooCommerce excludes it.
	 *
	 * Protected so tests can subclass and inject zones without static state,
	 * matching the pattern in WC_AI_Storefront_JsonLd.
	 *
	 * @return array
	 */
	/**
	 * Whether this WooCommerce can be asked for zone objects.
	 *
	 * `get_shipping_zones()` only exists in WooCommerce 10.3+, while the
	 * plugin's floor is 9.9 — and `WC requires at least` raises an admin
	 * notice rather than blocking activation. Calling it below that fatals
	 * inside wp_head, white-screening the homepage and every product page.
	 *
	 * The VERSION is checked, not the method: `tests/php/stubs.php` declares
	 * `get_shipping_zones()` unconditionally, so `method_exists()` is always
	 * true under test and PHPStan reports the guard as redundant.
	 *
	 * Overridable so a test can exercise both sides without redefining a
	 * constant, which PHP cannot do per-case.
	 *
	 * @return bool
	 */
	protected function zones_readable(): bool {
		return defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, self::ZONES_MIN_WC, '>=' );
	}

	protected function get_shipping_zones(): array {
		$zones   = array_values( WC_Shipping_Zones::get_shipping_zones() );
		$zones[] = new WC_Shipping_Zone( 0 );
		return $zones;
	}
}
