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
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class (Singleton).
 */
class WC_AI_Syndication {

	/**
	 * Option key for syndication settings.
	 */
	const SETTINGS_OPTION = 'wc_ai_syndication_settings';

	/**
	 * Singleton instance.
	 *
	 * @var WC_AI_Syndication|null
	 */
	private static $instance = null;


	/**
	 * Returns the singleton instance.
	 *
	 * @return WC_AI_Syndication
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

		$cache_invalidator = new WC_AI_Syndication_Cache_Invalidator();
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
		$path = WC_AI_SYNDICATION_PLUGIN_PATH . '/includes/ai-syndication/';

		require_once $path . 'class-wc-ai-syndication-logger.php';
		require_once $path . 'class-wc-ai-syndication-llms-txt.php';
		require_once $path . 'class-wc-ai-syndication-jsonld.php';
		require_once $path . 'class-wc-ai-syndication-robots.php';
		require_once $path . 'class-wc-ai-syndication-ucp.php';
		require_once $path . 'class-wc-ai-syndication-store-api-rate-limiter.php';
		require_once $path . 'class-wc-ai-syndication-attribution.php';
		require_once $path . 'class-wc-ai-syndication-cache-invalidator.php';

		// UCP REST adapter module (1.3.0+). See PLAN-ucp-adapter.md.
		$ucp_path = $path . 'ucp-rest/';
		require_once $ucp_path . 'class-wc-ai-syndication-ucp-agent-header.php';
		require_once $ucp_path . 'class-wc-ai-syndication-ucp-envelope.php';
		require_once $ucp_path . 'class-wc-ai-syndication-ucp-product-translator.php';
		require_once $ucp_path . 'class-wc-ai-syndication-ucp-variant-translator.php';
		require_once $ucp_path . 'class-wc-ai-syndication-ucp-store-api-filter.php';
		require_once $ucp_path . 'class-wc-ai-syndication-ucp-rest-controller.php';

		require_once WC_AI_SYNDICATION_PLUGIN_PATH . '/includes/admin/class-wc-ai-syndication-admin-controller.php';
	}

	/**
	 * Initialize all components.
	 */
	public function init_components() {
		$settings = self::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			// Only load attribution (to track even when syndication is paused)
			// and admin controller (to allow enabling).
			$attribution = new WC_AI_Syndication_Attribution();
			$attribution->init();
			return;
		}

		// Discovery Layer.
		// Note: llms.txt and UCP rewrite rules are registered unconditionally
		// in register_rewrite_rules(). Here we only init the remaining components.
		$jsonld = new WC_AI_Syndication_JsonLd();
		$jsonld->init();

		$robots = new WC_AI_Syndication_Robots();
		$robots->init();

		// Attribution.
		$attribution = new WC_AI_Syndication_Attribution();
		$attribution->init();

		// Store API rate limiting for AI bots.
		$rate_limiter = new WC_AI_Syndication_Store_Api_Rate_Limiter();
		$rate_limiter->init();

		// Store API product collection filter — enforces the merchant's
		// `product_selection_mode` against every Store API product query
		// (including UCP catalog routes, which dispatch via rest_do_request).
		// See class docblock for scope rationale.
		$store_api_filter = new WC_AI_Syndication_UCP_Store_API_Filter();
		$store_api_filter->init();
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
		$llms_txt = new WC_AI_Syndication_Llms_Txt();
		$ucp      = new WC_AI_Syndication_Ucp();

		// Register rewrite rules on init (plugins_loaded fires before init).
		add_action( 'init', [ $llms_txt, 'add_rewrite_rules' ] );
		add_action( 'init', [ $ucp, 'add_rewrite_rules' ] );

		add_filter( 'query_vars', [ $llms_txt, 'add_query_vars' ] );
		add_filter( 'query_vars', [ $ucp, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $llms_txt, 'serve_llms_txt' ] );
		add_action( 'template_redirect', [ $ucp, 'serve_manifest' ] );

		// Flush rewrite rules and bust content caches when needed:
		//
		// 1. After a plugin code update (stored version on disk differs
		//    from WC_AI_SYNDICATION_VERSION). This catches two install
		//    paths: in-place zip uploads AND remote auto-updates. It is
		//    critical that the activation hook does NOT pre-write the
		//    stored version — if it did, this branch would never detect
		//    a mismatch on in-place upgrades. See the comment on
		//    wc_ai_syndication_activate() for the full history.
		//
		// 2. After toggling syndication enabled/disabled (transient flag
		//    set by the admin controller).
		$needs_flush    = get_transient( 'wc_ai_syndication_flush_rewrite' );
		$stored_version = get_option( 'wc_ai_syndication_version', '' );

