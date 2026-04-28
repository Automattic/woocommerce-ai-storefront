# Release Process

How we version, document, and ship WooCommerce AI Storefront. Aligned with WordPress and WooCommerce plugin conventions.

## TL;DR

- **Versioning:** SemVer (`MAJOR.MINOR.PATCH`).
- **Commits:** Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`).
- **CHANGELOG:** Keep a Changelog format in `CHANGELOG.md`; mirrored summary in `readme.txt`.
- **Tags:** annotated `v<version>` tags trigger the GitHub Actions release workflow.
- **Cadence:** ship when a coherent set of changes is ready. No fixed schedule during prototype phase; expect that to slow as the plugin enters hardening.

## Versioning

We follow [Semantic Versioning 2.0.0](https://semver.org/) with WordPress-plugin nuances.

| Bump | When | Examples |
|------|------|----------|
| **PATCH** (`0.6.4` → `0.6.5`) | Bug fixes, copy edits, dependency bumps with no merchant-visible behavior change, internal refactors. | Fix in PR #119 (message-type rendering hint). |
| **MINOR** (`0.6.x` → `0.7.0`) | New features, new admin controls, new REST endpoints, new filters/actions, new test crawlers added to the canonical list. Backwards-compatible. | Adding a new tab. Surfacing a new stat card. Adding a recognized AI agent. |
| **MAJOR** (`0.x.y` → `1.0.0`, later `1.x.y` → `2.0.0`) | Breaking changes: removed REST endpoint, renamed setting, removed filter, schema migration that doesn't auto-apply on read, changed UCP `PROTOCOL_VERSION` to a non-backwards-compatible revision. | Removing the legacy `utm_medium=ai_agent` recognition path once pre-0.5.0 orders age out. |

While the plugin is `0.x.y` (pre-1.0), the SemVer `0.MINOR.PATCH` pattern still applies but the bar for a MINOR bump is lower — we're allowed to ship reasonably quickly. **Once we ship `1.0.0`, the breaking-change bar tightens significantly.**

### When to ship 1.0

`1.0.0` ships when:

1. The UCP REST adapter's wire shape is stable enough to commit to backwards compatibility for a year+.
2. The merchant-facing settings schema has converged (no more silent migrations expected).
3. Production traffic at scale has validated the plugin under realistic load.
4. The CHANGELOG, user guide, and engineering docs are all current.

This is a deliberate decision, not an automatic milestone.

## Commit messages

We use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/). Format:

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types:**

| Type | Use for | Affects version |
|------|---------|-----------------|
| `feat` | New feature, new endpoint, new filter, new control. | MINOR |
| `fix` | Bug fix. | PATCH |
| `refactor` | Code reorganization without behavior change. | PATCH |
| `docs` | Documentation only. | PATCH (skip if README-only) |
| `test` | Adding or improving tests. | PATCH |
| `chore` | Build, deps, tooling, CI. | PATCH |
| `perf` | Performance improvement. | PATCH |
| `style` | Whitespace, formatting. | (no version bump) |
| `ci` | CI pipeline changes. | (no version bump) |

**Breaking changes:** add `BREAKING CHANGE: ...` to the footer. Triggers MAJOR.

**Scope:** optional, but useful. Common scopes: `ucp`, `attribution`, `admin`, `jsonld`, `robots`, `cache`, `policies`, `discovery`, `tests`, `docs`.

**Subject:** imperative mood, lower case, no trailing period. ≤ 72 chars.

**Examples:**

```
feat(ucp): support optional shipping_address in /checkout-sessions

The handler now passes shipping_address to the Store API products
fetch for region-aware pricing. Continue_url shape is unchanged.

Closes #154.
```

```
fix(attribution): canonicalize utm_source from URL params on lenient gate

Previously the lenient gate compared raw utm_source against
KNOWN_AGENT_HOSTS keys without normalizing — orders with utm_source
set to 'https://openai.com:443/' silently failed to match.

Adds normalize_host_string() upstream of the lookup. 4 new tests in
AttributionTest.

Closes #161.
```

```
refactor(jsonld): extract return-policy block builder into its own method

No behavior change. Splits build_offer() into build_offer() +
build_return_policy_block() so JsonLdReturnPolicyTest can target
the new method directly without reaching through the full markup tree.
```

We don't enforce Conventional Commits via a commit-msg hook today — it's convention. Reviewers nudge in PR review when a subject doesn't follow the pattern.

## CHANGELOG

We maintain two changelogs:

| File | Format | Audience |
|------|--------|----------|
| `CHANGELOG.md` | [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) | GitHub readers, contributors, support engineers |
| `readme.txt` `== Changelog ==` | WP.org plugin format | wp.org browsers, WP-Admin Plugin updates dialog |

### `CHANGELOG.md` format

```markdown
## [Unreleased]

