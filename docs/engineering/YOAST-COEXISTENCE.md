# Coexistence with Yoast / RankMath / AIOSEO

This plugin and a traditional SEO plugin (Yoast WooCommerce SEO, Rank Math, All in One SEO) overlap on exactly one plane — **structured data and human-SERP `<head>` metadata** — and are otherwise complementary. This document explains where they overlap, why, and what to check before deactivating your SEO plugin so this plugin can take over the commerce-page SERP/social surface.

## Two audiences, one surface

Structured data has **two** consumers, and it is easy to conflate them:

1. **Traditional search crawlers** (Googlebot, Bingbot) — read JSON-LD to build *rich results*: product price/availability snippets, review stars, variant rich results, merchant listings.
2. **AI shopping agents** (ChatGPT, Gemini, Claude, Perplexity) — read JSON-LD, `llms.txt`, and the UCP API for product discovery and agentic checkout.

This plugin's **structured data** serves *both*. Its **SERP/social metadata layer** (title, meta description, Open Graph/Twitter) serves the **human-SERP / social** audience specifically — the headline, snippet, and share-preview a person sees.

These layers are **complementary, not substitutes**:

- JSON-LD adds the **enhancement row** in a search result (★ rating, price, availability).
- The `<title>` and meta description supply the **headline and snippet**.
- Neither replaces the other. JSON-LD does not generate your title or snippet; the meta tags do not generate your rich-result stars. (Google's documentation lists structured data as the source of rich-result *enhancements*, and the `<title>` element / meta description as the sources of the title link and snippet.)

## Governing principle

**WooCommerce / WordPress core is the single source of truth.** This plugin reads core fields and never an SEO plugin's parallel copies:

- GTIN → core Global Unique ID field (WC 9.4+). Brand → core Brand taxonomy (WC 9.5+).
- Any historical reads of an SEO plugin's stored options (e.g. social-profile URLs) are *legacy fallbacks*, not preferred sources, and read from the database (which survives the plugin being deactivated) — never a runtime dependency on the SEO plugin being active.

Yoast-stored data is **migration territory**, considered later — never a runtime dependency.

## Where they overlap

| Surface | What it is (plain terms) | Audience | SEO plugin | This plugin | Overlap / conflict |
|---|---|---|---|---|---|
| **Product schema (JSON-LD)** | Machine-readable product facts powering rich snippets *and* agent recommendations | crawlers + agents | Authors its own `Product` in an `@graph` | Enhances WooCommerce core's node → `ProductGroup` for variable products | 🔴 **Genuine contention** — two competing Product nodes for the same rich result |
| **Reviews / aggregateRating** | Star rating + review text feeding review snippets | crawlers + agents | Authors it | Passes through WooCommerce core's (never authored here) | 🔴 Duplicated only because the Product node is |
| **WebSite + SearchAction** | Site name + sitelinks search box | crawlers + agents | In its `@graph` | Emits its own node | 🟡 Both emit; independent |
| **Store / Organization + `sameAs`** | Business identity + social profile links | crawlers + agents | In its `@graph` | Emits its own node; may read the SEO plugin's stored social handles as a fallback | 🟢 Plugin borrows values, no conflict |
| **Meta title / description** | Blue headline + gray summary in search results | crawlers + humans | Owns | Self-emits on commerce pages (product title enriched with brand, except where the brand would be redundant; description derived from core fields) | 🟡 Title: this plugin wins via late filter priority (single tag, no dup). Description: transient duplicate until the SEO plugin is deactivated |
| **Open Graph / Twitter cards** | Image + title preview when a link is shared | humans | Owns | Self-emits on commerce pages | 🟡 Transient duplicate until the SEO plugin is deactivated |
| **Canonical (`rel=canonical`)** | "This is the master URL" — dedupes `?utm=`/sort variants | crawlers | Owns | Emits no tag (uses canonical permalinks only as *data* in JSON-LD/checkout URLs) | 🟢 No overlap — different senses of "canonical" |
| **robots-meta (indexing)** | "Should *this page* appear in search?" | crawlers | Owns (per-page UI) | Opinionated only: noindex for `catalog_visibility=hidden` products + internal shop search | 🟢 Minimal, complementary |
| **robots.txt (crawler access)** | Site-wide "which *bots* may fetch what" | crawlers + agents | Adds `Sitemap:` lines | Owns the AI-crawler welcome list | 🟢 Distinct surface |
| **XML sitemaps** | Machine list of every URL for crawlers | crawlers | Owns | Never emits; defers to any provider (WP core / Jetpack / Yoast / Rank Math / AIOSEO) and lists it in `llms.txt` | 🟢 No overlap (deliberate) |
| **UCP/MCP, llms.txt, products feed, BuyAction, inventory, subscriptions, attribution** | Agentic-commerce APIs and signals | agents | None | Owns | 🟢 Plugin-exclusive |

The "transient duplicate" caveat on the **Meta title / description** and **Open Graph / Twitter** rows applies to product, category, and shop pages. On product-search results (`post_type=product`) this plugin emits only the robots `noindex` tag — no meta description and no Open Graph/Twitter cards — so there is no duplication to resolve there regardless of whether the SEO plugin is active.

The single 🔴 is the real overlap: with both plugins active, two `Product` nodes compete for the same Google rich result. The migration nudge surfaces this and invites you to deactivate the SEO plugin — which resolves both the JSON-LD duplication and the head-metadata duplication at once.

## Coexistence behavior (assert + warn)

While both plugins are active, this plugin **always emits** on commerce pages — it never silently defers:

- **`<title>`** — this plugin hooks `document_title_parts` at a late priority, so it wins. There is only one title tag, so there is no duplication. On single products it appends the brand (`{name} | {brand}`), but suppresses that append when it would be redundant — case-insensitively, when the brand equals the store name (core already appends the site segment) or the product name already contains the brand. So an in-house-label store (`Camp Shirt` on the `Saltwarp` brand of the `Saltwarp` store) reads `Camp Shirt – Saltwarp`, not `Camp Shirt | Saltwarp – Saltwarp`. The brand still appears when it adds information (`Field Boot | Thornwick – Saltwarp`).
- **Meta description, Open Graph, Twitter, robots** — these are additive `<head>` tags. Until the SEO plugin is deactivated, the page carries two of each. Search engines tolerate this (they pick one); validators flag it. The duplication is your cue to act — and the admin notice tells you so. This plugin does **not** reach into the other plugin to suppress its output.

## Divergence: Jetpack's description is always ours, its title sometimes isn't; Yoast still overrides

As of the authored-intent-wins fix, this plugin treats Jetpack SEO Tools the opposite way it treats Yoast, and treats Jetpack's own title and description differently from each other:

- **Description** — on product and shop pages, this plugin always suppresses Jetpack's own `<meta name="description">` and always prints its own tag: the merchant's `advanced_seo_description` when one is authored, otherwise a generated fallback. There is exactly one description tag in every case, and it is always this plugin's.
- **Title** — on a single product, an authored `jetpack_seo_html_title` still wins by letting Jetpack render its own title tag; this plugin's role there is only appending the brand, which it skips when the title is authored. On the Shop page, this plugin resolves and prints the Shop page's own title itself — authored or not — because WooCommerce renders the product archive at that URL and Jetpack never reaches the Shop page's post there.

Category pages are out of scope for this fix — Jetpack's SEO fields are post meta, and a product category is a term, so there is no authored field to honour there. Yoast is unchanged — this plugin still wins the title via late filter priority and still emits an additive, duplicate meta description until Yoast is deactivated. That inconsistency is deliberate for now, not resolved: extending authored-intent-wins to Yoast, Rank Math, SEOPress, and AIOSEO needs its own detector per plugin, and is tracked as issue #669.

## Pre-flight checklist — before deactivating your SEO plugin

Deactivating an SEO plugin removes more than the overlapping tags. Check these first:

- **Breadcrumbs** — the breadcrumb *schema* (`BreadcrumbList` JSON-LD) is already emitted by WooCommerce core, so the SERP breadcrumb is safe. The risk is the *visible on-page trail*: if your theme hard-codes the SEO plugin's breadcrumb function (e.g. `yoast_breadcrumb()`), it will vanish — or fatal, if called unguarded. Switch that template call to `woocommerce_breadcrumb()`.
- **Redirects** — any 301/410 redirects you configured in the SEO plugin's redirect manager will stop working. Keep a dedicated redirect plugin (or export your redirects into one) before deactivating. (WordPress core's `wp_old_slug_redirect()` already handles same-item slug-change 301s natively.)
- **Custom noindex rules** — pages you manually noindexed in the SEO plugin will become indexable again (beyond this plugin's opinionated defaults of hidden products + internal search).
- **Sitemap** — the SEO plugin's `/sitemap_index.xml` will return 404; WordPress core serves `/wp-sitemap.xml`. Resubmit it in Google Search Console.

## Scope: what this plugin will and won't absorb

The litmus test for what belongs in this plugin is: **does it serve product discovery?**

**In scope** (serves discovery): product/category/shop metadata, structured data, opinionated noindex, the agentic-commerce surfaces.

**Deferred** (would serve discovery, not yet built): non-commerce page metadata (home, blog, archives, search), Article/author schema, a per-page manual noindex UI, per-product editable SEO fields.

**Never in scope** (fails the test):

- **Redirect manager** — a general-WordPress concern, unrelated to product discovery; use a dedicated plugin.
- **Visible breadcrumb-trail rendering** — a theme-integration concern (the schema is already covered by WooCommerce core).
