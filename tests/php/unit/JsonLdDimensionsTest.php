<?php
/**
 * Tests for the shared dimension-block builder.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdDimensionsTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = '' ) {
				if ( 'woocommerce_weight_unit' === $key ) {
					return 'kg';
				}
				if ( 'woocommerce_dimension_unit' === $key ) {
					return 'cm';
				}
				return $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a product mock with the given physical measurements.
	 *
	 * @param string|null $weight     Weight, or null for none.
	 * @param array|null  $dimensions length/width/height, or null for none.
	 * @return Mockery\MockInterface
	 */
	private function make_product( ?string $weight, ?array $dimensions ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'has_weight' )->andReturn( null !== $weight );
		$product->shouldReceive( 'get_weight' )->andReturn( (string) $weight );
		$product->shouldReceive( 'has_dimensions' )->andReturn( null !== $dimensions );
		$product->shouldReceive( 'get_dimensions' )->andReturn(
			$dimensions ?? array(
				'length' => '',
				'width'  => '',
				'height' => '',
			)
		);
		return $product;
	}

	/**
	 * Calls the private builder.
	 *
	 * @param mixed $product Product mock.
	 * @return array
	 */
	private function build( $product ): array {
		$jsonld = new WC_AI_Storefront_JsonLd();
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'build_dimension_blocks' );
		$method->setAccessible( true );
		return $method->invoke( $jsonld, $product );
	}

	public function test_builder_returns_all_four_blocks_with_unit_codes(): void {
		$blocks = $this->build(
			$this->make_product(
				'1.5',
				array(
					'length' => '10',
					'width'  => '20',
					'height' => '30',
				)
			)
		);

		$this->assertSame(
			array(
				'@type'    => 'QuantitativeValue',
				'value'    => 1.5,
				'unitCode' => 'KGM',
			),
			$blocks['weight']
		);
		// WC's "length" is Schema.org's "depth" — schema.org has no length.
		$this->assertSame( 10.0, $blocks['depth']['value'] );
		$this->assertSame( 20.0, $blocks['width']['value'] );
		$this->assertSame( 30.0, $blocks['height']['value'] );
		$this->assertSame( 'CMT', $blocks['depth']['unitCode'] );
	}

	public function test_builder_emits_numbers_not_strings(): void {
		// WC persists these as free-form decimal strings ('.5', '10').
		$blocks = $this->build(
			$this->make_product(
				'.5',
				array(
					'length' => '10',
					'width'  => '8',
					'height' => '3',
				)
			)
		);

		foreach ( array( 'weight', 'depth', 'width', 'height' ) as $key ) {
			$this->assertIsFloat( $blocks[ $key ]['value'], "{$key} must be a float" );
		}
		$this->assertStringNotContainsString( '"value":"', wp_json_encode( $blocks ) );
	}

	public function test_builder_omits_weight_when_absent(): void {
		$blocks = $this->build(
			$this->make_product(
				null,
				array(
					'length' => '10',
					'width'  => '20',
					'height' => '30',
				)
			)
		);

		$this->assertArrayNotHasKey( 'weight', $blocks );
		$this->assertArrayHasKey( 'depth', $blocks );
	}

	public function test_builder_omits_dimensions_when_absent(): void {
		$blocks = $this->build( $this->make_product( '1.5', null ) );

		$this->assertArrayHasKey( 'weight', $blocks );
		$this->assertArrayNotHasKey( 'depth', $blocks );
		$this->assertArrayNotHasKey( 'width', $blocks );
		$this->assertArrayNotHasKey( 'height', $blocks );
	}

	public function test_builder_returns_empty_array_when_product_has_neither(): void {
		$this->assertSame( array(), $this->build( $this->make_product( null, null ) ) );
	}

	public function test_add_dimensions_merges_the_same_blocks_into_markup(): void {
		// Pins that the wrapper and the builder agree — the same guarantee
		// build_return_policy_block()'s extraction test provides.
		$product = $this->make_product(
			'2',
			array(
				'length' => '4',
				'width'  => '5',
				'height' => '6',
			)
		);

		$expected = $this->build( $product );

		$jsonld = new WC_AI_Storefront_JsonLd();
		$markup = array();
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'add_dimensions' );
		$method->setAccessible( true );
		$method->invokeArgs( $jsonld, array( &$markup, $product ) );

		foreach ( $expected as $key => $block ) {
			$this->assertSame( $block, $markup[ $key ], "{$key} differs between builder and wrapper" );
		}
	}

	/**
	 * Runs add_shipping_details() and returns the resulting block.
	 *
	 * @param mixed $product Product mock.
	 * @return array|null
	 */
	private function shipping_block( $product ) {
		// `WC()` is deliberately NOT stubbed here — add_shipping_details()
		// never calls it (get_shipping_zones() goes through the
		// WC_Shipping_Zones stub directly), and Brain Monkey's WC()
		// stub leaks across the suite as MissingFunctionExpectations
		// for unrelated tests. See build_postal_address()'s docblock.
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$jsonld = new WC_AI_Storefront_JsonLd();
		$markup = array( 'offers' => array( array( '@type' => 'Offer' ) ) );
		$method = new \ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'add_shipping_details' );
		$method->setAccessible( true );
		$method->invokeArgs( $jsonld, array( &$markup, 'US', $product ) );

		return $markup['offers'][0]['shippingDetails'] ?? null;
	}

	public function test_shipping_details_carries_weight_and_dimensions(): void {
		$product = $this->make_product(
			'1.5',
			array(
				'length' => '10',
				'width'  => '8',
				'height' => '3',
			)
		);
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );

		$block = $this->shipping_block( $product );

		$this->assertSame( 'OfferShippingDetails', $block['@type'] );
		$this->assertSame( 1.5, $block['weight']['value'] );
		$this->assertSame( 'KGM', $block['weight']['unitCode'] );
		$this->assertSame( 10.0, $block['depth']['value'] );
		$this->assertSame( 8.0, $block['width']['value'] );
		$this->assertSame( 3.0, $block['height']['value'] );
	}

	public function test_shipping_details_omits_dimensions_when_product_has_none(): void {
		$product = $this->make_product( null, null );
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );

		$block = $this->shipping_block( $product );

		$this->assertSame( 'OfferShippingDetails', $block['@type'] );
		foreach ( array( 'weight', 'depth', 'width', 'height' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $block );
		}
	}

	public function test_shipping_details_keeps_its_existing_keys(): void {
		// Regression guard: adding dimensions must not displace the
		// destination or rate the block already carried.
		$product = $this->make_product( '1', null );
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );

		$block = $this->shipping_block( $product );

		$this->assertSame( 'DefinedRegion', $block['shippingDestination']['@type'] );
		$this->assertSame( 'US', $block['shippingDestination']['addressCountry'] );
	}

	public function test_no_shipping_block_at_all_for_a_virtual_product(): void {
		// A shippingDetails block on a no-ship product is contradictory,
		// and existing behaviour suppresses the whole block. Dimensions
		// must not resurrect it.
		$product = $this->make_product( '1.5', null );
		$product->shouldReceive( 'needs_shipping' )->andReturn( false );

		$this->assertNull( $this->shipping_block( $product ) );
	}
}
