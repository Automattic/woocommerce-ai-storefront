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
