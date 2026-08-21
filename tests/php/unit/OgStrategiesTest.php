<?php
/**
 * Tests for the Open Graph strategy seam and its SEOPress implementation.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Actions;
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
		Actions\expectAdded( 'wp_head' )->once()->with( \Mockery::type( 'array' ), 0 );
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
