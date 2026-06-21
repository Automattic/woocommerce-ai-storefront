# SERP/Social Metadata + Opinionated Noindex — Design (v1)

- **Date:** 2026-06-20
- **Status:** Draft — pending review
- **Topic:** Self-emitted human-SERP `<head>` metadata for commerce pages, so a lean store can replace Yoast/RankMath/AIOSEO.

## Context & problem

The plugin already owns the **structured-data + agentic-commerce** surface (Product/ProductGroup JSON-LD, UCP/MCP, llms.txt, products.json). It emits **zero** human-SERP `<head>` chrome — no `<title>` control, no `<meta name="description">`, no Open Graph/Twitter cards, no `robots` meta. That whole plane is left to an SEO plugin (Yoast, RankMath, AIOSEO) or WordPress core.

Two facts make this a real gap:

1. **WooCommerce/WordPress core emits no meta description at all** — Google falls back to scraping arbitrary page text. Core's `<title>` is a fixed `Name – Site`.
2. The plugin's JSON-LD and an SEO plugin's `@graph` already **both** emit a `Product` node, so on a store running both there is duplicate structured data competing for the same Google rich result.

### Directional decision

The merchant-facing goal is **replace, not coexist-subservient**: installing this plugin should let a lean commerce store **deactivate its SEO plugin** and have this plugin cover the SERP/social surface. Deference is a temporary anti-collision measure during migration, not the end state.

## Governing principle

**WooCommerce/WordPress core is the single source of truth.** The plugin reads core fields and never an SEO plugin's parallel copies. Examples:

- GTIN → core Global Unique ID field (WC 9.4+). Brand → core Brand taxonomy (WC 9.5+).
- Existing opportunistic reads of `wpseo_social` (sameAs) and `_yoast_wpseo_primary_product_cat` (primary category) are **legacy fallbacks, not preferred sources** — they exist only because core lacked an equivalent, and they migrate out as core grows native homes. They are read from the DB (which survives plugin deactivation), so they are not a runtime dependency on Yoast being active.

Yoast-stored data is **migration territory**, considered later — never a runtime dependency.

## Goals (v1)

- Self-emit, fully auto-derived from core data, on commerce pages:
  - `<title>` (branded template)
  - `<meta name="description">`
  - Open Graph + Twitter Card tags
  - Opinionated `robots` noindex for the common hide cases
- **Zero merchant configuration** — no settings screen, no per-product meta box, no overrides UI. Defaults must be good enough.
- Detect an overlapping SEO plugin and surface a dismissible **migration nudge** (with a pre-flight checklist) inviting the merchant to deactivate it.
- Developer **filters** as the only override surface.

## Non-goals (explicitly deferred)

