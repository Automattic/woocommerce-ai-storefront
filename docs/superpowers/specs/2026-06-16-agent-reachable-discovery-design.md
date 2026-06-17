# Agent-Reachable Discovery — Design

**Date:** 2026-06-16
**Status:** Approved (design); pending implementation plan
**Branch:** `feat/agent-reachable-discovery`

## Context

Live testing against saltwarp.shop with real AI assistants (ChatGPT, Perplexity, Gemini, Microsoft Copilot, and Claude on personal/work accounts) surfaced a concrete, reproducible barrier: **cold "casual-mode" agents that fetch a URL cannot reach the store's machine-readable surfaces**, even though those surfaces are healthy (all return `200`, edge-cached at `max-age=300`, robots-allowed).

The barrier is a **fetch-tool limitation, not access control and not policy**. Verified from a Claude `web_fetch` transcript (the agent describing its own tool) plus HTML inspection of saltwarp.shop:

1. **Allowlist gating.** `web_fetch` only retrieves a URL that has already appeared as literal text in a prior search result or a prior fetch's returned content. A JSON manifest like `/.well-known/ucp` is not search-indexed, so it never "surfaces" → every direct attempt returns `PERMISSIONS_ERROR`.
2. **Markdown-only extraction.** The tool keeps visible body text and `<a>` anchors, and **strips `<head>` `<link rel>` tags and `<script type="application/ld+json">` JSON-LD**. On saltwarp, `/.well-known/ucp` is advertised *only* via `<link rel="ucp-agent">` in `<head>` (verified: 1 `<link>`, 0 `<a>` anchors), so it is stripped out of every fetch → never enters the allowlist.
3. **Snap-to-seen query strings.** For a known path, the tool substitutes the literal example query string it has seen (`?ids=prod_1,prod_2,…` from llms.txt) for whatever parameters the agent actually appends, returning a `not_found` stub. Parameterized endpoints are therefore fragile for this class of tool; **parameterless URLs that appear as literal text** are the reliable surface.

Consequence: the agent fetched the homepage, the only UCP reference (a `<head>` link) was stripped, the URL never became fetchable, and the agent improvised ("paste the JSON yourself"). Capable agents/modes (Opus on a work account, ChatGPT enumerating `/products.json`, agentic browsers) already get through; this design targets the **lowest-common-denominator markdown fetcher**.

The reference store (allbirds.com, Shopify) does **not** solve this either — it links none of `/llms.txt`, `/.well-known/ucp`, `/products.json` from its HTML (0 anchors, 0 head links) and relies on platform pipelines (Shopify → Google/OpenAI feeds). A standalone WooCommerce store has no such pipeline, so a small, honest discoverability nudge is worthwhile.

## Goals

- Let a cold markdown-extraction agent **bootstrap discovery** from a single page fetch.
- Stop **actively misleading** such agents with a placeholder lookup example that resolves to `not_found`.
- Give weak fetchers a **single parameterless product-data URL** that returns the whole catalog.

## Non-goals (explicitly deferred)

- **Homepage JSON-LD `ItemList`** of featured products. (A `<script>` ItemList is stripped by markdown extraction, so it helps only structured crawlers — a separate audience.)
- **Google Merchant Center feed** (`g:` namespace product feed).

These two are bundled as a future "Google structured-crawler" effort, decided separately.

## Honesty boundary

We add **visible, truthful** links to our own data — ordinary web discoverability, the same family as sitemaps / `robots.txt Allow` / llms.txt. We explicitly reject: hidden/cloaked links, agent-directed instructions injected into the page, or any surface that shows agents something different from humans. There is no content-safety dimension — the agent confirmed UCP/REST/JSON-LD are "exactly the kind of thing I'd otherwise fetch without hesitation."

## Design

All changes live in `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` and are gated on the master `enabled` setting. No theme edits.

### Change 1 — Surface `/llms.txt` as one followable link

A `wp_footer` hook (theme-agnostic; every compliant theme calls `wp_footer()`) emits a **single, visible, unobtrusive** body anchor when syndication is enabled:

```html
<a href="https://{host}/llms.txt" rel="alternate" type="text/markdown">Machine-readable store data: llms.txt</a>
```

- The anchor is real body content, so it survives markdown extraction and enters the agent's fetchable allowlist on any page fetch.
- llms.txt enumerates every other endpoint as literal text, so one reachable anchor **bootstraps the whole chain**.
- Gated on `enabled === 'yes'`. Minimal, unstyled-or-lightly-styled markup; visible (not hidden).

