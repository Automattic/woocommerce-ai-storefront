<?php
/**
 * Tests for WC_AI_Syndication_UCP_Variant_Translator.
 *
 * Two paths:
 *   - translate(): takes a WC variation Store API response, produces
 *     a UCP variant shape (for variable products with real variations).
 *   - synthesize_default(): takes a simple product Store API response,
 *     emits one synthesized variant representing the product itself
 *     (satisfies UCP schema's minItems: 1 requirement on variants).
 *
 * @package WooCommerce_AI_Syndication
 */

class UcpVariantTranslatorTest extends \PHPUnit\Framework\TestCase {

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	/**
	 * A WC Store API response for a real product variation.
	 *
	 * @return array<string, mixed>
	 */
	private function variation_fixture(): array {
		return [
			'id'                => 456,
			'name'              => 'Blue Shirt',
			'sku'               => 'SHIRT-BLUE-L',
			'is_in_stock'       => true,
			'prices'            => [
				'price'               => '12000',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			],
			'attributes'        => [
				[ 'name' => 'Color', 'value' => 'Blue' ],
				[ 'name' => 'Size',  'value' => 'Large' ],
			],
			'short_description' => '<p>A blue shirt in <strong>large</strong>.</p>',
		];
	}

	/**
	 * A WC Store API response for a simple (non-variable) product.
	 *
	 * @return array<string, mixed>
	 */
	private function simple_product_fixture(): array {
		return [
			'id'            => 123,
			'name'          => 'Widget',
			'sku'           => 'WIDGET-001',
			'is_in_stock'   => true,
			'prices'        => [
				'price'               => '500',
				'currency_code'       => 'USD',
				'currency_minor_unit' => 2,
			],
		];
	}

	// ------------------------------------------------------------------
	// translate() — real WC variations
	// ------------------------------------------------------------------

	public function test_translate_prefixes_id_with_var(): void {
		$result = WC_AI_Syndication_UCP_Variant_Translator::translate(
			$this->variation_fixture()
		);

		$this->assertEquals( 'var_456', $result['id'] );
	}

	public function test_translate_builds_title_from_attribute_values(): void {
		// A WC variation's `name` is typically the parent product's name.
		// The meaningful variant title comes from its attributes.
		$result = WC_AI_Syndication_UCP_Variant_Translator::translate(
			$this->variation_fixture()
		);

		$this->assertEquals( 'Blue / Large', $result['title'] );
	}

	public function test_translate_falls_back_to_name_when_attributes_missing(): void {
		$fixture = $this->variation_fixture();
		unset( $fixture['attributes'] );

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertEquals( 'Blue Shirt', $result['title'] );
	}

	public function test_translate_preserves_price_as_integer_minor_units(): void {
		// WC Store API returns price as a STRING in minor units.
		// Output must be INT (JSON number, not string) in the same units.
		// No * 100, no float math.
		$result = WC_AI_Syndication_UCP_Variant_Translator::translate(
			$this->variation_fixture()
		);

		$this->assertSame( 12000, $result['price']['amount'] );
		$this->assertEquals( 'USD', $result['price']['currency'] );
	}

	public function test_translate_includes_sku_when_present(): void {
		$result = WC_AI_Syndication_UCP_Variant_Translator::translate(
			$this->variation_fixture()
		);

		$this->assertEquals( 'SHIRT-BLUE-L', $result['sku'] );
	}