- Per-product editable SEO fields / templates with variables / live preview.
- Metadata for non-commerce pages (home, blog posts, author/date archives, search).
- Article / author / breadcrumb schema for non-product content.
- Visible breadcrumb-trail output (the `yoast_breadcrumb()` theme-dependency replacement). The breadcrumb *schema* is already covered by WooCommerce core; only the visible trail is a theme-integration concern, not a plugin feature.
- A per-page manual `noindex` UI (the long-tail beyond the opinionated defaults below).
- Programmatically suppressing another plugin's output (we assert + warn; we do not reach into Yoast).
- **Redirect manager — out of scope by principle.** A redirect manager is a general-WordPress concern with nothing to do with product discovery; it is well-served by dedicated plugins and will never be part of this plugin. (WP core's `wp_old_slug_redirect()` already handles same-item slug-change 301s natively.)

## Audience framing

Structured data serves **two** audiences (Googlebot rich results **and** AI agents). This metadata layer serves the **human-SERP / social** audience specifically — the headline, snippet, and share-preview a person sees. It is complementary to, not a replacement for, the JSON-LD layer: JSON-LD adds the stars/price enhancement *row*; title+description supply the *headline and snippet*. Neither substitutes for the other.

## Architecture — three components on one shared seam

### 1. `WC_AI_Storefront_Seo_Plugin_Detector` (shared helper)

Single source of truth for "is another SEO plugin present." Presence-based checks:

- Yoast WooCommerce SEO addon → `class_exists( 'Yoast_WooCommerce_SEO' )` (the addon, not free Yoast core, is what emits the full WC Product node + meta).
- RankMath → `defined( 'RANK_MATH_VERSION' )`.
- AIOSEO → `defined( 'AIOSEO_VERSION' )`.

Returns a list of descriptors (`slug`, `label`). Consumed **only** by the migration nudge. It does **not** gate metadata emission.

Rationale for presence-based (vs. reading each plugin's own "emit schema" toggle): avoids coupling to version-fragile option keys — the same maintenance trap the sitemap code deliberately avoided. False positives are cheap (a dismissible notice, no output change).

### 2. `WC_AI_Storefront_Meta_Tags` (front-end emitter — the core value)

`init()` hooks:

- `document_title_parts` (filter core's title — composes with the theme; we run at a **late priority so we win** over an active SEO plugin, guaranteeing a single title with no duplication).
- `wp_head` @ priority ~5 → description + OG + Twitter + robots.

`should_emit(): bool` — true when **both**:

1. Our syndication is enabled (`enabled === 'yes'`).
2. Commerce context: `is_product()` || `is_product_category()` || `is_shop()`.

Note: `should_emit()` does **not** check for SEO-plugin presence. Per the assert-and-warn decision, we always emit on commerce pages; detection only drives the nudge.

Pure builders (unit-tested):

- **Title parts** → `Product Name | Brand | Store` (Brand dropped when absent; category/shop use term/store name + store name).
- **Description** → fallback chain: short description → excerpt → long content; `strip_shortcodes()` + `wp_strip_all_tags()`; trimmed to ~155 chars on a word boundary. **Omitted (not emitted empty)** when all three are blank.
- **Open Graph** → `og:type=product`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:image` (featured image; omitted if none); plus `product:price:amount` / `product:price:currency` when the product is purchasable.
- **Twitter** → `summary_large_image` + `twitter:title` / `:description` / `:image`.
- **Opinionated robots noindex** (commerce pages):
  - `catalog_visibility === 'hidden'` → `<meta name="robots" content="noindex,follow">`. A product the merchant hid in WC is reachable by URL and otherwise indexable; deriving noindex from existing core intent matches expectation, zero-config.
  - Internal shop search results (`is_search()` in a shop context) → `noindex,follow` (thin/duplicate content Yoast noindexes by default).

### 3. Migration nudge (dismissible admin notice)

Shown when: our syndication enabled **AND** detector returns ≥1 plugin **AND** user has `manage_woocommerce` **AND** not dismissed by this user. Scoped to our settings screen + WooCommerce admin context (not site-wide).

Message: *"AI Storefront now provides your product titles, descriptions, social cards, and structured data. You can deactivate [Plugin] — see the checklist before you do."* Links to a **pre-flight checklist** (below). Dismissal stored in user meta (per-user permanent). Tiny enqueued inline script POSTs to `wp_ajax_wc_ai_storefront_dismiss_schema_notice` — no build step, no dependency.

The nudge covers **both** overlaps at once: the head-metadata duplication *and* the duplicate Product JSON-LD node — disabling the SEO plugin resolves both.

## Coexistence philosophy (assert + warn)

While both this plugin and an SEO plugin are active:

- **Title:** we win via late filter priority → one title, no dup.
- **Description / OG / Twitter / robots:** additive echoes — until the merchant deactivates the SEO plugin, the page carries **two** of each. Google tolerates it (picks one); validators flag it. This transient duplication is the merchant's cue to act, surfaced by the nudge. We do **not** suppress the other plugin's output.

## Pre-flight checklist (shipped doc the nudge links to)

Before deactivating an SEO plugin, the merchant should verify:

- **Breadcrumbs** — the breadcrumb *schema* (`BreadcrumbList` JSON-LD) is already emitted by WooCommerce core, so the SERP breadcrumb is safe. The risk is the *visible on-page trail*: if the theme hard-codes `yoast_breadcrumb()`, it will vanish (or fatal, if unguarded). Switch that template call to `woocommerce_breadcrumb()`.
- **Redirects** — any 301/410 redirects configured in the SEO plugin's redirect manager will stop working. Keep a dedicated redirect plugin (or export them into one) before deactivating.
- **Custom noindex rules** — pages manually noindexed in the SEO plugin will become indexable (beyond our opinionated defaults).
- **Sitemap** — the SEO plugin's `/sitemap_index.xml` will 404; WP core serves `/wp-sitemap.xml`. Resubmit in Google Search Console.

## Developer filters (only override surface)

- `wc_ai_storefront_meta_title_parts` (array)
- `wc_ai_storefront_meta_description` (string)
- `wc_ai_storefront_og_tags` (array)
- `wc_ai_storefront_robots_noindex` (bool, per request)
- `wc_ai_storefront_emit_meta_tags` (master bool — disable the whole layer)

## Testing strategy

- **Pure builders:** description fallback chain + truncation + HTML/shortcode stripping; title parts assembly (with/without brand); OG/Twitter shape; noindex decision (`hidden`, search, normal).
- **Gating truth table** for `should_emit()` (enabled × commerce-context).
- **Nudge gating truth table** (enabled × detector-result × dismissed × capability).
- `detect()` tested for the "no SEO plugin present → `[]`" path (the realistic CI-env case).
- Render + AJAX dismiss are thin glue, lightly covered.

## i18n

- Nudge + checklist strings translatable under `woocommerce-ai-storefront`; the tags themselves are data, not UI.
- New `__()` calls require `./bin/make-pot.sh` regen per the i18n freshness gate.

## Edge cases

- Variable products → derive from the parent; OG price omitted when not purchasable.
- Non-purchasable/draft → still get a descriptive description (descriptive entity), no OG price.
- Missing featured image → omit `og:image` rather than emit a broken/placeholder URL.
- Theme already filtering the title → `document_title_parts` composes rather than replacing wholesale.

## Deferred roadmap (dependency-ordered, toward full "replace Yoast")

1. Non-commerce page metadata (home, blog posts, archives, search).
2. Article / author schema for blog content.
3. Per-page manual `noindex` (needs UI — tension with hands-off).
4. Migration path for SEO-plugin-stored data, if/when core still lacks a home for it.

**Never in scope** (fails the "does it serve product discovery?" test):

- Redirect manager — a general-WordPress concern; use a dedicated plugin.
- Visible breadcrumb-trail rendering — a theme-integration concern (schema is already covered by core).

## Process

- Code change → requires a tracking **issue** + a PR referencing it. No direct push to `main`; no self-merge.
- The `YOAST-COEXISTENCE.md` engineering doc (comparison table + two-audience framing + pre-flight checklist) is the nudge's link target and ships with this work.

## Open questions / risks

- Confirm the exact admin-bootstrap hook-up point for the emitter + notice during implementation.
- `is_shop()` title/description derive from store name + (optional) shop-page content; confirm the store-description source.
- Transient-duplicate description tags during migration are accepted by decision; revisit only if it proves to cause real ranking harm (it shouldn't).
