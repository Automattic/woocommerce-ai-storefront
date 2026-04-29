<?php
/**
 * WooCommerce AI Syndication - Main Plugin Class
 *
 * Orchestrates the Merchant-Led AI Syndicate:
 * - Discovery Layer (llms.txt, JSON-LD, robots.txt)
 * - Universal Commerce Protocol (UCP manifest)
 * - Product Catalog API with bot authentication
 * - Attribution via WooCommerce Order Attribution
 * - Cart synchronization via WooCommerce Store API
 *
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class (Singleton).
 */
class WC_AI_Storefront {

	/**
	 * Option key for syndication settings.
	 */
	const SETTINGS_OPTION = 'wc_ai_storefront_settings';

	/**
	 * Single source of truth for default values of every plugin setting.
	 *
	 * Used by get_settings() via wp_parse_args so any new key added here
	 * is automatically surfaced without touching the merge call. Note:
	 * wp_parse_args is shallow — it does not recurse into nested arrays,
	 * so the `return_policy` default array is only applied as a whole
	 * unit if the stored value omits that key entirely. The
	 * REST arg schema in the admin controller and the sanitization logic
	 * in update_settings() are separate concerns and are NOT collapsed
	 * here — they carry their own shape rules.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings_defaults(): array {
		return array(
			'enabled'                  => 'no',
			'product_selection_mode'   => 'all',
			'selected_categories'      => array(),
			'selected_tags'            => array(),
			'selected_brands'          => array(),
			'selected_products'        => array(),
			'rate_limit_rpm'           => 25,
			// UCP REST gate for unknown AI agents (hostnames not in
			// `KNOWN_AGENT_HOSTS`). Default `'no'` is secure-by-default
			// for both new installs and upgrades — `wp_parse_args`
			// merges this default into stored options on read, so
			// existing stores get `'no'` without a migration step.
			//
			// Scope: this flag is UCP-REST-only. The merchant's
			// `robots.txt` (`WC_AI_Storefront_Robots`) and the per-brand
			// `allowed_crawlers` list are independent mechanisms; do
			// NOT extend this flag to those surfaces — add siblings.
			//
			// See `WC_AI_Storefront_UCP_REST_Controller::check_agent_access()`
			// for the gate's full rationale + trade-off.
			'allow_unknown_ucp_agents' => 'no',
			// Return/refund policy exposed to AI agents at the
			// Offer level via `hasMerchantReturnPolicy`. Default
			// `unconfigured` mode emits NO policy block — until a
			// merchant opts into one of the explicit modes
			// (`returns_accepted` / `final_sale`) we never publish
			// a structurally invalid claim. See
			// `WC_AI_Storefront_JsonLd::build_return_policy_block()`
			// for the per-mode emission logic.
			'return_policy'            => array( 'mode' => 'unconfigured' ),
		);
	}

	/**
	 * Singleton instance.
	 *
	 * @var WC_AI_Storefront|null
	 */
	private static $instance = null;


	/**
	 * Returns the singleton instance.
	 *
	 * @return WC_AI_Storefront
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();

		// Rewrite rules, query vars, and cache invalidation register
		// unconditionally so they exist before syndication is enabled.
		// The serve callbacks check the enabled setting and return 404 if off.
		$this->register_rewrite_rules();

		$cache_invalidator = new WC_AI_Storefront_Cache_Invalidator();
		$cache_invalidator->init();

		$this->init_components();

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
		}
	}

	/**
	 * Load class files.
	 */
	private function load_dependencies() {
		$path = WC_AI_STOREFRONT_PLUGIN_PATH . '/includes/ai-storefront/';

		require_once $path . 'class-wc-ai-storefront-logger.php';
		require_once $path . 'class-wc-ai-storefront-return-policy.php';
		require_once $path . 'class-wc-ai-storefront-llms-txt.php';
		require_once $path . 'class-wc-ai-storefront-jsonld.php';
		require_once $path . 'class-wc-ai-storefront-robots.php';
		require_once $path . 'class-wc-ai-storefront-ucp.php';

		// UCP REST adapter module (1.3.0+). See PLAN-ucp-adapter.md.
		// Error codes must be loaded before the rate limiter (which references
		// WC_AI_Storefront_UCP_Error_Codes::UCP_RATE_LIMIT_EXCEEDED).
		$ucp_path = $path . 'ucp-rest/';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-error-codes.php';

		require_once $path . 'class-wc-ai-storefront-store-api-rate-limiter.php';
		require_once $path . 'class-wc-ai-storefront-attribution.php';
		require_once $path . 'class-wc-ai-storefront-cache-invalidator.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-agent-header.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-envelope.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-product-translator.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-variant-translator.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-request-context.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-dispatch-context.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-store-api-filter.php';
		require_once $ucp_path . 'class-wc-ai-storefront-store-api-extension.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-rest-controller.php';

		require_once WC_AI_STOREFRONT_PLUGIN_PATH . '/includes/admin/class-wc-ai-storefront-admin-controller.php';
		require_once WC_AI_STOREFRONT_PLUGIN_PATH . '/includes/admin/class-wc-ai-storefront-product-meta-box.php';
	}

