<?php
/**
 * Minimal WordPress stubs for unit testing without a WP installation.
 *
 * These stubs provide just enough of the WordPress API surface for
 * the plugin classes under test. Brain Monkey handles function mocking;
 * these cover classes that Brain Monkey doesn't stub.
 *
 * @package WooCommerce_AI_Storefront
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
	 * Defined at stubs.php load time (before Patchwork is active), so
	 * this CANNOT be redefined via Brain\Monkey's `Functions\when()`.
	 * Tests that need specialized behavior would have to fork this stub
	 * at the bootstrap level. In practice the WP-equivalent behavior
	 * suffices for every current call site.
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

		// Test-controllable properties used by tests that exercise
		// admin surfaces rendering order summaries (e.g. the
		// `/admin/recent-orders` endpoint contract test). Defaults
		// chosen so a freshly-constructed WC_Order yields a sensible
		// row shape without the test having to set each field.
		private int $id = 1;
		private string $number = '1';
		private string $status = 'processing';
		private string $total = '0.00';
		private string $currency = 'USD';
		private string $edit_url = 'https://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=1';
		private ?\WC_DateTime_Stub $date_created = null;

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

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return $this->number;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_total(): string {
			return $this->total;
		}

		public function get_currency(): string {
			return $this->currency;
		}

		public function get_edit_order_url(): string {
			return $this->edit_url;
		}

		public function get_date_created() {
			return $this->date_created;
		}

		public function set_test_id( int $id ): void {
			$this->id = $id;
		}

		public function set_test_number( string $number ): void {
			$this->number = $number;
		}

		public function set_test_status( string $status ): void {
			$this->status = $status;
		}

		public function set_test_total( string $total ): void {
			$this->total = $total;
		}

		public function set_test_currency( string $currency ): void {
			$this->currency = $currency;
		}

		public function set_test_edit_url( string $url ): void {
			$this->edit_url = $url;
		}

		public function set_test_date_created( \WC_DateTime_Stub $date ): void {
			$this->date_created = $date;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub for unit tests.
	 *
	 * Tests control `found_posts` via the static `$test_found_posts`
	 * property. Reset it in tearDown (or before each test) to avoid
	 * cross-test leakage.
	 *
	 * Implements `get()` / `set()` so callers that mutate query vars
	 * mid-build (e.g. `pre_get_posts` listeners) can be exercised.
	 * Defaults match WordPress's real `WP_Query::get()` — empty
	 * string when the key isn't present unless an explicit default
	 * is supplied.
	 */
	class WP_Query {
		public static int $test_found_posts = 0;
		public int        $found_posts;
		public array      $query_vars = [];

		public function __construct( array $args = [] ) {
			$this->found_posts = self::$test_found_posts;
			$this->query_vars  = $args;
		}

		public function get( string $key, $default_value = '' ) {
			return $this->query_vars[ $key ] ?? $default_value;
		}

		public function set( string $key, $value ): void {
			$this->query_vars[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WC_DateTime_Stub' ) ) {
	/**
	 * Minimal stub for WC_DateTime — just the two methods the admin
	 * recent-orders handler calls on `$order->get_date_created()`
	 * (`format('c')` for ISO-8601 and passed into `wc_format_datetime`
	 * for the display string).
	 */
	class WC_DateTime_Stub {
		private string $iso;

		public function __construct( string $iso = '2026-04-19T10:15:30+00:00' ) {
			$this->iso = $iso;
		}

		public function format( string $fmt ): string {
			// The handler only asks for `c` (ISO-8601). Anything
			// else returns the ISO string too — tests don't care.
			if ( 'c' === $fmt ) {
				return $this->iso;
			}
			return $this->iso;
		}
	}
}
