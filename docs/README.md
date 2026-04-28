# Documentation

This folder is the documentation home for WooCommerce AI Storefront. Two audiences, two subfolders.

## For merchants

[`user-guide/USER-GUIDE.md`](user-guide/USER-GUIDE.md) — install, enable, configure, monitor. Walks through every tab in the admin UI with screenshots and verification steps.

Topics:

- Install and activate
- Enable AI Storefront and choose product visibility
- Configure crawlers, rate limits, and return policy
- Verify your discovery endpoints (`/llms.txt`, `/.well-known/ucp`, `robots.txt`, JSON-LD)
- Read AI-attributed order stats
- Day-two maintenance and troubleshooting

## For developers

The [`engineering/`](engineering/) folder. Read in order:

1. [`ARCHITECTURE.md`](engineering/ARCHITECTURE.md) — what each component does, how they fit together, and the design decisions behind them. Start here.
2. [`UCP-BUY-FLOW.md`](engineering/UCP-BUY-FLOW.md) — how an AI agent decides to render a "Buy" button from the three discovery layers (manifest, catalog, checkout-session).
3. [`API-REFERENCE.md`](engineering/API-REFERENCE.md) — endpoint-level reference for the UCP REST adapter and the admin REST API. Request/response shapes, auth, errors, curl examples.
4. [`DATA-MODEL.md`](engineering/DATA-MODEL.md) — every persisted artifact (options, transients, order meta, post meta, scheduled events) — who writes/reads each, lifetime, uninstall behavior.
5. [`TESTING.md`](engineering/TESTING.md) — PHP and JS test stacks, conventions, how to add a test, what CI runs.

## Contributing

See [`CONTRIBUTING.md`](../CONTRIBUTING.md) at the repo root for branch naming, coding conventions, and the required pre-PR quality gate.

## Reporting issues

- **Bugs and feature requests:** open an issue at the project's GitHub repository.
- **Security:** see [`SECURITY.md`](../SECURITY.md). Do not open public issues for security reports.
