<?php
/**
 * AI Syndication: llms.txt Generator
 *
 * Generates a machine-readable Markdown document at /llms.txt
 * that gives AI crawlers a direct guide to the store's products
 * and API capabilities.
 *
 * @package WooCommerce_AI_Syndication
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles generation and serving of the llms.txt file.
 */
class WC_AI_Syndication_Llms_Txt {

	/**
	 * Transient key for cached llms.txt content.
	 */
	const CACHE_KEY = 'wc_ai_syndication_llms_txt';

	/**
	 * Short-circuit canonical-URL redirects for the llms.txt endpoint.
	 *
	 * @param string|false $redirect_url The candidate canonical URL
	 *                                   WordPress wants to redirect to.
	 * @return string|false               False disables the redirect;
	 *                                   original value otherwise.
	 */
	public function suppress_canonical_redirect( $redirect_url ) {
		if ( get_query_var( 'wc_ai_syndication_llms_txt' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Add rewrite rule for /llms.txt.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?wc_ai_syndication_llms_txt=1', 'top' );
	}

	/**
	 * Register query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wc_ai_syndication_llms_txt';
		return $vars;
	}

	/**
	 * Serve the llms.txt response.
	 *
	 * Response headers are tuned for maximum compatibility with the
	 * AI-tooling fleet (Gemini's browsing tool, ChatGPT browse, Claude
	 * web search, Perplexity spider, plus CLI fetchers):
	 *
	 * - `Content-Type: text/plain`: the llms.txt spec (RFC-style memo
	 *   by Jeremy Howard) accepts either `text/plain` or
	 *   `text/markdown`. We serve `text/plain` because some headless-
	 *   browser tooling has MIME allow-lists that don't include
	 *   `text/markdown` and will drop the response. Plain-text is the
	 *   universal fallback and still renders correctly in the merchant's
	 *   browser when they visit the URL directly.
	 *
	 * - `Access-Control-Allow-Origin: *`: required so AI browsing tools
	 *   running in Chromium-based contexts (where CORS applies even on
	 *   tool-initiated fetches) can read the resource. Without it the
	 *   file is invisible to Gemini's tool — the UCP manifest (which
	 *   sets CORS) reads fine, llms.txt (which didn't) did not. Symmetry
	 *   fixes discovery.
	 *
	 * - `X-Content-Type-Options: nosniff`: prevents MIME sniffing from
	 *   mis-classifying the response (e.g. as HTML if the merchant's
	 *   content happens to begin with an `<` character). Small hardening.
	 *
	 * - `Cache-Control: public, max-age=3600`: 1-hour client/proxy cache
	 *   matches the transient TTL inside `get_cached_content()` — both
	 *   refresh on the same cadence, so the merchant never sees a stale
	 *   file served while the internal cache has been rebuilt.
	 *
	 * (No `X-Robots-Tag: noindex`): earlier revisions set noindex to
	 * keep llms.txt out of human-facing search results, but 1.4.4
	 * dropped it. Some AI browsing tools (notably Gemini) appear to
	 * use Google's search index as a discovery layer — when they
	 * find a URL in the index they'll fetch it; when the URL is
	 * noindexed they never try. Because llms.txt exists specifically
	 * to be discovered, noindex was working against the plugin's
	 * own purpose. Agents that go direct to `/llms.txt` continue to
	 * work either way; agents that search-first now work too.
	 */
	public function serve_llms_txt() {
		if ( ! get_query_var( 'wc_ai_syndication_llms_txt' ) ) {
			return;
		}

		$settings = WC_AI_Syndication::get_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

		// Respond to CORS preflights without a body. Some browsing
		// tools fire OPTIONS first and treat a non-2xx preflight as
		// "resource unreachable" even if the GET would have succeeded.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}

		echo $this->get_cached_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown content.
		exit;
	}

	/**
	 * Get cached llms.txt content, regenerating if expired.
	 *
	 * Cache-hit detection must exclude both `false` (the transient
	 * miss sentinel) AND empty strings. Before 1.4.4 the check was
	 * `false !== $cached`, which treated an empty cached string as
	 * a valid hit — a real bug that surfaced in production:
	 * `generate()` had captured empty content during a transient
	 * bad state (likely a handler that never ran because of the
	 * 1.4.2 wiring bug), and the empty value stuck in the cache for
	 * the full 1-hour TTL, serving blank responses even after every
	 * upstream fix.
	 *
	 * Defensive matching pair: we also refuse to write empty content
	 * into the cache in the first place, so a single bad
	 * generate() call can't poison the next hour of responses.
	 *
	 * @return string Markdown content.
	 */
	private function get_cached_content() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && '' !== $cached ) {
			WC_AI_Syndication_Logger::debug( 'llms.txt cache hit' );
			return $cached;
		}

		WC_AI_Syndication_Logger::debug( 'llms.txt cache miss — regenerating' );
		$content = $this->generate();

		// Only cache non-empty content. Caching an empty string would
		// re-create the poisoning scenario the cache-hit check above
		// now defends against; belt + suspenders.
		if ( '' !== $content ) {
			set_transient( self::CACHE_KEY, $content, HOUR_IN_SECONDS );
		} else {
			WC_AI_Syndication_Logger::debug( 'llms.txt generate() returned empty — not caching' );
		}

		return $content;
	}

