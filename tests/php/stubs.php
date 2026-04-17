<?php
/**
 * Minimal WordPress stubs for unit testing without a WP installation.
 *
 * These stubs provide just enough of the WordPress API surface for
 * the plugin classes under test. Brain Monkey handles function mocking;
 * these cover classes that Brain Monkey doesn't stub.
 *
 * @package WooCommerce_AI_Syndication
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Minimal wp_parse_url stub. WordPress's own version wraps PHP
	 * native parse_url to normalize some cross-version quirks around
	 * protocol-relative URLs. For test purposes the native function
	 * is close enough — callers typically only ask for one component.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component PHP_URL_* constant (default -1 = all).
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * wp_strip_all_tags stub mirroring WordPress core's implementation.
	 *
	 * Differs from PHP native `strip_tags()` in two ways: strips the
	 * CONTENT of `<script>` and `<style>` tags (not just the tags
	 * themselves), and trims surrounding whitespace. Tests that exercise
	 * the translators (which switched from strip_tags to wp_strip_all_tags
	 * for safer behavior on rich-text-editor input) rely on this stub.
	 *
	 * Tests that Brain\Monkey-stub this function (e.g. LlmsTxtTest)
	 * win over this global definition because Brain\Monkey's aliasing
	 * redefines the symbol at test-setup time.
	 *
	 * @param mixed $text          Input string.
	 * @param bool  $remove_breaks Whether to also collapse internal whitespace.
	 */
	function wp_strip_all_tags( $text, bool $remove_breaks = false ): string {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$text = (string) $text;
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = [];
		private array $headers = [];
		private string $route = '';
		private string $method = '';

		/**
		 * Parsed JSON body. Distinct from form-encoded params so
		 * handlers calling `get_json_params()` vs `get_param()` can
		 * be exercised independently.
		 *
		 * @var ?array<string, mixed>
		 */
		private ?array $json_params = null;

		public function __construct( string $method = '', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			// Match WP behavior: get_param checks JSON body, then regular
			// params, returning the first match. Handlers that call
			// get_param('ids') should see the ids array whether it was
			// delivered via JSON body or form body.
			if ( null !== $this->json_params && array_key_exists( $key, $this->json_params ) ) {
				return $this->json_params[ $key ];
			}
			return $this->params[ $key ] ?? null;
		}

		public function get_header( string $key ): ?string {
			$normalized = strtolower( str_replace( '-', '_', $key ) );
			return $this->headers[ $normalized ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$normalized = strtolower( str_replace( '-', '_', $key ) );
			$this->headers[ $normalized ] = $value;
		}

		public function set_route( string $route ): void {
			$this->route = $route;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function set_method( string $method ): void {
			$this->method = $method;
		}

		/**
		 * @param array<string, mixed> $params
		 */
		public function set_json_params( array $params ): void {
			$this->json_params = $params;
		}

		/**
		 * @return ?array<string, mixed>
		 */
		public function get_json_params(): ?array {
			return $this->json_params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		private array $headers = [];
		private int $status = 200;

		public function __construct( $data = null, int $status = 200, array $headers = [] ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function set_status( int $status ): void {
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function set_data( $data ): void {
			$this->data = $data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const EDITABLE  = 'POST, PUT, PATCH';
		const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * Minimal WC_Product stub. This stub is also consumed by PHPStan
	 * for static analysis — every method actually called by the
	 * plugin code on a product instance must be declared here, or
	 * PHPStan will report `method.notFound`. Tests override return
	 * values via Mockery; PHPStan uses the signatures for type
	 * checking only.
	 */
	class WC_Product {
		protected int $id = 1;
		protected string $type = 'simple';
		protected string $permalink = 'https://example.com/product/test/';
		protected string $external_url = '';

		public function __construct( int $id = 1, string $type = 'simple' ) {
			$this->id   = $id;
			$this->type = $type;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_type(): string {
			return $this->type;
		}

		public function get_permalink(): string {
			return $this->permalink;
		}

		public function set_permalink( string $url ): void {
			$this->permalink = $url;
		}

		public function get_product_url(): string {
			return $this->external_url;
		}

		public function set_product_url( string $url ): void {
			$this->external_url = $url;
		}

		public function is_purchasable(): bool {
			return true;
		}

		public function is_in_stock(): bool {
			return true;
		}

		public function get_name(): string {
			return 'Test Product';
		}

		public function get_price(): string {
			return '19.99';
		}

		// Stock.
		public function managing_stock(): bool {
			return false;
		}

		public function get_stock_quantity(): ?int {
			return null;
		}

		// Weight + dimensions (JSON-LD enhancer).
		public function has_weight(): bool {
			return false;
		}

		public function get_weight(): string {
			return '';
		}

		public function has_dimensions(): bool {
			return false;
		}

		/**
		 * @return array{length: string, width: string, height: string}
		 */
		public function get_dimensions( bool $formatted = false ): array {
			return [ 'length' => '', 'width' => '', 'height' => '' ];
		}

		/**
		 * @return array<string, object>
		 */
		public function get_attributes(): array {
			return [];
		}

		public function get_attribute( string $name ): string {
			return '';
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		private array $meta = [];
		private bool $saved = false;

		public function get_meta( string $key ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function save(): void {
			$this->saved = true;
		}

		public function was_saved(): bool {
			return $this->saved;
		}

		public function set_test_meta( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}
	}
}
