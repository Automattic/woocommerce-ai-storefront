<?php
/**
 * AI Syndication: Attribution
 *
 * Integrates with WooCommerce's built-in Order Attribution system
 * to capture AI agent referrals using standard UTM parameters.
 *
 * Uses the native wc_order_attribution mechanism. Canonical UTM
 * shape on continue_urls our `/checkout-sessions` endpoint emits
 * (0.5.0+):
 * - utm_source = lowercase agent hostname (chatgpt.com, gemini.google.com, ...)
 * - utm_medium = "referral" (Google-canonical)
 * - utm_id     = "woo_ucp" (our routing flag)
 * - Custom: ai_session_id stored as order meta for conversation tracking
 *
 * The pre-0.5.0 shape (utm_source = canonical brand name,
 * utm_medium = "ai_agent") is still recognized by the STRICT
 * attribution gate's legacy branch so historical orders attribute
 * correctly through the upgrade window.
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
	 *   - Strict path (continue_url with `utm_id=woo_ucp`, or legacy
	 *     `utm_medium=ai_agent` for pre-0.5.0 orders): receives the
	 *     literal identifier from the `ai_agent_host_raw` URL
	 *     parameter our checkout-link builder emits. That parameter
	 *     is the producer-side raw identifier (hostname for
	 *     profile-URL-form requests, lowercased product token for
	 *     Product/Version-form requests, body field value for
	 *     meta.source-fallback requests).
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
	 * Legacy UTM medium value our pre-0.5.0 continue_url builder
	 * stamped to identify AI agent traffic. Still recognized by the
	 * STRICT attribution gate so already-placed orders attribute
	 * correctly through the canonical-UTM-shape upgrade window.
	 *
	 * The 0.5.0+ continue_url builder emits `utm_medium=referral`
	 * (Google-canonical) and signals "we routed this" via the
	 * `utm_id=woo_ucp` flag instead. See `WOO_UCP_ID` below.
	 *
	 * Removable from the STRICT gate once orders placed pre-0.5.0
	 * age out of every reporting window the merchant cares about.
	 * Concretely: WC Analytics' default ranges run 7d / 30d / 90d
	 * with comparison-period doubling, so a merchant comparing
	 * "last 90d vs prior 90d" needs 180 days of post-0.5.0 data
	 * before pre-0.5.0 orders fall outside both windows. Add a
	 * buffer for stragglers (long-running carts, refund flows) and
	 * the safe removal horizon is ~12 months from 0.5.0 ship date.
	 * The constant itself can stay as a historical reference even
	 * after the gate stops checking it.
	 */
	const AI_AGENT_MEDIUM = 'ai_agent';

	/**
	 * The UTM ID value our 0.5.0+ continue_url builder stamps to
	 * identify "this URL was routed through OUR /checkout-sessions
	 * endpoint." The STRICT attribution gate recognizes orders
	 * carrying this flag regardless of utm_medium / utm_source.
	 *
	 * The `woo_` prefix scopes the value to the WooCommerce ecosystem
	 * — if other UCP server implementations emerge, they'd use their
	 * own prefix. The `_ucp` suffix names the protocol context,
	 * leaving room for future `woo_acp` / `woo_other` if we ever
	 * route traffic through a different protocol.
	 */
	const WOO_UCP_ID = 'woo_ucp';

	/**
	 * Stamp our canonical attribution UTM shape onto a URL.
	 *
	 * Two emission surfaces share this helper so the wire shape stays
	 * identical across both:
	 *
	 *   - `/checkout-sessions` continue_url (built by the REST
	 *     controller's `build_continue_url()`). The continue_url is the
	 *     primary attribution path: agents redirect the buyer to it,
	 *     WC Order Attribution captures the params on the resulting
	 *     order.
	 *
	 *   - `/catalog/search` and `/catalog/lookup` product `url` field
	 *     (applied by `WC_AI_Storefront_UCP_Product_Translator`). The
	 *     bare product permalink is what agents render as the "view
	 *     product" link in chat. Buyers who follow that link directly,
	 *     add to cart, and check out (rather than going through the
	 *     agent's checkout-session integration) need the same UTM
	 *     payload to attribute correctly. Without this, those orders
	 *     bucket as "direct" or get attributed to the agent's referrer
	 *     header — invisible to the AI-orders dashboard.
	 *
	 * UTM shape (canonical 0.5.0+):
	 *
	 *   - `utm_source` — `$source_host` when non-empty, else
	 *     `WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE`
	 *     (`ucp_unknown`). Keeping the sentinel preserves the "agent
	 *     didn't identify itself" cohort as a distinct row in WC
	 *     Origin breakdowns rather than collapsing it into "direct".
	 *
	 *   - `utm_medium` — `referral` (Google-canonical). AI agent
	 *     traffic IS referral traffic by Google's analytics taxonomy;
	 *     `referral` lets GA4 auto-bucket under the Referral default
	 *     channel grouping rather than "Unassigned".
	 *
	 *   - `utm_id` — `WOO_UCP_ID` (`woo_ucp`). The "we routed this"
	 *     flag the STRICT attribution gate matches on, regardless of
	 *     `utm_source`/`utm_medium` values. Decouples WHO sent the
	 *     user (utm_source) from HOW it was routed (utm_id).
	 *
	 * Plus, when `$raw_host` is non-empty, an `ai_agent_host_raw`
	 * param carries the producer-side raw identifier for "Other AI"
	 * drill-in. Captured by `capture_ai_attribution()` into
	 * `AGENT_HOST_RAW_META_KEY`. Empty raw_host (no UCP-Agent header
	 * AND no body fallback) means the param is omitted entirely — no
	 * spurious `&ai_agent_host_raw=` in the URL.
	 *
	 * Implementation uses string concatenation rather than
	 * `add_query_arg()` for two reasons:
	 *
	 *   - `add_query_arg()` runs existing query parameters through
	 *     `urlencode_deep()` and rebuilds via `build_query()`, which
	 *     re-encodes characters that the original URL left raw.
	 *     Pre-0.6.4 `build_continue_url()` used string concat and
	 *     produced `?products=100:2&...` with a literal `:`. Routing
	 *     through `add_query_arg()` would silently change the wire
	 *     shape to `?products=100%3A2&...` — semantically equivalent
	 *     but a real byte-level diff for any agent with a fragile URL
	 *     parser. Wire-shape stability across this refactor matters
	 *     more than `add_query_arg()`'s "merge into existing query"
	 *     elegance.
	 *
	 *   - Permalinks with existing query strings (Polylang/WPML
	 *     `?lang=fr`, custom rewrite-rule params, paginated archives)
	 *     are handled by the `?` vs `&` separator check below — naive
	 *     "always append `?utm_source=`" would produce
	 *     `permalink?lang=fr?utm_source=...` (broken — the second `?`
	 *     becomes part of `lang`'s value). Detecting existing `?` and
	 *     using `&` keeps both clean-permalink and existing-query
	 *     cases correct without `add_query_arg()`'s re-encoding side
	 *     effect.
	 *
	 * @param string $url         Base URL to tag. May or may not
	 *                            already have a query string.
	 * @param string $source_host Lowercase identifier for `utm_source`:
	 *                            usually a normalized hostname (e.g.
	 *                            `chatgpt.com`); may be a lowercase
	 *                            product / agent token fallback when
	 *                            no hostname mapping exists. Empty
	 *                            falls back to `FALLBACK_SOURCE`.
	 * @param string $raw_host    Untransformed identifier from the
	 *                            UCP-Agent header or body field.
	 *                            Empty string skips the
	 *                            `ai_agent_host_raw` param entirely.
	 *                            Defaults to empty.
	 * @return string             URL with attribution params stamped.
	 */
	public static function with_woo_ucp_utm( string $url, string $source_host, string $raw_host = '' ): string {
		$utm_source = '' !== $source_host
			? $source_host
			: WC_AI_Storefront_UCP_Agent_Header::FALLBACK_SOURCE;

		// Strip `#fragment` first so the appended query lands BEFORE
		// it on rejoin. WC permalinks shouldn't carry fragments today,
		// but defensive: a third-party plugin that adds `#reviews` /
		// `#description` deep-links to product permalinks would
		// otherwise produce a broken URL like
		// `/product/widget/#reviews?utm_source=...`, where the `?` is
		// part of the fragment per RFC 3986 (fragment runs to
		// end-of-URI). Splitting before append puts the query in the
		// right structural position: `/product/widget/?utm_source=...#reviews`.
		$fragment   = '';
		$hash_index = strpos( $url, '#' );
		if ( false !== $hash_index ) {
			$fragment = substr( $url, $hash_index );
			$url      = substr( $url, 0, $hash_index );
		}

		// `?` if URL has no query yet; `&` to append onto existing
		// query. `str_contains()` over the simpler `false === strpos()`
		// for readability — same semantics, PHP 8.0+.
		$separator = str_contains( $url, '?' ) ? '&' : '?';

		$url .= $separator
			. 'utm_source=' . rawurlencode( $utm_source )
			. '&utm_medium=referral'
			. '&utm_id=' . self::WOO_UCP_ID;

		if ( '' !== $raw_host ) {
			$url .= '&ai_agent_host_raw=' . rawurlencode( $raw_host );
		}

		return $url . $fragment;
	}

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

		// Bust stats transient cache when order status changes or an order is
		// removed so the admin dashboard stays accurate within one TTL cycle.
		// Status-change hooks pass $order_id; bust_stats_cache() uses it to skip
		// the bust for non-AI orders (P-11 — see method docblock).
		// Delete/trash hooks are hooked with 0 accepted args (bust unconditionally)
		// because the order may already be gone at hook-fire time.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'bust_stats_cache' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'bust_stats_cache' ) );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'bust_stats_cache' ), 10, 0 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'bust_stats_cache' ), 10, 0 );
	}

	/**
	 * Capture AI attribution data from the request onto the order.
	 *
	 * Two recognition gates (evaluated in parallel via OR):
	 *
	 *   1. STRICT — `utm_id === 'woo_ucp'` (canonical 0.5.0+ shape)
	 *      OR legacy `utm_medium === 'ai_agent'` (pre-0.5.0 shape).
	 *      Both are signals our own `build_continue_url()` emits, so
	 *      any order placed via a link OUR /checkout-sessions
	 *      endpoint produced lands here. This is the canonical
	 *      "we routed this" signal.
	 *
	 *   2. LENIENT — utm_source matches a known AI agent host KEY
	 *      in `KNOWN_AGENT_HOSTS`. Agents that bypass our endpoint
	 *      and build their own checkout-link URL (e.g. UCPPlayground
	 *      sending `?utm_source=ucpplayground.com&utm_medium=referral`)
	 *      get recognized via the host match. Without this gate, those
	 *      orders looked like ordinary referral traffic and never got
	 *      `_wc_ai_storefront_agent` meta — surfacing as
	 *      "AI orders = 0" in the dashboard even when AI agents drove
	 *      real purchases.
	 *
	 * Post-0.5.0 our OWN continue_url emits `utm_medium=referral` and
	 * a hostname-shaped `utm_source` — the same pattern bypass-path
	 * agents naturally use. The STRICT gate's `utm_id` check is what
	 * identifies "we routed this" specifically; the LENIENT gate is
	 * the catch-all for agent-routed orders regardless of who emitted
	 * the URL.
	 *
	 * The host match is safe because `KNOWN_AGENT_HOSTS` is a small,
	 * code-controlled allow-list. A random referrer can't spoof
	 * itself into AI attribution by sending
	 * `utm_source=evil.example` because that hostname isn't in the
	 * map. An attacker WOULD have to (a) know we recognize a host
	 * AND (b) want to attribute their fake order to that host's
	 * brand — a narrow, low-payoff combination because attributing
	 * a fake order to "ChatGPT" provides no economic benefit to the
	 * attacker, only to the merchant's stats accounting.
	 *
	 * Stored values:
	 *   - `AGENT_META_KEY` = canonical brand name when host-matched
	 *     (so the Recent Orders display + Top Agent stats show
	 *     "UCPPlayground", not "ucpplayground.com"). Falls back to
	 *     the raw utm_source for orders matched by STRICT but not
	 *     LENIENT (utm_source is non-empty but isn't in
	 *     KNOWN_AGENT_HOSTS — common for unknown agents that still
	 *     stamped utm_id=woo_ucp).
	 *   - `AGENT_HOST_RAW_META_KEY` = a clean identifier value for
	 *     "Other AI" drill-in or graduation review. Two writers:
	 *     the strict-gate path stamps the `ai_agent_host_raw` URL
	 *     param verbatim (already producer-side normalized); the
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
		// WooCommerce Order Attribution stores utm_medium / utm_id in order meta.
		$utm_medium = $order->get_meta( '_wc_order_attribution_utm_medium' );
		$utm_id     = $order->get_meta( '_wc_order_attribution_utm_id' );

		// Resolve utm_source up front — both STRICT and LENIENT gates need it.
		$utm_source = $order->get_meta( '_wc_order_attribution_utm_source' );
		if ( ! $utm_source ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params.
			$utm_source = isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '';
		}

		// Two ways to recognize an AI order, evaluated in parallel
		// (OR-combined; no ordering implied):
		//
		//   1. STRICT: utm_id === 'woo_ucp' (canonical 0.5.0+ shape) OR
		//      legacy utm_medium === 'ai_agent' (pre-0.5.0 shape).
		//      Both signals were emitted by our own continue_url
		//      builder. Dual-checking keeps already-placed orders
		//      attributing correctly through the upgrade window; see
		//      `AI_AGENT_MEDIUM` docblock above for the legacy-branch
		//      removal horizon.
		//
		//   2. LENIENT: utm_source is an exact match for a hostname
		//      KEY in `KNOWN_AGENT_HOSTS` (e.g. `ucpplayground.com`,
		//      `openai.com`). Agents that bypass our /checkout-sessions
		//      endpoint and build their own checkout-link URL set
		//      whatever utm_medium they want (UCPPlayground sends
		//      `referral`, others may send `agent`, `ai`, `bot`, etc.).
		//      The host identifies the agent unambiguously regardless.
		//
		//      Note: post-0.5.0 our OWN continue_url ALSO emits
		//      `utm_medium=referral` and a hostname-shaped utm_source.
		//      Our own emissions are caught by STRICT (via utm_id) and
		//      ALSO by LENIENT (via the hostname). Either gate firing
		//      is enough; both firing is harmless because the second
		//      check is a no-op once `$is_strict || $is_known_ai_host`
		//      already short-circuits the early return.
		//
		//      Critical: we match the hostname KEY set, NOT the
		//      canonical-name VALUE set. The hostname keys are a
		//      small, code-controlled allow-list (~15 entries) that
		//      we explicitly curate; an attacker who wanted to spoof
		//      AI attribution would have to (a) know we recognize a
		//      specific host AND (b) want their fake order to
		//      attribute to that host's brand. Both are publicly
		//      guessable in isolation, but the combination provides
		//      no economic benefit to an attacker — they'd be
		//      poisoning the merchant's stats accounting with no
		//      payoff to themselves.
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

		// STRICT: 0.5.0+ utm_id flag OR legacy utm_medium=ai_agent.
		// Only the order's stored _wc_order_attribution_utm_* meta is
		// authoritative here. WC core writes those fields from the
		// landing-page session, which is the correct attribution
		// moment. Reading $_GET at order-creation time is a different
		// navigation context — stale UTM params from a forwarded link
		// or a previous checkout attempt would fire false-positives and
		// inflate "Other AI" stats with non-AI orders.
		$is_strict = self::WOO_UCP_ID === $utm_id
			|| self::AI_AGENT_MEDIUM === $utm_medium;

		if ( ! $is_strict && ! $is_known_ai_host ) {
			return;
		}

		// Capture AI session ID from request. Cap at 128 chars before writing to
		// order meta: sanitize_text_field() strips tags but does not limit length,
		// so an uncapped value could write up to 65,535 bytes (longtext column
		// limit) to the meta table per order (CWE-20 / FIND-S04).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading attribution params.
		$session_id = isset( $_GET['ai_session_id'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['ai_session_id'] ) ), 0, 128 ) : '';
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
		//   - STRICT-only (utm_id=woo_ucp or legacy utm_medium=ai_agent
		//     fired but utm_source is not in `KNOWN_AGENT_HOSTS`):
		//     `OTHER_AI_BUCKET` ("Other AI"). Pre-0.5.2 we stored the
		//     raw utm_source verbatim here, but with the canonical
		//     UTM shape (0.5.0+) emitting hostname-shaped utm_source
		//     for unknown agents (e.g. `agent.example.com`), that
		//     fragmented `get_stats()` and the Top Agent card into
		//     long-tail one-off buckets instead of rolling up cleanly
		//     under "Other AI". The raw identifier is still preserved
		//     in `_wc_ai_storefront_agent_host_raw` for drill-in /
		//     graduation review, and WC core's
		//     `_wc_order_attribution_utm_source` still has the raw
		//     value too — this change only affects the AI-specific
		//     surface meta.
		//   - Empty-utm_source edge case: empty string fall-through
		//     skips the stamp below entirely (the `'' !==` guard).
		$canonical_agent = '';
		if ( $is_known_ai_host ) {
			$canonical_agent = $lenient_canonical;
		} elseif ( $is_strict && '' !== (string) $utm_source ) {
			$canonical_agent = WC_AI_Storefront_UCP_Agent_Header::OTHER_AI_BUCKET;
		}

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
		//
		// Spoofing blast radius: a buyer who tampers `ai_agent_host_raw`
		// can only affect the "Agent host:" line in the admin order
		// detail panel — a cosmetic, informational field. It cannot
		// influence stats or routing: `get_stats()` groups exclusively
		// by `AGENT_META_KEY` (the canonical name written from
		// KNOWN_AGENT_HOSTS or the strict gate), never by this field.
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
		// Only write the URL-param value when the lenient gate did NOT
		// already write a normalized hostname above. When both fire,
		// the KNOWN_AGENT_HOSTS-validated normalized value is the
		// authoritative answer; a diverging URL-param value (e.g. an
		// agent that puts a Product/Version-form token in
		// ai_agent_host_raw while utm_source carries the canonical
		// lookup key) would contradict the class docblock contract and
		// confuse the admin drill-in display.
		if ( '' !== $raw_host && ! $is_known_ai_host ) {
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
		 *                                  When the normalized
		 *                                  `utm_source` matches a known
		 *                                  host (`KNOWN_AGENT_HOSTS`
		 *                                  key), this is the canonical
		 *                                  brand name (e.g. "ChatGPT");
		 *                                  otherwise it is the
		 *                                  `utm_source` value verbatim.
		 *                                  The rule is path-independent:
		 *                                  a strict-gate capture whose
		 *                                  `utm_source` happens to be a
		 *                                  hostname matching
		 *                                  `KNOWN_AGENT_HOSTS` gets
		 *                                  canonicalized too. Listeners
		 *                                  shouldn't switch logic on
		 *                                  which gate fired.
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
		// Normalize period first so the transient key is consistent.
		$valid_periods = array( 'day', 'week', 'month', 'year' );
		$period        = in_array( $period, $valid_periods, true ) ? $period : 'month';

		$transient_key = 'wc_ai_storefront_stats_' . $period;
		$cached        = get_transient( $transient_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$date_map = [
			'day'   => '1 day ago',
			'week'  => '1 week ago',
			'month' => '1 month ago',
			'year'  => '1 year ago',
		];

		// $period is already validated to one of the four keys above.
		$after    = $date_map[ $period ];
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

		$result_array = array(
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
		);

		set_transient( $transient_key, $result_array, 5 * MINUTE_IN_SECONDS );
		return $result_array;
	}

	/**
	 * Invalidate all cached stats transients. Called when order attribution
	 * data changes (e.g., after a new order is attributed or via admin reset).
	 *
	 * When $order_id > 0 (status-change hooks), skip the bust unless the order
	 * carries `_wc_ai_storefront_agent` meta. On busy stores, every order status
	 * change would otherwise invalidate the 5-minute stats cache; at 1,000
	 * orders/hr the cache hit rate approaches 0 even though AI-attributed orders
	 * may represent a small fraction. Delete/trash hooks pass no order_id
	 * ($order_id = 0) and always bust — those are rare events and the order
	 * may already be removed from the DB when the hook fires.
	 *
	 * @param int $order_id WC order ID from status-change hooks, or 0 for delete/trash.
	 */
	public static function bust_stats_cache( int $order_id = 0 ): void {
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			// HPOS-compatible check: get_meta() works on both HPOS and legacy orders.
			if ( $order instanceof WC_Order && ! $order->get_meta( self::AGENT_META_KEY ) ) {
				return; // Non-AI order status change — preserve the stats cache.
			}
		}

		foreach ( array( 'day', 'week', 'month', 'year' ) as $period ) {
			delete_transient( 'wc_ai_storefront_stats_' . $period );
		}
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
