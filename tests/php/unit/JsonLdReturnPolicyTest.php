<?php
/**
 * Tests for `WC_AI_Storefront_JsonLd::build_return_policy_block()` and
 * the wider settings-driven return-policy emission (PR-C).
 *
 * Pin the per-mode emission shape so a regression in
 * `enhance_product_data()` (or the new `build_return_policy_block()`
 * helper) can't silently produce a structurally invalid
 * `hasMerchantReturnPolicy` block. Three modes × edge cases
 * (smart-degrade days, single-vs-multi method, page link presence,
 * Offer-level placement, missing country) round out the coverage.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdReturnPolicyTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->jsonld = new WC_AI_Storefront_JsonLd();

		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$query = http_build_query( $args );
				$sep   = str_contains( $url, '?' ) ? '&' : '?';
				return $url . $sep . $query;
			}
		);
		Functions\when( 'wc_get_product_cat_ids' )->justReturn( [] );
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => 'US' ]
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => "https://example.com/?p={$id}"
		);
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_product(): Mockery\MockInterface {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 42 );
		$product->shouldReceive( 'get_permalink' )
			->andReturn( 'https://example.com/product/test/' );
		$product->shouldReceive( 'managing_stock' )->andReturn( false );
		$product->shouldReceive( 'get_stock_quantity' )->andReturn( null );
		$product->shouldReceive( 'has_weight' )->andReturn( false );
		$product->shouldReceive( 'get_weight' )->andReturn( '' );
		$product->shouldReceive( 'has_dimensions' )->andReturn( false );
		$product->shouldReceive( 'get_dimensions' )->andReturn( [] );
		$product->shouldReceive( 'get_attributes' )->andReturn( [] );
		return $product;
	}

	/** Convenience for tests that always start from a baseline-syndicated product. */
	private function set_settings( array $return_policy ): void {
		WC_AI_Storefront::$test_settings = [
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => $return_policy,
		];
	}

	private function run_with_offer( array $extra_offer = [] ): array {
		$offer = array_merge( [ '@type' => 'Offer' ], $extra_offer );
		return $this->jsonld->enhance_product_data(
			[ 'offers' => [ $offer ] ],
			$this->make_product()
		);
	}

	// ------------------------------------------------------------------
	// Mode: unconfigured
	// ------------------------------------------------------------------

	public function test_unconfigured_mode_emits_no_policy_block(): void {
		$this->set_settings( [ 'mode' => 'unconfigured' ] );
		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result );
		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
	}

	// ------------------------------------------------------------------
	// Mode: returns_accepted
	// ------------------------------------------------------------------

	public function test_returns_accepted_full_emits_finite_window_with_all_fields(): void {
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'page_id' => 99,
				'days'    => 30,
				'fees'    => 'FreeReturn',
				'methods' => [ 'ReturnByMail' ],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
		$this->assertSame( 'US', $block['applicableCountry'] );
		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$block['returnPolicyCategory']
		);
		$this->assertSame( 30, $block['merchantReturnDays'] );
		$this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
		$this->assertSame( 'https://schema.org/FreeReturn', $block['returnFees'] );
		$this->assertSame( 'https://schema.org/ReturnByMail', $block['returnMethod'] );
	}

	public function test_returns_accepted_no_days_smart_degrades_to_unspecified(): void {
		$this->set_settings(
			[
				'mode' => 'returns_accepted',
				'days' => 0,
				'fees' => 'FreeReturn',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnUnspecified',
			$block['returnPolicyCategory']
		);
		$this->assertArrayNotHasKey( 'merchantReturnDays', $block );
	}

	public function test_returns_accepted_no_page_omits_merchant_return_link(): void {
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'page_id' => 0,
				'days'    => 14,
				'fees'    => 'FreeReturn',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
	}

	public function test_returns_accepted_single_method_emits_scalar(): void {
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'days'    => 14,
				'fees'    => 'FreeReturn',
				'methods' => [ 'ReturnInStore' ],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertIsString( $block['returnMethod'] );
		$this->assertSame( 'https://schema.org/ReturnInStore', $block['returnMethod'] );
	}

	public function test_returns_accepted_multiple_methods_emits_array(): void {
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'days'    => 14,
				'fees'    => 'FreeReturn',
				'methods' => [ 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' ],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertIsArray( $block['returnMethod'] );
		$this->assertSame(
			[
				'https://schema.org/ReturnByMail',
				'https://schema.org/ReturnInStore',
				'https://schema.org/ReturnAtKiosk',
			],
			$block['returnMethod']
		);
	}

	public function test_returns_accepted_no_methods_omits_return_method_field(): void {
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'days'    => 14,
				'fees'    => 'FreeReturn',
				'methods' => [],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertArrayNotHasKey( 'returnMethod', $block );
	}

	// ------------------------------------------------------------------
	// Mode: final_sale
	// ------------------------------------------------------------------

	public function test_final_sale_with_page_emits_not_permitted_and_link(): void {
		$this->set_settings(
			[
				'mode'    => 'final_sale',
				'page_id' => 17,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		$this->assertSame( 'https://example.com/?p=17', $block['merchantReturnLink'] );
		// final_sale mode never emits returnFees / returnMethod —
		// the policy precludes returns, so those fields would be
		// nonsensical.
		$this->assertArrayNotHasKey( 'returnFees', $block );
		$this->assertArrayNotHasKey( 'returnMethod', $block );
	}

	public function test_final_sale_no_page_emits_not_permitted_only(): void {
		$this->set_settings(
			[
				'mode'    => 'final_sale',
				'page_id' => 0,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
	}

	// ------------------------------------------------------------------
	// Schema-placement contract (Offer-level, not Product-level)
	// ------------------------------------------------------------------

	public function test_policy_block_emitted_at_offer_level_not_product_level(): void {
		$this->set_settings(
			[
				'mode' => 'returns_accepted',
				'days' => 30,
				'fees' => 'FreeReturn',
			]
		);

		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result );
		$this->assertArrayHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0]
		);
	}

	public function test_shipping_details_moved_to_offer_level(): void {
		$this->set_settings( [ 'mode' => 'unconfigured' ] );
		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey( 'shippingDetails', $result );
		$this->assertArrayHasKey( 'shippingDetails', $result['offers'][0] );
		$this->assertSame(
			'US',
			$result['offers'][0]['shippingDetails']['shippingDestination']['addressCountry']
		);
	}

	// ------------------------------------------------------------------
	// Country gate
	// ------------------------------------------------------------------

	public function test_no_country_emits_no_policy_or_shipping_blocks(): void {
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->set_settings(
			[
				'mode' => 'returns_accepted',
				'days' => 30,
				'fees' => 'FreeReturn',
			]
		);

		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'shippingDetails', $result['offers'][0] );
	}
}
