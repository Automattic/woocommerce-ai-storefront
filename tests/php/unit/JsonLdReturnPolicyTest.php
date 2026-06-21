<?php
/**
 * Tests for `WC_AI_Storefront_JsonLd::build_return_policy_block()` and
 * the wider settings-driven return-policy emission.
 *
 * Pin the per-mode emission shape so a regression in
 * `enhance_product_data()` (or the `build_return_policy_block()`
 * helper) can't silently produce a structurally invalid
 * `hasMerchantReturnPolicy` block. The new shape separates Google's
 * Option A (inline detail, `mode='details'`) from Option B
 * (`merchantReturnLink`, `mode='link'`); `mode='unconfigured'` emits
 * nothing. Edge cases (smart-degrade days, single-vs-multi method,
 * page link presence, Offer-level placement, missing country) round
 * out the coverage.
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
				// Strip any fragment before appending query params, then
				// re-append it — matching WordPress core's behavior.
				// Without this, a permalink like `.../widget/#reviews`
				// would produce `.../widget/#reviews?add-to-cart=42`
				// where the entire query string is part of the fragment
				// and never reaches the server.
				$fragment = '';
				if ( str_contains( $url, '#' ) ) {
					[ $url, $fragment ] = explode( '#', $url, 2 );
					$fragment = '#' . $fragment;
				}
				$query = http_build_query( $args );
				$sep   = str_contains( $url, '?' ) ? '&' : '?';
				return $url . $sep . $query . $fragment;
			}
		);
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.com' . $path
		);
		Functions\when( 'wc_get_product_cat_ids' )->justReturn( [] );
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => 'US' ]
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => "https://example.com/?p={$id}"
		);
		// Default policy pages to `publish` status + `page` type.
		// Tests that exercise the degradation paths (unpublished page,
		// wrong post type) override these — see
		// `test_link_mode_with_unpublished_page_emits_null` for an
		// example. Both are required because emission re-checks
		// both at runtime to mirror the sanitizer's save-time gate
		// (which enforces `'publish' === get_post_status()` AND
		// `'page' === get_post_type()`).
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );

		// Default the per-product final-sale flag to "not flagged" for
		// every test. The store-wide policy tests below all use the
		// default mock product (id=42) which is NOT flagged final-sale,
		// so the override gate in `build_return_policy_block` should
		// fall through to the store-wide logic. Per-product override
		// tests further down override this stub to return 'yes' for
		// product id 42 to exercise the override branch.
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// Default `wp_get_post_parent_id()` to 0 (non-variation
		// products). Variant-specific tests override this alias to
		// return a parent product ID when the variation's own ID is
		// passed.
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		WC_AI_Storefront::$test_settings = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_product( int $id = 42 ): Mockery\MockInterface {
		// Variant-vs-parent resolution happens at the call site via
		// `wp_get_post_parent_id($product->get_id())` (a global WP
		// function), NOT via any product-level method. Variant tests
		// stub `wp_get_post_parent_id` directly to return the parent
		// product's ID.
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_permalink' )
			->andReturn( 'https://example.com/product/test/' );
		$product->shouldReceive( 'managing_stock' )->andReturn( false );
		$product->shouldReceive( 'get_stock_quantity' )->andReturn( null );
		$product->shouldReceive( 'has_weight' )->andReturn( false );
		$product->shouldReceive( 'get_weight' )->andReturn( '' );
		$product->shouldReceive( 'has_dimensions' )->andReturn( false );
		$product->shouldReceive( 'get_dimensions' )->andReturn( [] );
		$product->shouldReceive( 'get_attributes' )->andReturn( [] );
		$product->shouldReceive( 'get_children' )->andReturn( [] );
		$product->shouldReceive( 'get_sku' )->andReturn( '' );
		$product->shouldReceive( 'get_cross_sell_ids' )->andReturn( [] );
		$product->shouldReceive( 'get_upsell_ids' )->andReturn( [] );
		// Default to purchasable; the JSON-LD URL gate (#373) calls
		// `is_purchasable()` before emitting `BuyAction` /
		// `checkoutPageURLTemplate`.
		$product->shouldReceive( 'is_purchasable' )->andReturn( true );
		// Physical product: `add_shipping_details()` (#504) calls
		// `needs_shipping()` to skip the shipping block for virtual products.
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );

		// Argument-aware `is_type()` mock matching WC core: returns true
		// only when the queried type matches 'simple' (the type return-
		// policy tests need). A constant `false` stub would lie about
		// `is_type('simple')` and silently take the wrong branch if a
		// future call-graph change introduces a type check anywhere in
		// the JSON-LD enrichment path. This helper doesn't accept a
		// type override (all return-policy tests are simple products);
		// add an override parameter if that ever changes.
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static function ( $check ) {
				return is_array( $check ) ? in_array( 'simple', $check, true ) : 'simple' === $check;
			}
		);
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

	private function run_with_offer( array $extra_offer = [], ?Mockery\MockInterface $product = null ): array {
		$offer = array_merge( [ '@type' => 'Offer' ], $extra_offer );
		return $this->jsonld->enhance_product_data(
			[ 'offers' => [ $offer ] ],
			$product ?? $this->make_product()
		);
	}

	/**
	 * Helper: flip the per-product final-sale flag on for product 42.
	 * Tests that need the flag OFF rely on the setUp default ('').
	 */
	private function flag_product_as_final_sale(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $product_id, string $key, bool $single = false ) {
				if (
					42 === $product_id
					&& WC_AI_Storefront_Product_Meta_Box::META_KEY === $key
				) {
					return 'yes';
				}
				return '';
			}
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

	public function test_unconfigured_mode_emits_no_policy_block_even_with_junk_fields(): void {
		// After the mode-aware sanitizer runs, unconfigured can never carry
		// page_id — but a direct DB write or legacy stored value could. Gate
		// must still emit nothing.
		$this->set_settings( [ 'mode' => 'unconfigured', 'page_id' => 99 ] );
		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
	}

	// ------------------------------------------------------------------
	// Mode: link (new shape)
	// ------------------------------------------------------------------

	public function test_link_mode_with_valid_page_emits_link_only(): void {
		// Option B: only merchantReturnLink, no returnPolicyCategory,
		// no applicableCountry.
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 99,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
		$this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
		$this->assertArrayNotHasKey( 'returnPolicyCategory', $block );
		$this->assertArrayNotHasKey( 'applicableCountry', $block );
		$this->assertArrayNotHasKey( 'returnFees', $block );
		$this->assertArrayNotHasKey( 'merchantReturnDays', $block );
	}

	public function test_link_mode_with_zero_page_emits_null(): void {
		// mode='link' with page_id=0 produces nothing — the merchant
		// chose "link" but hasn't picked a page yet.
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 0,
			]
		);

		$result = $this->run_with_offer();
		$this->assertArrayNotHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0]
		);
	}

	public function test_link_mode_with_unpublished_page_emits_null(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 99,
			]
		);

		$result = $this->run_with_offer();
		$this->assertArrayNotHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0]
		);
	}

	public function test_link_mode_with_non_page_post_type_emits_null(): void {
		// page_id points at a post (or any non-`page` post type) —
		// reject the link emission to mirror the sanitizer's save-time
		// gate (`'page' === get_post_type()`). Without this re-check,
		// a merchant who flipped a `page_id` to point at a `post` via
		// direct option edit would get an unintended URL leaked into
		// JSON-LD.
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 99,
			]
		);

		$result = $this->run_with_offer();
		$this->assertArrayNotHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0]
		);
	}

	public function test_link_mode_emits_even_when_country_unset(): void {
		// Option B carries no applicableCountry, so the country gate
		// must not block it.
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 99,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];
		$this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
	}

	// ------------------------------------------------------------------
	// Mode: details + category: final_sale
	// ------------------------------------------------------------------

	public function test_details_final_sale_emits_not_permitted(): void {
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'final_sale',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
		$this->assertSame( 'US', $block['applicableCountry'] );
		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
		$this->assertArrayNotHasKey( 'returnFees', $block );
	}

	public function test_details_final_sale_emits_without_country_when_unset(): void {
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'final_sale',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		$this->assertArrayNotHasKey( 'applicableCountry', $block );
	}

	// ------------------------------------------------------------------
	// Mode: details + category: returns_accepted
	// ------------------------------------------------------------------

	public function test_details_returns_accepted_days_gt_0_emits_finite_window(): void {
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
				'methods'  => [ 'ReturnByMail' ],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'US', $block['applicableCountry'] );
		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$block['returnPolicyCategory']
		);
		$this->assertSame( 30, $block['merchantReturnDays'] );
		$this->assertSame( 'https://schema.org/FreeReturn', $block['returnFees'] );
		$this->assertSame( 'https://schema.org/ReturnByMail', $block['returnMethod'] );
		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
	}

	public function test_details_returns_accepted_days_0_smart_degrades_to_unspecified(): void {
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => null,
				'fees'     => 'FreeReturn',
				'methods'  => [],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnUnspecified',
			$block['returnPolicyCategory']
		);
		$this->assertArrayNotHasKey( 'merchantReturnDays', $block );
	}

	public function test_details_returns_accepted_no_country_emits_null(): void {
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
				'methods'  => [],
			]
		);

		$result = $this->run_with_offer();
		$this->assertArrayNotHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0]
		);
	}

	public function test_details_returns_accepted_single_method_emits_scalar(): void {
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 14,
				'fees'     => 'FreeReturn',
				'methods'  => [ 'ReturnInStore' ],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertIsString( $block['returnMethod'] );
		$this->assertSame( 'https://schema.org/ReturnInStore', $block['returnMethod'] );
	}

	public function test_details_returns_accepted_multiple_methods_emits_array(): void {
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 14,
				'fees'     => 'FreeReturn',
				'methods'  => [ 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' ],
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

	public function test_details_returns_accepted_no_methods_omits_return_method_field(): void {
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 14,
				'fees'     => 'FreeReturn',
				'methods'  => [],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertArrayNotHasKey( 'returnMethod', $block );
	}

	// ------------------------------------------------------------------
	// Schema-placement contract (Offer-level, not Product-level)
	// ------------------------------------------------------------------

	public function test_policy_block_emitted_at_offer_level_not_product_level(): void {
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
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
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
			]
		);

		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
		$this->assertArrayNotHasKey( 'shippingDetails', $result['offers'][0] );
	}

	// ------------------------------------------------------------------
	// Per-product final-sale override
	//
	// The override gate runs BEFORE store-wide mode logic. A flagged
	// product emits MerchantReturnNotPermitted regardless of the
	// store-wide setting — including when the store-wide is
	// `unconfigured` (the override forces a structured claim even
	// when the merchant otherwise opted out). When the store is
	// `mode='link'` with a resolved page, the link wins for that
	// product too (the linked page documents what is still covered).
	//
	// All override tests here flip the meta read to 'yes' for product
	// 42 (the make_product() default ID) to exercise the override
	// branch.
	// ------------------------------------------------------------------

	public function test_per_product_final_sale_with_link_mode_and_valid_page_emits_link(): void {
		// Flagged product + store is mode='link' + page resolves → link wins.
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 99,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
		$this->assertArrayNotHasKey( 'returnPolicyCategory', $block );
	}

	public function test_per_product_final_sale_with_link_mode_no_page_emits_not_permitted(): void {
		// Flagged product + store is mode='link' + page_id=0 → link fails,
		// fall back to NotPermitted.
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'    => 'link',
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

	public function test_per_product_final_sale_with_details_mode_emits_not_permitted(): void {
		// Flagged product + store is mode='details' → no page_id available,
		// emit NotPermitted.
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
				'methods'  => [],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
		$this->assertArrayNotHasKey( 'returnFees', $block );
	}

	public function test_per_product_final_sale_overrides_unconfigured_mode(): void {
		// Store-wide is `unconfigured` (merchant chose "don't expose
		// any policy"). Per-product flag still emits a policy block
		// — the override is the merchant's most-specific intent.
		// Without this branch, a flagged product on an unconfigured
		// store would silently emit nothing, defeating the merchant's
		// per-product opt-in.
		$this->flag_product_as_final_sale();
		$this->set_settings( [ 'mode' => 'unconfigured' ] );

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
	}

	public function test_per_product_final_sale_overrides_details_final_sale_mode(): void {
		// Both store-wide AND per-product flag agree (final-sale).
		// The override path still wins; the result is the same as
		// the store-wide path would emit, but produced by the
		// override branch. Locks the no-op equivalence so a future
		// refactor that drops one of the two paths can verify both
		// continue to emit the same shape.
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'final_sale',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
	}

	// ------------------------------------------------------------------
	// Per-product override: empty base country (issue #124)
	//
	// WC's setup wizard sets the store country, but installs that skip
	// or disable the wizard (custom onboarding, headless storefronts,
	// B2B sites) can have an empty `wc_get_base_location()['country']`.
	// For MerchantReturnNotPermitted paths (per-product flag AND store-
	// wide final_sale), omit `applicableCountry` when empty — Schema.org
	// marks the field as recommended, not required, for no-return
	// policies. For returns_accepted, keep the null return (a return
	// window without a target region is not useful).
	// ------------------------------------------------------------------

	public function test_per_product_final_sale_emits_without_country(): void {
		// Core acceptance criterion for issue #124: a merchant who
		// flags a product final-sale should see the structured claim
		// in the JSON-LD regardless of whether the store address is
		// configured. applicableCountry must be absent (not set to a
		// fallback like 'US'), and the block must be structurally valid.
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->flag_product_as_final_sale();
		$this->set_settings( [ 'mode' => 'unconfigured' ] );

		$result = $this->run_with_offer();

		// Block must be present despite empty country.
		$this->assertArrayHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0],
			'Per-product final-sale flag must emit the policy block even when store country is unset.'
		);
		$block = $result['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		// applicableCountry must be omitted — not defaulted to a
		// fallback value. Schema.org accepts the omission for
		// MerchantReturnNotPermitted; emitting a wrong country would
		// be worse than omitting it.
		$this->assertArrayNotHasKey(
			'applicableCountry',
			$block,
			'applicableCountry must be omitted, not defaulted, when the store country is unset.'
		);
		// shippingDetails must still be absent — a DefinedRegion
		// without addressCountry is meaningless.
		$this->assertArrayNotHasKey( 'shippingDetails', $result['offers'][0] );
	}

	public function test_store_wide_final_sale_emits_without_country(): void {
		// Store-wide details/final_sale must also emit without
		// applicableCountry when the base country is unset, for
		// the same Schema.org rationale as the per-product override.
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'final_sale',
			]
		);

		$result = $this->run_with_offer();

		$this->assertArrayHasKey( 'hasMerchantReturnPolicy', $result['offers'][0] );
		$block = $result['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		$this->assertArrayNotHasKey( 'applicableCountry', $block );
	}

	public function test_returns_accepted_still_omitted_when_country_unset(): void {
		// Regression guard: details/returns_accepted must still produce
		// no policy block when country is unset (same behavior as
		// before the issue #124 fix). A return-window declaration
		// without a target region is not useful to validators.
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
			]
		);

		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0],
			'details/returns_accepted must NOT emit a policy block when the store country is unset.'
		);
	}

	public function test_link_mode_emits_link_even_when_country_unset(): void {
		// Complement to the test above: in link mode, the Option B link
		// emits even when the store country is unset. Option B (a bare
		// merchantReturnLink) carries no applicableCountry, so the link
		// short-circuit MUST sit ABOVE the country null-gate. A refactor
		// that reordered them would wrongly drop the link for headless/B2B
		// stores with no base country — exactly the merchants most likely
		// to lean on a hosted policy page.
		Functions\when( 'wc_get_base_location' )->justReturn(
			[ 'country' => '' ]
		);
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 99,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'https://example.com/?p=99', $block['merchantReturnLink'] );
		$this->assertArrayNotHasKey( 'applicableCountry', $block );
		$this->assertArrayNotHasKey( 'merchantReturnDays', $block );
	}

	public function test_unflagged_product_uses_store_wide_setting(): void {
		// Regression guard: the override gate must not fire when the
		// product is NOT flagged. Without the meta read returning ''
		// (setUp default), the product falls through to the store-wide
		// details/returns_accepted logic. This is the ~99% common path —
		// every other test exercises it implicitly, but pinning a
		// dedicated assertion here makes the contract explicit.
		// (No flag — setUp's default get_post_meta('') applies.)
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$block['returnPolicyCategory'],
			'Unflagged product must fall through to store-wide details/returns_accepted mode.'
		);
		$this->assertSame( 30, $block['merchantReturnDays'] );
	}

	// ------------------------------------------------------------------
	// Per-product override: variation inheritance
	//
	// `WC_Product_Variation` reports its parent's product ID via
	// `get_parent_id()`. The JSON-LD layer resolves the override-flag
	// scope to the parent product so a merchant flagging the parent
	// "Final sale" sees every variant inherit that posture without
	// re-flagging each one. Pin both directions:
	//   - parent flagged → variant emits NotPermitted
	//   - parent unflagged → variant follows store-wide policy
	// ------------------------------------------------------------------

	public function test_variant_inherits_parent_final_sale_flag(): void {
		// Variant id=43 with parent id=42. Parent is flagged final-sale;
		// variant's own meta is unset. Expectation: NotPermitted.
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $product_id, string $key, bool $single = false ) {
				if (
					42 === $product_id
					&& WC_AI_Storefront_Product_Meta_Box::META_KEY === $key
				) {
					return 'yes';
				}
				return '';
			}
		);
		// `wp_get_post_parent_id(43)` resolves the variant's parent to
		// id=42, which is what `enhance_product_data` uses to look up
		// the override flag. Mirrors WC's actual data shape: variations
		// are posts whose `post_parent` is the parent product ID.
		Functions\when( 'wp_get_post_parent_id' )->alias(
			static fn( int $post_id ) => 43 === $post_id ? 42 : 0
		);
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
			]
		);

		$variant = $this->make_product( 43 );
		$block   = $this->run_with_offer( [], $variant )['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory'],
			'Variant must inherit parent final-sale flag — store-wide accepts mode would otherwise win.'
		);
	}

	public function test_variant_does_not_inherit_when_parent_unflagged(): void {
		// Variant id=43 with parent id=42. Neither parent nor variant
		// is flagged. Expectation: store-wide policy applies (variant
		// gets its parent's "no flag" instead of variant-self meta —
		// the resolution is parent-first regardless of the variant's
		// own meta state, and parent has no flag, so store-wide wins).
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_get_post_parent_id' )->alias(
			static fn( int $post_id ) => 43 === $post_id ? 42 : 0
		);
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
			]
		);

		$variant = $this->make_product( 43 );
		$block   = $this->run_with_offer( [], $variant )['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$block['returnPolicyCategory'],
			'Unflagged variant must use store-wide policy via parent fall-through.'
		);
	}

	// ------------------------------------------------------------------
	// Per-product override: build_return_policy_block null short-circuit
	//
	// The `?int $product_id = null` signature default exists for
	// callers that legitimately want the store-wide-only logic
	// (admin Policies-tab live-preview rendering, isolated unit
	// tests). Verify the override gate skips entirely when null is
	// passed — even when the meta read would otherwise return 'yes'
	// for some other product ID. Reflection is needed because the
	// method is protected.
	// ------------------------------------------------------------------

	public function test_build_return_policy_block_skips_override_when_product_id_is_null(): void {
		// Set up a meta state that WOULD trigger the override if a
		// product ID were passed — `get_post_meta` returns 'yes' for
		// any input. Then call build_return_policy_block(...null) and
		// assert the override branch was not taken (returnPolicyCategory
		// reflects the store-wide details/returns_accepted mode, not
		// MerchantReturnNotPermitted).
		Functions\when( 'get_post_meta' )->justReturn( 'yes' );

		$method = new ReflectionMethod( WC_AI_Storefront_JsonLd::class, 'build_return_policy_block' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->jsonld,
			[
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
			],
			'US',
			null
		);

		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$result['returnPolicyCategory'],
			'Null product_id must skip the override gate entirely, regardless of meta state.'
		);
		$this->assertSame( 30, $result['merchantReturnDays'] );
	}

	// ------------------------------------------------------------------
	// Emission-time allow-list validation (audit H-1)
	// ------------------------------------------------------------------

	public function test_unknown_fees_value_defaults_to_free_return_at_emission(): void {
		// The save-time sanitizer rejects unknown fee values, but a
		// direct DB write or import could bypass it. The emission-time
		// allow-list must catch it and fall back to FreeReturn rather
		// than concatenating an arbitrary string onto the schema.org URL.
		$markup = [
			'offers' => [
				[
					'@type' => 'Offer',
				],
			],
		];

		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'return_policy' => [
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'EvilReturn',  // Not in allow-list.
			],
		];

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( $markup, $product );

		$block = $result['offers'][0]['hasMerchantReturnPolicy'];
		$this->assertSame(
			'https://schema.org/FreeReturn',
			$block['returnFees'],
			'Unknown fees value must be sanitized to FreeReturn at emission time.'
		);
	}

	public function test_unknown_method_values_are_filtered_out_at_emission(): void {
		// Non-allow-listed method strings must be silently dropped at
		// emission time. If the only stored methods are invalid, the
		// returnMethod property must be omitted entirely rather than
		// emitting an empty array or an invalid schema.org URL.
		$markup = [
			'offers' => [
				[
					'@type' => 'Offer',
				],
			],
		];

		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'return_policy' => [
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
				'methods'  => [ 'ReturnByMail', 'NotAValidMethod', 'ReturnInStore' ],
			],
		];

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( $markup, $product );

		$block   = $result['offers'][0]['hasMerchantReturnPolicy'];
		$methods = $block['returnMethod'];
		$this->assertIsArray( $methods );
		$this->assertCount( 2, $methods, 'Only the two valid methods should survive.' );
		$this->assertContains( 'https://schema.org/ReturnByMail', $methods );
		$this->assertContains( 'https://schema.org/ReturnInStore', $methods );
		$this->assertNotContains( 'https://schema.org/NotAValidMethod', $methods );
	}

	public function test_duplicate_methods_are_deduped_at_emission(): void {
		// A tampered or imported settings value could contain duplicate
		// method entries. `array_unique()` at emission time must remove
		// them so the JSON-LD doesn't emit repeated schema.org URLs.
		$markup = [
			'offers' => [
				[
					'@type' => 'Offer',
				],
			],
		];

		WC_AI_Storefront::$test_settings = [
			'enabled'       => 'yes',
			'return_policy' => [
				'mode'     => 'details',
				'category' => 'returns_accepted',
				'days'     => 30,
				'fees'     => 'FreeReturn',
				'methods'  => [ 'ReturnByMail', 'ReturnByMail', 'ReturnInStore' ],
			],
		];

		$product = $this->make_product();
		$result  = $this->jsonld->enhance_product_data( $markup, $product );

		$methods = $result['offers'][0]['hasMerchantReturnPolicy']['returnMethod'];
		$this->assertIsArray( $methods );
		$this->assertCount( 2, $methods, 'Duplicate methods must be deduped at emission.' );
		$this->assertSame( array_unique( $methods ), $methods );
	}

	// ------------------------------------------------------------------
	// Fail-closed defensive branches (FIX 5a)
	// ------------------------------------------------------------------

	public function test_unknown_top_level_mode_emits_no_policy_block(): void {
		// The sanitizer rejects unknown modes at save time (failing closed
		// to 'unconfigured'), but a direct DB write or a malformed filter
		// could bypass it. The emitter must also fail closed: an
		// unrecognized top-level mode value must produce no policy block,
		// matching the JS derivePreview() fail-closed guard.
		$this->set_settings( [ 'mode' => 'gibberish' ] );
		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0],
			'Unknown top-level mode must not emit a policy block (fail closed).'
		);
	}

	public function test_details_with_unknown_category_emits_no_policy_block(): void {
		// mode='details' with an unrecognized category value must also
		// fail closed. Mirrors the JS guard: `category !== RETURNS_ACCEPTED
		// && category !== FINAL_SALE → return null`.
		$this->set_settings(
			[
				'mode'     => 'details',
				'category' => 'gibberish',
			]
		);
		$result = $this->run_with_offer();

		$this->assertArrayNotHasKey(
			'hasMerchantReturnPolicy',
			$result['offers'][0],
			'mode=details + unknown category must not emit a policy block (fail closed).'
		);
	}

	// ------------------------------------------------------------------
	// Per-product override: unpublished link page (FIX 5c)
	//
	// Currently only the page_id=0 path is covered. This test verifies
	// that when the store is mode='link' but the linked page has been
	// unpublished (get_post_status returns 'draft'), the link drops and
	// the per-product final-sale override falls back to bare
	// MerchantReturnNotPermitted (no merchantReturnLink key).
	// ------------------------------------------------------------------

	public function test_per_product_final_sale_with_link_mode_and_unpublished_page_emits_not_permitted(): void {
		// Page is registered (page_id > 0) but unpublished — status drifted
		// after save. The link resolution must fail (no URL emitted), and
		// the per-product final-sale override must fall back to bare
		// MerchantReturnNotPermitted, not nothing.
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'    => 'link',
				'page_id' => 99,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory'],
			'Flagged product + link mode + unpublished page must fall back to MerchantReturnNotPermitted.'
		);
		$this->assertArrayNotHasKey(
			'merchantReturnLink',
			$block,
			'merchantReturnLink must be absent when the linked page is unpublished.'
		);
	}
}
