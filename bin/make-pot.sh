#!/usr/bin/env bash
#
# Generate languages/woocommerce-ai-syndication.pot from source strings.
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
# Options:
#   --slug                  — used for the "Project-Id-Version" header
#   --domain                — matches the plugin's Text Domain header
#   --exclude               — skip dev/vendor/build artifacts
#   --file-comment          — prepended to the .pot file as a header
#   --skip-audit            — suppress warnings about string reuse /
#                             unnecessary escapes; we can run with
#                             audit on periodically, but it's noisy
#                             in routine regenerations.
# `build/` is excluded (the minified bundle contains the same strings
# as `client/` source, and scanning both produces duplicates).
# `client/` IS scanned — that's where the admin UI's translatable
# strings live (`__(...)`, `sprintf(__(...))`, etc.).
php "${WP_CLI}" i18n make-pot . languages/woocommerce-ai-syndication.pot \
	--slug=woocommerce-ai-syndication \
	--domain=woocommerce-ai-syndication \
	--exclude=build,tests,vendor,node_modules,.claude,.tools,.github,release-staging \
	--skip-audit

echo ""
echo "✓ Wrote languages/woocommerce-ai-syndication.pot"
wc -l languages/woocommerce-ai-syndication.pot
