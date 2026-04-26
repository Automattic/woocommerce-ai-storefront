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
		// Default policy pages to `publish` status + `page` type.
		// Tests that exercise the degradation paths (unpublished page,
		// wrong post type) override these — see
		// `test_emission_omits_merchant_return_link_when_page_unpublished`
		// for an example. Both are required because emission re-checks
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
	// Stale page_id degradation (page unpublished after save)
	// ------------------------------------------------------------------

	public function test_emission_omits_merchant_return_link_when_page_unpublished(): void {
		// Save-time sanitization enforces published status, but a
		// merchant can unpublish the page later. Without this gate,
		// `get_permalink()` would still return a URL pointing at a
		// dead/draft post — Google validators get a stale link, the
		// JS preview (which uses the published-only pages list)
		// silently omits the link, and the two outputs drift.
		// Re-checking at emission time keeps PHP and JS in lockstep.
		Functions\when( 'get_post_status' )->justReturn( 'draft' );

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

		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
		// Other fields still emit — only the link is gated.
		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$block['returnPolicyCategory']
		);
		$this->assertSame( 30, $block['merchantReturnDays'] );
	}

	public function test_final_sale_omits_merchant_return_link_when_page_unpublished(): void {
		// Same gate applies in final_sale mode — a merchant might
		// have a "no returns / final sale" disclaimer page they
		// later unpublish. The category claim still emits; just the
		// stale link drops.
		Functions\when( 'get_post_status' )->justReturn( 'draft' );

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
		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
	}

	public function test_emission_omits_merchant_return_link_when_post_type_is_not_page(): void {
		// Same gate as the unpublished-page check, different drift
		// case: settings corrupted/bypassed (or future UI expanded
		// to other post types) such that page_id points at a
		// post/attachment instead of a page. Save-time sanitizer
		// would reject this, but a direct DB write or settings
		// migration could land an out-of-contract value. Emission
		// must mirror the sanitizer's `'page' === get_post_type()`
		// gate to refuse a wrong-shape link.
		Functions\when( 'get_post_type' )->justReturn( 'post' );

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

		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
		// Other fields still emit — only the link is gated.
		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$block['returnPolicyCategory']
		);
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

	// ------------------------------------------------------------------
	// Per-product final-sale override (PR-D)
	//
	// The override gate runs BEFORE store-wide mode logic. A flagged
	// product emits MerchantReturnNotPermitted regardless of the
	// store-wide setting — including when the store-wide is
	// `unconfigured` (the override forces a structured claim even
	// when the merchant otherwise opted out).
	//
	// All tests here flip the meta read to 'yes' for product 42
	// (the make_product() default ID) to exercise the override
	// branch.
	// ------------------------------------------------------------------

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

	public function test_per_product_final_sale_overrides_returns_accepted_mode(): void {
		// Store-wide is `returns_accepted` with a full configuration.
		// Per-product flag forces MerchantReturnNotPermitted instead.
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'page_id' => 0,
				'days'    => 30,
				'fees'    => 'FreeReturn',
				'methods' => [ 'ReturnByMail' ],
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame( 'MerchantReturnPolicy', $block['@type'] );
		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory'],
			'Per-product flag must force NotPermitted regardless of store-wide accepts mode.'
		);
		// Override must NOT carry the store-wide accepts-returns
		// fields (days/fees/methods) — those describe the opposite
		// posture from "no returns".
		$this->assertArrayNotHasKey( 'merchantReturnDays', $block );
		$this->assertArrayNotHasKey( 'returnFees', $block );
		$this->assertArrayNotHasKey( 'returnMethod', $block );
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

	public function test_per_product_final_sale_overrides_store_wide_final_sale_mode(): void {
		// Both store-wide AND per-product flag agree (final-sale).
		// The override path still wins; the result is the same as
		// the store-wide path would emit, but produced by the
		// override branch. Locks the no-op equivalence so a future
		// refactor that drops one of the two paths can verify both
		// continue to emit the same shape.
		$this->flag_product_as_final_sale();
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
	}

	public function test_per_product_final_sale_reuses_store_wide_policy_page(): void {
		// Override block reuses `merchantReturnLink` from the
		// store-wide policy when configured — a "no returns" page
		// often documents what's covered (defective goods, statutory
		// rights), so reusing the link beats omission.
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'page_id' => 99,
				'days'    => 30,
				'fees'    => 'FreeReturn',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnNotPermitted',
			$block['returnPolicyCategory']
		);
		$this->assertSame(
			'https://example.com/?p=99',
			$block['merchantReturnLink']
		);
	}

	public function test_per_product_final_sale_omits_link_when_no_store_wide_page(): void {
		// No store-wide page configured → override block emits the
		// bare minimum (no `merchantReturnLink`). Verifying the
		// optional-link branch under the override path.
		$this->flag_product_as_final_sale();
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'page_id' => 0,
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertArrayNotHasKey( 'merchantReturnLink', $block );
	}

	public function test_unflagged_product_uses_store_wide_setting(): void {
		// Regression guard: the override gate must not fire when the
		// product is NOT flagged. Without the meta read returning ''
		// (setUp default), the product falls through to the
		// store-wide returns_accepted logic. This is the ~99% common
		// path — every other JsonLdReturnPolicyTest exercises it
		// implicitly, but pinning a dedicated assertion here makes
		// the contract explicit.
		// (No flag — setUp's default get_post_meta('') applies.)
		$this->set_settings(
			[
				'mode'    => 'returns_accepted',
				'page_id' => 0,
				'days'    => 30,
				'fees'    => 'FreeReturn',
			]
		);

		$block = $this->run_with_offer()['offers'][0]['hasMerchantReturnPolicy'];

		$this->assertSame(
			'https://schema.org/MerchantReturnFiniteReturnWindow',
			$block['returnPolicyCategory'],
			'Unflagged product must fall through to store-wide accepts mode.'
		);
		$this->assertSame( 30, $block['merchantReturnDays'] );
	}
}
