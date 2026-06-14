# JSON-LD Schema Reference

The structured-data shapes WooCommerce AI Storefront emits, where, and what controls each field. Use this when integrating with the plugin, debugging structured-data output, or extending it via the public filters.

## What the plugin emits

Four distinct JSON-LD blocks:

| Surface | Block | Location | Source |
|---------|-------|----------|--------|
| Product page (single product) | Enhanced `Product` | Inside the `<head>` via `wp_head`, layered on top of WooCommerce core's existing `Product` block | [`includes/ai-storefront/class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) `enhance_product_data()` |
| Store homepage / shop page | `OnlineBusiness` (an `Organization` subtype) | Inside the `<head>` via `wp_head`, on `is_front_page() || is_shop()` when the plugin is enabled | Same file, `output_store_jsonld()` (priority 5) |
| Every page | `WebSite` + `SearchAction` | Inside the `<head>` via `wp_head` (priority 4), on every page when the plugin is enabled | Same file, `output_website_jsonld()` |
| Shop / category / tag / product-search archives | `ItemList` of `ListItem` → `Product` stubs | Inside the `<head>` via `wp_head` (priority 6) | Same file, `output_archive_itemlist_jsonld()` |

All blocks emit only when the plugin is enabled (`enabled === 'yes'` in `wc_ai_storefront_settings`). Disabling the plugin removes the markup entirely; the underlying WooCommerce core JSON-LD (basic Product, Offer, AggregateRating) continues to render unchanged.

The three `wp_head` priorities are ordered so the global `WebSite` block (4) precedes the homepage `OnlineBusiness` block (5), which precedes the archive `ItemList` block (6). The homepage is excluded from the `ItemList` block (it already carries `OnlineBusiness` + `hasOfferCatalog`), so the two never collide on one page.

The plugin **does not replace** WC core's JSON-LD. It registers a `woocommerce_structured_data_product` filter that runs after WC has built its base markup and merges enhancement fields into the existing array.

### Visibility gating (per-product)

Beyond the global enabled/disabled toggle, the merchant's **Visibility** setting (Visibility tab in the admin: All / Products by category, tag, or brand / Specific products only) gates the per-product enhancement on a finer-grained basis. The check happens at the top of [`enhance_product_data()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) before any field is added:

```php
if ( ! WC_AI_Storefront::is_product_syndicated( $product, $settings ) ) {
    return $markup;  // WC core's untouched markup ships
}
```

When a product is **excluded** from visibility — i.e. not in the merchant's `selected_categories` / `selected_tags` / `selected_brands` / `selected_products`, depending on the active mode — the plugin adds **none** of the enhancement fields. Specifically:

- No `potentialAction` (`BuyAction` with attribution UTMs)
- No `offers[0].inventoryLevel`
- No `category` breadcrumb path
- No `weight` / `depth` / `width` / `height` dimensions
- No `additionalProperty` attribute array
- No `priceCurrency` normalization
- No `offers[0].shippingDetails` (`OfferShippingDetails`, including `handlingTime` and free-shipping `shippingRate`)
- No `offers[0].hasMerchantReturnPolicy`

