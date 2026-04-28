<?php
/**
 * WooCommerce AI Syndication - Self-Updater.
 *
 * Wires the Plugin Update Checker (PUC) library to the WordPress
 * plugin update UI so merchants receive update notifications and
 * one-click updates in wp-admin — the same UX as WP.org-hosted
 * plugins, but sourced from GitHub releases.
 *
 * Why this exists
 * ---------------
 * The plugin ships via GitHub releases, not the WP.org directory.
 * Without a self-updater each upgrade is a manual zip re-upload, and
 * merchants who download the auto-generated "Source code (zip)" from
 * a GitHub release get a directory named `{repo}-{ref}/` instead of
 * `woocommerce-ai-storefront/`. WordPress treats that as a new
 * plugin and the old copy stays installed, compounding the problem.
 *
 * Wiring the updater lets WordPress fetch the canonical release
 * asset (`woocommerce-ai-storefront.zip`) produced by the release
 * workflow, which already extracts to the correct slug directory.
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin with the PUC library to enable in-place
 * updates from GitHub releases.
 */
class WC_AI_Storefront_Updater {

	/**
	 * GitHub repository URL used as the update source.
	 *
	 * PUC reads the GitHub releases feed for this repo and exposes
	 * any release whose version exceeds the installed `Version:`
	 * header as an available update.
	 */
	const GITHUB_REPO_URL = 'https://github.com/Automattic/woocommerce-ai-storefront';

	/**
	 * Release asset filename pattern PUC should prefer.
	 *
	 * Matches the zip our release workflow attaches to every tag
	 * (`woocommerce-ai-storefront-v1.4.0.zip`). Without this PUC
	 * falls back to the source-code zip — which extracts to a
	 * repo-named directory and breaks in-place upgrades.
	 */
	const RELEASE_ASSET_PATTERN = '/woocommerce-ai-storefront-v?[\d\.]+\.zip/';

	/**
	 * Whether the updater has been wired. Guards against
	 * double-registration if the class is re-included.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize the updater.
	 *
	 * Safe to call more than once; subsequent calls are no-ops.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// The PUC library is optional — if the lib/ directory was
		// stripped from a hand-rolled build we degrade gracefully
		// rather than fataling the whole plugin.
		$loader = WC_AI_STOREFRONT_PLUGIN_PATH . '/includes/lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $loader ) ) {
			return;
		}
		require_once $loader;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::GITHUB_REPO_URL,
			WC_AI_STOREFRONT_PLUGIN_FILE,
			'woocommerce-ai-storefront'
		);

		// Set the branch explicitly. PUC defaults to `master` when no
		// branch is configured, but this repo (and most modern GitHub
		// repos) uses `main`. Without this override, PUC's secondary
		// branch-metadata fetch hits `/branches/master` and 404s. The
		// 404 is masked as a 403 under GitHub's anonymous rate-limit
		// throttle, producing a misleading "rate-limited" error in the
		// admin notice when the actual cause is a configuration miss.
		if ( method_exists( $checker, 'setBranch' ) ) {
			$checker->setBranch( 'main' );
		}

		// Configure the GitHub API instance: pin to release assets
		// and opt pre-releases into the eligible-update set. Extracted
		// to a helper for unit-testability — the helper takes the api
		// object as a parameter so a fake stub can record the
		// configuration calls without booting the full PUC factory.
		self::configure_github_api( $checker->getVcsApi() );

		/**
		 * Authenticate the update-check requests against GitHub.
		 *
		 * Anonymous GitHub API requests are rate-limited to 60/hour
		 * per IP. A merchant hitting that limit (shared hosting,
		 * frequent dashboard refreshes) sees a 403 and an admin
		 * notice "Could not determine if updates are available."
		 *
		 * Filter: `wc_ai_storefront_github_token` — return a GitHub
		 * personal access token (no scopes needed for public repos)
		 * to authenticate update-checker calls. Authenticated
		 * requests are 5,000/hour.
		 *
		 * @param string $token GitHub personal access token.
		 *                       Default empty (anonymous).
		 */
		$github_token = apply_filters( 'wc_ai_storefront_github_token', '' );
		if ( $github_token && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( $github_token );
		}
	}

	/**
	 * Configure PUC's GitHub API instance.
	 *
	 * Two pieces of configuration:
	 *
	 * 1. **Release-asset pattern.** Pins PUC to the canonical zip our
	 *    release workflow attaches to every tag, rather than the
	 *    source-code zip GitHub auto-generates (which extracts to a
	 *    repo-named directory and breaks in-place upgrades).
	 *
	 * 2. **Pre-release inclusion via `setReleaseVersionFilter`.** PUC's
	 *    default `STRATEGY_LATEST_RELEASE` calls GitHub's
	 *    `/releases/latest` endpoint, which only returns the most
	 *    recent NON-prerelease release (a GitHub-side filter, not
	 *    configurable on the API). Our distribution model marks every
	 *    GitHub release as Pre-release until the plugin lands on
	 *    WP.org's stable directory, so `/releases/latest` returns 404
	 *    and PUC falls through to `/tags` and `/branches/main`.
	 *    Anonymous calls to those endpoints either rate-limit
	 *    (60/hr/IP) or 403 in some host configurations — surfacing as
	 *    the merchant-facing "Could not determine if updates are
	 *    available" notice even though the latest release is sitting
	 *    right there. `setReleaseVersionFilter` makes PUC enumerate
	 *    `/releases` (not `/releases/latest`) and filter by regex; the
	 *    `RELEASE_FILTER_ALL` flag opts pre-releases in.
	 *
	 *    The semver regex pins the tag shape we ship — `vX.Y.Z` only.
	 *    PUC strips the `v` prefix before matching, so the pattern
	 *    matches `X.Y.Z`. Any non-semver tag (a draft branch tag, a
	 *    hotfix tag with a suffix) is skipped — defensive against
	 *    accidentally publishing a non-version tag and prompting every
	 *    merchant to "upgrade" to it.
	 *
	 *    The max-releases bound (50) caps how many recent releases PUC
	 *    scans — well above current ship cadence (~20) and leaves
	 *    headroom for non-semver tags accumulating above the latest
	 *    release in future hotfix scenarios.
	 *
	 *    The filter constant is read from the live PUC API class via
	 *    `defined()` lookup with a fall-through literal `3`, so a
	 *    future PUC bundle update that renumbers the values can't
	 *    silently mismatch our intent. The constant lives on
	 *    `Puc\v5p6\Vcs\Api`; `GitHubApi` extends it, so the inherited-
	 *    constant lookup resolves through `get_class( $api )`.
	 *
	 * Extracted from `init()` so unit tests can drive the configuration
	 * with a fake api stub (recording calls) rather than mocking the
	 * full PUC factory.
	 *
	 * @internal Public for testability; not intended as an extension
	 *           point for outside callers.
	 *
	 * @param object|null $api PUC GitHub API instance returned by
	 *                         `$checker->getVcsApi()`. Null when the
	 *                         factory boot failed; the helper no-ops.
	 * @return void
	 */
	public static function configure_github_api( $api ) {
		if ( ! $api ) {
			return;
		}

		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( self::RELEASE_ASSET_PATTERN );
		}

		if ( method_exists( $api, 'setReleaseVersionFilter' ) ) {
			$release_filter_all = defined( get_class( $api ) . '::RELEASE_FILTER_ALL' )
				? $api::RELEASE_FILTER_ALL
				: 3;

			$api->setReleaseVersionFilter(
				'/^\d+\.\d+\.\d+$/',
				$release_filter_all,
				50
			);
		}
	}
}
