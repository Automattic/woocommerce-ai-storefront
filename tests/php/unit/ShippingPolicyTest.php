<?php
/**
 * Unit tests for WC_AI_Storefront_Shipping_Policy.
 *
 * Covers the Organization-level `hasShippingService` block: parsing
 * WooCommerce's cost expressions, and mapping zones and methods onto
 * Google's `ShippingConditions`.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;

class ShippingPolicyTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// parse_cost() — what WooCommerce lets a merchant type, and what of
	// that can honestly be published
	// ------------------------------------------------------------------

	public function test_literal_costs_parse(): void {
		$this->assertSame(
			array(
				'type'  => 'literal',
				'value' => 20.0,
			),
			WC_AI_Storefront_Shipping_Policy::parse_cost( '20' )
		);
		$this->assertSame(
			array(
				'type'  => 'literal',
				'value' => 50.0,
			),
			WC_AI_Storefront_Shipping_Policy::parse_cost( '50.00' )
		);
		$this->assertSame(
			array(
				'type'  => 'literal',
				'value' => 0.0,
			),
			WC_AI_Storefront_Shipping_Policy::parse_cost( '0' )
		);
		// Merchants type spaces.
		$this->assertSame(
			array(
				'type'  => 'literal',
				'value' => 7.5,
			),
			WC_AI_Storefront_Shipping_Policy::parse_cost( ' 7.50 ' )
		);
	}

	public function test_percentage_fee_parses_to_a_fraction(): void {
		// Google's orderPercentage is a FRACTION: 0.10 means 10%.
		$this->assertSame(
			array(
				'type'  => 'percent',
				'value' => 0.10,
			),
			WC_AI_Storefront_Shipping_Policy::parse_cost( '[fee percent="10"]' )
		);
	}

	public function test_percentage_accepts_single_and_absent_quotes(): void {
		// WordPress's shortcode parser accepts unquoted attribute values, so
		// `[fee percent=10]` is a cost a merchant can genuinely have stored.
		$this->assertSame( 0.25, WC_AI_Storefront_Shipping_Policy::parse_cost( "[fee percent='25']" )['value'] );
		$this->assertSame( 0.15, WC_AI_Storefront_Shipping_Policy::parse_cost( '[fee percent=15]' )['value'] );
	}

	public function test_percentage_with_a_fee_floor_or_ceiling_is_unusable(): void {
		// `min_fee` is a floor applied AFTER the percentage, so
		// `[fee percent="10" min_fee="20"]` — WooCommerce's own example in the
		// cost-field help text — charges a flat 20 on every order below 200.
		// Publishing 10% would understate the majority of real baskets, not
		// merely blur an edge case. orderPercentage cannot express a clamp.
		$this->assertNull( WC_AI_Storefront_Shipping_Policy::parse_cost( '[fee percent="10" min_fee="20"]' ) );
		$this->assertNull( WC_AI_Storefront_Shipping_Policy::parse_cost( '[fee percent="10" max_fee="50"]' ) );
		// An empty clamp is WooCommerce's own default and imposes nothing.
		$this->assertSame( 0.10, WC_AI_Storefront_Shipping_Policy::parse_cost( '[fee percent="10" max_fee=""]' )['value'] );
	}

	public function test_cart_dependent_costs_are_unusable(): void {
		// These depend on what else is in the basket, so there is no honest
		// store-wide number and the condition must be skipped entirely.
		foreach ( array( '10 * [qty]', '[qty]', '5 + [cost]', '20 * [qty] + 3' ) as $cost ) {
			$this->assertNull( WC_AI_Storefront_Shipping_Policy::parse_cost( $cost ), $cost );
		}
	}

	public function test_empty_and_nonsense_costs_are_unusable(): void {
		foreach ( array( '', '   ', 'gibberish', '[fee]' ) as $cost ) {
			$this->assertNull(
				WC_AI_Storefront_Shipping_Policy::parse_cost( $cost ),
				var_export( $cost, true )
			);
		}
	}

	public function test_percentage_combined_with_other_terms_is_unusable(): void {
		// '5 + [fee percent="10"]' is a base PLUS a percentage. Google's
		// ShippingConditions can express one or the other, never their sum,
		// so neither half may be published alone — 5 understates the cost
		// and 0.10 misstates it.
		$this->assertNull( WC_AI_Storefront_Shipping_Policy::parse_cost( '5 + [fee percent="10"]' ) );
		$this->assertNull( WC_AI_Storefront_Shipping_Policy::parse_cost( '[fee percent="10"] + 5' ) );
	}

	// ------------------------------------------------------------------
	// build_conditions() — zones and methods to Google's condition list
	// ------------------------------------------------------------------

	public function test_threshold_free_shipping_emits_two_conditions(): void {
		// The reason #635 exists. One zone, two price bands — which a single
		// product-level MonetaryAmount cannot express.
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $this->free_method( 'min_amount', '20' ), $this->flat_method( '20' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertCount( 2, $out );
		$this->assertSame( 20.0, $out[0]['orderValue']['minValue'] );
		$this->assertSame( 0.0, $out[0]['shippingRate']['value'] );
		$this->assertSame( 19.99, $out[1]['orderValue']['maxValue'] );
		$this->assertSame( 20.0, $out[1]['shippingRate']['value'] );
	}

	public function test_unconditional_free_shipping_emits_one_free_condition(): void {
		$zone = $this->zone( 1, array( array( 'country', 'US' ) ), array( $this->free_method( '', '' ) ) );

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertCount( 1, $out );
		$this->assertSame( 0.0, $out[0]['shippingRate']['value'] );
		$this->assertArrayNotHasKey( 'orderValue', $out[0], 'Unconditional means no order-value band.' );
	}

	public function test_cheapest_rate_wins_within_a_zone(): void {
		// Google applies the lowest matching rate, so a dearer method for the
		// same destination and band is noise.
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $this->flat_method( '50.00' ), $this->flat_method( '20' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertCount( 1, $out );
		$this->assertSame( 20.0, $out[0]['shippingRate']['value'] );
	}

	public function test_catchall_zone_emits_no_destination(): void {
		// Zone 0 covers everywhere not matched by another zone. Naming a
		// country would be false; omitting the key is how Google reads
		// "anywhere else".
		$zone = $this->zone( 0, array(), array( $this->flat_method( '20' ) ) );

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertArrayNotHasKey( 'shippingDestination', $out[0] );
		$this->assertSame( 20.0, $out[0]['shippingRate']['value'] );
	}

	public function test_zone_locations_map_to_defined_region(): void {
		$zone = $this->zone(
			2,
			array( array( 'state', 'US:NY' ), array( 'postcode', '10001' ) ),
			array( $this->flat_method( '5' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertSame( 'US', $out[0]['shippingDestination']['addressCountry'] );
		$this->assertSame( array( 'NY' ), $out[0]['shippingDestination']['addressRegion'] );
		$this->assertSame( array( '10001' ), $out[0]['shippingDestination']['postalCode'] );
	}

	public function test_disabled_methods_are_ignored(): void {
		$disabled          = $this->flat_method( '5' );
		$disabled->enabled = 'no';
		$zone              = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $disabled, $this->flat_method( '20' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertSame( 20.0, $out[0]['shippingRate']['value'], 'A disabled method must not set the price.' );
	}

	public function test_unparseable_cost_skips_the_condition(): void {
		$zone = $this->zone( 1, array( array( 'country', 'US' ) ), array( $this->flat_method( '10 * [qty]' ) ) );

		$this->assertSame( array(), $this->policy( array( $zone ) )->build_conditions() );
	}

	public function test_percentage_cost_emits_order_percentage(): void {
		$zone = $this->zone( 1, array( array( 'country', 'US' ) ), array( $this->flat_method( '[fee percent="10"]' ) ) );

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertSame( 0.10, $out[0]['shippingRate']['orderPercentage'] );
		$this->assertArrayNotHasKey( 'value', $out[0]['shippingRate'] );
	}

	public function test_threshold_without_a_publishable_paid_rate_emits_nothing(): void {
		// Free over $20 and an unparseable rate below it. Publishing only the
		// free half would read as "shipping is always free".
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $this->free_method( 'min_amount', '20' ), $this->flat_method( '[qty]' ) )
		);

		$this->assertSame( array(), $this->policy( array( $zone ) )->build_conditions() );
	}

	public function test_lowest_threshold_wins_when_several_free_methods_exist(): void {
		// WooCommerce permits several min_amount methods per zone. The lowest
		// is the one a shopper hits first; overlapping bands would be worse.
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array(
				$this->free_method( 'min_amount', '75' ),
				$this->free_method( 'min_amount', '50' ),
				$this->flat_method( '9' ),
			)
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertSame( 50.0, $out[0]['orderValue']['minValue'] );
		$this->assertSame( 49.99, $out[1]['orderValue']['maxValue'] );
	}

	public function test_local_pickup_and_unknown_methods_carry_no_rate(): void {
		// Only flat rate has a static cost. Anything else is either not
		// shipping or computed against a real address at request time.
		$unknown = new \WC_Shipping_Method();
		$zone    = $this->zone( 1, array( array( 'country', 'US' ) ), array( $unknown ) );

		$this->assertSame( array(), $this->policy( array( $zone ) )->build_conditions() );
	}

	public function test_both_mode_free_shipping_is_never_published(): void {
		// WooCommerce evaluates 'both' as `$has_met_min_amount && $has_coupon`.
		// A $60 order against a $50 threshold still pays full shipping without
		// a free-shipping coupon, so a free band here would be a false claim —
		// and the paid band capped at 49.99 would leave every larger order
		// matching only the fabrication.
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $this->free_method( 'both', '50' ), $this->flat_method( '9' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertCount( 1, $out );
		$this->assertArrayNotHasKey( 'orderValue', $out[0] );
		$this->assertSame( 9.0, $out[0]['shippingRate']['value'] );
	}

	public function test_either_mode_publishes_the_amount_half(): void {
		// 'either' is `||`, so the amount alone genuinely suffices.
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $this->free_method( 'either', '50' ), $this->flat_method( '9' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertCount( 2, $out );
		$this->assertSame( 50.0, $out[0]['orderValue']['minValue'] );
	}

	public function test_zero_minimum_means_free_on_every_order(): void {
		// WooCommerce defaults min_amount to '0' and the admin UI saves it that
		// way. is_available() then tests `$total >= 0`, always true. Discarding
		// it would publish the flat rate and invert the store's actual policy.
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $this->free_method( 'min_amount', '0' ), $this->flat_method( '9' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertCount( 1, $out );
		$this->assertSame( 0.0, $out[0]['shippingRate']['value'] );
		$this->assertArrayNotHasKey( 'orderValue', $out[0] );
	}

	public function test_zone_mixing_a_literal_and_a_percentage_is_skipped(): void {
		// A flat 100 against [fee percent="1"] is dearer on every basket under
		// 10,000. Without an order total the two cannot be ranked, so picking
		// either risks publishing a price checkout undercuts.
		$zone = $this->zone(
			1,
			array( array( 'country', 'US' ) ),
			array( $this->flat_method( '100' ), $this->flat_method( '[fee percent="1"]' ) )
		);

		$this->assertSame( array(), $this->policy( array( $zone ) )->build_conditions() );
	}

	public function test_continent_zone_is_skipped_not_published_worldwide(): void {
		// `continent` is a first-class WooCommerce location type with no Google
		// equivalent. Treating it as the catch-all would publish a Europe-only
		// rate as the store's worldwide rate.
		$zone = $this->zone( 3, array( array( 'continent', 'EU' ) ), array( $this->flat_method( '5' ) ) );

		$this->assertSame( array(), $this->policy( array( $zone ) )->build_conditions() );
	}

	public function test_postcode_only_zone_is_skipped(): void {
		// No country to scope the postcodes against.
		$zone = $this->zone( 4, array( array( 'postcode', '10001' ) ), array( $this->flat_method( '5' ) ) );

		$this->assertSame( array(), $this->policy( array( $zone ) )->build_conditions() );
	}

	public function test_multi_country_zone_emits_one_condition_per_country(): void {
		// Google's addressCountry is singular. Collapsing to the first country
		// would hand the others whatever the catch-all zone charges.
		$zone = $this->zone(
			5,
			array( array( 'country', 'US' ), array( 'country', 'CA' ), array( 'country', 'MX' ) ),
			array( $this->flat_method( '10' ) )
		);

		$out = $this->policy( array( $zone ) )->build_conditions();

		$this->assertSame(
			array( 'US', 'CA', 'MX' ),
			array_column( array_column( $out, 'shippingDestination' ), 'addressCountry' )
		);
		foreach ( $out as $condition ) {
			$this->assertSame( 10.0, $condition['shippingRate']['value'] );
		}
	}

	public function test_state_codes_stay_with_their_own_country(): void {
		// A zone holding US:NY and CA:ON must not publish Ontario as a US
		// region, which pooling regions across countries would do.
		$zone = $this->zone(
			6,
			array( array( 'state', 'US:NY' ), array( 'state', 'CA:ON' ) ),
			array( $this->flat_method( '10' ) )
		);

		$out    = $this->policy( array( $zone ) )->build_conditions();
		$by_cc  = array();
		foreach ( $out as $condition ) {
			$by_cc[ $condition['shippingDestination']['addressCountry'] ] = $condition['shippingDestination']['addressRegion'];
		}

		$this->assertSame( array( 'NY' ), $by_cc['US'] );
		$this->assertSame( array( 'ON' ), $by_cc['CA'] );
	}

	public function test_band_ceiling_follows_the_stores_decimal_places(): void {
		// A hardcoded 0.01 leaves 19.995 matching neither band on a
		// three-decimal currency such as KWD.
		$zone   = $this->zone(
			1,
			array( array( 'country', 'KW' ) ),
			array( $this->free_method( 'min_amount', '20' ), $this->flat_method( '2' ) )
		);
		$policy = $this->policy( array( $zone ) );
		\Brain\Monkey\Functions\when( 'wc_get_price_decimals' )->justReturn( 3 );

		$out = $policy->build_conditions();

		$this->assertSame( 19.999, $out[1]['orderValue']['maxValue'] );
	}

	public function test_reproduces_a_real_three_zone_store(): void {
		// saltwarp.shop's live configuration, verified against its REST API:
		// a US zone and a Canada zone each with free-over-$20 plus flat rates,
		// and a catch-all. Expected output is five conditions, with the $50
		// "Expedited" method absent because $20 already beats it for the same
		// destination and band.
		$out = $this->policy(
			array(
				$this->zone(
					1,
					array( array( 'country', 'US' ) ),
					array(
						$this->free_method( 'min_amount', '20' ),
						$this->flat_method( '20' ),      // Overnight
						$this->flat_method( '50.00' ),   // Expedited
					)
				),
				$this->zone(
					6,
					array( array( 'country', 'CA' ) ),
					array( $this->free_method( 'min_amount', '20.00' ), $this->flat_method( '20' ) )
				),
				$this->zone( 0, array(), array( $this->flat_method( '20' ) ) ),
			)
		)->build_conditions();

		$this->assertCount( 5, $out );

		$shape = array_map(
			static function ( $c ) {
				return array(
					$c['shippingDestination']['addressCountry'] ?? 'ANY',
					$c['orderValue']['minValue'] ?? null,
					$c['orderValue']['maxValue'] ?? null,
					$c['shippingRate']['value'],
				);
			},
			$out
		);

		$this->assertSame(
			array(
				array( 'US', 20.0, null, 0.0 ),
				array( 'US', null, 19.99, 20.0 ),
				array( 'CA', 20.0, null, 0.0 ),
				array( 'CA', null, 19.99, 20.0 ),
				array( 'ANY', null, null, 20.0 ),
			),
			$shape
		);

		$this->assertNotContains( 50.0, array_column( $shape, 3 ), 'The dearer method must not surface.' );
	}

	// ------------------------------------------------------------------
	// build() — the ShippingService wrapper
	// ------------------------------------------------------------------

	public function test_service_block_wraps_handling_time_in_a_service_period(): void {
		// Org level uses ServicePeriod; the product block keeps its bare
		// QuantitativeValue. Google documents both, differently, per surface —
		// this divergence is deliberate, not an oversight to unify.
		$block = $this->policy( array( $this->zone( 0, array(), array( $this->flat_method( '20' ) ) ) ) )
			->build( array( 'handling_time' => array( 'min' => 1, 'max' => 2 ) ) );

		$this->assertSame( 'ShippingService', $block['@type'] );
		$this->assertSame( 'ServicePeriod', $block['handlingTime']['@type'] );
		$this->assertSame( 'QuantitativeValue', $block['handlingTime']['duration']['@type'] );
		$this->assertSame( 1, $block['handlingTime']['duration']['minValue'] );
		$this->assertSame( 2, $block['handlingTime']['duration']['maxValue'] );
		$this->assertSame( 'DAY', $block['handlingTime']['duration']['unitCode'] );
		$this->assertCount( 1, $block['shippingConditions'] );
	}

	public function test_no_zones_emits_nothing(): void {
		// An empty shippingConditions array would claim the store ships
		// nowhere, which is the opposite of "we could not derive rates".
		$this->assertNull( $this->policy( array() )->build( array() ) );
	}

	public function test_no_parseable_rate_emits_nothing(): void {
		$zone = $this->zone( 0, array(), array( $this->flat_method( '[qty]' ) ) );

		$this->assertNull( $this->policy( array( $zone ) )->build( array() ) );
	}

	public function test_handling_time_omitted_when_unconfigured(): void {
		$block = $this->policy( array( $this->zone( 0, array(), array( $this->flat_method( '20' ) ) ) ) )
			->build( array( 'handling_time' => array( 'min' => 0, 'max' => 0 ) ) );

		$this->assertArrayNotHasKey( 'handlingTime', $block );
		$this->assertArrayHasKey( 'shippingConditions', $block );
	}

	public function test_handling_time_omitted_when_range_is_inverted(): void {
		// Same guard the product block applies, so the two surfaces can never
		// disagree about dispatch time.
		$block = $this->policy( array( $this->zone( 0, array(), array( $this->flat_method( '20' ) ) ) ) )
			->build( array( 'handling_time' => array( 'min' => 5, 'max' => 2 ) ) );

		$this->assertArrayNotHasKey( 'handlingTime', $block );
	}

	public function test_zone_reader_is_gated_on_the_woocommerce_version(): void {
		// WC_Shipping_Zones::get_shipping_zones() only exists in WooCommerce
		// 10.3+, while the plugin's floor is 9.9 and the `WC requires at
		// least` header does not block activation. Calling it on 9.9-10.2 is
		// a fatal inside wp_head, white-screening the homepage and every
		// product page.
		//
		// Source-level assertion because the guard reads a constant defined
		// at bootstrap, which a unit test cannot vary per-case.
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/ai-storefront/class-wc-ai-storefront-shipping-policy.php'
		);

		$this->assertMatchesRegularExpression(
			"/version_compare\(\s*WC_VERSION,\s*'10\.3',\s*'<'\s*\)/",
			$source,
			'The zone reader must refuse to run below WooCommerce 10.3.'
		);
		$this->assertStringNotContainsString(
			"class_exists( 'WC_Shipping_Zones' )",
			$source,
			'A class check does not catch this: WC_Shipping_Zones has existed since WC 2.6.'
		);
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	/**
	 * A zone with the given id, locations and methods.
	 *
	 * @param int   $id        Zone id (0 is the catch-all).
	 * @param array $locations Pairs of [type, code].
	 * @param array $methods   Shipping method instances.
	 * @return \Mockery\MockInterface
	 */
	private function zone( int $id, array $locations, array $methods ) {
		$objects = array();
		foreach ( $locations as $pair ) {
			$location       = new \stdClass();
			$location->type = $pair[0];
			$location->code = $pair[1];
			$objects[]      = $location;
		}

		$zone = \Mockery::mock( 'WC_Shipping_Zone' );
		$zone->shouldReceive( 'get_id' )->andReturn( $id );
		$zone->shouldReceive( 'get_zone_locations' )->andReturn( $objects );
		$zone->shouldReceive( 'get_shipping_methods' )->andReturn( $methods );
		return $zone;
	}

	/**
	 * @param string $requires   '' for unconditional, else 'min_amount'.
	 * @param string $min_amount Threshold as WooCommerce stores it.
	 */
	private function free_method( string $requires, string $min_amount ): \WC_Shipping_Free_Shipping {
		$method             = new \WC_Shipping_Free_Shipping();
		$method->requires   = $requires;
		$method->min_amount = $min_amount;
		return $method;
	}

	/**
	 * @param string $cost Raw cost expression, as typed by the merchant.
	 */
	private function flat_method( string $cost ): \WC_Shipping_Flat_Rate {
		$method       = new \WC_Shipping_Flat_Rate();
		$method->cost = $cost;
		return $method;
	}

	/**
	 * A policy whose zones are injected rather than read from the database.
	 *
	 * Subclasses rather than manipulating WC_Shipping_Zones static state,
	 * mirroring the pattern JsonLdTest uses for the same reason.
	 *
	 * @param array $zones Zone doubles.
	 */
	private function policy( array $zones ): WC_AI_Storefront_Shipping_Policy {
		\Brain\Monkey\Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		\Brain\Monkey\Functions\when( 'wc_shipping_enabled' )->justReturn( true );
		// Brain Monkey registers a function suite-wide once ANY test mocks it,
		// so this must be unconditional. The decimals test re-stubs it after.
		\Brain\Monkey\Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );

		return new class( $zones ) extends WC_AI_Storefront_Shipping_Policy {
			/** @var array */
			private array $zones;

			public function __construct( array $zones ) {
				$this->zones = $zones;
			}

			protected function get_shipping_zones(): array {
				return $this->zones;
			}
		};
	}
}
