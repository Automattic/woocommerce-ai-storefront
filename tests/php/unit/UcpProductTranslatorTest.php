<?php
/**
 * Tests for WC_AI_Storefront_UCP_Product_Translator.
 *
 * Two variant-expansion paths:
 *   - Simple product (or caller passes no variations): one synthesized
 *     default variant, id suffixed `_default`.
 *   - Variable product with caller-supplied variations: one real UCP
 *     variant per fetched WC variation, each carrying its own price +
 *     attributes.
 *
 * The translator is pure — it does not dispatch `rest_do_request` to
 * fetch variations. The REST controller pre-fetches and passes them in.
 *
 * @package WooCommerce_AI_Storefront
 */

class UcpProductTranslatorTest extends \PHPUnit\Framework\TestCase {

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	/**
	 * A minimally-populated WC Store API response for a simple product.
	 *
	 * @return array<string, mixed>
	 */
	private function simple_product_fixture(): array {
		return [
			'id'                => 123,
			'name'              => 'Widget',
			'slug'              => 'widget',
			'permalink'         => 'https://example.com/product/widget/',
			'short_description' => '<p>A simple <em>widget</em>.</p>',
			'is_in_stock'       => true,
			'prices'            => [
				'price'               => '2500',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			],
			'categories'        => [
				[ 'id' => 5, 'name' => 'Widgets', 'slug' => 'widgets' ],
				[ 'id' => 12, 'name' => 'Gadgets', 'slug' => 'gadgets' ],
			],
			'images'            => [
				[ 'src' => 'https://example.com/widget.jpg', 'alt' => 'A widget' ],
			],
		];
	}

	/**
	 * A WC Store API response for a variable product with price range.
	 *
	 * Used for price-range assertions that don't require variation
	 * expansion. For variation-expansion tests, see
	 * variable_product_with_variations_fixture().
	 */
	private function variable_product_fixture(): array {
		return [
			'id'                => 789,
			'name'              => 'T-Shirt',
			'prices'            => [
				'price'               => '1000',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
				'price_range'         => [
					'min_amount' => '1000',
					'max_amount' => '1500',
				],
			],
		];
	}

	/**
	 * A variable product response paired with the full Store API responses
	 * for each of its variations.
	 *
	 * In production the REST controller pre-fetches each variation via
	 * `rest_do_request( GET /wc/store/v1/products/{variation_id} )` and
	 * passes the decoded responses to `translate()`. The fixture mirrors
	 * that shape so tests exercise the real code path.
	 *
	 * @return array{product: array<string, mixed>, variations: array<int, array<string, mixed>>}
	 */
	private function variable_product_with_variations_fixture(): array {
		return [
			'product'    => [
				'id'         => 789,
				'name'       => 'T-Shirt',
				'type'       => 'variable',
				'prices'     => [
					'price'               => '1000',
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
					'price_range'         => [
						'min_amount' => '1000',
						'max_amount' => '1500',
					],
				],
				// WC Store API emits this as a thin list of
				// {id, attributes} pointers — full variation details
				// require a follow-up call per ID.
				'variations' => [
					[ 'id' => 101, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Small' ] ] ],
					[ 'id' => 102, 'attributes' => [ [ 'name' => 'Size', 'value' => 'Large' ] ] ],
				],
			],
			'variations' => [
				[
					'id'                => 101,
					'name'              => 'T-Shirt',
					'sku'               => 'SHIRT-S',
					'is_in_stock'       => true,
					'short_description' => '',
					'prices'            => [
						'price'               => '1000',
						'currency_code'       => 'USD',
						'currency_minor_unit' => 2,
					],
					'attributes'        => [
						[ 'name' => 'Size', 'value' => 'Small' ],
					],
				],
				[
					'id'                => 102,
					'name'              => 'T-Shirt',
					'sku'               => 'SHIRT-L',
					'is_in_stock'       => true,
					'short_description' => '',
					'prices'            => [
						'price'               => '1500',
						'currency_code'       => 'USD',
						'currency_minor_unit' => 2,
					],
					'attributes'        => [
						[ 'name' => 'Size', 'value' => 'Large' ],
					],
				],
			],
		];
	}

	// ------------------------------------------------------------------
	// Required UCP fields
	// ------------------------------------------------------------------

	public function test_id_prefixed_with_prod(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'prod_123', $result['id'] );
	}

