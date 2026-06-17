# Dependency Modernization (Retrospective)

Status: complete. PRs landed: #215 (overrides), #217 (`@wordpress/scripts` 28→32), #219 (`@wordpress/components` 28→33). Tracking issue: #216.

This doc started as a forward-looking plan and is now a retrospective of what actually shipped. It documents both what the modernization accomplished and what it could not, so future contributors don't relitigate the same investigations.

## Outcome

Starting state: 14 open Dependabot alerts (plus 3 npm-audit-only entries). Ending state: 9 audit entries collapsing to 3 unique remaining advisories. Net change is most of the surface cleared, with a clear story for what's left.

## Strategy A: `npm overrides` (PR #215)

Pinned within-major patches via [`overrides`](../../package.json) for transitive deps that had a patched version in their existing major. Kept after cleanup:

- `@babel/runtime ^7.26.10`
- `basic-ftp ^6.0.0`
- `cross-spawn ^7.0.6`
- `postcss ^8.5.10`
- `ws ^8.17.1`
- `markdownlint-cli → minimatch ^3.1.5` (path-conditional; `markdownlint-cli@0.31.1` ships `minimatch@3.0.8` directly, override forces the patched 3.1.5)
- `@wp-playground/{blueprints,cli,tools} → ajv ^8.17.1` (path-conditional; avoids the global `ajv` cascade that crashes `babel-loader`'s embedded `schema-utils`)

Initially-included overrides that were dropped after the modernization made them moot:

- `@typescript-eslint/typescript-estree → minimatch` — Strategy B's `@wordpress/scripts` bump pulled `typescript-estree v8`, which depends on `minimatch ^10.2.2` (already patched).
- `@wordpress/env → minimatch` — `@wordpress/env`'s rimraf/glob chain landed on patched `minimatch@9.0.9` naturally.
- `tar-fs ^2.1.4` (initially flat, briefly path-conditional under `puppeteer-core`) — both forms turned out to be misguided after Strategy B. The post-modernization chain is `puppeteer-core@23/24 → @puppeteer/browsers@2.x → tar-fs ^3.x` (`@puppeteer/browsers` declares `tar-fs ^3.1.1` / `^3.0.6` directly), so any `^2.x` override forces an incompatible major-version downgrade against the declared peer. The natural resolution lands on `tar-fs@3.1.2`, which is above the patched floors for all known advisories (the 2.x advisories were patched in 2.1.2/2.1.3/2.1.4 but those same vulns are also patched in 3.x at 3.0.7/3.0.9/3.1.0+, well below 3.1.2). Removed entirely; no override needed. Caught by Copilot review on PR #220.

## Strategy B: parent-package major bumps

Two PRs, sequenced because the React UI `@wordpress/components` deps cleanly stack on top of the `@wordpress/scripts` build/lint/test toolchain.

### B1: `@wordpress/scripts` 28→32 (PR #217)

Resolved 32.1.0. Required adaptations:

- **ESLint 9 flat config.** `.eslintrc.*` is no longer auto-loaded. Added [`eslint.config.cjs`](../../eslint.config.cjs) extending `@wordpress/scripts/config/eslint.config.cjs`. Two project rule overrides:
  - `jsdoc/no-undefined-types`: `definedTypes: ['JSX']` so `@type {JSX.Element}` JSDoc keeps validating without rewriting every site to `import('react').JSX.Element`.
  - `no-unused-vars`: `argsIgnorePattern`, `caughtErrorsIgnorePattern`, `varsIgnorePattern` all `^_` so the `_`-prefix unused-binding convention is honored.
- **`engines` floor.** `@wordpress/scripts@32` requires `node >=18.12.0`, `npm >=8.19.2`. Declared in [`package.json`](../../package.json) so contributors on older runtimes hit a clear error rather than confusing install failures.
- **Newly-declared runtime deps.** Stricter `import/no-extraneous-dependencies` revealed `@wordpress/html-entities` and `@wordpress/url` were imported in `client/` without being listed in `package.json`. Added.
- **`catch ( _error )` renames.** Three intentionally-unused `catch ( error )` bindings renamed to satisfy the new `no-unused-vars` strictness.

### B2: `@wordpress/components` 28→33 (PR #219, replacing the auto-closed #218)

Resolved 33.0.0. Plus floor bumps on `@wordpress/dataviews` (^14.2), `@wordpress/i18n` (^6.18), `@wordpress/icons` (^13) to match what the bumped packages require internally and drop duplicate installs.

**No `client/` source changes.** None of the `@wordpress/components` API the plugin uses (DataViews, ToggleControl, etc.) had breaking changes between 28 and 33 that affected the plugin.

## Advisories that didn't clear

These three remain after the full modernization. None are actionable from this repo:

| Package | Patched at | Why we can't clear it |
|---------|------------|-----------------------|
| `uuid` 9.0.1 | 14.0.0 | `@wordpress/components@33` still ships `uuid@9.0.1` internally. The @wordpress team hasn't migrated. The plan assumed B2 would clear this; it didn't. |
| `webpack-dev-server` 4.15.2 | 5.2.1 | `@wordpress/scripts@32` still pins `webpack-dev-server@4.15.2`. Needs a future wp-scripts major. |
| `serialize-javascript` 6.0.2 | 7.0.5 | Pulled in by `copy-webpack-plugin@10` which `@wordpress/scripts@32` still pins. Same upstream dependency. |

A global `uuid: ^14.0.0` override was tried during Strategy A and rolled back because uuid 14.x is ESM-only and broke `@wordpress/components`'s CJS imports. Same shape of breakage for `webpack-dev-server 5.x` (different dev-server API) and `serialize-javascript 7.x` (when forced through `copy-webpack-plugin@10`'s expectations).

