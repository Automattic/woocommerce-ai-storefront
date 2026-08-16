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
			return array(
				'type'  => 'literal',
				'value' => (float) $cost,
			);
		}

		// A bare fee shortcode and nothing else. `min_fee`/`max_fee` are
		// deliberately ignored: they clamp the computed amount, which
		// `orderPercentage` cannot express, but their presence does not make
		// the percentage itself wrong — only less precise at the extremes.
		//
		// A percentage COMBINED with other terms (e.g. `5 + [fee percent="10"]`)
		// falls through to null on purpose. Google can express a flat rate or
		// a percentage, not their sum, so publishing either half alone would
		// misstate the real cost.
		if ( preg_match( '/^\[fee\s+[^\]]*percent=(["\'])([0-9.]+)\1[^\]]*\]$/', $cost, $matches ) ) {
			return array(
				'type'  => 'percent',
				'value' => (float) $matches[2] / 100,
			);
		}

		return null;
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
		$destination = $this->zone_destination( $zone );
		$cheapest    = null;   // Cheapest publishable paid rate.
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
				// Modes naming a minimum: 'min_amount', and the coupon
				// variants 'either'/'both'. Only the amount is publishable —
				// a coupon is not a property of the store's shipping policy,
				// so 'either' is treated as its amount half.
				if ( in_array( $method->requires, array( 'min_amount', 'either', 'both' ), true ) ) {
					$amount = is_numeric( $method->min_amount ) ? (float) $method->min_amount : null;
					if ( null !== $amount && $amount > 0 && ( null === $free_from || $amount < $free_from ) ) {
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
			if ( null === $cheapest || $this->cheaper( $parsed, $cheapest ) ) {
				$cheapest = $parsed;
			}
		}

		if ( $free_always ) {
			return array(
				$this->condition(
					$destination,
					$this->rate_block(
						array(
							'type'  => 'literal',
							'value' => 0.0,
						)
					)
				),
			);
		}

		if ( null !== $free_from && null !== $cheapest ) {
			return array(
				$this->condition(
					$destination,
					$this->rate_block(
						array(
							'type'  => 'literal',
							'value' => 0.0,
						)
					),
					array( 'minValue' => $free_from )
				),
				$this->condition(
					$destination,
					$this->rate_block( $cheapest ),
					// Google's ranges are inclusive, so the paid band stops a
					// cent below the threshold rather than at it.
					array( 'maxValue' => round( $free_from - 0.01, 2 ) )
				),
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

		return array( $this->condition( $destination, $this->rate_block( $cheapest ) ) );
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
	 * Compare two parsed costs.
	 *
	 * A percentage and a literal are not comparable without an order total,
	 * so a literal always wins — it is the one that can be stated plainly.
	 *
	 * @param array $candidate Parsed cost under consideration.
	 * @param array $current   Parsed cost currently held as cheapest.
	 * @return bool
	 */
	private function cheaper( array $candidate, array $current ): bool {
		if ( $candidate['type'] !== $current['type'] ) {
			return 'literal' === $candidate['type'];
		}
		return $candidate['value'] < $current['value'];
	}

	/**
	 * A zone's locations as a Google `DefinedRegion`, or null for the
	 * catch-all zone.
	 *
	 * Zone 0 covers everywhere not matched by another zone. Naming a country
	 * there would be false, and Google reads a condition with no
	 * `shippingDestination` as "anywhere else", which is exactly right.
	 *
	 * @param WC_Shipping_Zone $zone The zone.
	 * @return array|null
	 */
	public function zone_destination( WC_Shipping_Zone $zone ): ?array {
		$locations = $zone->get_zone_locations();
		if ( empty( $locations ) ) {
			return null;
		}

		$countries = array();
		$regions   = array();
		$postcodes = array();

		foreach ( $locations as $location ) {
			switch ( $location->type ) {
				case 'country':
					$countries[] = $location->code;
					break;
				case 'state':
					// WooCommerce stores states as `US:NY`; Google wants the
					// region alone, with the country carried separately.
					$parts = explode( ':', $location->code );
					if ( 2 === count( $parts ) ) {
						$countries[] = $parts[0];
						$regions[]   = $parts[1];
					}
					break;
				case 'postcode':
					$postcodes[] = $location->code;
					break;
			}
		}

		$countries = array_values( array_unique( $countries ) );
		if ( empty( $countries ) ) {
			// Postcode-only zones exist but are meaningless to Google without
			// a country to scope them.
			return null;
		}

		$region = array(
			'@type'          => 'DefinedRegion',
			// Google's addressCountry is singular. A zone listing several
			// countries collapses to the first; splitting into one condition
			// per country is a possible refinement, not a correctness issue.
			'addressCountry' => $countries[0],
		);
		if ( ! empty( $regions ) ) {
			$region['addressRegion'] = array_values( array_unique( $regions ) );
		}
		if ( ! empty( $postcodes ) ) {
			$region['postalCode'] = array_values( array_unique( $postcodes ) );
		}

		return $region;
	}

	/**
	 * A method's parsed cost, or null when it has none we can publish.
	 *
	 * Only flat rate carries a static cost. Local pickup is not shipping,
	 * and third-party table-rate and live-carrier methods compute against a
	 * real address at request time, so neither can be stated here.
	 *
	 * @param object $method Shipping method instance.
	 * @return array|null
	 */
	private function method_cost( $method ): ?array {
		if ( ! $method instanceof WC_Shipping_Flat_Rate ) {
			return null;
		}
		return self::parse_cost( (string) $method->cost );
	}

	/**
	 * Whether a method is enabled.
	 *
	 * `get_shipping_methods( true )` already filters to enabled methods, so
	 * this is a belt-and-braces read for method objects that expose the flag
	 * without honouring the filter.
	 *
	 * @param object $method Shipping method instance.
	 * @return bool
	 */
	private function method_enabled( $method ): bool {
		return ! property_exists( $method, 'enabled' ) || (bool) $method->enabled;
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
	protected function get_shipping_zones(): array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		$zones   = array_values( WC_Shipping_Zones::get_shipping_zones() );
		$zones[] = new WC_Shipping_Zone( 0 );
		return $zones;
	}
}