	/**
	 * Generate the llms.txt content.
	 *
	 * @return string Markdown content.
	 */
	public function generate() {
		$site_name   = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' );
		$site_url    = home_url( '/' );
		$description = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'description' ) ), ENT_QUOTES, 'UTF-8' );
		$currency    = get_woocommerce_currency();
		$settings    = WC_AI_Syndication::get_settings();

		$lines   = [];
		$lines[] = "# {$site_name}";
		$lines[] = '';

		if ( $description ) {
			$lines[] = "> {$description}";
			$lines[] = '';
		}

		$lines[] = 'This store accepts AI-assisted product discovery. Checkout occurs exclusively on this website.';
		$lines[] = '';

		// Store metadata.
		$lines[] = '## Store Information';
		$lines[] = '';
		$lines[] = "- **URL**: {$site_url}";
		$lines[] = "- **Currency**: {$currency}";
		$lines[] = '- **Checkout**: On-site only (web redirect)';
		$lines[] = "- **Commerce Protocol**: {$site_url}.well-known/ucp";
		$lines[] = '';

		// API access. This plugin does NOT expose its own authenticated
		// API — AI agents use WooCommerce's public Store API directly.
		// The UCP manifest describes purchase URL templates and checkout
		// policy in machine-readable form; agents that want structured
		// data fetch that document.
		$lines[]   = '## API Access';
		$lines[]   = '';
		$store_api = rest_url( 'wc/store/v1' );
		$ucp_url   = $site_url . '.well-known/ucp';
		$lines[]   = "- **Store API**: `{$store_api}` — public WooCommerce Store API for product search and cart operations (no authentication required)";
		$lines[]   = "- **Commerce Protocol Manifest**: `{$ucp_url}` — declares capabilities, checkout policy, and purchase URL templates";
		$lines[]   = '';

		// Sitemaps section. Surfaces exhaustive URL enumeration
		// paths for agents doing deep catalog discovery — parallel
		// to robots.txt's per-bot `Allow:` sitemap entries from
		// 1.6.1/1.6.2, but in llms.txt's human+machine-readable
		// narrative form. Unlike robots.txt (where `Allow:` for
		// non-existent paths is harmless), here we probe each
		// candidate via HEAD so only URLs that actually respond
		// make it into the document. Probes are synchronous but
		// amortized by the 1-hour transient cache in
		// `get_cached_content()` — one round of probing per
		// cache miss.
		$sitemap_urls = self::discover_sitemap_urls( $site_url );
		if ( ! empty( $sitemap_urls ) ) {
			$lines[] = '## Sitemaps';
			$lines[] = '';
			$lines[] = 'Exhaustive URL lists for catalog enumeration. Agents wanting every product/category URL in one pass fetch these instead of paginating the Store API.';
			$lines[] = '';
			foreach ( $sitemap_urls as $sitemap_url ) {
				$lines[] = "- {$sitemap_url}";
			}
			$lines[] = '';
		}

		// Product categories summary.
		$categories = $this->get_syndicated_categories( $settings );
		if ( ! empty( $categories ) ) {
			$lines[] = '## Product Categories';
			$lines[] = '';
			foreach ( $categories as $category ) {
				$link = get_term_link( $category );
				if ( ! is_wp_error( $link ) ) {
					$cat_name    = html_entity_decode( wp_strip_all_tags( $category->name ), ENT_QUOTES, 'UTF-8' );
					$count_label = 1 === (int) $category->count ? 'product' : 'products';
					$lines[]     = "- [{$cat_name}]({$link}) ({$category->count} {$count_label})";
				}
			}
			$lines[] = '';
		}

		// Featured/popular products.
		$product_data = $this->get_featured_products( $settings );
		if ( ! empty( $product_data['products'] ) ) {
			$section_title   = $product_data['is_featured'] ? 'Featured Products' : 'Popular Products';
			$lines[]         = "## {$section_title}";
			$lines[]         = '';
			$currency_symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			foreach ( $product_data['products'] as $product ) {
				$product_name = html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' );
				$price        = $currency_symbol . $product->get_price();
				$lines[]      = "- [{$product_name}](" . $product->get_permalink() . ") - {$price}";
			}
			$lines[] = '';
		}

		// Checkout policy declaration. Makes explicit the merchant-
		// only-checkout posture that the UCP manifest already
		// declares implicitly (by what it doesn't advertise:
		// no payment_handlers, no ap2_mandate capability, no cart
		// capability, REST-only transport). Redundant signaling
		// is cheap insurance for agent trust frameworks and for
		// human merchant-review audiences that can't parse UCP.
		$lines[] = '## Checkout Policy';
		$lines[] = '';
		$lines[] = 'All purchases complete on this site (' . $site_url . '). Agents MUST redirect buyers to the `continue_url` returned from `POST ' . rtrim( rest_url( 'wc/ucp/v1' ), '/' ) . '/checkout-sessions` to finalize transactions.';
		$lines[] = '';
		$lines[] = 'This store does NOT support:';
		$lines[] = '';
		$lines[] = '- In-chat or in-agent payment completion';
		$lines[] = '- Embedded checkout (UCP Embedded Protocol / ECP)';
		$lines[] = '- Agent-delegated authorization (AP2 Mandates / Verifiable Digital Credentials)';
		$lines[] = '- Persistent agent-managed carts';
		$lines[] = '- Payment handler tokens (Google Pay, Shop Pay, etc. via UCP)';
		$lines[] = '';
		$lines[] = 'Programmatic verification — the UCP manifest at `' . $site_url . '.well-known/ucp` reflects this posture:';
		$lines[] = '';
		$lines[] = '- `capabilities` contains `dev.ucp.shopping.catalog.search`, `.catalog.lookup`, `.checkout`, plus the `com.woocommerce.ai_syndication` merchant extension — and nothing else';
		$lines[] = '- `payment_handlers` is `{}` (empty — no delegated payment)';
		$lines[] = '- The service binding declares `transport: "rest"` exclusively (no Embedded Protocol, MCP, or A2A)';
		$lines[] = '- Checkout responses always return `status: "requires_escalation"` with `continue_url` — never `ready_for_complete` or `complete_in_progress`';
		$lines[] = '';
		$lines[] = 'This posture is locked by regression tests in the plugin test suite; weakening it requires a deliberate policy decision reflected in a plugin release.';
		$lines[] = '';

		// Attribution instructions. This section is the
		// AUTHORITATIVE merchant-facing guidance for AI-agent
		// attribution. The UCP manifest carries the same parameter
		// set under the `com.woocommerce.ai_syndication` extension,
		// but UCP itself doesn't define attribution semantics —
		// so the canonical guidance lives here in the
		// human+machine-readable document, not in the wire-format
		// manifest.
		//
		// 1.6.5 removed URL-template examples in favor of the
		// API-first flow (`POST /checkout-sessions`), matching the
		// UCP checkout spec's SHOULD directive that businesses
		// should provide continue_url rather than platforms
		// constructing their own. Agents that prefer direct URL
		// construction can still derive the pattern from
		// WooCommerce's public /checkout-link/ documentation —
		// we just don't advertise templates that nudge agents
		// away from the canonical path.
		$ucp_rest_base = rtrim( rest_url( 'wc/ucp/v1' ), '/' );
		$lines[]       = '## Attribution';
		$lines[]       = '';
		$lines[]       = 'The recommended purchase flow is to `POST` line items to our UCP checkout endpoint; the server returns a `continue_url` with attribution pre-attached, and the agent redirects the user there. This matches the UCP checkout specification\'s `requires_escalation` / `continue_url` contract.';
		$lines[]       = '';
		$lines[]       = 'Endpoint:';
		$lines[]       = '';
		$lines[]       = '`POST ' . $ucp_rest_base . '/checkout-sessions`';
		$lines[]       = '';
		$lines[]       = 'Request body (UCP Checkout schema):';
		$lines[]       = '';
		$lines[]       = '```json';
		$lines[]       = '{';
		$lines[]       = '  "line_items": [{ "item": { "id": "123" }, "quantity": 1 }]';
		$lines[]       = '}';
		$lines[]       = '```';
		$lines[]       = '';
		$lines[]       = 'Set the `UCP-Agent` request header to your agent\'s discovery profile URL; the server extracts the hostname, maps it to a short brand name via the table below, and uses that as `utm_source` on the returned `continue_url` — so you do not need to construct UTM parameters yourself.';
		$lines[]       = '';
		$lines[]       = 'Response includes `status: "requires_escalation"` and a `continue_url` with `utm_source` + `utm_medium=ai_agent` already attached. Redirect the user to that URL to complete the purchase on our site.';
		$lines[]       = '';

		// Attribution name mapping. Publishing this table makes
		// attribution a two-way contract instead of merchant-side
		// opaque policy: agents building UCP integrations can see up
		// front what brand name their orders will be attributed under
		// in the merchant's Orders list, and unmapped vendors have a
		// clear path to request canonicalization (the GitHub issue
		// pointer below). We render from
		// WC_AI_Syndication_UCP_Agent_Header::KNOWN_AGENT_HOSTS so the
		// published table and the runtime canonicalizer can't drift —
		// a single source of truth with one reader (the runtime) and
		// one publisher (this generator).
		//
		// Grouping by brand name (not by hostname) is deliberate: one
		// row per brand reads more naturally as "here are the brands
		// we know" than "here are 14 individual hostnames"; the
		// grouping also makes it obvious when a single brand has
		// multiple aliased hostnames (chatgpt.com + openai.com →
		// ChatGPT).
		$grouped = [];
		foreach ( WC_AI_Syndication_UCP_Agent_Header::KNOWN_AGENT_HOSTS as $host => $brand ) {
			$grouped[ $brand ][] = $host;
		}
		ksort( $grouped );

		$lines[] = '### Attribution name mapping';
		$lines[] = '';
		$lines[] = 'When your `UCP-Agent` profile URL hostname matches one of the entries below, orders are attributed under the brand name shown (merchants see `Source: {name}` in the WooCommerce Orders list). Unknown hostnames pass through verbatim — merchants see `Source: your-hostname.example` — so attribution is never lost, just refined for known vendors. Missing or malformed `UCP-Agent` headers are attributed as `ucp_unknown`.';
		$lines[] = '';
		$lines[] = '| Attribution name | Profile hostnames |';
		$lines[] = '|------------------|-------------------|';
		foreach ( $grouped as $brand => $hosts ) {
			$lines[] = '| ' . $brand . ' | `' . implode( '`, `', $hosts ) . '` |';
		}
		$lines[] = '';
		$lines[] = 'If your agent\'s hostname is missing and you\'d like a specific brand name applied, open an issue on the plugin\'s [GitHub repository](https://github.com/pierorocca/woocommerce-ai-syndication/issues) — additions are a single constant entry plus a test row.';
		$lines[] = '';

		$lines[] = 'If you must construct a checkout URL client-side (legacy or non-UCP-aware flow), append these parameters for order attribution:';
		$lines[] = '';
		$lines[] = '- `utm_source`: Your agent identifier (e.g. `chatgpt`, `gemini`, `perplexity`)';
		$lines[] = '- `utm_medium`: `ai_agent`';
		$lines[] = '- `utm_campaign`: Optional campaign name';
		$lines[] = '- `ai_session_id`: The current conversation/session ID';
		$lines[] = '';
		$lines[] = 'These map to standard WooCommerce Order Attribution fields. See the WooCommerce [Shareable Checkout URLs](https://woocommerce.com/document/creating-sharable-checkout-urls-in-woocommerce/) documentation for URL construction patterns.';
		$lines[] = '';

		/**
		 * Filter the llms.txt content lines before rendering.
		 *
		 * @since 1.0.0
		 * @param array $lines    The lines of Markdown content.
		 * @param array $settings The AI syndication settings.
		 */
		$lines = apply_filters( 'wc_ai_syndication_llms_txt_lines', $lines, $settings );

		return implode( "\n", $lines );
	}

	/**
	 * Get categories available for syndication.
	 *
	 * @param array $settings AI syndication settings.
	 * @return WP_Term[]
	 */
	/**
	 * Discover sitemap URLs by probing known paths + WP core's helper.
	 *
	 * Unlike the robots.txt sitemap handling (where `Allow:` for a
	 * non-existent path is a harmless no-op), llms.txt is a
	 * user-facing content document — emitting URLs that 404 would
	 * be factually incorrect. So here we HEAD-probe each candidate
	 * and only include the ones that actually respond.
	 *
	 * Probe sources:
	 *   - `get_sitemap_url( 'index' )` — WP core canonical (5.5+)
	 *   - `WC_AI_Syndication_Robots::COMMON_SITEMAP_PATHS` — common
	 *     plugin paths (Jetpack, Yoast, Rank Math, etc.) appended
	 *     to site URL
	 *
	 * Synchronous HEAD requests with a 1-second timeout, made on
	 * the same origin. Amortized by the 1-hour transient cache in
	 * `get_cached_content()` — probes run once per cache miss, not
	 * per request. Worst case (all 4 paths time out): 4 seconds of
	 * generation latency once per hour. Typical case (paths exist
	 * or fast 404): <500ms.
	 *
	 * @param string $site_url Home URL with trailing slash.
	 * @return string[]        Sitemap URLs that returned 2xx/3xx to
	 *                         a HEAD probe. Empty on sites with no
	 *                         sitemap at any common path.
	 */
	private static function discover_sitemap_urls( string $site_url ): array {
		$candidates = [];

		// WP core canonical (returns full URL when core sitemap is
		// enabled; returns empty string if disabled via filter).
		if ( function_exists( 'get_sitemap_url' ) ) {
			$core = get_sitemap_url( 'index' );
			if ( is_string( $core ) && '' !== $core ) {
				$candidates[] = $core;
			}
		}

		// Common plugin paths, absolute-URL form for llms.txt output.
		$base = rtrim( $site_url, '/' );
		foreach ( WC_AI_Syndication_Robots::COMMON_SITEMAP_PATHS as $path ) {
			$candidates[] = $base . $path;
		}
		$candidates = array_values( array_unique( $candidates ) );

		// HEAD-probe each. Only URLs returning 2xx/3xx make it in.
		$existent = [];
		foreach ( $candidates as $candidate ) {
			$response = wp_remote_head(
				$candidate,
				[
					'timeout'     => 1,
					'redirection' => 1,
					'blocking'    => true,
					// Skip SSL verify on self-origin probes — some
					// local/dev environments have self-signed certs
					// that would otherwise reject the probe.
					'sslverify'   => false,
				]
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				$existent[] = $candidate;
			}
		}

		return $existent;
	}

	private function get_syndicated_categories( $settings ) {
		$args = [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 20,
		];

		$product_mode = $settings['product_selection_mode'] ?? 'all';
		if ( 'categories' === $product_mode && ! empty( $settings['selected_categories'] ) ) {
			$args['include'] = array_map( 'absint', $settings['selected_categories'] );
			$args['number']  = 0;
		}

		$terms = get_terms( $args );
		return is_wp_error( $terms ) ? [] : $terms;
	}

	/**
	 * Get featured (or fallback popular) products for the llms.txt listing.
	 *
	 * @param array $settings AI syndication settings.
	 * @return array{products: WC_Product[], is_featured: bool} Products and a flag
	 *               indicating whether the list came from the featured-products
	 *               query (true) or the popular-products fallback (false).
	 */
	private function get_featured_products( $settings ) {
		$query_args = [
			'status'   => 'publish',
			'limit'    => 10,
			'orderby'  => 'popularity',
			'order'    => 'DESC',
			'featured' => true,
		];

		$product_mode = $settings['product_selection_mode'] ?? 'all';
		if ( 'categories' === $product_mode && ! empty( $settings['selected_categories'] ) ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array_map( 'absint', $settings['selected_categories'] ),
				],
			];
		} elseif ( 'selected' === $product_mode && ! empty( $settings['selected_products'] ) ) {
			$query_args['include'] = array_map( 'absint', $settings['selected_products'] );
			unset( $query_args['featured'] );
		}

		$products    = wc_get_products( $query_args );
		$is_featured = ! empty( $products );

		// Fallback to popular if no featured products exist.
		if ( ! $is_featured ) {
			unset( $query_args['featured'] );
			$products = wc_get_products( $query_args );
		}

		return [
			'products'    => $products,
			'is_featured' => $is_featured,
		];
	}
}
