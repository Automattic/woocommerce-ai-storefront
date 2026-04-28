# Agent Guide

Conventions and context for AI coding agents (Claude Code, Cursor, Copilot, etc.) working in this repo.

This file is the authoritative source. [`CLAUDE.md`](./CLAUDE.md) is a pointer.

## What this is

WooCommerce AI Storefront is a WordPress/WooCommerce plugin that publishes machine-readable signals (`/llms.txt`, `/.well-known/ucp`, enhanced JSON-LD on product pages) so AI shopping assistants can discover and recommend products while the merchant keeps checkout, customer data, and the payment relationship.

Core principle: AI agents discover and recommend. The merchant owns the transaction.

## Where to look

| For | Read |
|-----|------|
| What each component does | [`docs/engineering/ARCHITECTURE.md`](./docs/engineering/ARCHITECTURE.md) |
| How an agent decides to render a Buy CTA | [`docs/engineering/UCP-BUY-FLOW.md`](./docs/engineering/UCP-BUY-FLOW.md) |
| REST endpoint shapes (UCP and admin) | [`docs/engineering/API-REFERENCE.md`](./docs/engineering/API-REFERENCE.md) |
| Options, transients, meta keys, cron | [`docs/engineering/DATA-MODEL.md`](./docs/engineering/DATA-MODEL.md) |
| Filters and actions exposed | [`docs/engineering/HOOKS.md`](./docs/engineering/HOOKS.md) |
| React UI conventions | [`docs/engineering/UI-CONVENTIONS.md`](./docs/engineering/UI-CONVENTIONS.md) |
| PHP / JS test conventions | [`docs/engineering/TESTING.md`](./docs/engineering/TESTING.md) |
| Versioning, CHANGELOG, release flow | [`docs/engineering/RELEASE.md`](./docs/engineering/RELEASE.md) |
| Branch naming, code-review conventions | [`CONTRIBUTING.md`](./CONTRIBUTING.md) |
| Merchant-facing context | [`docs/user-guide/USER-GUIDE.md`](./docs/user-guide/USER-GUIDE.md) |

## Code conventions

### PHP

- WordPress + Automattic standards. Tabs, Yoda conditions, strict comparisons (`===` / `!==`), `array()` not `[]`. PHPCS enforces.
- HPOS-compatible: order access goes through `WC_Order` methods exclusively, never raw post-meta queries on `shop_order` posts.
- Pure translators, caller-orchestrated dispatch — translators (`WC_AI_Storefront_UCP_Product_Translator`, etc.) are pure functions; controllers orchestrate fetching.
- Defensive guards over assumptions about WP/WC state. Functions like `function_exists`, `class_exists`, and `is_array` checks before reading optional structures.

### JS

- ES modules, single quotes, `const` by default, functional React components.
- `@wordpress/components` is the default UI library. `@wordpress/dataviews` for tables. **Do not** add `@woocommerce/components` back without reading [`docs/engineering/UI-CONVENTIONS.md`](./docs/engineering/UI-CONVENTIONS.md) first — there's a CSS-enqueue trap.
- Inline `style={ ... }` props are fine for this codebase, but **all colors must come from `client/settings/ai-storefront/tokens.js`**. Raw hex literals in JSX are a lint-review red flag.

### User-facing copy

- **No em-dashes (`—`) in merchant-facing copy or readme.txt.** The plugin's `Description:` header has rendering edge cases with em-dashes (CSV-split tools, ASCII renderers); the convention extends to all merchant-facing UI strings, error messages, and the user guide. En-dashes (`–`) and hyphens (`-`) are fine.
- Sentence case for tab labels, button labels, headings. Title Case is wrong here.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/). Format:

```
<type>(<scope>): <subject>

<body>

<footer with Closes #N>
```

Types: `feat` / `fix` / `refactor` / `docs` / `test` / `chore` / `perf` / `style` / `ci`.

Scopes (use when helpful): `ucp`, `attribution`, `admin`, `jsonld`, `robots`, `cache`, `policies`, `discovery`, `tests`, `docs`.

Subject: imperative, lower-case, ≤ 72 chars, no trailing period.

See [`docs/engineering/RELEASE.md`](./docs/engineering/RELEASE.md) for the full convention and version-bump implications.

## Versioning

