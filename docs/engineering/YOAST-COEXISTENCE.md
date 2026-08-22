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
| **Open Graph / Twitter cards** | Image + title preview when a link is shared | humans | Owns | One strategy per plugin: SEOPress's social tags are removed and ours print; Yoast, Rank Math and AIOSEO have theirs corrected and extended in place | 🟢 **Resolved** (#676) — one set of tags per page. See [Open Graph coexistence](#open-graph-coexistence) |
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
- **First non-empty value wins, never the last.** SEOPress fires `seopress_titles_desc` 6 to 12 times per request, the exact count varying by page type and run; All in One SEO fires `aioseo_description` twice with the second call always empty. Keeping the last value seen would read that trailing empty and conclude nothing was emitted.

An empty firing is a reliable "no tag will be emitted" signal, which is what makes the inverse safe: SEOPress fires the filter three or four times with an empty value on the shop archive, and on category pages whose term has no description, and emits no description there, so this plugin correctly keeps emitting its own. A category term that does have a description gets a non-empty firing instead, and this plugin stands down for it there too. Between the two suppressions (Jetpack's tag always removed, these four plugins' tags read and deferred to), **at most one plugin's description tag ever reaches the page** — not quite a guarantee against zero. A rival plugin's own "disable meta description" master switch, applied downstream of the filter this plugin observes, could carry a value through the filter that the rival plugin never actually prints, leaving this plugin stood down for a tag nobody sees.

### Who owns the `<title>`

There is only one title tag, so there is never duplication; the question is who fills it, and the answer is measured: **the SEO plugin does, almost everywhere.**

`wp_get_document_title()` applies the whole `pre_get_document_title` chain and returns early on any non-empty result, so the **last** callback to return a non-empty value wins. Registration priorities on that filter:

