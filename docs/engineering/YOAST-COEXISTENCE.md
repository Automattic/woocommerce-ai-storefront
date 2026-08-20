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
| **Meta title / description** | Blue headline + gray summary in search results | crawlers + humans | Owns | Self-emits on commerce pages (product title enriched with brand, except where the brand would be redundant; description derived from core fields) | 🟡 Title: single tag either way, but which plugin ends up owning it is unresolved and untested (#669). Description: transient duplicate until the SEO plugin is deactivated |
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

- **`<title>`** — there is only one title tag, so there is never duplication; the open question is who fills it. This plugin claims `pre_get_document_title` at priority 11 to print a merchant's authored headline, and hooks `document_title_parts` late to append the brand. Against an SEO plugin that also claims `pre_get_document_title`, the outcome is unresolved and untested — see the Yoast paragraph below and issue #669. On single products it appends the brand (`{name} | {brand}`), but suppresses that append when it would be redundant — case-insensitively, when the brand equals the store name (core already appends the site segment) or the product name already contains the brand. So an in-house-label store (`Camp Shirt` on the `Saltwarp` brand of the `Saltwarp` store) reads `Camp Shirt – Saltwarp`, not `Camp Shirt | Saltwarp – Saltwarp`. The brand still appears when it adds information (`Field Boot | Thornwick – Saltwarp`).
- **Meta description, Open Graph, Twitter, robots** — these are additive `<head>` tags. Until the SEO plugin is deactivated, the page carries two of each. Search engines tolerate this (they pick one); validators flag it. The duplication is your cue to act — and the admin notice tells you so. This plugin does **not** reach into the other plugin to suppress its output.

## Divergence: Jetpack's description and authored title are both ours; Yoast is unresolved

As of the authored-intent-wins fix, this plugin treats Jetpack SEO Tools the opposite way it treats Yoast: it reads the merchant's authored Jetpack fields and prints them itself, rather than emitting alongside and leaving the merchant to deactivate.

- **Description** — on product, category and shop pages, this plugin always suppresses Jetpack's own `<meta name="description">` and always prints its own tag: the merchant's `advanced_seo_description` when one is authored, otherwise a generated fallback. On those pages there is exactly one description tag, and it is always this plugin's. Product-search results are the deliberate exception described above: the suppression still applies, but this plugin emits only the robots tag there, so the page carries no description tag at all.
- **Title** — this plugin prints the merchant's authored `jetpack_seo_html_title` itself, on single products as well as on the Shop page, via `pre_get_document_title` at priority 11. It does not defer to Jetpack: Jetpack's own `pre_get_document_title` callback short-circuits `wp_get_document_title()`, so standing down on `document_title_parts` achieved nothing when Jetpack did apply the title, and lost both the authored title and the brand suffix when it didn't (a conflicted theme, or `jetpack_seo_custom_titles` filtered false). With nothing authored, the incoming title is returned untouched and core's assembled title stands. Brand enrichment still happens on `document_title_parts`, and stands down on an authored product title. The Shop page is the case Jetpack can never reach on its own: WooCommerce renders the product archive at that URL, so the Shop page's post is not what the query resolves.

Category pages are out of scope for this fix — Jetpack's SEO fields are post meta, and a product category is a term, so there is no authored field to honour there.

Yoast is unchanged, and the title contest with it is **unresolved and untested**. The "late filter priority wins" claim in the Coexistence behavior section above applies only to `document_title_parts`, and `wp_get_document_title()` applies `pre_get_document_title` first and returns on any non-empty value — the same short-circuit this plugin now relies on for authored titles. A `document_title_parts` callback at priority 99 therefore never runs when an SEO plugin claims the earlier filter, whatever its priority. Which plugin ends up owning the `<title>` alongside Yoast, Rank Math, SEOPress or AIOSEO has not been established; the meta description remains an additive duplicate until the SEO plugin is deactivated. Extending authored-intent-wins to those plugins needs its own detector per plugin, and both questions are tracked as issue #669.

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
