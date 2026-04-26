<?php
/**
 * Tests for WC_AI_Storefront_Admin_Controller::get_policy_pages().
 *
 * Contract test: the frontend Policies tab's page-link dropdown
 * relies on a specific response shape (mirroring `/wp/v2/pages`):
 * `[ { id, title: { rendered }, link } ]`. An accidental shape change
 * would silently blank the dropdown without any other test failure
 * — this file locks the contract.
 *
 * Also pins the merchant-facing intent: WC system pages (Cart,
 * Checkout, My Account, Shop) are excluded so they can't be picked
 * as a return-policy link by accident. Privacy / Terms / Refund
 * pages are kept because merchants legitimately link them.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AdminPolicyPagesTest extends \PHPUnit\Framework\TestCase {
	use MockeryPHPUnitIntegration;

	private WC_AI_Storefront_Admin_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->controller = new WC_AI_Storefront_Admin_Controller();

		// `the_title` filter passthrough — production WP runs the
		// title through plugin filters here. Identity passthrough is
		// fine for unit tests; specific filter behaviors (entity
		// decoding, shortcode stripping) are WP/plugin concerns
		// covered by their own tests.
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => "https://example.com/?p={$id}"
		);

		// `__()` passthrough: tests don't depend on translation, just
		// the literal English string the WP_Error payload carries.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a stdClass page record mirroring WP's `get_pages()` output.
	 * The controller treats it as opaque + only reads `ID` + `post_title`,
	 * so we set only those two.
	 */
	private function make_page( int $id, string $title ): \stdClass {
		$page             = new \stdClass();
		$page->ID         = $id;
		$page->post_title = $title;
		return $page;
	}

	// ------------------------------------------------------------------
	// Contract: response shape exactly matches `/wp/v2/pages`
	// ------------------------------------------------------------------

	public function test_response_shape_matches_wp_v2_pages(): void {
		// JS at the call site does `p.title?.rendered || p.title` and
		// reads `p.id` + `p.link`. If any of those keys rename or
		// nest differently, the dropdown blanks silently. Lock the
		// shape here so a future refactor breaks loudly.
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_pages' )->justReturn( [
			$this->make_page( 100, 'Privacy Policy' ),
		] );

		$response = $this->controller->get_policy_pages();
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$row = $data[0];
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'title', $row );
		$this->assertArrayHasKey( 'rendered', $row['title'] );
		$this->assertArrayHasKey( 'link', $row );
		$this->assertSame( 100, $row['id'] );
		$this->assertSame( 'Privacy Policy', $row['title']['rendered'] );
		$this->assertSame( 'https://example.com/?p=100', $row['link'] );
	}

	// ------------------------------------------------------------------
	// Contract: WC system pages are excluded
	// ------------------------------------------------------------------

	public function test_excludes_wc_system_pages(): void {
		// `wc_get_page_id()` is the canonical way to identify WC's
		// configured Cart/Checkout/My Account/Shop — survives merchant
		// renames because it reads the actual page IDs from WC settings.
		// All four must end up in the `exclude` arg passed to `get_pages()`.
		Functions\when( 'wc_get_page_id' )->alias(
			static fn( $slug ) => match ( $slug ) {
				'cart'      => 10,
				'checkout'  => 20,
				'myaccount' => 30,
				'shop'      => 40,
				default     => 0,
			}
		);

		$captured_args = null;
		Functions\when( 'get_pages' )->alias(
			static function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [];
			}
		);

		$this->controller->get_policy_pages();

		$this->assertSame(
			[ 10, 20, 30, 40 ],
			$captured_args['exclude'] ?? null,
			'`exclude` must contain the canonical Cart/Checkout/MyAccount/Shop page IDs.'
		);
	}

	public function test_excludes_skip_unconfigured_pages(): void {
		// `wc_get_page_id()` returns -1 for unconfigured pages and 0
		// when never set. Both must be filtered out of the exclude
		// list — passing -1 or 0 to `get_pages()`'s exclude would
		// either fatal or silently exclude unrelated post IDs.
		Functions\when( 'wc_get_page_id' )->alias(
			static fn( $slug ) => match ( $slug ) {
				'cart'      => 10,
				'checkout'  => -1, // Unconfigured.
				'myaccount' => 0,  // Never set.
				'shop'      => 40,
				default     => 0,
			}
		);

		$captured_args = null;
		Functions\when( 'get_pages' )->alias(
			static function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [];
			}
		);

		$this->controller->get_policy_pages();

		$this->assertSame(
			[ 10, 40 ],
			$captured_args['exclude'] ?? null,
			'Only positive page IDs should land in `exclude`; -1 (unconfigured) and 0 (never set) must be skipped.'
		);
	}

	public function test_handles_fresh_install_with_no_wc_pages_configured(): void {
		// On a fresh install where WC hasn't been set up yet, every
		// `wc_get_page_id()` returns 0. The exclude list is empty;
		// `get_pages()` should still be called with `exclude => []`
		// and not fatal.
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );

		// Build the page outside the closure (closures must be static
		// per Brain Monkey + project convention; static closures can't
		// access `$this`).
		$page = $this->make_page( 100, 'About' );

		$captured_args = null;
		Functions\when( 'get_pages' )->alias(
			static function ( $args ) use ( &$captured_args, $page ) {
				$captured_args = $args;
				return [ $page ];
			}
		);

		$response = $this->controller->get_policy_pages();
		$data     = $response->get_data();

		$this->assertSame( [], $captured_args['exclude'] ?? null );
		$this->assertCount( 1, $data );
		$this->assertSame( 100, $data[0]['id'] );
	}

	// ------------------------------------------------------------------
	// Contract: only published pages are returned
	// ------------------------------------------------------------------

	public function test_passes_post_status_publish_to_get_pages(): void {
		// Drafts / trashed pages must not appear as selectable policy
		// links — the server-side JSON-LD emitter drops the
		// merchantReturnLink for non-published pages, so the dropdown
		// must not surface them as valid options.
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );

		$captured_args = null;
		Functions\when( 'get_pages' )->alias(
			static function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [];
			}
		);

		$this->controller->get_policy_pages();

		$this->assertSame( 'publish', $captured_args['post_status'] ?? null );
	}

	// ------------------------------------------------------------------
	// Contract: DB failure surfaces as WP_Error, not silent empty
	// ------------------------------------------------------------------

	public function test_returns_wp_error_when_get_pages_fails(): void {
		// `get_pages()` returns `false` on DB error. The endpoint
		// must surface that as a WP_Error with status 500 so the JS
		// pagesError state lights up — silent empty would render
		// identically to "no policy-eligible pages exist," which a
		// merchant has no traceable signal to debug.
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_pages' )->justReturn( false );

		$result = $this->controller->get_policy_pages();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'wc_ai_storefront_pages_query_failed',
			$result->get_error_code()
		);
		$this->assertSame(
			500,
			$result->get_error_data()['status'] ?? null
		);
	}

	public function test_empty_pages_list_returns_empty_array_not_error(): void {
		// Distinguishes from the WP_Error case above: an empty array
		// from get_pages() is legitimate ("no eligible pages exist
		// yet"), not a DB failure. The endpoint must return [] with
		// 200 OK, not WP_Error.
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_pages' )->justReturn( [] );

		$response = $this->controller->get_policy_pages();

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$this->assertSame( [], $response->get_data() );
	}

	// ------------------------------------------------------------------
	// Title is filtered through `the_title` for /wp/v2/pages parity
	// ------------------------------------------------------------------

	public function test_title_runs_through_the_title_filter(): void {
		// `/wp/v2/pages` returns titles under `title.rendered` AFTER
		// running through the `the_title` filter (entity decoding,
		// shortcode stripping, third-party plugin filtering). This
		// endpoint is a drop-in replacement, so it must apply the
		// same filter — otherwise a merchant's title containing
		// `&amp;` or shortcodes would render differently here than
		// in WP admin or `/wp/v2/pages`.
		$apply_filters_calls = [];
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$rest ) use ( &$apply_filters_calls ) {
				$apply_filters_calls[] = [ $hook, $value, $rest ];
				return $value;
			}
		);

		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		Functions\when( 'get_pages' )->justReturn( [
			$this->make_page( 100, 'Privacy & Cookies' ),
		] );

		$this->controller->get_policy_pages();

		$the_title_calls = array_filter(
			$apply_filters_calls,
			static fn( $call ) => 'the_title' === $call[0]
		);
		$this->assertNotEmpty(
			$the_title_calls,
			'Page titles must be passed through the `the_title` filter for /wp/v2/pages parity.'
		);
	}

	// ------------------------------------------------------------------
	// Contract: route registration (namespace + path + permission_callback)
	// ------------------------------------------------------------------

	public function test_policy_pages_route_is_registered_with_admin_permission(): void {
		// Mirrors AdminReturnPolicyTest's `/settings` registration test.
		// A regression that swaps the permission_callback to
		// `__return_true` would expose merchant page titles to anyone;
		// a regression that drops the route entirely would silently
		// blank the dropdown. Asserting the wiring catches both.
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = [
					'namespace' => $namespace,
					'args'      => $args,
				];
				return true;
			}
		);

		$controller = new WC_AI_Storefront_Admin_Controller();
		$controller->register_routes();

		$this->assertArrayHasKey(
			'/policy-pages',
			$registered,
			'`/policy-pages` route must be registered.'
		);
		$this->assertSame(
			WC_AI_Storefront_Admin_Controller::NAMESPACE,
			$registered['/policy-pages']['namespace'],
			'`/policy-pages` must register under the admin REST namespace.'
		);

		$args = $registered['/policy-pages']['args'];
		$this->assertSame(
			\WP_REST_Server::READABLE,
			$args['methods'],
			'`/policy-pages` must be GET-only — it returns merchant page metadata.'
		);
		$this->assertSame(
			[ $controller, 'check_admin_permission' ],
			$args['permission_callback'],
			'`/policy-pages` must use the admin permission gate, not `__return_true`.'
		);
		$this->assertSame(
			[ $controller, 'get_policy_pages' ],
			$args['callback'],
			'`/policy-pages` must dispatch to `get_policy_pages()`.'
		);
	}
}