| plugin | priority on `pre_get_document_title` | provenance |
|---|---|---|
| **this plugin** | **11** | measured (#669 spike hookmap dump) |
| Yoast SEO | 15 | measured (#669 spike hookmap dump) |
| Rank Math | 15 | read from source, not independently confirmed by the spike |
| SEOPress | 20 (or `214748364` in one config branch) | read from source, not independently confirmed by the spike |
| All in One SEO | 99999 | measured (#669 spike hookmap dump) |

Rank Math's and SEOPress's numbers are carried over from the plugin's own source rather than a runtime hookmap capture. Treat them as lower-confidence than the other three rows: the spike confirmed both plugins win the title in practice (their own title text and separator replace this plugin's, on the page types where they run at all), but did not capture a hookmap dump pinning the exact priority number the way it did for this plugin, Yoast and AIOSEO.

All four register above 11, run after this plugin, and overwrite its value. This plugin's `document_title_parts` callback at priority 99 never runs at all, because the short-circuit returns before core assembles the title from its parts — so the brand suffix is lost alongside the title. Deactivating the SEO plugin restores both.

**One measured exception:** SEOPress does not take the title on the shop archive, shop page 2, or shop-as-front-page. It does not treat the WooCommerce product archive as the Shop page, so an authored SEOPress title there is ignored and this plugin keeps the title. This is **documented, not fixed** — making the title consistent under SEOPress would mean losing it in one more place, not gaining it anywhere.

The tell in a live `<head>` is the separator: `&#8211;` (en dash) is WordPress core's, and so this plugin's; a plain `-` means the SEO plugin supplied the title.

This plugin still claims `pre_get_document_title` at priority 11, which is what lets it print a merchant's authored headline when no SEO plugin outranks it (see the Jetpack section below). On single products it appends the brand (`{name} | {brand}`), but suppresses that append when it would be redundant — case-insensitively, when the brand equals the store name (core already appends the site segment) or the product name already contains the brand. So an in-house-label store (`Camp Shirt` on the `Saltwarp` brand of the `Saltwarp` store) reads `Camp Shirt – Saltwarp`, not `Camp Shirt | Saltwarp – Saltwarp`. The brand still appears when it adds information (`Field Boot | Thornwick – Saltwarp`).

### Open Graph, Twitter and robots are still additive

These remain duplicated while both plugins are active: the page carries two of each. Search engines tolerate it (they pick one); validators flag it. The duplication is your cue to act, and the admin notice says so.

Open Graph used to be on that list. It no longer is, and it was resolved by a different mechanism than the description — see below.

## Open Graph coexistence

The description stand-down works by prediction: the other plugin's own filter tells us what it is about to write. That signal does not reach Open Graph. Free Yoast with nothing authored fires `wpseo_metadesc` empty, correctly predicting no description tag, and emits an `og:description` anyway.

So Open Graph got its own mechanism, one strategy per plugin, because five measured providers behave five different ways.

| Plugin | Strategy | Why |
|---|---|---|
| SEOPress | **Suppress** — remove its 16 social callbacks, print ours | Its per-tag filters fire only for tags it already emits, so there is no seam through which to add a commerce fact it never emits |
| Yoast (free, and with the paid WooCommerce addon) | **Enrich** — correct `og:type`, drop the `article:*` presenters, add the missing facts | Both extension points reach the page, and the paid addon uses the same two itself |
| Rank Math | **Enrich** — substitute through its per-tag filters, add the rest from its action | Closest to correct already; misses price on variable products and `og:availability` everywhere |
| All in One SEO | **Enrich** — one flat tag map through two filters | Emits no Open Graph at all on a product category, where our own block stays |
| Jetpack | **Suppress** — predates this work, lives in `WC_AI_Storefront_Meta_Tags` | One lazy callback, removed between its loader and its emitter |

**Presence is not emission.** Standing our own block down is decided by observing that the other plugin's seam actually ran this request, never by its being installed. Rank Math defines its version constant at load but publishes nothing until its setup wizard is finished; Yoast and AIOSEO both ship an Open Graph switch. In every one of those states the plugin is present and silent, and standing down would leave the page with no social tags at all — worse than the duplication this replaced. Each strategy latches on its own filter running, at `wp_head:1`, and `render_head_tags()` reads that at `wp_head:5`.

**One known residue.** With SEOPress's "Date in SERPs" option on, `seopress_titles_single_cpt_date_hook` emits `article:published_time`, `article:modified_time` and `og:updated_time` on singular pages. It lives in SEOPress's titles file, not its social file, and it is the same callback rendering the SERP date the merchant asked for, so it is deliberately left alone.

## Non-commerce fallback

`WC_AI_Storefront_Meta_Tags` is scoped to commerce pages, and that boundary is what makes everything above work. It assumes a second emitter exists. On a plain WooCommerce install none does, so a shared blog post had no `og:*`, no `twitter:*` and no description at all (#680).

`WC_AI_Storefront_Content_Meta_Tags` fills that, and only that. Singular posts and pages, six `og:*` properties, three `twitter:*`, a description, and an image when the post has one. Not `article:published_time`, `article:modified_time`, `article:author` or `profile:*` — the smallest thing that fixes a blank card is the right size for a fallback, and authorship and timestamps are where a real SEO plugin starts.

**The gate is presence-based, and asymmetric on purpose.** A false negative leaves a post with the blank card it already had; a false positive puts a second set of tags on a page that has one. So any plugin the detector reports means silence.

Jetpack is the exception, because it has two independent emitters:

| Jetpack state | what we do |
|---|---|
| active, Open Graph off, SEO Tools off | emit |
| active, Open Graph on | stay silent — `has_action( 'wp_head', 'jetpack_og_tags' )` |
| active, SEO Tools on | emit the card, but suppress Jetpack's description via `jetpack_seo_meta_tags` |

Treating Jetpack's mere presence as disqualifying would mean this never fires on a WordPress.com store, which is where the bug was found.

**Known limit.** The detector knows five plugins. The SEO Framework, Slim SEO, Squirrly and themes with built-in Open Graph are not among them, so on those stores the gate opens and the page gets duplicate tags. Closing that needs observing what actually reached the page, the way `WC_AI_Storefront_Og_Strategies` does for commerce — and that ground truth was measured on commerce pages, so it has to be re-measured on posts first. Tracked as #690.

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

**Deferred** (would serve discovery, not yet built): blog and archive metadata, Article/author schema, a per-page manual noindex UI, per-product editable SEO fields.

**Shipped since** (#680): social metadata for singular posts and pages, on stores where nothing else provides any. See [Non-commerce fallback](#non-commerce-fallback).

**Never in scope** (fails the test):

- **Redirect manager** — a general-WordPress concern, unrelated to product discovery; use a dedicated plugin.
- **Visible breadcrumb-trail rendering** — a theme-integration concern (the schema is already covered by WooCommerce core).
