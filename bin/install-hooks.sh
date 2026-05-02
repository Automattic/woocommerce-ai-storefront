#!/usr/bin/env bash
#
# Activate the in-repo Git hooks shipped under `.githooks/`.
#
# Git's default hook directory is `.git/hooks/` — which is per-checkout
# and not tracked by Git. Hooks committed to a repo therefore can't be
# placed there directly. The standard workaround is to commit hooks
# to a tracked directory (we use `.githooks/`) and point Git at it via
# `core.hooksPath`.
#
# This script does two things:
#   1. Sets `core.hooksPath` to `.githooks` for the current clone.
#   2. Marks the hook files as executable (a fresh clone may not
#      preserve the +x bit on every filesystem).
#
# The script is idempotent — running it multiple times is harmless.
#
# When this runs
# --------------
# Wired to npm's `prepare` lifecycle script in `package.json`, which
# fires automatically on `npm install` (without args) and `npm ci`.
# Also called from `composer.json`'s `post-install-cmd` so contributors
# who only run `composer install` get the hooks too.
#
# CI behavior
# -----------
# `npm prepare` does NOT run when npm is invoked with `--production`
# or with `NODE_ENV=production` set. CI typically runs in production
# mode, so the hook activation skips automatically — CI doesn't need
# (and wouldn't want) the same auto-regen-and-stage behavior the
# hook provides; CI's job is to fail closed when the .pot is stale,
# which is the safety net the hook is preventing the developer from
# tripping in the first place.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

# `git config core.hooksPath` is local to this checkout (not pushed
# to remote, not shared with other clones) — exactly what we want.
git config core.hooksPath .githooks

# Ensure each hook in `.githooks/` is executable. Most filesystems
# preserve the +x bit through git, but Windows/WSL setups and some
# zip-based imports may strip it.
for hook in .githooks/*; do
	if [ -f "${hook}" ]; then
		chmod +x "${hook}"
	fi
done

echo "git hooks activated (core.hooksPath = .githooks)"
