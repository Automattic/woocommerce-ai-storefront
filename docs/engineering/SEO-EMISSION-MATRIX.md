# SEO plugin emission matrix

Which Open Graph, Twitter and description attributes each SEO plugin actually puts
on a page, per page type, measured rather than read from documentation.

This exists because every coexistence decision in the plugin rests on it. Whether a
provider is enriched or suppressed, whether a tag is corrected or added, and whether
we stand down at all are all answers to "what does this one actually emit here" — a
question the vendors' own docs do not answer, and which differs per page type in ways
that do not transfer.

## How to read it

`●` emitted, `·` absent. `og:type` shows its value, because the value is the finding.

Every cell is a real HTTP response against a real render on a local store, with one
plugin installed at a time and nothing authored in its own SEO fields. So this is the
**unauthored** case throughout; authoring generally adds tags rather than removing
them.

## Provenance, and the trap in it

Two different capture runs, deliberately:

- **The rival columns** come from the #676 spike
  (`.claude/tmp/artifacts/676/captures/`), 2026-08-21. Still current: those are
  measurements of *their* plugins, which our changes cannot affect.
- **The `Ours` column** was recaptured on 2026-08-23 against `main`.

That split is not tidiness, it is correctness. The #676 run predates #683, #684, #688
and #693, and our own output changed materially in all of them — the shop archive went
from 7 tags to 14, and product search from 1 to 14. Reusing the original `Ours` column
would have published a matrix that was wrong in four of its five tables.

**If you regenerate this file, recapture the `Ours` column.** The rival columns only
need redoing when a vendor ships a change worth re-measuring.

## Versions measured

Yoast SEO free 28.3, Yoast SEO WooCommerce 16.8, All in One SEO Lite 5.0.0.1,
Jetpack 16.1.2, WooCommerce 11.0.1. Rank Math and SEOPress were the current
wordpress.org releases on 2026-08-21; neither stamps a version into the head.

Two conditions the numbers depend on:

- **Rank Math emits nothing at all until its setup wizard has run.** Registration was
  skipped explicitly before capturing. An out-of-the-box install shows an empty column.
- **Rank Math's WooCommerce module was off** on the capture box, because it gates on a
  hard-coded plugin path the local environment does not use. The commerce rows come
  from a run that corrects for it.

### product

`/product/hoodie/` (variable, featured image 801x801)

| Attribute | Ours | Yoast free | Rank Math | AIOSEO | SEOPress |
|---|---|---|---|---|---|
| `og:type` | `product` | `article` | `product` | `article` | `product` |
| `og:title` | ● | ● | ● | ● | ● |
| `og:description` | ● | ● | ● | ● | ● |
| `og:url` | ● | ● | ● | ● | ● |
| `og:site_name` | ● | ● | ● | ● | ● |
| `og:locale` | ● | ● | ● | ● | ● |
| `og:image` | ● | ● | ● | · | ● |
| `og:image:width` | ● | ● | ● | · | ● |
| `og:image:height` | ● | ● | ● | · | ● |
| `og:image:alt` | · | · | ● | · | · |
| `og:image:type` | · | ● | ● | · | · |
| `og:updated_time` | · | · | ● | · | · |
| `og:availability` | ● | · | · | · | · |
| `product:price:amount` | ● | · | · | · | · |
| `product:price:currency` | ● | · | · | · | · |
| `product:availability` | ● | · | ● | · | · |
| `article:published_time` | · | · | · | ● | · |
| `article:modified_time` | · | ● | · | ● | · |
| `article:publisher` | · | · | · | · | ● |
| `article:author` | · | · | · | · | ● |
| `twitter:card` | ● | ● | ● | ● | ● |
| `twitter:title` | ● | · | ● | ● | ● |
| `twitter:description` | ● | · | ● | ● | ● |
| `twitter:image` | ● | · | ● | · | ● |
| `twitter:site` | · | · | · | · | ● |
| `twitter:creator` | · | · | · | · | ● |
| `twitter:label1` | ● | · | ● | · | · |
| `twitter:data1` | ● | · | ● | · | · |
| `twitter:label2` | ● | · | ● | · | · |
| `twitter:data2` | ● | · | ● | · | · |
| `description` | ● | · | ● | ● | ● |
| `robots` | · | ● | ● | ● | ● |

