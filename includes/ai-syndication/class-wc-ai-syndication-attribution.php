<?php
/**
 * AI Syndication: Attribution
 *
 * Integrates with WooCommerce's built-in Order Attribution system
 * to capture AI agent referrals using standard UTM parameters.
 *
 * Uses the native wc_order_attribution mechanism:
 * - utm_source = agent identifier (chatgpt, gemini, perplexity, etc.)
 * - utm_medium = "ai_agent"
 * - utm_campaign = optional campaign name
 * - Custom: ai_session_id stored as order meta for conversation tracking
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles AI agent order attribution via WooCommerce Order Attribution.
 */
class WC_AI_Syndication_Attribution {

	/**
	 * Meta key for storing the AI session ID on orders.
	 */
	const SESSION_META_KEY = '_wc_ai_syndication_session_id';

	/**
	 * Meta key for storing the AI agent name on orders.
	 */
	const AGENT_META_KEY = '_wc_ai_syndication_agent';

	/**
	 * The UTM medium value used to identify AI agent traffic.
	 */
	const AI_AGENT_MEDIUM = 'ai_agent';

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Capture ai_session_id from the request and store on the order.
		add_action( 'woocommerce_checkout_order_created', [ $this, 'capture_ai_attribution' ], 10, 1 );

		// Also capture from Store API / Blocks checkout.
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'capture_ai_attribution' ], 10, 1 );

		// Display AI attribution data in admin order view.
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_attribution_in_admin' ], 20, 1 );

		// Add AI agent column to orders list (optional, lightweight).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_order_list_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_order_list_column' ], 10, 2 );
	}

	/**
	 * Capture AI attribution data from the request onto the order.
	 *
	 * WooCommerce Order Attribution already captures utm_source, utm_medium, etc.
	 * We just need to capture our custom ai_session_id parameter and identify
	 * whether the order came from an AI agent.
	 *
	 * @param WC_Order $order The order object.
	 */
	public function capture_ai_attribution( $order ) {
		// Check if this order was attributed to an AI agent.
		// WooCommerce Order Attribution stores utm_medium in order meta.
		$utm_medium = $order->get_meta( '_wc_order_attribution_utm_medium' );

		if ( self::AI_AGENT_MEDIUM !== $utm_medium ) {
			// Also check the current request parameters as a fallback.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params, not processing form.
			$request_medium = isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '';
			if ( self::AI_AGENT_MEDIUM !== $request_medium ) {
				return;
			}
		}

		// Capture AI session ID from request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params.
		$session_id = isset( $_GET['ai_session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_session_id'] ) ) : '';
		if ( $session_id ) {
			$order->update_meta_data( self::SESSION_META_KEY, $session_id );
		}

		// Capture the agent name from utm_source.
		$utm_source = $order->get_meta( '_wc_order_attribution_utm_source' );
		if ( ! $utm_source ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params.
			$utm_source = isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '';
		}

		if ( $utm_source ) {
			$order->update_meta_data( self::AGENT_META_KEY, $utm_source );
		}

		$order->save();

		/**
		 * Fires when an AI agent order attribution is captured.
		 *
		 * @since 1.0.0
		 * @param WC_Order $order      The order.
		 * @param string   $utm_source The AI agent identifier.
		 * @param string   $session_id The AI session identifier.
		 */
		do_action( 'wc_ai_syndication_attribution_captured', $order, $utm_source, $session_id );
	}

	/**
	 * Display AI attribution data in the admin order view.
	 *
	 * @param WC_Order $order The order.
	 */
	public function display_attribution_in_admin( $order ) {
		$agent      = $order->get_meta( self::AGENT_META_KEY );
		$session_id = $order->get_meta( self::SESSION_META_KEY );

		if ( ! $agent ) {
			return;
		}

		echo '<div class="wc-ai-syndication-attribution">';
		echo '<h3>' . esc_html__( 'AI Agent Attribution', 'woocommerce-ai-syndication' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Agent:', 'woocommerce-ai-syndication' ) . '</strong> ' . esc_html( $agent ) . '</p>';

		if ( $session_id ) {
			echo '<p><strong>' . esc_html__( 'Session ID:', 'woocommerce-ai-syndication' ) . '</strong> <code>' . esc_html( $session_id ) . '</code></p>';
		}

		echo '</div>';
	}

	/**
	 * Add AI agent column to HPOS orders list.
	 *
	 * @param array $columns Order list columns.
	 * @return array
	 */
	public function add_order_list_column( $columns ) {
		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			return $columns;
		}

		// Insert after 'order_status' column.
		$new_columns = [];
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns['ai_agent'] = __( 'AI Agent', 'woocommerce-ai-syndication' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render AI agent column in orders list.
	 *
	 * @param string $column_name Column name.
	 * @param int    $order_id    Order ID (for HPOS, this is the WC_Order).
	 */
	public function render_order_list_column( $column_name, $order_id ) {
		if ( 'ai_agent' !== $column_name ) {
			return;
		}

		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order ) {
			echo '&mdash;';
			return;
		}

		$agent = $order->get_meta( self::AGENT_META_KEY );
		echo $agent ? esc_html( ucfirst( $agent ) ) : '&mdash;';
	}

	/**
	 * Get AI-attributed order statistics.
	 *
	 * @param string $period Period: 'day', 'week', 'month', 'year'.
	 * @return array
	 */
	public static function get_stats( $period = 'month' ) {
		$date_map = [
			'day'   => '1 day ago',
			'week'  => '1 week ago',
			'month' => '1 month ago',
			'year'  => '1 year ago',
		];

		$after = $date_map[ $period ] ?? $date_map['month'];

		$orders = wc_get_orders( [
			'status'     => [ 'wc-completed', 'wc-processing' ],
			'limit'      => -1,
			'return'     => 'ids',
			'date_after' => $after,
			'meta_query' => [
				[
					'key'     => self::AGENT_META_KEY,
					'compare' => 'EXISTS',
				],
			],
		] );

		$total_orders  = count( $orders );
		$total_revenue = 0;
		$by_agent      = [];

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$agent  = $order->get_meta( self::AGENT_META_KEY );
			$amount = (float) $order->get_total();
			$total_revenue += $amount;

			if ( ! isset( $by_agent[ $agent ] ) ) {
				$by_agent[ $agent ] = [ 'orders' => 0, 'revenue' => 0.0 ];
			}
			$by_agent[ $agent ]['orders']++;
			$by_agent[ $agent ]['revenue'] += $amount;
		}

		return [
			'period'        => $period,
			'total_orders'  => $total_orders,
			'total_revenue' => $total_revenue,
			'currency'      => get_woocommerce_currency(),
			'by_agent'      => $by_agent,
		];
	}
}
