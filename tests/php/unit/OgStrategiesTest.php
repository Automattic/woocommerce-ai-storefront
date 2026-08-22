<?php
/**
 * Tests for the Open Graph strategy seam and its SEOPress implementation.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class OgStrategiesTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Commerce-page gate handed to every strategy.
	 *
	 * @var bool
	 */
	private bool $on_commerce_page = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		$this->on_commerce_page = true;
	}

	protected function tearDown(): void {
		WC_AI_Storefront_Og_Strategies::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The gate the dispatcher passes down, closing over $this so a test can
	 * flip the answer after the strategy has already captured the callable.
	 */
	private function gate(): callable {
		return function () {
			return $this->on_commerce_page;
		};
	}

	// --- The dispatcher ---

	public function test_no_seo_plugin_yields_no_strategies(): void {
		$this->assertSame( array(), WC_AI_Storefront_Og_Strategies::for_slugs( array() ) );
	}

	public function test_an_unrecognised_plugin_yields_no_strategies(): void {
		$this->assertSame(
			array(),
			WC_AI_Storefront_Og_Strategies::for_slugs( array( 'gibberish' ) )
		);
	}

	public function test_seopress_yields_its_own_strategy(): void {
		$strategies = WC_AI_Storefront_Og_Strategies::for_slugs( array( 'seopress' ) );
		$this->assertCount( 1, $strategies );
		$this->assertInstanceOf( WC_AI_Storefront_Og_Strategy_Seopress::class, $strategies[0] );
	}

	public function test_a_recognised_plugin_is_picked_out_of_a_mixed_list(): void {
		$strategies = WC_AI_Storefront_Og_Strategies::for_slugs( array( 'gibberish', 'seopress' ) );
		$this->assertCount( 1, $strategies );
		$this->assertInstanceOf( WC_AI_Storefront_Og_Strategy_Seopress::class, $strategies[0] );
	}

	public function test_jetpack_has_no_strategy_here(): void {
		// Jetpack's suppression predates this seam and lives in
		// WC_AI_Storefront_Meta_Tags. Adding it here as well would remove the
		// same action twice, from two places, with no owner.
		$this->assertSame(
			array(),
			WC_AI_Storefront_Og_Strategies::for_slugs( array( 'jetpack' ) )
		);
	}

	public function test_init_registers_nothing_when_no_strategy_applies(): void {
		Actions\expectAdded( 'template_redirect' )->never();
		WC_AI_Storefront_Og_Strategies::init_for_slugs( array(), $this->gate() );
	}

	public function test_yoast_yields_its_own_strategy(): void {
		$strategies = WC_AI_Storefront_Og_Strategies::for_slugs( array( 'yoast' ) );
		$this->assertCount( 1, $strategies );
		$this->assertInstanceOf( WC_AI_Storefront_Og_Strategy_Yoast::class, $strategies[0] );
	}

	// --- Who prints the tags ---

	public function test_nothing_is_delegated_when_no_strategy_is_registered(): void {
		WC_AI_Storefront_Og_Strategies::reset();
		$this->assertFalse( WC_AI_Storefront_Og_Strategies::emission_is_delegated() );
	}

	public function test_a_suppressing_strategy_leaves_emission_with_us(): void {
		// SEOPress's tags are the ones being removed, so ours are the only
		// ones left to print.
		WC_AI_Storefront_Og_Strategies::init_for_slugs( array( 'seopress' ), $this->gate() );
		$this->assertFalse( WC_AI_Storefront_Og_Strategies::emission_is_delegated() );
	}

	public function test_an_enriching_strategy_does_not_take_over_until_observed(): void {
		// Registering is not emitting. Yoast with its Open Graph switch off
		// never reaches its presenter filter, and standing our block down for
		// it leaves the page with no social tags at all (#676 review).
		WC_AI_Storefront_Og_Strategies::init_for_slugs( array( 'yoast' ), $this->gate() );
		$this->assertFalse( WC_AI_Storefront_Og_Strategies::emission_is_delegated() );
	}

	public function test_an_enriching_strategy_takes_emission_over_once_it_has_run(): void {
		// Yoast rendered; we corrected its type and filled its gaps through
		// its own pipeline. Printing our block as well is the duplication
		// #676 exists to remove.
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product' )->justReturn( false );
		$strategy = new WC_AI_Storefront_Og_Strategy_Yoast();
		$strategy->init( $this->gate() );
		$strategy->filter_presenters( array() );

		WC_AI_Storefront_Og_Strategies::register_for_test( array( $strategy ) );

		$this->assertTrue( WC_AI_Storefront_Og_Strategies::emission_is_delegated() );
	}

	public function test_registration_replaces_rather_than_accumulates(): void {
		// Static state on a class that outlives a request. The strategy handed
		// over here has ALREADY observed its seam, so delegation would be true
		// if the array accumulated — without that, the observation latch made
		// this test pass either way (#676 review).
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product' )->justReturn( false );
		$observed = new WC_AI_Storefront_Og_Strategy_Yoast();
		$observed->init( $this->gate() );
		$observed->filter_presenters( array() );
		WC_AI_Storefront_Og_Strategies::register_for_test( array( $observed ) );

		WC_AI_Storefront_Og_Strategies::init_for_slugs( array( 'seopress' ), $this->gate() );

		$this->assertFalse(
			WC_AI_Storefront_Og_Strategies::emission_is_delegated(),
			'A previous request\'s enriching strategy must not answer for this one.'
		);
	}

	public function test_every_registered_strategy_is_initialised(): void {
		// A store can run two SEO plugins. Initialising only the first leaves
		// the second with no hooks at all, and nothing said so.
		Filters\expectAdded( 'wpseo_frontend_presenters' )->once();
		Filters\expectAdded( 'aioseo_facebook_tags' )->once();

		WC_AI_Storefront_Og_Strategies::init_for_slugs( array( 'yoast', 'aioseo' ), $this->gate() );
	}

	public function test_one_enricher_standing_down_does_not_cancel_another(): void {
		// AIOSEO never latches on a product category. If delegation required
		// EVERY enricher to have taken over, Yoast's genuine takeover would be
		// cancelled there and both blocks would print.
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product' )->justReturn( false );

		$taken = new WC_AI_Storefront_Og_Strategy_Yoast();
		$taken->init( $this->gate() );
		$taken->filter_presenters( array() );

		$silent = new WC_AI_Storefront_Og_Strategy_Aioseo();
		$silent->init( $this->gate() );

		foreach ( array( array( $taken, $silent ), array( $silent, $taken ) ) as $order ) {
			WC_AI_Storefront_Og_Strategies::register_for_test( $order );
			$this->assertTrue(
				WC_AI_Storefront_Og_Strategies::emission_is_delegated(),
				'Order must not decide this.'
			);
		}
	}

	public function test_seopress_never_delegates_emission(): void {
		// mode() and has_taken_over() were covering for each other: each could
		// be broken alone without a failure.
		$this->assertSame(
			WC_AI_Storefront_Og_Strategy::MODE_SUPPRESS,
			WC_AI_Storefront_Og_Strategy_Seopress::mode()
		);

		$strategy = new WC_AI_Storefront_Og_Strategy_Seopress();
		$strategy->init( $this->gate() );
		$this->assertFalse( $strategy->has_taken_over() );
	}

	// --- SEOPress ---

	public function test_seopress_defers_registration_to_template_redirect(): void {
		// SEOPress registers its 16 social callbacks lazily, from
		// seopress_load_social_options at wp_head:0. A remover added at file
		// scope runs before that loader and silently removes nothing, so the
		// remover itself has to be registered later than plugin load.
		Actions\expectAdded( 'template_redirect' )->once();
		( new WC_AI_Storefront_Og_Strategy_Seopress() )->init( $this->gate() );
	}

	public function test_seopress_removes_at_wp_head_zero(): void {
		// Priority 0 registered from template_redirect puts us LAST in the
		// priority-0 bucket, after SEOPress's loader and a full priority
		// before its wp_head:1 emitters. Priority 1 would report success and
		// emit anyway: WP_Hook::apply_filters copies the bucket at loop entry,
		// so a same-priority removal made mid-bucket has no effect.
		Actions\expectAdded( 'wp_head' )->once()->with( \Mockery::any(), 0 );
		( new WC_AI_Storefront_Og_Strategy_Seopress() )->register_removal();
	}

	public function test_seopress_removes_every_social_callback_at_priority_one(): void {
		$removed = array();
		Functions\when( 'remove_action' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$removed ) {
				$removed[] = array( $hook, $callback, $priority );
				return true;
			}
		);

		$strategy = new WC_AI_Storefront_Og_Strategy_Seopress();
		$strategy->init( $this->gate() );
		$strategy->remove_social_tags();

		$this->assertCount( 16, $removed, 'SEOPress emits one callback per social tag.' );
		foreach ( $removed as $call ) {
			$this->assertSame( 'wp_head', $call[0] );
			$this->assertSame( 1, $call[2], 'SEOPress registers all of them at wp_head:1.' );
			$this->assertStringStartsWith( 'seopress_social_', $call[1] );
		}
	}

	public function test_seopress_leaves_its_title_and_description_callbacks_alone(): void {
		$removed = array();
		Functions\when( 'remove_action' )->alias(
			static function ( $hook, $callback ) use ( &$removed ) {
				$removed[] = $callback;
				return true;
			}
		);

		$strategy = new WC_AI_Storefront_Og_Strategy_Seopress();
		$strategy->init( $this->gate() );
		$strategy->remove_social_tags();

		// Title, canonical, robots and description are seopress_titles_*
		// callbacks at the same priority, and the JSON-LD schema is at
		// wp_head:2. Taking any of them would strip a page SEOPress is
		// otherwise handling correctly; #669 already settled that the
		// description is theirs to write when they write one.
		foreach ( $removed as $callback ) {
			$this->assertStringNotContainsString( 'seopress_titles', $callback );
			$this->assertStringNotContainsString( 'schema', $callback );
		}
	}

	public function test_seopress_removes_nothing_on_a_product_search(): void {
		// should_emit() is true on product search, but render_head_tags()
		// prints no Open Graph there — no single product or term to describe,
		// and the result set differs per visitor (#668). Gating the removal on
		// the wider predicate stripped SEOPress's card off that page and put
		// nothing back, leaving it barer than with this plugin uninstalled.
		$this->on_commerce_page = false;
		$removed                = 0;
		Functions\when( 'remove_action' )->alias(
			static function () use ( &$removed ) {
				++$removed;
				return true;
			}
		);

		$strategy = new WC_AI_Storefront_Og_Strategy_Seopress();
		$strategy->init( $this->gate() );
		$strategy->remove_social_tags();

		$this->assertSame( 0, $removed );
	}

	public function test_seopress_keeps_going_when_one_removal_fails(): void {
		// remove_action() returning false means that callback is not
		// registered under the name and priority we expect. Stopping there
		// would leave the remaining fifteen on the page. The count feeds a
		// debug line, which this suite cannot assert on — error_log() is an
		// internal function Patchwork will not redefine — so what is pinned
		// here is that every callback is still attempted.
		$attempted = array();
		Functions\when( 'remove_action' )->alias(
			static function ( $hook, $callback ) use ( &$attempted ) {
				$attempted[] = $callback;
				return 'seopress_social_twitter_img_hook' !== $callback;
			}
		);

		$strategy = new WC_AI_Storefront_Og_Strategy_Seopress();
		$strategy->init( $this->gate() );
		$strategy->remove_social_tags();

		$this->assertCount( 16, $attempted );
		$this->assertContains( 'seopress_social_facebook_app_id_hook', $attempted, 'The last name must still be attempted.' );
	}

	public function test_seopress_removes_nothing_off_a_commerce_page(): void {
		$this->on_commerce_page = false;
		$removed                = 0;
		Functions\when( 'remove_action' )->alias(
			static function () use ( &$removed ) {
				++$removed;
				return true;
			}
		);

		$strategy = new WC_AI_Storefront_Og_Strategy_Seopress();
		$strategy->init( $this->gate() );
		$strategy->remove_social_tags();

		$this->assertSame( 0, $removed, 'Off commerce pages SEOPress keeps describing what we do not handle.' );
	}

	public function test_seopress_asks_the_gate_at_removal_time_not_at_init(): void {
		// The page type is not knowable when init() runs — it fires long
		// before the query is resolved. A strategy that resolved the gate
		// eagerly would answer for the wrong request.
		$strategy = new WC_AI_Storefront_Og_Strategy_Seopress();

		$this->on_commerce_page = false;
		$strategy->init( $this->gate() );

		$removed = 0;
		Functions\when( 'remove_action' )->alias(
			static function () use ( &$removed ) {
				++$removed;
				return true;
			}
		);

		$this->on_commerce_page = true;
		$strategy->remove_social_tags();

		$this->assertSame( 16, $removed );
	}

	public function test_seopress_declares_the_slug_the_detector_reports(): void {
		// The dispatcher matches on this string, and the detector produces it.
		// A drift between the two disables the strategy silently.
		$this->assertSame( 'seopress', WC_AI_Storefront_Og_Strategy_Seopress::slug() );
	}
}
