<?php
/**
 * Tests for WC_AI_Syndication_UCP_Product_Translator.
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
 * @package WooCommerce_AI_Syndication
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
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'prod_123', $result['id'] );
	}

	public function test_title_from_wc_name(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'Widget', $result['title'] );
	}

	public function test_description_strips_html_tags(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertEquals(
			"Joe's widgets & gadgets",
			$result['description']['plain']
		);
	}

	public function test_emits_at_least_one_variant_per_schema_minitems(): void {
		// UCP schema: variants minItems: 1. Every product must have
		// at least one variant — even simple products. This test
		// locks in the synthesized-default behavior.
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertNotEmpty( $result['variants'] );
		$this->assertGreaterThanOrEqual( 1, count( $result['variants'] ) );
	}

	public function test_synthesized_variant_has_default_suffix(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'var_123_default', $result['variants'][0]['id'] );
	}

	// ------------------------------------------------------------------
	// Price range
	// ------------------------------------------------------------------

	public function test_simple_product_price_range_min_equals_max(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->variable_product_fixture()
		);

		$this->assertSame( 1000, $result['price_range']['min']['amount'] );
		$this->assertSame( 1500, $result['price_range']['max']['amount'] );
	}

	public function test_currency_passes_through_unchanged(): void {
		// Currency code is opaque to us — whatever WC says, we echo.
		$fixture                         = $this->simple_product_fixture();
		$fixture['prices']['currency_code'] = 'GBP';

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertEquals( 'GBP', $result['price_range']['min']['currency'] );
	}

	// ------------------------------------------------------------------
	// Optional fields — emit only when present
	// ------------------------------------------------------------------

	public function test_handle_from_slug_when_present(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'widget', $result['handle'] );
	}

	public function test_handle_omitted_when_slug_absent(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['slug'] );

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'handle', $result );
	}

	public function test_url_from_permalink(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture()
		);

		$this->assertEquals(
			'https://example.com/product/widget/',
			$result['url']
		);
	}

	// ------------------------------------------------------------------
	// Categories
	// ------------------------------------------------------------------

	public function test_categories_tagged_with_merchant_taxonomy(): void {
		// WC categories are business-defined; UCP expects taxonomy
		// tagging so agents can distinguish "this is the merchant's
		// own categorization" from standardized taxonomies like
		// google_product_category.
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'categories', $result );
	}

	public function test_categories_skip_entries_without_name(): void {
		$fixture               = $this->simple_product_fixture();
		$fixture['categories'] = [
			[ 'id' => 5, 'name' => 'Widgets' ],
			[ 'id' => 6 ],  // malformed — no name
			[ 'id' => 7, 'name' => 'Other' ],
		];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertCount( 2, $result['categories'] );
	}

	// ------------------------------------------------------------------
	// Media
	// ------------------------------------------------------------------

	public function test_media_from_images_array(): void {
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'alt_text', $result['media'][0] );
	}

	public function test_media_omitted_when_product_has_no_images(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['images'] );

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'media', $result );
	}

	public function test_media_skips_image_entries_without_src(): void {
		$fixture           = $this->simple_product_fixture();
		$fixture['images'] = [
			[ 'src' => 'https://example.com/a.jpg' ],
			[],  // malformed
			[ 'src' => 'https://example.com/b.jpg' ],
		];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture );

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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
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
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$this->assertCount( 1, $result['variants'] );
		$this->assertEquals( 'var_123_default', $result['variants'][0]['id'] );
	}

	// ------------------------------------------------------------------
	// 1.8.0: description.html, tags, product attributes, ratings
	// ------------------------------------------------------------------

	public function test_translate_emits_description_html_when_source_has_markup(): void {
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = '<ul><li>Waterproof</li><li>Light</li></ul>';

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayHasKey( 'html', $result['description'] );
		$this->assertSame( '<ul><li>Waterproof</li><li>Light</li></ul>', $result['description']['html'] );
		$this->assertSame( 'WaterproofLight', $result['description']['plain'] );
	}

	public function test_translate_omits_description_html_when_plain(): void {
		$fixture                      = $this->simple_product_fixture();
		$fixture['short_description'] = 'Just plain text';

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

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

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'html', $result['description'] );
		$this->assertSame( 'Fish & Chips', $result['description']['plain'] );
	}

	public function test_translate_emits_tags_as_second_taxonomy_in_categories(): void {
		$fixture         = $this->simple_product_fixture();
		$fixture['tags'] = [
			[ 'id' => 5, 'name' => 'summer', 'slug' => 'summer' ],
			[ 'id' => 6, 'name' => 'eco-friendly', 'slug' => 'eco-friendly' ],
		];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertContains(
			[ 'value' => 'summer', 'taxonomy' => 'tag' ],
			$result['categories']
		);
		$this->assertContains(
			[ 'value' => 'eco-friendly', 'taxonomy' => 'tag' ],
			$result['categories']
		);
	}

	public function test_translate_emits_brands_as_third_taxonomy_in_categories(): void {
		// WC 9.5+ `product_brand` taxonomy (and the earlier "WooCommerce
		// Brands" plugin) surfaces via Store API under `brands[]`. Shape
		// mirrors categories/tags — emit with `taxonomy: "brand"`.
		$fixture           = $this->simple_product_fixture();
		$fixture['brands'] = [
			[ 'id' => 88, 'name' => 'ACME', 'slug' => 'acme' ],
		];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertContains(
			[ 'value' => 'ACME', 'taxonomy' => 'brand' ],
			$result['categories']
		);
	}

	public function test_translate_omits_brands_when_source_has_none(): void {
		// Merchants without Brands registered pay zero payload — no
		// empty `brand` taxonomy entries should appear.
		$result = WC_AI_Syndication_UCP_Product_Translator::translate(
			$this->simple_product_fixture(),
			[]
		);

		$brand_entries = array_filter(
			$result['categories'] ?? [],
			static fn( array $entry ): bool => 'brand' === ( $entry['taxonomy'] ?? '' )
		);
		$this->assertEmpty( $brand_entries );
	}

	public function test_translate_emits_product_attributes_excluding_variation_defining(): void {
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
				// Variation-defining attribute — belongs on variant options, NOT here.
				'name'           => 'Size',
				'taxonomy'       => 'pa_size',
				'has_variations' => true,
				'terms'          => [
					[ 'id' => 20, 'name' => 'M', 'slug' => 'm' ],
				],
			],
		];

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayHasKey( 'attributes', $result );
		$this->assertCount( 1, $result['attributes'] );
		$this->assertSame( 'Material', $result['attributes'][0]['name'] );
		$this->assertSame( [ 'Cotton', 'Organic' ], $result['attributes'][0]['values'] );
	}

	public function test_translate_omits_attributes_when_source_has_none(): void {
		$fixture = $this->simple_product_fixture();
		unset( $fixture['attributes'] );

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'attributes', $result );
	}

	public function test_translate_emits_ratings_under_extension_when_reviews_exist(): void {
		$fixture                   = $this->simple_product_fixture();
		$fixture['average_rating'] = '4.67';
		$fixture['review_count']   = 42;

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayHasKey( 'extensions', $result );
		$this->assertArrayHasKey(
			'com.woocommerce.ai_syndication',
			$result['extensions']
		);
		$ratings = $result['extensions']['com.woocommerce.ai_syndication']['ratings'];
		$this->assertSame( 4.67, $ratings['average'] );
		$this->assertSame( 42, $ratings['count'] );
	}

	public function test_translate_omits_ratings_extension_when_no_reviews(): void {
		$fixture                   = $this->simple_product_fixture();
		$fixture['average_rating'] = '0';
		$fixture['review_count']   = 0;

		$result = WC_AI_Syndication_UCP_Product_Translator::translate( $fixture, [] );

		$this->assertArrayNotHasKey( 'extensions', $result );
	}
}
