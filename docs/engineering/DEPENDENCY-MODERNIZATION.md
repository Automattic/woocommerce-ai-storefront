# Dependency Modernization Plan (Strategy B)

Status: planned, not started.
Owner: TBD.
Estimated effort: 0.5 to 1 dev-day, plus PR-review time.
Tracking: see PR #215 for the Strategy A pre-work and remaining advisories.

## Goal

Resolve the residual security advisories that PR #215 could not address without breaking the build, by bumping the project's two largest direct dev dependencies to their latest majors:

- `@wordpress/scripts`: `^28.0.0` → `^32.x` (4 majors)
- `@wordpress/components`: `^28.0.0` → `^33.x` (5 majors)

Both have accumulated multiple years of upstream improvements. Bumping them naturally pulls patched versions of the transitive deps that are stuck on vulnerable majors today.

## Why a separate PR

PR #215 (Strategy A) used `npm overrides` to pin every transitive dep we could pin without breaking compatibility. The overrides we tried but rolled back, and the ESM/CJS interop reasons they failed, are listed in the PR body.

The remaining advisories all share one root cause: the patched version is a semver-major that the surrounding `@wordpress/scripts 28.x` ecosystem cannot accommodate. Bumping these one-by-one via overrides cascades into either (a) the wp-scripts build pipeline (`copy-webpack-plugin`, `babel-loader`, `webpack-dev-server`) or (b) jest's jsdom test environment, both of which break on a global override. The only clean path is to bump the parent.

## Advisories cleared by Strategy B

| Package | Current | Patched at | Reach |
|---------|---------|------------|-------|
| `uuid` | 9.0.1 | 14.0.0 | `@wordpress/components`, `webpack-dev-server` (via sockjs) |
| `webpack-dev-server` | 4.15.2 | 5.2.1 | `@wordpress/scripts`, `@pmmmwh/react-refresh-webpack-plugin` |
| `serialize-javascript` | 6.0.2 | 7.0.5 | `copy-webpack-plugin` 10 (in `@wordpress/scripts`) |
| `@tootallnate/once` | 2.0.0 | 3.0.1 | `http-proxy-agent` → `jsdom` → jest test env |
| `minimatch` 9.0.3 (strict-pinned in `@typescript-eslint/typescript-estree` 6.21) | 9.0.3 | 9.0.7 | TypeScript ESLint chain; bumped naturally when `@wordpress/eslint-plugin` updates |

## Approach

Two-PR sequencing recommended:

### PR B1: bump `@wordpress/scripts` to ^32.x

This is the larger of the two — `@wordpress/scripts` controls webpack, jest, eslint, prettier, and the build/lint/test commands. Expected breaking changes between 28 and 32:

- **Webpack 5 dev-server** API changes (`onBeforeSetupMiddleware`/`onAfterSetupMiddleware` → `setupMiddlewares`).
- **ESLint 9 flat config** — `@wordpress/scripts` 30+ may default to flat config, requiring renaming `.eslintrc.js` → `eslint.config.js` or similar.
- **Jest 30** — minor config changes, mostly `transformIgnorePatterns` updates for ESM packages.
- **Babel 8** transitives — usually invisible.
- **Prettier 3** — quote/trailing-comma defaults shifted (likely small lint diff after running `lint:js-fix`).

Sequence:
1. Branch off main: `chore/bump-wp-scripts-32`.
2. `npm install --save-dev @wordpress/scripts@^32`.
3. `npm install` and inspect lockfile — many transitives will shift.
4. `npm run build` and fix any webpack-config errors.
5. `npm run lint:js -- --fix` and review the diff.
6. `npm run test:js` and fix jest config drift.
7. `npm audit` — should be near zero remaining.
8. Smoke-test `npm run env:start` and the plugin in wp-admin.
9. Update CHANGELOG.

Exit criteria: all CI checks green, `npm audit` reports 0 high or critical vulns.

### PR B2: bump `@wordpress/components` to ^33.x (and `@wordpress/dataviews` minor)

Smaller scope — `@wordpress/components` is a runtime UI dep. Breaking changes between 28 and 33:

- API surface area: a few components renamed or moved (e.g., `__experimental*` promoted or removed).
- `uuid` major bump from 9 to 14 — should not affect callers since this plugin only uses uuid transitively.
- Style tokens: minor visual diff possible if any inline `@emotion` styles changed default theme keys.

Sequence:
1. Branch off main (or off PR B1 if not yet merged): `chore/bump-wp-components-33`.
2. `npm install --save-dev @wordpress/components@^33 @wordpress/dataviews@latest`.
3. `npm run build` and fix any TS/import errors.
4. Inspect any `__experimental*` references in `client/` and update.
5. Smoke-test the settings UI; visual diff against current screenshots.
6. Update CHANGELOG.

Exit criteria: settings UI renders identically, no console errors, all tests pass.

## Risks and mitigations

| Risk | Mitigation |
|------|------------|
| Eslint flat-config migration breaks `lint:js` non-trivially | Run `lint:js -- --fix`, accept the cosmetic diff in the PR; review for any rule-level regressions. |
| Webpack 5 dev-server config differs enough to break `npm start` | Document the new config in `CONTRIBUTING.md`; webpack-dev-server 5 is well-documented. |
| `@wordpress/components` 33 deprecates a component we use | Audit `client/` imports against the [33 changelog](https://github.com/WordPress/gutenberg/blob/trunk/packages/components/CHANGELOG.md) before starting. |
| Jest 30 transformIgnorePatterns regression | Compare with the [@wordpress/scripts 32 jest preset](https://github.com/WordPress/gutenberg/tree/trunk/packages/scripts/config); copy any new patterns. |

## Rollback plan

Both PRs revert cleanly via `git revert <merge-commit>` because the changes are confined to `package.json`, `package-lock.json`, and any generated config diffs. No PHP or runtime code touched.

## Out of scope

- Bumping non-WordPress deps (`cross-env`, `@woocommerce/dependency-extraction-webpack-plugin`) — already current or close enough.
- Refactoring `client/` for new component APIs beyond what the bump strictly requires — keep the PR focused on dep updates; visual changes go in a separate UI PR.

## Reference

- Strategy A: [PR #215](https://github.com/Automattic/woocommerce-ai-storefront/pull/215)
- `@wordpress/scripts` upgrade guide: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
- `@wordpress/components` changelog: https://github.com/WordPress/gutenberg/blob/trunk/packages/components/CHANGELOG.md
