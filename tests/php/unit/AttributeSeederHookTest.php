<?php
/**
 * Verifies attribute seeding is scheduled on init from the version-bump branch.
 *
 * Deviation from the task brief: the brief's callback target was
 * `array( WC_AI_Storefront_Attribute_Seeder::class, 'seed' )`. Wiring that
 * directly into add_action() fails `php -d memory_limit=2G vendor/bin/phpstan
 * analyse` for real — seed() returns int (the created-attribute count, for
 * direct callers) and PHPStan's WordPress extension (HookCallbackRule)
 * correctly flags a non-void `add_action` callback as an error. Every other
 * static method this codebase hooks as an action callback
 * (WC_AI_Storefront_Crawl_Logger::prune_raw_log(), ::rollup(),
 * WC_AI_Storefront_Attribution::bust_stats_cache()) is `: void`, so
 * WC_AI_Storefront::run_attribute_seeding() was added as the same kind of
 * void adapter and is what `init` actually calls. See its docblock in
 * includes/class-wc-ai-storefront.php for the full rationale.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AttributeSeederHookTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_seeding_is_deferred_to_init_never_called_inline(): void {
		$hooked = array();

		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$hooked ) {
				$hooked[] = array(
					'hook'     => $hook,
					'callback' => $callback,
					'priority' => $priority,
				);
			}
		);

		// The seeder must NOT run during the version-bump branch itself —
		// that fires on plugins_loaded, where taxonomy work is unsafe.
		Functions\expect( 'wc_create_attribute' )->never();

		WC_AI_Storefront::schedule_attribute_seeding();

		$init_hooks = array_values(
			array_filter(
				$hooked,
				static fn( $h ) => 'init' === $h['hook']
			)
		);

		$this->assertCount( 1, $init_hooks, 'Expected exactly one init hook.' );
		// Deviation from the brief: callback is the void adapter
		// run_attribute_seeding(), not WC_AI_Storefront_Attribute_Seeder::seed
		// directly — see the file docblock above for why. The next test
		// verifies the adapter genuinely forwards to the seeder.
		$this->assertSame(
			array( WC_AI_Storefront::class, 'run_attribute_seeding' ),
			$init_hooks[0]['callback']
		);
	}

	public function test_run_attribute_seeding_forwards_to_the_seeder(): void {
		// seed()'s first statement is apply_filters( self::SEED_FILTER, true ).
		// Expecting exactly that call, and returning false to short-circuit
		// before any WC calls, proves run_attribute_seeding() reaches
		// WC_AI_Storefront_Attribute_Seeder::seed() rather than silently
		// no-op'ing or calling something else.
		Functions\expect( 'apply_filters' )
			->once()
			->with( WC_AI_Storefront_Attribute_Seeder::SEED_FILTER, true )
			->andReturn( false );
		Functions\expect( 'wc_create_attribute' )->never();

		WC_AI_Storefront::run_attribute_seeding();
	}

	/**
	 * WC_AI_Storefront is shadowed for the whole suite by
	 * tests/php/stubs/class-wc-ai-storefront-stub.php (loaded unconditionally
	 * in bootstrap.php so classes that reference WC_AI_Storefront statically
	 * get a controllable double), so the tests above only exercise the
	 * stub's mirror — they never touch includes/class-wc-ai-storefront.php.
	 * DiscoveryCacheControlTest and PluginSettingsLinkTest hit the same
	 * shadowing and solve it the same way: pin the real file structurally.
	 * This is that pin for the deferral contract this whole task exists to
	 * build.
	 */
	public function test_real_orchestrator_defers_seeding_to_init_and_calls_it_between_create_tables_and_version_write(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-wc-ai-storefront.php' );

		// The scheduling method itself must defer via add_action( 'init', ... )
		// rather than ever calling the seeder directly.
		$this->assertStringContainsString(
			"add_action( 'init', array( self::class, 'run_attribute_seeding' ) );",
			$source,
			'schedule_attribute_seeding() must defer to init via the run_attribute_seeding() adapter.'
		);

		// And the adapter itself must forward to the real seeder — the
		// deferral is worthless if the callback does something else.
		$this->assertStringContainsString(
			'WC_AI_Storefront_Attribute_Seeder::seed();',
			$source,
			'run_attribute_seeding() must call WC_AI_Storefront_Attribute_Seeder::seed().'
		);

		// And the version-mismatch branch must call the scheduler — never
		// seed inline — positioned after crawl-log tables are created and
		// before the version option is written (the ordering the brief
		// specifies; unrelated to but consistent with ActivationTest's
		// ordering guards on the same branch).
		$create_tables_pos = strpos( $source, 'WC_AI_Storefront_Crawl_Logger::create_tables();' );
		$schedule_call_pos = strpos( $source, 'self::schedule_attribute_seeding();' );
		$version_write_pos = strpos( $source, "update_option( 'wc_ai_storefront_version', WC_AI_STOREFRONT_VERSION );" );

		$this->assertNotFalse( $create_tables_pos, 'create_tables() call not found.' );
		$this->assertNotFalse( $schedule_call_pos, 'schedule_attribute_seeding() call not found in register_rewrite_rules().' );
		$this->assertNotFalse( $version_write_pos, 'Version-option write not found.' );

		$this->assertGreaterThan(
			$create_tables_pos,
			$schedule_call_pos,
			'Seeding must be scheduled after crawl-log tables are created.'
		);
		$this->assertLessThan(
			$version_write_pos,
			$schedule_call_pos,
			'Seeding must be scheduled before the version option is written.'
		);
	}
}
