#!/usr/bin/env bash
#
# Generate languages/woocommerce-ai-storefront.pot from source strings.
#
# This is the canonical translation template WP.org and translator
# tools consume. Translators generate locale-specific `.po` / `.mo`
# files from this template.
#
# Usage:  ./bin/make-pot.sh
#
# The script uses WP-CLI's `i18n make-pot` command. It expects the
# wp-cli phar at `.tools/wp-cli.phar` — downloading it the first time
# the script runs. The phar is gitignored (not repo content).
#
# Run whenever a user-facing string changes. The generated .pot file
# IS committed so translators on the WP.org platform can pick it up
# without a build step.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS_DIR="${REPO_ROOT}/.tools"
WP_CLI="${TOOLS_DIR}/wp-cli.phar"

mkdir -p "${TOOLS_DIR}"
mkdir -p "${REPO_ROOT}/languages"

if [ ! -f "${WP_CLI}" ]; then
	echo "Downloading wp-cli.phar..."
	curl -fsSL -o "${WP_CLI}" \
		https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x "${WP_CLI}"
fi

cd "${REPO_ROOT}"

# `i18n make-pot` walks the source tree and extracts every translatable
# string (calls to __(), _e(), _n(), etc.) into a Gettext template.
#
# IMPORTANT: these flags must stay byte-identical with the
# `wp i18n make-pot` invocation in `.github/workflows/ci.yml`'s
# "i18n (.pot freshness)" job. CI regenerates the .pot with its own
# args and diffs against the committed file; if the local script
# uses different args (e.g. a different `--headers` value or a
# different `--exclude` set), the local-regenerated file looks
# fresh locally but fails CI freshness check.
#
# Options:
#   --domain                — matches the plugin's Text Domain header.
#   --exclude               — skip build artifacts + vendor + tests.
#                             CI excludes 4 dirs; mirrored exactly here.
#   --headers               — overrides wp-cli's directory-derived
#                             `Report-Msgid-Bugs-To` URL with the
#                             canonical wordpress.org URL so the file
#                             is byte-stable regardless of which
#                             directory name a contributor checks the
#                             repo into.
#   --skip-plugins          — don't bootstrap WP plugins during scan.
#   --skip-themes           — don't bootstrap WP themes during scan.
#
# `build/` is excluded (the minified bundle contains the same strings
# as `client/` source, and scanning both produces duplicates).
# `client/` IS scanned — that's where the admin UI's translatable
# strings live (`__(...)`, `sprintf(__(...))`, etc.).
php "${WP_CLI}" i18n make-pot . languages/woocommerce-ai-storefront.pot \
	--domain=woocommerce-ai-storefront \
	--exclude=build,node_modules,vendor,tests \
	--headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/woocommerce-ai-storefront"}' \
	--skip-plugins --skip-themes

echo ""
echo "✓ Wrote languages/woocommerce-ai-storefront.pot"
wc -l languages/woocommerce-ai-storefront.pot
