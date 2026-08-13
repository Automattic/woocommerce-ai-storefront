# Google Merchant Center attribute requirements

> Last reviewed: 2026-08-13. Requirement scopes and value lists verified directly against Google Merchant Center help pages on that date; Schema.org property domains verified against `schemaorg-current-https.ttl`.

Apparel merchants running Google Merchant Center alongside this plugin hit a recurring class of catalog-data problem: GMC reports missing `gender`, `age_group`, `color`, or `size` on products the merchant considers complete. This document maps each GMC-required attribute to its Schema.org property and to what the plugin emits today, and enumerates the ways correct catalog data can still fail to reach Google.

This is a catalog-data reference, not a feed-integration guide. The plugin is not a Merchant Center feed (see the "What this plugin does not do" section of the [merchant user guide](../user-guide/USER-GUIDE.md)); merchants submit to GMC through a feed plugin or through Google's crawl of on-page structured data. Either path reads the same underlying WooCommerce attributes, so the data hygiene below applies regardless of which one a merchant uses.

## The four attributes

| GMC attribute | Required for | Supported values | Schema.org target | Plugin emits? |
|---|---|---|---|---|
| `gender` | All Apparel & Accessories (ID 166) | `male`, `female`, `unisex` | `Product.audience` → `PeopleAudience.suggestedGender` | ❌ never |
| `age_group` | All Apparel & Accessories (ID 166) | `newborn`, `infant`, `toddler`, `kids`, `adult` | `PeopleAudience.suggestedMinAge` / `suggestedMaxAge` | ❌ never |
| `color` | All Apparel & Accessories (ID 166) | Free text | `Product.color` | ✅ conditionally |
| `size` | Apparel & Accessories > Clothing (ID 1604) and > Shoes (ID 187) | Free text | `Product.size` | ✅ conditionally |

"Required for" above is the **free listings** requirement, which applies to every merchant with an unpaid GMC presence. Each attribute is additionally required for **Shopping ads** when targeting Brazil, France, Germany, Japan, the United Kingdom, or the United States. Google lists optional exemptions for a set of Apparel & Accessories subcategories (pinback buttons, tie clips, cufflinks, wristbands, shoelaces, keychains and similar).

Note the scope difference: `gender`, `age_group`, and `color` are required across **all** of Apparel & Accessories, while `size` narrows to Clothing and Shoes. A merchant selling hats and bags needs the first three but not the fourth.

### `age_group` values

Google's supported values, with its own age definitions:

| Value | Age range |
|---|---|
| `newborn` | 0–3 months old |
| `infant` | 3–12 months old |
| `toddler` | 1–5 years old |
| `kids` | 5–13 years old |
| `adult` | Typically teens or older (13 years old or more) |

Most apparel merchants need only `adult`, and that is the point merchants most often miss: `adult` is not a default Google infers from silence. An adult-clothing store must state `adult` explicitly on every product or GMC reports the attribute as missing.

Google's guidance on variants: "Use this attribute when your product is a variant that is distinguished by its age group (for example, 'shoes for 0–3 months' and 'shoes for 1–5 years'), and also submit each variant with the same value for the [item group ID]" attribute.

### `gender` values

Three values only: `male`, `female`, `unisex`. Google requires that "these values must be submitted in English" regardless of the store's language.

### `color` on single-color products

The requirement is scoped by **product category, not by whether the product varies**. Every Apparel & Accessories product needs a color, including one that comes in exactly one color. Google's help page states you must "Submit the color of your product" and provides no carve-out for products with a single color or no meaningful color.

This is the most common source of the missing-`color` report. A WooCommerce merchant naturally creates a Color attribute only when they need it as a variation axis, because that is the only case where WooCommerce itself requires one. A single-color product gets no Color attribute, so nothing reaches GMC.

The same logic applies to `size` on Clothing and Shoes that come in one size only.

## What the plugin emits today

