<?php
/**
 * Migration nudge: detect an SEO plugin that emits some of the same tags
 * this plugin now provides (titles, descriptions, social cards, and
 * structured data) and invite the merchant to deactivate it. Informational
 * + dismissible; changes no output.
 *
 * @package WooCommerce_AI_Storefront
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the dismissible SEO-plugin conflict notice.
 */
class WC_AI_Storefront_Schema_Conflict_Notice {

	/**
	 * Per-user dismissal flag (user meta key).
	 */
	public const DISMISS_META = 'wc_ai_storefront_schema_notice_dismissed';

	/**
	 * AJAX action name for dismissal.
	 */
	private const AJAX_ACTION = 'wc_ai_storefront_dismiss_schema_notice';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Whether to render the notice for the current admin request/user.
	 */
	public function should_show(): bool {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		$settings = WC_AI_Storefront::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return false;
		}
		return WC_AI_Storefront_Seo_Plugin_Detector::has_conflict();
	}

	/**
	 * Render the notice (and its inline dismiss script) when applicable.
	 */
	public function maybe_render(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		// Only deactivate-able plugins belong in the deactivate nudge; auto-handled
		// ones (e.g. Jetpack, whose overlap we suppress ourselves) are excluded.
		$plugins = WC_AI_Storefront_Seo_Plugin_Detector::deactivatable();
		$labels  = implode( ', ', array_column( $plugins, 'label' ) );
		$nonce   = wp_create_nonce( self::AJAX_ACTION );
		$doc_url = 'https://github.com/Automattic/woocommerce-ai-storefront/blob/main/docs/engineering/YOAST-COEXISTENCE.md';

		echo '<div class="notice notice-info is-dismissible" id="wc-ai-storefront-schema-notice" data-nonce="' . esc_attr( $nonce ) . '">';
		echo '<p>';
		printf(
			/* translators: %s: comma-separated list of detected SEO plugin names. */
			esc_html__( 'WooCommerce AI Storefront now provides your product titles, descriptions, social cards, and structured data. %s emits some of the same tags, which can produce duplicates. You can deactivate it. Review the checklist first.', 'woocommerce-ai-storefront' ),
			'<strong>' . esc_html( $labels ) . '</strong>'
		);
		echo '</p>';
		echo '<p><strong>' . esc_html__( 'Before deactivating, check:', 'woocommerce-ai-storefront' ) . '</strong></p>';
		echo '<ul style="list-style:disc;margin-left:20px;">';
		echo '<li>' . esc_html__( 'Breadcrumbs: if your theme calls the SEO plugin\'s breadcrumb function, switch it to woocommerce_breadcrumb().', 'woocommerce-ai-storefront' ) . '</li>';
		echo '<li>' . esc_html__( 'Redirects: any redirects configured in the SEO plugin will stop working. Keep a dedicated redirect plugin.', 'woocommerce-ai-storefront' ) . '</li>';
		echo '<li>' . esc_html__( 'Custom noindex rules: pages you manually noindexed will become indexable.', 'woocommerce-ai-storefront' ) . '</li>';
		echo '<li>' . esc_html__( 'Sitemap: WordPress core serves /wp-sitemap.xml. Resubmit it in Google Search Console.', 'woocommerce-ai-storefront' ) . '</li>';
		echo '</ul>';
		echo '<p><a href="' . esc_url( $doc_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Read the full coexistence guide', 'woocommerce-ai-storefront' ) . '</a></p>';
		echo '</div>';

		$this->print_dismiss_script();
	}

	/**
	 * Inline footer script that POSTs the dismissal when the notice's
	 * built-in (×) dismiss button is clicked. No build step, no dependency.
	 */
	private function print_dismiss_script(): void {
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		( function () {
			var n = document.getElementById( 'wc-ai-storefront-schema-notice' );
			if ( ! n ) { return; }
			n.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var body = new URLSearchParams();
				body.append( 'action', '<?php echo esc_js( self::AJAX_ACTION ); ?>' );
				body.append( 'nonce', n.getAttribute( 'data-nonce' ) );
				fetch( '<?php echo esc_url( $ajax_url ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function ( response ) {
					if ( ! response.ok ) {
						window.console && window.console.warn( 'AI Storefront: notice dismiss failed (HTTP ' + response.status + ')' );
					}
				} ).catch( function ( err ) {
					window.console && window.console.warn( 'AI Storefront: notice dismiss network error', err );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Persist the per-user dismissal.
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			// Terminates the request — wp_send_json_error() calls wp_die() —
			// so the state mutation below is never reached for an
			// unauthorized user.
			wp_send_json_error( '', 403 );
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_send_json_success();
	}
}