What still ships on excluded products: WC core's untouched base block (`@type: Product`, `name`, `url`, `description`, `image`, `sku`, `offers[0].price` / `availability` / `seller`, etc.). The merchant's visibility intent is fully honored at the **AI-attribution layer** (no `BuyAction` UTMs means agents that recommend the product can't claim attribution credit) and at the **UCP catalog endpoints** (`/wp-json/wc/ucp/v1/catalog/{search,lookup}` filter excluded products out of result sets via a `pre_get_posts` gate). It is **not** honored at the WC-core JSON-LD layer, because we don't suppress WC core's emission; we just decline to augment it.

Variations inherit their parent's visibility status, so a "Hoodie - Red" variation page gets the same gating answer as the parent "Hoodie." See [`is_product_syndicated()`](../../includes/class-wc-ai-storefront.php) and [`resolve_product_id_for_syndication()`](../../includes/class-wc-ai-storefront.php) for the parent-redirect logic.

This behavior is locked by the `test_enhancement_is_bypassed_when_product_not_syndicated` test in [`tests/php/unit/JsonLdTest.php`](../../tests/php/unit/JsonLdTest.php) — a regression that re-introduces enhancement on excluded products would fail that test.

## Product page: enhanced Product schema

Below is a representative full output for a published product after the plugin's enhancement pass. Annotations call out which fields are added or modified by this plugin vs. inherited from WC core.

```jsonc
{
  "@context": "https://schema.org/",
  "@type": "Product",

  // ---- Inherited from WC core (shape may vary by theme/extension) ----
  "@id": "https://yourstore.example.com/product/hoodie-with-zipper/#product",
  "name": "Hoodie with Zipper",
  "url": "https://yourstore.example.com/product/hoodie-with-zipper/",
  "description": "Zip-front hoodie in heavyweight cotton fleece.",
  "image": ["https://yourstore.example.com/wp-content/uploads/.../hoodie.jpg"],
  "sku": "woo-hoodie-with-zipper",

  "offers": [{
    "@type": "Offer",
    "price": "45.00",
    "availability": "https://schema.org/InStock",
    "url": "https://yourstore.example.com/product/hoodie-with-zipper/",
    "seller": { "@type": "Organization", "name": "Your Store" },

    // ---- Added by this plugin ----
    "priceCurrency": "USD",
    "inventoryLevel": { "@type": "QuantitativeValue", "value": 12 },
    "shippingDetails": { "@type": "OfferShippingDetails", "shippingDestination": [{
      "@type": "DefinedRegion", "addressCountry": "US"
    }]},
    "hasMerchantReturnPolicy": {
      "@type": "MerchantReturnPolicy",
      "applicableCountry": "US",
      "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
      "merchantReturnDays": 30,
      "returnMethod": ["https://schema.org/ReturnByMail"],
      "returnFees": "https://schema.org/FreeReturn"
    }
  }],

  // ---- Added by this plugin ----
  "potentialAction": {
    "@type": "BuyAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "https://yourstore.example.com/checkout-link/?products=123:1&utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld",
      "actionPlatform": [
        "https://schema.org/DesktopWebPlatform",
        "https://schema.org/MobileWebPlatform"
      ]
    },
    "result": { "@type": "Order" }
  },
  "category": "Clothing > Hoodies",
  "weight":  { "@type": "QuantitativeValue", "value": 0.6, "unitCode": "KGM" },
  "depth":   { "@type": "QuantitativeValue", "value": 5,   "unitCode": "CMT" },
  "width":   { "@type": "QuantitativeValue", "value": 30,  "unitCode": "CMT" },
  "height":  { "@type": "QuantitativeValue", "value": 60,  "unitCode": "CMT" },
  "additionalProperty": [
    { "@type": "PropertyValue", "name": "Color", "value": "Navy" },
    { "@type": "PropertyValue", "name": "Material", "value": "Cotton fleece" },
    { "@type": "PropertyValue", "name": "Sizes available", "value": "S, M, L, XL" }
  ]
}
```

## Field reference

Each field added by the plugin, with the rule that controls its presence.

### `potentialAction` (BuyAction)

A Schema.org `BuyAction` pointing to a URL the AI agent can use to send the shopper to checkout with that product pre-added.

- **Always emitted** for purchasable products (not draft, not out of stock when stock management is on, has a price).
- **`target` URL** is built from `$product->add_to_cart_url()` plus `utm_*` placeholders (`{agent_id}`, `{session_id}`) the AI agent substitutes at runtime per UCP convention.
- **`result.@type`** is always `Order` (Schema.org's expected result type for `BuyAction`).
- **Source**: `add_buy_action()` (line ~108 in `class-wc-ai-storefront-jsonld.php`).

#### Are the UTM placeholders actually filled in by AI agents?

No — not today. JSON-LD is crawled and indexed offline; AI agents query their knowledge base at recommendation time, not the live page. The `{agent_id}` and `{session_id}` placeholders are stored verbatim in the crawler's index. No AI agent currently dynamically constructs purchase URLs from `BuyAction` `urlTemplate` at recommendation time (unlike `SearchAction`'s `{search_term}`, which Google does exercise for sitelinks search boxes).

The placeholders are **aspirational**: they express a machine-readable intent that allows agents to attribute traffic and correlate sessions if a future agentic standard emerges for dynamic URL construction. There is no harm in leaving them in — crawlers store the template string as-is, no session data leaks, and no broken URLs reach browsers.

### `offers[0].inventoryLevel`

Schema.org `QuantitativeValue` exposing the current stock level.

- **Emitted only when** WooCommerce stock management is enabled for the product AND the product has a numeric `stock_quantity`.
- **Skipped for** products with `manage_stock=false` (out of scope for inventory-level discovery).

### `sku`, `gtin`, `mpn`, `productID` (WC-core identifiers)

These identification fields are emitted by **WC core** in its base Product JSON-LD; the plugin doesn't add or modify them.

- **`sku`** -- always emitted. From `$product->get_sku()`. Falls back to `$product->get_id()` if the merchant didn't set an SKU. *(Note: `sku` and `gtin` are different concepts. SKU is the merchant's internal stock code; GTIN is the global Trade Item Number — UPC, EAN, ISBN, ITF-14. A merchant whose SKU happens to be EAN-format should also populate the dedicated GTIN field — WC core's `_global_unique_id` meta — to get both emitted correctly.)*
- **`gtin`** -- emitted when WC's `_global_unique_id` (Global Unique ID) field is set on the product. WC core handles emission; this plugin doesn't synthesize or override.
- **`mpn`** -- not emitted in default WC core. Some extensions add it via the `woocommerce_structured_data_product` filter.
- **`productID`** -- not emitted in default WC core.

The plugin's `wc_ai_storefront_jsonld_product` filter is the right hook for extensions that want to add `mpn` or `productID` from custom merchant data (or normalize an SKU value to a more specific `gtin8`/`gtin13` shape per Schema.org).

### `BreadcrumbList`

A separate JSON-LD block emitted on product pages (and other archive pages) by **WC core**, not by this plugin. The `<script type="application/ld+json">` element on a product page has TWO entries in its `@graph`: a `BreadcrumbList` (category-path navigation) and the `Product` block.

```jsonc
{
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "..." },
    { "@type": "ListItem", "position": 2, "name": "Clothing", "item": "..." },
    { "@type": "ListItem", "position": 3, "name": "Tshirts", "item": "..." }
  ]
}
```

- **Source**: WC core's [`WC_Structured_Data::generate_breadcrumblist_data()`](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/class-wc-structured-data.php) — uses WC's `WC_Breadcrumb` data builder.
- **Plugin contribution**: none. We don't add to or modify the BreadcrumbList. Available to AI agents and search crawlers as the natural Schema.org breadcrumb-rich-result signal.
- **Why this matters**: Google requires `BreadcrumbList` for breadcrumb rich results in search. AI agents use the breadcrumb chain for product-context navigation ("this is in Clothing > Tshirts > Long Sleeve Tee").

### `brand`

`{"@type": "Brand", "name": "..."}` from the `product_brand` taxonomy.

- **Emitted when** WooCommerce's built-in brand support is active (`product_brand` taxonomy registered) and the product has at least one assigned brand term.
- **Selection rule**: WC core picks the first assigned brand if multiple are set (a product belongs to one brand for Schema.org purposes).
- **Source**: emitted by **WC core**'s `WC_Brands::add_structured_data()` (in `wp-content/plugins/woocommerce/includes/class-wc-brands.php`), hooked into `woocommerce_structured_data_product` at priority 20 — separate from the main `WC_Structured_Data` class. This is why early audit grep on the main structured-data class missed it.
- **Compatibility**: requires WC's modern brands feature (rolled out via the `Automattic\WooCommerce\Internal\Brands` package). Older WC installs without brand taxonomy support, or sites using third-party brand plugins, may not emit this — and would need the third-party plugin's own structured-data integration.
- **Plugin enrichment**: this plugin doesn't add or modify the brand emission; it relies on WC core.

### `category`

The primary category path as a breadcrumb string (e.g. `"Clothing > Hoodies"`).

- **Emitted when** the product has at least one assigned category.
- **Selection rule**: deepest leaf in the longest breadcrumb path. Ties broken by category ID.
- **Format**: " > " separator.

#### Sourcing and merchant control

`Product.category` is sourced directly from the WooCommerce `product_cat` taxonomy. The plugin does no name transformation, no Google Product Taxonomy mapping, and no synonym normalization at emission time. What the merchant authored in `Products → Categories` is what AI agents and search engines see. This is intentional:

- **The merchant is authoritative**. The plugin's job is to publish the catalog faithfully, not to reinterpret it.
- **Reinterpreting silently is worse than emitting unfamiliar names**. A "Summer Vibes" → "Swimwear" guess that's wrong pollutes the merchant's structured-data feed; the right answer is to surface the gap and let the merchant fix the source.
- **Downstream behavior is testable**. With raw category names flowing through unchanged, the JSON-LD output is a 1:1 function of WC state: straightforward to diff, debug, and validate.

The consequence: catalog taxonomy quality is a *merchant-facing* concern, documented in [USER-GUIDE §5b](../user-guide/USER-GUIDE.md#5b-shape-your-catalog-for-ai-discoverability). Merchants who want good AI surfacing need to author categories that read as canonical product types ("Hoodies & Sweatshirts", not "Summer Vibes"), at most two levels deep, with every product assigned to a leaf rather than a parent.

#### Why not normalize to Google Product Taxonomy in the plugin?

A reasonable question, since several existing plugins (Yoast WooCommerce SEO, RankMath WC, Google Listings & Ads, Facebook for WooCommerce) already let merchants set a Google Product Category (GPC) ID per term. The plugin could read that term meta and override `Product.category` with the Google path.

It currently doesn't, for these reasons:

1. **The Google path and the merchant's category name are different things**. `Product.category` is a free-text Schema.org field; replacing the merchant's "Hoodies" with Google's "Apparel & Accessories > Clothing > Activewear > Hoodies & Sweatshirts" would surface unfamiliar phrasing to AI agents who quote the category name back to shoppers. Better: emit the merchant's name in `Product.category` and emit the GPC separately so consumers that key on canonical IDs can use it.

2. **The right field for canonical IDs is `additionalProperty`** (or a future-proposed `productCategory` Schema.org extension), not `category`. Emitting GPC alongside the human-readable category in distinct fields keeps both audiences happy.

3. **Surfacing canonical IDs is on the roadmap as a follow-on**. Once we have a reliable cross-plugin reader for term-meta GPC values, the natural addition is an `additionalProperty` entry like `{ "@type": "PropertyValue", "name": "Google Product Category ID", "value": "5697", "propertyID": "GPC" }`. Tracked in [#412](https://github.com/Automattic/woocommerce-ai-storefront/issues/412), which proposes a deeper auto-mapping pipeline for merchants who haven't set GPC IDs manually.

#### Breadcrumb selection algorithm

The "deepest leaf in the longest breadcrumb path" rule, in concrete terms:

1. Fetch all `product_cat` terms assigned to the product.
2. For each term, compute its breadcrumb path by walking `parent` pointers to the root. The path's length is the number of segments.
3. Pick the path with the most segments. On a tie (multiple equally-deep paths), pick the one whose leaf term has the lowest `term_id` for determinism.
4. The leaf's name (not the full path) becomes `Product.category`, unless changed in a future revision to emit the full " > " joined path.

Practical implication for merchants: when a product belongs to multiple categories of different depths, the deepest leaf wins. So a hoodie assigned to both `Activewear > Hoodies & Sweatshirts` *and* a flat top-level `Capsule` (subscription drop) emits `category: "Hoodies & Sweatshirts"`, not `"Capsule"`. The merchant's merchandising taxonomy and their canonical product taxonomy can coexist; the deeper canonical leaf wins for AI emission.

### `weight`, `depth`, `width`, `height` (dimensions)

Schema.org `QuantitativeValue` blocks with `unitCode` set to UN/CEFACT codes (`KGM`, `LBR`, `CMT`, `INH`, etc.) per the store's WC unit settings.

- **Each emitted independently** if the product has a non-empty value for that dimension.
- **Unit codes** map from WC's wordpress unit setting (kg, lbs, cm, in, m, mm) via `get_weight_unit_code()` / `get_dimension_unit_code()`.

### `color`, `material`, `pattern`, `size` (typed Schema.org properties)

Since [#327](https://github.com/Automattic/woocommerce-ai-storefront/issues/327) the plugin emits known WC attributes as their typed Schema.org Product properties rather than as `additionalProperty` entries. Schema.org's directive — *"Always use specific schema.org properties when they exist"* — supersedes the generic `additionalProperty` fallback for these.

Mapped slugs (case-insensitive lookup) → typed Schema.org property:

| WC attribute slug | Schema.org property | Spec type |
|---|---|---|
| `pa_color`, `color`, `pa_colour`, `colour` | [`color`](https://schema.org/color) | `Text` |
| `pa_size`, `size` | [`size`](https://schema.org/size) | `Text` |
| `pa_material`, `material` | [`material`](https://schema.org/material) | `Text` |
| `pa_pattern`, `pattern` | [`pattern`](https://schema.org/pattern) | `Text` |

**Emission rules:**

- **Single-value attribute** with no upstream owner → emit as the typed property (e.g. `"color": "Black"`). The attribute is then *excluded* from `additionalProperty` to avoid double-emit.
- **Multi-value attribute** (any `,` or `|` in the value) → typed-property emission is **skipped**. Schema.org's `Text` type can't honestly carry a multi-value claim, and a first-piece-only emit would silently drop merchant data. Falls back to `additionalProperty` with the joined string preserved.
- **Variation-defining attribute** → both typed-property and `additionalProperty` emission are skipped on the parent. Variation-defining attributes describe individual *variants*, not the parent product as a whole — emitting them at the parent level would claim a single intrinsic color/size that the parent doesn't have. Per-variant typed emission lives on each `hasVariant` Product entry under the `ProductGroup` shape — see [`ProductGroup` / `hasVariant` / `variesBy`](#productgroup--hasvariant--variesby-variable-products) below.
- **Existing value in `$markup`** (set by WC core or another plugin) → defer on the typed side, don't overwrite. The merchant's attribute *still* emits to `additionalProperty` so its data signal reaches agents even when upstream chose a different typed value. Caller control over the typed claim is preserved.

Worked example:

```jsonc
// Simple product, attributes: pa_color="Black", pa_size="L", pa_style="Casual"
{
  "@type": "Product",
  "name": "...",
  "color": "Black",     // typed
  "size": "L",          // typed
  "additionalProperty": [
    { "@type": "PropertyValue", "name": "Style", "value": "Casual" }  // unmapped
  ]
}
```

Implementation: [`emit_attributes()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) — single-pass per attribute, decides typed property vs `additionalProperty` inline. One `get_attribute()` lookup per visible attribute regardless of which path the value takes.

### `ProductGroup` / `hasVariant` / `variesBy` (variable products)

Variable products with at least one attribute marked **Used for variations** emit as Schema.org [`ProductGroup`](https://schema.org/ProductGroup) — a parent abstraction over its variations, where each concrete buyable SKU lives under `hasVariant` as its own Product block.

**Top-level shape change:**

- `@type` flips from `Product` → `ProductGroup`.
- `productGroupID` is the parent SKU (or, when no SKU is set, the parent post ID as a string).
- `variesBy` lists Schema.org property URLs (or short Text labels for unmapped attributes) for the axes that **actually** vary across variations — i.e. axes with more than one distinct non-empty value. If every variation shares the same color and only differs by size, only `size` appears in `variesBy`.
- Parent-level `offers` and `potentialAction` are dropped on conversion. Buyers can't purchase the parent of a variable product, and per Schema.org the concrete offers belong on the `hasVariant` Product entries.

**Per-variant shape (one entry per child variation):**

Each entry under `hasVariant` is itself a Product with:

- `@type: "Product"`, `@id` and `url` (variation permalink), `name` (WC's variation display name, e.g. `"Hoodie - Blue, Logo: Yes"`), `sku`, `image` (variation-specific when set; falls back to parent gallery image).
- **Inherited parent base fields** (`add_inherited_variant_fields()`): `description`, `brand`, and `category` are copied from the parent `ProductGroup`. The from-scratch variant rebuild would otherwise drop the WC-core base fields a simple product keeps — which made Google report every variant as having "no description" (#443). `description` prefers the variation's own (formatted identically to WC core via `wp_strip_all_tags( do_shortcode() )`) and falls back to the parent's. Every copy is `isset`/`empty`-guarded and never overwrites a value the variant already set.
- The typed Schema.org property for the variation's differentiating attribute (`color` / `size` / `material` / `pattern`) — same mapping as the parent's typed-property emission.
- An `offers` array (single-element) whose member carries `price`, `priceCurrency`, `availability`, `shippingDetails`, `hasMerchantReturnPolicy`, and `checkoutPageURLTemplate` (Schema.org `Offer` property — a URL template per [RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570) that points at the variation's checkout page). The offer also inherits `seller` from the parent (store-level) and a `url` (the variation permalink), plus `priceValidUntil` sourced **per-variant** from the variation's own sale-end date (`get_date_on_sale_to()`) — falling back to the parent's value (the store default for non-sale products) only when the variation has no sale window of its own. Inheriting the parent's date verbatim would misreport validity for a variation running its own sale (#443).
- A `potentialAction: BuyAction` whose `target.urlTemplate` points at the **variation ID** so an AI agent's deep-link resolves to that specific SKU instead of the parent's "choose your color" detour.

**Core-typed override (parent flag missing):**

WC's `get_variation_attributes()` is gated by the parent attribute's "Used for variations" flag — if a merchant configures variations with distinct `pa_color` / `pa_size` / `pa_material` / `pa_pattern` values but forgets to flag the parent attribute, that API silently returns empty. Per-variation postmeta (`attribute_<slug>`) is still populated, though. For these four core typed slugs only, the plugin reads variation postmeta directly via `read_variation_core_attributes()`. The override touches three layers:

1. **Axis detection** (`detect_varies_by()`): if at least two children have distinct non-empty values for a core slug, the axis qualifies as varying and the corresponding Schema.org URL appears in `variesBy`.
2. **Per-variant typed properties** (`add_variant_basics()`): each `hasVariant` entry's typed property (`color` / `size` / etc.) is filled from the same postmeta source so the variant data stays coherent with the advertised axis.
3. **Per-variant `@id` and `url`** (`add_variant_basics()`): WC's `WC_Product_Variation::get_permalink()` is itself gated by the same parent flag — when the gate evicts every variation attribute, it returns the bare parent URL instead of the parent + `?attribute_<slug>=value` query args. The plugin detects the fall-through (variation permalink === parent permalink) and synthesizes the URL from the same postmeta source, so each variant's `@id` carries its specific core-attribute value (e.g. `?attribute_pa_color=red`), distinct per-variant. Without this third-layer override, every variant on a misconfigured variable product collapses to the same `@id`, breaking variant-graph traversal for AI agents.

Override is intentionally limited to the four core typed slugs: they have canonical Schema.org typed-property mappings and AI agents look for them by name. Unmapped custom attributes (Style, Heel Height, Logo) honor the parent flag — getting them wrong only changes a Text label, and surfacing variation noise the merchant intentionally hid would over-step the override's narrow scope. Variants that differ ONLY by an unmapped attribute keep the bare parent URL.

**Misconfigured-variable fallback:**

When a variable product has variation children but no axis qualifies — neither `get_variation_attributes()` nor the core-typed override surfaces ≥2 distinct values — the plugin falls back to simple-Product emission. With no `variesBy` to advertise, a `hasVariant` block of N near-identical entries would just confuse agents. Better to emit a working single-SKU shape and let the merchant fix the variation config in the editor.

**Unpurchasable URL suppression** (#373):

When a variation or parent product reads `is_purchasable: false` in WC (typically missing a price, draft, catalog-hidden, or merchant-misconfigured), the JSON-LD emission **suppresses both `BuyAction` and `Offer.checkoutPageURLTemplate`** on that entry while keeping the descriptive fields (`@id`, `name`, `sku`, `image`, `offers[].price`). SEO crawlers and non-UCP agents that only read JSON-LD therefore don't receive a URL that WC would refuse at checkout — but they still see the product/variant exists. Same suppression applies to:

- A variant entry under `hasVariant[]` whose underlying variation is unpurchasable (descriptive fields emit; URLs drop).
- A simple or variable parent product that itself reads `is_purchasable: false` (the parent's own `BuyAction` + `checkoutPageURLTemplate` are skipped).

For variable parents, this is mostly defense-in-depth — `maybe_convert_to_product_group()` already drops the parent's `offers[]` and `potentialAction` when converting to ProductGroup. The per-variant gate in `build_variant_entry()` handles the in-list case.

**WC core type-allow-list registration:**

`WC_Structured_Data::get_structured_data()` keys the generated markup by `strtolower($value['@type'])` and intersects with the per-page allow-list returned by `get_data_type_for_page()` — which only ships `product`, `breadcrumblist`, `review`, `order`. The plugin hooks `woocommerce_structured_data_type_for_page` on single-product pages to add `productgroup` to that list; without the registration the entire ProductGroup block is silently dropped at output time.

Worked example (V-Neck T-Shirt with 3 variations across color and size):

```jsonc
{
  "@type": "ProductGroup",
  "productGroupID": "woo-vneck-tee",
  "name": "V-Neck T-Shirt",
  "variesBy": [
    "https://schema.org/color",
    "https://schema.org/size"
  ],
  "hasVariant": [
    {
      "@type": "Product",
      "@id": "https://example.com/product/v-neck-t-shirt/?attribute_pa_color=red&attribute_pa_size=s",
      "url": "https://example.com/product/v-neck-t-shirt/?attribute_pa_color=red&attribute_pa_size=s",
      "name": "V-Neck T-Shirt - Red, Small",
      "sku": "woo-vneck-tee-red-s",
      "color": "Red",
      "size": "Small",
      "offers": [
        {
          "@type": "Offer",
          "price": "20",
          "priceCurrency": "USD",
          "availability": "https://schema.org/InStock",
          "checkoutPageURLTemplate": "https://example.com/checkout-link/?products=43:1&utm_source={agent_id}&..."
        }
      ],
      "potentialAction": {
        "@type": "BuyAction",
        "target": { "@type": "EntryPoint", "urlTemplate": "...products=43:1..." }
      }
    }
    // ...other variations
  ]
}
```

Implementation: [`maybe_convert_to_product_group()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) (parent-level rewrite) and [`build_variant_entry()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) (per-variant block). Type registration: [`allow_product_group_type()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php).

### `isRelatedTo` and `isSimilarTo` (cross-sells / upsells)

Schema.org pointers to other products on the same store. AI agents use these as the product graph for "people also bought" / "similar items" reasoning.

- [`isRelatedTo`](https://schema.org/isRelatedTo) — *"a pointer to another, somehow related product."* Sourced from WC **cross-sells** (`get_cross_sell_ids()`) — the cart-page complementary purchases the merchant configured.
- [`isSimilarTo`](https://schema.org/isSimilarTo) — *"a pointer to another, functionally similar product."* Sourced from WC **upsells** (`get_upsell_ids()`) — the premium / alternate version of the same item.

Each entry is a Schema.org `@id` reference, not a full Product block:

```jsonc
"isRelatedTo": [
  { "@id": "https://example.com/product/coat/" },
  { "@id": "https://example.com/product/scarf/" }
]
```

Reference-only emission keeps the markup compact — agents dereference `@id` to fetch the linked product's own structured-data block. Full Product blocks would 5×+ the page weight on stores with rich cross-sell graphs.

**Three guards apply to every entry**:

1. **Visibility consistency** — IDs that fail [`is_product_syndicated()`](../../includes/class-wc-ai-storefront.php) are dropped, so excluded products aren't reachable via graph traversal either. Honors `selected` / `by_taxonomy` modes the same way the per-product gate does.
2. **Deleted-product skip** — `wc_get_product()` returns `false` for trashed/deleted IDs; we drop those silently. WC doesn't auto-prune stale cross-sell IDs when a referenced product is deleted, so this case is common on long-lived stores.
3. **Hard cap of 10 entries per property** — a merchant with 100 cross-sells gets the first 10 (in the order WC returned them). The cap is a private constant `MAX_RELATED_PRODUCT_REFS`, not a filter; agents need a few signal-rich pointers, not an exhaustive list.

**Existing-key preservation**: if `$markup` already carries `isRelatedTo` or `isSimilarTo` (set by WC core or another plugin's filter at higher priority), defer — same pattern as the typed-property emission for `color`/`size`/`material`/`pattern`.

The references survive the `ProductGroup` conversion: `add_related_products()` runs before `maybe_convert_to_product_group()`, and Schema.org's `ProductGroup` is a `Product` subtype where both properties are valid.

Implementation: [`add_related_products()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php).

### `additionalProperty` (attributes)

Array of `PropertyValue` entries for product attributes that aren't represented as typed Schema.org properties on this pass.

- **Emitted when** an attribute is visible (`WC_Product_Attribute::get_visible()`), not variation-defining, and **either**:
  - (a) doesn't map to a typed Schema.org property (e.g. `Style`, `Heel Height`, `Origin`), OR
  - (b) maps to one but typed emission was skipped or deferred — multi-value inputs and upstream-owned typed keys both fall through here so the merchant's data still reaches agents.
- **Excluded**: variation-defining attributes (intentionally omitted from the parent — they describe variants, with per-variant emission tracked in [#328](https://github.com/Automattic/woocommerce-ai-storefront/issues/328)), and attributes whose typed Schema.org property was emitted by this plugin in the current pass (no double-emit).
- **Merge semantics**: existing `additionalProperty` entries from WC core or upstream filters are preserved. The plugin's emissions are appended to whatever already exists, with single-value upstream entries normalized to array form first.

### `offers[0].priceCurrency`

ISO 4217 currency code, normalized.

- **Always emitted**. Inherits from WC core when present; otherwise plugin synthesizes from store settings.
- **Defensive**: covers a WC core edge case where nested `priceSpecification[0].priceCurrency` was set but top-level `priceCurrency` wasn't.

**Per-page currency reflection (WooPayments multi-currency).** When a crawler fetches a single-product page with a `?currency=XXX` query parameter, WooPayments' multi-currency feature switches `get_woocommerce_currency()` for that request *before* the JSON-LD enricher runs. As a result, every `priceCurrency` field on the page's Product JSON-LD (including the variant-level Offer skeletons under `hasVariant[i].offers[0]` and the subscription `priceSpecification` entries) reflects `XXX`, and every `price` reflects the converted amount. This is a free behavior — no plugin code change required. Crawlers that need a multi-currency index can fetch each product URL once per code in `currenciesAccepted` to build the full matrix.

This does NOT apply to the homepage `OnlineBusiness.currenciesAccepted` field (a store-wide list, not a per-quote currency), the UCP manifest (a discovery file served outside the storefront page render), or UCP REST responses (the `/wp-json/wc/ucp/v1/...` path does not traverse WooPayments' page-level `?currency=` handler — that's Phase 2).

### `offers[0].seller`

WooCommerce core emits `seller` as `{ "@type": "Organization", "name": "...", "url": "..." }`. The plugin does not change the `@type`.

**Why `Organization` and not `OnlineStore`?**

Schema.org allows `Person` or `Organization` (and any `Organization` subtype) as the `seller` value. `OnlineStore` is an `Organization` subtype and would be strictly more accurate — it is the same entity described in the store-level JSON-LD block. However, this field is owned by WC core's output pipeline; the plugin only post-processes `seller.name` (entity decoding, see below). Changing `@type` here would require overriding a core field that core may update independently.

The current stance is to defer to core's `Organization` and leave `OnlineStore` refinement for a future pass — possibly when core itself adopts a more specific type, or when we have a clear signal from Google/schema.org validators that the `seller` subtype materially affects rich results. The `wc_ai_storefront_jsonld_product` filter is available for extensions that want to override it today.

#### `offers[0].seller.name` — entity decoding

- **Modification, not addition**: WC core sets this; the plugin double-decodes any HTML entities to ensure correct rendering when the store name contains characters like `'`, `&`, `<`.

### `offers[0].shippingDetails`

`OfferShippingDetails` declaring the shipping destination country, free-shipping rate, and handling time.

- **Emitted when** the WC base country is set in WooCommerce settings.
- **Country source**: `wc_get_base_location()`.

#### `offers[0].shippingDetails.shippingRate`

Schema.org `MonetaryAmount` emitted when unconditional free shipping is available for the store's base country.

- **Emitted when** a WooCommerce shipping zone covers the base country and contains a free-shipping method with no minimum order amount.
- **Value**: always `{ "@type": "MonetaryAmount", "value": 0, "currency": "<store currency>" }`.
- **Not emitted** when free shipping requires a coupon or minimum spend — those are conditional, not unconditional.
- **Source**: `add_shipping_details()` → `has_free_shipping_for_country()`. Result is per-request cached keyed by country code.

#### `offers[0].shippingDetails.deliveryTime.handlingTime`

Schema.org `ShippingDeliveryTime` → `QuantitativeValue` emitted when the merchant has configured handling time on the Policies tab.

- **Emitted when** Policies tab → Shipping → Minimum > 0 AND Maximum > 0 AND min ≤ max.
- **Value**: `{ "@type": "QuantitativeValue", "minValue": <min>, "maxValue": <max>, "unitCode": "DAY" }`.
- **Guard**: invalid pairs stored in the database (e.g. `{min:5, max:2}`) are silently skipped — the block is omitted rather than emitting broken structured data.
- **Source**: `add_handling_time()`. Sanitization is enforced by `WC_AI_Storefront_Handling_Time::sanitize()` at save time (0–365 clamp, max raised to min when below).

### `offers[0].priceSpecification`, `offers[0].addOn`, `offers[0].eligibleDuration` (WC Subscriptions)

When WC Subscriptions is active and the product is a recurring-billing product, the plugin emits Schema.org subscription-billing signals on the Offer (#368). A subscription product's JSON-LD looks different from a one-shot purchase: AI agents and rich-result consumers can learn "this is a $10/month subscription with a 14-day free trial and a $5 sign-up fee" from the structured data alone, without needing a separate subscription discovery flow.

**Gating** (fail-closed): every emission path requires `function_exists('wcs_is_subscription')` + `class_exists('WC_Subscriptions_Product', false)` + `is_subscription( $product )` to pass. Stores without WC Subscriptions installed get unchanged JSON-LD output.

**Emitted fields:**

- **Recurring price** — `priceSpecification: [UnitPriceSpecification, …]` carrying `priceComponentType: https://schema.org/Subscription` and an ISO 8601 `billingDuration` (e.g. `P1M` for monthly, `P1Y` for annual, `P3M` for quarterly). Period and interval come from `WC_Subscriptions_Product::get_period()` + `get_interval()`.
- **Free trial** (when set) — expressed as a two-element `priceSpecification` array: the first entry at `price: 0` with `billingDuration` set to the trial window, the recurring entry second at full price. Array position + `price: 0` convey the trial-then-paid sequence; the plugin deliberately does NOT emit `billingStart` on the recurring entry because Schema.org types `billingStart` as `Number` (not Duration), so emitting an ISO 8601 string there would violate the spec contract. (See https://schema.org/billingStart.)
- **Sign-up fee** (when set) — emitted as **both** an inline `UnitPriceSpecification` entry with `priceComponentType: https://schema.org/ActivationFee` AND a separate `Offer.addOn` block. Schema.org's `ActivationFee` page carries the disclaimer "This term is in the 'new' area," so `addOn` (released vocabulary) covers consumers that haven't adopted the enum yet. Duplication is intentional and spec-legal.
- **Finite-length subscriptions** (`get_length() > 0`) — emit `Offer.eligibleDuration` as a `QuantitativeValue` with UN/CEFACT `unitCode` (`DAY` / `WEE` / `MON` / `ANN` per Recommendation N°20). Indefinite subscriptions omit the field.
- **Per-variation emission** — `variable-subscription` parents emit per-variation `priceSpecification` blocks under each `hasVariant[i].offers[0]` because `subscription_variation` children can have different billing periods (one variant monthly, another yearly).

**What's intentionally NOT emitted:**

- **`variesBy` override** — Schema.org has no normalized vocabulary for naming the subscription-duration variant axis. `Product.variesBy` accepts plain text or `DefinedTerm`; we pass through the merchant's WC attribute label (e.g. "Length", "Term", "Plan") as-is rather than overriding to an invented term. Machine-readable subscription semantics live on each variation's `priceSpecification`, not on the parent's `variesBy` label.
- **`billingStart`** — see free-trial note above. Array-position + `price: 0` is the spec-legal substitute.

**Source**: `add_subscription_signals()` (line ~283), invoked from `enhance_product_data()` after `add_currency()` runs (so `priceCurrency` is hoisted before the enricher reads it) and from `build_variant_entry()` for per-variation emission. Two pure helpers (`period_to_iso8601_duration`, `period_to_uncefact_code`) handle the unit-encoding mappings.

### `offers[0].hasMerchantReturnPolicy`

Schema.org `MerchantReturnPolicy` describing return rules.

- **Emitted when** Policies tab → Returns mode is "Returns accepted" or "Final sale" AND the WC base country is set.
- **Per-product override**: if the product has the "AI: Final sale" checkbox enabled in its Inventory tab, the merchant return policy block reflects final-sale terms regardless of the store-wide setting.
- **Schema-validated values**:
  - `returnPolicyCategory`: `MerchantReturnFiniteReturnWindow` (returns accepted) or `MerchantReturnNotPermitted` (final sale).
  - `returnFees`: from a Schema.org allow-list (`FreeReturn`, `ReturnFeesCustomerResponsibility`, etc.). Invalid values are dropped at emit time.
  - `returnMethod`: same allow-list defense (`ReturnByMail`, `ReturnInStore`, `ReturnAtKiosk`).
- **Source**: `add_return_policy()` (line ~356) and `build_return_policy_block()` (line ~559).

## Store homepage: OnlineBusiness schema

A separate JSON-LD block emitted on the front page or shop page (`is_front_page() || is_shop()`) when the plugin is enabled.

The `@type` is [`OnlineBusiness`](https://schema.org/OnlineBusiness) — a Schema.org `Organization` subtype that covers the breadth of WC's actual install base: traditional retail, services (WooCommerce Bookings, consultancies), subscriptions, donations, lead-gen, digital downloads. Until [issue #334](https://github.com/Automattic/woocommerce-ai-storefront/issues/334) (resolved) the plugin emitted [`OnlineStore`](https://schema.org/OnlineStore), a sub-subtype defined as "an eCommerce site"; that was too narrow for the install-base reality. `OnlineBusiness` is the parent in `Thing → Organization → OnlineBusiness → OnlineStore` and inherits all the descriptive fields (`name`, `url`, `description`, `potentialAction`, `hasOfferCatalog`) from `Organization`. We continue to emit `currenciesAccepted` even though Schema.org scopes it to the `OnlineStore` subtype — that's an intentional non-domain pairing, not a spec-blessed inheritance (Schema.org properties only flow parent → child). See [SCHEMA-ORG-COVERAGE.md](./SCHEMA-ORG-COVERAGE.md#why-onlinebusiness-and-not-onlinestore) for the rationale.

> **Cross-reference:** The `/llms.txt` `## Store`, `## Catalog`, and `## Shipping & Returns` sections (issue #398) source their data from the same JsonLd helpers that build this homepage block — `build_identity_fields()`, `build_postal_address()`, `get_catalog_summary()`. One render cycle, two surfaces. A change to any of those helpers propagates to both the homepage JSON-LD and llms.txt in the same release. `## Structured data` in llms.txt is a one-line signpost back at this document for agents wanting the full structured payload.

```jsonc
{
  "@context": "https://schema.org",
  "@type": "OnlineBusiness",
  "name": "Your Store",
  "description": "Your store's tagline / blog description",
  "url": "https://yourstore.example.com/",
  "currenciesAccepted": "USD",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "https://yourstore.example.com/?s={search_term}&post_type=product&utm_source={agent_id}&utm_medium=referral&utm_id=woo_jsonld"
    },
    "query-input": "required name=search_term"
  },
  "hasOfferCatalog": {
    "@type": "OfferCatalog",
    "name": "Products",
    "itemListElement": [
      // Top-level categories with product counts; built by get_catalog_summary().
      // Empty categories (zero exposed products) are omitted.
    ]
  },
  "knowsAbout": ["Clothing", "Hoodies", "T-Shirts" /* up to 10 category names */],

  // Identity fields (since 0.10.0). Each is omit-when-empty: a merchant
  // who has no logo, no WC base country, and no usable email gets none of
  // these keys.
  "logo": "https://yourstore.example.com/wp-content/uploads/.../brand.png",
  "address": {
    "@type": "PostalAddress",
    "addressCountry": "US",
    "addressLocality": "Springfield",
    "addressRegion":   "IL",
    "postalCode":      "62701"
    // Note: streetAddress is intentionally NEVER emitted. See "Identity
    // field sourcing" below.
  },
  "contactPoint": {
    "@type":       "ContactPoint",
    "contactType": "Customer Service",
    "email":       "support@yourstore.example.com"
  },

  // Organization-level return policy (since #337 phase 1). Always emitted
  // when configured; per-Offer emission on product pages remains the
  // per-product override surface.
  "hasMerchantReturnPolicy": {
    "@type": "MerchantReturnPolicy",
    "applicableCountry": "US",
    "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
    "merchantReturnDays": 30,
    "returnFees": "https://schema.org/FreeReturn"
  }
}
```

### `currenciesAccepted` (homepage)

Space-separated string of ISO-4217 currency codes (Schema.org convention). Single-currency stores emit one code; multi-currency stores emit the full accepted set with the base currency first (since 0.17.0).

```jsonc
// Single-currency store:
"currenciesAccepted": "USD"

// WooPayments multi-currency store (base USD, with EUR + GBP enabled):
"currenciesAccepted": "USD EUR GBP"
```

- **Emitted when**: plugin is enabled and on `is_front_page() || is_shop()`.
- **Format**: Schema.org's `Organization.currenciesAccepted` is a single `Text` field; the multi-currency convention is space-separation per the Schema.org "Use the [currenciesAccepted](https://schema.org/currenciesAccepted) property and pass the values as a list of ISO 4217 codes" guidance. Validators accept either shape.
- **Source**: [`WC_AI_Storefront_Multi_Currency::get_accepted_currencies()`](../../includes/ai-storefront/class-wc-ai-storefront-multi-currency.php) — reads the WooPayments multi-currency enabled set when the soft dependency is present, otherwise falls back to `[ base_currency ]`. Extensible via the `wc_ai_storefront_accepted_currencies` filter (see [`HOOKS.md`](HOOKS.md#wc_ai_storefront_accepted_currencies)).
- **Schema.org caveat**: `currenciesAccepted` is scoped to the `OnlineStore` subtype; we emit it on the `OnlineBusiness` parent for the same intentional non-domain-pairing rationale described in the parent section.

### `hasOfferCatalog` (homepage / shop)

Schema.org's "what this organization sells" pointer, emitted on the homepage `OnlineBusiness` block as a structured summary of the storefront's catalog. Lets AI agents and search crawlers learn the store's category structure without crawling individual product pages.

```jsonc
{
  "@type": "OnlineBusiness",
  "hasOfferCatalog": {
    "@type": "OfferCatalog",
    "name": "Products",
    "itemListElement": [
      { "@type": "OfferCatalog", "name": "Clothing", "numberOfItems": 25, "url": "..." },
      { "@type": "OfferCatalog", "name": "Hoodies",  "numberOfItems": 8,  "url": "..." },
      // top 10 root categories, ordered by product count
    ]
  }
}
```

- **Emitted when**: plugin is enabled and on `is_front_page() || is_shop()`.
- **Source**: top 10 root `product_cat` categories (`hide_empty: true`, ordered by product count DESC), pulled by [`get_catalog_summary()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php). Subcategories are not recursed.
- **Per-category fields**: nested `OfferCatalog` with `name`, `numberOfItems`, and `url` (term archive link).
- **Cache**: 1-hour transient (`wc_ai_storefront_catalog_summary`); product/category changes don't propagate immediately. Invalidated by `WC_AI_Storefront_Cache_Invalidator` when relevant terms change.

### `knowsAbout` (homepage)

Schema.org [`Organization.knowsAbout`](https://schema.org/knowsAbout) — a Text array of topics this organization knows about. Tells AI agents what the store specializes in without forcing them to crawl every product page.

```jsonc
"knowsAbout": ["Clothing", "Hoodies", "T-Shirts", "Accessories"]
```

- **Emitted when**: catalog is non-empty. Sourced from the same `get_catalog_summary()` data that drives `hasOfferCatalog` — the call is hoisted to a local variable so both consumers share one cache hit per page render.
- **Values**: top 10 root product category names, in the order `get_catalog_summary()` returns them (by product count DESC).
- **Omitted when**: catalog is empty — no point claiming the org "knows about" nothing.
- **Cache**: same 1-hour transient as `hasOfferCatalog` (no separate cache).

### `hasMerchantReturnPolicy` (Organization-level)

Same `MerchantReturnPolicy` block shape that the per-Offer emission produces, but at the Organization level — the canonical store-wide commitment.

- **Emitted when**: a return policy is configured (`mode !== 'unconfigured'`). For `mode: returns_accepted` the WC base country is also required (a return-window declaration without a target region is useless to validators). For `mode: final_sale` the country is optional — "no returns" is globally meaningful, so the block emits with or without `applicableCountry`. Always additive in this PR — the per-Offer emission on product pages is unchanged.
- **Shared builder**: both this call site and `add_return_policy()` (for per-Offer emission) call `build_return_policy_block($policy, $country, $product_id)`. Org-level emission passes `null` for `$product_id` (no per-product context). The shared builder guarantees the two emissions produce identical block shapes for the same configuration.
- **Coexists with per-Offer emission**: today both Org-level and per-Offer emissions ship together. Per-Offer is already override-aware — it emits a `MerchantReturnNotPermitted` block when the product's final-sale flag is set, and otherwise emits the same store-wide policy as Org-level. The redundancy is intentional defensive emission: Schema.org consumers that don't implement the Org-level → Offer-level inheritance correctly still get the right answer on a product page. Skipping the per-Offer block when it duplicates Org-level (sometimes called "phase 2" in earlier discussions) was considered and ruled out — the markup-size win is marginal and the backward-compat risk for non-spec-compliant consumers is real.
- **Source**: `output_store_jsonld()` for the Org-level call site; `build_return_policy_block()` (now `protected`) for the shared block builder.

### Identity field sourcing

All three identity fields are auto-sourced from existing WP/WC data. There are **no plugin-owned settings** for these — the plugin reads what's already configured at the platform level.

| Field | Source | Omit-when-empty rule |
|-------|--------|----------------------|
| `logo` | WP custom-logo theme mod (`get_theme_mod( 'custom_logo' )` → resolved via `wp_get_attachment_image_src`), with `get_site_icon_url()` as fallback. | Omitted when neither is set. Avoids publishing the default WP favicon as a brand mark. |
| `address.addressCountry` | `WC()->countries->get_base_country()`. | The whole `address` block is omitted when country is empty (the minimum viable address signal). |
| `address.addressLocality` / `addressRegion` / `postalCode` | `WC()->countries->get_base_city()` / `get_base_state()` / `get_base_postcode()`. | Each sub-key is omitted when WC has no value. |
| `address.streetAddress` | **NEVER emitted.** Not even when WC has the street address populated (`get_base_address()` / `get_base_address_2()`). | See "Why streetAddress is suppressed" below. |
| `contactPoint.email` | Two-stage: (1) `woocommerce_email_reply_to_address` when `woocommerce_email_reply_to_enabled === 'yes'`, (2) `woocommerce_email_from_address` as fallback (rejected when local-part is a noreply pattern). | The whole `contactPoint` block is omitted when neither stage produces a usable address. **Never** falls back to `admin_email`. |

### Why streetAddress is suppressed

For an `OnlineBusiness` (vs. a `LocalBusiness`), street address has low signal value: buyers transact remotely and don't visit. But the privacy/safety risk is real — many small Woo merchants populate WooCommerce > Settings > General with their home address (the field is required at WC setup so tax calculations work) and don't realize that saving it would publish the address in machine-readable form on the homepage's JSON-LD. By emitting only `addressLocality`, `addressRegion`, `postalCode`, and `addressCountry`, we preserve every meaningful identity signal (jurisdiction, shipping origin, fraud-check disambiguation) without leaking a residential address. `build_postal_address()` in the emitter doesn't even read `get_base_address()` or `get_base_address_2()` — even a future filter that wants to re-emit street would have to source it independently.

### Why the email resolver has a noreply guard

WC's "From" address is often set to `noreply@store.com` to avoid bounce-handling on outgoing transactional emails. Publishing that as `contactPoint.email` would route legitimate customer questions into a black hole. The `is_noreply_email()` heuristic matches the four canonical noreply local-parts (`noreply`, `no-reply`, `donotreply`, `do-not-reply`) case-insensitively, including their RFC 5233 plus-addressing variants (`noreply+orders@…`, `do-not-reply+billing@…`) which route to the same underlying mailbox at most providers. Local-part-only matching prevents false positives on legitimate mailboxes hosted on a `noreply.*` subdomain (e.g. `support@noreply.example.com` stays publishable). The guard only applies to the From-address fallback path; the merchant's explicit reply-to address (when enabled in WC settings) is trusted as-is.

### What this plugin does NOT emit

- **`sameAs`** (social profile URLs). WC has no canonical storage for these; ecosystem plugins (Jetpack Social, Yoast Knowledge Graph, etc.) own the merchant capture and can inject via the `wc_ai_storefront_jsonld_store` filter. See [`HOOKS.md`](HOOKS.md) for a worked example.
- **`contactPoint.telephone`**. Same reason: WC has no phone option, so the plugin can't auto-source. Plugins that capture a merchant phone number can inject via the same filter.

The `hasOfferCatalog.itemListElement` is built by `get_catalog_summary()` and respects the plugin's product visibility setting — categories with zero exposed products are omitted.

## Every page: `WebSite` + `SearchAction` schema

Emitted on **every** front-end page (priority 4, before the homepage block) by `output_website_jsonld()`. It advertises the store's two search entry points so an agent that lands on any page — not just the homepage — can discover how to search without following a discovery link. The block is cached site-wide in a 1-hour transient (`WEBSITE_JSONLD_CACHE_KEY`); its content depends only on the store URL and settings, not on the current page or user.

```jsonc
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "url": "https://your-store.com/",
  "potentialAction": [
    {
      "@type": "SearchAction",
      "target": {
        "@type": "EntryPoint",
        "urlTemplate": "https://your-store.com/?s={search_term}&post_type=product"
      },
      "query-input": "required name=search_term"
    },
    {
      "@type": "SearchAction",
      "name": "Product catalog API",
      "target": {
        "@type": "EntryPoint",
        "urlTemplate": "https://your-store.com/wp-json/wc/ucp/v1/catalog/search?q={search_term}"
      },
      "query-input": "required name=search_term"
    }
  ]
}
```

- **First action** — the native WP product search results page (HTML). This is the `SearchAction` shape Google exercises for sitelinks search boxes, with `{search_term}` filled in at query time.
- **Second action** — the public [`GET /catalog/search`](API-REFERENCE.md#get-catalogsearch) REST endpoint (JSON). Points agents at the machine-readable surface the same way the OpenSearch descriptor and the manifest do.
- **REST URL** uses `rest_url()`, so it resolves correctly under both pretty-permalink and `?rest_route=` configurations.
- **`wc_ai_storefront_jsonld_website` filter** — return `false`/`null` to suppress the block entirely (e.g. when Yoast or Rank Math already emits a `WebSite` block), preventing duplication. The filter runs before the result is cached. See [`HOOKS.md`](HOOKS.md).

## Archive pages: `ItemList` schema

Emitted on shop / category / tag / product-search archive pages (priority 6, after the homepage block) by `output_archive_itemlist_jsonld()`. Each `itemListElement` carries a `ListItem` wrapping an inline `Product` stub — enough for an agent to present results without following each product URL. Full `Product` enrichment (BuyAction, attributes, shipping, returns) stays on the single-product page.

```jsonc
{
  "@context": "https://schema.org",
  "@type": "ItemList",
  "name": "Hoodies",
  "numberOfItems": 42,
  "url": "https://your-store.com/product-category/hoodies/",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Classic Hoodie",
      "url": "https://your-store.com/product/classic-hoodie/",
      "item": {
        "@type": "Product",
        "name": "Classic Hoodie",
        "url": "https://your-store.com/product/classic-hoodie/",
        "sku": "HOOD-001",
        "image": "https://your-store.com/wp-content/uploads/classic-hoodie.jpg",
        "offers": {
          "@type": "Offer",
          "price": "39.00",
          "priceCurrency": "USD",
          "availability": "https://schema.org/InStock"
        }
      }
    }
  ]
}
```

**Firing contexts** (each `wp_head` render checks these in order):

| Context | Condition | `name` | `url` |
|---------|-----------|--------|-------|
| Shop front | `is_shop() && ! is_front_page()` | Site name | Shop page permalink |
| Category archive | `is_product_category()` | Term name | Term link |
| Tag archive | `is_product_tag()` | Term name | Term link |
| Product search | `is_search() && 'product' === get_query_var('post_type')` | Search query | `/?s=<query>&post_type=product` |

The product-search context keys on the searched **post type**, not `is_woocommerce()` — the latter is `is_shop() || is_product_taxonomy() || is_product()`, all false on a search results page, so gating on it would make the search block never fire.

**`numberOfItems` honesty.** The field is emitted **only when the merchant's visibility mode is `all`**. In that mode every queried product is syndicated, so the catalog-wide count (`term->count` → `$wp_query->found_posts` → a `wc_get_products` count query, in that fallback order) equals the visible list. In `selected` / `by_taxonomy` mode the `itemListElement` is a syndication-filtered subset with no cheap accurate count, so `numberOfItems` is **omitted** rather than published as an inflated total that would mislead an agent paginating on it (and would disclose the non-syndicated count). Skipped products are filtered before positions are assigned, so the surviving items always carry contiguous `position` values.

**Pagination.** On paged archives, `position` starts at `((paged - 1) * effective_page) + 1` where `effective_page = min(posts_per_page, 100)` matches the query's clamped page size, so item positions stay globally correct across pages.

**Caching.** Shop / category / tag blocks are cached per page view in 1-hour transients keyed `ITEMLIST_JSONLD_CACHE_PREFIX . <type>_<term-id|page>` (e.g. `wc_ai_storefront_itemlist_cat_7_1`). **Search pages are deliberately not cached** (no read, no write): a search transient key is `…_search_<md5(query)>_<page>`, whose cardinality is bounded only by the distinct `?s=` values an unauthenticated visitor supplies — caching each would flood `wp_options`. Search pages recompute cheaply instead. All cached blocks are purged by [`WC_AI_Storefront_Cache_Invalidator`](../../includes/ai-storefront/class-wc-ai-storefront-cache-invalidator.php) on any product or term change via a `LIKE` wildcard on the shared prefix; see [`DATA-MODEL.md`](DATA-MODEL.md).

**Output safety.** Both blocks encode with `wp_json_encode( …, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | … )` so a `</script>` sequence in a product field can't break out of the inline `<script>`. If encoding returns `false` (e.g. malformed UTF-8 in a product name), the block is suppressed entirely rather than emitted as an empty, invalid `ld+json` island — and the un-encodable payload is never written to the cache.

**WooCommerce serializer interception.** WooCommerce's own `WC_Structured_Data::output_structured_data()` callback — hooked to `wp_footer` at priority 10 — passes the JSON string through `wc_esc_json()`, which calls `_wp_specialchars` with `ENT_NOQUOTES` and converts every `&` to `&amp;`. This happens *after* all PHP data filters run, so there is no hook available to clean the output. The result is that `checkoutPageURLTemplate`, `BuyAction.urlTemplate`, and variation `@id` URLs all contain literal `&amp;` entities in the raw HTTP response — breaking `curl`, Python `requests`, and LLM tool calls that don't HTML-decode JSON.

To avoid this, the plugin replaces WC's callback on `woocommerce_init` (fired at the end of `WC::init()`, after `WC()->structured_data` is instantiated) via `replace_wc_structured_data_output()`. The replacement callback, `output_wc_structured_data()`, calls `wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES )` directly — skipping `wc_esc_json` entirely. `JSON_HEX_AMP` encodes `&` as `&`, which is safe in `<script>` contexts and does not alter the decoded string value for any JSON consumer. Email order details are unaffected: `output_email_structured_data()` calls `output_structured_data()` directly, not via the `wp_footer` hook.

## Public filters

The blocks expose filters for theme and extension authors to customize:

- `wc_ai_storefront_jsonld_product` -- runs at the end of `enhance_product_data()`. Receives the enhanced markup, the WC_Product, and a minimal safe subset of plugin settings (`enabled`, `product_selection_mode`, `return_policy`). Security-sensitive fields (rate limits, crawler allow-lists) are intentionally excluded.
- `wc_ai_storefront_jsonld_store` -- runs at the end of `output_store_jsonld()`. Same safe-subset settings.
- `wc_ai_storefront_jsonld_website` -- runs in `output_website_jsonld()` before the block is cached and output. Receives the `WebSite` data array. Return a modified array to customize, or `false`/`null` to suppress the block entirely (e.g. when another SEO plugin already emits a `WebSite` block).

The `_product` and `_store` filters return arrays; returning a partial array replaces only those keys. The `_website` filter additionally treats an empty/falsy return as "suppress this block". See [`HOOKS.md`](HOOKS.md) for signatures.

## Validation

Three external tools cover this output:

- [Schema.org validator](https://validator.schema.org/) -- accepts a URL or pasted JSON-LD; reports type-shape errors.
- [Google's Rich Results Test](https://search.google.com/test/rich-results) -- validates Product structured data specifically; warns on missing fields Google considers important for rich results (price, availability, image).
- [Schema Markup Validator](https://validator.schema.org/) -- the canonical Schema.org-side checker.

For automated checks, the plugin's PHPUnit suite covers:

- `JsonLdTest.php` -- per-method coverage of `enhance_product_data`, `add_buy_action`, `add_inventory_level`, `add_dimensions`, etc.
- `JsonLdReturnPolicyTest.php` -- Schema.org allow-list enforcement on `returnFees` / `returnMethod`.
- `JsonLdNormalizationTest.php` -- HTML entity decoding, currency normalization, edge cases.

## What this plugin deliberately does not emit

For reference, fields that a JSON-LD reader might expect but aren't part of this plugin's enhancement:

- **`aggregateRating` / `review`** -- inherited from WC core if present (e.g. via WooCommerce's reviews feature); plugin doesn't add or modify.
- **`audience`, `eligibleRegion`** -- not modeled. AI agents that need region/audience scoping should infer from `shippingDetails.shippingDestination`.
- **Per-variation top-level JSON-LD** -- variations don't get their own top-level Product `<script>` block when the buyer lands on a variation permalink. The plugin emits per-variation Product blocks under `hasVariant` on the parent's `ProductGroup` shape (see [`ProductGroup` / `hasVariant` / `variesBy`](#productgroup--hasvariant--variesby-variable-products) above) so AI agents can deep-link to the specific SKU; rendering those same blocks again as standalone top-level markup on each variation URL would duplicate data.

These omissions are deliberate: the plugin's scope is "AI-shopping discovery essentials," not full Schema.org coverage. Extensions can fill these via the `wc_ai_storefront_jsonld_product` filter.

## Reference

- [`includes/ai-storefront/class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) -- the source of truth for emitted shapes.
- [`HOOKS.md`](HOOKS.md) -- full filter signatures for `wc_ai_storefront_jsonld_product` and `wc_ai_storefront_jsonld_store`.
- [Schema.org Product](https://schema.org/Product) -- canonical type reference.
- [Schema.org BuyAction](https://schema.org/BuyAction), [InventoryLevel](https://schema.org/inventoryLevel), [MerchantReturnPolicy](https://schema.org/MerchantReturnPolicy) -- spec for the headline enhancements.
