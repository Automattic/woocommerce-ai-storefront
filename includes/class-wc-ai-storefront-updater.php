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

		// Track GitHub releases (tagged, published) rather than
		// branch HEAD. Merchants should only ever see versions we
		// have explicitly shipped.
		$api = $checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( self::RELEASE_ASSET_PATTERN );
		}

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
}
