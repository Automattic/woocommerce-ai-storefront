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
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'template_redirect', [ $this, 'serve_llms_txt' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
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

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );

		echo $this->get_cached_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown content.
		exit;
	}

	/**
	 * Get cached llms.txt content, regenerating if expired.
	 *
	 * @return string Markdown content.
	 */
	private function get_cached_content() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			WC_AI_Syndication_Logger::debug( 'llms.txt cache hit' );
			return $cached;
		}

		WC_AI_Syndication_Logger::debug( 'llms.txt cache miss — regenerating' );
		$content = $this->generate();
		set_transient( self::CACHE_KEY, $content, HOUR_IN_SECONDS );
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
		$lines[]    = '## API Access';
		$lines[]    = '';
		$store_api  = rest_url( 'wc/store/v1' );
		$ucp_url    = $site_url . '.well-known/ucp';
		$lines[]    = "- **Store API**: `{$store_api}` — public WooCommerce Store API for product search and cart operations (no authentication required)";
		$lines[]    = "- **Commerce Protocol Manifest**: `{$ucp_url}` — declares capabilities, checkout policy, and purchase URL templates";
		$lines[]    = '';

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
