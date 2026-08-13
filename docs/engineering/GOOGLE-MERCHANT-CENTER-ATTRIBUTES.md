# Google Merchant Center attribute requirements

> Last reviewed: 2026-08-13. Requirement scopes and value lists verified directly against Google Merchant Center help pages on that date; Schema.org property domains verified against `schemaorg-current-https.ttl`.

Apparel merchants running Google Merchant Center alongside this plugin hit a recurring class of report: GMC flags missing `gender`, `age_group`, `color`, or `size`. This document maps each GMC-required attribute to its Schema.org property, records what the plugin does with the value once it has one, and lists the traps that produce a missing-attribute report.

**Start from the product, not from the plugin.** The plugin publishes what the merchant enters. It cannot publish an attribute that is not on the product, and the overwhelming majority of these reports resolve to exactly that: the Color field is empty, the Gender attribute was never created. Nothing downstream compensates for an empty field, so check the product data first. The emission detail in this document is for the minority of cases where the value *is* present and something else is going on.

**The plugin is not the Merchant Center path.** Merchants submit to GMC through a product-feed plugin, which reads WooCommerce attributes directly. This plugin's JSON-LD is the agent-facing surface, not the feed. So the emission behaviour described below explains what AI agents see; it is not what gates a Merchant Center report, and no change to it will clear one. What both paths share is the underlying WooCommerce attribute, which is why the data hygiene here applies either way. (See "What this plugin does not do" in the [merchant user guide](../user-guide/USER-GUIDE.md).)

