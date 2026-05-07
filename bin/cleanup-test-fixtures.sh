#!/usr/bin/env bash
#
# Reduce multi-value core Schema.org attribute taxonomies (color, size,
# material, pattern) on simple products to first-listed term.
#
# Why: WC's product editor accepts multi-value taxonomy attributes on
# simple products (e.g. `Color: Black, Navy, Gray`) without warning that
# this is semantically wrong — a simple product is one SKU, one color,
# one size. The JSON-LD enhancer's typed-property emission path requires
# single-value attributes; multi-value falls back to additionalProperty.
#
# Test fixtures need single-value attributes on simple products to
# exercise the typed-property emit path. This script does that cleanup
# idempotently — safe to re-run after re-seeding the dev DB.
#
# What's preserved (intentional fixtures):
#   - Product 15 (V-Neck T-Shirt): correct variable product, exercises
#     the variation-defining-skip path.
#   - Product 16 (Hoodie): misconfigured variable (attributes set but
#     "Used for variations" unticked), exercises the core-multi-value-
#     fallback path.
#   - Product 22 (Sunglasses): already single-value, control fixture.
#   - Products 37, 38 (grouped, external): different product types,
#     exercise the type-skip path.
#
# Usage:
#   ./bin/cleanup-test-fixtures.sh           # dry run (default), shows what would change
#   ./bin/cleanup-test-fixtures.sh --apply   # actually apply changes
#
# Container override (defaults to docker-compose project-root setup):
#   CONTAINER=woocommerce-ai-storefront-cli-1 ./bin/cleanup-test-fixtures.sh

set -euo pipefail

CONTAINER="${CONTAINER:-woocommerce-ai-storefront-cli}"
APPLY="0"

if [ "${1:-}" = "--apply" ]; then
	APPLY="1"
elif [ -n "${1:-}" ] && [ "${1:-}" != "--dry-run" ]; then
	echo "Unknown argument: $1" >&2
	echo "Usage: $0 [--apply|--dry-run]" >&2
	exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
	echo "ERROR: Container '${CONTAINER}' is not running." >&2
	echo "Start the dev stack: docker compose up -d" >&2
	echo "Or override: CONTAINER=<name> $0" >&2
	exit 1
fi

echo "Container: ${CONTAINER}"
echo "Mode:      $([ "$APPLY" = "1" ] && echo 'APPLY (live)' || echo 'DRY RUN')"
echo ""

docker exec -i -e APPLY="$APPLY" "${CONTAINER}" \
	php -d memory_limit=512M /usr/local/bin/wp --allow-root eval-file - <<'PHP'
<?php
$apply = getenv( 'APPLY' ) === '1';

// Schema.org core attribute taxonomies that map to typed Product properties
// (color, material, pattern, size — all Text-typed per spec). Multi-value
// inputs on these can't honestly be emitted as a single typed Schema.org
// property, so test fixtures should use single values to exercise the
// emit path. Non-core attributes (Style, Heel Height, Features, etc.)
// are left alone — multi-value is legitimate for those.
$core_taxonomies = array( 'pa_color', 'pa_size', 'pa_material', 'pa_pattern' );

// Hard-coded simple-product IDs from the WC sample-products seed. Skips
// 15, 16 (variable fixtures), 22 (already single), 37 (grouped), 38
// (external) — those exercise other #327 branches and should stay as-is.
$product_ids = array( 17, 18, 19, 20, 21, 23, 24, 25, 26, 35, 36 );

$change_count = 0;
foreach ( $product_ids as $id ) {
	$p = wc_get_product( $id );
	if ( ! $p ) {
		echo "SKIP  $id - product not found\n";
		continue;
	}
	foreach ( $core_taxonomies as $slug ) {
		if ( ! taxonomy_exists( $slug ) ) {
			continue;
		}
		$current_value = $p->get_attribute( $slug );
		if ( ! $current_value ) {
			continue;
		}
		$pieces = array_map( 'trim', explode( ',', $current_value ) );
		if ( count( $pieces ) <= 1 ) {
			continue;
		}
		$first_label = $pieces[0];
		$first_term  = get_term_by( 'name', $first_label, $slug );
		if ( ! $first_term ) {
			printf( "SKIP  %-3d %-22s %-12s no term \"%s\"\n", $id, $p->get_name(), $slug, $first_label );
			continue;
		}
		$verb = $apply ? 'OK   ' : 'WOULD';
		printf( "%s %-3d %-22s %-12s \"%s\" → \"%s\"\n", $verb, $id, $p->get_name(), $slug, $current_value, $first_term->name );
		if ( $apply ) {
			wp_set_object_terms( $id, array( $first_term->slug ), $slug );
		}
		++$change_count;
	}
	if ( $apply ) {
		clean_post_cache( $id );
		wc_delete_product_transients( $id );
	}
}

echo "\n";
if ( $apply ) {
	echo "Applied $change_count updates.\n";
} else {
	echo "Dry run: $change_count updates would be applied. Re-run with --apply to commit.\n";
}
PHP