The honest read: all three need upstream maintenance, not local action.

### Update 2026-06-17: Dependabot surfaces 6 open alerts (same WP-toolchain pinning shape)

The three above, plus three more transitive build-tool deps — all surfaced by Dependabot. None are in the shipped plugin artifact, and none fail the production-scoped CI gate (`npm audit --omit=dev --audit-level=high`), which is why "Security audit (composer + npm)" stays green.

**Cleared via flat `overrides` (this PR):** three of the six pin cleanly without breaking the gate (build + jest + eslint all pass; markdownlint still runs under markdown-it 14):

| Package | Scope | Override | Was → now |
|---------|-------|----------|-----------|
| `shell-quote` (was CRITICAL) | dev | `^1.8.4` | 1.8.3 → 1.8.4 (patch) |
| `markdown-it` | dev | `^14.2.0` | 12.3.2 → 14.2.0 (only consumer is `markdownlint-cli`, not run in CI/build) |
| `qs` | dev | `^6.15.2` | 6.14.2 → 6.15.2 (patch) |

This clears the CRITICAL `shell-quote` (a `@wordpress/env` dev dep that never reached plugin users anyway) plus two moderates — `npm audit` drops from 73 → 66 with no critical remaining.

**Round 2 (2026-06-17):** two more within-major advisories cleared via override — `js-yaml` (→ 4.2.0) and `@babel/core` (→ 7.29.6, deliberately staying on 7.x, not the 8.0 major). Verified against the full gate (build unchanged, 302 jest tests / 14 suites pass, eslint clean). Production-scoped audit stays at 0.

**Still deferred** — three resist a clean local fix. Recon (2026-06-17) confirmed the upstream situation:

| Package | Scope | Patched at | Why it can't clear locally |
|---------|-------|------------|----------------------------|
| `serialize-javascript` | dev | 7.0.5 | Comes via `@wordpress/scripts`' own `copy-webpack-plugin@10` chain. The **latest** `@wordpress/scripts` (32.4.1) still pins `copy-webpack-plugin ^10.2.0` — there is no upstream version to bump to. |
| `webpack-dev-server` | dev | 5.2.4 | The **latest** `@wordpress/scripts` (32.4.1) still pins `webpack-dev-server ^4.15.1`; v5 has a different dev-server API. Local dev server only. |
| `uuid` | runtime | 11.1.1 | `@wordpress/components@33.1` *does* adopt the patched `uuid@14`, clearing the runtime copy — **but `uuid@14` is ESM-only, and every patched uuid (≥11) ships ESM-only**, so jest's `node_modules` transform chokes (`SyntaxError: Unexpected token 'export'` via `@wordpress/components/style-provider`). Fixing it needs a `jest.config.js` + `--config` + hand-tuned `transformIgnorePatterns` (the fragile surgery Strategy A warns against) plus a bundle change — verified, then reverted. Not exploitable as used (WP's uuid usage is v4-only, no `buf`). |

