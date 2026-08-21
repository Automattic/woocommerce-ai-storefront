# Coexistence with Yoast / Rank Math / SEOPress / AIOSEO

This plugin and a traditional SEO plugin (Yoast WooCommerce SEO, Rank Math, SEOPress, All in One SEO) overlap on exactly one plane — **structured data and human-SERP `<head>` metadata** — and are otherwise complementary. This document explains where they overlap, why, and what to check before deactivating your SEO plugin so this plugin can take over the commerce-page SERP/social surface.

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
| **Meta description** | Gray summary line in search results | crawlers + humans | Owns | Stands down: emits nothing when the SEO plugin's own description filter carried a value, otherwise emits its own (derived from core fields) | 🟢 **Resolved** (#669) — exactly one tag on product, category and shop pages, whichever plugin supplies it. Nothing needs deactivating |
| **Meta title** | Blue headline in search results | crawlers + humans | Owns | Self-emits (product title enriched with brand, except where the brand would be redundant), but loses the contest | 🟡 Single tag either way; the SEO plugin owns it. Measured, not assumed — see [Who owns the `<title>`](#who-owns-the-title) |
| **Open Graph / Twitter cards** | Image + title preview when a link is shared | humans | Owns | Self-emits on commerce pages | 🟡 Still duplicated while both are active. The description stand-down deliberately does **not** extend here (#676) |
| **Canonical (`rel=canonical`)** | "This is the master URL" — dedupes `?utm=`/sort variants | crawlers | Owns | Emits no tag (uses canonical permalinks only as *data* in JSON-LD/checkout URLs) | 🟢 No overlap — different senses of "canonical" |
| **robots-meta (indexing)** | "Should *this page* appear in search?" | crawlers | Owns (per-page UI) | Opinionated only: noindex for `catalog_visibility=hidden` products + internal shop search | 🟢 Minimal, complementary |
| **robots.txt (crawler access)** | Site-wide "which *bots* may fetch what" | crawlers + agents | Adds `Sitemap:` lines | Owns the AI-crawler welcome list | 🟢 Distinct surface |
| **XML sitemaps** | Machine list of every URL for crawlers | crawlers | Owns | Never emits; defers to any provider (WP core / Jetpack / Yoast / Rank Math / AIOSEO) and lists it in `llms.txt` | 🟢 No overlap (deliberate) |
| **UCP/MCP, llms.txt, products feed, BuyAction, inventory, subscriptions, attribution** | Agentic-commerce APIs and signals | agents | None | Owns | 🟢 Plugin-exclusive |

The duplicate on the **Open Graph / Twitter** row applies to product, category, and shop pages. On product-search results (`post_type=product`) this plugin emits only the robots `noindex` tag — no meta description and no Open Graph/Twitter cards — so there is nothing to duplicate there regardless of whether the SEO plugin is active.

The single 🔴 is the real overlap: with both plugins active, two `Product` nodes compete for the same Google rich result. The migration nudge surfaces this and invites you to deactivate the SEO plugin — which resolves the JSON-LD duplication and the remaining Open Graph/Twitter duplication. The meta description no longer needs it.

## Coexistence behavior

While both plugins are active, this plugin emits its own `<head>` tags on commerce pages with one deliberate exception: the meta description, where it stands down.

### The meta description stands down

Resolved in #669, and the resolution is not uninstalling anything. Before it renders, this plugin reads the value the other plugin's own description filter carried during this request — `wpseo_metadesc` (Yoast), `rank_math/frontend/description`, `seopress_titles_desc`, or `aioseo_description` — and skips its own tag when that value was non-empty. The result on product, category and shop pages is **exactly one** `<meta name="description">`, whichever plugin supplies it. This plugin still never reaches into the other plugin to suppress its output; it only declines to add a second tag.

Two constraints govern the observation, both measured against real installs rather than reasoned from documentation (`WC_AI_Storefront_Rival_Seo_Description`):

- **Hooked at `PHP_INT_MAX`, not a normal priority.** With the paid Yoast WooCommerce SEO addon active, the same request gives `wpseo_metadesc` as an empty string at priority 5 — where this plugin renders — and the full 27-character product description at `PHP_INT_MAX`. The addon supplies the value above priority 5. An observer at a default priority reads "empty", never stands down, and leaves the duplicate in place for exactly the configuration the feature most needs to handle.
- **First non-empty value wins, never the last.** SEOPress fires `seopress_titles_desc` 6 to 12 times in one request; All in One SEO fires `aioseo_description` twice with the second call always empty. Keeping the last value seen would read that trailing empty and conclude nothing was emitted.

An empty firing is a reliable "no tag will be emitted" signal, which is what makes the inverse safe: SEOPress fires the filter three or four times with an empty value on category and shop-archive pages and emits no description there, so this plugin correctly keeps emitting its own. **A page never ends up with zero description tags** because this plugin stood down for a tag the other plugin was not going to print.

### Who owns the `<title>`

There is only one title tag, so there is never duplication; the question is who fills it, and the answer is measured: **the SEO plugin does, almost everywhere.**

`wp_get_document_title()` applies the whole `pre_get_document_title` chain and returns early on any non-empty result, so the **last** callback to return a non-empty value wins. Registration priorities on that filter:

| plugin | priority on `pre_get_document_title` |
|---|---|
| **this plugin** | **11** |
| Yoast SEO | 15 |
| Rank Math | 15 |
| SEOPress | 20 |
| All in One SEO | 99999 |

All four register above 11, run after this plugin, and overwrite its value. This plugin's `document_title_parts` callback at priority 99 never runs at all, because the short-circuit returns before core assembles the title from its parts — so the brand suffix is lost alongside the title. Deactivating the SEO plugin restores both.

**One measured exception:** SEOPress does not take the title on the shop archive, shop page 2, or shop-as-front-page. It does not treat the WooCommerce product archive as the Shop page, so an authored SEOPress title there is ignored and this plugin keeps the title. This is **documented, not fixed** — making the title consistent under SEOPress would mean losing it in one more place, not gaining it anywhere.

The tell in a live `<head>` is the separator: `&#8211;` (en dash) is WordPress core's, and so this plugin's; a plain `-` means the SEO plugin supplied the title.

This plugin still claims `pre_get_document_title` at priority 11, which is what lets it print a merchant's authored headline when no SEO plugin outranks it (see the Jetpack section below). On single products it appends the brand (`{name} | {brand}`), but suppresses that append when it would be redundant — case-insensitively, when the brand equals the store name (core already appends the site segment) or the product name already contains the brand. So an in-house-label store (`Camp Shirt` on the `Saltwarp` brand of the `Saltwarp` store) reads `Camp Shirt – Saltwarp`, not `Camp Shirt | Saltwarp – Saltwarp`. The brand still appears when it adds information (`Field Boot | Thornwick – Saltwarp`).

### Open Graph, Twitter and robots are still additive

These remain duplicated while both plugins are active: the page carries two of each. Search engines tolerate it (they pick one); validators flag it. The duplication is your cue to act, and the admin notice says so.

The description stand-down deliberately does **not** extend to Open Graph, because the signal does not carry that far. The filters above predict only the other plugin's `<meta name="description">`, not its Open Graph output — free Yoast with nothing authored fires `wpseo_metadesc` empty, correctly predicting no description tag, and emits an `og:description` anyway.

That produces a visible asymmetry worth knowing before you read a `<head>`: on a Yoast store, a product page now emits **one** `<meta name="description">` and **two** `og:description` tags. Nothing is broken; Open Graph needs its own observation, tracked as issue **#676**.

## Divergence: Jetpack's fields are reprinted; the other SEO plugins are stood down for

This plugin treats Jetpack SEO Tools differently from the other SEO plugins: it reads the merchant's authored Jetpack fields and prints them itself, rather than declining to emit.

- **Description** — on product, category and shop pages, this plugin always suppresses Jetpack's own `<meta name="description">` and always prints its own tag: the merchant's `advanced_seo_description` when one is authored, otherwise a generated fallback. On those pages there is exactly one description tag, and it is always this plugin's. Product-search results are the deliberate exception described above: the suppression still applies, but this plugin emits only the robots tag there, so the page carries no description tag at all.
- **Title** — this plugin prints the merchant's authored `jetpack_seo_html_title` itself, on single products as well as on the Shop page, via `pre_get_document_title` at priority 11. It does not defer to Jetpack: Jetpack's own `pre_get_document_title` callback short-circuits `wp_get_document_title()`, so standing down on `document_title_parts` achieved nothing when Jetpack did apply the title, and lost both the authored title and the brand suffix when it didn't (a conflicted theme, or `jetpack_seo_custom_titles` filtered false). With nothing authored, the incoming title is returned untouched and core's assembled title stands. Brand enrichment still happens on `document_title_parts`, and stands down on an authored product title. The Shop page is the case Jetpack can never reach on its own: WooCommerce renders the product archive at that URL, so the Shop page's post is not what the query resolves.

Category pages are out of scope for this fix — Jetpack's SEO fields are post meta, and a product category is a term, so there is no authored field to honour there.

Yoast, Rank Math, SEOPress and All in One SEO are handled differently again, and both halves of that are now settled rather than open:

- **Description** — this plugin stands down for them, as described in [The meta description stands down](#the-meta-description-stands-down). It does not read their authored fields and reprint them the way it does Jetpack's; it declines to emit a second tag. The outcome is the same one tag per page, reached the other way round.
- **Title** — this plugin loses, everywhere except SEOPress on the shop archive family. See [Who owns the `<title>`](#who-owns-the-title) for the measured priorities. Extending authored-intent-wins to these four would mean reading each plugin's own stored title fields — a detector per plugin — and is not built.

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