### category

`/product-category/hoodies/`

| Attribute | Ours | Yoast free | Rank Math | AIOSEO | SEOPress |
|---|---|---|---|---|---|
| `og:type` | `website` | `article` | `article` | · | `object` |
| `og:title` | ● | ● | ● | · | ● |
| `og:description` | ● | · | · | · | · |
| `og:url` | ● | ● | ● | · | ● |
| `og:site_name` | ● | ● | ● | · | ● |
| `og:locale` | ● | ● | ● | · | ● |
| `og:image` | ● | · | · | · | · |
| `og:image:width` | ● | · | · | · | · |
| `og:image:height` | ● | · | · | · | · |
| `twitter:card` | ● | ● | ● | · | ● |
| `twitter:title` | ● | · | ● | · | ● |
| `twitter:description` | ● | · | · | · | · |
| `twitter:image` | ● | · | · | · | · |
| `twitter:site` | · | · | · | · | ● |
| `twitter:creator` | · | · | · | · | ● |
| `twitter:label1` | · | · | ● | · | · |
| `twitter:data1` | · | · | ● | · | · |
| `description` | ● | · | · | · | · |
| `robots` | · | ● | ● | ● | ● |

### shop

`/shop/`

| Attribute | Ours | Yoast free | Rank Math | AIOSEO | SEOPress |
|---|---|---|---|---|---|
| `og:type` | `website` | `article` | `article` | `website` | `object` |
| `og:title` | ● | ● | ● | ● | ● |
| `og:description` | ● | · | ● | · | ● |
| `og:url` | ● | ● | ● | ● | ● |
| `og:site_name` | ● | ● | ● | ● | ● |
| `og:locale` | ● | ● | ● | ● | ● |
| `og:image` | ● | · | · | · | ● |
| `og:image:width` | ● | · | · | · | ● |
| `og:image:height` | ● | · | · | · | ● |
| `article:modified_time` | · | ● | · | · | · |
| `twitter:card` | ● | ● | ● | ● | ● |
| `twitter:title` | ● | · | ● | ● | ● |
| `twitter:description` | ● | · | ● | · | ● |
| `twitter:image` | ● | · | · | · | ● |
| `twitter:site` | · | · | · | · | ● |
| `twitter:creator` | · | · | · | · | ● |
| `description` | ● | · | ● | · | · |
| `robots` | · | ● | ● | ● | ● |

### shop-page2

`/shop/page/2/`

| Attribute | Ours | Yoast free | Rank Math | AIOSEO | SEOPress |
|---|---|---|---|---|---|
| `og:type` | `website` | `article` | `article` | `website` | `object` |
| `og:title` | ● | ● | ● | ● | ● |
| `og:description` | ● | · | ● | · | ● |
| `og:url` | ● | ● | ● | ● | ● |
| `og:site_name` | ● | ● | ● | ● | ● |
| `og:locale` | ● | ● | ● | ● | ● |
| `og:image` | ● | · | · | · | ● |
| `og:image:width` | ● | · | · | · | ● |
| `og:image:height` | ● | · | · | · | ● |
| `article:modified_time` | · | ● | · | · | · |
| `twitter:card` | ● | ● | ● | ● | ● |
| `twitter:title` | ● | · | ● | ● | ● |
| `twitter:description` | ● | · | ● | · | ● |
| `twitter:image` | ● | · | · | · | ● |
| `twitter:site` | · | · | · | · | ● |
| `twitter:creator` | · | · | · | · | ● |
| `description` | ● | · | ● | · | · |
| `robots` | · | ● | ● | ● | ● |

### search

`/?s=hoodie&post_type=product`

