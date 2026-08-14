<?php
/**
 * Tests for WC_AI_Storefront_Attribute_Seeder.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AttributeSeederTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_definitions_cover_all_six_attributes(): void {
		$defs = WC_AI_Storefront_Attribute_Seeder::get_definitions();

		$this->assertSame(
			array( 'gender', 'age_group', 'color', 'size', 'material', 'pattern' ),
			array_keys( $defs ),
			'Definition order is the creation order; keep it stable.'
		);
	}

	public function test_closed_list_terms_match_google_values_exactly(): void {
		$defs = WC_AI_Storefront_Attribute_Seeder::get_definitions();

		// Google defines these two exhaustively. Any drift here means we
		// are publishing values Merchant Center will reject.
		$this->assertSame(
			array( 'male', 'female', 'unisex' ),
			$defs['gender']['terms']
		);
		$this->assertSame(
			array( 'newborn', 'infant', 'toddler', 'kids', 'adult' ),
			$defs['age_group']['terms']
		);
	}

	public function test_size_terms_use_abbreviations_not_words(): void {
		$defs = WC_AI_Storefront_Attribute_Seeder::get_definitions();

		// Google: "submit 'S', 'M', and 'L'. Don't submit 'S', 'Medium',
		// and 'Lrg'." WooCommerce's own sample data creates Small/Medium/
		// Large, which is the form we are deliberately NOT copying.
		$this->assertContains( 'S', $defs['size']['terms'] );
		$this->assertContains( 'M', $defs['size']['terms'] );
		$this->assertContains( 'L', $defs['size']['terms'] );
		$this->assertNotContains( 'Small', $defs['size']['terms'] );
		$this->assertNotContains( 'Medium', $defs['size']['terms'] );
		$this->assertNotContains( 'Large', $defs['size']['terms'] );
	}

	public function test_every_definition_has_a_label_and_non_empty_terms(): void {
		foreach ( WC_AI_Storefront_Attribute_Seeder::get_definitions() as $slug => $def ) {
			$this->assertArrayHasKey( 'label', $def, "{$slug} missing label" );
			$this->assertNotSame( '', $def['label'], "{$slug} has empty label" );
			$this->assertNotEmpty( $def['terms'], "{$slug} has no terms" );
			$this->assertSame(
				array_values( array_unique( $def['terms'] ) ),
				$def['terms'],
				"{$slug} has duplicate terms"
			);
		}
	}
}
