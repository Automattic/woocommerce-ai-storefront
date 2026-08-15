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
		// Attribute labels are merchant-facing and therefore translated.
		// Return the source string so the assertions below stay readable.
		Functions\when( '__' )->returnArg();
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

	public function test_create_attribute_skips_when_taxonomy_not_registered_but_row_exists_in_db(): void {
		// Simulates the concurrent-request race the second guard closes: this
		// request's in-memory taxonomy registry does not know about the
		// attribute (taxonomy_exists() is false, e.g. a sibling request
		// created it after this request's init:5 registry was already
		// built), but the DB row already exists and wc_create_attribute()
		// busted the wc_attribute_taxonomies cache when it was inserted, so
		// wc_attribute_taxonomy_id_by_name() (which re-reads that cache)
		// reports a non-zero ID.
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 42 );

		// If the seeder relied on taxonomy_exists() alone, it would reach
		// these calls and create a duplicate row.
		Functions\expect( 'wc_create_attribute' )->never();
		Functions\expect( 'register_taxonomy' )->never();
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
		// Second existence guard (see create_attribute()'s docblock) must
		// also report "not found" so this happy-path test reaches
		// wc_create_attribute().
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );
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
		// Second existence guard must also report "not found" so this test
		// reaches wc_create_attribute() and exercises the error path.
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );
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
		// Second existence guard must also report "not found" so this test
		// reaches wc_create_attribute().
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );
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
	 * Also stubs get_option()/update_option() — seed() now guards on
	 * needs_seeding() and records SEED_VERSION on the way out (see #629),
	 * and every caller of this helper calls seed() directly. get_option()
	 * returns '' so the guard reports "needs seeding" and orchestration
	 * still runs; update_option() is a no-op recorder these tests don't
	 * assert against (that behaviour has its own dedicated test below).
	 *
	 * @param string[] $existing Taxonomy names to report as existing.
	 * @param array    $created  Populated by reference with created slugs.
	 */
	private function stub_creation_environment( array $existing, array &$created ): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( $taxonomy ) => in_array( $taxonomy, $existing, true )
		);
		// Second existence guard (see create_attribute()'s docblock). Kept
		// consistent with $existing here — these seed()-level orchestration
		// tests are not exercising the concurrent-request race itself
		// (AttributeSeederTest::test_create_attribute_skips_when_taxonomy_not_registered_but_row_exists_in_db
		// covers that directly), so both guards should agree.
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->alias(
			static fn( $slug ) => in_array( 'pa_' . $slug, $existing, true ) ? 1 : 0
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
		$created  = array();
		$recorded = array();
		$this->stub_creation_environment(
			array( 'pa_gender', 'pa_age_group', 'pa_color', 'pa_size', 'pa_material', 'pa_pattern' ),
			$created
		);
		Functions\when( 'apply_filters' )->justReturn( true );
		// Overrides stub_creation_environment()'s default update_option()
		// stub so this test can inspect what was recorded, not just that a
		// call didn't fatal.
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$recorded ) {
				$recorded[ $name ] = $value;
			}
		);

		$this->assertSame( 0, WC_AI_Storefront_Attribute_Seeder::seed() );
		$this->assertSame( array(), $created );
		// The dangerous case: zero attributes created is still a successful
		// run, and the flag must be recorded so this store stops being
		// re-evaluated on every subsequent request. A naive
		// `if ( $created > 0 )` guard around the update_option() call in
		// seed() would pass every other test in this file and silently
		// reopen the concurrency bug this whole feature exists to close.
		$this->assertSame(
			WC_AI_Storefront_Attribute_Seeder::SEED_VERSION,
			$recorded[ WC_AI_Storefront_Attribute_Seeder::SEEDED_OPTION ] ?? null,
			'update_option() must record SEED_VERSION even when nothing was created.'
		);
	}

	public function test_seed_does_nothing_when_filter_returns_false(): void {
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( false );

		$this->assertSame( 0, WC_AI_Storefront_Attribute_Seeder::seed() );
		$this->assertSame( array(), $created );
	}

	public function test_needs_seeding_is_true_when_option_absent(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertTrue( WC_AI_Storefront_Attribute_Seeder::needs_seeding() );
	}

	public function test_needs_seeding_is_false_when_option_matches_seed_version(): void {
		Functions\when( 'get_option' )->justReturn(
			WC_AI_Storefront_Attribute_Seeder::SEED_VERSION
		);

		$this->assertFalse( WC_AI_Storefront_Attribute_Seeder::needs_seeding() );
	}

	public function test_needs_seeding_is_true_when_option_holds_an_older_seed_version(): void {
		// The flag stores the SEED SET version, not the plugin version, so a
		// plugin release that does not change the attribute set leaves this
		// untouched and no seeding is attempted.
		Functions\when( 'get_option' )->justReturn( 'not-the-current-seed-version' );

		$this->assertTrue( WC_AI_Storefront_Attribute_Seeder::needs_seeding() );
	}

	public function test_seed_returns_zero_without_touching_anything_when_already_seeded(): void {
		Functions\when( 'get_option' )->justReturn(
			WC_AI_Storefront_Attribute_Seeder::SEED_VERSION
		);
		// The whole point: no filter, no taxonomy probe, no insert, and no
		// re-recording of a flag that's already correct.
		Functions\expect( 'apply_filters' )->never();
		Functions\expect( 'taxonomy_exists' )->never();
		Functions\expect( 'wc_create_attribute' )->never();
		Functions\expect( 'update_option' )->never();

		$this->assertSame( 0, WC_AI_Storefront_Attribute_Seeder::seed() );
	}

	public function test_seed_records_the_seed_version_after_a_successful_run(): void {
		$recorded = array();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->justReturn( true );
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'term_exists' )->justReturn( null );
		Functions\when( 'register_taxonomy' )->justReturn( null );
		Functions\when( 'wp_insert_term' )->justReturn( array( 'term_id' => 1 ) );
		Functions\when( 'wc_create_attribute' )->justReturn( 1 );
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$recorded ) {
				$recorded[ $name ] = $value;
			}
		);

		WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertSame(
			WC_AI_Storefront_Attribute_Seeder::SEED_VERSION,
			$recorded[ WC_AI_Storefront_Attribute_Seeder::SEEDED_OPTION ] ?? null
		);
	}
}
