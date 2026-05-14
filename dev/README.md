# `/dev/` — Development-only assets

**This directory does NOT ship in the plugin distribution.**

The `release.yml` workflow's rsync excludes `/dev/` from the release zip,
so nothing in here reaches merchants. It exists for development-side
conveniences that don't belong in the plugin itself.

## What lives here

### `test-store-brand/`

Brand identities for **personal/team test stores** used to validate
plugin behavior against real AI engines (ChatGPT, Gemini, Claude,
Perplexity).

Why this matters: AI engines pattern-match on domain history,
on-page brand signals, and OG metadata to decide whether a site
"looks real." Stores with placeholder identities (default WordPress
theme, "My WordPress Site" title, no favicon) get treated as dev
infrastructure, which can trigger hallucination instead of actual
fetches. A credible-looking test store produces more reliable
smoke-test signal.

Each subdirectory is one self-contained brand identity:

- `saltwarp/` — Piero's test store identity. Premium streetwear
  archetype. Wordmark + geometric textile-cross mark.

The plugin itself stays brand-agnostic. WordPress's standard Site
Identity controls (title, tagline, site icon, custom logo) are what
the plugin reads at runtime — the assets here just give you something
to upload there.

## What does NOT belong here

- Anything the plugin's runtime code references. If a class in
  `includes/` would need to load a file, that file goes in
  `includes/` or `assets/`, not `dev/`.
- Tests or fixtures. Those live in `tests/` and ship to contributors
  but not to merchants — different exclusion category.
- Build tooling. Those live in `bin/` or project root config files.

If you find yourself wanting to add something that another developer
on this plugin would need, it probably doesn't belong in `/dev/`.
