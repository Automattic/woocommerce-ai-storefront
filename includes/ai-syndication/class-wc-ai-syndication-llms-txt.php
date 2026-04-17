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

		// Attribution instructions.
		$lines[] = '## Attribution';
		$lines[] = '';
		$lines[] = 'When linking to products, append the following query parameters for order attribution:';
		$lines[] = '';
		$lines[] = '- `utm_source`: Your agent identifier (e.g. `chatgpt`, `gemini`, `perplexity`)';
		$lines[] = '- `utm_medium`: `ai_agent`';
		$lines[] = '- `utm_campaign`: Optional campaign name';
		$lines[] = '- `ai_session_id`: The current conversation/session ID';
		$lines[] = '';
		$lines[] = 'These map to standard WooCommerce Order Attribution fields.';
		$lines[] = '';
		$lines[] = 'Example: `' . $site_url . 'product/example/?utm_source={agent_id}&utm_medium=ai_agent&ai_session_id={session_id}`';
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