	/**
	 * Initialize all components.
	 */
	public function init_components() {
		$settings = self::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			// Only load attribution (to track even when syndication is paused)
			// and admin controller (to allow enabling).
			$attribution = new WC_AI_Storefront_Attribution();
			$attribution->init();
			return;
		}

		// Discovery Layer.
		// Note: llms.txt and UCP rewrite rules are registered unconditionally
		// in register_rewrite_rules(). Here we only init the remaining components.
		$jsonld = new WC_AI_Storefront_JsonLd();
		$jsonld->init();

		$robots = new WC_AI_Storefront_Robots();
		$robots->init();

		// Attribution.
		$attribution = new WC_AI_Storefront_Attribution();
		$attribution->init();

		// Store API rate limiting for AI bots.
		$rate_limiter = new WC_AI_Storefront_Store_Api_Rate_Limiter();
		$rate_limiter->init();

		// UCP product-scoping hook — enforces the merchant's
		// `product_selection_mode` on product `WP_Query` instances
		// only when the UCP REST controller dispatches an inner Store
		// API request (via the `enter_ucp_dispatch()` /
		// `exit_ucp_dispatch()` markers). Other product queries
		// (front-end Cart, block-theme Checkout, themes, admin product
		// list, third-party plugins) are unaffected. The hook layer is
		// `pre_get_posts`; see the filter class's docblock for why a
		// global WP-level hook is the right place to apply UCP scope.
		$store_api_filter = new WC_AI_Storefront_UCP_Store_API_Filter();
		$store_api_filter->init();

		// Store API extension — surfaces WC core's `global_unique_id`
		// (GTIN/UPC/EAN/MPN) in the product response under
		// `extensions.{namespace}.barcodes`, which the UCP variant
		// translator reads from. WC core's Store API schema doesn't
		// expose `global_unique_id` yet; see the filed enhancement
		// request in woocommerce/woocommerce for the proposal to
		// remove this extension once core picks it up.
		$store_api_extension = new WC_AI_Storefront_Store_Api_Extension();
		$store_api_extension->init();

