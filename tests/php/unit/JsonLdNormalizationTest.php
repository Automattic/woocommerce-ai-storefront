<?php
/**
 * Tests for the upstream-WC-core JSON-LD normalization fixes folded
 * into PR-C (audit bugs #3, #4, #5).
 *
 * Each fix lives behind its own test so a future regression is loud,
 * and each is mentioned by name in the CHANGELOG so the
 * upstream-core nature is clear to anyone reading.
 *
 *   - seller.name double-encoding (`Piero&amp;#039;s` → `Piero's`)
 *   - weight string normalization (`'.5'` → `0.5` numeric)
 *   - priceCurrency at Offer level (Google's preferred placement)
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class JsonLdNormalizationTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_JsonLd $jsonld;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->jsonld = new WC_AI_Storefront_JsonLd();

		WC_AI_Storefront::$test_settings = array(
			'enabled'                => 'yes',
			'product_selection_mode' => 'all',
			'return_policy'          => array( 'mode' => 'unconfigured' ),
		);

		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				// Strip any fragment before appending query params, then
				// re-append it — matching WordPress core's behavior.
				// Without this, a permalink like `.../widget/#reviews`
				// would produce `.../widget/#reviews?add-to-cart=42`
				// where the entire query string is part of the fragment
				// and never reaches the server.
				$fragment = '';
				if ( str_contains( $url, '#' ) ) {
					[ $url, $fragment ] = explode( '#', $url, 2 );
					$fragment           = '#' . $fragment;
				}
				$query = http_build_query( $args );
				$sep   = str_contains( $url, '?' ) ? '&' : '?';
				return $url . $sep . $query . $fragment;
			}
		);
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'wc_get_product_cat_ids' )->justReturn( array() );
		Functions\when( 'wc_get_base_location' )->justReturn(
			array( 'country' => 'US' )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = '' ) =>
				'woocommerce_weight_unit' === $key ? 'kg' : $default
		);
		// Stub the per-product final-sale meta read to "not flagged"
		// — these normalization tests are about the store-wide policy
		// emission flow, not the per-product override gate.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// Default `wp_get_post_parent_id()` to 0 (non-variation
		// products). The override-scope resolution at
		// `enhance_product_data` calls this to determine whether to
		// read the flag off a parent product.
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_product( array $overrides = array() ): Mockery\MockInterface {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $overrides['id'] ?? 42 );
		$product->shouldReceive( 'get_permalink' )
			->andReturn( $overrides['permalink'] ?? 'https://example.com/product/test/' );
		$product->shouldReceive( 'managing_stock' )->andReturn( false );
		$product->shouldReceive( 'get_stock_quantity' )->andReturn( null );
		$product->shouldReceive( 'has_weight' )
			->andReturn( $overrides['has_weight'] ?? false );
		$product->shouldReceive( 'get_weight' )
			->andReturn( $overrides['weight'] ?? '' );
		$product->shouldReceive( 'has_dimensions' )->andReturn( false );
		$product->shouldReceive( 'get_dimensions' )->andReturn( array() );
		$product->shouldReceive( 'get_attributes' )->andReturn( array() );
		$product->shouldReceive( 'get_children' )->andReturn( array() );
		$product->shouldReceive( 'get_cross_sell_ids' )->andReturn( array() );
		$product->shouldReceive( 'get_upsell_ids' )->andReturn( array() );
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		// Default to purchasable for the JSON-LD URL gate (#373).
		$product->shouldReceive( 'is_purchasable' )
			->andReturn( $overrides['is_purchasable'] ?? true );
		// In stock by default: the buy-link gate reads `is_in_stock()`
		// alongside `is_purchasable()` (see `is_orderable()`, #606).
		$product->shouldReceive( 'is_in_stock' )
			->andReturn( $overrides['in_stock'] ?? true );
		// Physical by default: `add_shipping_details()` (#504) calls
		// `needs_shipping()` to skip shipping for virtual products.
		$product->shouldReceive( 'needs_shipping' )
			->andReturn( $overrides['needs_shipping'] ?? true );
		// Sale window (#582): `add_sale_window()` gates on `is_on_sale()` and
		// reads both boundary dates. Default to no sale window.
		$product->shouldReceive( 'is_on_sale' )
			->andReturn( $overrides['is_on_sale'] ?? false );
		$product->shouldReceive( 'get_date_on_sale_from' )
			->andReturn( $overrides['date_on_sale_from'] ?? null );
		$product->shouldReceive( 'get_date_on_sale_to' )
			->andReturn( $overrides['date_on_sale_to'] ?? null );

		// Argument-aware `is_type()` mock matching WC core: returns true
		// only when the queried type matches the configured product type
		// (default 'simple'). Tests in this file don't exercise the
		// bundle/grouped branch, but a constant `false` stub would lie
		// about `is_type('simple')` and silently take the wrong branch
		// if a future call-graph change introduces a type check anywhere
		// in the JSON-LD enrichment path.
		$type = $overrides['type'] ?? 'simple';
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $check ) use ( $type ) {
				return is_array( $check ) ? in_array( $type, $check, true ) : $type === $check;
			}
		);
		return $product;
	}

	// ------------------------------------------------------------------
	// Audit bug #3: seller.name double-encoding
	// ------------------------------------------------------------------

	public function test_seller_name_double_encoding_decoded(): void {
		// Real-world artifact observed on `pierorocca.com`: the WC core
		// JSON-LD generator hands us `Piero&amp;#039;s Fashion House`
		// (`'` first encoded as `&#039;`, then `&` re-escaped to
		// `&amp;`). After our normalization the value should read
		// `Piero's Fashion House` so AI agents JSON.parse-ing the
		// markup get the literal merchant-typed string.
		$markup = array(
			'offers' => array(
				array(
					'@type'  => 'Offer',
					'seller' => array(
						'@type' => 'Organization',
						'name'  => 'Piero&amp;#039;s Fashion House',
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $this->make_product() );

		$this->assertSame(
			"Piero's Fashion House",
			$result['offers'][0]['seller']['name']
		);
	}

	// ------------------------------------------------------------------
	// Audit bug #4: weight string value normalization
	// ------------------------------------------------------------------

	public function test_weight_string_value_normalized_to_numeric(): void {
		// WC stores weight as whatever the merchant typed in the
		// editor; a leading-dot string like `.5` round-trips through
		// JSON encoders as the literal `".5"` rather than as `0.5`.
		// Casting through (float) canonicalizes both the type and the
		// surface form.
		$product = $this->make_product(
			array(
				'has_weight' => true,
				'weight'     => '.5',
			)
		);

		$result = $this->jsonld->enhance_product_data( array(), $product );

		$this->assertSame( 0.5, $result['weight']['value'] );
		$this->assertIsFloat( $result['weight']['value'] );
	}

	// ------------------------------------------------------------------
	// Audit bug #5: priceCurrency at Offer level
	// ------------------------------------------------------------------

	public function test_price_currency_copied_to_offer_level_when_missing(): void {
		// WC core writes the currency under the nested
		// priceSpecification[0]; Google's preferred placement is on
		// the outer Offer dict. We copy it up so consumers reading
		// from either location resolve a value.
		$markup = array(
			'offers' => array(
				array(
					'@type'              => 'Offer',
					'priceSpecification' => array(
						array(
							'@type'         => 'UnitPriceSpecification',
							'price'         => '19.99',
							'priceCurrency' => 'USD',
						),
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $this->make_product() );

		$this->assertSame( 'USD', $result['offers'][0]['priceCurrency'] );
	}

	public function test_price_currency_not_overwritten_when_already_set_at_offer_level(): void {
		// Defensive: a third-party filter or future WC core change
		// might already set Offer-level priceCurrency. Don't clobber
		// it with the nested value.
		$markup = array(
			'offers' => array(
				array(
					'@type'              => 'Offer',
					'priceCurrency'      => 'EUR',
					'priceSpecification' => array(
						array(
							'@type'         => 'UnitPriceSpecification',
							'priceCurrency' => 'USD',
						),
					),
				),
			),
		);

		$result = $this->jsonld->enhance_product_data( $markup, $this->make_product() );

		$this->assertSame( 'EUR', $result['offers'][0]['priceCurrency'] );
	}
}