| Attribute | Ours | Yoast free | Rank Math | AIOSEO | SEOPress |
|---|---|---|---|---|---|
| `og:type` | `website` | `article` | `article` | `website` | `object` |
| `og:title` | ● | ● | ● | ● | ● |
| `og:description` | ● | · | · | · | · |
| `og:url` | ● | ● | ● | · | ● |
| `og:site_name` | ● | ● | ● | ● | ● |
| `og:locale` | ● | ● | ● | ● | ● |
| `og:image` | ● | · | · | · | ● |
| `og:image:width` | ● | · | · | · | ● |
| `og:image:height` | ● | · | · | · | ● |
| `twitter:card` | ● | ● | ● | ● | ● |
| `twitter:title` | ● | ● | ● | ● | ● |
| `twitter:description` | ● | · | · | · | · |
| `twitter:image` | ● | · | · | · | ● |
| `twitter:site` | · | · | · | · | ● |
| `twitter:creator` | · | · | · | · | ● |
| `description` | ● | · | · | · | · |
| `robots` | ● | ● | ● | ● | ● |


## Jetpack

Jetpack's column is absent from the tables above because our own suppression removes
its block at `wp_head:9` on every commerce page type, so nothing of it survives to
measure. What it *would* emit was captured separately by re-registering its emitter
after the removal:

| Page | Would emit | Notable |
|---|---|---|
| product | 15 tags, `og:type=article` | carries `article:published_time` on a product, and finds the real featured image at full size |
| category | 10 tags, `og:type=website` | `og:image` is `s0.wp.com/i/blank.jpg`, a 200x200 placeholder |
| shop | 10 tags, `og:type=website` | `og:url` is the literal string `False` |
| shop, page 2 | 10 tags, `og:type=website` | same `False` |
| search | 10 tags, `og:type=website` | same `False` |

Worth noting against the tables above: on all four archive types Jetpack picks the
same `og:type` we do, and is the only one of the five to get a product category right.

## What the matrix shows

**Only two columns ever carry commerce facts.** Ours, and Yoast's paid WooCommerce
addon (measured separately, see below). `product:price:amount`,
`product:price:currency`, `og:availability` and `product:condition` are absent from
every free plugin on every page type.

**Rank Math emits `product:availability` with no price.** Deliberate on its part, and
only on variable products; a simple product gets both.

**`article:*` vocabulary appears on product pages** in three columns. Yoast, AIOSEO and
SEOPress all describe a product with editorial metadata.

**Nobody but us describes a product category properly.** Yoast and Rank Math say
`article`, SEOPress says `object`, AIOSEO emits no Open Graph there at all.

**AIOSEO Lite never emits `og:image`**, on any page type, on a product that has a
featured image.

**SEOPress puts one product's image and description on the whole archive.** Different
wrong product on `/shop/`, on page 2, and on search.

**Only Rank Math emits `og:image:alt`.**

## The paid Yoast WooCommerce addon

Captured separately (`.claude/tmp/artifacts/676/captures/yoast-woo/`), because it needs
a licence and is not in the tables above.

| Page type | `og:type` | Commerce properties |
|---|---|---|
| simple product | `product` | complete: price, currency, availability, condition, retailer_item_id, `og:availability`, both Twitter label rows |
| variable product | `product` | availability, condition, retailer_item_id, four `og:image` blocks, Twitter Availability. **No price, no currency** |
| category / shop / search | `article` | none |

It is the closest thing to a peer on a simple product, and has the same
variable-product price gap Rank Math does.

## Not covered

**Posts and pages.** Every table here is a commerce page type. Only Jetpack has ever
been captured off-commerce, which is exactly the gap [#690](https://github.com/Automattic/woocommerce-ai-storefront/issues/690)
exists to close: the non-commerce emitter currently gates on whether a plugin is
*installed*, and nothing has measured what these four actually emit on a post.

**Authored fields.** Nothing was typed into any plugin's own SEO fields.

**Rank Math PRO, SEOPress PRO, AIOSEO Pro.** Closed source and unlicensed here.