The merchant-facing version of this guidance lives in [user guide §5b](../user-guide/USER-GUIDE.md#5b-shape-your-catalog-for-ai-discoverability).

## The four attributes

| GMC attribute | Required for | Supported values | Schema.org target | Published as, when the merchant sets it |
|---|---|---|---|---|
| `gender` | All Apparel & Accessories (ID 166) | `male`, `female`, `unisex` | `Product.audience` → `PeopleAudience.suggestedGender` | `additionalProperty` (untyped) |
| `age_group` | All Apparel & Accessories (ID 166) | `newborn`, `infant`, `toddler`, `kids`, `adult` | `PeopleAudience.suggestedMinAge` / `suggestedMaxAge` | `additionalProperty` (untyped) |
| `color` | All Apparel & Accessories (ID 166) | Free text | `Product.color` | typed `color` |
| `size` | Apparel & Accessories > Clothing (ID 1604) and > Shoes (ID 187) | Free text | `Product.size` | typed `size` |

Every one of these is published when the merchant sets it. The last column is about *shape*, not presence: `color` and `size` become typed Schema.org properties, while `gender` and `age_group` currently ride along as generic `additionalProperty` entries because no slug maps them. An agent can read either; only the typed form carries machine meaning beyond a name/value pair. None of this affects the feed path.

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

So a Gender value the merchant entered is published, and published accurately. There is simply no `audience` / `PeopleAudience` emission anywhere in the codebase, so it arrives untyped. That is a legibility limitation for AI agents, not a data loss.

## Why an attribute is missing

**By far the most common reason is that nobody filled it in.** Check this first, on the product itself, before reading any further. A WooCommerce merchant creates a Color attribute when color drives variations, because that is the only case WooCommerce itself demands one; a single-colorway product therefore has no Color at all, and nothing downstream can invent it. The same holds for one-size items, and for Gender and Age Group, which most stores never create as attributes in the first place.

Once the value *is* on the product, four behaviours of `emit_attributes()` determine what shape it takes in the JSON-LD. All four are intended, none is a defect, and none of them affects the Merchant Center feed, which reads the WooCommerce attribute directly. They matter for what AI agents can read.

**1. The attribute is unmapped.** A `Gender` or `Age Group` attribute produces a `PropertyValue` entry in `additionalProperty` rather than a typed property, because no slug maps it. The value is present and correct; it just carries no more machine meaning than any other free-text pair. #618 tracks giving these two a typed home.

**2. The attribute is not marked visible.** `emit_attributes()` skips any attribute where `get_visible()` is false. An attribute added for internal use, or added with the "Visible on the product page" checkbox cleared, is emitted nowhere.

**3. The attribute is variation-defining, on a variable product.** Attributes used as variation axes are skipped on the parent. A variable product whose Color axis drives its variations emits `variesBy` on the parent `ProductGroup` and `color` on each variant, but no `color` on the parent itself. Confirmed on a local hoodie: the `ProductGroup` node carries `variesBy`, and `color` appears only inside `hasVariant` entries.

That is defensible Schema.org modelling: the axis is described once as `variesBy` and resolved per variant. It means an agent reading only the parent node sees no colour, which is the reason to know about it. It does not affect the feed, where the parent and its variants are built from the WooCommerce attributes regardless of how the JSON-LD nests them.

**4. The value is multi-valued.** The typed branch requires a single value. If the attribute value contains `|` or `,` — "Red, Blue" on one product — the code falls back to `additionalProperty` rather than claim a single `color`. Schema.org's `color` is `Text`-ranged and cannot honestly carry two colors, so this is correct, but the typed property goes unemitted.

A fifth, narrower case: if an upstream filter has already set the typed key (`$markup['color']`), the plugin defers on the typed side and still writes the merchant's attribute to `additionalProperty`, preserving the merchant's signal even when it disagrees with upstream.

## What merchants should do in WooCommerce

1. **Create global attributes** under Products > Attributes for Color, Size, Gender, and Age Group. Global (`pa_`-prefixed) attributes are reusable and keep values consistent; the plugin's map recognises both the `pa_` and bare forms for color and size.
2. **Set Color on every apparel product, including single-color ones.** "Black" on a product that only comes in black is exactly what GMC wants.
3. **Set Size on all Clothing and Shoes**, including one-size items.
4. **Use Google's exact English values** for Gender (`male` / `female` / `unisex`) and Age Group (`newborn` / `infant` / `toddler` / `kids` / `adult`). A localised or pluralised value ("Womens", "Adults") will not validate.
5. **Tick "Visible on the product page"** on each attribute, or the plugin skips it.
6. **Keep one value per attribute per product.** Multi-value attributes fall back to a generic property.

Steps 1 through 4 are what clear a Merchant Center report, and they are entirely merchant-side. Steps 5 and 6 are specific to this plugin's JSON-LD and affect what AI agents read, not the feed.

## Enhancements on the plugin side

Neither of these clears a Merchant Center report, since the feed does not read our JSON-LD. Both make an already-published value more legible to AI agents, which is the plugin's own remit.

**Map gender and age group to `audience`.** `Product.audience` accepts an `Audience`, and `PeopleAudience` carries the exact properties needed:

```json
"audience": {
  "@type": "PeopleAudience",
  "suggestedGender": "female",
  "suggestedMinAge": 13
}
```

`suggestedGender` is ranged as `GenderType` or `Text` and Schema.org's own comment gives "male", "female", "unisex" as the examples — the same three values GMC accepts, so the merchant's value passes through unchanged. `suggestedMinAge` and `suggestedMaxAge` are `Number`, in years, which means Google's named buckets need translating (`toddler` → min 1, max 5; `adult` → min 13, no max). That translation table is a design decision, not a mechanical mapping, and the boundary values above come from Google's own definitions.

**Decide parent-level emission for variation-defining color and size.** Skipping them on the parent is right for Schema.org and leaves an agent reading only the parent node without the values. Options include emitting the distinct set of variant values as `additionalProperty` on the parent, or leaving it as-is and documenting that the variants carry the data.

## How to verify a product

1. **Open the product in WP-Admin** and look at the Attributes tab. This answers the question that resolves most reports: is the value there at all? If it is blank, stop here and fill it in.
2. **View source** on the product page and search for `"color"`, `"size"`, and `"audience"`. This tells you what AI agents see, and whether a present value arrived typed or as an `additionalProperty` entry.
3. **Google's [Rich Results Test](https://search.google.com/test/rich-results)** parses the page the way Google does and shows the structured data it extracted.
4. **GMC diagnostics** is authoritative for what Google actually ingested from the feed. Note that it is reporting on the feed, so a discrepancy between it and the page markup is expected rather than alarming.

A value that appears as `additionalProperty` rather than a typed property is published and readable; work back through the four behaviours above to see which one shaped it that way.

## Sources

- [`age_group`](https://support.google.com/merchants/answer/6324463), [`gender`](https://support.google.com/merchants/answer/6324479), [`color`](https://support.google.com/merchants/answer/6324487), [`size`](https://support.google.com/merchants/answer/6324492) — Google Merchant Center help
- [`Product.audience`](https://schema.org/audience), [`PeopleAudience`](https://schema.org/PeopleAudience), [`suggestedGender`](https://schema.org/suggestedGender), [`suggestedMinAge`](https://schema.org/suggestedMinAge)
- [`JSON-LD-SCHEMA.md`](./JSON-LD-SCHEMA.md) — what the plugin emits, including the typed-attribute section
- [`SCHEMA-ORG-COVERAGE.md`](./SCHEMA-ORG-COVERAGE.md) — full Schema.org coverage audit, where `audience` is recorded as not emitted
