#!/usr/bin/env bash
#
# Seed the four subscription test fixtures into the local wp-env stack.
#
# Creates products by SKU (idempotent):
#   AI-SUB-SIMPLE   — simple subscription ($100 / year)
#   AI-SUB-VAR-DEF  — variable-subscription, default term = 6 months
#   AI-SUB-VAR-NDF  — variable-subscription, no default term
#   AI-SUB-VAR-MAL  — variable-subscription, no variations (malformed)
#
# Mirrors the pierorocca.com fixtures used to audit subscription support
# in PR #367 and inform issues #368 + #369. Pinning local fixtures here
# means tests can drive against real WC Subscriptions data during the
# build rather than against the live post-release site.
#
# Pre-requisites:
#   - wp-env stack running (per memory `reference_local_dev_setup.md`)
#   - WC Subscriptions plugin installed + activated in the stack
#     (one-time setup; not handled by this script)
#
# Usage:
#   ./bin/seed-subscription-fixtures.sh
#
# Container override:
#   CONTAINER=woocommerce-ai-storefront-cli-1 ./bin/seed-subscription-fixtures.sh

set -euo pipefail

CONTAINER="${CONTAINER:-woocommerce-ai-storefront-cli}"
SEED_PHP="$(cd "$(dirname "$0")" && pwd)/seed-subscription-fixtures.php"

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
	echo "ERROR: Container '${CONTAINER}' is not running." >&2
	echo "Start the dev stack with the recipe in CLAUDE.md / memory." >&2
	echo "Or override: CONTAINER=<name> $0" >&2
	exit 1
fi

if [ ! -f "$SEED_PHP" ]; then
	echo "ERROR: Seed PHP not found at $SEED_PHP" >&2
	exit 1
fi

# Copy the seed PHP into the container so `wp eval-file` can read it. The
# container's /tmp is ephemeral but writable; this avoids touching the
# WordPress filesystem with a script that isn't part of the plugin.
docker cp "$SEED_PHP" "${CONTAINER}:/tmp/seed-subscription-fixtures.php"

# Bump memory because wp-env's default 128M is exhausted during WP bootstrap
# when WC Payments + Google Listings & Ads are active. `sh -c` is needed so
# the shell expands -d before exec sees it.
docker exec "${CONTAINER}" sh -c 'php -d memory_limit=1G /usr/local/bin/wp eval-file /tmp/seed-subscription-fixtures.php'
