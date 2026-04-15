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

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = [];
		private array $headers = [];
		private string $route = '';

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
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
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		private array $headers = [];

		public function __construct( $data = null ) {
			$this->data = $data;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
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
