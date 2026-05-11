<?php
/**
 * Subscription test-fixture seeder.
 *
 * Creates four products that mirror the pierorocca.com test fixtures used to
 * audit subscription support in PR #367 and inform issues #368 + #369:
 *
 *   - SKU AI-SUB-SIMPLE   — simple subscription ($100 / year)
 *   - SKU AI-SUB-VAR-DEF  — variable-subscription, default term = 6 months
 *   - SKU AI-SUB-VAR-NDF  — variable-subscription, no default term
 *   - SKU AI-SUB-VAR-MAL  — variable-subscription, no variations (malformed)
 *
 * Idempotent: re-running updates existing fixtures rather than creating dupes.
 * SKU is the identity key.
 *
 * Invoke via:
 *   docker exec woocommerce-ai-storefront-cli sh -c \
 *     'php -d memory_limit=1G /usr/local/bin/wp eval-file /path/in/container/seed-subscription-fixtures.php'
 *
 * The companion `bin/seed-subscription-fixtures.sh` handles the docker cp + exec wiring.
 *
 * @package WooCommerce_AI_Storefront
 */

if ( ! function_exists( 'wcs_is_subscription' ) ) {
	WP_CLI::error( 'WooCommerce Subscriptions plugin is not active. Activate it before seeding.' );
}
if ( ! class_exists( 'WC_Product_Subscription' )
	|| ! class_exists( 'WC_Product_Variable_Subscription' )
	|| ! class_exists( 'WC_Product_Subscription_Variation' ) ) {
	WP_CLI::error( 'WC Subscriptions product classes not available — plugin version may be incompatible.' );
}

/**
 * Find an existing product by SKU.
 *
 * Uses WC's helper which queries the SKU index — faster than meta_query and
 * survives custom-tables migrations.
 */
function ai_storefront_fixture_find( string $sku ): ?WC_Product {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id <= 0 ) {
		return null;
	}
	$product = wc_get_product( $id );
	return $product ?: null;
}

/**
 * Create-or-update the SKU-keyed product.
 *
 * Returns [WC_Product, bool $was_created].
 */
function ai_storefront_fixture_upsert( string $sku, string $type ): array {
	$existing = ai_storefront_fixture_find( $sku );
	if ( $existing ) {
		// Type mismatch — recreate from scratch to avoid stale postmeta from
		// a prior fixture-shape change. Trash, then build fresh.
		if ( $existing->get_type() !== $type ) {
			wp_delete_post( $existing->get_id(), true );
			$existing = null;
		}
	}
	if ( $existing ) {
		return [ $existing, false ];
	}
	$class = [
		'subscription'          => 'WC_Product_Subscription',
		'variable-subscription' => 'WC_Product_Variable_Subscription',
	][ $type ] ?? null;
	if ( ! $class ) {
		WP_CLI::error( "Unsupported fixture type: $type" );
	}
	$product = new $class();
	$product->set_sku( $sku );
	return [ $product, true ];
}

/**
 * Ensure a global "Term" attribute taxonomy (pa_length) exists with the four
 * length-of-time terms used by the variable-subscription fixtures.
 *
 * Variable subscriptions use a global attribute (rather than per-product
 * custom) so multiple fixture products share the same axis — matches the
 * pierorocca.com setup and exercises the typed-attribute path through the
 * UCP product translator.
 */
function ai_storefront_fixture_ensure_term_taxonomy(): int {
	global $wpdb;

	$attribute_id = 0;
	$existing     = wc_get_attribute_taxonomies();
	foreach ( $existing as $att ) {
		if ( $att->attribute_name === 'length' ) {
			$attribute_id = (int) $att->attribute_id;
			break;
		}
	}
	if ( $attribute_id <= 0 ) {
		$attribute_id = wc_create_attribute( [
			'name'         => 'Length',
			'slug'         => 'length',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		] );
		if ( is_wp_error( $attribute_id ) ) {
			WP_CLI::error( 'Failed to create Length attribute: ' . $attribute_id->get_error_message() );
		}
		WP_CLI::log( "Created global Length attribute id=$attribute_id (taxonomy: pa_length)" );
	}
	// Always (re)register the taxonomy in this request so wp_insert_term and
	// wp_set_object_terms below can target it. The plugin's normal init does
	// this on every request, but the seed script runs before that hook fires.
	register_taxonomy( 'pa_length', [ 'product' ], [
		'hierarchical' => false,
		'show_ui'      => false,
		'query_var'    => true,
	] );
	// Bust WC's taxonomy cache so subsequent attribute-id lookups see the row.
	delete_transient( 'wc_attribute_taxonomies' );
	WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

	foreach ( [ '1-month' => '1 month', '3-months' => '3 months', '6-months' => '6 months', '1-year' => '1 year' ] as $slug => $name ) {
		if ( ! term_exists( $slug, 'pa_length' ) ) {
			$res = wp_insert_term( $name, 'pa_length', [ 'slug' => $slug ] );
			if ( is_wp_error( $res ) ) {
				WP_CLI::warning( "Failed inserting term $slug: " . $res->get_error_message() );
			}
		}
	}

	return (int) $attribute_id;
}

/**
 * Apply common subscription metadata. Both simple and variation use the same
 * key set; the variation case is called from the variation loop below.
 */
function ai_storefront_fixture_apply_subscription_meta( $product, string $period, int $interval, int $length, string $price ): void {
	$product->update_meta_data( '_subscription_period', $period );
	$product->update_meta_data( '_subscription_period_interval', (string) $interval );
	$product->update_meta_data( '_subscription_length', (string) $length );
	$product->update_meta_data( '_subscription_price', $price );
	$product->update_meta_data( '_subscription_sign_up_fee', '0' );
	$product->update_meta_data( '_subscription_trial_length', '0' );
	$product->update_meta_data( '_subscription_trial_period', $period );
}

