#!/usr/bin/env bash
#
# Seed the missing variant-dimension-override fixture for #615.
#
# Every variation of the local "Hoodie" variable product (ID 14) inherits
# weight and dimensions from the parent (weight 1.5, dimensions 10/8/3) —
# none sets its own. That means the JSON-LD hasVariant emission has no
# fixture exercising the OVERRIDE path (a variation reporting its own
# value while its siblings still report the parent's); only the INHERIT
# path is covered by whatever ships in the sample-products seed.
#
# This script sets distinct weight + dimensions on the first Hoodie
# variation only, leaving the rest to keep inheriting, then prints the
# resulting per-variation state so the split is visible before you fetch
# the product page and diff the JSON-LD.
#
# Idempotent — re-running re-applies the same override values rather than
# accumulating anything. Uses WC_Product_Variation setters (not raw
# `wp post meta update`) so WooCommerce's own read caches are invalidated
# via ->save(), matching how the plugin admin would make this change.
#
# Usage:
#   ./bin/seed-variant-dimension-fixtures.sh
#
# Container override (defaults to docker-compose project-root setup):
#   CONTAINER=woocommerce-ai-storefront-cli-1 ./bin/seed-variant-dimension-fixtures.sh

set -euo pipefail

CONTAINER="${CONTAINER:-woocommerce-ai-storefront-cli}"

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
	echo "ERROR: Container '${CONTAINER}' is not running." >&2
	echo "Start the dev stack: docker compose up -d" >&2
	echo "Or override: CONTAINER=<name> $0" >&2
	exit 1
fi

echo "Container: ${CONTAINER}"
echo ""

docker exec -i "${CONTAINER}" \
	php -d memory_limit=512M /usr/local/bin/wp --allow-root eval-file - <<'PHP'
<?php
$products = wc_get_products(
	array(
		'name'   => 'Hoodie',
		'type'   => 'variable',
		'limit'  => 1,
		'return' => 'objects',
	)
);
$parent = $products[0] ?? null;
if ( ! $parent ) {
	fwrite( STDERR, "ERROR: variable product \"Hoodie\" not found.\n" );
	exit( 1 );
}

$variation_ids = $parent->get_children();
if ( empty( $variation_ids ) ) {
	fwrite( STDERR, "ERROR: Hoodie (ID {$parent->get_id()}) has no variations.\n" );
	exit( 1 );
}
sort( $variation_ids );

$override_id = $variation_ids[0];
$variation   = wc_get_product( $override_id );

$variation->set_weight( '0.9' );
$variation->set_length( '7' );
$variation->set_width( '5' );
$variation->set_height( '2.5' );
$variation->save();

wc_delete_product_transients( $parent->get_id() );
clean_post_cache( $parent->get_id() );

printf(
	"Parent Hoodie (ID %d): weight %s, dimensions %sx%sx%s (L/W/H)\n\n",
	$parent->get_id(),
	$parent->get_weight(),
	$parent->get_length(),
	$parent->get_width(),
	$parent->get_height()
);

foreach ( $variation_ids as $id ) {
	$v      = wc_get_product( $id );
	$own    = ( $id === $override_id ) ? 'OWN (override)' : 'inherited';
	printf(
		"Variation %-4d %-20s weight=%-5s length=%-4s width=%-4s height=%-4s  [%s]\n",
		$id,
		'(' . $v->get_attribute_summary() . ')',
		$v->get_weight(),
		$v->get_length(),
		$v->get_width(),
		$v->get_height(),
		$own
	);
}
PHP
