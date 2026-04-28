# Contributing to WooCommerce AI Storefront

Thanks for your interest. This plugin is in **active prototype-phase iteration** — the pace of changes reflects rapid feedback cycles, not instability. Every release ships with full test coverage and a detailed CHANGELOG entry.

## Quick Start

```bash
# Clone and install
git clone https://github.com/Automattic/woocommerce-ai-storefront.git
cd woocommerce-ai-storefront
composer install
npm install

# Build the JS bundle
npm run build

# Run the test suite
composer test
```

## Required Checks Before a PR

- `composer test` — PHPUnit (currently 906 tests, 2507 assertions)
- `vendor/bin/phpcs` — WordPress coding standards
- `vendor/bin/phpstan analyse --memory-limit=512M` — static analysis
- `npm run lint:js` — JS lint via `@wordpress/scripts`
- `npm run build` — bundle freshness
- `./bin/make-pot.sh` — i18n template freshness

CI runs all of these on every PR. Builds fail closed.

## Branch Naming

- Use a descriptive change-focused name: `fix/policy-dropdown-system-pages`, not `fix/0.6.1-policy-dropdown`
- **Don't include version numbers** in branch names or PR titles. The version is metadata in `package.json` and the plugin header; putting it in the branch name duplicates info that may drift.

## Coding Conventions

- **PHP**: WordPress + Automattic standards. Tabs, Yoda conditions, strict comparisons, `array()` not `[]`. PHPCS enforces.
- **JS/TS**: ES modules, single quotes, `const` by default, functional React components, `@wordpress/components` for UI.
- **No em-dashes (`—`) in user-facing copy or docs.** The `Description:` plugin header has rendering edge cases with em-dashes (CSV-split tools, ASCII renderers); the convention extends to all merchant-facing copy.
- See [`docs/engineering/ARCHITECTURE.md`](./docs/engineering/ARCHITECTURE.md) for the full architecture and component conventions. Other engineering docs (API reference, data model, testing strategy) live alongside it in [`docs/engineering/`](./docs/engineering/).

## Testing

- New PHP code requires PHPUnit coverage. Tests live in `tests/php/unit/`.
- Bug fixes must include a regression test that fails without the fix.
- JS unit tests are minimal by convention — JSX components are validated manually.

## Reporting Issues

For functional bugs or feature requests, open an issue at <https://github.com/Automattic/woocommerce-ai-storefront/issues>.

For security vulnerabilities, **do not open a public issue**. See [`SECURITY.md`](./SECURITY.md).

## License

By contributing, you agree that your contributions will be licensed under GPL-3.0-or-later, the same license as the project. See [`LICENSE`](./LICENSE).
