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
	 * Bot manager instance.
	 *
	 * @var WC_AI_Syndication_Bot_Manager|null
	 */
	private $bot_manager;

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

		// Cache invalidation hooks register unconditionally so the llms.txt
		// cache stays fresh even while syndication is temporarily disabled.
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

		require_once $path . 'class-wc-ai-syndication-llms-txt.php';
		require_once $path . 'class-wc-ai-syndication-jsonld.php';
		require_once $path . 'class-wc-ai-syndication-robots.php';
		require_once $path . 'class-wc-ai-syndication-ucp.php';
		require_once $path . 'class-wc-ai-syndication-bot-manager.php';
		require_once $path . 'class-wc-ai-syndication-rate-limiter.php';
		require_once $path . 'class-wc-ai-syndication-catalog-api.php';
		require_once $path . 'class-wc-ai-syndication-attribution.php';
		require_once $path . 'class-wc-ai-syndication-cache-invalidator.php';

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
		$llms_txt = new WC_AI_Syndication_Llms_Txt();
		$llms_txt->init();

		$jsonld = new WC_AI_Syndication_JsonLd();
		$jsonld->init();

		$robots = new WC_AI_Syndication_Robots();
		$robots->init();

		// UCP Manifest.
		$ucp = new WC_AI_Syndication_Ucp();
		$ucp->init();

		// Attribution.
		$attribution = new WC_AI_Syndication_Attribution();
		$attribution->init();

		// Bot manager.
		$this->bot_manager = new WC_AI_Syndication_Bot_Manager();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		// Public AI catalog API.
		$bot_manager  = $this->bot_manager ?: new WC_AI_Syndication_Bot_Manager();
		$rate_limiter = new WC_AI_Syndication_Rate_Limiter();
		$catalog_api  = new WC_AI_Syndication_Catalog_Api( $bot_manager, $rate_limiter );
		$catalog_api->register_routes();

		// Admin settings API.
		$admin_controller = new WC_AI_Syndication_Admin_Controller( $bot_manager );
		$admin_controller->register_routes();
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

		wp_register_style(
			'wc-ai-syndication-settings',
			WC_AI_SYNDICATION_PLUGIN_URL . '/build/ai-syndication-settings.css',
			[ 'wp-components' ],
			$asset['version']
		);

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
				'version'    => WC_AI_SYNDICATION_VERSION,
			]
		);

		wp_enqueue_script( 'wc-ai-syndication-settings' );
		wp_enqueue_style( 'wc-ai-syndication-settings' );
	}

	/**
	 * Get plugin settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = [
			'enabled'                => 'no',
			'product_selection_mode' => 'all',
			'selected_categories'    => [],
			'selected_products'      => [],
			'rate_limit_rpm'         => 60,
			'rate_limit_rph'         => 1000,
			'allowed_crawlers'       => WC_AI_Syndication_Robots::AI_CRAWLERS,
		];

		$settings = get_option( self::SETTINGS_OPTION, [] );

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Update plugin settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool
	 */
	public static function update_settings( $settings ) {
		$current  = self::get_settings();
		$merged   = wp_parse_args( $settings, $current );

		// Sanitize.
		$merged['enabled']                = in_array( $merged['enabled'], [ 'yes', 'no' ], true ) ? $merged['enabled'] : 'no';
		$merged['product_selection_mode'] = in_array( $merged['product_selection_mode'], [ 'all', 'categories', 'selected' ], true )
			? $merged['product_selection_mode']
			: 'all';
		$merged['selected_categories']    = array_map( 'absint', (array) $merged['selected_categories'] );
		$merged['selected_products']      = array_map( 'absint', (array) $merged['selected_products'] );
		$merged['rate_limit_rpm']         = max( 1, absint( $merged['rate_limit_rpm'] ) );
		$merged['rate_limit_rph']         = max( 1, absint( $merged['rate_limit_rph'] ) );

		return update_option( self::SETTINGS_OPTION, $merged, false );
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
	 * Get the bot manager instance.
	 *
	 * @return WC_AI_Syndication_Bot_Manager
	 */
	public function get_bot_manager() {
		if ( ! $this->bot_manager ) {
			$this->bot_manager = new WC_AI_Syndication_Bot_Manager();
		}
		return $this->bot_manager;
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