	public function test_title_from_wc_name(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'Widget', $result['title'] );
	}

	public function test_description_strips_html_tags(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals(
			'A simple widget.',
			$result['description']['plain']
		);
	}

	public function test_description_decodes_html_entities(): void {
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = 'Joe&#039;s widgets &amp; gadgets';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertEquals(
			"Joe's widgets & gadgets",
			$result['description']['plain']
		);
	}

	public function test_emits_at_least_one_variant_per_schema_minitems(): void {
		// UCP schema: variants minItems: 1. Every product must have
		// at least one variant — even simple products. This test
		// locks in the synthesized-default behavior.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertNotEmpty( $result['variants'] );
		$this->assertGreaterThanOrEqual( 1, count( $result['variants'] ) );
	}

	public function test_synthesized_variant_has_default_suffix(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'var_123_default', $result['variants'][0]['id'] );
	}

	// ------------------------------------------------------------------
	// Price range
	// ------------------------------------------------------------------

	public function test_simple_product_price_range_min_equals_max(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertSame( 2500, $result['price_range']['min']['amount'] );
		$this->assertSame( 2500, $result['price_range']['max']['amount'] );
		$this->assertEquals( 'USD', $result['price_range']['min']['currency'] );
	}

	public function test_price_amounts_are_integers_not_strings(): void {
		// WC returns prices as STRINGS in minor units. UCP wants INTEGERS.
		// Test the explicit cast — if we ever accidentally forwarded the
		// string, JSON consumers would get a typing error.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertIsInt( $result['price_range']['min']['amount'] );
		$this->assertIsInt( $result['price_range']['max']['amount'] );
	}

	public function test_variable_product_price_range_spans_min_to_max(): void {
		// When WC supplies `prices.price_range` (variable product),
		// the UCP price_range uses min_amount and max_amount as
		// separate values. A $10-15 variable product has
		// price_range.min = 1000, price_range.max = 1500.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->variable_product_fixture()
		);

		$this->assertSame( 1000, $result['price_range']['min']['amount'] );
		$this->assertSame( 1500, $result['price_range']['max']['amount'] );
	}

	public function test_currency_passes_through_unchanged(): void {
		// Currency code is opaque to us — whatever WC says, we echo.
		$fixture                         = $this->simple_product_fixture();
		$fixture['prices']['currency_code'] = 'GBP';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertEquals( 'GBP', $result['price_range']['min']['currency'] );
	}

	// ------------------------------------------------------------------
	// list_price_range — pre-discount range for strikethrough
	// ------------------------------------------------------------------

	public function test_list_price_range_omitted_when_no_sale_on_simple_product(): void {
		// No discount → regular_price == price → list_price_range
		// would be redundant with price_range. Omit to keep payload
		// tight; agents can read price_range directly.
		$fixture                             = $this->simple_product_fixture();
		$fixture['prices']['regular_price']  = '2500';
		$fixture['prices']['price']          = '2500';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'list_price_range', $result );
	}

	public function test_list_price_range_emitted_for_discounted_simple_product(): void {
		// Simple product on sale: regular_price > price. Emit
		// list_price_range as a single-point range (min == max ==
		// regular_price) so agents render "was $X, now $Y".
		$fixture                             = $this->simple_product_fixture();
		$fixture['prices']['regular_price']  = '3500';
		$fixture['prices']['price']          = '2500';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame( 3500, $result['list_price_range']['min']['amount'] );
		$this->assertSame( 3500, $result['list_price_range']['max']['amount'] );
		$this->assertSame( 'USD', $result['list_price_range']['min']['currency'] );
	}

	public function test_list_price_range_computed_from_variants_when_mixed_sale(): void {
		// Variable product where some variants are on sale and
		// others aren't. list_price_range spans the regular_price
		// min/max across ALL variants (including non-sale ones
		// whose regular_price == price). A shopper sees "was X-Y,
		// now A-B" where A-B is tighter than or equal to X-Y.
		$product    = [
			'id'    => 789,
			'name'  => 'T-Shirt',
			'type'  => 'variable',
			'prices' => [
				'price'         => '1000',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '1500' ],
			],
		];
		$variations = [
			[
				'id'     => 101,
				'prices' => [
					'price'         => '1000',
					'regular_price' => '2000', // on sale
					'currency_code' => 'USD',
				],
			],
			[
				'id'     => 102,
				'prices' => [
					'price'         => '1500',
					'regular_price' => '1500', // not on sale
					'currency_code' => 'USD',
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $product, $variations );

		$this->assertArrayHasKey( 'list_price_range', $result );
		$this->assertSame( 1500, $result['list_price_range']['min']['amount'] );
		$this->assertSame( 2000, $result['list_price_range']['max']['amount'] );
	}

	public function test_list_price_range_emitted_when_only_max_differs(): void {
		// Asymmetric-bounds case: cheapest variant not on sale,
		// most expensive on sale. The two range bounds have different
		// sale statuses, and list_price_range should emit with the
		// pre-discount max to let agents render the strikethrough on
		// the top bound correctly.
		//
		// Under the current per-variant emission rule (a variant with
		// `regular > price` triggers emission), this case emits
		// because the max-priced variant is on sale. The fixture
		// exercises the path where the cheapest end of the range
		// coincides with its regular price — important because any
		// emission rule that somehow collapsed identical bounds
		// (e.g. a future refactor that re-introduces range-equality
		// short-circuits) would silently drop this case.
		//
		// Fixture: Active range: 1000-1500 (discounted max). List
		// range: 1000-2000 (pre-discount max). Min matches between
		// ranges, max differs → emit.
		$product    = [
			'id'    => 789,
			'name'  => 'T-Shirt',
			'type'  => 'variable',
			'prices' => [
				'price'         => '1000',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '1500' ],
			],
		];
		$variations = [
			[
				'id'     => 101,
				'prices' => [
					'price'         => '1000',
					'regular_price' => '1000', // not on sale
					'currency_code' => 'USD',
				],
			],
			[
				'id'     => 102,
				'prices' => [
					'price'         => '1500',
					'regular_price' => '2000', // on sale (20% off)
					'currency_code' => 'USD',
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $product, $variations );

		$this->assertArrayHasKey( 'list_price_range', $result );
		$this->assertSame( 1000, $result['list_price_range']['min']['amount'] );
		$this->assertSame( 2000, $result['list_price_range']['max']['amount'] );
	}

	public function test_list_price_range_omitted_when_variable_product_no_sales(): void {
		// All variants have regular_price == price → no discount
		// anywhere → omit list_price_range as redundant with
		// price_range. Same redundancy check as the simple-product
		// path but exercised via the variation-walk code path.
		$product    = [
			'id'    => 789,
			'name'  => 'T-Shirt',
			'type'  => 'variable',
			'prices' => [
				'price'         => '1000',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '1500' ],
			],
		];
		$variations = [
			[
				'id'     => 101,
				'prices' => [
					'price'         => '1000',
					'regular_price' => '1000',
					'currency_code' => 'USD',
				],
			],
			[
				'id'     => 102,
				'prices' => [
					'price'         => '1500',
					'regular_price' => '1500',
					'currency_code' => 'USD',
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $product, $variations );

		$this->assertArrayNotHasKey( 'list_price_range', $result );
	}

	public function test_list_price_range_omitted_when_source_lacks_regular_price(): void {
		// Defensive: Store API should always emit regular_price, but
		// if it's missing we can't derive a list_price_range.
		// Return null rather than fabricate a range from zero.
		$fixture = $this->simple_product_fixture();
		unset( $fixture['prices']['regular_price'] );

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'list_price_range', $result );
	}

	public function test_list_price_range_emitted_when_only_mid_priced_variant_is_discounted(): void {
		// Critical-case regression (flagged by Copilot review on PR #48):
		// when a discounted variant is neither the cheapest nor the
		// most expensive, the overall min/max regular-price range
		// equals the active min/max exactly. A min-max-equality check
		// would silently omit list_price_range even though a sale IS
		// happening. The current rule (per-variant `regular > price`)
		// detects the discount directly and emits the range.
		//
		// Fixture: variant B is the sale — mid-priced, $15 was $18.
		// Cheapest (A, $10) and most expensive (C, $20) are at their
		// regular prices. Active price_range = $10-$20 (from prices.price).
		// List price_range = $10-$20 (same numbers, different variants).
		// The ranges coincide numerically but a sale exists — emit.
		$product    = [
			'id'    => 790,
			'name'  => 'T-Shirt',
			'type'  => 'variable',
			'prices' => [
				'price'         => '1000',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '2000' ],
			],
		];
		$variations = [
			[
				'id'     => 201,
				'prices' => [
					'price'         => '1000',
					'regular_price' => '1000', // not on sale
					'currency_code' => 'USD',
				],
			],
			[
				'id'     => 202,
				'prices' => [
					'price'         => '1500',
					'regular_price' => '1800', // on sale (mid-priced!)
					'currency_code' => 'USD',
				],
			],
			[
				'id'     => 203,
				'prices' => [
					'price'         => '2000',
					'regular_price' => '2000', // not on sale
					'currency_code' => 'USD',
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $product, $variations );

		$this->assertArrayHasKey( 'list_price_range', $result );
		// Range derived from ALL regular prices: {1000, 1800, 2000} → 1000-2000.
		// Same numeric endpoints as the active range, but emission is
		// still correct because the mid-point discount exists.
		$this->assertSame( 1000, $result['list_price_range']['min']['amount'] );
		$this->assertSame( 2000, $result['list_price_range']['max']['amount'] );
	}

	public function test_list_price_range_omitted_when_variation_set_is_partial(): void {
		// Partial-variation guard (flagged by Copilot review on PR #48):
		// the controller may cap or skip variations
		// (MAX_VARIATIONS_PER_PRODUCT, individual fetch failures) and
		// emit a `partial_variants` warning. In that state our range
		// would be derived from incomplete data — misleading. Omit
		// entirely; the warning already tells agents variant data is
		// partial, and dropping list_price_range alongside is the
		// honest posture.
		//
		// Fixture: parent declares 3 variations; we receive 2. Even
		// though one of the provided variants is on sale, we omit
		// because the unseen variant might carry a different
		// regular-price range.
		$product    = [
			'id'         => 791,
			'name'       => 'T-Shirt',
			'type'       => 'variable',
			'prices'     => [
				'price'         => '1000',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '1000', 'max_amount' => '2500' ],
			],
			'variations' => [
				[ 'id' => 301, 'attributes' => [] ],
				[ 'id' => 302, 'attributes' => [] ],
				[ 'id' => 303, 'attributes' => [] ], // unfetched
			],
		];
		$variations = [
			[
				'id'     => 301,
				'prices' => [
					'price'         => '1000',
					'regular_price' => '1200', // on sale
					'currency_code' => 'USD',
				],
			],
			[
				'id'     => 302,
				'prices' => [
					'price'         => '1500',
					'regular_price' => '1500',
					'currency_code' => 'USD',
				],
			],
			// 303 unfetched
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $product, $variations );

		$this->assertArrayNotHasKey( 'list_price_range', $result );
	}

	// ------------------------------------------------------------------
	// Optional fields — emit only when present
	// ------------------------------------------------------------------

	public function test_handle_from_slug_when_present(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'widget', $result['handle'] );
	}

	public function test_handle_omitted_when_slug_absent(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['slug'] );

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'handle', $result );
	}

	public function test_url_from_permalink(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals(
			'https://example.com/product/widget/',
			$result['url']
		);
	}

	// ------------------------------------------------------------------
	// URL — the translator always returns the bare permalink.
	//
	// UTM attribution stamping was moved to the REST controller (after
	// translate() returns) to preserve the translator's pure-function
	// contract. The translator never calls WP API functions, so its
	// output is fully determined by its inputs alone.
	//
	// The controller stamps UTM via
	// `WC_AI_Storefront_Attribution::with_woo_ucp_utm()` after calling
	// translate(). That stamping is tested at the controller level.
	// Here we simply lock in that the translator always returns the
	// bare permalink, regardless of what context surrounds the call.
	// ------------------------------------------------------------------

	public function test_url_always_bare_no_utm_stamped_by_translator(): void {
		// Translator must return the bare permalink — no UTM params.
		// UTM stamping is the controller's job (see issue #176).
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[],
			null
		);

		$this->assertEquals(
			'https://example.com/product/widget/',
			$result['url']
		);
		$this->assertStringNotContainsString( 'utm_', $result['url'] );
	}

	public function test_url_bare_permalink_preserved_verbatim_with_existing_query_params(): void {
		// A permalink that already carries query params (e.g. lang=fr
		// from Polylang/WPML) must be forwarded verbatim. The controller
		// is responsible for appending UTM params; the translator must
		// not modify the URL shape.
		$fixture              = $this->simple_product_fixture();
		$fixture['permalink'] = 'https://example.com/product/widget/?lang=fr';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture,
			[],
			null
		);

		$this->assertEquals( 'https://example.com/product/widget/?lang=fr', $result['url'] );
		$this->assertStringNotContainsString( 'utm_', $result['url'] );
	}

	// ------------------------------------------------------------------
	// Categories
	// ------------------------------------------------------------------

	public function test_categories_tagged_with_merchant_taxonomy(): void {
		// WC categories are business-defined; UCP expects taxonomy
		// tagging so agents can distinguish "this is the merchant's
		// own categorization" from standardized taxonomies like
		// google_product_category.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertCount( 2, $result['categories'] );
		$this->assertEquals(
			[ 'value' => 'Widgets', 'taxonomy' => 'merchant' ],
			$result['categories'][0]
		);
		$this->assertEquals(
			[ 'value' => 'Gadgets', 'taxonomy' => 'merchant' ],
			$result['categories'][1]
		);
	}

	public function test_categories_omitted_when_product_uncategorized(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['categories'] );

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'categories', $result );
	}

	public function test_categories_skip_entries_without_name(): void {
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 5, 'name' => 'Widgets' ],
			[ 'id' => 6 ],  // malformed — no name
			[ 'id' => 7, 'name' => 'Other' ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertCount( 2, $result['categories'] );
	}

	// ------------------------------------------------------------------
	// Media
	// ------------------------------------------------------------------

	public function test_media_from_images_array(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals(
			[
				'type'     => 'image',
				'url'      => 'https://example.com/widget.jpg',
				'alt_text' => 'A widget',
			],
			$result['media'][0]
		);
	}

	public function test_media_omits_alt_text_when_absent(): void {
		$fixture           = $this->simple_product_fixture();
		$fixture['images'] = [ [ 'src' => 'https://example.com/img.jpg' ] ];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'alt_text', $result['media'][0] );
	}

	public function test_media_omitted_when_product_has_no_images(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['images'] );

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'media', $result );
	}

	public function test_media_skips_image_entries_without_src(): void {
		$fixture           = $this->simple_product_fixture();
		$fixture['images'] = [
			[ 'src' => 'https://example.com/a.jpg' ],
			[],  // malformed
			[ 'src' => 'https://example.com/b.jpg' ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertCount( 2, $result['media'] );
	}

	// ------------------------------------------------------------------
	// Variable product variant expansion
	// ------------------------------------------------------------------

	public function test_variable_product_emits_one_variant_per_fetched_variation(): void {
		// The caller (REST controller's search/lookup handlers) is
		// responsible for pre-fetching each variation. When it does,
		// the translator emits one UCP variant per entry — no more
		// synthesized single-variant fallback.
		$fixture = $this->variable_product_with_variations_fixture();

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture['product'],
			$fixture['variations']
		);

		$this->assertCount( 2, $result['variants'] );
	}

	public function test_variable_product_variants_have_real_variation_ids_not_default_suffix(): void {
		// The `_default` suffix is reserved for synthesized placeholder
		// variants on simple products. Real WC variations must produce
		// `var_{variation_id}` exactly — without the suffix — so agents
		// can distinguish "this is a real variation you can buy" from
		// "this is a stand-in for a simple product".
		$fixture = $this->variable_product_with_variations_fixture();

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture['product'],
			$fixture['variations']
		);

		$this->assertEquals( 'var_101', $result['variants'][0]['id'] );
		$this->assertEquals( 'var_102', $result['variants'][1]['id'] );
	}

	public function test_variable_product_variants_carry_variation_specific_prices(): void {
		// Each variant's price reflects its own variation's price, not
		// the parent product's. A $10 Small and $15 Large must emit
		// distinct prices — if we accidentally forwarded the parent's
		// `prices.price` onto every variant, the variants would all
		// share the parent's price (or, worse, the min of the range).
		$fixture = $this->variable_product_with_variations_fixture();

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture['product'],
			$fixture['variations']
		);

		$this->assertSame( 1000, $result['variants'][0]['price']['amount'] );
		$this->assertSame( 1500, $result['variants'][1]['price']['amount'] );
	}

	public function test_variable_product_variants_build_title_from_attributes(): void {
		// Locks in that we dispatch through UcpVariantTranslator::translate(),
		// not synthesize_default() — translate() builds titles from
		// attribute values ("Small", "Large"); synthesize_default() would
		// use the parent product name ("T-Shirt") for every variant.
		$fixture = $this->variable_product_with_variations_fixture();

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture['product'],
			$fixture['variations']
		);

		$this->assertEquals( 'Small', $result['variants'][0]['title'] );
		$this->assertEquals( 'Large', $result['variants'][1]['title'] );
	}

	public function test_variable_product_price_range_preserved_when_variations_supplied(): void {
		// The price_range field is computed from the parent product's
		// `prices.price_range`, independently of the variations. Passing
		// variations should not change price_range output — that's the
		// translator contract.
		$fixture = $this->variable_product_with_variations_fixture();

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture['product'],
			$fixture['variations']
		);

		$this->assertSame( 1000, $result['price_range']['min']['amount'] );
		$this->assertSame( 1500, $result['price_range']['max']['amount'] );
	}

	public function test_empty_variations_argument_falls_back_to_synthesized_default(): void {
		// Backward-compatible signature: existing callers that pass only
		// `$wc_product` (or pass `[]` explicitly) still get a minItems:1-
		// compliant variants array. The synthesized default's `_default`
		// suffix makes the fallback self-documenting.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$this->assertCount( 1, $result['variants'] );
		$this->assertEquals( 'var_123_default', $result['variants'][0]['id'] );
	}

	public function test_parent_attribute_names_flow_to_variant_translator(): void {
		// Issue #347: real-world variations from WC Store API leave
		// `attributes[]` empty and put the active option set in a
		// `variation` formatted string. The product translator must
		// extract the parent's attribute names (so the variant
		// translator can disambiguate comma-in-value cases) and pass
		// them through. This integration test verifies the wiring end-
		// to-end: parent has Color+Size, variations carry only the
		// formatted string, and emitted variants pick up correct
		// titles + options.
		$product = [
			'id'         => 999,
			'name'       => 'Leather Shoes',
			'type'       => 'variable',
			'prices'     => [
				'price'               => '15000',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
				'price_range'         => [
					'min_amount' => '15000',
					'max_amount' => '15000',
				],
			],
			'attributes' => [
				[
					'name'           => 'Color',
					'has_variations' => true,
					'terms'          => [ [ 'name' => 'Tan' ], [ 'name' => 'Black' ] ],
				],
				[
					'name'           => 'Size',
					'has_variations' => true,
					'terms'          => [ [ 'name' => '8' ], [ 'name' => '9' ] ],
				],
			],
			'variations' => [
				[ 'id' => 1001 ],
				[ 'id' => 1002 ],
			],
		];

		$variations = [
			[
				'id'                => 1001,
				'name'              => 'Leather Shoes',
				'sku'               => 'SHOE-TAN-9',
				'is_in_stock'       => true,
				'short_description' => '',
				'prices'            => [
					'price'               => '15000',
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				],
				// The empty-array shape WC Store API actually returns.
				'attributes'        => [],
				'variation'         => 'Color: Tan, Size: 9',
			],
			[
				'id'                => 1002,
				'name'              => 'Leather Shoes',
				'sku'               => 'SHOE-BLACK-8',
				'is_in_stock'       => true,
				'short_description' => '',
				'prices'            => [
					'price'               => '15000',
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				],
				'attributes'        => [],
				'variation'         => 'Color: Black, Size: 8',
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $product, $variations );

		$this->assertSame( 'Tan / 9', $result['variants'][0]['title'] );
		$this->assertSame( 'Black / 8', $result['variants'][1]['title'] );
		$this->assertSame(
			[
				[ 'name' => 'Color', 'label' => 'Tan' ],
				[ 'name' => 'Size', 'label' => '9' ],
			],
			$result['variants'][0]['options']
		);
		$this->assertSame(
			[
				[ 'name' => 'Color', 'label' => 'Black' ],
				[ 'name' => 'Size', 'label' => '8' ],
			],
			$result['variants'][1]['options']
		);
	}

	// ------------------------------------------------------------------
	// 1.8.0: description.html, tags, product attributes, ratings
	// ------------------------------------------------------------------

	public function test_translate_emits_description_html_when_source_has_markup(): void {
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = '<ul><li>Waterproof</li><li>Light</li></ul>';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayHasKey( 'html', $result['description'] );
		$this->assertSame( '<ul><li>Waterproof</li><li>Light</li></ul>', $result['description']['html'] );
		$this->assertSame( 'WaterproofLight', $result['description']['plain'] );
	}

	public function test_translate_omits_description_html_when_plain(): void {
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = 'Just plain text';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'html', $result['description'] );
		$this->assertSame( 'Just plain text', $result['description']['plain'] );
	}

	public function test_translate_omits_description_html_when_source_has_trailing_whitespace(): void {
		// Regression: wp_strip_all_tags() trims surrounding whitespace
		// as a side effect, so comparing the stripped form to the raw
		// form would false-positive on plain text with trailing
		// newlines/spaces — treating a whitespace difference as
		// "source had markup" and emitting a redundant `html` field.
		// The fix compares against `trim( $raw )` so whitespace-only
		// differences don't trigger html emission.
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = "Just plain text\n\n";

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'html', $result['description'] );
		$this->assertSame( 'Just plain text', $result['description']['plain'] );
	}

	public function test_translate_omits_description_html_when_source_has_entities_but_no_tags(): void {
		// Regression: entity-decoding was being used as the "has markup"
		// detector, so `"Fish &amp; Chips"` decoded to `"Fish & Chips"`
		// and false-positive'd into emitting `html`. HTML emission
		// should be about preserving structural markup (tags), not
		// entity glyphs — the decoded plain form conveys those
		// losslessly.
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = 'Fish &amp; Chips';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'html', $result['description'] );
		$this->assertSame( 'Fish & Chips', $result['description']['plain'] );
	}

	public function test_translate_emits_tags_as_top_level_string_array(): void {
		// 2.0.0+: tags moved out of `categories[{taxonomy:"tag"}]` into
		// their own top-level `tags[]` array of plain strings — matching
		// UCP core product shape. Categories and brands stay in
		// `categories[]` with the taxonomy discriminator.
		$fixture         = $this->simple_product_fixture();
		$fixture['tags'] = [
			[ 'id' => 5, 'name' => 'summer', 'slug' => 'summer' ],
			[ 'id' => 6, 'name' => 'eco-friendly', 'slug' => 'eco-friendly' ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame(
			[ 'summer', 'eco-friendly' ],
			$result['tags']
		);

		// Categories block must NOT leak a `taxonomy:tag` entry anymore —
		// regression guard for the split.
		foreach ( $result['categories'] ?? [] as $cat ) {
			$this->assertNotSame( 'tag', $cat['taxonomy'] ?? null );
		}
	}

	public function test_translate_omits_tags_key_when_source_has_none(): void {
		// No WC tags seeded → `tags` key absent entirely (not empty array).
		// Spec treats missing and empty-array as semantically equivalent,
		// but omission is cleaner for downstream serializers.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$this->assertArrayNotHasKey( 'tags', $result );
	}

	public function test_translate_emits_brands_as_third_taxonomy_in_categories(): void {
		// WC 9.5+ `product_brand` taxonomy (and the earlier "WooCommerce
		// Brands" plugin) surfaces via Store API under `brands[]`. Shape
		// mirrors categories/tags — emit with `taxonomy: "brand"`.
		$fixture           = $this->simple_product_fixture();
		$fixture['brands'] = [
			[ 'id' => 88, 'name' => 'ACME', 'slug' => 'acme' ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertContains(
			[ 'value' => 'ACME', 'taxonomy' => 'brand' ],
			$result['categories']
		);
	}

	public function test_translate_handles_categories_tags_and_brands_simultaneously(): void {
		// Compositional test for the 2.0.0 split: a single product
		// carrying WC categories + tags + brands all at once exercises
		// the full classification path in one shot. Single-axis tests
		// (category-only / tag-only / brand-only) each pass today,
		// but a refactor could silently regress the classifier for one
		// axis while keeping the others green. This locks the
		// three-way interaction.
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 10, 'name' => 'Apparel', 'slug' => 'apparel' ],
		];
		$fixture['tags']       = [
			[ 'id' => 20, 'name' => 'summer', 'slug' => 'summer' ],
			[ 'id' => 21, 'name' => 'eco-friendly', 'slug' => 'eco-friendly' ],
		];
		$fixture['brands']     = [
			[ 'id' => 30, 'name' => 'Acme', 'slug' => 'acme' ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		// `categories[]` carries ONLY merchant + brand entries —
		// never a `taxonomy:"tag"` leak.
		$this->assertCount( 2, $result['categories'] );
		$taxonomies = array_column( $result['categories'], 'taxonomy' );
		$this->assertContains( 'merchant', $taxonomies );
		$this->assertContains( 'brand', $taxonomies );
		$this->assertNotContains( 'tag', $taxonomies );

		// `tags[]` carries plain strings of tag names only.
		$this->assertSame( [ 'summer', 'eco-friendly' ], $result['tags'] );

		// No 1.x `attributes`-style leak anywhere on the product shape.
		$this->assertArrayNotHasKey( 'attributes', $result );
	}

	public function test_translate_omits_brands_when_source_has_none(): void {
		// Merchants without Brands registered pay zero payload — no
		// empty `brand` taxonomy entries should appear.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$brand_entries = array_filter(
			$result['categories'] ?? [],
			static fn( array $entry ): bool => 'brand' === ( $entry['taxonomy'] ?? '' )
		);
		$this->assertEmpty( $brand_entries );
	}

	public function test_translate_splits_attributes_into_options_and_metadata(): void {
		// 2.0.0+: WC attributes split into two UCP buckets based on
		// `has_variations`.
		//   - Variation axes (`has_variations: true`) → `product.options[]`
		//   - Informational (`has_variations: false`) → `product.metadata.attributes`
		// Pre-2.0 these all collapsed into `product.attributes[]` with
		// no distinction; splitting matches UCP core shape and lets
		// agents distinguish "selectable dimension" from "product fact".
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Material',
				'taxonomy'       => 'pa_material',
				'has_variations' => false,
				'terms'          => [
					[ 'id' => 10, 'name' => 'Cotton', 'slug' => 'cotton' ],
					[ 'id' => 11, 'name' => 'Organic', 'slug' => 'organic' ],
				],
			],
			[
				// Variation-defining attribute — lands in `options[]`.
				'name'           => 'Size',
				'taxonomy'       => 'pa_size',
				'has_variations' => true,
				'terms'          => [
					[ 'id' => 20, 'name' => 'S', 'slug' => 's' ],
					[ 'id' => 21, 'name' => 'M', 'slug' => 'm' ],
					[ 'id' => 22, 'name' => 'L', 'slug' => 'l' ],
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		// Variation axis landed in options[] with `option_value` shape
		// items (UCP 2026-04-08: required `label`, optional `id`).
		// `id` is emitted from `<taxonomy>:<slug>` for taxonomy-backed
		// (pa_*) attributes — issue #350.
		$this->assertArrayHasKey( 'options', $result );
		$this->assertCount( 1, $result['options'] );
		$this->assertSame( 'Size', $result['options'][0]['name'] );
		$this->assertSame(
			[
				[ 'label' => 'S', 'id' => 'pa_size:s' ],
				[ 'label' => 'M', 'id' => 'pa_size:m' ],
				[ 'label' => 'L', 'id' => 'pa_size:l' ],
			],
			$result['options'][0]['values']
		);

		// Informational attribute landed in metadata.attributes with
		// the same shape (mirrors the option_value structure for
		// internal consistency). Same `id` enrichment applies for
		// taxonomy-backed informational attributes.
		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertArrayHasKey( 'attributes', $result['metadata'] );
		$this->assertCount( 1, $result['metadata']['attributes'] );
		$this->assertSame( 'Material', $result['metadata']['attributes'][0]['name'] );
		$this->assertSame(
			[
				[ 'label' => 'Cotton', 'id' => 'pa_material:cotton' ],
				[ 'label' => 'Organic', 'id' => 'pa_material:organic' ],
			],
			$result['metadata']['attributes'][0]['values']
		);

		// Regression guard for the 1.x flat shape — must not reappear.
		$this->assertArrayNotHasKey( 'attributes', $result );
	}

	public function test_translate_omits_options_and_metadata_when_source_has_no_attributes(): void {
		// No WC attributes at all → no `options` key, and no
		// `metadata.attributes` sub-block (no empty scaffolding emitted).
		// `metadata` itself IS present post-#374 because
		// `metadata.lifecycle.status` always emits; the assertion is
		// scoped to the attributes sub-block.
		$fixture = $this->simple_product_fixture();
		unset( $fixture['attributes'] );

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'options', $result );
		$this->assertArrayNotHasKey( 'attributes', $result['metadata'] ?? [] );
	}

	public function test_simple_product_reserved_attribute_demoted_to_metadata(): void {
		// Simple product with a schema.org reserved variant attribute (Color)
		// emits the attribute under metadata.attributes — NOT under options[].
		// A simple WC product has no `has_variations: true` axis, so there's
		// no buyer-selectable variation axis — Color is descriptive, not
		// selectable. Reverts the #356 promotion.
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Color',
				'has_variations' => false,
				'terms'          => [ [ 'id' => 1, 'name' => 'White' ] ],
			],
			[
				'name'           => 'Fabric Weight',
				'has_variations' => false,
				'terms'          => [ [ 'id' => 2, 'name' => '180gsm' ] ],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		// No options[] — non-variable product has no selectable axes.
		$this->assertArrayNotHasKey( 'options', $result );

		// Both attributes (reserved Color + non-reserved Fabric Weight) live
		// in metadata.attributes — uniform treatment, no special-case for
		// the four schema.org reserved names.
		$this->assertArrayHasKey( 'metadata', $result );
		$attr_names = array_column( $result['metadata']['attributes'], 'name' );
		$this->assertContains( 'Color', $attr_names );
		$this->assertContains( 'Fabric Weight', $attr_names );
		$this->assertCount( 2, $result['metadata']['attributes'] );
	}

	public function test_simple_product_non_reserved_attribute_in_metadata(): void {
		// Sanity: simple product with only a non-reserved attribute emits
		// the attribute in metadata.attributes (unchanged from prior
		// behavior — the demote-uniform rule preserves this path).
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Origin',
				'has_variations' => false,
				'terms'          => [ [ 'id' => 10, 'name' => 'Italy' ] ],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'options', $result );
		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertSame( 'Origin', $result['metadata']['attributes'][0]['name'] );
	}

	public function test_simple_product_reserved_names_get_no_special_treatment(): void {
		// Pre-revert (#356), the four schema.org reserved names — Color,
		// Size, Pattern, Material — were special-cased: any non-variable
		// product carrying any of them (in any case) was promoted to
		// product-group shape with options[]. After the revert, there's
		// no reserved-name matching logic at all; reserved-named
		// attributes route through the same path as Origin / Fabric
		// Weight / etc., gated only by `has_variations: false`. This
		// test mixes case (COLOR / Size / Pattern / material) to confirm
		// the case-sensitivity question is moot — there's no name-based
		// branch left to be sensitive about.
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[ 'name' => 'COLOR',   'has_variations' => false, 'terms' => [ [ 'id' => 1, 'name' => 'White' ] ] ],
			[ 'name' => 'Size',    'has_variations' => false, 'terms' => [ [ 'id' => 2, 'name' => 'M' ] ] ],
			[ 'name' => 'Pattern', 'has_variations' => false, 'terms' => [ [ 'id' => 3, 'name' => 'Solid' ] ] ],
			[ 'name' => 'material','has_variations' => false, 'terms' => [ [ 'id' => 4, 'name' => 'Cotton' ] ] ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'options', $result );
		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertCount( 4, $result['metadata']['attributes'] );
	}

	public function test_synthesized_default_variant_omits_options(): void {
		// Synthesized default variant on a simple product carries no
		// `options[]` (the array of `selected_option`-shaped entries
		// that locks in a buyer's variant pick) — there's no buyer
		// selection to lock in. UCP `product_option.json` reserves
		// options for selectable axes. The variant's purpose is
		// satisfying UCP's `variants[] minItems:1` requirement; it's
		// not advertising a specific concrete combination because no
		// combination was chosen.
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Color',
				'taxonomy'       => 'pa_color',
				'has_variations' => false,
				'terms'          => [ [ 'id' => 1, 'name' => 'White', 'slug' => 'white' ] ],
			],
			[
				'name'           => 'Size',
				'has_variations' => false,
				'terms'          => [ [ 'id' => 0, 'name' => 'L', 'slug' => 'L' ] ],
			],
		];

		$result  = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );
		$variant = $result['variants'][0];

		$this->assertArrayNotHasKey( 'options', $variant );
	}

	public function test_simple_product_multi_value_reserved_attribute_emits_all_values_in_metadata(): void {
		// Regression for the prod_24 production bug: multi-value reserved
		// attributes (Color=[Beige, Blue, Gray], Size=[XS, S, M, L, XL, XXL])
		// on a simple product previously emitted ONLY the first value to
		// options[].values[] — silently hiding the other values from agents.
		// After the demote-uniform rule, all values are emitted under
		// metadata.attributes (no truncation possible because there's no
		// promote path).
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Color',
				'taxonomy'       => 'pa_color',
				'has_variations' => false,
				'terms'          => [
					[ 'id' => 1, 'name' => 'Beige', 'slug' => 'beige' ],
					[ 'id' => 2, 'name' => 'Blue',  'slug' => 'blue' ],
					[ 'id' => 3, 'name' => 'Gray',  'slug' => 'gray' ],
				],
			],
			[
				'name'           => 'Size',
				'has_variations' => false,
				'terms'          => [
					[ 'id' => 0, 'name' => 'XS',  'slug' => 'XS' ],
					[ 'id' => 0, 'name' => 'S',   'slug' => 'S' ],
					[ 'id' => 0, 'name' => 'M',   'slug' => 'M' ],
					[ 'id' => 0, 'name' => 'L',   'slug' => 'L' ],
					[ 'id' => 0, 'name' => 'XL',  'slug' => 'XL' ],
					[ 'id' => 0, 'name' => 'XXL', 'slug' => 'XXL' ],
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'options', $result );
		$this->assertArrayHasKey( 'metadata', $result );

		$attrs_by_name = [];
		foreach ( $result['metadata']['attributes'] as $attr ) {
			$attrs_by_name[ $attr['name'] ] = $attr;
		}

		// All three colors emitted, not just the first.
		$color_labels = array_column( $attrs_by_name['Color']['values'], 'label' );
		$this->assertEqualsCanonicalizing( [ 'Beige', 'Blue', 'Gray' ], $color_labels );

		// All six sizes emitted, not just XS.
		$size_labels = array_column( $attrs_by_name['Size']['values'], 'label' );
		$this->assertEqualsCanonicalizing( [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ], $size_labels );

		// pa_* taxonomy IDs survive the demote path. Color uses real
		// `pa_color` taxonomy with slugs, so each value should carry a
		// `<taxonomy>:<slug>` id — the same enrichment shape that
		// `options[]` emits for variable products. Pins that demoted
		// reserved attributes don't lose taxonomy-id provenance.
		$beige = null;
		foreach ( $attrs_by_name['Color']['values'] as $value ) {
			if ( 'Beige' === ( $value['label'] ?? '' ) ) {
				$beige = $value;
				break;
			}
		}
		$this->assertNotNull( $beige );
		$this->assertSame( 'pa_color:beige', $beige['id'] );

		// Synthesized variant emits no `options[]` (no
		// `selected_option`-shaped entries).
		$this->assertArrayNotHasKey( 'options', $result['variants'][0] );
	}

	public function test_synthesize_default_omits_options_for_variable_product_safety_net(): void {
		// `extract_variants()` falls back to `synthesize_default()` when a
		// variable product is translated without pre-fetched variations
		// (the safety-net path documented in extract_variants()'s
		// docblock). The "no `options[]` on synthesized default"
		// rule must hold on this path too — a variable product has
		// selectable axes (its `options[]` is non-empty), but the
		// synthesized fallback variant doesn't represent any one
		// concrete combination, so it shouldn't claim one.
		$fixture = [
			'id'                => 999,
			'name'              => 'Variable T-Shirt',
			'slug'              => 'variable-t-shirt',
			'permalink'         => 'https://example.com/product/variable-t-shirt/',
			'short_description' => '',
			'is_in_stock'       => true,
			'prices'            => [
				'price'         => '2000',
				'currency_code' => 'USD',
			],
			'attributes'        => [
				[
					'name'           => 'Color',
					'taxonomy'       => 'pa_color',
					'has_variations' => true,
					'terms'          => [
						[ 'id' => 1, 'name' => 'Black', 'slug' => 'black' ],
						[ 'id' => 2, 'name' => 'White', 'slug' => 'white' ],
					],
				],
			],
		];

		// Translate with empty $wc_variations (no pre-fetched variations
		// supplied) — caller pretends the pre-fetch step was skipped.
		// extract_variants() should fall back to synthesize_default().
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		// `options[]` is still emitted at the product level (variable
		// product, has_variations:true axis is real) — so a future
		// caller that does pre-fetch variations would see them.
		$this->assertArrayHasKey( 'options', $result );

		// But the synthesized fallback variant carries no `options[]`
		// (no `selected_option`-shaped entries) — there's nothing
		// concrete to lock in for this fallback variant.
		$this->assertCount( 1, $result['variants'] );
		$this->assertStringEndsWith( '_default', $result['variants'][0]['id'] );
		$this->assertArrayNotHasKey( 'options', $result['variants'][0] );
	}

	public function test_translate_omits_metadata_attributes_when_only_variation_axes(): void {
		// Variable product with only has_variations:true attributes —
		// `options[]` present, `metadata.attributes` absent.
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Color',
				'has_variations' => true,
				'terms'          => [ [ 'id' => 30, 'name' => 'Red' ] ],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayHasKey( 'options', $result );
		$this->assertArrayNotHasKey( 'attributes', $result['metadata'] ?? [] );
	}

	public function test_translate_decodes_html_entities_in_title_and_term_labels(): void {
		// The WC Store API returns name fields with HTML entities intact.
		// Product title, attribute axis name, and term label must all be
		// decoded to plain Unicode before emission.
		$fixture         = $this->simple_product_fixture();
		$fixture['name'] = 'Shirt &#8211; Green';
		$fixture['attributes'] = [
			[
				'name'           => 'Coul&#233;e',
				'taxonomy'       => 'pa_coulee',
				'has_variations' => true,
				'terms'          => [
					[ 'id' => 1, 'name' => 'Cr&#232;me', 'slug' => 'creme' ],
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame( 'Shirt – Green', $result['title'] );
		$this->assertSame( 'Coulée', $result['options'][0]['name'] );
		$this->assertSame( 'Crème', $result['options'][0]['values'][0]['label'] );
	}

	public function test_translate_strips_html_tags_that_survive_entity_decode(): void {
		// html_entity_decode() turns &lt;strong&gt; back into <strong>.
		// wp_strip_all_tags() must run after decoding so encoded markup
		// cannot reintroduce HTML elements in the UCP output.
		$fixture         = $this->simple_product_fixture();
		$fixture['name'] = '&lt;strong&gt;Sale&lt;/strong&gt; Shirt';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame( 'Sale Shirt', $result['title'] );
	}

	public function test_translate_emits_rating_under_core_when_reviews_exist(): void {
		// UCP `rating.json` shape: `{value, scale_min, scale_max, count}`.
		// 0.12.0+ aligned to spec — `value` (the average) replaces the
		// pre-0.12.0 `average` key, and `scale_min`/`scale_max` are
		// hardcoded to WC core's inflexible 1-5 star bounds.
		$fixture                   = $this->simple_product_fixture();
		$fixture['average_rating'] = '4.67';
		$fixture['review_count']   = 42;

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayHasKey( 'rating', $result );
		$this->assertSame( 4.67, $result['rating']['value'] );
		$this->assertSame( 1, $result['rating']['scale_min'] );
		$this->assertSame( 5, $result['rating']['scale_max'] );
		$this->assertSame( 42, $result['rating']['count'] );
		// Hard-cut regression guard for the 0.12.0 rename — old
		// `average` key must not reappear.
		$this->assertArrayNotHasKey( 'average', $result['rating'] );

		// Regression guard: the pre-2.0.0 extension-namespace home must
		// stay empty — agents that were reading from there need to
		// see the migration cleanly, not a double-emission.
		$this->assertArrayNotHasKey( 'extensions', $result );
	}

	public function test_translate_omits_rating_key_when_no_reviews(): void {
		// Zero reviews → omit the key entirely. Emitting `rating: 0.0`
		// for a product with no reviews would misleadingly rank it
		// alongside products with many one-star reviews.
		$fixture                   = $this->simple_product_fixture();
		$fixture['average_rating'] = '0';
		$fixture['review_count']   = 0;

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'rating', $result );
		$this->assertArrayNotHasKey( 'extensions', $result );
	}

	// ------------------------------------------------------------------
	// Spec metadata fields (PR G)
	// ------------------------------------------------------------------

	public function test_translate_always_emits_status_published_under_metadata(): void {
		// The handler upstream filters out draft/private products at
		// the Store API layer, so anything we translate is by
		// definition published. Emitting the `status` key explicitly
		// communicates that posture to agents — otherwise they'd
		// have no way to know whether missing products are drafts
		// vs. out-of-stock vs. excluded-by-permission. Lives under
		// `metadata.lifecycle.status` per UCP spec (#374): top-level
		// `status` is not in `product.json`.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$this->assertSame( 'published', $result['metadata']['lifecycle']['status'] );
		$this->assertArrayNotHasKey( 'status', $result, 'Top-level status must not be emitted post-#374.' );
	}

	public function test_translate_reads_timestamps_from_store_api_extension_namespace(): void {
		// Primary source: our Store API extension exposes the dates
		// under `extensions[com-woocommerce-ai-storefront]` as RFC
		// 3339 / ISO 8601 UTC strings. WC 9.5+ Store API strips the
		// top-level date fields; the extension is the only reliable
		// path to these values. Emitted under `metadata.timestamps`
		// post-#374.
		$fixture                 = $this->simple_product_fixture();
		$fixture['extensions']   = [
			'com-woocommerce-ai-storefront' => [
				'date_created'  => '2026-01-15T10:30:00Z',
				'date_modified' => '2026-04-20T14:22:31Z',
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame( '2026-01-15T10:30:00Z', $result['metadata']['timestamps']['published_at'] );
		$this->assertSame( '2026-04-20T14:22:31Z', $result['metadata']['timestamps']['updated_at'] );
		$this->assertArrayNotHasKey( 'published_at', $result, 'Top-level published_at must not be emitted post-#374.' );
		$this->assertArrayNotHasKey( 'updated_at', $result, 'Top-level updated_at must not be emitted post-#374.' );
	}

	public function test_translate_falls_back_to_top_level_date_fields_when_extension_absent(): void {
		// Forward-compat: if a future WC release (or a fixture-based
		// integration test) puts the dates back at the top level,
		// we still pick them up. The extension path takes precedence
		// when both are present; this test covers the extension-absent
		// case.
		$fixture                  = $this->simple_product_fixture();
		$fixture['date_created']  = '2026-01-15T10:30:00';
		$fixture['date_modified'] = '2026-04-20T14:22:31';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame( '2026-01-15T10:30:00', $result['metadata']['timestamps']['published_at'] );
		$this->assertSame( '2026-04-20T14:22:31', $result['metadata']['timestamps']['updated_at'] );
	}

	public function test_translate_prefers_extension_over_top_level_when_both_present(): void {
		// When both sources exist (unusual but possible during a
		// migration window or with a third-party filter that
		// re-adds top-level dates), the extension value wins —
		// it's the one produced by our own code and therefore
		// guaranteed to be in the UCP-expected RFC 3339 shape.
		$fixture                 = $this->simple_product_fixture();
		$fixture['date_created'] = '2020-01-01T00:00:00'; // stale / wrong
		$fixture['extensions']   = [
			'com-woocommerce-ai-storefront' => [
				'date_created' => '2026-01-15T10:30:00Z', // authoritative
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame( '2026-01-15T10:30:00Z', $result['metadata']['timestamps']['published_at'] );
	}

	public function test_translate_tolerates_non_array_extensions_without_fatal(): void {
		// A third-party plugin or a filtered Store API response could
		// write a non-array value at `extensions` or the namespace
		// entry. Without an `is_array` guard we'd fatal on array
		// indexing. Verify the translator degrades to the top-level
		// fallback path (and ultimately to omission) without error.
		// Post-#374: `metadata.timestamps` is omitted entirely when no
		// timestamps survive (rather than emitted as an empty sub-block).
		foreach ( [
			'extensions-is-string' => [ 'extensions' => 'surprise string' ],
			'extensions-is-int'    => [ 'extensions' => 42 ],
			'namespace-is-string'  => [ 'extensions' => [ 'com-woocommerce-ai-storefront' => 'nope' ] ],
			'namespace-is-object'  => [ 'extensions' => [ 'com-woocommerce-ai-storefront' => (object) [ 'foo' => 'bar' ] ] ],
		] as $label => $overlay ) {
			$fixture = array_merge( $this->simple_product_fixture(), $overlay );

			// Must not throw, and must omit timestamps entirely
			// (no top-level date_* either in this fixture).
			$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

			$this->assertArrayNotHasKey(
				'timestamps',
				$result['metadata'] ?? [],
				"Fatal-averted path failed: {$label}"
			);
		}
	}

	public function test_translate_omits_timestamps_when_source_lacks_them(): void {
		// Store API should always emit these, but the fixture-free
		// translator is pure — don't synthesize fake timestamps if
		// the input happens to lack them (e.g. a mocked response in
		// a caller's integration test). Omission is valid per spec.
		// Post-#374: `metadata.lifecycle.status` still emits (it's a
		// fixed literal not sourced from input); only `timestamps` is
		// conditional on input.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$this->assertArrayHasKey( 'lifecycle', $result['metadata'] );
		$this->assertArrayNotHasKey( 'timestamps', $result['metadata'] );
	}

	public function test_translate_stamps_seller_on_synthesized_default_variant(): void {
		// Seller is computed once per request in the REST controller
		// and threaded through. Per UCP `variant.json`, seller lives
		// on each variant — not on the product (no `product.seller`
		// field exists in the spec). Single-merchant store: every
		// variant in the response carries the same seller.
		$seller = [
			'name' => 'Example Store',
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[],
			$seller
		);

		$this->assertArrayNotHasKey( 'seller', $result );
		$this->assertCount( 1, $result['variants'] );
		$this->assertSame( $seller, $result['variants'][0]['seller'] );
	}

	public function test_translate_stamps_seller_on_every_real_variant(): void {
		// Variable products: same seller threads through to every
		// translated variation, not just the first.
		$fixture = $this->variable_product_with_variations_fixture();
		$seller  = [ 'name' => 'Example Store' ];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture['product'],
			$fixture['variations'],
			$seller
		);

		$this->assertArrayNotHasKey( 'seller', $result );
		$this->assertCount( 2, $result['variants'] );
		foreach ( $result['variants'] as $variant ) {
			$this->assertSame( $seller, $variant['seller'] );
		}
	}

	public function test_translate_omits_seller_when_not_passed(): void {
		// Backward-compat — existing callers without the $seller arg
		// keep working, just without seller emission on variants.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$this->assertArrayNotHasKey( 'seller', $result );
		$this->assertArrayNotHasKey( 'seller', $result['variants'][0] );
	}

	public function test_translate_omits_seller_when_passed_empty(): void {
		// An empty seller array behaves the same as omitting the arg.
		// Covers the edge case where the controller's build_seller()
		// returns [] (no site name set, no WC available) — we'd rather
		// skip the key than emit `seller: {}` on every variant.
		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[],
			[]
		);

		$this->assertArrayNotHasKey( 'seller', $result );
		$this->assertArrayNotHasKey( 'seller', $result['variants'][0] );
	}

	// ------------------------------------------------------------------
	// option_value.id enrichment (#350)
	// ------------------------------------------------------------------

	public function test_option_value_id_omitted_for_custom_non_taxonomy_attribute(): void {
		// Custom inline attributes have no `taxonomy` slug starting with
		// `pa_` and thus no canonical identifier. Per UCP 2026-04-08
		// `option_value.json`, `id` is optional — omit it cleanly
		// rather than emit a synthetic one.
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Custom Axis',
				'taxonomy'       => '', // no taxonomy → custom inline
				'has_variations' => true,
				'terms'          => [
					[ 'name' => 'A', 'slug' => 'a' ],
					[ 'name' => 'B', 'slug' => 'b' ],
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame( 'Custom Axis', $result['options'][0]['name'] );
		foreach ( $result['options'][0]['values'] as $value ) {
			$this->assertArrayHasKey( 'label', $value );
			$this->assertArrayNotHasKey( 'id', $value );
		}
	}

	public function test_option_value_id_omitted_when_term_slug_missing(): void {
		// Defensive: a taxonomy-backed attribute whose terms lack a
		// `slug` (corrupt data, plugin override) should still emit
		// `label` but skip `id` per term — graceful degradation.
		$fixture               = $this->simple_product_fixture();
		$fixture['attributes'] = [
			[
				'name'           => 'Color',
				'taxonomy'       => 'pa_color',
				'has_variations' => true,
				'terms'          => [
					[ 'name' => 'Black', 'slug' => 'black' ],
					[ 'name' => 'NoSlug' ], // missing slug
				],
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$values = $result['options'][0]['values'];
		$this->assertSame( 'pa_color:black', $values[0]['id'] );
		$this->assertArrayNotHasKey( 'id', $values[1] );
	}

	public function test_variant_options_id_emitted_from_term_slug_map(): void {
		// Variants get `selected_option.id` from the threaded
		// `term_slug_map` built once per product. Map shape:
		//   [axis_label => {
		//     taxonomy: 'pa_color',
		//     slugs:    [value_label => slug, ...],
		//   }]
		// The structured per-axis shape (vs. an earlier sentinel-key
		// design) eliminates collision risk with merchant-defined
		// term names.
		$wc_product = [
			'id'         => 999,
			'name'       => 'Tee',
			'type'       => 'variable',
			'prices'     => [
				'price'         => '1500',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '1500', 'max_amount' => '1500' ],
			],
			'attributes' => [
				[
					'name'           => 'Color',
					'taxonomy'       => 'pa_color',
					'has_variations' => true,
					'terms'          => [
						[ 'name' => 'Black', 'slug' => 'black' ],
						[ 'name' => 'Green', 'slug' => 'green' ],
					],
				],
			],
			'variations' => [ [ 'id' => 991 ] ],
		];

		$wc_variations = [
			[
				'id'                => 991,
				'name'              => 'Tee',
				'is_in_stock'       => true,
				'short_description' => '',
				'prices'            => [
					'price'         => '1500',
					'currency_code' => 'USD',
				],
				// Live WC variations carry empty `attributes[]` plus a
				// formatted `variation` string (post-#347 path).
				'attributes'        => [],
				'variation'         => 'Color: Black',
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $wc_product, $wc_variations );

		$variant = $result['variants'][0];
		$this->assertSame(
			[
				[ 'name' => 'Color', 'label' => 'Black', 'id' => 'pa_color:black' ],
			],
			$variant['options']
		);
	}

	// ------------------------------------------------------------------
	// Hierarchical category strings (#350)
	// ------------------------------------------------------------------

	public function test_category_value_uses_hierarchy_path_when_supplied(): void {
		// Per UCP `category.json` (release/2026-04-08), hierarchy is
		// encoded as a `>`-delimited string in the `value` field. When
		// the controller pre-builds a path map (e.g.
		// `[42 => "Clothing > Tshirts"]`), the translator emits the
		// path string instead of the bare leaf name.
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 42, 'name' => 'Tshirts', 'slug' => 'tshirts', 'parent' => 41 ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture,
			[],
			null,
			[ 42 => 'Clothing > Tshirts' ]
		);

		$this->assertSame(
			[ [ 'value' => 'Clothing > Tshirts', 'taxonomy' => 'merchant' ] ],
			$result['categories']
		);
	}

	public function test_category_value_falls_back_to_bare_name_when_path_missing(): void {
		// Graceful degradation: when the path map doesn't have an entry
		// for a category id (controller couldn't fetch its parents,
		// dispatch failed, etc.), emit the bare leaf name.
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 99, 'name' => 'OrphanCat', 'slug' => 'orphancat', 'parent' => 0 ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture,
			[],
			null,
			[ /* no entry for 99 */ ]
		);

		$this->assertSame(
			[ [ 'value' => 'OrphanCat', 'taxonomy' => 'merchant' ] ],
			$result['categories']
		);
	}

	public function test_category_value_unchanged_when_path_map_omitted(): void {
		// Backwards-compat: when no $category_paths is passed (legacy
		// callers), emit the bare leaf name as before. Strict shape
		// preserved.
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 5, 'name' => 'Widgets', 'slug' => 'widgets', 'parent' => 0 ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertSame(
			[ [ 'value' => 'Widgets', 'taxonomy' => 'merchant' ] ],
			$result['categories']
		);
	}

	public function test_brand_categories_stay_flat_regardless_of_path_map(): void {
		// WC `product_brand` taxonomy has no native hierarchy — brands
		// stay flat even when the controller supplies a path map.
		$fixture               = $this->simple_product_fixture();
		$fixture['brands']     = [
			[ 'id' => 70, 'name' => 'NorthPeak', 'slug' => 'northpeak' ],
		];
		$fixture['categories'] = [
			[ 'id' => 42, 'name' => 'Tshirts', 'slug' => 'tshirts', 'parent' => 41 ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture,
			[],
			null,
			[ 42 => 'Clothing > Tshirts', 70 => 'Should-Not-Be-Used' ]
		);

		// Two categories: hierarchical merchant + flat brand.
		$this->assertCount( 2, $result['categories'] );
		$this->assertSame(
			[ 'value' => 'Clothing > Tshirts', 'taxonomy' => 'merchant' ],
			$result['categories'][0]
		);
		// Brand entry: bare name even though path map has key 70.
		$this->assertSame(
			[ 'value' => 'NorthPeak', 'taxonomy' => 'brand' ],
			$result['categories'][1]
		);
	}

	public function test_category_bare_name_is_entity_decoded(): void {
		// When no path map entry exists, the category value falls back to
		// cat['name'] decoded via self::decode(). Entities in the bare
		// name must be resolved to plain Unicode.
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 7, 'name' => 'Caf&#233;', 'slug' => 'cafe', 'parent' => 0 ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture, [], null, [] );

		$this->assertSame( 'Café', $result['categories'][0]['value'] );
	}

	public function test_category_path_with_entities_is_emitted_verbatim_from_map(): void {
		// The path map is built by the controller (which now decodes entities
		// before storing names). This test documents that the translator emits
		// the map value as-is — decoding responsibility sits upstream.
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 8, 'name' => 'Cafe', 'slug' => 'cafe', 'parent' => 5 ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate(
			$fixture,
			[],
			null,
			[ 8 => 'Food & Drink > Café' ]
		);

		$this->assertSame( 'Food & Drink > Café', $result['categories'][0]['value'] );
	}

	public function test_variant_options_id_omitted_when_term_label_missing_from_map(): void {
		// String-path variation references a value not in the map
		// (e.g. label-case mismatch, custom-per-variation override).
		// Emit name + label without `id` — silent graceful degradation.
		$wc_product = [
			'id'         => 999,
			'name'       => 'Tee',
			'type'       => 'variable',
			'prices'     => [
				'price'         => '1500',
				'currency_code' => 'USD',
				'price_range'   => [ 'min_amount' => '1500', 'max_amount' => '1500' ],
			],
			'attributes' => [
				[
					'name'           => 'Color',
					'taxonomy'       => 'pa_color',
					'has_variations' => true,
					'terms'          => [
						[ 'name' => 'Black', 'slug' => 'black' ],
					],
				],
			],
			'variations' => [ [ 'id' => 991 ] ],
		];

		$wc_variations = [
			[
				'id'                => 991,
				'name'              => 'Tee',
				'is_in_stock'       => true,
				'short_description' => '',
				'prices'            => [
					'price'         => '1500',
					'currency_code' => 'USD',
				],
				'attributes'        => [],
				// "Charcoal" not in the term slug map.
				'variation'         => 'Color: Charcoal',
			],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $wc_product, $wc_variations );

		$variant = $result['variants'][0];
		$this->assertSame( 'Color', $variant['options'][0]['name'] );
		$this->assertSame( 'Charcoal', $variant['options'][0]['label'] );
		$this->assertArrayNotHasKey( 'id', $variant['options'][0] );
	}

	// ------------------------------------------------------------------
	// Product Bundles plugin support (#358)
	//
	// WC Product Bundles emits `type: bundle` and exposes the bundle
	// structure under `extensions.bundles`. Translator overrides the
	// bundle's price_range, list_price_range, and adds a metadata.bundle
	// block so agents can describe the bundle to buyers and decide
	// whether to attempt direct purchase or hand off to the storefront.
	// ------------------------------------------------------------------

	/**
	 * Mirror of the live `extensions.bundles` shape for pierorocca.com
	 * bundle 875 (Shirt Bundle): 3 bundled items, item 3 is optional
	 * and priced individually with a 10% discount, items 1 and 2 are
	 * required references to variable child products. Bundle's live
	 * price range is $20 base (excl_tax: 2000 minor) up to $36.20
	 * (excl_tax: 3620 minor) when all optional items are added at
	 * full price; regular range is $25 (2500) to $43 (4300).
	 */
	private function bundle_product_fixture(): array {
		return [
			'id'        => 875,
			'name'      => 'Shirt Bundle',
			'type'      => 'bundle',
			'permalink' => 'https://example.com/product/shirt-bundle/',
			'prices'    => [
				'price'         => '2000',
				'regular_price' => '2500',
				'currency_code' => 'USD',
			],
			'extensions' => [
				'bundles' => [
					'bundle_min_size'      => '2',
					'bundle_max_size'      => '4',
					'bundle_stock_status'  => 'instock',
					'bundle_price'         => [
						'price'         => [
							'min' => [ 'incl_tax' => '2215', 'excl_tax' => '2000' ],
							'max' => [ 'incl_tax' => '4009', 'excl_tax' => '3620' ],
						],
						'regular_price' => [
							'min' => [ 'incl_tax' => '2769', 'excl_tax' => '2500' ],
							'max' => [ 'incl_tax' => '4763', 'excl_tax' => '4300' ],
						],
						'currency_code' => 'USD',
					],
					'bundled_items'        => [
						[
							'bundled_item_id'                       => 1,
							'product_id'                            => 77,
							'quantity_default'                      => 1,
							'optional'                              => false,
							'priced_individually'                   => false,
							'discount'                              => '',
							'override_default_variation_attributes' => false,
						],
						[
							'bundled_item_id'                       => 2,
							'product_id'                            => 80,
							'quantity_default'                      => 1,
							'optional'                              => false,
							'priced_individually'                   => false,
							'discount'                              => '',
							'override_default_variation_attributes' => false,
						],
						[
							'bundled_item_id'                       => 3,
							'product_id'                            => 24,
							'quantity_default'                      => 1,
							'optional'                              => true,
							'priced_individually'                   => true,
							'discount'                              => '10',
							'override_default_variation_attributes' => false,
						],
					],
				],
			],
		];
	}

	public function test_bundle_price_range_uses_bundle_extension_min_max(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->bundle_product_fixture() );

		// Spans the configured bundle range, not the parent's flat
		// `prices.price`. With optional T-Shirt at full price the max
		// is 3620 minor; without it, min is 2000 minor.
		$this->assertSame( 2000, $result['price_range']['min']['amount'] );
		$this->assertSame( 3620, $result['price_range']['max']['amount'] );
		$this->assertSame( 'USD', $result['price_range']['min']['currency'] );
	}

	public function test_bundle_list_price_range_uses_bundle_extension_regular_price(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->bundle_product_fixture() );

		$this->assertArrayHasKey( 'list_price_range', $result );
		$this->assertSame( 2500, $result['list_price_range']['min']['amount'] );
		$this->assertSame( 4300, $result['list_price_range']['max']['amount'] );
	}

	public function test_bundle_list_price_range_omitted_when_no_discount(): void {
		// Live range equals regular range — strikethrough is
		// suppressed (same rule as non-bundle path).
		$fixture = $this->bundle_product_fixture();
		$fixture['extensions']['bundles']['bundle_price']['regular_price'] = $fixture['extensions']['bundles']['bundle_price']['price'];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'list_price_range', $result );
	}

	public function test_bundle_list_price_range_suppressed_for_flat_price_bundle_with_omitted_max(): void {
		// Flat-price bundle: live and regular ranges are both
		// effectively single-point (min == max). Bundles plugin may
		// omit the `max` leg in this case. The "nothing on sale"
		// suppression check must normalize null `max` to `min` —
		// otherwise the comparison fails and the helper emits a
		// phantom strikethrough range. Round-7 Copilot review caught
		// this for `live_max=null` + `regular_max` present scenario.
		$fixture = $this->bundle_product_fixture();
		// Strip the `max` legs from BOTH live and regular price.
		// Regular legs match live legs in value (no discount).
		$fixture['extensions']['bundles']['bundle_price']['price'] = [
			'min' => [ 'excl_tax' => '2000' ],
			// max omitted — flat-price bundle.
		];
		$fixture['extensions']['bundles']['bundle_price']['regular_price'] = [
			'min' => [ 'excl_tax' => '2000' ],
			'max' => [ 'excl_tax' => '2000' ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'list_price_range', $result, 'Flat-price bundle with omitted live max + matching regular range must not emit a strikethrough.' );
	}

	public function test_bundle_list_price_range_falls_back_to_parent_currency_when_extension_omits_it(): void {
		// `extensions.bundles.bundle_price.currency_code` is missing —
		// list_price_range must fall back to the parent's
		// `prices.currency_code`, not hard-default to USD. Regression
		// for round-2 Copilot review on a non-USD store.
		$fixture                                                     = $this->bundle_product_fixture();
		$fixture['prices']['currency_code']                          = 'GBP';
		unset( $fixture['extensions']['bundles']['bundle_price']['currency_code'] );

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertSame( 'GBP', $result['list_price_range']['min']['currency'] );
		$this->assertSame( 'GBP', $result['list_price_range']['max']['currency'] );
		// price_range already had this fallback; verify both helpers stay consistent.
		$this->assertSame( 'GBP', $result['price_range']['min']['currency'] );
	}

	public function test_bundle_metadata_emitted_with_full_item_structure(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->bundle_product_fixture() );

		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertArrayHasKey( 'bundle', $result['metadata'] );

		$bundle = $result['metadata']['bundle'];
		$this->assertSame( 2, $bundle['min_size'] );
		$this->assertSame( 4, $bundle['max_size'] );
		$this->assertCount( 3, $bundle['items'] );

		// Item 3 = optional T-Shirt with 10% discount
		$item3 = $bundle['items'][2];
		$this->assertSame( 3, $item3['bundled_item_id'] );
		$this->assertSame( 24, $item3['product_id'] );
		$this->assertTrue( $item3['optional'] );
		$this->assertSame( '10', $item3['discount'] );
		$this->assertFalse( $item3['has_default_variation'] );
	}

	public function test_bundle_metadata_discount_null_when_blank_string(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->bundle_product_fixture() );

		// Item 1 has discount='' in the fixture — should normalize to null.
		$this->assertNull( $result['metadata']['bundle']['items'][0]['discount'] );
	}

	public function test_bundle_metadata_skips_items_with_invalid_ids(): void {
		// Bundled-items entries missing/zero `bundled_item_id` or
		// `product_id` are merchant misconfigurations; emitting them as
		// `0` would mislead agents. Skip them entirely; metadata.bundle
		// should reflect only valid items.
		$fixture = $this->bundle_product_fixture();
		// Inject one invalid entry (missing bundled_item_id) before the valid items.
		array_unshift(
			$fixture['extensions']['bundles']['bundled_items'],
			[
				'product_id'                            => 999,
				'quantity_default'                      => 1,
				'optional'                              => false,
				'override_default_variation_attributes' => false,
				// `bundled_item_id` deliberately absent.
			]
		);
		// And one with product_id=0.
		$fixture['extensions']['bundles']['bundled_items'][] = [
			'bundled_item_id'                       => 4,
			'product_id'                            => 0,
			'quantity_default'                      => 1,
			'optional'                              => false,
			'override_default_variation_attributes' => false,
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		// Original fixture had 3 valid items; 2 invalid entries above
		// must be filtered out, leaving 3.
		$this->assertCount( 3, $result['metadata']['bundle']['items'] );
		foreach ( $result['metadata']['bundle']['items'] as $item ) {
			$this->assertGreaterThan( 0, $item['bundled_item_id'] );
			$this->assertGreaterThan( 0, $item['product_id'] );
		}
	}

	public function test_bundle_metadata_returns_null_when_all_items_invalid(): void {
		// `bundled_items` is non-empty but every entry has invalid IDs.
		// Per the docblock contract, emit no metadata.bundle at all
		// rather than `metadata.bundle.items: []`.
		$fixture = $this->bundle_product_fixture();
		$fixture['extensions']['bundles']['bundled_items'] = [
			[ 'product_id' => 0 ],
			'not-an-array-at-all',
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertFalse( isset( $result['metadata']['bundle'] ), 'metadata.bundle must not emit when no items have valid IDs.' );
	}

	public function test_non_bundle_product_omits_bundle_metadata(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->simple_product_fixture() );

		// Either no metadata at all (base simple-product fixture has
		// no residual attributes), OR metadata exists for other
		// reasons but the bundle key is absent. Both are acceptable;
		// what's NOT acceptable is a non-bundle product carrying
		// `metadata.bundle`.
		$has_bundle_metadata = isset( $result['metadata']['bundle'] );
		$this->assertFalse( $has_bundle_metadata, 'Non-bundle product must not emit metadata.bundle' );
	}

	public function test_bundle_translation_tolerates_non_array_extensions(): void {
		// A `woocommerce_store_api_*` filter or future Store API revision
		// could set `extensions` to a non-array value. The translator
		// must not raise warnings on that input — it should fall back
		// to the simple-product translation path. Round-8 Copilot
		// review: defensive guard before indexing into ['extensions']['bundles'].
		$fixture               = $this->bundle_product_fixture();
		$fixture['extensions'] = 'not-an-array';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		// Falls back to the parent's flat `prices.price` since the
		// bundle extension is unreadable.
		$this->assertSame( 2000, $result['price_range']['min']['amount'] );
		// No bundle metadata (no extension to read from).
		$this->assertFalse( isset( $result['metadata']['bundle'] ) );
	}

	public function test_bundle_type_without_extension_falls_back_to_simple_path(): void {
		// Bundles plugin deactivated mid-flight: type='bundle' but no
		// extensions.bundles block. Emit a working (if minimal) UCP
		// shape — agents see a simple-looking product rather than a
		// schema-violating empty one.
		$fixture = $this->bundle_product_fixture();
		unset( $fixture['extensions'] );

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		// Falls back to the parent's flat `prices.price` (2000 minor).
		$this->assertSame( 2000, $result['price_range']['min']['amount'] );
		$this->assertSame( 2000, $result['price_range']['max']['amount'] );
		// No bundle metadata.
		$this->assertFalse( isset( $result['metadata']['bundle'] ), 'Bundle without extension must not emit metadata.bundle' );
	}

	public function test_bundle_min_max_size_null_when_unconfigured(): void {
		// `bundle_min_size` / `bundle_max_size` are blank strings on a
		// bundle the merchant hasn't constrained. Normalize to null
		// rather than 0 so agents can distinguish "no constraint" from
		// "constrained to 0 items".
		$fixture = $this->bundle_product_fixture();
		$fixture['extensions']['bundles']['bundle_min_size'] = '';
		$fixture['extensions']['bundles']['bundle_max_size'] = '';

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertNull( $result['metadata']['bundle']['min_size'] );
		$this->assertNull( $result['metadata']['bundle']['max_size'] );
	}

	/**
	 * Grouped product fixture (#359). WC's Store API exposes the parent's
	 * children as a flat int[] under `grouped_products`.
	 */
	private function grouped_product_fixture(): array {
		return [
			'id'               => 600,
			'name'             => 'Grouped Parent',
			'slug'             => 'grouped-parent',
			'permalink'        => 'https://example.com/product/grouped-parent/',
			'is_in_stock'      => true,
			'prices'           => [
				'price'         => '3000',
				'currency_code' => 'USD',
			],
			'type'             => 'grouped',
			'grouped_products' => [ 601, 602, 603 ],
		];
	}

	public function test_grouped_metadata_emitted_with_children_list(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->grouped_product_fixture() );

		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertArrayHasKey( 'grouped', $result['metadata'] );
		$this->assertSame( [ 601, 602, 603 ], $result['metadata']['grouped']['children'] );
	}

	public function test_non_grouped_product_omits_grouped_metadata(): void {
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->simple_product_fixture() );

		// Either metadata is absent entirely or grouped key is unset.
		$this->assertTrue(
			! isset( $result['metadata'] ) || ! array_key_exists( 'grouped', $result['metadata'] ),
			'Simple products must not emit metadata.grouped.'
		);
	}

	public function test_grouped_metadata_dedupes_and_filters_invalid_child_ids(): void {
		// Merchant misconfiguration: same child listed twice + a zero ID
		// + a negative ID. Translator dedupes via array_unique and drops
		// non-positive IDs.
		$fixture                       = $this->grouped_product_fixture();
		$fixture['grouped_products']  = [ 601, 601, 0, -5, 602 ];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertSame( [ 601, 602 ], $result['metadata']['grouped']['children'] );
	}

	public function test_grouped_metadata_omitted_when_children_empty(): void {
		// `type === 'grouped'` but no children. Metadata block is omitted
		// rather than emitted as `[]` so consumers can distinguish "not
		// grouped" from "grouped with no children" (the former: omit; the
		// latter: misconfiguration, also omit because the empty list is
		// not actionable).
		$fixture                      = $this->grouped_product_fixture();
		$fixture['grouped_products']  = [];

		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $fixture );

		$this->assertTrue(
			! isset( $result['metadata'] ) || ! array_key_exists( 'grouped', $result['metadata'] ),
			'Grouped product with empty children must omit metadata.grouped.'
		);
	}

	public function test_build_grouped_url_query_returns_quantity_map_when_all_children_simple(): void {
		// Lazy fetcher returns a `simple` Store API record for every child
		// → result is `['quantity' => [cid => '1', ...]]`.
		$fetcher = function ( int $cid ): array {
			return [
				'id'   => $cid,
				'type' => 'simple',
			];
		};

		$result = WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601, 602, 603 ],
			$fetcher
		);

		$this->assertSame(
			[ 'quantity' => [ 601 => '1', 602 => '1', 603 => '1' ] ],
			$result
		);
	}

	public function test_build_grouped_url_query_returns_null_when_any_child_is_variable(): void {
		// A single variable child poisons the whole grouped product —
		// caller falls back to the parent permalink for buyer configuration.
		$fetcher = function ( int $cid ): array {
			return [
				'id'          => $cid,
				'type'        => 601 === $cid ? 'simple' : 'variable',
				'is_in_stock' => true,
			];
		};

		$result = WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601, 602 ],
			$fetcher
		);

		$this->assertNull( $result );
	}

	public function test_build_grouped_url_query_returns_null_when_any_child_is_external(): void {
		// Pin the contract that *all* non-simple types disqualify, not
		// just variable. External children (affiliate links to partner
		// sites) are realistic in the wild.
		$fetcher = function ( int $cid ): array {
			return [
				'id'          => $cid,
				'type'        => 601 === $cid ? 'simple' : 'external',
				'is_in_stock' => true,
			];
		};

		$result = WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601, 602 ],
			$fetcher
		);

		$this->assertNull( $result );
	}

	public function test_build_grouped_url_query_returns_null_when_any_child_is_out_of_stock(): void {
		// WC reports the grouped parent's `is_in_stock = true` if ANY
		// child is in stock, so a parent-level check alone lets OOS
		// children slip through. Helper must check each child's
		// `is_in_stock` independently.
		$fetcher = function ( int $cid ): array {
			return [
				'id'          => $cid,
				'type'        => 'simple',
				'is_in_stock' => 601 === $cid,
			];
		};

		$result = WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601, 602 ],
			$fetcher
		);

		$this->assertNull( $result );
	}

	public function test_build_grouped_url_query_uses_per_child_minimum_quantity(): void {
		// Per-child `add_to_cart.minimum` must propagate into the
		// returned quantity_map. Defaults to 1 when missing or
		// non-positive.
		$fetcher = function ( int $cid ): array {
			$minimums = [
				601 => 1,
				602 => 3,
				603 => 0, // invalid → defaults to 1
			];
			return [
				'id'          => $cid,
				'type'        => 'simple',
				'is_in_stock' => true,
				'add_to_cart' => [
					'minimum' => $minimums[ $cid ] ?? null,
				],
			];
		};

		$result = WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601, 602, 603 ],
			$fetcher
		);

		$this->assertSame(
			[ 'quantity' => [ 601 => '1', 602 => '3', 603 => '1' ] ],
			$result
		);
	}

	public function test_build_grouped_url_query_defaults_quantity_to_one_when_add_to_cart_missing(): void {
		// Older Store API versions / mocked fixtures may omit
		// `add_to_cart` entirely. Defensive default of 1 keeps the
		// helper functional rather than null-returning when the field
		// isn't present.
		$fetcher = function ( int $cid ): array {
			return [
				'id'          => $cid,
				'type'        => 'simple',
				'is_in_stock' => true,
				// no add_to_cart key
			];
		};

		$result = WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601 ],
			$fetcher
		);

		$this->assertSame( [ 'quantity' => [ 601 => '1' ] ], $result );
	}

	public function test_build_grouped_url_query_returns_null_when_child_fetch_fails(): void {
		// Fetcher returns null (Store API miss) → conservative null return.
		$fetcher = function ( int $cid ): ?array {
			return null;
		};

		$result = WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601 ],
			$fetcher
		);

		$this->assertNull( $result );
	}

	public function test_build_grouped_url_query_short_circuits_on_first_failure(): void {
		// Lazy fetcher pattern: if child #1 is variable, we should fetch
		// only child #1 (not child #2 or #3). Verifies the
		// `return null` short-circuit inside the foreach loop.
		$call_count = 0;
		$fetcher    = function ( int $cid ) use ( &$call_count ): array {
			++$call_count;
			return [ 'id' => $cid, 'type' => 'variable' ];
		};

		WC_AI_Storefront_UCP_Product_Translator::build_grouped_url_query(
			[ 601, 602, 603 ],
			$fetcher
		);

		$this->assertSame( 1, $call_count, 'Fetcher must short-circuit after first variable child.' );
	}

	public function test_grouped_translation_omits_bundle_metadata(): void {
		// Sanity check that grouped products don't accidentally pick up
		// the bundle code path (similar Store API shape — both are
		// "container" products from an agent perspective).
		$result = WC_AI_Storefront_UCP_Product_Translator::translate( $this->grouped_product_fixture() );

		$this->assertTrue(
			! isset( $result['metadata'] ) || ! array_key_exists( 'bundle', $result['metadata'] ),
			'Grouped products must not emit metadata.bundle.'
		);
	}

	// ------------------------------------------------------------------
	// resolve_default_variation_id() — single source of truth for the
	// merchant's `_default_attributes` signal used by the lookup
	// response's featured-variant assembly (#369).
	// ------------------------------------------------------------------

	/**
	 * Build a variable-parent fixture with the Length axis (matches the
	 * local wp-env subscription fixtures' shape — see
	 * `reference_subscription_fixtures` memory).
	 *
	 * @param string $default_term_slug Slug to mark `default: true` on,
	 *                                  or empty string for no default.
	 */
	private function variable_parent_with_length_axis( string $default_term_slug ): array {
		$terms = [];
		foreach ( [ '1-month', '3-months', '6-months', '1-year' ] as $slug ) {
			$terms[] = [
				'id'      => crc32( $slug ),
				'name'    => str_replace( '-', ' ', $slug ),
				'slug'    => $slug,
				'default' => $slug === $default_term_slug,
			];
		}
		return [
			'id'         => 100,
			'name'       => 'Variable Subscription',
			'type'       => 'variable',
			'attributes' => [
				[
					'id'             => 3,
					'name'           => 'Length',
					'taxonomy'       => 'pa_length',
					'has_variations' => true,
					'terms'          => $terms,
				],
			],
		];
	}

	/**
	 * Build a variation set matching the Length axis (4 entries:
	 * 1mo/3mo/6mo/1yr). Mirrors WC Store API variation response shape.
	 */
	private function length_axis_variations(): array {
		$out = [];
		$id  = 101;
		foreach ( [ '1-month', '3-months', '6-months', '1-year' ] as $slug ) {
			$out[] = [
				'id'         => $id++,
				'attributes' => [
					[ 'name' => 'Length', 'value' => $slug ],
				],
			];
		}
		return $out;
	}

	public function test_resolve_default_variation_id_returns_variation_when_all_axes_set(): void {
		$parent     = $this->variable_parent_with_length_axis( '6-months' );
		$variations = $this->length_axis_variations();
		// Variation IDs are 101 (1-month), 102 (3-months), 103 (6-months), 104 (1-year)
		// — so the 6-months default should resolve to ID 103.

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertSame( 103, $result );
	}

	public function test_resolve_default_variation_id_returns_null_when_no_defaults(): void {
		$parent     = $this->variable_parent_with_length_axis( '' ); // no slug → no term marked default
		$variations = $this->length_axis_variations();

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertNull( $result );
	}

	public function test_resolve_default_variation_id_returns_null_when_default_value_empty(): void {
		// Edge case: a default term whose slug is the empty string — WC
		// stores "any" selections this way in `_default_attributes`, and
		// the Store API surfaces it as default: true on a term with
		// slug: ''. That's not deterministic.
		$parent = $this->variable_parent_with_length_axis( '' );
		// Override: explicitly add a default-true term with empty slug.
		$parent['attributes'][0]['terms'][] = [
			'id'      => 999,
			'name'    => 'Any',
			'slug'    => '',
			'default' => true,
		];
		$variations = $this->length_axis_variations();

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertNull( $result );
	}

	public function test_resolve_default_variation_id_returns_null_when_partial_coverage(): void {
		// Two axes: Color + Size. Color has a default, Size doesn't.
		// Partial coverage → null.
		$parent = [
			'id'         => 200,
			'name'       => 'Two-axis Variable',
			'type'       => 'variable',
			'attributes' => [
				[
					'name'           => 'Color',
					'taxonomy'       => 'pa_color',
					'has_variations' => true,
					'terms'          => [
						[ 'id' => 1, 'name' => 'Red',  'slug' => 'red',  'default' => true  ],
						[ 'id' => 2, 'name' => 'Blue', 'slug' => 'blue', 'default' => false ],
					],
				],
				[
					'name'           => 'Size',
					'taxonomy'       => 'pa_size',
					'has_variations' => true,
					'terms'          => [
						[ 'id' => 3, 'name' => 'Small', 'slug' => 'small', 'default' => false ],
						[ 'id' => 4, 'name' => 'Large', 'slug' => 'large', 'default' => false ],
					],
				],
			],
		];
		$variations = [
			[ 'id' => 201, 'attributes' => [ [ 'name' => 'Color', 'value' => 'red'  ], [ 'name' => 'Size', 'value' => 'small' ] ] ],
			[ 'id' => 202, 'attributes' => [ [ 'name' => 'Color', 'value' => 'red'  ], [ 'name' => 'Size', 'value' => 'large' ] ] ],
			[ 'id' => 203, 'attributes' => [ [ 'name' => 'Color', 'value' => 'blue' ], [ 'name' => 'Size', 'value' => 'small' ] ] ],
		];

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertNull( $result );
	}

	public function test_resolve_default_variation_id_returns_null_for_non_variable_type(): void {
		// Simple products don't have variations — resolution is structurally
		// undefined. Bail rather than silently returning a default.
		$parent         = $this->variable_parent_with_length_axis( '6-months' );
		$parent['type'] = 'simple';
		$variations     = $this->length_axis_variations();

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertNull( $result );
	}

	public function test_resolve_default_variation_id_returns_null_when_variations_empty(): void {
		// Defensive against the malformed case (parent has type=variable
		// but no variations were pre-fetched — controller fetch failed,
		// or merchant misconfiguration like the AI-SUB-VAR-MAL fixture).
		$parent = $this->variable_parent_with_length_axis( '6-months' );

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			[]
		);

		$this->assertNull( $result );
	}

	public function test_resolve_default_variation_id_returns_null_when_default_slug_orphaned(): void {
		// `_default_attributes` postmeta names a slug whose term was deleted
		// from the taxonomy after being saved on the parent. The Store API
		// still surfaces `default: true` on a no-longer-matching term, but
		// no variation has that value — resolution fails closed.
		$parent = $this->variable_parent_with_length_axis( 'discontinued-term' );
		// Inject a fake default-marked term that no variation matches.
		$parent['attributes'][0]['terms'][] = [
			'id'      => 9999,
			'name'    => 'Discontinued',
			'slug'    => 'discontinued-term',
			'default' => true,
		];
		$variations = $this->length_axis_variations();

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertNull( $result );
	}

	public function test_resolve_default_variation_id_handles_empty_attributes_via_formatted_string(): void {
		// WC 9.x quirk: variable-product variations from the Store API
		// have `attributes[]` empty and put the active option set in the
		// formatted `variation` string ("Length: 6 months"). The helper
		// must parse that string and match by LABEL since the formatted
		// string uses labels, not slugs.
		$parent     = $this->variable_parent_with_length_axis( '6-months' );
		$variations = [];
		foreach ( [ '101' => '1 month', '102' => '3 months', '103' => '6 months', '104' => '1 year' ] as $id => $label ) {
			$variations[] = [
				'id'         => (int) $id,
				'attributes' => [],
				'variation'  => "Length: $label",
			];
		}

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertSame( 103, $result, 'Helper must resolve via formatted-string fallback when attributes[] is empty.' );
	}

	public function test_resolve_default_variation_id_ignores_extra_axes_on_variation(): void {
		// Variations can declare values on axes the parent doesn't have
		// defaults for — e.g. a custom inline attribute the merchant
		// didn't mark for variations, or leftover `attribute_*` postmeta.
		// The helper must match on the SUBSET of axes the parent defaults
		// declare (superset-match), not require exact axis equality.
		// Without this, any "extra" variation axis would silently disqualify
		// the default-matching variation.
		$parent     = $this->variable_parent_with_length_axis( '6-months' );
		$variations = [];
		$id         = 101;
		foreach ( [ '1-month', '3-months', '6-months', '1-year' ] as $slug ) {
			$variations[] = [
				'id'         => $id++,
				'attributes' => [
					[ 'name' => 'Length', 'value' => $slug ],
					// Extra axis the parent never declared as a variation
					// driver. Should be silently ignored during matching.
					[ 'name' => 'WarehouseSlot', 'value' => 'A-12' ],
				],
			];
		}

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertSame( 103, $result, 'Extra non-defaulted axes on variation must not disqualify the matching variation.' );
	}

	public function test_resolve_default_variation_id_works_for_variable_subscription_type(): void {
		// The subscription extension's `variable-subscription` shares the
		// shape of `variable` and uses `_default_attributes` identically.
		// Resolution must treat it as a first-class variable type.
		$parent         = $this->variable_parent_with_length_axis( '6-months' );
		$parent['type'] = 'variable-subscription';
		$variations     = $this->length_axis_variations();

		$result = WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id(
			$parent,
			$variations
		);

		$this->assertSame( 103, $result );
	}
}
