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
		require_once $path . 'class-wc-ai-storefront-llms-txt.php';
		require_once $path . 'class-wc-ai-storefront-jsonld.php';
		require_once $path . 'class-wc-ai-storefront-robots.php';
		require_once $path . 'class-wc-ai-storefront-ucp.php';
		require_once $path . 'class-wc-ai-storefront-store-api-rate-limiter.php';
		require_once $path . 'class-wc-ai-storefront-attribution.php';
		require_once $path . 'class-wc-ai-storefront-cache-invalidator.php';

		// UCP REST adapter module (1.3.0+). See PLAN-ucp-adapter.md.
		$ucp_path = $path . 'ucp-rest/';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-agent-header.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-envelope.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-product-translator.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-variant-translator.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-store-api-filter.php';
		require_once $ucp_path . 'class-wc-ai-storefront-store-api-extension.php';
		require_once $ucp_path . 'class-wc-ai-storefront-ucp-rest-controller.php';

		require_once WC_AI_STOREFRONT_PLUGIN_PATH . '/includes/admin/class-wc-ai-storefront-admin-controller.php';
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

		// Store API product collection filter — enforces the merchant's
		// `product_selection_mode` against every Store API product query
		// (including UCP catalog routes, which dispatch via rest_do_request).
		// See class docblock for scope rationale.
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
			delete_transient( WC_AI_Storefront_Llms_Txt::CACHE_KEY );
			delete_transient( WC_AI_Storefront_Ucp::CACHE_KEY );
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

		$defaults = [
			'enabled'                => 'no',
			'product_selection_mode' => 'all',
			'selected_categories'    => [],
			'selected_tags'          => [],
			'selected_brands'        => [],
			'selected_products'      => [],
			'rate_limit_rpm'         => 25,
		];

		$settings = get_option( self::SETTINGS_OPTION, [] );
		$merged   = wp_parse_args( is_array( $settings ) ? $settings : [], $defaults );

		// Allowed crawlers: delegates to the Robots class's helper so
		// the three-branch resolution (fresh install vs. stored-empty
		// vs. stored-list) is defined in one place and unit-tested
		// independently of this settings aggregator. See
		// `WC_AI_Storefront_Robots::resolve_allowed_crawlers()` for
		// the decision table.
		$merged['allowed_crawlers'] = WC_AI_Storefront_Robots::resolve_allowed_crawlers(
			is_array( $settings ) ? $settings : []
		);

		self::$settings_cache = $merged;
		return $merged;
	}

	/**
	 * Update plugin settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool
	 */
	public static function update_settings( $settings ) {
		// Read directly from DB, bypassing any object cache.
		wp_cache_delete( self::SETTINGS_OPTION, 'options' );
		$current = self::get_settings();
		$merged  = wp_parse_args( $settings, $current );

		// Sanitize — only store known keys to keep the option clean.
		$clean = [
			'enabled'                => in_array( $merged['enabled'], [ 'yes', 'no' ], true ) ? $merged['enabled'] : 'no',
			'product_selection_mode' => in_array( $merged['product_selection_mode'], [ 'all', 'categories', 'tags', 'brands', 'selected' ], true )
				? $merged['product_selection_mode']
				: 'all',
			'selected_categories'    => array_map( 'absint', (array) ( $merged['selected_categories'] ?? [] ) ),
			'selected_tags'          => array_map( 'absint', (array) ( $merged['selected_tags'] ?? [] ) ),
			'selected_brands'        => array_map( 'absint', (array) ( $merged['selected_brands'] ?? [] ) ),
			'selected_products'      => array_map( 'absint', (array) ( $merged['selected_products'] ?? [] ) ),
			'rate_limit_rpm'         => max( 1, absint( $merged['rate_limit_rpm'] ?? 25 ) ),
			'allowed_crawlers'       => WC_AI_Storefront_Robots::sanitize_allowed_crawlers(
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
	 * Check if a product is included in syndication.
	 *
	 * @param WC_Product $product  The product.
	 * @param array|null $settings Settings (null to auto-load).
	 * @return bool
	 */
	public static function is_product_syndicated( $product, $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		$mode = $settings['product_selection_mode'] ?? 'all';

		if ( 'all' === $mode ) {
			return true;
		}

		if ( 'categories' === $mode && ! empty( $settings['selected_categories'] ) ) {
			$product_cats = wc_get_product_cat_ids( $product->get_id() );
			return ! empty( array_intersect( $product_cats, array_map( 'absint', $settings['selected_categories'] ) ) );
		}

		if ( 'tags' === $mode && ! empty( $settings['selected_tags'] ) ) {
			$product_tags = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'ids' ] );
			if ( is_wp_error( $product_tags ) ) {
				return false;
			}
			return ! empty( array_intersect( $product_tags, array_map( 'absint', $settings['selected_tags'] ) ) );
		}

		if ( 'brands' === $mode ) {
			// Graceful degradation first — before the empty-selection
			// check. If the `product_brand` taxonomy isn't registered
			// (pre-WC 9.5 or a custom env), the merchant's brand
			// selection can't be enforced. Degrade to "product is
			// syndicated" (return true) so this gate stays symmetric
			// with the Store API filter, which also declines to
			// restrict in the same scenario — REGARDLESS of whether
			// `selected_brands` is populated or empty. Without this
			// ordering, a merchant with mode=`brands` but an empty
			// selection AND a missing taxonomy would see llms.txt /
			// JSON-LD hide the catalog while UCP catalog agents saw
			// everything, recreating the exact enforcement split the
			// brands-mode degradation path is meant to prevent.
			//
			// Rationale for "show all" over "hide all" in this
			// specific degraded scenario: the merchant picked
			// `brands` mode on a store that supported the taxonomy,
			// then something removed that support (WC downgrade,
			// custom taxonomy unregistration). Hiding the whole
			// catalog is a surprising consequence of an environment
			// change they may not have made. Showing the pre-
			// downgrade catalog preserves their previous-actual
			// agent-visible state until they re-configure. The
			// persisted `selected_brands` array stays on disk so a
			// future taxonomy re-registration restores enforcement.
			if ( ! taxonomy_exists( 'product_brand' ) ) {
				return true;
			}

			// Taxonomy is registered. Apply the empty-selection
			// policy: `brands` mode with an empty `selected_brands`
			// hides everything, matching the Store API filter's
			// `post__in = [0]` posture for the same state.
			if ( empty( $settings['selected_brands'] ) ) {
				return false;
			}

			$product_brands = wp_get_post_terms( $product->get_id(), 'product_brand', [ 'fields' => 'ids' ] );
			if ( is_wp_error( $product_brands ) ) {
				return false;
			}
			return ! empty( array_intersect( $product_brands, array_map( 'absint', $settings['selected_brands'] ) ) );
		}

		if ( 'selected' === $mode && ! empty( $settings['selected_products'] ) ) {
			return in_array( $product->get_id(), array_map( 'absint', $settings['selected_products'] ), true );
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
