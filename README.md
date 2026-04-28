# WooCommerce AI Storefront

Make your WooCommerce catalog discoverable by AI assistants (ChatGPT, Gemini, Claude, Perplexity, Copilot) while keeping checkout, customer data, and brand experience under merchant control.

**Core principle: AI agents discover and recommend. The merchant owns the transaction.**

> **Status: active prototype-phase development.** Iterating fast; expect frequent releases. Every release ships with full test coverage and a detailed CHANGELOG entry. Cadence will slow as the plugin enters the hardening phase. See [`CONTRIBUTING.md`](./CONTRIBUTING.md) for development setup and conventions.

## What it does

Publishes three discovery surfaces that AI crawlers consume:

- **`/llms.txt`** — Markdown store guide (categories, featured products, attribution instructions)
- **`/.well-known/ucp`** — JSON manifest declaring capabilities, checkout policy (web-redirect only), and purchase URL templates
- **Enhanced JSON-LD** on product pages — Schema.org `Product` augmented with `BuyAction`, inventory, attributes, shipping details

Plus integrations with WordPress and WooCommerce:

- **`robots.txt`** — per-crawler allowlist for 12 commerce-relevant AI bots
- **Store API rate limiting** — built-in WC rate limiter fingerprints AI bots by user-agent (regular customer traffic is unaffected)
- **Order Attribution** — standard `utm_medium=ai_agent` capture, surfaced in WooCommerce core's built-in "Origin" column

## What it doesn't do

- No delegated payments. Checkout happens on your store.
- No authentication. No API keys to manage.
- No custom rate limiter. Uses WC's built-in with user-agent fingerprinting.
- No Stripe or other payment provider dependency.
- **No MCP (Model Context Protocol) support.** MCP requires a server surface reachable by external, non-admin clients — neither WordPress core nor WooCommerce scaffold one today, and there's no first-class plugin hook to add one without standing up auth, transport, and capability-routing infrastructure outside this plugin's reasonable scope. AI Storefront targets the Universal Commerce Protocol (UCP) instead, which works with the public HTTP/REST surfaces WP/WC already expose. MCP will be evaluated once WP or WC grow native MCP-server primitives.

## Requirements

- WordPress 6.7+
- WooCommerce 9.9+
- PHP 8.0+

## Installation

See [readme.txt](readme.txt) for the user-facing installation steps. For development:

```bash
git clone <this repo>
cd woocommerce-ai-syndication
npm install && npm run build
composer install
```

Then symlink or copy the plugin directory into a WordPress install's `wp-content/plugins/` and activate through the admin.

## Development

```bash
npm run build                   # Build frontend bundle
npm run test:js                 # Run JS tests (Jest)
npm run lint:js                 # Lint JS (ESLint via @wordpress/scripts)
vendor/bin/phpunit              # Run PHP tests (PHPUnit + Brain Monkey)
vendor/bin/phpcs                # Lint PHP (WordPress-Extra standard)
vendor/bin/phpcbf               # Auto-fix PHPCS violations
vendor/bin/phpstan analyse      # PHP static analysis (level 5)
```

CI runs all of the above on every push to `main` and on pull requests. See `.github/workflows/ci.yml`.

## Documentation

- **For merchants** — [`docs/user-guide/USER-GUIDE.md`](docs/user-guide/USER-GUIDE.md): install, enable, configure, verify endpoints, read attribution stats, troubleshoot.
- **For developers** — [`docs/engineering/`](docs/engineering/): architecture, UCP buy flow, API reference, data model, testing strategy.
- **Doc index** — [`docs/README.md`](docs/README.md).

## Architecture

See [`docs/engineering/ARCHITECTURE.md`](docs/engineering/ARCHITECTURE.md) for a detailed architecture overview, file map, and design decisions. (`AGENTS.md` at the repo root is a pointer for AI coding agents and links here.)

In brief:

- **Discovery layer** — three stateless generators (`LlmsTxt`, `Ucp`, `JsonLd`) emit the public surfaces. Event-driven cache invalidation on product/category/settings changes keeps them fresh.
- **Attribution** — hooks into WooCommerce Order Attribution to capture AI-referred orders; no custom checkout flow.
- **Rate limiting** — configures WC's built-in Store API rate limiter via `woocommerce_store_api_rate_limit_options` / `_id` filters.
- **Admin UI** — React app built with `@wordpress/components` and `@wordpress/data`. Color tokens are centralized in `client/settings/ai-syndication/tokens.js`.

## Contributing

Issues and pull requests welcome. Please:

1. Run the full quality gate (`phpunit`, `phpcs`, `phpstan`, `test:js`, `lint:js`) before opening a PR.
2. Include tests for new behavior. PHP tests use Brain Monkey (no WordPress install required).
3. Follow the styling rules in AGENTS.md — design tokens via `tokens.js`, component precedence rules for `@wordpress/components` vs. `@woocommerce/components`.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE) if present, or the GPL at https://www.gnu.org/licenses/gpl-3.0.html.