### Features
### Fixes
### Refactors
### Tests
### Docs

---

## [0.7.0] – 2026-05-12

### Features
- **Title-case headline.** Body paragraph explaining what changed, why, and any merchant-facing implication. Code references in backticks. Closes #issue.

### Fixes
- **Title-case headline.** Body paragraph.

---

## [0.6.5] – 2026-04-28
...
```

Conventions:

- `## [X.Y.Z] – YYYY-MM-DD` for each released version. ISO date. En-dash separator.
- `## [Unreleased]` block at the top accumulates entries between releases. Move under the next version header on release.
- Subsections in this fixed order: `### Features`, `### Fixes`, `### Refactors`, `### Tests`, `### Docs`. Omit empty subsections.
- Each entry: a bolded title sentence, then a body paragraph. Body explains _what_, _why_, and _impact_. Reference issue/PR numbers (`Closes #123`, `Addressed by #124`).
- No truncation — long entries are fine. The CHANGELOG is for engineering memory and incident triage, not marketing.
- `---` separator between versions.

### `readme.txt` `== Changelog ==` format

WP.org's plugin readme has a fixed format — different from `CHANGELOG.md`:

```
== Changelog ==

= 0.7.0 - 2026-05-12 =
**New**
* Short headline. (Mirror the CHANGELOG.md headline; one line.)

**Fixed**
* Short headline.

= 0.6.5 - 2026-04-28 =
**Fixed**
* Short headline.
```

Conventions:

- `= X.Y.Z - YYYY-MM-DD =` headers (note: spaces around `=`, hyphen separator).
- `**New**` / `**Fixed**` / `**Improved**` / `**Tweaked**` subsection labels (WP.org style — these match WC Core's readme).
- One-line bullets only. The verbose body that lives in `CHANGELOG.md` belongs there, not here. wp.org renders the readme into a tabbed UI where verbose changelog entries get awkward.
- Keep only the last 5–10 versions in `readme.txt`. Older history lives in `CHANGELOG.md`. The wp.org "Changelog" tab is meant for "what's new lately," not the full archive.

### Today's gap

`readme.txt` currently has only the `0.1.0` entry. Bringing it up to date is a follow-up task — track in an issue and tackle in the next release commit.

## Release checklist

The full sequence for cutting a release. Run from a clean working tree, on `main`, with `gh` authenticated.

### 1. Decide the version

Based on the commits since the last tag:

```bash
git log $(git describe --tags --abbrev=0)..HEAD --oneline
```

Apply the SemVer table above. If you're unsure, default DOWN (PATCH instead of MINOR) — over-bumping in pre-1.0 is fine; under-bumping in post-1.0 is the bug.

### 2. Update version markers

Three places must agree:

- `woocommerce-ai-storefront.php` — both the `* Version: X.Y.Z` header AND the `define( 'WC_AI_STOREFRONT_VERSION', 'X.Y.Z' );` line.
- `package.json` — `"version": "X.Y.Z"`.
- `readme.txt` — `Stable tag: X.Y.Z`.

Unified search/replace:

```bash
NEW=0.7.0
sed -i.bak \
  -e "s/^ \* Version: .*/ * Version: ${NEW}/" \
  -e "s/^define( 'WC_AI_STOREFRONT_VERSION', '.*' );/define( 'WC_AI_STOREFRONT_VERSION', '${NEW}' );/" \
  woocommerce-ai-storefront.php
sed -i.bak "s/^Stable tag: .*/Stable tag: ${NEW}/" readme.txt
sed -i.bak "s/\"version\": \".*\"/\"version\": \"${NEW}\"/" package.json
rm -f woocommerce-ai-storefront.php.bak readme.txt.bak package.json.bak
```

(BSD `sed` on macOS keeps the backup files; the `rm` cleans up.)

### 3. Move `[Unreleased]` to a versioned block

In `CHANGELOG.md`:

```diff
 ## [Unreleased]

+### Features
+### Fixes
+### Refactors
+### Tests
+### Docs
+
+---
+
+## [0.7.0] – 2026-05-12

 ### Features
 - existing entries…
```

The new empty `[Unreleased]` skeleton at the top accumulates entries for the next release.

### 4. Mirror the new entries into `readme.txt`

Trim each headline to one line. Drop the body paragraph. Keep only the last 5–10 versions.

### 5. Run the full quality gate

```bash
composer test                    # PHPUnit
npm run test:js                  # Jest
vendor/bin/phpcs                 # PHPCS
vendor/bin/phpstan analyse       # PHPStan
npm run lint:js                  # ESLint
npm run build                    # Frontend bundle
./bin/make-pot.sh                # Regenerate .pot
git status                       # Confirm .pot is unchanged or commit it
```

If `.pot` regeneration produces a diff, commit it as part of the release commit.

### 6. Commit and tag

```bash
git add -A
git commit -m "chore(release): ${NEW}"
git tag -a "v${NEW}" -m "Release ${NEW}"
git push origin main
git push origin "v${NEW}"
```

### 7. GitHub release

The `v*` tag triggers `.github/workflows/release.yml` which builds the distribution zip and attaches it to a draft GitHub Release. Then:

```bash
gh release edit "v${NEW}" --notes-file <(awk "/## \\[${NEW}\\]/,/^---$/" CHANGELOG.md)
gh release edit "v${NEW}" --draft=false
```

(Or open the draft in the GitHub UI, paste the new CHANGELOG block into the release notes, and click Publish.)

### 8. Verify the release

- The Release page on GitHub shows the version, date, and notes.
- The attached zip downloads cleanly and contains a `woocommerce-ai-storefront/` directory at the top.
- A test install of the zip on a staging WordPress site activates without errors and shows the new version in the Plugins list.

If the merchant-visible behavior changed, also smoke-test the affected flow.

## Hotfix flow

For an urgent patch on a released version (security, broken release):

1. Branch off the version tag, not `main`:
   ```bash
   git checkout -b hotfix/0.6.5-fix-xyz v0.6.5
   ```
2. Make the minimal fix.
3. PATCH bump (`0.6.5` → `0.6.5.1` is NOT valid SemVer; use `0.6.6` and accept that `main` may already be on `0.7.0-dev`).
4. Run the release checklist on the hotfix branch.
5. Tag `v0.6.6`, push.
6. Cherry-pick the fix back to `main` if it isn't already there.

Avoid this flow when possible — it's the least pleasant. If the bug isn't security-critical and `main` is close to ready, ship the next regular release instead.

## PR template

Each PR should update both changelogs as part of the change. Recommended PR template (`.github/pull_request_template.md`):

```markdown
## Summary

<one paragraph: what changed and why>

## Type of change

- [ ] feat
- [ ] fix
- [ ] refactor
- [ ] docs
- [ ] test
- [ ] chore

## CHANGELOG

- [ ] `CHANGELOG.md` `[Unreleased]` block updated
- [ ] `readme.txt` updated (only required if this PR will be in a release before more PRs land)
- [ ] No CHANGELOG entry needed (style-only / CI-only / `[skip changelog]` justified below)

## Verification

- [ ] `composer test` passes
- [ ] `vendor/bin/phpcs` clean on changed files
- [ ] `vendor/bin/phpstan analyse` clean
- [ ] `npm run lint:js` clean
- [ ] `npm run build` produces no diff (or diff is included)
- [ ] `./bin/make-pot.sh` produces no diff (or diff is included)

## Issue

Closes #
```

The `CHANGELOG` section is the most-overlooked checkbox today; the path-based docs-followup workflow plus a CI check for "code changed AND `[Unreleased]` block didn't" closes that gap.

## Automation backlog

Lightweight helpers worth adding once a few releases have shipped under the new process:

1. **`bin/release.sh`** — encapsulates steps 2–6 above into a single command (`./bin/release.sh 0.7.0`). Reads CHANGELOG.md, validates Stable tag matches, runs the test gate, commits, tags. Refuses to run with a dirty working tree.
2. **`/releases/draft` GitHub Action** — on every push to `main` after a release-tagged commit, auto-creates a draft Release with the new `[Unreleased]` block extracted from CHANGELOG.md.
3. **`changelog-check` PR check** — if any file under `includes/` or `client/` changed and `CHANGELOG.md` `[Unreleased]` block didn't, fail with an annotation pointing at the missing entry. Pairs with the docs-followup workflow.

Implement these incrementally — each is a half-day project. Don't pre-build them before we've shipped 5+ releases under the manual process; the automation should follow the patterns we discover, not predict them.

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — context for what changes affect what surfaces
- [`TESTING.md`](TESTING.md) — the quality gate that step 5 runs
- [`HOOKS.md`](HOOKS.md) — public API surface changes that should bump MINOR
- [`API-REFERENCE.md`](API-REFERENCE.md) — REST surface changes that affect compatibility
- [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md) — branch naming, code review
- [`../../CHANGELOG.md`](../../CHANGELOG.md) — the running history
- [`../../readme.txt`](../../readme.txt) — wp.org plugin readme
- [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) — the conventions we follow