[SemVer](https://semver.org/). Pre-1.0 the MINOR bar is lower; that tightens at 1.0.

| Bump | When |
|------|------|
| PATCH | Bug fix, copy edit, refactor, docs, test, chore. |
| MINOR | New feature / endpoint / filter / setting. Backwards-compatible. |
| MAJOR | Breaking change to a REST endpoint, removed filter, schema migration without auto-apply, incompatible UCP `PROTOCOL_VERSION` bump. |

When you bump version, three places must agree: `woocommerce-ai-storefront.php` plugin header **and** `WC_AI_STOREFRONT_VERSION` constant, `package.json`, `readme.txt` Stable tag. The `sed` recipe is in `docs/engineering/RELEASE.md`.

## CHANGELOG

Two changelogs:

- [`CHANGELOG.md`](./CHANGELOG.md) — [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. Verbose entries with body paragraphs explaining what changed and why. Audience: engineers, support.
- [`readme.txt`](./readme.txt) `== Changelog ==` — one-line WP.org-style bullets. Mirror the headlines from `CHANGELOG.md`. Keep only the last 5–10 versions. Audience: wp.org browsers and the Plugins admin updater.

Every PR adds an entry to the `[Unreleased]` block in `CHANGELOG.md`. The PR template's CHANGELOG checkbox should be ticked or explicitly waived.

## Testing

PHP suite uses PHPUnit + Brain Monkey + Mockery. **No real WordPress install required** — Brain Monkey mocks WP/WC functions. Tests live in `tests/php/unit/`, flat structure, one file per production class. Naming: `<UnitOfBehaviorBeingTested>Test.php`. Test methods: `test_<what>_<conditions>_<outcome>` snake_case.

JS suite (Jest) covers the `@wordpress/data` store only — reducers, selectors, async thunks. React components are validated manually in PR review.

Pre-PR quality gate (CI runs all of these):

```bash
composer test                          # PHPUnit
vendor/bin/phpcs                       # WP coding standards
vendor/bin/phpstan analyse --memory-limit=512M  # static analysis
npm run lint:js                        # JS lint
npm run build                          # bundle freshness
./bin/make-pot.sh                      # i18n template freshness
```

Bug fixes must include a regression test that fails without the fix.

## Documentation conventions

When you add or update an engineering doc:

- Open with the audience-and-purpose paragraph. No marketing.
- Code references in backticks. Class names in PascalCase, file paths in code-fenced relative form.
- Long lists go in tables. Tables beat bulleted prose for reference content.
- Add a `## See also` footer linking to sibling docs. Bidirectional links are the rule, not the exception.
- The merchant guide (`docs/user-guide/USER-GUIDE.md`) is task-oriented (one section per merchant goal). Engineering docs are reference-shaped (one section per concept).
- No glossary in engineering docs. Define terms inline on first use; assume the reader is a developer.
- No em-dashes in `docs/user-guide/`. Em-dashes are fine in engineering docs.

## Path → doc impact map

When you change a code file, update the corresponding doc(s) in the same PR. The mapping mirrors `.github/workflows/docs-followup.yml`:

| Code path | Update these docs |
|-----------|-------------------|
| `includes/ai-storefront/ucp-rest/**` | API-REFERENCE.md, UCP-BUY-FLOW.md, ARCHITECTURE.md |
| `includes/admin/class-wc-ai-storefront-admin-controller.php` | API-REFERENCE.md, ARCHITECTURE.md, USER-GUIDE.md |
| `includes/ai-storefront/class-wc-ai-storefront-attribution.php` | DATA-MODEL.md, UCP-BUY-FLOW.md, USER-GUIDE.md |
| `includes/ai-storefront/class-wc-ai-storefront-ucp.php` | API-REFERENCE.md, UCP-BUY-FLOW.md |
| `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` | ARCHITECTURE.md, HOOKS.md |
| `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` | ARCHITECTURE.md, HOOKS.md, USER-GUIDE.md |
| `includes/ai-storefront/class-wc-ai-storefront-robots.php` | ARCHITECTURE.md, HOOKS.md, USER-GUIDE.md |
| `includes/ai-storefront/class-wc-ai-storefront-store-api-rate-limiter.php` | ARCHITECTURE.md, USER-GUIDE.md |
| `includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php` | DATA-MODEL.md, ARCHITECTURE.md |
| `includes/ai-storefront/class-wc-ai-storefront-logger.php` | HOOKS.md |
| `includes/ai-storefront/class-wc-ai-storefront-return-policy.php` | DATA-MODEL.md, USER-GUIDE.md |
| `includes/admin/class-wc-ai-storefront-product-meta-box.php` | DATA-MODEL.md, USER-GUIDE.md |
| `includes/class-wc-ai-storefront.php` | DATA-MODEL.md, ARCHITECTURE.md |
| `uninstall.php` | DATA-MODEL.md |
| `client/settings/ai-storefront/**` | UI-CONVENTIONS.md, USER-GUIDE.md |
| `client/data/ai-storefront/**` | UI-CONVENTIONS.md |
| `webpack.config.js` | UI-CONVENTIONS.md, ARCHITECTURE.md |
| `composer.json` | TESTING.md |
| `package.json` | TESTING.md, RELEASE.md |
| `phpcs.xml.dist`, `phpstan.neon.dist` | TESTING.md |
| `.github/workflows/**` | TESTING.md, RELEASE.md |

If you add a code file outside this map, add a row to both this table and the workflow YAML so the autonomous docs-followup picks it up.

## Workflow guidance

If you're editing this repo as an autonomous agent (e.g. via the `docs-followup` workflow), here's what we expect:

- **Bias toward conservative edits.** The goal is keeping docs honest, not rewriting them. Don't restructure a doc that's still correct — fix the stale facts and leave the structure alone.
- **Don't open empty PRs.** If the diff doesn't actually contradict the docs, log a "no changes needed" summary and exit.
- **Run the quality gate before committing.** Compose the gate from the commands above. If anything fails, surface the failure in the PR body — don't silently revert.
- **One concern per PR.** A docs-followup PR should be about doc edits prompted by a specific commit. Don't bundle unrelated cleanup into it.
- **Reference the triggering commit** in the PR body: `Auto follow-up to <commit subject> (<short-sha>).` Then a per-doc rationale.
- **Label the PR** `documentation` and `automated` so reviewers can filter.
- **Don't push directly to `main`.** Always branch (`docs/auto-followup-<short-sha>`), commit, push, open a PR. Reviewers approve and merge.

## Reporting

- Bugs and feature requests: <https://github.com/Automattic/woocommerce-ai-storefront/issues>.
- Security: **do not open a public issue.** See [`SECURITY.md`](./SECURITY.md).