**Open implementation detail for the plan:** exact label text and footer placement/markup; whether to register the hook from the llms-txt class (preferred — thematically owns llms.txt) or the main bootstrap class.

### Change 2 — Replace the lookup placeholder with a real, working example

In `generate()`, the catalog-lookup examples currently render the literal placeholder `?ids=prod_1,prod_2,…`. Replace the anchoring example with a **dynamically-generated real one** drawn from an actual syndicated product — preferring the slug form, which is the most agent-friendly (the slug is already in the product URL the agent holds):

```
GET https://{host}/wp-json/wc/ucp/v1/catalog/lookup?slug={real-handle}
```

- Keep the `?slug={handle}` / `?ids={ids}` placeholder documentation for clarity, but lead with a real, fetchable example so a snap-to-seen fetcher lands on **real product data** instead of a `not_found` stub. The single-lookup example uses the slug form (`?slug={real-handle}`); the batch-lookup example uses real ids (`?ids={real-id-1},{real-id-2}`).
- The generator currently summarizes at the *category* level (`get_catalog_summary()`); individual products are not already in hand (`get_featured_products()` was removed). So Change 2 needs a **lightweight lookup of one or two currently-syndicated products** (reusing the plugin's existing syndicated-product query helpers + cache) to source real handle/ids. The example must reflect currently-syndicated products so it stays valid as the catalog changes; if the catalog is empty, fall back to the placeholder form.

Verified behavior: `GET …/catalog/lookup?slug=half-zip-hoodie` → `prod_30`, $59.97; `?ids=prod_1,prod_2` → `not_found`.

### Change 3 — Add the parameterless feed to `## Read-only browsing`

In the `## Read-only browsing` section, add bulk **`/products.json`** (and the parameterless per-product `/products/{handle}.json`) framed as the **simple, no-params, no-POST** path, with the UCP catalog endpoints kept as the preferred *structured* option above it.

- This reverses, for the read-only section only, the prior "steer to structured / omit bulk `/products.json`" decision — justified by the new evidence that weak fetchers cannot use parameterized/POST UCP queries but can fetch one parameterless URL and get the whole catalog (exactly what ChatGPT did successfully).
- The `.json` bullets remain gated on `products_json_enabled` (existing behavior); the bulk feed line renders only when that toggle is on.
- The `## Typical agent flow` and transactional sections are unchanged — they still steer transacting agents to UCP `continue_url` checkout.

## Testing

`tests/php/unit/LlmsTxtTest.php`:
- **Invert** the existing assertion that bulk `/products.json` is *absent* from llms.txt → now assert it is present in `## Read-only browsing` (gated on `products_json_enabled`).
- **Update** content assertions that reference the `prod_1,prod_2` placeholder → assert a real lookup example (slug form) is emitted and that the `prod_1,prod_2` literal is gone.
- **Add** a presence test for the `wp_footer` discovery anchor (rendered only when `enabled`), and absence when disabled.
- Section-order test (`test_output_emits_sections_in_documented_order`) is unaffected (no new H2 sections).

## Verification

1. `composer test` green; `vendor/bin/phpcs` and `vendor/bin/phpstan analyse` clean (`phpcbf` before push).
2. Local wp-env: `curl /llms.txt` shows the real lookup example and `/products.json` in Read-only browsing; homepage HTML body contains the `<a href=".../llms.txt">` anchor; `/agents.md` stays byte-identical to llms.txt.
3. Live saltwarp after deploy: cold-agent bootstrap — fetch homepage → see the llms.txt anchor → fetch llms.txt → reach `/products.json` and the UCP endpoints.

## Risks / notes

- **Edge cache.** llms.txt and products.json are `max-age=300` at the Atomic edge; content changes take up to 5 min to propagate (a release bumps the rewrite/version path). Acceptable.
- **`/agents.md` parity.** llms.txt and agents.md are byte-identical mirrors — both inherit Changes 2–3 automatically; confirm parity in tests.
- **Snap-to-seen residue.** Change 2 makes the *documented example* return real data, but a snap-to-seen agent wanting a *different* product still lands on the example's product. The substantive weak-fetcher path is Change 3 (bulk `/products.json`), which Change 2 complements rather than fully solves. This is a documented agent-tool limitation, not ours to fix.
