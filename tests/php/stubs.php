<?php
/**
 * Minimal WordPress stubs for unit testing without a WP installation.
 *
 * These stubs provide just enough of the WordPress API surface for
 * the plugin classes under test. Brain Monkey handles function mocking;
 * these cover classes that Brain Monkey doesn't stub.
 *
 * WARNING — these stubs describe WooCommerce's API without recording WHEN
 * each method arrived, so they silently assert that everything here exists on
 * every supported WooCommerce. `phpstan.neon.dist` lists this file under
 * `scanFiles`, so static analysis inherits the same assumption and will even
 * report a legitimate `method_exists()` guard as redundant.
 *
 * That combination shipped a fatal: `WC_Shipping_Zones::get_shipping_zones()`
 * requires WooCommerce 10.3 while the plugin's floor is 9.9, and neither the
 * suite nor PHPStan could see it (#638).
 *
 * When stubbing a WooCommerce method, check the version that introduced it and
 * note it here. An `@since` scan of WooCommerce source is a useful first pass
 * but not sufficient — `get_shipping_zones()` itself carries no `@since` tag,
 * and 29 of 66 stubbed core methods are in the same position. For anything
 * recent, confirm against `git tag --contains` on the introducing commit.
 *
 * @package WooCommerce_AI_Storefront
 */