Typed attribute emission lives in `emit_attributes()` in [`class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php). It maps a fixed set of WooCommerce attribute slugs to Schema.org properties:

```php
private const CORE_ATTRIBUTE_MAP = array(
    'pa_color'    => 'color',
    'color'       => 'color',
    'pa_colour'   => 'color',
    'colour'      => 'color',
    'pa_size'     => 'size',
    'size'        => 'size',
    'pa_material' => 'material',
    'material'    => 'material',
    'pa_pattern'  => 'pattern',
    'pattern'     => 'pattern',
);
```

Anything not in that map is emitted as a generic `additionalProperty` entry:

```json
{ "@type": "PropertyValue", "name": "Gender", "value": "female" }
```

There is no `audience` / `PeopleAudience` emission anywhere in the codebase, so `gender` and `age_group` have no typed representation at all.

## Four ways correct catalog data fails to reach Google

These are the failure modes worth checking before concluding a product is missing data. All four are current, intended behavior of `emit_attributes()`, not defects — but each one produces a GMC report that looks like missing data.

**1. The attribute exists but is unmapped.** A merchant who dutifully creates a `Gender` or `Age Group` attribute and fills it on every product gets a `PropertyValue` entry in `additionalProperty`. The data is present and correct in the markup, and Google will not read it as `gender` or `age_group`, because those require the typed `audience` structure. This is the most frustrating variant of the problem: the merchant did the work and the report still says missing.

**2. The attribute is not marked visible.** `emit_attributes()` skips any attribute where `get_visible()` is false. An attribute added for internal use, or added with the "Visible on the product page" checkbox cleared, is emitted nowhere.

**3. The attribute is variation-defining, on a variable product.** Attributes used as variation axes are skipped on the parent. A variable product whose Color axis drives its variations emits `variesBy` on the parent `ProductGroup` and `color` on each variant, but no `color` on the parent itself. Confirmed on a local hoodie: the `ProductGroup` node carries `variesBy`, and `color` appears only inside `hasVariant` entries.

That is defensible Schema.org modelling. It matters for GMC because the item-group parent and its variants are submitted as separate rows, and a report keyed to the parent shows the attribute absent.

**4. The value is multi-valued.** The typed branch requires a single value. If the attribute value contains `|` or `,` — "Red, Blue" on one product — the code falls back to `additionalProperty` rather than claim a single `color`. Schema.org's `color` is `Text`-ranged and cannot honestly carry two colors, so this is correct, but the typed property goes unemitted.

A fifth, narrower case: if an upstream filter has already set the typed key (`$markup['color']`), the plugin defers on the typed side and still writes the merchant's attribute to `additionalProperty`, preserving the merchant's signal even when it disagrees with upstream.

## What merchants should do in WooCommerce

1. **Create global attributes** under Products > Attributes for Color, Size, Gender, and Age Group. Global (`pa_`-prefixed) attributes are reusable and keep values consistent; the plugin's map recognises both the `pa_` and bare forms for color and size.
2. **Set Color on every apparel product, including single-color ones.** "Black" on a product that only comes in black is exactly what GMC wants.
3. **Set Size on all Clothing and Shoes**, including one-size items.
4. **Use Google's exact English values** for Gender (`male` / `female` / `unisex`) and Age Group (`newborn` / `infant` / `toddler` / `kids` / `adult`). A localised or pluralised value ("Womens", "Adults") will not validate.
5. **Tick "Visible on the product page"** on each attribute, or the plugin skips it.
6. **Keep one value per attribute per product.** Multi-value attributes fall back to a generic property.

Steps 4 and 6 apply to the feed path as much as the structured-data path; steps 5 and 6 are specific to what this plugin emits.

## Gaps to close in the plugin

Neither is filed yet; both are catalog-completeness work rather than bug fixes.

**Map gender and age group to `audience`.** `Product.audience` accepts an `Audience`, and `PeopleAudience` carries the exact properties needed:

```json
"audience": {
  "@type": "PeopleAudience",
  "suggestedGender": "female",
  "suggestedMinAge": 13
}
```

`suggestedGender` is ranged as `GenderType` or `Text` and Schema.org's own comment gives "male", "female", "unisex" as the examples — the same three values GMC accepts, so the merchant's value passes through unchanged. `suggestedMinAge` and `suggestedMaxAge` are `Number`, in years, which means Google's named buckets need translating (`toddler` → min 1, max 5; `adult` → min 13, no max). That translation table is a design decision, not a mechanical mapping, and the boundary values above come from Google's own definitions.

**Decide parent-level emission for variation-defining color and size.** Skipping them on the parent is right for Schema.org and unhelpful for GMC. Options include emitting the distinct set of variant values as `additionalProperty` on the parent, or leaving it and documenting that the variants carry the data.

## How to verify a product

1. **View source** on the product page and search for `"color"`, `"size"`, and `"audience"`. Check whether the value is a typed property or buried in `additionalProperty`.
2. **Google's [Rich Results Test](https://search.google.com/test/rich-results)** parses the page the way Google does and shows the structured data it extracted.
3. **GMC diagnostics** reports per-product attribute problems and is the authoritative source for what Google actually ingested.

If a value appears in `additionalProperty` rather than as a typed property, work back through the four failure modes above — the attribute almost certainly exists and is simply not reaching Google in a form it reads.

## Sources

- [`age_group`](https://support.google.com/merchants/answer/6324463), [`gender`](https://support.google.com/merchants/answer/6324479), [`color`](https://support.google.com/merchants/answer/6324487), [`size`](https://support.google.com/merchants/answer/6324492) — Google Merchant Center help
- [`Product.audience`](https://schema.org/audience), [`PeopleAudience`](https://schema.org/PeopleAudience), [`suggestedGender`](https://schema.org/suggestedGender), [`suggestedMinAge`](https://schema.org/suggestedMinAge)
- [`JSON-LD-SCHEMA.md`](./JSON-LD-SCHEMA.md) — what the plugin emits, including the typed-attribute section
- [`SCHEMA-ORG-COVERAGE.md`](./SCHEMA-ORG-COVERAGE.md) — full Schema.org coverage audit, where `audience` is recorded as not emitted
