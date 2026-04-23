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
 * @package WooCommerce_AI_Storefront
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles AI agent order attribution via WooCommerce Order Attribution.
 */
class WC_AI_Storefront_Attribution {

	/**
	 * Meta key for storing the AI session ID on orders.
	 */
	const SESSION_META_KEY = '_wc_ai_storefront_session_id';

	/**
	 * Meta key for storing the AI agent name on orders.
	 */
	const AGENT_META_KEY = '_wc_ai_storefront_agent';

	/**
	 * The UTM medium value used to identify AI agent traffic.
	 */
	const AI_AGENT_MEDIUM = 'ai_agent';

	/**
	 * Initialize hooks.
	 *
	 * Deliberately minimal: we capture agent metadata onto the order
	 * and render it in the order-edit screen, then stop. The orders
	 * list surfaces agent attribution through WooCommerce core's
	 * native "Origin" column (fed by `_wc_order_attribution_utm_source`,
	 * which we set via the continue_url's `utm_source` param) — so a
	 * custom "AI Agent" column on the list would be pure duplication.
	 * Removed in 1.6.7; see AGENTS.md "Attribution" for the rationale.
	 */
	public function init() {
		// Capture ai_session_id from the request and store on the order.
		add_action( 'woocommerce_checkout_order_created', [ $this, 'capture_ai_attribution' ], 10, 1 );

		// Also capture from Store API / Blocks checkout.
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'capture_ai_attribution' ], 10, 1 );

		// Display AI attribution data in admin order view.
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_attribution_in_admin' ], 20, 1 );
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

		WC_AI_Storefront_Logger::debug(
			'attribution captured — agent=%s session=%s',
			$utm_source ? $utm_source : '(none)',
			$session_id ? $session_id : '(none)'
		);

		/**
		 * Fires when an AI agent order attribution is captured.
		 *
		 * @since 1.0.0
		 * @param WC_Order $order      The order.
		 * @param string   $utm_source The AI agent identifier.
		 * @param string   $session_id The AI session identifier.
		 */
		do_action( 'wc_ai_storefront_attribution_captured', $order, $utm_source, $session_id );
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

		echo '<div class="wc-ai-storefront-attribution">';
		echo '<h3>' . esc_html__( 'AI Agent Attribution', 'woocommerce-ai-storefront' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Agent:', 'woocommerce-ai-storefront' ) . '</strong> ' . esc_html( $agent ) . '</p>';

		if ( $session_id ) {
			echo '<p><strong>' . esc_html__( 'Session ID:', 'woocommerce-ai-storefront' ) . '</strong> <code>' . esc_html( $session_id ) . '</code></p>';
		}

		echo '</div>';
	}

	/**
	 * Get AI-attributed order statistics.
	 *
	 * @param string $period Period: 'day', 'week', 'month', 'year'.
	 * @return array
	 */
	public static function get_stats( $period = 'month' ) {
		global $wpdb;

		$date_map = [
			'day'   => '1 day ago',
			'week'  => '1 week ago',
			'month' => '1 month ago',
			'year'  => '1 year ago',
		];

		$after    = $date_map[ $period ] ?? $date_map['month'];
		$after_ts = strtotime( $after );
		if ( false === $after_ts ) {
			$after_ts = strtotime( '1 month ago' );
		}
		$after_date = gmdate( 'Y-m-d H:i:s', $after_ts );

		// Use HPOS tables if available, fall back to post meta.
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// Table names are derived from `$wpdb->prefix` (admin-controlled,
			// not user input) and hard-coded WC HPOS suffixes. Interpolation
			// is the canonical WordPress pattern here — `$wpdb->prepare()`
			// cannot parameterize table names.
			$orders_table = $wpdb->prefix . 'wc_orders';
			$meta_table   = $wpdb->prefix . 'wc_orders_meta';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT agent_meta.meta_value AS agent,
							COUNT( DISTINCT o.id ) AS order_count,
							SUM( o.total_amount ) AS revenue
					 FROM {$orders_table} o
					 INNER JOIN {$meta_table} agent_meta
						ON o.id = agent_meta.order_id AND agent_meta.meta_key = %s
					 WHERE o.status IN ( 'wc-completed', 'wc-processing' )
					   AND o.date_created_gmt >= %s
					 GROUP BY agent_meta.meta_value",
					self::AGENT_META_KEY,
					$after_date
				)
			);
			// phpcs:enable
		} else {
			// Legacy post-based orders.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS agent,
							COUNT( DISTINCT p.ID ) AS order_count,
							SUM( pm_total.meta_value ) AS revenue
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm
						ON p.ID = pm.post_id AND pm.meta_key = %s
					 INNER JOIN {$wpdb->postmeta} pm_total
						ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
					 WHERE p.post_type = 'shop_order'
					   AND p.post_status IN ( 'wc-completed', 'wc-processing' )
					   AND p.post_date_gmt >= %s
					 GROUP BY pm.meta_value",
					self::AGENT_META_KEY,
					$after_date
				)
			);
		}

		$total_orders  = 0;
		$total_revenue = 0.0;
		$by_agent      = [];

		if ( $results ) {
			foreach ( $results as $row ) {
				$count   = (int) $row->order_count;
				$revenue = (float) $row->revenue;

				$total_orders           += $count;
				$total_revenue          += $revenue;
				$by_agent[ $row->agent ] = [
					'orders'  => $count,
					'revenue' => $revenue,
				];
			}
		}

		// Get total store orders for the same period (for AI share calculation).
		$all_orders_count = 0;
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$all_orders_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$orders_table}
					 WHERE status IN ( 'wc-completed', 'wc-processing' )
					   AND date_created_gmt >= %s",
					$after_date
				)
			);
			// phpcs:enable
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_orders_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_type = 'shop_order'
					   AND post_status IN ( 'wc-completed', 'wc-processing' )
					   AND post_date_gmt >= %s",
					$after_date
				)
			);
		}

		return [
			'period'           => $period,
			'ai_orders'        => $total_orders,
			'ai_revenue'       => $total_revenue,
			'all_orders'       => $all_orders_count,
			'ai_share_percent' => $all_orders_count > 0
				? round( ( $total_orders / $all_orders_count ) * 100, 1 )
				: 0,
			'currency'         => get_woocommerce_currency(),
			'by_agent'         => $by_agent,
		];
	}
}
