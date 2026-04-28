# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this plugin, please **do not** open a public GitHub issue. Instead:

1. **Preferred — Automattic's bug bounty program on HackerOne**: <https://hackerone.com/automattic>. Reports submitted through HackerOne are eligible for rewards based on severity and follow Automattic's structured triage process.

2. **Email fallback**: <security@automattic.com>. Use this if you don't have a HackerOne account or prefer email-based disclosure.

Please include:

- A description of the vulnerability
- Steps to reproduce
- Impact assessment
- Affected versions

We aim to acknowledge reports within 48 hours and to coordinate disclosure on a timeline that gives merchants time to update.

## Supported Versions

The plugin is in active development. Security fixes target the latest minor release line; older versions may not receive backports. Run the latest version in production.

## Plugin Update Mechanism

Stores running this plugin check for updates via the GitHub release feed for this repository. Authenticated update-check requests (using a GitHub Personal Access Token wired through the `wc_ai_storefront_github_token` filter) are recommended for stores with high-volume admin activity to avoid GitHub's anonymous rate limits. See `includes/class-wc-ai-storefront-updater.php` for details.