		if ( $needs_flush || $stored_version !== WC_AI_SYNDICATION_VERSION ) {
			delete_transient( 'wc_ai_syndication_flush_rewrite' );
			update_option( 'wc_ai_syndication_version', WC_AI_SYNDICATION_VERSION );

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
			delete_transient( WC_AI_Syndication_Llms_Txt::CACHE_KEY );
			delete_transient( WC_AI_Syndication_Ucp::CACHE_KEY );
		}
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$admin_controller = new WC_AI_Syndication_Admin_Controller();
		$admin_controller->register_routes();

		// UCP REST adapter routes at /wp-json/wc/ucp/v1/*. Registered
		// unconditionally — route handlers check settings.enabled and
		// return an appropriate UCP error envelope when syndication is
		// off, rather than 404 from missing registration. Matches the
		// pattern used for llms.txt / UCP manifest rewrite rules.
		$ucp_rest_controller = new WC_AI_Syndication_UCP_REST_Controller();
		$ucp_rest_controller->register_routes();
	}

	/**
	 * Add admin menu under WooCommerce.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'AI Syndication', 'woocommerce-ai-syndication' ),
			__( 'AI Syndication', 'woocommerce-ai-syndication' ),
			'manage_woocommerce',
			'wc-ai-syndication',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Render the admin settings page container.
	 */
	public function render_admin_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Syndication', 'woocommerce-ai-syndication' ) . '</h1>';
		echo '<div id="wc-ai-syndication-settings"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function admin_scripts( $hook_suffix ) {
		if ( 'woocommerce_page_wc-ai-syndication' !== $hook_suffix ) {
			return;
		}

		$asset_file = WC_AI_SYNDICATION_PLUGIN_PATH . '/build/ai-syndication-settings.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-data', 'wp-i18n' ],
				'version'      => WC_AI_SYNDICATION_VERSION,
			];

		wp_register_script(
			'wc-ai-syndication-settings',
			WC_AI_SYNDICATION_PLUGIN_URL . '/build/ai-syndication-settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Only register the stylesheet if the build produced one.
		$css_path = WC_AI_SYNDICATION_PLUGIN_PATH . '/build/ai-syndication-settings.css';
		if ( file_exists( $css_path ) ) {
			wp_register_style(
				'wc-ai-syndication-settings',
				WC_AI_SYNDICATION_PLUGIN_URL . '/build/ai-syndication-settings.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		wp_localize_script(
			'wc-ai-syndication-settings',
			'wcAiSyndicationParams',
			[
				'restUrl'    => rest_url( 'wc/v3/ai-syndication' ),
				'adminUrl'   => rest_url( 'wc/v3/ai-syndication/admin' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'siteUrl'    => home_url( '/' ),
				'llmsTxtUrl' => home_url( '/llms.txt' ),
				'ucpUrl'     => home_url( '/.well-known/ucp' ),
				'ordersUrl'  => admin_url( 'admin.php?page=wc-orders' ),
				'version'    => WC_AI_SYNDICATION_VERSION,
			]
		);

		wp_enqueue_script( 'wc-ai-syndication-settings' );
		if ( wp_style_is( 'wc-ai-syndication-settings', 'registered' ) ) {
			wp_enqueue_style( 'wc-ai-syndication-settings' );
		}
		wp_enqueue_style( 'wp-components' );
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
			'selected_products'      => [],
			'rate_limit_rpm'         => 25,
		];

		$settings = get_option( self::SETTINGS_OPTION, [] );
		$merged   = wp_parse_args( is_array( $settings ) ? $settings : [], $defaults );

		// Allowed crawlers: stored in option, defaults to all known crawlers.
		$merged['allowed_crawlers'] = ! empty( $settings['allowed_crawlers'] )
			? $settings['allowed_crawlers']
			: WC_AI_Syndication_Robots::AI_CRAWLERS;

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
			'product_selection_mode' => in_array( $merged['product_selection_mode'], [ 'all', 'categories', 'selected' ], true )
				? $merged['product_selection_mode']
				: 'all',
			'selected_categories'    => array_map( 'absint', (array) ( $merged['selected_categories'] ?? [] ) ),
			'selected_products'      => array_map( 'absint', (array) ( $merged['selected_products'] ?? [] ) ),
			'rate_limit_rpm'         => max( 1, absint( $merged['rate_limit_rpm'] ?? 25 ) ),
			'allowed_crawlers'       => WC_AI_Syndication_Robots::sanitize_allowed_crawlers(
				$merged['allowed_crawlers'] ?? WC_AI_Syndication_Robots::AI_CRAWLERS
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