`serialize-javascript` and `webpack-dev-server` are blocked on WordPress upstream; `uuid` is blocked on the ESM/jest cascade. All three are dev-tooling or non-exploitable runtime, never shipped, and the production-scoped CI audit stays green.

## Lessons learned

### npm `overrides` cannot violate exact pins in deeply-nested `package.json`s

The targeted `@typescript-eslint/typescript-estree → minimatch ^9.0.7` override in Strategy A *silently failed*. ts-estree 6.21.0 (the version present at that time) had `"minimatch": "9.0.3"` as an *exact pin* in its own published `package.json`. npm's overrides system can rewrite *semver ranges* but cannot violate a deeper-nested package's exact pin — so it satisfied the override by creating a new nested `9.0.3` rather than upgrading. The advisory remained open in the lockfile. We didn't catch it until the retrospective review.

**Practice:** after writing an override, run `npm install` then check `node_modules/<parent>/node_modules/<target>/package.json` to confirm the resolved version actually matches the floor. If it doesn't, the override is silently ineffective and the advisory is still live.

This is also why most overrides should be **path-conditional** rather than flat — the path-conditional form `"<parent>": { "<child>": "<version>" }` makes the targeting explicit, narrows blast radius, and makes "did this override do anything?" easier to answer by checking only the named parent's `node_modules`.

### Forced ESM-only majors break CJS-importing parents

Several attempted overrides cascaded into build/lint/test failures during Strategy A. Common shape: the patched version is ESM-only, but a transitive parent imports it via CJS `require()`. Examples we hit:

- Global `minimatch ^9.0.7`: `eslint-plugin-jsx-a11y` does `require('minimatch').default`; minimatch 9.x dropped the `.default` export.
- `@tootallnate/once 3.x`: ESM-only; `http-proxy-agent` (jest's jsdom env) requires it via CJS.
- Global `ajv ^8.17.1`: cascaded into `babel-loader`'s embedded `schema-utils` whose pinned `ajv-keywords` crashed against ajv 8 internals.

**Practice:** when a major bump introduces ESM-only or a renamed export shape, it's almost always going to cascade. Either keep it scoped to the chains where you've verified compatibility (path-conditional override) or wait for the parent to bump.

### GitHub stack-merge gotcha: `--delete-branch` auto-closes child PRs

When PR #217 merged with `--delete-branch`, GitHub auto-closed PR #218 because its base branch (`chore/bump-wp-scripts-32`) was the one being deleted. **Closed PRs cannot be reopened.** PR #218 was lost; #219 was opened fresh with the same content rebased onto current main.

**Practice:** for stacked PRs, either retarget the child to `main` *before* merging the parent, or enable "Auto-delete head branches" in repo settings so GitHub auto-retargets children before deleting the parent's branch. Don't merge with `--delete-branch` until the children are retargeted.

### Cherry-pick is cleaner than rebase for stacks once the parent has merged

After PR #217 squash-merged, rebasing PR #218 onto main hit conflicts on every file the squash had touched. The clean pattern was `git reset --hard origin/main && git cherry-pick <feature-commit>` — pulls just the actual feature change forward, avoids the squash-vs-original-history conflict dance, and produces a single-commit branch that's ready for squash merge.

## Reference

- PR #215: `npm overrides` for within-major patches
- PR #217: `@wordpress/scripts` 28→32
- PR #219: `@wordpress/components` 28→33 (replaces auto-closed #218)
- Issue #216: tracking issue for the whole modernization
- `@wordpress/scripts` upgrade guide: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
- `@wordpress/components` changelog: https://github.com/WordPress/gutenberg/blob/trunk/packages/components/CHANGELOG.md