	public function test_translate_omits_sku_when_absent(): void {
		$fixture = $this->variation_fixture();
		unset( $fixture['sku'] );

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'sku', $result );
	}

	public function test_translate_marks_out_of_stock_variant_unavailable(): void {
		$fixture                = $this->variation_fixture();
		$fixture['is_in_stock'] = false;

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertFalse( $result['availability']['available'] );
	}

	public function test_translate_strips_html_from_description(): void {
		$result = WC_AI_Syndication_UCP_Variant_Translator::translate(
			$this->variation_fixture()
		);

		$this->assertEquals(
			'A blue shirt in large.',
			$result['description']['plain']
		);
	}

	// ------------------------------------------------------------------
	// synthesize_default() — simple products
	// ------------------------------------------------------------------

	public function test_synthesize_default_has_default_suffix_in_id(): void {
		// Distinguishes synthesized variants from real ones. An agent
		// looking up var_123_default would know this isn't a real
		// variation — just the simple product wrapped for schema compliance.
		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'var_123_default', $result['id'] );
	}

	public function test_synthesize_default_uses_product_name_as_title(): void {
		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'Widget', $result['title'] );
	}

	public function test_synthesize_default_emits_empty_description(): void {
		// Simple products have their own description at the product level;
		// we don't duplicate it onto the variant. Empty string keeps
		// the schema-required description.plain field present but not
		// redundant.
		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default(
			$this->simple_product_fixture()
		);

		$this->assertEquals( '', $result['description']['plain'] );
	}

	public function test_synthesize_default_copies_price_from_product(): void {
		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default(
			$this->simple_product_fixture()
		);

		$this->assertSame( 500, $result['price']['amount'] );
		$this->assertEquals( 'USD', $result['price']['currency'] );
	}

	public function test_synthesize_default_includes_sku_when_present(): void {
		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default(
			$this->simple_product_fixture()
		);

		$this->assertEquals( 'WIDGET-001', $result['sku'] );
	}

	public function test_synthesize_default_marks_out_of_stock(): void {
		$fixture                = $this->simple_product_fixture();
		$fixture['is_in_stock'] = false;

		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default( $fixture );

		$this->assertFalse( $result['availability']['available'] );
	}

	// ------------------------------------------------------------------
	// Zero-decimal and non-2-decimal currency handling
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// 1.8.0: structured options, sale pricing, stock quantity, barcodes
	// ------------------------------------------------------------------

	public function test_translate_emits_structured_options_from_attributes(): void {
		$fixture = [
			'id'         => 501,
			'name'       => 'Polo shirt',
			'prices'     => [ 'price' => '2500', 'currency_code' => 'USD' ],
			'attributes' => [
				[ 'name' => 'Color', 'value' => 'Blue', 'taxonomy' => 'pa_color' ],
				[ 'name' => 'Size', 'value' => 'Medium', 'taxonomy' => 'pa_size' ],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayHasKey( 'options', $result );
		$this->assertSame(
			[
				[ 'attribute' => 'Color', 'value' => 'Blue' ],
				[ 'attribute' => 'Size', 'value' => 'Medium' ],
			],
			$result['options']
		);
	}

	public function test_translate_omits_options_when_attributes_empty(): void {
		$fixture = [
			'id'     => 501,
			'name'   => 'Variant with no attrs',
			'prices' => [ 'price' => '2500', 'currency_code' => 'USD' ],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'options', $result );
	}

	public function test_translate_skips_option_entries_without_attribute_label(): void {
		// Regression: an attribute entry with a present value but
		// missing `name` would emit `{attribute: "", value: "Blue"}`
		// — an unlabeled axis the agent can't filter or present.
		// Drop those, parallel to the empty-value skip.
		$fixture = [
			'id'         => 501,
			'name'       => 'Mixed attribute shapes',
			'prices'     => [ 'price' => '2500', 'currency_code' => 'USD' ],
			'attributes' => [
				[ 'name' => 'Color', 'value' => 'Blue' ],
				[ 'value' => 'no-label-here' ],
				[ 'name' => '', 'value' => 'also-no-label' ],
				[ 'name' => 'Size', 'value' => 'M' ],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertCount( 2, $result['options'] );
		$this->assertSame( 'Color', $result['options'][0]['attribute'] );
		$this->assertSame( 'Size', $result['options'][1]['attribute'] );
	}

	public function test_translate_emits_compare_at_price_when_on_sale(): void {
		$fixture = [
			'id'      => 601,
			'name'    => 'Sale item',
			'on_sale' => true,
			'prices'  => [
				'price'         => '1500',
				'regular_price' => '2000',
				'currency_code' => 'USD',
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayHasKey( 'compare_at_price', $result );
		$this->assertSame( 2000, $result['compare_at_price']['amount'] );
		$this->assertSame( 'USD', $result['compare_at_price']['currency'] );
		$this->assertSame( 1500, $result['price']['amount'] );
	}

	public function test_translate_omits_compare_at_price_when_not_on_sale(): void {
		$fixture = [
			'id'      => 602,
			'name'    => 'Regular item',
			'on_sale' => false,
			'prices'  => [
				'price'         => '2000',
				'regular_price' => '2000',
				'currency_code' => 'USD',
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'compare_at_price', $result );
	}

	public function test_translate_omits_compare_at_price_on_inconsistent_state(): void {
		// on_sale: true but regular_price <= price — rather than
		// emit nonsensical "was $10, now $10" we skip it. Third-
		// party plugins occasionally produce this state.
		$fixture = [
			'id'      => 603,
			'name'    => 'Flag on but no discount',
			'on_sale' => true,
			'prices'  => [
				'price'         => '2000',
				'regular_price' => '2000',
				'currency_code' => 'USD',
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'compare_at_price', $result );
	}

	public function test_translate_emits_availability_quantity_from_low_stock(): void {
		$fixture = [
			'id'                  => 701,
			'name'                => 'Almost gone',
			'prices'              => [ 'price' => '1000', 'currency_code' => 'USD' ],
			'is_in_stock'         => true,
			'low_stock_remaining' => 3,
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertTrue( $result['availability']['available'] );
		$this->assertSame( 3, $result['availability']['quantity'] );
	}

	public function test_translate_omits_availability_quantity_when_not_provided(): void {
		$fixture = [
			'id'          => 702,
			'name'        => 'Plenty',
			'prices'      => [ 'price' => '1000', 'currency_code' => 'USD' ],
			'is_in_stock' => true,
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertTrue( $result['availability']['available'] );
		$this->assertArrayNotHasKey( 'quantity', $result['availability'] );
	}

	public function test_translate_emits_barcodes_from_store_api_extension(): void {
		$fixture = [
			'id'         => 801,
			'name'       => 'Barcoded product',
			'prices'     => [ 'price' => '2000', 'currency_code' => 'USD' ],
			'extensions' => [
				WC_AI_Syndication_Store_Api_Extension::NAMESPACE => [
					'barcodes' => [
						[ 'type' => 'gtin13', 'value' => '1234567890123' ],
					],
				],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayHasKey( 'barcodes', $result );
		$this->assertSame( 'gtin13', $result['barcodes'][0]['type'] );
		$this->assertSame( '1234567890123', $result['barcodes'][0]['value'] );
	}

	public function test_translate_skips_malformed_barcode_entries(): void {
		$fixture = [
			'id'         => 802,
			'name'       => 'Malformed',
			'prices'     => [ 'price' => '2000', 'currency_code' => 'USD' ],
			'extensions' => [
				WC_AI_Syndication_Store_Api_Extension::NAMESPACE => [
					'barcodes' => [
						[ 'type' => '', 'value' => '123' ],
						[ 'type' => 'gtin13', 'value' => '' ],
						[ 'type' => 'gtin13', 'value' => 'ok' ],
					],
				],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertCount( 1, $result['barcodes'] );
		$this->assertSame( 'ok', $result['barcodes'][0]['value'] );
	}

	// ------------------------------------------------------------------
	// 1.9.0: Per-variant media
	// ------------------------------------------------------------------

	public function test_translate_emits_media_from_variation_images(): void {
		// WC variations can have their own image (red shirt → red
		// photo). Store API returns it under the variation's `images`
		// array; we emit it at variant level so agents can present the
		// right visual for each option.
		$fixture = [
			'id'          => 555,
			'name'        => 'Red / M',
			'prices'      => [
				'price'         => '2500',
				'currency_code' => 'USD',
			],
			'is_in_stock' => true,
			'images'      => [
				[ 'src' => 'https://store.example/red.jpg', 'alt' => 'Red shirt' ],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertCount( 1, $result['media'] );
		$this->assertSame( 'image', $result['media'][0]['type'] );
		$this->assertSame( 'https://store.example/red.jpg', $result['media'][0]['url'] );
		$this->assertSame( 'Red shirt', $result['media'][0]['alt_text'] );
	}

	public function test_translate_omits_media_when_variation_has_no_images(): void {
		// When a merchant hasn't set a variation-specific image, we
		// don't emit `media` at variant level — the product-level
		// media still carries the default, and omitting the key keeps
		// the variant payload lean.
		$fixture = [
			'id'          => 555,
			'name'        => 'Default / One',
			'prices'      => [
				'price'         => '2500',
				'currency_code' => 'USD',
			],
			'is_in_stock' => true,
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'media', $result );
	}

	public function test_translate_omits_media_alt_text_when_empty(): void {
		$fixture = [
			'id'          => 555,
			'name'        => 'Red / M',
			'prices'      => [
				'price'         => '2500',
				'currency_code' => 'USD',
			],
			'is_in_stock' => true,
			'images'      => [
				[ 'src' => 'https://store.example/red.jpg', 'alt' => '' ],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertArrayNotHasKey( 'alt_text', $result['media'][0] );
	}

	public function test_translate_skips_image_entries_without_src(): void {
		$fixture = [
			'id'          => 555,
			'name'        => 'Red / M',
			'prices'      => [
				'price'         => '2500',
				'currency_code' => 'USD',
			],
			'is_in_stock' => true,
			'images'      => [
				[ 'src' => '', 'alt' => 'broken' ],
				[ 'src' => 'https://store.example/red.jpg' ],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::translate( $fixture );

		$this->assertCount( 1, $result['media'] );
		$this->assertSame( 'https://store.example/red.jpg', $result['media'][0]['url'] );
	}

	public function test_synthesize_default_also_carries_new_fields(): void {
		$fixture = [
			'id'                  => 901,
			'name'                => 'Simple on sale',
			'on_sale'             => true,
			'is_in_stock'         => true,
			'low_stock_remaining' => 5,
			'prices'              => [
				'price'         => '1500',
				'regular_price' => '2000',
				'currency_code' => 'USD',
			],
			'extensions'          => [
				WC_AI_Syndication_Store_Api_Extension::NAMESPACE => [
					'barcodes' => [
						[ 'type' => 'gtin13', 'value' => '9876543210987' ],
					],
				],
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default( $fixture );

		$this->assertSame( 2000, $result['compare_at_price']['amount'] );
		$this->assertSame( 5, $result['availability']['quantity'] );
		$this->assertSame( '9876543210987', $result['barcodes'][0]['value'] );
	}

	public function test_jpy_integer_amount_preserved_without_math(): void {
		// JPY has currency_minor_unit = 0 (no cents). WC returns
		// `prices.price = "5000"` meaning 5000 JPY. A hardcoded *100
		// bug would turn this into 500000 — a 100x price error.
		// This test locks in the correct behavior.
		$fixture = [
			'id'     => 789,
			'name'   => 'Tokyo Special',
			'prices' => [
				'price'               => '5000',
				'currency_code'       => 'JPY',
				'currency_minor_unit' => 0,
			],
		];

		$result = WC_AI_Syndication_UCP_Variant_Translator::synthesize_default( $fixture );

		$this->assertSame( 5000, $result['price']['amount'] );
		$this->assertEquals( 'JPY', $result['price']['currency'] );
	}
}
