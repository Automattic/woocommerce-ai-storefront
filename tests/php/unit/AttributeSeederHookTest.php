<?php
/**
 * Verifies attribute seeding runs — immediately or deferred to init — from
 * the version-bump branch, and never silently drops on activation.
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
 * A second deviation, added after the initial implementation shipped:
 * unconditionally deferring to `add_action( 'init', ... )` was itself a
 * critical bug. `register_activation_hook` callbacks
 * (wc_ai_storefront_activate() in woocommerce-ai-storefront.php) run AFTER
 * `init` has already fired for that request, so a plain add_action() there
 * registers a callback that never runs — a merchant who activates the
 * plugin got zero attributes, silently, forever (the version option is
 * still written on the next line, consuming the one-shot version-mismatch
 * branch for good). schedule_attribute_seeding() now branches on
 * `did_action( 'init' )`: run the seeder immediately when init already
 * fired, defer via add_action() otherwise. See the method docblock in
 * includes/class-wc-ai-storefront.php for the full rationale, and
 * test_seeding_runs_immediately_when_init_already_fired below for the
 * regression coverage that was missing when the bug shipped.
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

	public function test_seeding_is_deferred_to_init_when_init_has_not_fired(): void {
		$hooked = array();

		// Explicit precondition: init has NOT fired yet for this request —
		// the normal plugins_loaded entry point. See
		// test_seeding_runs_immediately_when_init_already_fired below for
		// the other entry point (activation), where init HAS already fired.
		Functions\when( 'did_action' )->justReturn( 0 );

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

	/**
	 * Regression test for the critical activation bug described in the file
	 * docblock above: the register_activation_hook callback runs AFTER
	 * `init` has already fired for that request, so unconditionally
	 * deferring via add_action( 'init', ... ) registers a callback that
	 * never runs. Nothing crashed — the plugin just silently seeded nothing
	 * on activation, every time. This test proves the immediate path exists
	 * and is taken instead: when init has already fired, seeding runs
	 * inline, with no dependence on a hook that will never fire again.
	 */
	public function test_seeding_runs_immediately_when_init_already_fired(): void {
		Functions\when( 'did_action' )->justReturn( 1 );

		// Must NOT register anything on `init` — that hook has already
		// dispatched to every subscriber for this request.
		Functions\expect( 'add_action' )->never();

		// seed() now guards on needs_seeding() before anything else (see
		// #629); stub get_option() to report "not yet seeded" so that
		// guard doesn't short-circuit before the apply_filters() call this
		// test is asserting on.
		Functions\when( 'get_option' )->justReturn( '' );

		// Proves seeding actually ran rather than being silently skipped:
		// once needs_seeding() clears, seed()'s next statement is
		// apply_filters( SEED_FILTER, true ). Returning false
		// short-circuits before any WC calls.
		Functions\expect( 'apply_filters' )
			->once()
			->with( WC_AI_Storefront_Attribute_Seeder::SEED_FILTER, true )
			->andReturn( false );
		Functions\expect( 'wc_create_attribute' )->never();

		WC_AI_Storefront::schedule_attribute_seeding();
	}

	public function test_run_attribute_seeding_forwards_to_the_seeder(): void {
		// seed() now guards on needs_seeding() before anything else (see
		// #629); stub get_option() to report "not yet seeded" so that
		// guard doesn't short-circuit before the apply_filters() call this
		// test is asserting on.
		Functions\when( 'get_option' )->justReturn( '' );

		// Once needs_seeding() clears, seed()'s next statement is
		// apply_filters( self::SEED_FILTER, true ). Expecting exactly that
		// call, and returning false to short-circuit before any WC calls,
		// proves run_attribute_seeding() reaches
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
	public function test_real_orchestrator_branches_on_init_state_and_calls_scheduler_between_create_tables_and_version_write(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-wc-ai-storefront.php' );

		// Isolate schedule_attribute_seeding()'s own body by slicing up to
		// the START of run_attribute_seeding()'s signature. Needed because
		// run_attribute_seeding() ALSO calls
		// WC_AI_Storefront_Attribute_Seeder::seed() a few lines later in the
		// same file — a whole-file substring search could not tell the two
		// call sites apart.
		$method_start = strpos( $source, 'public static function schedule_attribute_seeding(): void {' );
		$next_method_start = strpos( $source, 'public static function run_attribute_seeding(): void {' );
		$this->assertNotFalse( $method_start, 'schedule_attribute_seeding() not found.' );
		$this->assertNotFalse( $next_method_start, 'run_attribute_seeding() not found.' );
		$this->assertGreaterThan( $method_start, $next_method_start, 'Expected run_attribute_seeding() to follow schedule_attribute_seeding() in the file.' );
		$method_body = substr( $source, $method_start, $next_method_start - $method_start );

		// Immediate path: when `init` has already fired (the
		// register_activation_hook entry point — see the file docblock
		// above), seeding must run inline rather than being registered on a
		// hook that will never fire again this request.
		$this->assertStringContainsString(
			"if ( did_action( 'init' ) ) {",
			$method_body,
			'schedule_attribute_seeding() must branch on did_action( "init" ).'
		);
		$this->assertStringContainsString(
			'WC_AI_Storefront_Attribute_Seeder::seed();',
			$method_body,
			'The did_action( "init" ) branch must call the seeder directly.'
		);

		// Deferred path: unchanged — when `init` has not fired yet (the
		// normal plugins_loaded entry point), defer via the
		// run_attribute_seeding() void adapter.
		$this->assertStringContainsString(
			"add_action( 'init', array( self::class, 'run_attribute_seeding' ) );",
			$method_body,
			'schedule_attribute_seeding() must still defer to init when init has not fired.'
		);

		// And the adapter itself must forward to the real seeder — the
		// deferral is worthless if the callback does something else.
		$this->assertStringContainsString(
			'WC_AI_Storefront_Attribute_Seeder::seed();',
			substr( $source, $next_method_start ),
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
