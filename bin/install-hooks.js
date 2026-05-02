#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Activate the in-repo Git hooks shipped under `.githooks/`.
 *
 * Git's default hook directory is `.git/hooks/` — per-checkout, not
 * tracked by Git. Hooks committed to a repo can't live there directly.
 * Standard workaround: commit hooks to a tracked directory (we use
 * `.githooks/`) and point Git at it via `core.hooksPath`.
 *
 * This script does two things:
 *   1. Sets `core.hooksPath` to `.githooks` for the current clone.
 *   2. Marks each hook in `.githooks/` as executable (a fresh clone
 *      may not preserve the +x bit on every filesystem).
 *
 * Idempotent — running multiple times is harmless.
 *
 * Why this is Node, not bash
 * --------------------------
 * Earlier revisions called `bin/install-hooks.sh`. That broke
 * dependency-install on Windows environments without `bash` (notably
 * Windows-native PowerShell, no Git Bash / WSL). Since `npm prepare`
 * fires inside `npm install`, a hard-failing `bash` invocation could
 * abort the entire install before the contributor could do anything.
 * Node is guaranteed to exist if `npm install` is running.
 *
 * When this runs
 * --------------
 * - `npm install` and `npm ci` (via the `prepare` lifecycle script)
 * - `composer install` and `composer update` (via `post-install-cmd`
 *   and `post-update-cmd`, which spawn `node` directly)
 *
 * CI behavior
 * -----------
 * `npm prepare` does NOT run when npm is invoked with `--production`
 * or `NODE_ENV=production`. CI typically runs in production mode, so
 * the activation skips automatically. CI doesn't need the auto-regen
 * hook — CI's job is to fail closed on stale .pot, which is the safety
 * net the hook prevents the developer from tripping in the first place.
 *
 * Failure handling
 * ----------------
 * Best-effort. If git isn't available, or `.githooks/` doesn't exist,
 * or chmod fails, this script logs a warning and exits 0 — never
 * breaks the install. Hooks are a productivity tool, not a correctness
 * requirement; a failure here should never block a contributor from
 * running their tests.
 */

'use strict';

const { spawnSync } = require('node:child_process');
const { existsSync, readdirSync, statSync, chmodSync } = require('node:fs');
const { join } = require('node:path');

const REPO_ROOT = join(__dirname, '..');
const HOOKS_DIR = join(REPO_ROOT, '.githooks');

function warn(msg) {
	console.warn(`install-hooks: ${ msg }`);
}

// Bail quietly if the hooks directory is missing — happens if the
// script is run from an export of the repo that excluded `.githooks/`.
if (!existsSync(HOOKS_DIR)) {
	warn(`.githooks/ not found at ${ HOOKS_DIR }; skipping activation.`);
	process.exit(0);
}

// Set `core.hooksPath` for the current clone. `git config` exits 0
// on success and writes to `.git/config`, which is local — not pushed,
// not shared. Exactly the scope we want.
const gitResult = spawnSync(
	'git',
	['config', 'core.hooksPath', '.githooks'],
	{ cwd: REPO_ROOT, stdio: 'inherit' }
);

if (gitResult.error) {
	warn(`could not run \`git config\`: ${ gitResult.error.message }. Hooks not activated.`);
	process.exit(0);
}

if (gitResult.status !== 0) {
	warn(`\`git config core.hooksPath\` exited ${ gitResult.status }. Hooks may not be active.`);
	process.exit(0);
}

// Mark each hook in `.githooks/` as executable. Most filesystems
// preserve the +x bit through git, but Windows-on-NTFS and zip-based
// imports may strip it. Skip on Windows entirely — Windows file modes
// don't have a Unix execute bit; Git for Windows treats `.githooks/`
// shell scripts as executable as long as Git's `core.fileMode` allows.
if (process.platform !== 'win32') {
	try {
		for (const entry of readdirSync(HOOKS_DIR)) {
			const path = join(HOOKS_DIR, entry);
			const st = statSync(path);
			if (st.isFile()) {
				// 0o755 = rwx for owner, rx for group/others. Standard
				// shell-script permission set.
				chmodSync(path, 0o755);
			}
		}
	} catch (err) {
		warn(`chmod failed: ${ err.message }. Hooks may not be executable.`);
		// Don't exit non-zero — chmod failure isn't fatal; Git might
		// still execute the hooks depending on filesystem.
	}
}

console.log('git hooks activated (core.hooksPath = .githooks)');
