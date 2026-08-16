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
}
