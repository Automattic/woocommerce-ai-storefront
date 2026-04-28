# Contributing to WooCommerce AI Storefront

Thanks for your interest. This plugin is in **active prototype-phase iteration** — pace reflects rapid feedback cycles, not instability. Every release ships with full test coverage and a detailed CHANGELOG entry.

## Quick start

```bash
git clone https://github.com/Automattic/woocommerce-ai-storefront.git
cd woocommerce-ai-storefront
composer install
npm install
npm run build
composer test
```

## Required checks before a PR

- `composer test` — PHPUnit (920+ tests).
- `vendor/bin/phpcs` — WordPress coding standards.
- `vendor/bin/phpstan analyse --memory-limit=512M` — static analysis.
- `npm run lint:js` — JS lint via `@wordpress/scripts`.
- `npm run build` — bundle freshness.
- `./bin/make-pot.sh` — i18n template freshness.

CI runs all of these on every PR. Builds fail closed. See [`docs/engineering/TESTING.md`](./docs/engineering/TESTING.md) for the full testing playbook.

## Branch naming

- Use a descriptive change-focused name: `fix/policy-dropdown-system-pages`, not `fix/0.6.1-policy-dropdown`.
- **Don't include version numbers** in branch names or PR titles. Version metadata lives in `package.json` and the plugin header; duplicating it in branch names invites drift.

## Coding conventions

- **PHP**: WordPress + Automattic standards. Tabs, Yoda conditions, strict comparisons, `array()` not `[]`. PHPCS enforces.
- **JS/TS**: ES modules, single quotes, `const` by default, functional React components, `@wordpress/components` for UI. See [`docs/engineering/UI-CONVENTIONS.md`](./docs/engineering/UI-CONVENTIONS.md) for component-library precedence and design-token rules.
- **No em-dashes (`—`) in user-facing copy or docs.** The `Description:` plugin header has rendering edge cases with em-dashes (CSV-split tools, ASCII renderers); the convention extends to all merchant-facing copy.

## Documentation

- Architecture and component overview: [`docs/engineering/ARCHITECTURE.md`](./docs/engineering/ARCHITECTURE.md).
- API reference, data model, hooks, testing, release process: [`docs/engineering/`](./docs/engineering/).
- Merchant user guide: [`docs/user-guide/USER-GUIDE.md`](./docs/user-guide/USER-GUIDE.md).
- Doc index: [`docs/README.md`](./docs/README.md).

## Commit messages

We use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`). Optional scope (`feat(ucp):`, `fix(attribution):`). See [`docs/engineering/RELEASE.md`](./docs/engineering/RELEASE.md) for the full convention and version-bump implications.

## Testing

- New PHP code requires PHPUnit coverage. Tests live in `tests/php/unit/`.
- Bug fixes must include a regression test that fails without the fix.
- JS unit tests cover the `@wordpress/data` store; React components are validated manually in PR review.

## CHANGELOG

Add an entry to the `[Unreleased]` block in [`CHANGELOG.md`](./CHANGELOG.md) with your PR. Format: title sentence + body paragraph + `Closes #issue`. See [`docs/engineering/RELEASE.md`](./docs/engineering/RELEASE.md) for the full conventions.

## Reporting issues

- Functional bugs or feature requests: <https://github.com/Automattic/woocommerce-ai-storefront/issues>.
- Security: **do not open a public issue.** See [`SECURITY.md`](./SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under GPL-3.0-or-later — the same license as the project. See [`LICENSE`](./LICENSE).
