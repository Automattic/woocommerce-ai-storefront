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
