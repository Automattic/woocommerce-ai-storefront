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
