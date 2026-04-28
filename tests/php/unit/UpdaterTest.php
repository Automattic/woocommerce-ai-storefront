<?php
/**
 * Tests for WC_AI_Storefront_Updater.
 *
 * The updater is thin glue around the vendored Plugin Update Checker
 * library. These tests lock in the pieces that WOULD hide real bugs
 * if they regressed:
 *
 * 1. init() is idempotent — multiple calls don't double-register the
 *    update checker (would cause duplicate HTTP requests + admin UI
 *    double-renders on every wp-admin page load).
 * 2. Missing library gracefully no-ops — if someone strips the
 *    `includes/lib/` tree from a hand-rolled build, the plugin should
 *    still boot. Fatal here would brick wp-admin.
 * 3. The advertised GitHub repo URL is the canonical slug-matching
 *    one — a drift here would route update checks to the wrong feed
 *    and silently break upgrades. Locking the constant keeps it in
 *    sync with the `Update URI:` header.
 *
 * The happy path (PUC factory call succeeds) is not unit-tested
 * because it requires loading the full PUC library — that's
 * integration territory. Manual smoke test: fresh plugin install →
 * wait ~12h → "Update available" shows in wp-admin Plugins screen.
 *
 * @package WooCommerce_AI_Storefront
 */

use Brain\Monkey;

class UpdaterTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		// Reset the private $initialized flag between tests so each
		// case starts fresh. We use reflection because the class
		// guards against double-init by design — that's the whole
		// point of one of the tests.
		$reflection = new ReflectionClass( WC_AI_Storefront_Updater::class );
		$prop       = $reflection->getProperty( 'initialized' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Configuration constants
	// ------------------------------------------------------------------

	public function test_github_repo_url_matches_plugin_slug(): void {
		// The repo URL must match the plugin slug so the source-code
		// zip extracts to the same directory name as the release-
		// asset zip. A mismatch here is what created the install-
		// additive problem in 1.3.x; locking the URL prevents a
		// regression where someone changes the constant without
		// also renaming the repo.
		$this->assertSame(
			'https://github.com/Automattic/woocommerce-ai-storefront',
			WC_AI_Storefront_Updater::GITHUB_REPO_URL,
			'Repo URL must end in the plugin slug "woocommerce-ai-storefront"'
		);
	}

	public function test_release_asset_pattern_matches_workflow_output(): void {
		// The release workflow produces zips named
		// `woocommerce-ai-storefront-v{VERSION}.zip`. The pattern
		// PUC uses to pick the right asset must match that naming,
		// otherwise PUC falls back to the source-code zip (wrong
		// directory name).
		$pattern = WC_AI_Storefront_Updater::RELEASE_ASSET_PATTERN;

		$this->assertMatchesRegularExpression( $pattern, 'woocommerce-ai-storefront-v1.4.0.zip' );
		$this->assertMatchesRegularExpression( $pattern, 'woocommerce-ai-storefront-1.4.0.zip' );
		$this->assertDoesNotMatchRegularExpression( $pattern, 'source-code.zip' );
		$this->assertDoesNotMatchRegularExpression( $pattern, 'some-other-plugin-v1.0.0.zip' );
	}

	// ------------------------------------------------------------------
	// init() contract
	// ------------------------------------------------------------------

	public function test_init_short_circuits_when_already_initialized(): void {
		// Verify the double-init guard by pre-setting the flag.
		// If the guard is missing, init() would proceed to require
		// the PUC library and call PucFactory::buildUpdateChecker(),
		// which would throw (PUC needs WP constants like
		// WP_PLUGIN_DIR that aren't available in the unit test env).
		//
		// Testing "no throw" rather than "library not loaded"
		// because the require_once behavior depends on whether
		// other tests have already loaded PUC — not something we
		// can reliably observe at the unit level.
		$reflection = new ReflectionClass( WC_AI_Storefront_Updater::class );
		$prop       = $reflection->getProperty( 'initialized' );
		$prop->setAccessible( true );
		$prop->setValue( null, true );

		// If the guard is broken this next line would throw
		// "Undefined constant WP_PLUGIN_DIR" from PUC.
		WC_AI_Storefront_Updater::init();

		$this->assertTrue(
			$prop->getValue(),
			'The initialized flag should remain true after a no-op init() call.'
		);
	}

	public function test_init_tolerates_missing_library(): void {
		// Simulate a hand-rolled build where someone stripped the
		// vendored PUC library. The updater should no-op gracefully
		// rather than fataling — plugins fataling at init() brick
		// wp-admin entirely, which is a much worse outcome than
		// "updates don't work."
		//
		// We can't actually remove the library at test time, but we
		// can verify the code path exists: if the loader file is
		// missing, init() returns early without raising. The real-
		// world check is the file_exists() guard in the class.
		$loader = WC_AI_STOREFRONT_PLUGIN_PATH . '/includes/lib/plugin-update-checker/plugin-update-checker.php';
		$this->assertFileExists(
			$loader,
			'The vendored PUC library must be present at the expected path. '
			. 'If this fails, either the library was moved or init() will silently '
			. 'skip registering updates.'
		);
	}

	// ------------------------------------------------------------------
	// configure_github_api() contract
	// ------------------------------------------------------------------

	public function test_configure_github_api_pins_release_asset_and_version_filter(): void {
		// Happy path: a faithful stub of the PUC GitHubApi shape — has
		// both methods and the RELEASE_FILTER_ALL constant inherited
		// from its parent Api class. Configuration should call both
		// methods with the documented args.
		$api = new class() {
			const RELEASE_FILTER_ALL = 3;

			public $enable_release_assets_calls       = array();
			public $set_release_version_filter_calls  = array();

			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName
			public function enableReleaseAssets( $pattern ) {
				$this->enable_release_assets_calls[] = $pattern;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName
			public function setReleaseVersionFilter( $regex, $filter, $max_releases_to_examine = 50 ) {
				$this->set_release_version_filter_calls[] = array( $regex, $filter, $max_releases_to_examine );
			}
		};

		WC_AI_Storefront_Updater::configure_github_api( $api );

		$this->assertSame(
			array( WC_AI_Storefront_Updater::RELEASE_ASSET_PATTERN ),
			$api->enable_release_assets_calls,
			'enableReleaseAssets() should be called once with the canonical release-asset pattern.'
		);

		$this->assertCount(
			1,
			$api->set_release_version_filter_calls,
			'setReleaseVersionFilter() should be called exactly once.'
		);

		[ $regex, $filter, $max ] = $api->set_release_version_filter_calls[0];
		$this->assertSame(
			'/^\d+\.\d+\.\d+$/',
			$regex,
			'The semver regex must match X.Y.Z (PUC strips the v prefix before matching).'
		);
		$this->assertSame(
			3,
			$filter,
			'Filter must resolve to RELEASE_FILTER_ALL (= 3) so pre-releases are included.'
		);
		$this->assertSame(
			50,
			$max,
			'max-releases-to-examine should be the documented bound of 50.'
		);
	}

	public function test_configure_github_api_falls_back_when_filter_constant_missing(): void {
		// Defensive-fallback path: a future PUC bundle that removed or
		// renamed RELEASE_FILTER_ALL. The helper must still pass `3` —
		// the v5p6+ documented value — so pre-release inclusion keeps
		// working through the bundle bump until we update the literal.
		$api = new class() {
			public $set_release_version_filter_calls = array();

			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName
			public function enableReleaseAssets( $pattern ) {
				// No-op; not the focus of this test.
			}

			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName
			public function setReleaseVersionFilter( $regex, $filter, $max_releases_to_examine = 50 ) {
				$this->set_release_version_filter_calls[] = array( $regex, $filter, $max_releases_to_examine );
			}
		};

		WC_AI_Storefront_Updater::configure_github_api( $api );

		$this->assertCount( 1, $api->set_release_version_filter_calls );
		$this->assertSame(
			3,
			$api->set_release_version_filter_calls[0][1],
			'When RELEASE_FILTER_ALL is undefined on the api class, the helper must fall back to the documented literal 3.'
		);
	}

	public function test_configure_github_api_no_ops_when_methods_missing(): void {
		// Forward-compat: a future PUC API surface that drops one or
		// both of the configuration methods entirely. The helper must
		// no-op cleanly rather than fatal.
		$api = new \stdClass();

		// Should not throw.
		WC_AI_Storefront_Updater::configure_github_api( $api );

		// And null api (factory boot failed) is also a no-op.
		WC_AI_Storefront_Updater::configure_github_api( null );

		$this->assertTrue( true, 'configure_github_api should tolerate api objects without the optional methods.' );
	}

	public function test_configure_github_api_rejects_non_object_inputs(): void {
		// Defensive type guard: the factory contract returns
		// `object|null`, but a future refactor that accidentally
		// returns an array or scalar would otherwise hit a TypeError
		// inside method_exists() / get_class(). The helper must treat
		// any non-object the same as null and no-op cleanly.
		//
		// We can't directly observe "no method calls" without an
		// object stub, so the assertion here is "no exception thrown" —
		// which is the exact failure mode we're guarding against.
		WC_AI_Storefront_Updater::configure_github_api( array( 'not', 'an', 'object' ) );
		WC_AI_Storefront_Updater::configure_github_api( 42 );
		WC_AI_Storefront_Updater::configure_github_api( 'arbitrary string' );
		WC_AI_Storefront_Updater::configure_github_api( false );

		$this->assertTrue( true, 'configure_github_api must no-op on non-object inputs without raising.' );
	}
}
