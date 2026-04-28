# Documentation

Two audiences, two folders.

## For merchants

[`user-guide/USER-GUIDE.md`](user-guide/USER-GUIDE.md) — install, enable, configure, monitor. Walks through every tab in the admin UI with screenshots and verification steps.

## For developers

[`engineering/`](engineering/) — read in order:

1. [`ARCHITECTURE.md`](engineering/ARCHITECTURE.md) — what each component does, how they fit together, the design decisions behind them. Start here.
2. [`UCP-BUY-FLOW.md`](engineering/UCP-BUY-FLOW.md) — how an AI agent decides to render a Buy CTA from the three discovery layers (manifest, catalog, checkout-session).
3. [`API-REFERENCE.md`](engineering/API-REFERENCE.md) — UCP REST and admin REST endpoint reference. Request/response shapes, auth, errors, curl examples.
4. [`DATA-MODEL.md`](engineering/DATA-MODEL.md) — every persisted artifact (options, transients, order meta, post meta, scheduled events). Lifetime, who writes/reads, uninstall behavior.
5. [`HOOKS.md`](engineering/HOOKS.md) — filters and actions exposed for extending plugins.
6. [`UI-CONVENTIONS.md`](engineering/UI-CONVENTIONS.md) — React component-library precedence, styling rules, design tokens.
7. [`TESTING.md`](engineering/TESTING.md) — PHP and JS test stacks, conventions, anti-patterns, what CI runs.
8. [`RELEASE.md`](engineering/RELEASE.md) — versioning, CHANGELOG format, release checklist.

## Contributing

See [`CONTRIBUTING.md`](../CONTRIBUTING.md) at the repo root for branch naming, coding conventions, and the pre-PR quality gate.

## Reporting issues

- **Bugs and feature requests:** open an issue on GitHub.
- **Security:** see [`SECURITY.md`](../SECURITY.md). Do not open public issues for security reports.
