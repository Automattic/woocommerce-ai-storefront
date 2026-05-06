#!/bin/sh
# First-boot setup for the local docker-compose dev stack.
#
# Idempotent: short-circuits when WordPress is already installed.
# Safe to run on every `docker compose up`. Mounted into the
# `bootstrap` service in docker-compose.yml at /bootstrap/.
#
# What it does on a fresh install:
#   1. Wait for /var/www/html/wp-config.php (the official WordPress
#      image's entrypoint creates this on first boot — racy with our
#      bootstrap startup).
#   2. `wp core install` with admin/password/dev@example.com.
#   3. Download WooCommerce (the AI Storefront plugin requires it).
#   4. Activate WooCommerce + this plugin.
#   5. Set pretty permalinks (`/%postname%/`) — required for the
#      plugin's custom rewrite rules to apply.
#   6. Flush rewrite rules so /llms.txt, /.well-known/ucp, /robots.txt
#      respond.
#   7. Enable the plugin's `syndication` setting — without this, the
#      serve_* callbacks return 404 even though the rewrite rules
#      are registered (gated by design for production opt-in).
#
# Login at http://localhost:8030/wp-admin
#   user: admin
#   password: password

set -e

WP_PATH=/var/www/html
WP_URL="${WP_URL:-http://localhost:8030}"
WP_TITLE="${WP_TITLE:-WC AI Storefront Dev}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-password}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-dev@example.com}"

cd "$WP_PATH"

# The official `wordpress` image's entrypoint creates wp-config.php on
# first boot using its DB env vars. Our bootstrap container starts in
# parallel; wait for the file to appear before invoking wp-cli.
echo "[bootstrap] Waiting for wp-config.php..."
i=0
while [ ! -f wp-config.php ] && [ $i -lt 60 ]; do
  sleep 1
  i=$((i + 1))
done
if [ ! -f wp-config.php ]; then
  echo "[bootstrap] ERROR: wp-config.php did not appear within 60s. Aborting."
  exit 1
fi

# Idempotency gate. `wp core is-installed` exits 0 when installed,
# 1 otherwise. If installed, we still want to ensure the plugin
# settings are flipped on (a merchant who restored a DB without our
# settings, or a fresh-install bootstrap that landed in a partial
# state, should converge on the right defaults).
if wp core is-installed --allow-root 2>/dev/null; then
  echo "[bootstrap] WordPress already installed; verifying plugin state."
else
  echo "[bootstrap] Installing WordPress..."
  wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASS" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email \
    --allow-root
fi

# Download WooCommerce if it's not already present. The official
# wordpress image doesn't ship WC; we install it on first boot. wp-cli
# `plugin install` is idempotent (skips when present) so we don't
# need to gate this.
echo "[bootstrap] Ensuring WooCommerce is installed..."
wp plugin install woocommerce --allow-root 2>&1 | grep -v "already installed" || true

# Activate both plugins. `wp plugin activate` is idempotent — emits
# "already active" without erroring when the plugin is already
# active.
echo "[bootstrap] Activating plugins..."
wp plugin activate woocommerce woocommerce-ai-storefront --allow-root

# Pretty permalinks. The plugin's `add_rewrite_rule` calls register
# llms.txt, /.well-known/ucp, and the UCP REST routes — all of these
# need a non-Plain permalink structure to match.
echo "[bootstrap] Setting permalink structure..."
wp option update permalink_structure '/%postname%/' --allow-root

# Enable plugin syndication. The plugin defaults `enabled => 'no'`
# (production-safe — merchants opt in via the admin UI). For local
# dev, flip it on so the discovery URLs respond.
echo "[bootstrap] Enabling plugin syndication..."
wp option update wc_ai_storefront_settings --format=json '{"enabled":"yes"}' --allow-root

# Flush rewrite rules — must run AFTER plugin activation and AFTER
# the syndication setting is enabled (the plugin only registers its
# rewrite rules when those preconditions are met).
echo "[bootstrap] Flushing rewrite rules..."
wp rewrite flush --allow-root

echo "[bootstrap] Done."
echo "[bootstrap] Site:        $WP_URL"
echo "[bootstrap] Admin login: $WP_URL/wp-admin"
echo "[bootstrap] User:        $WP_ADMIN_USER"
echo "[bootstrap] Password:    $WP_ADMIN_PASS"
echo "[bootstrap] Endpoints:"
echo "[bootstrap]   $WP_URL/llms.txt"
echo "[bootstrap]   $WP_URL/.well-known/ucp"
echo "[bootstrap]   $WP_URL/robots.txt"
