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

	public function test_create_attribute_skips_when_taxonomy_already_exists(): void {
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->justReturn( true );

		// If the seeder tries to create despite the taxonomy existing,
		// these expectations fail the test.
		Functions\expect( 'wc_create_attribute' )->never();
		Functions\expect( 'wp_insert_term' )->never();

		$created = WC_AI_Storefront_Attribute_Seeder::create_attribute(
			'color',
			array(
				'label' => 'Color',
				'terms' => array( 'Black' ),
			)
		);

		$this->assertFalse( $created );
	}

	public function test_create_attribute_registers_taxonomy_before_inserting_terms(): void {
		$call_order = array();

		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		// is_wp_error() is defined in stubs.php before Patchwork loads, so it
		// cannot be redefined via Brain Monkey. The stub checks `instanceof
		// WP_Error`; the int id returned by wc_create_attribute() below
		// naturally evaluates false, same as the real function.
		Functions\when( 'term_exists' )->justReturn( null );

		Functions\when( 'wc_create_attribute' )->alias(
			static function ( $args ) use ( &$call_order ) {
				$call_order[] = 'create:' . $args['slug'];
				return 42;
			}
		);
		Functions\when( 'register_taxonomy' )->alias(
			static function ( $taxonomy ) use ( &$call_order ) {
				$call_order[] = 'register:' . $taxonomy;
			}
		);
		Functions\when( 'wp_insert_term' )->alias(
			static function ( $term, $taxonomy ) use ( &$call_order ) {
				$call_order[] = 'term:' . $term;
				return array( 'term_id' => 1 );
			}
		);

		$created = WC_AI_Storefront_Attribute_Seeder::create_attribute(
			'gender',
			array(
				'label' => 'Gender',
				'terms' => array( 'male', 'female' ),
			)
		);

		$this->assertTrue( $created );

		// wc_create_attribute() does NOT register the taxonomy in the same
		// request. Inserting terms before register_taxonomy() fails with an
		// invalid-taxonomy error, so the ordering is the contract.
		$this->assertSame(
			array( 'create:gender', 'register:pa_gender', 'term:male', 'term:female' ),
			$call_order
		);
	}

	public function test_create_attribute_aborts_when_attribute_creation_errors(): void {
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		// is_wp_error() in stubs.php checks instanceof WP_Error; a real
		// WP_Error instance drives the true branch without mocking the
		// function (see note in the previous test).
		Functions\when( 'wc_create_attribute' )->justReturn( new WP_Error( 'invalid_attribute', 'wp-error-object' ) );

		Functions\expect( 'register_taxonomy' )->never();
		Functions\expect( 'wp_insert_term' )->never();

		$created = WC_AI_Storefront_Attribute_Seeder::create_attribute(
			'size',
			array(
				'label' => 'Size',
				'terms' => array( 'S' ),
			)
		);

		$this->assertFalse( $created );
	}

	public function test_create_attribute_skips_terms_that_already_exist(): void {
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		// is_wp_error() is the real stub from stubs.php (see note above); the
		// int id returned here is not a WP_Error, so it naturally evaluates
		// false.
		Functions\when( 'wc_create_attribute' )->justReturn( 7 );
		Functions\when( 'register_taxonomy' )->justReturn( null );

		// 'Black' already present, 'White' not.
		Functions\when( 'term_exists' )->alias(
			static fn( $term ) => 'Black' === $term ? array( 'term_id' => 5 ) : null
		);

		$inserted = array();
		Functions\when( 'wp_insert_term' )->alias(
			static function ( $term ) use ( &$inserted ) {
				$inserted[] = $term;
				return array( 'term_id' => 9 );
			}
		);

		WC_AI_Storefront_Attribute_Seeder::create_attribute(
			'color',
			array(
				'label' => 'Color',
				'terms' => array( 'Black', 'White' ),
			)
		);

		$this->assertSame( array( 'White' ), $inserted );
	}

	/**
	 * Stubs every WP/WC function create_attribute() touches, so seed()
	 * tests can focus on orchestration. $existing lists taxonomies that
	 * already exist.
	 *
	 * @param string[] $existing Taxonomy names to report as existing.
	 * @param array    $created  Populated by reference with created slugs.
	 */
	private function stub_creation_environment( array $existing, array &$created ): void {
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( $taxonomy ) => in_array( $taxonomy, $existing, true )
		);
		// Do NOT stub is_wp_error(). tests/php/stubs.php defines a real one
		// before Patchwork loads, so Brain Monkey throws DefinedTooEarly.
		// wc_create_attribute() below returns an int, which the real
		// is_wp_error() correctly reports as not-an-error. Same approach
		// as IndexNowTest.php.
		Functions\when( 'term_exists' )->justReturn( null );
		Functions\when( 'register_taxonomy' )->justReturn( null );
		Functions\when( 'wp_insert_term' )->justReturn( array( 'term_id' => 1 ) );
		Functions\when( 'wc_create_attribute' )->alias(
			static function ( $args ) use ( &$created ) {
				$created[] = $args['slug'];
				return 1;
			}
		);
	}

	public function test_seed_creates_all_six_on_a_fresh_store(): void {
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( true );

		$count = WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertSame( 6, $count );
		$this->assertSame(
			array( 'gender', 'age_group', 'color', 'size', 'material', 'pattern' ),
			$created
		);
	}

	public function test_seed_leaves_existing_attributes_untouched_per_attribute(): void {
		$created = array();
		// Merchant already has Color and Size; the other four are absent.
		$this->stub_creation_environment( array( 'pa_color', 'pa_size' ), $created );
		Functions\when( 'apply_filters' )->justReturn( true );

		$count = WC_AI_Storefront_Attribute_Seeder::seed();

		// Per-attribute decision, not all-or-nothing.
		$this->assertSame( 4, $count );
		$this->assertSame(
			array( 'gender', 'age_group', 'material', 'pattern' ),
			$created
		);
		$this->assertNotContains( 'color', $created );
		$this->assertNotContains( 'size', $created );
	}

	public function test_seed_is_a_noop_when_everything_already_exists(): void {
		$created = array();
		$this->stub_creation_environment(
			array( 'pa_gender', 'pa_age_group', 'pa_color', 'pa_size', 'pa_material', 'pa_pattern' ),
			$created
		);
		Functions\when( 'apply_filters' )->justReturn( true );

		$this->assertSame( 0, WC_AI_Storefront_Attribute_Seeder::seed() );
		$this->assertSame( array(), $created );
	}

	public function test_seed_does_nothing_when_filter_returns_false(): void {
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( false );

		$this->assertSame( 0, WC_AI_Storefront_Attribute_Seeder::seed() );
		$this->assertSame( array(), $created );
	}
}
