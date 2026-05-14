# Saltwarp

A brand identity for the test store at pierorocca.com (or wherever
this gets pointed). Premium streetwear archetype: monochrome with one
warm accent, geometric grotesque wordmark, textile-derived mark.

## The brand in one paragraph

**Saltwarp** — *Wear that earns its weather.* The name pairs textile
vocabulary (warp = the lengthwise threads on a loom) with environmental
wear (salt-cured, weathered). Reads as quietly textile-literate without
being literal. Positions adjacent to Marine Layer, Imogene + Willie,
Outerknown — coastal-California heritage with design rigor.

## Palette

| Token | Hex | Use |
|---|---|---|
| Ink | `#0F1213` | Primary brand color; wordmark, body text, navigation chrome |
| Bone | `#F2EFE9` | Primary surface; page backgrounds, product card surfaces |
| Rust | `#8C3B1F` | Single accent; hover states, sale tags, occasional emphasis |

The palette is intentionally narrow. Premium streetwear leans on
restraint — adding a fourth color cheapens the read.

## Typography

- **Wordmark:** custom hand-drawn geometric grotesque, set all-caps with
  tight tracking. The SVG file is the source of truth — no system font
  reproduces it.
- **Body / UI:** [Inter](https://rsms.me/inter/) — open source,
  Google-Fonts-hosted, geometric grotesque DNA. Use 400 (regular) for
  body copy and 600 (semibold) for navigation / labels. Avoid 700+
  except for the rare hero headline.
- **Numerals:** Inter's tabular variants (`font-feature-settings:
  "tnum"`) for price displays.

## Logo system

Three lockups, all in `logo/`:

- **`wordmark.svg`** — type only. The default usage. Use anywhere the
  brand needs to read at >32px height.
- **`mark.svg`** — the standalone geometric mark (textile-cross shape).
  Use for favicon, social-media avatar, anywhere the wordmark would
  be too cramped. Mark stands alone confidently.
- **`lockup.svg`** — mark-to-the-left + wordmark-to-the-right with
  proportional spacing baked in. Use for the site header.

All three are pure vector — no rasters, no system-font fallbacks. They
scale infinitely. Background color of every SVG is transparent; the
fill is Ink (`#0F1213`) by default and can be inverted to Bone for
dark-mode applications by swapping a single fill value.

## Favicon set

`favicon/` contains the standard WordPress site-icon sizes:

- `favicon-16.svg`, `favicon-32.svg` — browser tab favicons
- `favicon-180.svg` — iOS home screen
- `favicon-512.svg` — Android home screen / WordPress site icon
- `favicon.svg` — modern browsers that prefer SVG favicons

Each is the standalone mark, sized appropriately. The 16px version
has minor weight adjustments so the strokes survive sub-pixel
rendering.

## OG image

`og/og-default.svg` — 1200×630 social-share image template. Ink
background, Bone wordmark centered, with the rust accent line below.
This is what AI engines see in their link preview and what shows up
on Slack/Twitter/Bluesky shares.

Per-page OG images can be derived from this template by swapping the
tagline; the SVG is laid out so the swap is a single text edit.

## Catalog

`catalog/products.json` is a draft on-brand catalog of 12 products
designed for seeding into the test store. Categories: Tops, Bottoms,
Outerwear, Knits. Pricing band: $48–$280. Each product carries
on-brand copy in the voice that matches the wordmark and palette.

The catalog is JSON for ease of editing, but it's not wired to
anything yet — converting to a WC fixture or seeding script is a
follow-up step (see `WIRING.md`).

## Wiring the brand into WordPress

`WIRING.md` documents step-by-step what to change in wp-admin to make
the test store actually look like Saltwarp:

1. Site title + tagline (Settings → General)
2. Site icon (Settings → General → Site Icon, upload favicon-512.svg)
3. Header logo (Theme Customizer → Site Identity → Logo)
4. Theme palette tokens (theme.json or Customizer)
5. The `@a8c.com` email leak in JSON-LD (plugin setting or theme override)
6. OG image (varies by SEO plugin; defaults to site icon if nothing set)
7. Catalog reseed (delete current products, import on-brand replacements)

See WIRING.md for the actual commands and screen paths.
