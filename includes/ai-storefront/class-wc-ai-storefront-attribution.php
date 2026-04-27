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
	 *
	 * Stamped with the canonical merchant-facing name (e.g. "ChatGPT",
	 * "Gemini", "Other AI") via `WC_AI_Storefront_UCP_Agent_Header::canonicalize_host()`.
	 * Unknown agents bucket to "Other AI"; the raw hostname is preserved
	 * separately in `AGENT_HOST_RAW_META_KEY`.
	 *
	 * Mixed-era cardinality (important for stats consumers):
	 *   - pre-1.6.7 orders: stored as raw hostnames (e.g. "agent.foo.com").
	 *     `canonicalize_host_idempotent()` at display time maps known
	 *     pre-1.6.7 raw hostnames (anything in `KNOWN_AGENT_HOSTS`'s
	 *     keys) to canonical names; unknown raw hostnames pass through
	 *     to `OTHER_AI_BUCKET`.
	 *   - 1.6.7 → 0.2.x (pre-Other-AI): stored as canonical brand names
	 *     for known hosts, raw hostnames for unknown agents (the bug
	 *     this PR fixed prospectively).
	 *   - 0.3.x onward: stored as canonical brand names for known hosts,
	 *     `OTHER_AI_BUCKET` for unknown.
	 *
	 * `get_stats()` `GROUP BY` on this meta will therefore mix:
	 *   - canonical brand names ("Gemini", "ChatGPT", ...) that match
	 *     across all three eras.
	 *   - the literal `"Other AI"` value, only present from 0.3.x.
	 *   - long-tail raw hostnames from pre-1.6.7 + middle-era unknown
	 *     agents.
	 *
	 * Pre-1.6.7 unknown-agent rows continue to appear as their own
	 * buckets in stats, partially defeating the "Other AI" rollup until
	 * those orders age out of the rolling stats window. Acceptable for
	 * the rolling-window stats (day/week/month/year) since pre-1.6.7
	 * data ages out within ~14 months. A migration pass is out of scope
	 * for this rollout.
	 */
	const AGENT_META_KEY = '_wc_ai_storefront_agent';

	/**
	 * Meta key for storing the hostname from the UCP-Agent profile URL,
	 * normalized to a clean lookup-key shape.
	 *
	 * Captured alongside `AGENT_META_KEY` to preserve provenance for
	 * orders that bucket under "Other AI" — merchants who drill into
	 * an "Other AI" order still see the actual host that sent it.
	 *
	 * Two writers feed this meta:
	 *
	 *   - Strict path (continue_url with `utm_medium=ai_agent`):
	 *     receives the literal hostname from the
	 *     `ai_agent_host_raw` URL parameter our checkout-link builder
	 *     emits. That parameter is already produced from
	 *     `WC_AI_Storefront_UCP_Agent_Header::extract_profile_hostname()`
	 *     on the producer side, so it's host-shape and
	 *     unaltered.
	 *
	 *   - Lenient path (utm_source matches a `KNOWN_AGENT_HOSTS` key):
	 *     receives the value passed through
	 *     `normalize_host_string()` — scheme / path / port stripped,
	 *     lowercased, FQDN trailing dot removed. Storing the
	 *     normalized form (rather than the raw URL-shape utm_source)
	 *     keeps drill-in/debug surfaces showing a tidy
	 *     `openai.com` rather than `https://openai.com:443/foo`.
	 *
	 * The truly verbatim user-facing value (whatever the agent put on
	 * the URL) is preserved by WC core in
	 * `_wc_order_attribution_utm_source` — that meta is the source of
	 * truth if a debug session needs the exact bytes the agent sent.
	 *
	 * Future use: feeds aggregate review for graduating frequent
	 * unknown hostnames into `WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_HOSTS`
	 * with proper canonical names.
	 */
	const AGENT_HOST_RAW_META_KEY = '_wc_ai_storefront_agent_host_raw';

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
	 * Two recognition gates:
	 *
	 *   1. STRICT — utm_medium === 'ai_agent'. Set by our own
	 *      `build_continue_url()` so any order placed via the link
	 *      OUR /checkout-sessions endpoint produced lands here. This
	 *      is the canonical AI-order signal.
	 *
	 *   2. LENIENT — utm_source canonicalizes to a known AI agent host
	 *      via `KNOWN_AGENT_HOSTS`. Agents that bypass our endpoint
	 *      and build their own checkout-link URL (e.g. UCPPlayground
	 *      sending `?utm_source=ucpplayground.com&utm_medium=referral`)
	 *      get recognized via the host match. Without this gate, those
	 *      orders looked like ordinary referral traffic and never got
	 *      `_wc_ai_storefront_agent` meta — surfacing as
	 *      "AI orders = 0" in the dashboard even when AI agents drove
	 *      real purchases.
	 *
	 * The host match is safe because `KNOWN_AGENT_HOSTS` is a
	 * code-controlled allow-list, not a free-form string. A random
	 * referrer can't spoof itself into AI attribution by sending
	 * `utm_source=evil.example` — that hostname isn't in the map.
	 *
	 * Stored values:
	 *   - `AGENT_META_KEY` = canonical brand name when host-matched
	 *     (so the Recent Orders display + Top Agent stats show
	 *     "UCPPlayground", not "ucpplayground.com"). Falls back to
	 *     the raw utm_source for legacy orders or when the source
	 *     wasn't a recognized host.
	 *   - `AGENT_HOST_RAW_META_KEY` = a clean hostname-shape value
	 *     for "Other AI" drill-in or graduation review. Two writers:
	 *     the strict-gate path stamps the `ai_agent_host_raw` URL
	 *     param verbatim (already host-shaped because the producer
	 *     side ran it through `extract_profile_hostname()`); the
	 *     lenient-gate path stamps the output of
	 *     `normalize_host_string()` — scheme / path / port stripped,
	 *     lowercased, FQDN trailing dot removed. The verbatim
	 *     user-facing value (whatever the agent put on the URL) is
	 *     preserved by WC core in `_wc_order_attribution_utm_source`.
	 *
	 * WooCommerce core's Order Attribution captures utm_source /
	 * utm_medium itself; we don't duplicate that work, just lift the
	 * AI-specific signals into our own meta and an ai_session_id from
	 * the request.
	 *
	 * @param WC_Order $order The order object.
	 */
	public function capture_ai_attribution( $order ) {
		// Check if this order was attributed to an AI agent.
		// WooCommerce Order Attribution stores utm_medium in order meta.
		$utm_medium = $order->get_meta( '_wc_order_attribution_utm_medium' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params, not processing form.
		$request_medium = isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '';

		// Resolve utm_source up front — both the strict (utm_medium =
		// ai_agent) and lenient (host-match) gates need it.
		$utm_source = $order->get_meta( '_wc_order_attribution_utm_source' );
		if ( ! $utm_source ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params.
			$utm_source = isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '';
		}

		// Two ways to recognize an AI order:
		//
		//   1. STRICT: utm_medium === 'ai_agent'. This is the value our
		//      own continue_url builder emits, so it's unambiguous —
		//      every order WE built the link for hits this branch.
		//
		//   2. LENIENT: utm_source is an exact match for a hostname
		//      KEY in `KNOWN_AGENT_HOSTS` (e.g. `ucpplayground.com`,
		//      `openai.com`). Agents that bypass our /checkout-sessions
		//      endpoint and build their own checkout-link URL set
		//      whatever utm_medium they want (UCPPlayground sends
		//      `referral`, others may send `agent`, `ai`, `bot`, etc.).
		//      The host identifies the agent unambiguously regardless.
		//
		//      Critical: we match the hostname KEY set, NOT the
		//      canonical-name VALUE set. A non-AI referrer could
		//      otherwise spoof AI attribution by sending
		//      `utm_source=Gemini&utm_medium=referral` — the canonical
		//      brand-name string is publicly guessable, but the
		//      hostname-keys set requires sending an actual hostname
		//      that we've curated into the allow-list. That's a much
		//      narrower spoofing surface (an attacker would have to
		//      both know we recognize a host AND want to attribute
		//      their fake order to that host's brand).
		//
		// Either path = AI order. Otherwise the order is regular
		// referral / direct / paid-search traffic and we leave it
		// alone.
		$lenient_canonical = '';
		$normalized_host   = '';
		if ( '' !== $utm_source ) {
			// Normalize utm_source through the shared helper so common
			// real-world variants (`https://openai.com/`,
			// `OpenAI.COM:443`, `openai.com.`, etc.) all collapse to
			// the same lookup key. Without this, attribution silently
			// missed orders where the agent declared the same host in
			// a different lexical form.
			$normalized_host   = WC_AI_Storefront_UCP_Agent_Header::normalize_host_string( (string) $utm_source );
			$lenient_canonical = '' !== $normalized_host
				? ( WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_HOSTS[ $normalized_host ] ?? '' )
				: '';
		}
		$is_known_ai_host = '' !== $lenient_canonical;

		if (
			self::AI_AGENT_MEDIUM !== $utm_medium
			&& self::AI_AGENT_MEDIUM !== $request_medium
			&& ! $is_known_ai_host
		) {
			return;
		}

		// Capture AI session ID from request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params.
		$session_id = isset( $_GET['ai_session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_session_id'] ) ) : '';
		if ( $session_id ) {
			$order->update_meta_data( self::SESSION_META_KEY, $session_id );
		}

		// Resolve the single canonical-agent identifier used by ALL
		// downstream surfaces — the meta stamp, log lines, and the
		// `wc_ai_storefront_attribution_captured` action's payload.
		// Without this, lenient captures stored "ChatGPT" in meta but
		// emitted "openai.com" in the hook, so external listeners saw
		// a different identifier than the merchant-facing display.
		// Resolves to:
		//   - lenient match: the canonical brand name ("ChatGPT", ...).
		//   - strict path / unmatched: utm_source verbatim. The
		//     display layer's `canonicalize_host_idempotent()` is the
		//     safety net for the verbatim case.
		$canonical_agent = $is_known_ai_host ? $lenient_canonical : (string) $utm_source;

		if ( '' !== $canonical_agent ) {
			$order->update_meta_data( self::AGENT_META_KEY, $canonical_agent );
		}

		// When the lenient gate matched, stamp the NORMALIZED hostname
		// into host_raw so merchants drilling in see a clean
		// hostname-shape value rather than whatever URL-form the agent
		// happened to send (`https://openai.com/`,
		// `OpenAI.COM:443`, etc. all collapse to `openai.com` here).
		// ONLY fires for the lenient path — strict-path utm_source
		// carries the already-canonical brand name (e.g. "Gemini"),
		// which the gate would not have matched, so this branch
		// doesn't run for it. The strict path's correct host_raw
		// value arrives via the `ai_agent_host_raw` URL param
		// processed further down.
		if ( $is_known_ai_host && '' !== $normalized_host ) {
			$order->update_meta_data( self::AGENT_HOST_RAW_META_KEY, $normalized_host );
		}

		// Capture the raw (untransformed) hostname when present. This
		// is the unaltered value from the UCP-Agent profile URL — for
		// "Other AI" bucketed orders it lets merchants drill in and see
		// who actually sent the request. For known-canonical agents
		// (e.g. utm_source=Gemini) the raw host (gemini.google.com) is
		// also stamped for completeness.
		//
		// The continue_url's `ai_agent_host_raw` param flows through
		// the buyer's browser, so we treat it as untrusted here even
		// though it originated from our own controller. Two-layer
		// validation:
		//   (a) Length cap at 253 — RFC 1035 max DNS hostname length.
		//       Without this, a forged UCP-Agent → forged URL param
		//       can stuff multi-KB strings into postmeta (longtext, no
		//       schema cap), bloating the database and the admin order
		//       view's <code> blob.
		//   (b) Hostname-shape regex (alphanum + dot + hyphen). The
		//       upstream extract_profile_hostname() already enforces
		//       this on the producer side via wp_parse_url, so legit
		//       values always pass; only attacker-tampered URLs (e.g.
		//       carrying `<script>` or shell metachars) fail. Reject
		//       silently with a debug log so the rejection is auditable
		//       without poisoning the order.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params.
		$raw_host_input = isset( $_GET['ai_agent_host_raw'] ) ? sanitize_text_field( wp_unslash( $_GET['ai_agent_host_raw'] ) ) : '';
		$raw_host       = '';
		if ( '' !== $raw_host_input ) {
			if ( strlen( $raw_host_input ) > 253 || ! preg_match( '/^[a-z0-9.\-]+$/i', $raw_host_input ) ) {
				WC_AI_Storefront_Logger::debug(
					'rejected malformed ai_agent_host_raw (len=%d)',
					strlen( $raw_host_input )
				);
			} else {
				$raw_host = $raw_host_input;
			}
		}
		if ( '' !== $raw_host ) {
			$order->update_meta_data( self::AGENT_HOST_RAW_META_KEY, $raw_host );
		}

		$order->save();

		// Log the raw hostname when the canonical bucket is "Other AI"
		// so the WP debug log accumulates a record of unknown agents
		// for retrospective graduation review (which novel hostnames
		// are showing up often enough to warrant a KNOWN_AGENT_HOSTS
		// entry?). Known agents don't need this log line — their
		// canonical name already says everything the operator needs.
		if (
			$raw_host
			&& WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET === $canonical_agent
		) {
			WC_AI_Storefront_Logger::debug(
				'unknown AI agent bucketed as "Other AI": host=%s session=%s',
				$raw_host,
				$session_id ? $session_id : '(none)'
			);
		}

		// Resolve the host value actually stored in
		// AGENT_HOST_RAW_META_KEY for this capture, regardless of which
		// gate fired:
		//   - Strict path: the `ai_agent_host_raw` URL param flows
		//     through `$raw_host`.
		//   - Lenient path: utm_source IS the host, normalized
		//     upstream into `$normalized_host` (and the URL param is
		//     usually absent on this path).
		// Reading the meta directly keeps the log honest — the line
		// reports the same value the order persists, even when the
		// two source variables ($raw_host vs $normalized_host) point
		// at different captured values for the same logical concept.
		$logged_host = (string) $order->get_meta( self::AGENT_HOST_RAW_META_KEY );
		WC_AI_Storefront_Logger::debug(
			'attribution captured — agent=%s session=%s raw_host=%s',
			'' !== $canonical_agent ? $canonical_agent : '(none)',
			$session_id ? $session_id : '(none)',
			'' !== $logged_host ? $logged_host : '(none)'
		);

		/**
		 * Fires when an AI agent order attribution is captured.
		 *
		 * @since 1.0.0
		 * @param WC_Order $order           The order.
		 * @param string   $canonical_agent The AI agent identifier — the
		 *                                  same value stored in
		 *                                  `_wc_ai_storefront_agent` meta.
		 *                                  Lenient captures pass the
		 *                                  canonical brand name (e.g.
		 *                                  "ChatGPT"), strict captures
		 *                                  pass utm_source verbatim.
		 * @param string   $session_id      The AI session identifier.
		 */
		do_action( 'wc_ai_storefront_attribution_captured', $order, $canonical_agent, $session_id );
	}

	/**
	 * Display AI attribution data in the admin order view.
	 *
	 * @param WC_Order $order The order.
	 */
	public function display_attribution_in_admin( $order ) {
		$agent      = $order->get_meta( self::AGENT_META_KEY );
		$session_id = $order->get_meta( self::SESSION_META_KEY );
		$raw_host   = $order->get_meta( self::AGENT_HOST_RAW_META_KEY );

		if ( ! $agent ) {
			return;
		}

		echo '<div class="wc-ai-storefront-attribution">';
		echo '<h3>' . esc_html__( 'AI Agent Attribution', 'woocommerce-ai-storefront' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Agent:', 'woocommerce-ai-storefront' ) . '</strong> ' . esc_html( $agent ) . '</p>';

		// Surface the raw hostname when present — gives merchants the
		// underlying provenance for "Other AI" bucketed orders, plus
		// useful context even for known canonical agents (e.g. seeing
		// `bing.com` confirms the UCP-Agent really came from there
		// rather than a misconfigured proxy).
		if ( $raw_host ) {
			echo '<p><strong>' . esc_html__( 'Agent host:', 'woocommerce-ai-storefront' ) . '</strong> <code>' . esc_html( $raw_host ) . '</code></p>';
		}

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
		//
		// `currency_symbol` is an empty string (NOT the currency code)
		// when `get_woocommerce_currency_symbol()` is unavailable. The
		// frontend's `formatMoney()` helper does
		// `currency_symbol || currency || '$'`; falling back to the
		// code here would short-circuit that chain and render
		// glued-to-digits ("USD42.00") instead of space-separated
		// ("USD 42.00"). We keep this field strictly "symbol or empty"
		// and let the frontend's currency-code branch handle the
		// separator.
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
				: '',
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
						// Primary: orders DESC. Secondary: revenue DESC.
						// Tertiary: agent name ASC.
						//
						// `usort` is NOT stable in PHP — when a comparator
						// returns 0, the relative order of those elements
						// is implementation-defined and can flicker
						// between snapshots. The tertiary tie-break
						// guarantees deterministic ordering even when
						// two agents tie on BOTH orders AND revenue (a
						// realistic case for low-volume stores: two
						// agents each driving 1 order at the same price
						// point). Without it, the card winner could swap
						// between Tuesday and Wednesday's snapshot for
						// no merchant-visible reason. ASC name keeps
						// alphabetical familiarity ("Anthropic" wins
						// over "ChatGPT" on a true tie, which is
						// arbitrary but stable).
						//
						// See class docblock above re: spaceship vs
						// subtraction and short-ternary vs expanded.
						$primary = $b['orders'] <=> $a['orders'];
						if ( 0 !== $primary ) {
							return $primary;
						}
						$secondary = $b['revenue'] <=> $a['revenue'];
						if ( 0 !== $secondary ) {
							return $secondary;
						}
						return $a['name'] <=> $b['name'];
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
