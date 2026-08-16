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
			WC_AI_Storefront_Shipping_Policy::parse_cost( '[fee percent="10" min_fee="4"]' )
		);
	}

	public function test_percentage_accepts_single_quotes(): void {
		$parsed = WC_AI_Storefront_Shipping_Policy::parse_cost( "[fee percent='25']" );
		$this->assertSame( 0.25, $parsed['value'] );
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
		$disabled->enabled = false;
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