// -----------------------------------------------------------------------
// 1. Simple subscription (annual coffee membership)
// -----------------------------------------------------------------------

[ $simple, $simple_created ] = ai_storefront_fixture_upsert( 'AI-SUB-SIMPLE', 'subscription' );
$simple->set_name( 'Annual Coffee Membership (fixture)' );
$simple->set_regular_price( '100.00' );
$simple->set_price( '100.00' );
$simple->set_status( 'publish' );
ai_storefront_fixture_apply_subscription_meta( $simple, 'year', 1, 0, '100.00' );
$simple->save();
WP_CLI::log( ( $simple_created ? 'Created' : 'Updated' ) . " AI-SUB-SIMPLE → ID {$simple->get_id()}" );

// -----------------------------------------------------------------------
// 2 + 3. Variable subscriptions (with default / without default)
// -----------------------------------------------------------------------

$length_attribute_id = ai_storefront_fixture_ensure_term_taxonomy();

$variations_spec = [
	// [ term slug, term label, price, period, interval ]
	[ '1-month',  '1 month',  '10.00', 'month', 1 ],
	[ '3-months', '3 months', '25.00', 'month', 3 ],
	[ '6-months', '6 months', '50.00', 'month', 6 ],
	[ '1-year',   '1 year',   '75.00', 'year',  1 ],
];

foreach ( [ 'AI-SUB-VAR-DEF' => '6-months', 'AI-SUB-VAR-NDF' => null ] as $sku => $default_term ) {
	[ $parent, $parent_created ] = ai_storefront_fixture_upsert( $sku, 'variable-subscription' );
	$parent->set_name(
		'Membership - Variable' . ( $default_term ? ' (default 6mo)' : ' (no default)' ) . ' (fixture)'
	);
	$parent->set_status( 'publish' );

	// Attach the global Length attribute, used for variations. The
	// attribute id captured above is the canonical reference — WC won't
	// store the attribute as a taxonomy-backed one without it.
	$term_ids = array_map(
		'intval',
		wp_list_pluck( get_terms( [ 'taxonomy' => 'pa_length', 'hide_empty' => false ] ), 'term_id' )
	);
	$attribute = new WC_Product_Attribute();
	$attribute->set_id( $length_attribute_id );
	$attribute->set_name( 'pa_length' );
	$attribute->set_options( $term_ids );
	$attribute->set_position( 0 );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent->set_attributes( [ $attribute ] );

	if ( $default_term ) {
		$parent->set_default_attributes( [ 'pa_length' => $default_term ] );
	} else {
		$parent->set_default_attributes( [] );
	}
	$parent->save();
	WP_CLI::log( ( $parent_created ? 'Created' : 'Updated' ) . " $sku → ID {$parent->get_id()}" );

	// Sync attribute → variations via WC's data store (handles term-id↔slug
	// mapping correctly for global attributes).
	wp_set_object_terms(
		$parent->get_id(),
		array_map(
			fn( $s ) => get_term_by( 'slug', $s, 'pa_length' )->term_id,
			[ '1-month', '3-months', '6-months', '1-year' ]
		),
		'pa_length'
	);

	// Recreate variations from scratch each run — simpler than diffing.
	foreach ( $parent->get_children() as $child_id ) {
		wp_delete_post( $child_id, true );
	}

	$menu_order = 0;
	foreach ( $variations_spec as [ $term_slug, $term_label, $price, $period, $interval ] ) {
		$variation = new WC_Product_Subscription_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( [ 'pa_length' => $term_slug ] );
		$variation->set_regular_price( $price );
		$variation->set_price( $price );
		$variation->set_status( 'publish' );
		$variation->set_menu_order( $menu_order++ );
		ai_storefront_fixture_apply_subscription_meta( $variation, $period, $interval, 0, $price );
		$variation->save();
	}

	// Force WC to recompute the parent's variation index + price cache.
	WC_Product_Variable::sync( $parent->get_id() );
}

// -----------------------------------------------------------------------
// 4. Malformed variable subscription (no variations setup at all)
// -----------------------------------------------------------------------

[ $mal, $mal_created ] = ai_storefront_fixture_upsert( 'AI-SUB-VAR-MAL', 'variable-subscription' );
$mal->set_name( 'Membership - Variable (malformed) (fixture)' );
$mal->set_status( 'publish' );
$mal->set_attributes( [] );
$mal->set_default_attributes( [] );
$mal->save();
// Clear any leftover children from prior runs.
foreach ( $mal->get_children() as $child_id ) {
	wp_delete_post( $child_id, true );
}
WC_Product_Variable::sync( $mal->get_id() );
WP_CLI::log( ( $mal_created ? 'Created' : 'Updated' ) . " AI-SUB-VAR-MAL → ID {$mal->get_id()}" );

// -----------------------------------------------------------------------
// Emit a JSON manifest with the resolved IDs for the bash wrapper to capture.
// -----------------------------------------------------------------------

WP_CLI::log( '' );
WP_CLI::log( '=== Fixture manifest ===' );
WP_CLI::log( json_encode( [
	'simple'                   => $simple->get_id(),
	'variable_with_default'    => wc_get_product_id_by_sku( 'AI-SUB-VAR-DEF' ),
	'variable_without_default' => wc_get_product_id_by_sku( 'AI-SUB-VAR-NDF' ),
	'variable_malformed'       => $mal->get_id(),
], JSON_PRETTY_PRINT ) );

WP_CLI::success( 'Subscription fixtures seeded' );