		// Per-product final-sale meta box (admin-only). Adds the
		// "AI: Final sale" checkbox to the WC product editor's
		// Inventory tab and persists the merchant's choice. The
		// checkbox is read by `WC_AI_Storefront_JsonLd::build_return_policy_block()`
		// at JSON-LD emission time to override the store-wide return
		// policy on a per-product basis. Frontend has no use for the
		// meta box — gate the registration so we don't pay the hook
		// cost on store visitor pageloads.
		if ( is_admin() ) {
			$product_meta_box = new WC_AI_Storefront_Product_Meta_Box();
			$product_meta_box->init();
		}
	}

	/**
	 * Register rewrite rules and serve callbacks unconditionally.
	 *
	 * The rewrite rules for /llms.txt and /.well-known/ucp must exist
	 * even before syndication is enabled, otherwise WordPress returns
	 * 404 until the next permalink flush. The serve callbacks already
	 * check the enabled setting and return 404 when syndication is off.
	 */
	private function register_rewrite_rules() {
		$llms_txt = new WC_AI_Storefront_Llms_Txt();
		$ucp      = new WC_AI_Storefront_Ucp();

		// Register rewrite rules on init (plugins_loaded fires before init).
		add_action( 'init', [ $llms_txt, 'add_rewrite_rules' ] );
		add_action( 'init', [ $ucp, 'add_rewrite_rules' ] );

		add_filter( 'query_vars', [ $llms_txt, 'add_query_vars' ] );
		add_filter( 'query_vars', [ $ucp, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $llms_txt, 'serve_llms_txt' ] );
		add_action( 'template_redirect', [ $ucp, 'serve_manifest' ] );

		// Suppress WordPress's trailing-slash canonical redirect for
		// the discovery endpoints. On sites with trailing-slash
		// permalink structures (the default on WordPress.com and
		// most self-hosted installs), core would otherwise 301 the
		// unslashed URL to a slashed variant that no longer matches
		// our rewrite rule — the redirected request falls through to
		// a 404 HTML page and AI browsing tools give up. The filter
		// returns false only when the corresponding query var is
		// set, so canonical behavior elsewhere on the site is
		// untouched.
		add_filter( 'redirect_canonical', [ $llms_txt, 'suppress_canonical_redirect' ], 10, 1 );
		add_filter( 'redirect_canonical', [ $ucp, 'suppress_canonical_redirect' ], 10, 1 );

		// Flush rewrite rules and bust content caches when needed:
		//
		// 1. After a plugin code update (stored version on disk differs
		//    from WC_AI_STOREFRONT_VERSION). This catches two install
		//    paths: in-place zip uploads AND remote auto-updates. It is
		//    critical that the activation hook does NOT pre-write the
		//    stored version — if it did, this branch would never detect
		//    a mismatch on in-place upgrades. See the comment on
		//    wc_ai_storefront_activate() for the full history.
		//
		// 2. After toggling syndication enabled/disabled (transient flag
		//    set by the admin controller).
		$needs_flush    = get_transient( 'wc_ai_storefront_flush_rewrite' );
		$stored_version = get_option( 'wc_ai_storefront_version', '' );

		if ( $needs_flush || $stored_version !== WC_AI_STOREFRONT_VERSION ) {
			delete_transient( 'wc_ai_storefront_flush_rewrite' );
			update_option( 'wc_ai_storefront_version', WC_AI_STOREFRONT_VERSION );

			// Self-healing flush: register the rules IMMEDIATELY (at the
			// current `plugins_loaded` hook, which is before WordPress
			// calls `parse_request`), then flush them to the rewrite
			// option so the active request can still resolve /llms.txt
			// and /.well-known/ucp without a second round-trip.
			//
			// Before 1.1.2 we only scheduled the flush on `init` priority
			// 99, which worked for the NEXT request but left the CURRENT
			// one 404-ing — exactly when a merchant had just upgraded
			// and hit the URL to verify the fix had taken effect. The
			// inline flush eliminates that race.
			//
			// `flush_rewrite_rules( false )` skips the .htaccess rewrite
			// (the in-DB option is sufficient for WP's parse_request
			// machinery), avoiding a filesystem write during request
			// handling.
			$llms_txt->add_rewrite_rules();
			$ucp->add_rewrite_rules();
			flush_rewrite_rules( false );

			// Also keep the deferred init-99 flush: if the current request
			// is an admin page load (plugins.php after update), the
			// rule-registration actions on `init` will run again, and this
			// second flush ensures the DB option is fully consistent with
			// the init-time registration path. Cheap belt-and-suspenders.
			add_action( 'init', 'flush_rewrite_rules', 99 );

			// Bust content caches on code updates so fixes to generation
			// logic (e.g., entity encoding) take effect immediately.
			// Uses host_cache_key() so the currently-serving Host gets
			// fresh content immediately; other virtual-host entries
			// expire at their natural 1-hour TTL.
			delete_transient( WC_AI_Storefront_Llms_Txt::host_cache_key() );
			// Legacy key — retained here for clean uninstall of pre-1.0 installs.
			delete_transient( 'wc_ai_storefront_ucp' );
		}
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$admin_controller = new WC_AI_Storefront_Admin_Controller();
		$admin_controller->register_routes();

		// UCP REST adapter routes at /wp-json/wc/ucp/v1/*. Registered
		// unconditionally — route handlers check settings.enabled and
		// return an appropriate UCP error envelope when syndication is
		// off, rather than 404 from missing registration. Matches the
		// pattern used for llms.txt / UCP manifest rewrite rules.
		$ucp_rest_controller = new WC_AI_Storefront_UCP_REST_Controller();
		$ucp_rest_controller->register_routes();
	}

	/**
	 * Add admin menu under WooCommerce.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'AI Storefront', 'woocommerce-ai-storefront' ),
			__( 'AI Storefront', 'woocommerce-ai-storefront' ),
			'manage_woocommerce',
			'wc-ai-storefront',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Render the admin settings page container.
	 */
	public function render_admin_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Storefront', 'woocommerce-ai-storefront' ) . '</h1>';
		echo '<div id="wc-ai-storefront-settings"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function admin_scripts( $hook_suffix ) {
		if ( 'woocommerce_page_wc-ai-storefront' !== $hook_suffix ) {
			return;
		}

		$asset_file = WC_AI_STOREFRONT_PLUGIN_PATH . '/build/ai-storefront-settings.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-data', 'wp-i18n' ],
				'version'      => WC_AI_STOREFRONT_VERSION,
			];

		wp_register_script(
			'wc-ai-storefront-settings',
			WC_AI_STOREFRONT_PLUGIN_URL . '/build/ai-storefront-settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Only register the stylesheet if the build produced one.
		//
		// Cache-busting note: the CSS file is produced out-of-band by
		// `scripts/copy-dataviews-css.js` (a postbuild step), NOT by
		// webpack — so it doesn't participate in `$asset['version']`'s
		// JS content hash. A DataViews CSS-only bump without a JS
		// change would otherwise not invalidate merchants' browser
		// caches. Hash the file separately via `md5_file()` so the
		// registered version tracks the file's actual contents.
		$css_path = WC_AI_STOREFRONT_PLUGIN_PATH . '/build/ai-storefront-settings.css';
		if ( file_exists( $css_path ) ) {
			// Three-tier fallback chain for the cache-bust version:
			//   1. md5_file() content hash (normal path)
			//   2. filemtime() if md5_file fails
			//   3. $asset['version'] JS hash as last resort
			// A blank version would defeat cache-busting, so we
			// always produce a non-empty string.
			//
			// The `is_readable()` guard is important: md5_file()
			// emits an E_WARNING (in addition to returning false)
			// when it can't open the file. Without the guard, a
			// transient permissions blip on a merchant's server
			// would spam the error log on every admin page load
			// and can leak absolute filesystem paths to logs
			// exposed by some hosting dashboards. Checking
			// readability first keeps the fallback silent.
			$css_version = $asset['version'];
			if ( is_readable( $css_path ) ) {
				$hash = md5_file( $css_path );
				if ( false !== $hash ) {
					$css_version = substr( $hash, 0, 20 );
				} else {
					// md5_file raced or failed despite is_readable
					// (rare: file disappeared between the check and
					// the read). Try mtime as a still-silent
					// fallback; if that also fails, the
					// $asset['version'] default remains.
					$mtime = @filemtime( $css_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional: fall through to $asset['version'] default on failure.
					if ( false !== $mtime ) {
						$css_version = (string) $mtime;
					}
				}
			}
			wp_register_style(
				'wc-ai-storefront-settings',
				WC_AI_STOREFRONT_PLUGIN_URL . '/build/ai-storefront-settings.css',
				[ 'wp-components' ],
				$css_version
			);
		}

		wp_localize_script(
			'wc-ai-storefront-settings',
			'wcAiSyndicationParams',
			[
				'restUrl'        => rest_url( 'wc/v3/ai-syndication' ),
				'adminUrl'       => rest_url( 'wc/v3/ai-storefront/admin' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'siteUrl'        => home_url( '/' ),
				'llmsTxtUrl'     => home_url( '/llms.txt' ),
				'ucpUrl'         => home_url( '/.well-known/ucp' ),
				'ordersUrl'      => admin_url( 'admin.php?page=wc-orders' ),
				'version'        => WC_AI_STOREFRONT_VERSION,
				// `product_brand` is a native WC taxonomy from 9.5+.
				// We gate client-side rendering of the Brands segment
				// on this flag so stores running older WC versions (or
				// any environment without the taxonomy registered) hide
				// the segment rather than surfacing a dead toggle.
				// Matches the server-side guard in
				// `is_product_syndicated()` + the Store API filter +
				// the `/search/brands` admin route.
				'supportsBrands' => taxonomy_exists( 'product_brand' ),
			]
		);

		wp_enqueue_script( 'wc-ai-storefront-settings' );
		if ( wp_style_is( 'wc-ai-storefront-settings', 'registered' ) ) {
			wp_enqueue_style( 'wc-ai-storefront-settings' );
		}
		wp_enqueue_style( 'wp-components' );

		// Woo component style handles (wc-components, wc-admin-layout,
		// wc-experimental) were previously enqueued here to support
		// `@woocommerce/components`' TableCard adoption. That adoption
		// was reverted in favor of `@wordpress/dataviews`, whose CSS
		// ships bundled into our own stylesheet via an import in
		// client/settings/ai-storefront/index.js. No wc-admin handles
		// needed anymore — keeping our styles self-contained means the
		// admin page renders identically on every WC configuration.
		// See AGENTS.md "Styling" for the decision history.
	}

	/**
	 * Get plugin settings.
	 *
	 * @return array
	 */
	/**
	 * Memoized settings for the current request.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	public static function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$defaults = self::settings_defaults();

		$settings = get_option( self::SETTINGS_OPTION, array() );

		// Silent migration from legacy pre-0.1.5 enum values
		// (`categories` / `tags` / `brands`) to the consolidated
		// `by_taxonomy` value that triggers UNION enforcement.
		// Migrating on read (rather than on activation) means a
		// rollback-then-forward across versions still converges,
		// and avoids coupling to activation-hook timing.
		$needs_migration =
			is_array( $settings )
			&& isset( $settings['product_selection_mode'] )
			&& in_array(
				$settings['product_selection_mode'],
				[ 'categories', 'tags', 'brands' ],
				true
			);
		if ( $needs_migration ) {
			$settings['product_selection_mode'] = 'by_taxonomy';
		}

		$merged = wp_parse_args( is_array( $settings ) ? $settings : [], $defaults );

		// Allowed crawlers: delegates to the Robots class's helper so
		// the three-branch resolution (fresh install vs. stored-empty
		// vs. stored-list) is defined in one place and unit-tested
		// independently of this settings aggregator. See
		// `WC_AI_Storefront_Robots::resolve_allowed_crawlers()` for
		// the decision table.
		$merged['allowed_crawlers'] = WC_AI_Storefront_Robots::resolve_allowed_crawlers(
			is_array( $settings ) ? $settings : []
		);

		// Populate the cache BEFORE the migration write so any hook
		// subscriber on `update_option_wc_ai_storefront_settings`
		// that calls `get_settings()` during the write reads the
		// already-migrated value from cache rather than re-entering
		// this code path and recursing.
		self::$settings_cache = $merged;

		if ( $needs_migration ) {
			$updated = update_option( self::SETTINGS_OPTION, $settings, true );
			if ( $updated ) {
				// Match `update_settings()`'s cache-invalidation
				// behavior so a persistent-object-cache deployment
				// (Redis / Memcached) doesn't serve the legacy value
				// to sibling PHP workers off `alloptions`.
				wp_cache_delete( self::SETTINGS_OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			} else {
				WC_AI_Storefront_Logger::debug(
					'silent migration: update_option returned false for %s',
					self::SETTINGS_OPTION
				);
			}
		}

		return $merged;
	}

	/**
	 * Update plugin settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool
	 */
	public static function update_settings( $settings ) {
		// Reset both the static cache and the WP object cache so get_settings()
		// reads the current persisted value from DB. Without resetting
		// $settings_cache first, get_settings() returns the stale static value
		// and the wp_cache_delete below has no practical effect.
		self::$settings_cache = null;
		wp_cache_delete( self::SETTINGS_OPTION, 'options' );
		$current = self::get_settings();
		$merged  = wp_parse_args( $settings, $current );

		// Strict yes/no enum. Behavior depends on what the POST body
		// contains AFTER the wp_parse_args merge above:
		//
		//   - Key omitted from the POST → `$merged[key]` carries the
		//     existing stored value forward (or the get_settings()
		//     default `'no'` if the key was never stored). Correct
		//     behavior: omitting the field on a partial PATCH-style
		//     update preserves the merchant's prior choice.
		//   - Key present with `'yes'` or `'no'` → preserved verbatim.
		//   - Key present with anything else (malformed string, bool,
		//     int, explicit null) → falls back to `'no'`. Schema-level
		//     enum should reject most of these at the REST layer
		//     before they reach this sanitizer; this is the safety
		//     net for direct option writes / future schema loosening.
		//
		// Coalesce ONCE into a local — the earlier shape
		// (`in_array($merged[key] ?? 'no', ...) ? $merged[key] : 'no'`)
		// had a hole: explicit `null` passed validation against the
		// coalesced `'no'` but persisted the raw `null`. With
		// `$allow_unknown` resolved up front, validation and storage
		// see the same value.
		$allow_unknown = $merged['allow_unknown_ucp_agents'] ?? 'no';
		if ( ! in_array( $allow_unknown, [ 'yes', 'no' ], true ) ) {
			$allow_unknown = 'no';
		}

		// Map legacy mode aliases to their canonical form. Old stores may
		// have 'categories', 'tags', or 'brands' saved before the unified
		// by_taxonomy mode was introduced; this normalizes them at write
		// time so the DB stays clean.
		$legacy_mode_map = [
			'categories' => 'by_taxonomy',
			'tags'       => 'by_taxonomy',
			'brands'     => 'by_taxonomy',
		];
		$raw_mode        = $merged['product_selection_mode'] ?? 'all';
		$normalized_mode = isset( $legacy_mode_map[ $raw_mode ] ) ? $legacy_mode_map[ $raw_mode ] : $raw_mode;

		// Sanitize — only store known keys to keep the option clean.
		$clean = [
			'enabled'                  => in_array( $merged['enabled'], [ 'yes', 'no' ], true ) ? $merged['enabled'] : 'no',
			'product_selection_mode'   => in_array( $normalized_mode, [ 'all', 'by_taxonomy', 'selected' ], true ) ? $normalized_mode : 'all',
			'selected_categories'      => array_map( 'absint', (array) ( $merged['selected_categories'] ?? [] ) ),
			'selected_tags'            => array_map( 'absint', (array) ( $merged['selected_tags'] ?? [] ) ),
			'selected_brands'          => array_map( 'absint', (array) ( $merged['selected_brands'] ?? [] ) ),
			'selected_products'        => array_map( 'absint', (array) ( $merged['selected_products'] ?? [] ) ),
			'rate_limit_rpm'           => max( 1, absint( $merged['rate_limit_rpm'] ?? 25 ) ),
			'allowed_crawlers'         => WC_AI_Storefront_Robots::sanitize_allowed_crawlers(
				// Fallback to live-browsing only (matching the
				// fresh-install default from get_settings) if the
				// caller invoked update_settings without specifying
				// `allowed_crawlers`. In practice the admin form
				// always sends this key, so this fallback rarely
				// fires — but when it does, we err on the side of
				// the commerce-safer default rather than re-enabling
				// every training crawler the merchant may have
				// explicitly unchecked.
				$merged['allowed_crawlers'] ?? WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS
			),
			'return_policy'            => self::sanitize_return_policy(
				$merged['return_policy'] ?? []
			),
			// See `$allow_unknown` resolution above the array literal
			// for why we don't inline this with `??` + ternary.
			'allow_unknown_ucp_agents' => $allow_unknown,
		];

		// Use autoload=true so the option is always in the alloptions cache.
		self::$settings_cache = null;
		$result               = update_option( self::SETTINGS_OPTION, $clean, true );

		// Bust the cache so the next get_settings() reads the fresh value.
		wp_cache_delete( self::SETTINGS_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return $result;
	}

	/**
	 * Sanitize the `return_policy` nested settings object.
	 *
	 * Thin delegation to `WC_AI_Storefront_Return_Policy::sanitize()` —
	 * the actual rules live there so the unit-test stub class can
	 * exercise the production sanitizer rather than hand-mirroring it.
	 *
	 * Mode-aware persistence: only the fields that are meaningful for
	 * the resolved mode are stored. `unconfigured` returns just `mode`;
	 * `final_sale` returns `mode` + `page_id`; `returns_accepted`
	 * returns the full 5-field shape. See the helper's docblock for
	 * the full per-field rules.
	 *
	 * @param mixed $policy Raw return-policy input.
	 * @return array<string, mixed>
	 */
	public static function sanitize_return_policy( $policy ): array {
		return WC_AI_Storefront_Return_Policy::sanitize( $policy );
	}

	/**
	 * Prime the WordPress term relationship and term object caches for a
	 * batch of product IDs before calling is_product_syndicated() in a loop.
	 *
	 * Without priming, each is_product_syndicated() call in by_taxonomy mode
	 * issues 1-2 DB queries (one per taxonomy). With priming, the whole batch
	 * is loaded in a fixed number of queries regardless of batch size.
	 *
	 * Callers in the UCP REST controller should call this once before
	 * iterating over a set of product IDs for catalog/search filtering.
	 * The complete N+1 elimination requires calling prime_syndication_cache()
	 * from the UCP REST controller before the product loop (tracked in
	 * issue #168). This method provides the priming infrastructure.
	 *
	 * @param int[]  $product_ids WC product IDs to prime.
	 * @param array  $settings    Plugin settings (to determine which taxonomies are needed).
	 */
	public static function prime_syndication_cache( array $product_ids, array $settings ): void {
		if ( empty( $product_ids ) ) {
			return;
		}
		$mode = $settings['product_selection_mode'] ?? 'all';
		if ( 'by_taxonomy' !== $mode ) {
			return;
		}
		// Prime term relationship objects so wp_get_post_terms() is a cache hit.
		update_object_term_cache( $product_ids, 'product' );
	}

	/**
	 * Check if a product is included in syndication.
	 *
	 * Gating semantics by mode:
	 *
	 *   - `all`           → every product matches
	 *   - `selected`      → product must appear in `selected_products`
	 *   - `by_taxonomy`   → UNION across `selected_categories`,
	 *                       `selected_tags`, `selected_brands`. A product
	 *                       matches if it belongs to at least one term
	 *                       in any of the configured arrays.
	 *
	 * The `by_taxonomy` mode is the 0.1.5 replacement for the previously-
	 * separate `categories` / `tags` / `brands` modes. Under the old
	 * enum, only one taxonomy's selection enforced at a time and the
	 * other two `selected_*` arrays were inert storage. Under UNION a
	 * merchant selecting 3 categories + 1 brand sees products matching
	 * any of those 3 categories OR that 1 brand — matching the "Products
	 * by category, tag, or brand" UI copy and the multi-count By-
	 * taxonomy badge. A plugin-load silent migration in `get_settings()`
	 * rewrites any stored legacy mode to `by_taxonomy` so the
	 * historical enum vocabulary never leaks into fresh reads; the
	 * defensive legacy-mode fallback near the start of this method
	 * covers the narrow window where a caller passes explicit
	 * `$settings` with an old value.
	 *
	 * Empty-selection policy for `by_taxonomy` mode: if all three
	 * `selected_*` arrays are empty (after accounting for missing
	 * taxonomies), the method returns false — hides the catalog.
	 * This matches the Store API filter's `post__in = [0]` posture in
	 * the same state. Rationale: "By taxonomy but pick nothing" is a
	 * recoverable misconfiguration; hiding everything makes it
	 * visible to the merchant immediately rather than silently
	 * exposing the whole catalog.
	 *
	 * Brand-taxonomy downgrade exception: if the ONLY configured
	 * taxonomy is brands and `product_brand` isn't registered (pre-
	 * WC 9.5, or a custom unregistration), we return true (show
	 * everything) rather than return false (hide everything). The
	 * pre-0.1.5 code had the same exception for the dedicated
	 * `brands` mode; preserving it here means a merchant who
	 * configured brands on a WC 9.5+ store and then downgraded
	 * doesn't see their catalog silently vanish. The stored
	 * `selected_brands` array stays on disk; re-registration of
	 * the taxonomy restores enforcement.
	 *
	 * Bulk usage: when calling this method in a loop over many products,
	 * call prime_syndication_cache() first to batch-load term relationships
	 * and avoid N+1 DB queries in by_taxonomy mode.
	 *
	 * @param WC_Product|int $product  WC_Product object OR an int product ID.
	 *                                 The int form is what UCP REST callers
	 *                                 (catalog/lookup, etc.) have at hand
	 *                                 without paying for `wc_get_product()`.
	 * @param array|null $settings Settings (null to auto-load).
	 * @return bool
	 */
	public static function is_product_syndicated( $product, $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		$product_id = self::resolve_product_id_for_syndication( $product );
		if ( $product_id <= 0 ) {
			return false;
		}

		$mode = $settings['product_selection_mode'] ?? 'all';

		// Defensive legacy-mode fallback. The silent migration on
		// plugin load rewrites stored values, but a caller that
		// passes explicit `$settings` with a legacy enum still gets
		// correct behavior. Mapping is 1:1 because the pre-0.1.5
		// enum's `categories`/`tags`/`brands` values all corresponded
		// to "scoping by a single taxonomy"; rewriting them to
		// `by_taxonomy` preserves intent under UNION enforcement
		// (the inert-storage arrays become live without surprise
		// because the merchant had only populated the one matching
		// their mode anyway).
		if ( 'categories' === $mode || 'tags' === $mode || 'brands' === $mode ) {
			$mode = 'by_taxonomy';
		}

		if ( 'all' === $mode ) {
			return true;
		}

		if ( 'selected' === $mode ) {
			if ( empty( $settings['selected_products'] ) ) {
				return false;
			}
			return in_array(
				$product_id,
				array_map( 'absint', $settings['selected_products'] ),
				true
			);
		}

		if ( 'by_taxonomy' === $mode ) {
			return self::is_in_taxonomy_scope( $product_id, $settings );
		}

		return false;
	}

	/**
	 * Normalise a product argument to a resolved, parent-redirected product ID.
	 *
	 * Accepts either a raw int product ID or a WC_Product-like object (anything
	 * with a `get_id()` method). Returns 0 for invalid inputs and for orphaned
	 * variations whose parent post no longer exists.
	 *
	 * Variations inherit their parent's syndication status. The merchant's
	 * selection mechanisms (`selected_products`, `selected_categories`, etc.)
	 * all attach to PARENT product posts. Without this redirect a child
	 * variation always reads as out-of-scope — breaking per-variation catalog
	 * lookups. See the inline comments in `is_product_syndicated()` for the
	 * full cost and edge-case analysis.
	 *
	 * @param  int|object $product Raw int ID or WC_Product-like object.
	 * @return int                 Resolved parent ID (>=1) or 0 on failure.
	 */
	private static function resolve_product_id_for_syndication( $product ): int {
		// Accept either a `WC_Product`-like object (with `get_id()`)
		// or a raw int product ID. The int form is what UCP REST
		// callers (catalog/lookup, checkout line-item resolution)
		// have at hand without paying the cost of `wc_get_product()`
		// just to satisfy the type. The method only consults the
		// product's ID and its term memberships — both addressable
		// via the int directly.
		$product_id = is_int( $product )
			? $product
			: ( is_object( $product ) && method_exists( $product, 'get_id' )
				? (int) $product->get_id()
				: 0 );

		if ( $product_id <= 0 ) {
			return 0;
		}

		// `function_exists` guard is defense-in-depth for non-WP
		// loading contexts (static analyzers, scaffolding scripts,
		// hypothetical CLI tooling that includes this file outside
		// a full WP bootstrap). WP core always provides both
		// functions in production, and the unit-test harness loads
		// the stub class rather than this file — so the guard is
		// genuinely never false in either production or the current
		// test suite. It exists purely so the file remains
		// include-safe in any environment where WP isn't loaded.
		if (
			function_exists( 'get_post_type' )
			&& function_exists( 'wp_get_post_parent_id' )
			&& 'product_variation' === get_post_type( $product_id )
		) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			if ( $parent_id <= 0 ) {
				// Orphaned variation (parent deleted but variation
				// row still exists). Return 0 so the caller treats
				// the product as out-of-scope rather than leaking.
				return 0;
			}
			return $parent_id;
		}

		return $product_id;
	}

	/**
	 * Return true if `$product_id` falls within the `by_taxonomy` scope.
	 *
	 * Checks product categories, tags, and (when the taxonomy exists) brands
	 * against the merchant-configured term lists. Any matching term returns
	 * true immediately (UNION / OR semantics). An entirely empty selection
	 * returns false ("hide all"). A brands-only selection where the taxonomy
	 * is missing degrades gracefully to true ("show all") so an environment
	 * change doesn't silently hide the catalog.
	 *
	 * @param  int   $product_id Resolved, parent-redirected product ID.
	 * @param  array $settings   Plugin settings array (from `get_settings()`).
	 * @return bool
	 */
	private static function is_in_taxonomy_scope( int $product_id, array $settings ): bool {
		$selected_categories = array_map( 'absint', $settings['selected_categories'] ?? array() );
		$selected_tags       = array_map( 'absint', $settings['selected_tags'] ?? array() );
		$selected_brands     = array_map( 'absint', $settings['selected_brands'] ?? array() );

		$brands_supported = taxonomy_exists( 'product_brand' );

		$has_cats   = ! empty( $selected_categories );
		$has_tags   = ! empty( $selected_tags );
		$has_brands = ! empty( $selected_brands ) && $brands_supported;

		// Brand-downgrade exception: only brands configured and
		// the taxonomy is now missing → show all. Preserves the
		// pre-0.1.5 `brands` mode's degradation behavior so an
		// environment change doesn't silently hide the catalog.
		if ( ! $has_cats && ! $has_tags && ! $brands_supported && ! empty( $selected_brands ) ) {
			return true;
		}

		// Empty-selection policy: nothing enforceable → hide all.
		if ( ! $has_cats && ! $has_tags && ! $has_brands ) {
			return false;
		}

		if ( $has_cats ) {
			$product_cats = wc_get_product_cat_ids( $product_id );
			if ( ! empty( array_intersect( $product_cats, $selected_categories ) ) ) {
				return true;
			}
		}

		if ( $has_tags ) {
			$product_tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $product_tags ) && ! empty( array_intersect( $product_tags, $selected_tags ) ) ) {
				return true;
			}
		}

		if ( $has_brands ) {
			$product_brands = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $product_brands ) && ! empty( array_intersect( $product_brands, $selected_brands ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {}
}
