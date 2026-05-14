# Wiring Saltwarp into WordPress

Step-by-step instructions for installing the Saltwarp brand identity on
a WordPress + WooCommerce site. Assumes admin access and that the
target site already has the AI Storefront plugin installed.

All file paths below are relative to this directory
(`dev/test-store-brand/saltwarp/`).

## Order of operations

The order matters because some downstream steps depend on what
upstream steps publish. Top-down:

1. [Site title + tagline](#1-site-title--tagline)
2. [Site icon (favicon)](#2-site-icon-favicon)
3. [Header logo](#3-header-logo)
4. [Theme palette](#4-theme-palette)
5. [Contact email in JSON-LD](#5-contact-email-in-json-ld)
6. [Open Graph image](#6-open-graph-image)
7. [Catalog reset](#7-catalog-reset)
8. [Verify discovery surfaces](#8-verify-discovery-surfaces)

Each step is independent — you can install in any order. Verification
at the end is much faster if you do them in this sequence.

---

## 1. Site title + tagline

Where: **Settings → General**.

- **Site Title:** `Saltwarp`
- **Tagline:** `Wear that earns its weather.`

These two fields flow into:

- WordPress's `<title>` and `<meta name="description">` tags
- OpenGraph `og:site_name` and `og:description` (via WP core)
- Schema.org JSON-LD `name` and `description` (via this plugin)
- The browser tab title
- The `/llms.txt` header line (via this plugin's `WC_AI_Storefront_LLMSText` builder)

Click **Save Changes**.

## 2. Site icon (favicon)

Where: **Settings → General → Site Icon → Choose Image**.

Upload `favicon/favicon-512.png` (the 512×512 PNG). WordPress
auto-derives the 16/32/180 sizes from this one upload via the
`wp_site_icon_meta_tags` filter — no need to upload the smaller
PNGs separately.

The smaller PNG renders in this directory are provided **only for
verification** that the mark survives small sizes. They're not
uploaded to WP.

If you want the modern SVG favicon (Chrome 80+, Firefox 41+, Safari
13+ all support SVG favicons), it requires either a theme override
or a plugin like "RealFaviconGenerator." For most cases, the 512 PNG
is fine — modern browsers cache scaled raster favicons well enough
that the visible quality difference is minimal.

## 3. Header logo

This depends on the active theme. Most modern WP themes (Twenty
Twenty-Four, Twenty Twenty-Five, Storefront) support a custom logo
via the Customizer or Site Editor.

**For block themes (Twenty Twenty-Four+):**

Where: **Appearance → Editor → Patterns → Header**. Click the
existing header pattern, find the Site Logo block, swap in
`logo/lockup.svg` for full-width contexts or `logo/wordmark.svg` for
narrower headers. The SVG uses `fill="currentColor"` so it inherits
the theme's text color — no manual tinting needed.

**For classic themes (Storefront, older):**

Where: **Appearance → Customize → Site Identity → Logo**. Upload
`logo/lockup.svg`. Set logo width to ~280px for desktop.

**Color matching:**

The SVG ships with `color="#0F1213"` (Ink). If your theme uses a
different text color in the header (e.g. a dark-mode theme using Bone
on Ink background), override via CSS:

```css
.site-header .custom-logo {
  color: #F2EFE9; /* Bone — for dark header */
}
```

This works because the SVG's fill uses `currentColor`, so it adopts
whatever CSS `color` value its container sets.

## 4. Theme palette

This step is theme-dependent. For block themes, the cleanest path is
the Site Editor's Styles panel; for classic themes, you'll likely
edit a child theme's `style.css`.

**For block themes via Site Editor:**

Where: **Appearance → Editor → Styles → Colors → Palette**.

Define three custom colors:

| Name | Hex | Use |
|---|---|---|
| Ink | `#0F1213` | Primary text, navigation, buttons |
| Bone | `#F2EFE9` | Page backgrounds, surfaces |
| Rust | `#8C3B1F` | Accents, sale tags, link hover |

Then assign:
- **Background → Bone**
- **Text → Ink**
- **Link → Ink**
- **Link hover → Rust**
- **Buttons primary → Ink (background), Bone (text)**

**Type:**

Where: **Appearance → Editor → Styles → Typography**.

- **Body:** Inter, 16px, weight 400, line-height 1.6
- **Headings:** Inter, weight 600, line-height 1.2, tracking -1%

Add this to the active theme's `header.php` or via the Site Editor's
template head injection:

```html
<link rel="preconnect" href="https://rsms.me/">
<link rel="stylesheet" href="https://rsms.me/inter/inter.css">
```

(Inter from rsms.me is the maintainer's CDN — the canonical Inter
hosting. Self-host the font in production if you want to avoid the
external request, but for a test store the CDN is fine.)

## 5. Contact email in JSON-LD

The plugin currently emits `contactPoint.email` in the homepage
JSON-LD, pulled from WordPress's `admin_email` (Settings → General →
Administration Email Address).

If your admin email is `you@a8c.com` or similar internal address, AI
engines reading the JSON-LD see it and infer "internal test site, not
a real merchant."

**Two fixes:**

1. **Change the admin email** (Settings → General → Administration
   Email Address) to a Saltwarp-branded address like
   `hello@saltwarp.com` or `studio@saltwarp.com`. Even if the address
   doesn't actually receive mail, the JSON-LD will read consistent
   with the brand. WordPress sends a confirmation email to the
   new address — you can either accept that round-trip or filter via
   `new_admin_email` hook in a mu-plugin.

2. **Filter the email in JSON-LD only** (if you don't want to change
   the admin email). Add to a mu-plugin or theme functions:

   ```php
   add_filter(
     'wc_ai_storefront_homepage_jsonld',
     static function ( array $jsonld ): array {
         if ( isset( $jsonld['contactPoint'] ) ) {
             $jsonld['contactPoint']['email'] = 'hello@saltwarp.com';
         }
         return $jsonld;
     }
   );
   ```

   (Check the plugin's actual filter name — this is the expected
   pattern but the exact hook may be named differently. If the
   plugin doesn't ship a filter for this surface, the cleaner fix
   is option 1.)

## 6. Open Graph image

Where this lives depends on your SEO plugin.

**No SEO plugin (vanilla WP):**

WordPress core emits OG tags through theme support. For a custom OG
image, add to functions.php:

```php
add_action( 'wp_head', static function (): void {
    echo '<meta property="og:image" content="' . esc_url( get_template_directory_uri() . '/og-default.png' ) . '">';
    echo '<meta property="og:image:width" content="1200">';
    echo '<meta property="og:image:height" content="630">';
}, 5 );
```

…and upload `og/og-default.png` to the theme directory.

**With Yoast SEO:**

Where: **SEO → Settings → Site basics → Social sharing →
Open Graph default image**. Upload `og/og-default.png`. Yoast emits
the appropriate meta tags site-wide.

**With Rank Math:**

Where: **Rank Math → Titles & Meta → Global Meta → Social Meta**.
Upload `og/og-default.png` as the default Facebook image.

**Verification:** Once uploaded, paste your site URL into [Meta's
Sharing Debugger](https://developers.facebook.com/tools/debug/) and
click "Scrape Again" to force a fresh fetch. The preview should show
the Ink background + Saltwarp wordmark.

## 7. Catalog reset

Existing products (WC sample data + subscription fixtures) should be
deleted before seeding the Saltwarp catalog. The current catalog will
otherwise show through AI-engine fetches and contradict the brand.

**Delete existing products (CLI, fastest):**

```bash
docker exec woocommerce-ai-storefront-cli wp post list \
  --post_type=product --format=ids \
  | xargs docker exec woocommerce-ai-storefront-cli wp post delete --force
```

Or in **Products → All Products**, select all, Bulk Actions → Move to
Trash → Apply, then **Trash** → Empty Trash.

**Seed the Saltwarp catalog:**

The on-brand catalog draft lives at `catalog/products.json`. See
that file's header comment for the import recipe (currently a manual
WP-Admin workflow; a WP-CLI seeder script is a future improvement).

Categories created: Tops, Bottoms, Outerwear, Knits.

## 8. Verify discovery surfaces

After all of the above, the AI-discovery surfaces should now reflect
Saltwarp consistently. Spot-check each:

```bash
# llms.txt — should start with "# Saltwarp" + tagline
curl -s https://your-test-domain/llms.txt | head -5

# UCP manifest — store_context.name should be "Saltwarp"
curl -s https://your-test-domain/.well-known/ucp | jq .store_context

# Homepage JSON-LD — name + description should reflect brand
curl -s https://your-test-domain/ \
  | grep -A1 'application/ld+json' \
  | head -3
```

If any surface still shows old brand text:

- llms.txt: regenerate via the plugin's settings (or the cache may
  be stale; deactivate/reactivate the plugin).
- UCP manifest: same as llms.txt (cached, often regenerated on plugin
  config save).
- JSON-LD: live-rendered per request, should reflect the site title
  immediately.

Then revisit the smoke test:

> *"Find products at [your-test-domain] that match 'merino tee under $80.'"*

Across ChatGPT, Gemini, Claude, Perplexity. A brand that reads
"premium streetwear" with consistent name, palette, OG preview, and
a real-looking catalog should now produce real product names from
the AI's fetch — not hallucinated fabrications.

If Gemini still hallucinates (it sometimes never fetches unfamiliar
domains regardless of how legit they look), that's a Gemini-side
behavior to note in the smoke-test caveats, not a fixable
brand-identity issue.
