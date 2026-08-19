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
		// $wpdb is a global, and a mock left behind by one test will answer
		// queries in the next. Clear it both ways so a test that sets one up
		// cannot leak, and a test that expects none cannot inherit one.
		unset( $GLOBALS['wpdb'] );
		// Attribute labels are merchant-facing and therefore translated.
		// Return the source string so the assertions below stay readable.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_definitions_cover_all_seven_attributes(): void {
		$defs = WC_AI_Storefront_Attribute_Seeder::get_definitions();

		$this->assertSame(
			array( 'gender', 'age_group', 'condition', 'color', 'size', 'material', 'pattern' ),
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

	public function test_condition_is_seeded_with_googles_three_accepted_values(): void {
		$defs = WC_AI_Storefront_Attribute_Seeder::get_definitions();

		$this->assertArrayHasKey( 'condition', $defs );
		$this->assertSame(
			array( 'new', 'refurbished', 'used' ),
			$defs['condition']['terms']
		);
	}

	public function test_condition_omits_the_schema_org_value_google_rejects(): void {
		// OfferItemCondition has four members; Google accepts three.
		// DamagedCondition is valid schema.org that Google ignores, so a
		// merchant who picked it would believe they had declared a
		// condition and would have declared nothing.
		$terms = WC_AI_Storefront_Attribute_Seeder::get_definitions()['condition']['terms'];

		$this->assertNotContains( 'damaged', $terms );
	}

	public function test_seed_version_advanced_past_the_original_set(): void {
		// The attribute set changed, so the version keyed to it must move
		// or no existing store ever creates pa_condition.
		$this->assertNotSame( '1', WC_AI_Storefront_Attribute_Seeder::SEED_VERSION );
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
		// a direct read of the table reports the row.
		//
		// This used to assert against wc_attribute_taxonomy_id_by_name().
		// #649 showed that accessor serves from the same transient the
		// taxonomy registry is built from, so on a host with a shared
		// persistent object cache BOTH guards answer "absent" together.
		// The table is the only source that cannot.
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static fn( $slug ) => 'pa_' . $slug
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '42' );

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
		// Seeding holds an add_option()-backed lock (#649). The default here
		// is "this caller won the lock"; tests about contention override it.
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		// The lock value carries an ownership token (#649 review).
		Functions\when( 'wp_generate_password' )->justReturn( 'tok123456789' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// create_attribute() now reads the attributes table directly rather
		// than a cached accessor (#649). Report the same absent/present
		// answer $existing gives, so both guards stay consistent here.
		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				foreach ( $args as $arg ) {
					$sql = preg_replace( '/%[sd]/', (string) $arg, $sql, 1 );
				}
				return $sql;
			}
		);
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $sql ) use ( $existing ) {
				foreach ( $existing as $taxonomy ) {
					if ( false !== strpos( $sql, '= ' . substr( $taxonomy, 3 ) ) ) {
						return '1';
					}
				}
				return null;
			}
		);
		// seed() also runs the #649 repair pass, which scans the table.
		// No duplicates in these fixtures.
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
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

	public function test_seed_creates_all_seven_on_a_fresh_store(): void {
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( true );

		$count = WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertSame( 7, $count );
		$this->assertSame(
			array( 'gender', 'age_group', 'condition', 'color', 'size', 'material', 'pattern' ),
			$created
		);
	}

	public function test_repair_runs_on_a_store_whose_seed_flag_is_current(): void {
		// The whole point of a separate trigger. An affected store already
		// holds attributes_seeded = SEED_VERSION, so needs_seeding() is
		// false and anything hanging off it never runs.
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( WC_AI_Storefront_Attribute_Seeder::SEEDED_OPTION === $name ) {
					return WC_AI_Storefront_Attribute_Seeder::SEED_VERSION;
				}
				return '';
			}
		);

		$this->assertFalse(
			WC_AI_Storefront_Attribute_Seeder::needs_seeding(),
			'Precondition: this store looks fully seeded.'
		);
		$this->assertTrue(
			WC_AI_Storefront_Attribute_Seeder::needs_repair(),
			'…and must still be offered the repair.'
		);
	}

	public function test_repair_does_not_rerun_once_recorded(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( WC_AI_Storefront_Attribute_Seeder::REPAIRED_OPTION === $name ) {
					return WC_AI_Storefront_Attribute_Seeder::REPAIR_VERSION;
				}
				return '';
			}
		);

		$this->assertFalse( WC_AI_Storefront_Attribute_Seeder::needs_repair() );
	}

	public function test_create_attribute_skips_a_slug_already_in_the_table(): void {
		// The state that produced #649: the row exists, but the taxonomy is
		// not registered and WooCommerce's cached attribute list does not
		// know about it. Both existing guards say "absent". A direct read of
		// the table is the only one that can say otherwise.
		Functions\when( 'wc_attribute_taxonomy_name' )->alias( static fn( $s ) => 'pa_' . $s );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );
		Functions\when( '__' )->returnArg();
		Functions\expect( 'wc_create_attribute' )->never();

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '9' );

		$this->assertFalse(
			WC_AI_Storefront_Attribute_Seeder::create_attribute(
				'condition',
				array(
					'label' => 'Condition',
					'terms' => array( 'new' ),
				)
			)
		);
	}

	public function test_repair_removes_extra_rows_but_keeps_the_terms(): void {
		// The trap. wc_delete_attribute() deletes every term in the
		// taxonomy, and both duplicate rows share pa_condition — so calling
		// it would wipe new/refurbished/used and leave the survivor empty.
		// The repair must delete the ROW only.
		Functions\expect( 'wc_delete_attribute' )->never();
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, $arg ) => str_replace( '%d', (string) $arg, $sql )
		);
		// Two condition rows; ids 9 and 10.
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				(object) array(
					'attribute_id'   => '9',
					'attribute_name' => 'condition',
				),
				(object) array(
					'attribute_id'   => '10',
					'attribute_name' => 'condition',
				),
			)
		);
		$deleted = array();
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$deleted ) {
				if ( preg_match( '/attribute_id\s*=\s*(\d+)/', $sql, $m ) ) {
					$deleted[] = (int) $m[1];
				}
				return 1;
			}
		);

		$removed = WC_AI_Storefront_Attribute_Seeder::repair_duplicates();

		$this->assertSame( 1, $removed );
		$this->assertSame( array( 10 ), $deleted, 'Keep the lowest id, drop the rest.' );
	}

	public function test_repair_ignores_attributes_this_plugin_does_not_seed(): void {
		// A merchant with two of their own attributes sharing a name is not
		// our business to "fix".
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( '__' )->returnArg();

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, $arg ) => str_replace( '%d', (string) $arg, $sql )
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				(object) array(
					'attribute_id'   => '20',
					'attribute_name' => 'fabric_weight',
				),
				(object) array(
					'attribute_id'   => '21',
					'attribute_name' => 'fabric_weight',
				),
			)
		);
		$wpdb->shouldReceive( 'query' )->never();

		$this->assertSame( 0, WC_AI_Storefront_Attribute_Seeder::repair_duplicates() );
	}

	public function test_second_concurrent_seed_creates_nothing(): void {
		// #649. Both existing guards ask a CACHE whether the attribute
		// exists, and a cache can answer wrongly. add_option() is backed by
		// a unique index on option_name, so exactly one concurrent caller
		// can create the lock. The loser must create nothing at all —
		// not "fewer duplicates", none.
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( true );

		// First caller takes the lock; every later add_option() fails, which
		// is what the unique index gives us in production.
		$lock_taken = false;
		Functions\when( 'add_option' )->alias(
			static function () use ( &$lock_taken ) {
				if ( $lock_taken ) {
					return false;
				}
				$lock_taken = true;
				return true;
			}
		);

		WC_AI_Storefront_Attribute_Seeder::seed();
		$first_round = $created;
		$created     = array();

		// Second request, arriving while the first still holds the lock.
		// Its seed flag read is stale, so needs_seeding() is still true.
		WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertCount( 7, $first_round );
		$this->assertSame( array(), $created, 'The losing request must create nothing.' );
	}

	public function test_release_does_not_delete_a_lock_someone_else_reclaimed(): void {
		// The mutex is only correct if release is scoped to the lock this
		// request took. A run that overruns LOCK_TIMEOUT gets its lock
		// reclaimed by a second request; if its finally then deletes
		// unconditionally, it frees the SECOND request's lock and a third
		// can start seeding alongside it.
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );

		// Replace the harness's $wpdb wholesale rather than layering another
		// shouldReceive() on it — Mockery keeps the first matching
		// expectation, so an added one would never be consulted.
		$delete_sql = array();
		global $wpdb;
		$wpdb          = Mockery::mock();
		$wpdb->prefix  = 'wp_';
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				foreach ( $args as $arg ) {
					$sql = preg_replace( '/%[sd]/', (string) $arg, $sql, 1 );
				}
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$delete_sql ) {
				if ( false !== strpos( $sql, 'DELETE' ) && false !== strpos( $sql, 'option_name' ) ) {
					$delete_sql[] = $sql;
				}
				return 1;
			}
		);

		WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertNotEmpty( $delete_sql, 'The lock must be released through a conditional delete.' );
		$this->assertStringContainsString(
			'option_value',
			$delete_sql[0],
			'Release must match on the token this request stored, not just the option name.'
		);
	}

	public function test_repair_does_not_flush_the_whole_object_cache(): void {
		// wp_cache_flush() empties every group on the site. WooCommerce
		// itself invalidates only `woocommerce-attributes` plus the
		// transient after wc_create_attribute(); match that.
		Functions\expect( 'wp_cache_flush' )->never();
		Functions\expect( 'delete_transient' )->atLeast()->once();
		Functions\when( '__' )->returnArg();

		global $wpdb;
		$wpdb          = Mockery::mock();
		$wpdb->prefix  = 'wp_';
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, $arg ) => str_replace( '%d', (string) $arg, $sql )
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				(object) array(
					'attribute_id'   => '9',
					'attribute_name' => 'condition',
				),
				(object) array(
					'attribute_id'   => '10',
					'attribute_name' => 'condition',
				),
			)
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		WC_AI_Storefront_Attribute_Seeder::repair_duplicates();
	}

	public function test_a_stale_lock_does_not_block_seeding_forever(): void {
		// A request that dies mid-seed leaves the lock behind. Without a
		// timeout the store never seeds again and the failure is silent.
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( true );

		// Lock exists and is older than the timeout. add_option() fails
		// while the stale row is present and succeeds once it is gone —
		// which is what the unique index does in production, and what
		// makes the re-add the atomic step rather than the delete.
		$stale_row_present = true;
		Functions\when( 'delete_option' )->alias(
			static function () use ( &$stale_row_present ) {
				$stale_row_present = false;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function () use ( &$stale_row_present ) {
				return ! $stale_row_present;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( WC_AI_Storefront_Attribute_Seeder::LOCK_OPTION === $name ) {
					// Timestamp-first, matching what acquire_lock() stores.
					return ( time() - 3600 ) . ':stale-token';
				}
				return '';
			}
		);

		WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertCount( 7, $created, 'A stale lock must be reclaimed, not obeyed forever.' );
	}

	public function test_a_fresh_lock_is_obeyed(): void {
		// The companion: a lock taken seconds ago belongs to a request that
		// is still working. Stealing it would recreate the very race the
		// lock exists to prevent.
		$created = array();
		$this->stub_creation_environment( array(), $created );
		Functions\when( 'apply_filters' )->justReturn( true );
		// Modelled exactly like the stale-lock test: add_option() fails only
		// while the row is present. If the freshness check is removed, the
		// reclaim path deletes and re-adds successfully and seeding runs —
		// so this test fails for the right reason rather than because
		// add_option() was rigged to always fail.
		$lock_row_present = true;
		Functions\when( 'delete_option' )->alias(
			static function () use ( &$lock_row_present ) {
				$lock_row_present = false;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function () use ( &$lock_row_present ) {
				return ! $lock_row_present;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( WC_AI_Storefront_Attribute_Seeder::LOCK_OPTION === $name ) {
					return time() . ':fresh-token';
				}
				return '';
			}
		);

		WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertSame( array(), $created );
		$this->assertTrue( $lock_row_present, 'A fresh lock must not even be deleted.' );
	}

	public function test_reseed_creates_only_the_new_attribute(): void {
		// The whole safety argument for bumping SEED_VERSION: a store that
		// seeded version 1 must gain Condition and nothing else. Asserted
		// rather than assumed, because a regression here re-runs the
		// duplicate-attribute failure from #628.
		$created = array();
		$this->stub_creation_environment(
			array( 'pa_gender', 'pa_age_group', 'pa_color', 'pa_size', 'pa_material', 'pa_pattern' ),
			$created
		);
		Functions\when( 'apply_filters' )->justReturn( true );

		$count = WC_AI_Storefront_Attribute_Seeder::seed();

		$this->assertSame( 1, $count );
		$this->assertSame( array( 'condition' ), $created );
	}

	public function test_seed_leaves_existing_attributes_untouched_per_attribute(): void {
		$created = array();
		// Merchant already has Color and Size; the other five are absent.
		$this->stub_creation_environment( array( 'pa_color', 'pa_size' ), $created );
		Functions\when( 'apply_filters' )->justReturn( true );

		$count = WC_AI_Storefront_Attribute_Seeder::seed();

		// Per-attribute decision, not all-or-nothing.
		$this->assertSame( 5, $count );
		$this->assertSame(
			array( 'gender', 'age_group', 'condition', 'material', 'pattern' ),
			$created
		);
		$this->assertNotContains( 'color', $created );
		$this->assertNotContains( 'size', $created );
	}

	public function test_seed_is_a_noop_when_everything_already_exists(): void {
		$created  = array();
		$recorded = array();
		$this->stub_creation_environment(
			array(
				'pa_gender',
				'pa_age_group',
				'pa_condition',
				'pa_color',
				'pa_size',
				'pa_material',
				'pa_pattern',
			),
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
		// Per option name, not a blanket answer: seed() now asks two
		// separate questions and a stub that says SEED_VERSION to both
		// leaves needs_repair() true, so the run proceeds and the
		// expectations below fire for the wrong reason.
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( WC_AI_Storefront_Attribute_Seeder::REPAIRED_OPTION === $name ) {
					return WC_AI_Storefront_Attribute_Seeder::REPAIR_VERSION;
				}
				return WC_AI_Storefront_Attribute_Seeder::SEED_VERSION;
			}
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
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'tok123456789' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
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