if ( ! function_exists( 'wp_parse_str' ) ) {
	/**
	 * Minimal wp_parse_str stub.
	 *
	 * Pass-by-reference second parameter cannot be proxied through Brain
	 * Monkey's Patchwork-based aliasing, so this is declared as a real
	 * function before any test file loads.
	 *
	 * @param string $str    Input query string.
	 * @param array  $result Populated by reference.
	 */
	function wp_parse_str( $str, &$result ) {
		parse_str( (string) $str, $result );
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	/**
	 * Minimal esc_sql stub — uses PHP's addslashes() to escape
	 * single quotes, double quotes, backslashes, and NUL bytes.
	 * This is a simplified stand-in for WordPress core's
	 * `wpdb::_real_escape()` which uses mysqli_real_escape_string()
	 * when available; addslashes is sufficient for unit tests that
	 * only assert SQL fragment shape, not real DB safety.
	 *
	 * Defined here (before Patchwork loads) so it cannot be redefined
	 * via Brain\Monkey's Functions\when().
	 *
	 * @param string|array $data Input to escape.
	 * @return string|array
	 */
	function esc_sql( $data ) {
		if ( is_array( $data ) ) {
			return array_map( 'esc_sql', $data );
		}
		return addslashes( (string) $data );
	}
}

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
		private string $body = '';

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

		/**
		 * @param array<string, mixed> $params
		 */
		public function set_query_params( array $params ): void {
			foreach ( $params as $key => $value ) {
				$this->params[ $key ] = $value;
			}
		}

		/**
		 * Mirror of WP_REST_Request::set_body_params(). In real WP this
		 * stores into a dedicated `$body_params` slot that `get_param()`
		 * consults alongside query / JSON / URL / file / default
		 * sources. The stub unifies on `$this->params` for simplicity —
		 * handlers under test access via `get_param()` regardless of
		 * which setter populated the slot, which matches WP's observable
		 * behavior for the param-resolution merge.
		 *
		 * @param array<string, mixed> $params
		 */
		public function set_body_params( array $params ): void {
			foreach ( $params as $key => $value ) {
				$this->params[ $key ] = $value;
			}
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

		public function set_body( string $body ): void {
			$this->body = $body;
		}

		public function get_body(): string {
			return $this->body;
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

		/**
		 * @param string|string[] $type One type, or an array of types
		 *                              for an "any-of" check (matches WC core).
		 */
		public function is_type( $type ): bool {
			return is_array( $type ) ? in_array( $this->type, $type, true ) : $this->type === $type;
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

		/**
		 * Post status of the product. Declared so PHPStan resolves the
		 * `get_status()` call in `WC_AI_Storefront_IndexNow::is_product_indexable()`.
		 * Tests override via Mockery (`shouldReceive('get_status')->andReturn('publish')`).
		 */
		public function get_status(): string {
			return 'publish';
		}

		public function is_purchasable(): bool {
			return true;
		}

		public function is_in_stock(): bool {
			return true;
		}

		/**
		 * Catalog visibility: 'visible' | 'catalog' | 'search' | 'hidden'.
		 * Declared so the single-product endpoint's leak-guard
		 * (`get_catalog_visibility()` in `WC_AI_Storefront_Products_Feed`) is
		 * PHPStan-resolvable and Mockery-overridable. Tests set it via
		 * `shouldReceive( 'get_catalog_visibility' )`.
		 */
		public function get_catalog_visibility(): string {
			return 'visible';
		}

		public function get_name(): string {
			return 'Test Product';
		}

		/**
		 * Product/variation description. Declared so
		 * `method_exists( $variation, 'get_description' )` resolves true
		 * in the variant-field-inheritance path
		 * (`WC_AI_Storefront_JsonLd::add_inherited_variant_fields()`) and
		 * so PHPStan sees the signature. Tests override the return value
		 * via Mockery (`make_variation([ 'description' => ... ])`).
		 */
		public function get_description(): string {
			return '';
		}

		/**
		 * Product short description (post excerpt). Declared so PHPStan sees
		 * the signature used by
		 * `WC_AI_Storefront_Meta_Tags::build_description()`. Tests override
		 * the return value via Mockery.
		 */
		public function get_short_description(): string {
			return '';
		}

		/**
		 * Variation/product sale-end date. Declared so
		 * `method_exists( $variation, 'get_date_on_sale_to' )` resolves
		 * true in the per-variant `priceValidUntil` derivation in
		 * `WC_AI_Storefront_JsonLd::add_inherited_variant_fields()` and so
		 * PHPStan sees the signature. Typed `?\DateTimeInterface` (real WC
		 * returns a `WC_DateTime`, a `DateTime`/`DateTimeInterface`
		 * subclass, or null) so the production `instanceof
		 * \DateTimeInterface` guard reads as a meaningful nullable-object
		 * narrowing rather than always-false. Returns `null` by default
		 * (no sale window). Tests override via Mockery
		 * (`make_variation([ 'date_on_sale_to' => $datetime ])`).
		 */
		public function get_date_on_sale_to(): ?\DateTimeInterface {
			return null;
		}

		/**
		 * Variation/product sale-start date. Declared alongside
		 * `get_date_on_sale_to()` so `method_exists()` resolves true in the
		 * `validFrom` derivation in
		 * `WC_AI_Storefront_JsonLd::add_sale_window()` /
		 * `add_inherited_variant_fields()`, and so PHPStan sees the
		 * signature. Typed `?\DateTimeInterface` (real WC returns a
		 * `WC_DateTime` or null) so the production `instanceof
		 * \DateTimeInterface` guard reads as a meaningful nullable-object
		 * narrowing. Returns `null` by default (no sale window). Tests
		 * override via Mockery
		 * (`make_product([ 'date_on_sale_from' => $datetime ])`).
		 */
		public function get_date_on_sale_from(): ?\DateTimeInterface {
			return null;
		}

		public function get_price( string $context = 'view' ): string {
			return '19.99';
		}

		public function get_sku(): string {
			return '';
		}

		/**
		 * The $context parameter matters: WC_Product_Variation overrides this
		 * to fall back to the parent's image in 'view' context, so the feed
		 * mapper passes 'edit' when it needs to know whether a variation owns
		 * an image of its own. The base product has no parent and ignores it.
		 *
		 * @param string $context 'view' or 'edit'.
		 */
		public function get_image_id( string $context = 'view' ): int {
			return 0;
		}

		// Methods below are consumed by WC_AI_Storefront_Products_Feed's
		// WC->Shopify mapper. Declared on the base stub purely so PHPStan
		// resolves the call sites (the mapper takes a generic WC_Product);
		// ProductsFeedMapperTest overrides every return value via Mockery.

		public function get_slug(): string {
			return 'test-product';
		}

		public function get_regular_price( string $context = 'view' ): string {
			return '19.99';
		}

		public function is_on_sale(): bool {
			return false;
		}

		public function needs_shipping(): bool {
			return true;
		}

		/**
		 * @return int[]
		 */
		public function get_category_ids(): array {
			return [];
		}

		/**
		 * @return int[]
		 */
		public function get_tag_ids(): array {
			return [];
		}

		/**
		 * @return int[]
		 */
		public function get_gallery_image_ids(): array {
			return [];
		}

		/**
		 * Real WC_Product returns variation IDs for variable products,
		 * grouped product IDs for grouped products, empty array for
		 * simple/external. Test stub returns empty by default — tests
		 * that exercise the variable-products path override via
		 * `make_product([ 'children' => [...] ])`.
		 *
		 * @return int[]
		 */
		public function get_children(): array {
			return [];
		}

		/**
		 * @return int[]
		 */
		public function get_cross_sell_ids(): array {
			return [];
		}

		/**
		 * @return int[]
		 */
		public function get_upsell_ids(): array {
			return [];
		}

		// Stock.
		public function managing_stock(): bool {
			return false;
		}

		public function get_stock_quantity(): ?int {
			return null;
		}

		/**
		 * @return string One of 'instock' | 'outofstock' | 'onbackorder'.
		 */
		public function get_stock_status(): string {
			return 'instock';
		}

		// Weight + dimensions (JSON-LD enhancer).
		public function has_weight(): bool {
			return false;
		}

		public function get_weight(): string {
			return '';
		}

		// Consumed by the products-feed mapper's Shopify variant shape.
		public function get_tax_status(): string {
			return 'taxable';
		}

		public function get_menu_order(): int {
			return 0;
		}

		public function get_parent_id(): int {
			return 0;
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

		/**
		 * Test/PHPStan-only stub. In real WooCommerce, this method is
		 * defined on `WC_Product_Variable` (and its subclasses such as
		 * `WC_Product_Variable_Subscription`) — NOT on the `WC_Product`
		 * base class. Calling it directly on a `WC_Product_Simple`
		 * instance fatals.
		 *
		 * Production code in `get_variation_attribute_slugs()` gates
		 * the call via `method_exists()` to handle this. The stub
		 * exposes the method on the base here purely so tests using
		 * `Mockery::mock( 'WC_Product' )` and PHPStan can resolve
		 * `$product->get_variation_attributes()` without manually
		 * type-narrowing every call site to the variable subclass.
		 *
		 * If you're adding production code that calls this method,
		 * use the `method_exists()` capability gate, not this stub.
		 *
		 * The return shape depends on the receiver, which is why this is a
		 * union rather than one array type. A variable PARENT returns each
		 * attribute's available values (`pa_size => ['s','m']`), while a
		 * VARIATION returns the single value it has selected, keyed with the
		 * `attribute_` prefix (`attribute_pa_size => 'm'`). Declaring only the
		 * parent's shape would let PHPStan prove away the guards that callers
		 * legitimately need for the variation form.
		 *
		 * @return array<string, array<int, string>|string>
		 */
		public function get_variation_attributes(): array {
			return [];
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
		private int $customer_id = 0;
		private string $billing_first_name = '';
		private string $billing_last_name = '';

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

		public function get_billing_first_name(): string {
			return $this->billing_first_name;
		}

		public function get_billing_last_name(): string {
			return $this->billing_last_name;
		}

		public function get_billing_email(): string {
			return '';
		}

		public function get_customer_id(): int {
			return $this->customer_id;
		}

		public function set_test_customer_id( int $id ): void {
			$this->customer_id = $id;
		}

		public function set_test_billing_first_name( string $name ): void {
			$this->billing_first_name = $name;
		}

		public function set_test_billing_last_name( string $name ): void {
			$this->billing_last_name = $name;
		}

		public function get_items( string $type = 'line_item' ): array {
			return [];
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

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal wpdb stub for unit tests.
	 *
	 * The production plugin calls $wpdb->query() and $wpdb->prepare()
	 * for wildcard transient deletion. Tests that exercise those code
	 * paths must set the global $wpdb to a Mockery mock; this stub
	 * exists so PHPStan can resolve the class name and so test files
	 * can type-hint against it without a real database connection.
	 */
	class wpdb {
		public string $options = 'wp_options';

		public function query( string $sql ): int|bool {
			return false;
		}

		public function prepare( string $query, ...$args ): string {
			// Minimal implementation: substitute %s placeholders with
			// sprintf-style escaping so the returned string is a valid
			// SQL fragment in tests that assert on the prepared query.
			$i = 0;
			return (string) preg_replace_callback(
				'/%s/',
				static function () use ( &$i, $args ) {
					$val = $args[ $i++ ] ?? '';
					return "'" . addslashes( (string) $val ) . "'";
				},
				$query
			);
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
	class WC_Shipping_Zone {
		private int $id;
		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}
		public function get_id(): int {
			return $this->id;
		}
		public function get_zone_locations(): array {
			return [];
		}
		/** @return WC_Shipping_Method[] */
		public function get_shipping_methods( bool $enabled_only = false ): array {
			return [];
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
	class WC_Shipping_Zones {
		/** @var array<int, WC_Shipping_Zone> Keyed by zone id. Set in tests to inject zones without a DB. */
		public static array $test_zones = [];

		/**
		 * Data arrays keyed by zone id, as the admin UI consumes them.
		 * `@since 2.6.0` — available on every WooCommerce this plugin
		 * supports, unlike get_shipping_zones().
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public static function get_zones(): array {
			$zones = [];
			foreach ( self::$test_zones as $id => $zone ) {
				$zones[ $id ] = [ 'zone_id' => $id ];
			}
			return $zones;
		}

		/**
		 * `@since 2.6.0`. Returns false for an unknown id, which callers
		 * must narrow with `instanceof`.
		 *
		 * @param int $zone_id Zone ID.
		 * @return WC_Shipping_Zone|bool
		 */
		public static function get_zone( $zone_id ) {
			return self::$test_zones[ (int) $zone_id ] ?? false;
		}

		/**
		 * Zone OBJECTS keyed by zone id.
		 *
		 * `@since 10.3.0` — NOT available on the plugin's declared WooCommerce
		 * floor of 9.9. Production code must gate on WC_VERSION before calling
		 * it; this stub declares it unconditionally, so neither the suite nor
		 * PHPStan can catch an unguarded call (#638).
		 *
		 * @return WC_Shipping_Zone[]
		 */
		public static function get_shipping_zones(): array {
			return self::$test_zones;
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public string $id = '';
		/**
		 * WooCommerce types this as the STRING 'yes'/'no', not a boolean —
		 * WC_Shipping_Zone assigns `$raw->is_enabled ? 'yes' : 'no'`. Typing
		 * it bool here would let a test pass against code that mishandles the
		 * real value.
		 */
		public string $enabled = 'yes';
	}
}

if ( ! class_exists( 'WC_Shipping_Free_Shipping' ) ) {
	class WC_Shipping_Free_Shipping extends WC_Shipping_Method {
		public string $id = 'free_shipping';
		public string $requires = '';
		/**
		 * Order subtotal at or above which shipping becomes free. Empty
		 * unless `$requires` names a min_amount mode.
		 */
		public string $min_amount = '';
	}
}

if ( ! class_exists( 'WC_Shipping_Flat_Rate' ) ) {
	/**
	 * `$cost` is an EXPRESSION, not a number — WooCommerce evaluates it
	 * against a real cart, so it may contain `[qty]`, `[cost]` or a
	 * `[fee percent="…"]` shortcode. Typed as string here for that reason.
	 */
	class WC_Shipping_Flat_Rate extends WC_Shipping_Method {
		public string $id = 'flat_rate';
		public string $cost = '';
		/**
		 * Per-instance settings. Carries `class_cost_<term_id>` and
		 * `no_class_cost`, which calculate_shipping() ADDS to `$cost`.
		 *
		 * @var array<string, string>
		 */
		public array $instance_settings = [];
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
			if ( 'c' === $fmt ) {
				return $this->iso;
			}
			return $this->iso;
		}

		public function getTimestamp(): int {
			return strtotime( $this->iso ) ?: 0;
		}
	}
}

// Faithful `WC_DateTime` stub for sale-window tests. Unlike `WC_DateTime_Stub`
// above (a minimal echo stub for admin-orders display), this reproduces the
// REAL `WC_DateTime` timezone contract that `iso8601_or_empty()` must handle:
// a `DateTime` subclass with a detached `utc_offset` property, a `getOffset()`
// that prefers it, and — critically — NO `format()` override. That last point
// is the whole reason the production code cannot use `format('c')`: in the
// manual-UTC-offset store shape, the underlying `DateTime` stays at UTC, so
// `format('c')` emits `+00:00` and a UTC wall-clock while `getOffset()` still
// reports the merchant's real offset. A `DateTimeImmutable` fixture cannot
// reproduce this divergence; a real subclass can. Mirrors WooCommerce core's
// `class-wc-datetime.php` (`set_utc_offset()` / `getOffset()` / `setTimezone()`).
if ( ! class_exists( 'WC_DateTime' ) ) {
	class WC_DateTime extends \DateTime {
		protected int $utc_offset = 0;

		public function set_utc_offset( int $offset ): void {
			$this->utc_offset = $offset;
		}

		#[\ReturnTypeWillChange]
		public function getOffset() {
			return $this->utc_offset ?: parent::getOffset();
		}

		#[\ReturnTypeWillChange]
		public function setTimezone( $timezone ): \DateTime {
			$this->utc_offset = 0;
			return parent::setTimezone( $timezone );
		}
	}
}

// WC Subscriptions plugin stub. The plugin is optional — only present
// when the merchant has it activated. Subscription-signal emission in
// JSON-LD detects the plugin via `function_exists('wcs_is_subscription')`
// and reads per-product configuration via `WC_Subscriptions_Product`
// static getters. Tests drive the helper by writing to the public-static
// override properties on the stub (same pattern as `WC_AI_Storefront::$test_settings`).
if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
	class WC_Subscriptions_Product {
		// Per-product override map keyed by product ID. Tests set entries
		// like `$test_data[42] = ['period' => 'month', 'interval' => 1, ...]`
		// before invoking the JSON-LD emitter. A missing entry means "this
		// product isn't a subscription" — is_subscription() returns false.
		public static array $test_data = [];

		public static function is_subscription( $product ): bool {
			$id = self::id_of( $product );
			return isset( self::$test_data[ $id ] );
		}

		public static function get_period( $product ): string {
			$id = self::id_of( $product );
			return (string) ( self::$test_data[ $id ]['period'] ?? 'month' );
		}

		public static function get_interval( $product ): int {
			$id = self::id_of( $product );
			return (int) ( self::$test_data[ $id ]['interval'] ?? 1 );
		}

		public static function get_length( $product ): int {
			$id = self::id_of( $product );
			return (int) ( self::$test_data[ $id ]['length'] ?? 0 );
		}

		public static function get_sign_up_fee( $product ): string {
			$id = self::id_of( $product );
			return (string) ( self::$test_data[ $id ]['sign_up_fee'] ?? '0' );
		}

		public static function get_trial_length( $product ): int {
			$id = self::id_of( $product );
			return (int) ( self::$test_data[ $id ]['trial_length'] ?? 0 );
		}

		public static function get_trial_period( $product ): string {
			$id = self::id_of( $product );
			return (string) ( self::$test_data[ $id ]['trial_period'] ?? 'month' );
		}

		// WC Subscriptions accepts either a product object or an ID; the
		// real implementation does the same coercion. Tests pass mocks
		// whose `get_id()` returns an int.
		private static function id_of( $product ): int {
			if ( is_int( $product ) ) {
				return $product;
			}
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				return (int) $product->get_id();
			}
			return 0;
		}
	}
}

if ( ! function_exists( 'wcs_is_subscription' ) ) {
	function wcs_is_subscription( $product ): bool {
		return WC_Subscriptions_Product::is_subscription( $product );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int    $ID          = 0;
		public string $post_title  = '';
		public string $post_status = 'publish';
		public string $post_type   = 'product';
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * WP_Term stub mirroring core's full public property surface.
	 *
	 * Exists so the production `$term instanceof WP_Term` guards in
	 * `WC_AI_Storefront_Products_Feed` (product_type / tags resolution) read
	 * as meaningful type narrowing under unit test. Tests build term doubles
	 * via `Mockery::mock( 'WP_Term' )` and set properties directly; Mockery
	 * only satisfies `instanceof WP_Term` when the class is defined.
	 *
	 * IMPORTANT: `phpstan.neon.dist` lists this file under `scanFiles`, so
	 * PHPStan binds every `WP_Term` reference in the codebase to THIS class
	 * (a scanned definition shadows the vendor wordpress-stubs one). It must
	 * therefore declare the complete real WP_Term public surface — otherwise
	 * unrelated production files that read e.g. `$term->slug` / `->count` /
	 * `->parent` fail static analysis with `property.notFound`. The surface
	 * has been frozen in core since WP 4.4, so this is maintenance-free.
	 */
	class WP_Term {
		public int    $term_id          = 0;
		public string $name             = '';
		public string $slug             = '';
		public int    $term_group       = 0;
		public int    $term_taxonomy_id = 0;
		public string $taxonomy         = '';
		public string $description      = '';
		public int    $parent           = 0;
		public int    $count            = 0;
		public string $filter           = 'raw';
	}
}
