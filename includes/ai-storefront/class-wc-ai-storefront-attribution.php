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
					   AND agent_meta.meta_value <> ''
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
					   AND pm.meta_value <> ''
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

		$derived = self::derive_stats( $total_orders, $total_revenue, $by_agent );

		// Both currency code and symbol are exposed. The card UI uses
		// the symbol for display ("$42.00", "€42.00"); some callers may
		// still need the code for formatting downstream (e.g., third-
		// party integrations relying on ISO 4217). Keeping `currency`
		// as the code preserves backward compatibility for any
		// consumer reading the response shape that landed pre-0.1.8.
		$currency_code = get_woocommerce_currency();

		return [
			'period'           => $period,
			'ai_orders'        => $total_orders,
			'ai_revenue'       => $total_revenue,
			'ai_aov'           => $derived['ai_aov'],
			'all_orders'       => $all_orders_count,
			'ai_share_percent' => $all_orders_count > 0
				? round( ( $total_orders / $all_orders_count ) * 100, 1 )
				: 0,
			'currency'         => $currency_code,
			'currency_symbol'  => function_exists( 'get_woocommerce_currency_symbol' )
				? html_entity_decode( get_woocommerce_currency_symbol( $currency_code ) )
				: $currency_code,
			'by_agent'         => $by_agent,
			'top_agent'        => $derived['top_agent'],
		];
	}

	/**
	 * Maximum length for the agent name surfaced on the Top Agent card.
	 *
	 * `_wc_ai_storefront_agent` meta is populated from `utm_source` query
	 * params, which are merchant-uncontrolled inbound URL parameters. A
	 * pathological agent name (extremely long, attacker-controlled, or
	 * raw HTML markup) would break the StatCard layout when rendered as
	 * a React text child. React escapes HTML so it's not an XSS vector,
	 * but width-wise an unbounded string still degrades the dashboard.
	 * 64 characters is generous for canonical names ("chatgpt",
	 * "gemini.google.com", "perplexity") while bounding the layout impact.
	 */
	const TOP_AGENT_NAME_MAX_LENGTH = 64;

	/**
	 * Derive the AOV + top-agent fields from the aggregate query result.
	 *
	 * Extracted from `get_stats()` so the math is unit-testable without
	 * mocking `$wpdb`. The query that produces `$by_agent` already runs
	 * elsewhere; this method's contract is "given a totals + per-agent
	 * breakdown, return the stat-card fields the React Overview tab needs."
	 *
	 * AOV is computed from totals (`$total_revenue / $total_orders`),
	 * not by averaging per-agent AOVs — averaging weighted means is
	 * the unweighted-mean-of-weighted-means trap and produces the
	 * wrong number when agent volumes differ.
	 *
	 * Top-agent tie-break is `orders DESC, revenue DESC`. For low-volume
	 * stores in a 7-day window, ties on order count are common; revenue
	 * as the secondary sort surfaces the agent driving more business
	 * AND keeps the card stable across daily snapshots (no flicker
	 * between Tuesday and Wednesday). Returns null when `$by_agent` is
	 * empty so the React side renders an em-dash, matching the other
	 * cards' empty-state convention.
	 *
	 * The comparator uses `<=>` (spaceship) and is split into a primary +
	 * secondary check rather than the more compact `?:` short-ternary,
	 * for two reasons:
	 * (1) WP coding standard's `Universal.Operators.DisallowShortTernary`
	 *     forbids `?:`; an explicit `0 !== $primary` makes the WP-CS
	 *     reviewer happy.
	 * (2) Subtraction-based comparators (`return $b['revenue'] - $a['revenue']`)
	 *     would lose sub-dollar tie-breaks: `usort` casts the comparator's
	 *     return value to `int`, so a return of `0.25` truncates to `0` and
	 *     the tie isn't resolved. The spaceship operator returns clean
	 *     `-1`/`0`/`1` regardless of float magnitude.
	 *
	 * Defensive early-exit: when `$total_orders <= 0` we skip the ranking
	 * entirely. Even if `$by_agent` is non-empty (a caller-bug scenario
	 * the helper's contract doesn't strictly forbid), returning a "winner
	 * with `share_percent = 0`" would render a populated Top Agent card
	 * with a meaningless zero share — silently misleading the merchant.
	 * Better to render the empty-state em-dash than silently-wrong data.
	 *
	 * @param int                                                              $total_orders  Total AI-attributed orders in the period.
	 * @param float                                                            $total_revenue Total AI-attributed revenue in the period.
	 * @param array<string, array{orders: int<0, max>, revenue: float}>        $by_agent      Per-agent breakdown. Empty-string keys are accepted but skipped during ranking (defense-in-depth alongside the SQL `meta_value <> ''` filter in `get_stats()`).
	 * @return array{ai_aov: float, top_agent: array{name: string, orders: int, revenue: float, share_percent: float}|null}
	 */
	public static function derive_stats( int $total_orders, float $total_revenue, array $by_agent ): array {
		// Defensive early-exit. Negative or zero totals can't yield a
		// meaningful AOV or top-agent ranking; render empty state.
		if ( $total_orders <= 0 ) {
			return [
				'ai_aov'    => 0.0,
				'top_agent' => null,
			];
		}

		$ai_aov = round( $total_revenue / $total_orders, 2 );

		$top_agent = null;
		if ( ! empty( $by_agent ) ) {
			$ranked = [];
			foreach ( $by_agent as $name => $row ) {
				// Skip empty-string agent names defensively. The SQL
				// in `get_stats()` already filters these out (see
				// `meta_value <> ''` in both query branches), but
				// `derive_stats()` is `public static` and could be
				// called by a future caller that doesn't share that
				// guarantee.
				if ( '' === $name ) {
					continue;
				}
				$ranked[] = [
					'name'    => $name,
					'orders'  => $row['orders'],
					'revenue' => $row['revenue'],
				];
			}

			if ( ! empty( $ranked ) ) {
				usort(
					$ranked,
					static function ( $a, $b ) {
						// Primary: orders DESC. If equal, revenue DESC.
						// See class docblock above re: spaceship vs
						// subtraction and short-ternary vs expanded.
						$primary = $b['orders'] <=> $a['orders'];
						return 0 !== $primary
							? $primary
							: ( $b['revenue'] <=> $a['revenue'] );
					}
				);
				$winner    = $ranked[0];
				$top_agent = [
					// Cap at TOP_AGENT_NAME_MAX_LENGTH chars so an
					// abnormally long utm_source can't push the card
					// width past its layout slot. mbstring is a
					// "Recommended" PHP extension but not strictly
					// required by WordPress; guard with function_exists
					// and fall back to substr() so the plugin doesn't
					// fatal on minimal hosting. substr() can split a
					// multi-byte character mid-codepoint, but agent
					// names from utm_source are almost always ASCII
					// (chatgpt, gemini, etc.), so the fallback is
					// safe in the realistic failure mode.
					'name'          => function_exists( 'mb_substr' )
						? mb_substr( (string) $winner['name'], 0, self::TOP_AGENT_NAME_MAX_LENGTH )
						: substr( (string) $winner['name'], 0, self::TOP_AGENT_NAME_MAX_LENGTH ),
					'orders'        => $winner['orders'],
					'revenue'       => $winner['revenue'],
					// Always a float — `round()` returns float on the
					// happy path; the early-exit handles the zero case.
					'share_percent' => round( ( $winner['orders'] / $total_orders ) * 100, 1 ),
				];
			}
		}

		return [
			'ai_aov'    => $ai_aov,
			'top_agent' => $top_agent,
		];
	}
}
