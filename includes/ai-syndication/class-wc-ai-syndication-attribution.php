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

		// Add AI agent column to orders list — support both HPOS and legacy post type.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_order_list_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_order_list_column' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_order_list_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_order_list_column' ], 10, 2 );

		// Add AI agent filter dropdown to orders list.
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_agent_filter' ], 20 );
		add_action( 'restrict_manage_posts', [ $this, 'render_agent_filter_legacy' ], 20 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ $this, 'filter_orders_by_agent' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_orders_by_agent_legacy' ] );
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

		WC_AI_Syndication_Logger::debug(
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
	 * Works with both the legacy post-based orders list (where the second
	 * parameter is an int post ID) and the HPOS orders list (where it's
	 * already a WC_Order instance). The `instanceof` guard at the start
	 * of the method handles the polymorphism.
	 *
	 * @param string       $column_name Column name.
	 * @param int|WC_Order $order_id    Order ID (legacy) or WC_Order (HPOS).
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
		echo $agent ? esc_html( $agent ) : '&mdash;';
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

	/**
	 * Get distinct AI agent names from order meta for the filter dropdown.
	 *
	 * @return string[]
	 */
	private static function get_known_agents() {
		global $wpdb;

		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$meta_table = $wpdb->prefix . 'wc_orders_meta';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$agents = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$meta_table} WHERE meta_key = %s AND meta_value != '' ORDER BY meta_value",
					self::AGENT_META_KEY
				)
			);
			// phpcs:enable
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$agents = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' ORDER BY meta_value",
					self::AGENT_META_KEY
				)
			);
		}

		return is_array( $agents ) ? $agents : [];
	}

	/**
	 * Render the AI agent filter dropdown (HPOS orders list).
	 */
	public function render_agent_filter() {
		$agents = self::get_known_agents();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['ai_agent_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_agent_filter'] ) ) : '';

		echo '<select name="ai_agent_filter">';
		echo '<option value="">' . esc_html__( 'Filter by AI agent', 'woocommerce-ai-syndication' ) . '</option>';
		if ( ! empty( $agents ) ) {
			echo '<option value="_any"' . selected( $current, '_any', false ) . '>' . esc_html__( 'Any AI agent', 'woocommerce-ai-syndication' ) . '</option>';
			foreach ( $agents as $agent ) {
				echo '<option value="' . esc_attr( $agent ) . '"' . selected( $current, $agent, false ) . '>' . esc_html( $agent ) . '</option>';
			}
		}
		echo '</select>';
	}

	/**
	 * Render the AI agent filter dropdown (legacy shop_order).
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_agent_filter_legacy( $post_type ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}
		$this->render_agent_filter();
	}

	/**
	 * Filter HPOS orders by AI agent meta.
	 *
	 * @param array $query_args Order query arguments.
	 * @return array
	 */
	public function filter_orders_by_agent( $query_args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$agent = isset( $_GET['ai_agent_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_agent_filter'] ) ) : '';

		if ( empty( $agent ) ) {
			return $query_args;
		}

		if ( '_any' === $agent ) {
			$query_args['meta_query'][] = [
				'key'     => self::AGENT_META_KEY,
				'compare' => 'EXISTS',
			];
		} else {
			$query_args['meta_query'][] = [
				'key'   => self::AGENT_META_KEY,
				'value' => $agent,
			];
		}

		return $query_args;
	}

	/**
	 * Filter legacy shop_order posts by AI agent meta.
	 *
	 * @param WP_Query $query The query.
	 */
	public function filter_orders_by_agent_legacy( $query ) {
		global $pagenow;

		if ( 'edit.php' !== $pagenow || ( $query->get( 'post_type' ) ?? '' ) !== 'shop_order' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$agent = isset( $_GET['ai_agent_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_agent_filter'] ) ) : '';

		if ( empty( $agent ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = [];
		}

		if ( '_any' === $agent ) {
			$meta_query[] = [
				'key'     => self::AGENT_META_KEY,
				'compare' => 'EXISTS',
			];
		} else {
			$meta_query[] = [
				'key'   => self::AGENT_META_KEY,
				'value' => $agent,
			];
		}

		$query->set( 'meta_query', $meta_query );
	}
}
