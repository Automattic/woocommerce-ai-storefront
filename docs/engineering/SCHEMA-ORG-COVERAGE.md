# Schema.org Coverage Audit

> Last reviewed: 2026-08-14 (post-[#614](https://github.com/Automattic/woocommerce-ai-storefront/issues/614)/[#615](https://github.com/Automattic/woocommerce-ai-storefront/issues/615) shipping-level and variant dimensions)

## What changed since the last review (2026-08-13)

- **`OfferShippingDetails.weight`/`.depth`/`.width`/`.height` now emitted** (#614): the same `QuantitativeValue` blocks already published at `Product` level now also appear under `offers[0].shippingDetails`, from a shared `build_dimension_blocks()` builder so the two placements can't drift. Schema.org distinguishes item size (`Product`) from parcel size (`OfferShippingDetails`), matching Google's `product_*`/`shipping_*` split; WooCommerce has one set of dimension fields — its own shipping methods already consume them to compute rates — so populating both from that one set is faithful to what the data means in WooCommerce. Deliberately does not gate on `wc_product_weight_enabled()`/`wc_product_dimensions_enabled()` — see [JSON-LD-SCHEMA.md §dimensions](./JSON-LD-SCHEMA.md#weight-depth-width-height-dimensions).
- **`hasVariant[].weight`/`.depth`/`.width`/`.height` now emitted** (#615): each variant Product entry carries its own resolved dimensions — its own value if set, the parent's otherwise — via WooCommerce's own getter inheritance (`WC_Product_Variation` falling back to `parent_data`), not logic this plugin wrote. Emits on every variant including inherited values, so a consumer reading one `hasVariant` node in isolation gets a self-contained answer. See [JSON-LD-SCHEMA.md §hasVariant](./JSON-LD-SCHEMA.md#productgroup--hasvariant--variesby-variable-products).

## What changed in the review before that (2026-07-10)

- **`Product.audience` now emitted** (#618): `emit_attributes()` resolves the merchant's Gender and Age group attributes (`pa_gender`/`pa_age_group`, or a bare `gender`/`age_group` custom attribute as a compatibility fallback) into a `PeopleAudience` block — `suggestedGender` passes through any non-empty value, normalising only a recognised match to lowercase; `suggestedAge` maps a recognised bucket to a `QuantitativeValue`. An unrecognised age-group value falls back to `additionalProperty` since there's no honest number to emit for it. Variants resolve and inherit the block per Schema.org sub-property, and `variesBy` advertises `suggestedGender`/`suggestedAge` when that's the varying axis. See [JSON-LD-SCHEMA.md §audience](./JSON-LD-SCHEMA.md#audience-gender-and-age-group--peopleaudience).

## What changed in the review before that (2026-06-22)

- **`Offer.validFrom` / `Offer.validThrough` now emitted** (#582): `add_sale_window()` emits the sale window as full ISO 8601 with the store timezone offset when a product is on sale with a configured WooCommerce sale schedule. Each field is independent (open-ended sales supported), skipped on `AggregateOffer`, and per-variant windows use each variation's own schedule with no parent fallback. Complements the date-only `priceValidUntil` from WC core.

## What changed in the review before that (2026-05-07)

- **Variable products now emit `ProductGroup`** (#328/#373): `maybe_convert_to_product_group()` converts a variable product to `@type: ProductGroup`, emitting `productGroupID` (SKU or id fallback), `variesBy` (the varying attribute axes), and `hasVariant: [...]` (each a standalone `Product` with its own offer, `BuyAction`, and `checkoutPageURLTemplate`).
- **`Offer.checkoutPageURLTemplate` now emitted** on simple offers (`add_checkout_page_url_template()`) and on every per-variant offer inside `hasVariant`; coexists with `BuyAction`.
- **Catalog/checkout API prices now currency-converted** (#517, 0.26.0): `with_active_currency()` wraps the UCP REST Store-API dispatch; page JSON-LD follows `?currency=` render handling — these are distinct paths.
- **Return policy is now Option A/B** (#520): merchants choose between a direct return-policy link (Option A) or a structured `MerchantReturnPolicy` block (Option B).

---

This document audits the plugin's JSON-LD output against [Schema.org](https://schema.org) for every type we emit. It complements [`JSON-LD-SCHEMA.md`](./JSON-LD-SCHEMA.md) (which describes *what we emit and how*) by enumerating *what the spec offers* and where we have coverage gaps.

## Why this doc exists

The plugin's primary value to AI agents is the structured semantic data they can read at scale without per-store integration. Coverage gaps against Schema.org are coverage gaps against AI-agent intelligence — every property we don't emit is a question agents can't answer about the merchant's store.

Use this audit to:
- Decide which Schema.org properties to add next.
- Spot drift between the plugin and its documentation (gaps where `JSON-LD-SCHEMA.md` doesn't describe an emitted field).
- Onboard contributors who need to know *what's possible* before deciding *what to add*.

## Methodology

1. Properties pulled directly from Schema.org spec pages for `Product`, `Offer`, `Action`, `Review`, `Organization`, `OnlineBusiness`, `OnlineStore`. Domain claims (which type a property is actually declared on) are verified against the canonical machine-readable vocabulary — `https://schema.org/version/latest/schemaorg-current-https.ttl`, grepping the property's `schema:domainIncludes` — because the rendered spec pages make an inherited property and a directly-declared one look alike.
2. Implementation cross-referenced against [`class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) and WooCommerce core's `WC_Structured_Data::generate_product_data()` (in `wp-content/plugins/woocommerce/includes/class-wc-structured-data.php`).
3. Doc coverage measured against [`JSON-LD-SCHEMA.md`](./JSON-LD-SCHEMA.md).

## Type hierarchy at-a-glance

The plugin emits two top-level JSON-LD blocks:

| Surface | Currently emitted `@type` | Schema.org chain |
|---|---|---|
| Single product page (simple) | `Product` | Thing → Product |
| Single product page (variable) | `ProductGroup` | Thing → Product → ProductGroup |
| Homepage / shop | `OnlineBusiness` *([decision](#why-onlinebusiness-and-not-onlinestore))* | Thing → Organization → OnlineBusiness → OnlineStore |

Nested types in either block: `Offer`, `BuyAction`, `EntryPoint`, `QuantitativeValue`, `MonetaryAmount`, `OfferShippingDetails`, `ShippingDeliveryTime`, `MerchantReturnPolicy`, `DefinedRegion`, `PostalAddress`, `ContactPoint`, `OfferCatalog`, `Review`, `Rating`, `AggregateRating`, `Person`, `PropertyValue`, `SearchAction`, `ProductGroup`, `variesBy`, `hasVariant`.

## Legend

| Symbol | Meaning |
|---|---|
| ✓ | Property is emitted in some form |
| — | Not emitted, not documented |
| ✓ §X | Section X of `JSON-LD-SCHEMA.md` covers this |
| **WC core** | Emitted by `WC_Structured_Data::generate_product_data()` |
| **Plugin** | Emitted by `WC_AI_Storefront_JsonLd::enhance_product_data()` |

---

## `Product`

[Schema.org spec →](https://schema.org/Product)

### Direct properties

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `additionalProperty` | ✓ | ✓ | Plugin |
| `aggregateRating` | — | ✓ when reviews enabled + ≥1 rated | WC core |
| `audience` | ✓ §audience | ✓ from `pa_gender` / `pa_age_group` (or bare `gender` / `age_group`) attributes | Plugin |
| `award` | — | — | — |
| `brand` | ✓ §brand | ✓ when `product_brand` taxonomy has a value | WC core (`WC_Brands::add_structured_data()` in `class-wc-brands.php`, NOT `class-wc-structured-data.php` — that's why an audit grep against just the main structured-data file missed it) |
| `category` | ✓ | ✓ | Plugin |
| `color` | ✓ §typed | ✓ (single value, taxonomy or free-text) | Plugin |
| `colorSwatch` | — | — | — |
| `countryOfAssembly` / `countryOfLastProcessing` / `countryOfOrigin` | — | — | — |
| `depth` | ✓ §dimensions | ✓ (`QuantitativeValue` with UN/CEFACT `unitCode`) | Plugin |
| `displayLocation` | — | — | — |
| `funding` | — | — | — |
| `gtin` | ✓ ([§deliberately-not-emitted](./JSON-LD-SCHEMA.md), as a "plugin doesn't enrich" note) | ✓ when WC's GTIN field is set | WC core |
| `gtin8` / `gtin12` / `gtin13` / `gtin14` | — | — | — *(WC core emits a generic `gtin` only — see follow-up)* |
| `hasAdultConsideration` | ✓ §adult | ✓ when the product is flagged adult | Plugin (`add_adult_consideration()`, #644) — only `SexualContentConsideration` is reachable; the other nine enumeration members are ignored by Google. Also on every `hasVariant` entry. |
| `hasCertification` | — | — | — |
| `hasEnergyConsumptionDetails` | — | — | — |
| `hasGS1DigitalLink` | — | — | — |
| `hasMeasurement` | — | — | — |
| `hasMerchantReturnPolicy` | ✓ §return | ✓ at `offers[0]` level | Plugin |
| `height` | ✓ §dimensions | ✓ | Plugin |
| `inProductGroupWithID` | — | — | Not emitted — the parent `ProductGroup` carries `productGroupID` instead (sku, or id fallback) |
| `isAccessoryOrSparePartFor` / `isConsumableFor` | — | — | — |
| `isRelatedTo` / `isSimilarTo` | ✓ §isRelatedTo-isSimilarTo | ✓ when product has cross-sells / upsells | Plugin (`add_related_products()`) — cross-sells → `isRelatedTo`, upsells → `isSimilarTo`, capped at 10 entries each, syndication-filtered |
| `isVariantOf` | — | — | Not emitted — variants emit as standalone `Product` entries under the parent's `hasVariant`; no back-pointer is emitted |
| `itemCondition` | — | — | — *(see "Recommended follow-ups")* |
| `keywords` | — | — | — |
| `logo` | — | — | — |
| `manufacturer` | — | — | — |
| `material` | ✓ §typed | ✓ (single value) | Plugin |
| `mobileUrl` | — | — | — |
| `model` | — | — | — |
| `mpn` | — | — | — *(WC core has plumbing but not in default emission)* |
| `negativeNotes` / `positiveNotes` | — | — | — |
| `nsn` | — | — | — |
| `offers` | ✓ (sub-fields) | ✓ | WC core (with plugin enrichments — see Offer table) |
| `pattern` | ✓ §typed | ✓ (single value) | Plugin |
| `productID` | — | — | — |
| `productionDate` / `purchaseDate` / `releaseDate` | — | — | — |
| `review` | — | ✓ top 5 most recent when reviews enabled | WC core |
| `size` | ✓ §typed | ✓ (single value) | Plugin |
| `sku` | ✓ (in worked example) | ✓ | WC core (falls back to `get_id()` if no SKU) |
| `slogan` | — | — | — |
| `weight` | ✓ §dimensions | ✓ | Plugin |

### Inherited from `Thing`

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `additionalType` / `alternateName` / `disambiguatingDescription` / `identifier` / `mainEntityOfPage` / `owner` / `subjectOf` | — | — | — |
| `description` | — | ✓ | WC core (uses short description, falls back to long) |
| `image` | — | ✓ when product has image | WC core |
| `name` | — | ✓ | WC core |
| `potentialAction` | ✓ §BuyAction | ✓ (`BuyAction`) | Plugin |
| `sameAs` | — | — | — |
| `url` | — | ✓ | WC core (product permalink) |

---

## `ProductGroup`

[Schema.org spec →](https://schema.org/ProductGroup)

Variable products are converted to `@type: ProductGroup` by `maybe_convert_to_product_group()` (~line 1085). This is Google's preferred shape for variant rich results — each variant is a standalone `Product` listed under `hasVariant`, and the parent carries the grouping metadata.

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `@type: ProductGroup` | — | ✓ | Plugin (`maybe_convert_to_product_group()`) |
| `productGroupID` | — | ✓ (SKU, or product `id` as fallback) | Plugin (line 1187) |
| `variesBy` | — | ✓ (the varying attribute axes, e.g. `"color"`, `"size"`) | Plugin (line 1188) |
| `hasVariant` | — | ✓ (array of `Product` entries built by `build_variant_entry()`, ~line 1219) | Plugin (line 1195) |

### Per-variant `Product` entries (inside `hasVariant[]`)

Each `hasVariant` entry is a standalone `Product` built by `build_variant_entry()` and includes: `@id`, `url`, `name`, `sku`, `image`, typed props, `brand`, `category`, `weight`/`depth`/`width`/`height` (own value if set, else inherited from the parent via WC's own getters, #615), and `offers[0]` with `seller` (copied from parent), `shippingDetails` (including the variant's own weight/dimensions, #614), the variation's `BuyAction`, and `Offer.checkoutPageURLTemplate`.

**Not emitted on variants**: `isVariantOf` (no back-pointer to the parent) and `inProductGroupWithID` (the parent carries `productGroupID` instead).

---

## `Offer`

[Schema.org spec →](https://schema.org/Offer)

### Direct properties

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `acceptedPaymentMethod` | — | — | — |
| `addOn` | — | ✓ for WC Subscriptions products with a sign-up fee | Plugin (#368) — emitted alongside an inline `UnitPriceSpecification` with `priceComponentType: ActivationFee` for max consumer reach |
| `additionalProperty` | — | — | — |
| `advanceBookingRequirement` | — | — | — |
| `aggregateRating` | — | — | — *(WC core emits at Product level instead — single-merchant stores don't need per-offer rating differentiation)* |
| `areaServed` | — | — | — |
| `asin` | — | — | — |
| `availability` | — | ✓ (`InStock`/`OutOfStock`/`BackOrder`) | WC core |
| `availabilityEnds` / `availabilityStarts` | — | — | — |
| `availableAtOrFrom` / `availableDeliveryMethod` | — | — | — |
| `businessFunction` | — | — | — |
| `category` | — | — | — *(plugin emits at Product level; WC's `product_cat` taxonomy classifies the thing being sold, not the offer's commercial role)* |
| `checkoutPageURLTemplate` | ✓ | ✓ (Plugin) | Plugin — emitted on simple offers (`add_checkout_page_url_template()`) and on every per-variant offer inside `hasVariant`; coexists with `BuyAction` (same URL at different positions: `BuyAction` on `Product.potentialAction` for Action-vocabulary breadth; `checkoutPageURLTemplate` directly on `Offer` for modern e-commerce signal + per-variant fit) |
| `deliveryLeadTime` | — | — | — *(handlingTime is in `shippingDetails` instead)* |
| `eligibleDuration` | — | ✓ for WC Subscriptions products with a finite `get_length() > 0` | Plugin (#368) — emitted as `QuantitativeValue` with UN/CEFACT `unitCode` (DAY/WEE/MON/ANN); indefinite subscriptions omit the field |
| `eligibleCustomerType` / `eligibleQuantity` / `eligibleRegion` / `eligibleTransactionVolume` | — | — | — |
| `gtin` / `gtin8/12/13/14` / `mpn` | — | — | — *(emitted at Product level when set)* |
| `hasAdultConsideration` | ✓ §adult | ✓ when the product is flagged adult | Plugin (#644) — emitted on the Offer as well as the Product; Google documents it under merchant listings |
| `hasGS1DigitalLink` / `hasMeasurement` | — | — | — |
| `hasMerchantReturnPolicy` | ✓ §return | ✓ | Plugin |
| `includesObject` / `ineligibleRegion` | — | — | — |
| `inventoryLevel` | ✓ | ✓ when stock managed | Plugin |
| `isFamilyFriendly` | — | — | — |
| `itemCondition` | — | — | — |
| `itemOffered` | — | — | — |
| `leaseLength` | — | — | — |
| `mobileUrl` | — | — | — |
| `offeredBy` | — | — | — |
| `price` | — | ✓ | WC core. **API path**: catalog/checkout API responses are currency-converted per the agent's `context.currency` via `with_active_currency()` (#517, requires WooPayments ≥ 10.9); page JSON-LD follows `?currency=` render — these are distinct paths. |
| `priceCurrency` | ✓ | ✓ | Plugin (hoists from `priceSpecification[0]` to outer Offer). Same API vs page path distinction as `price` above. |
| `priceSpecification` | — | ✓ (`UnitPriceSpecification`) | WC core for non-subscription sale-window entries; for WC Subscriptions products the plugin replaces `offers[0].priceSpecification` wholesale (#368) — the subscription array overwrites any WC-core entries rather than merging. The plugin emits `UnitPriceSpecification` entries carrying `priceComponentType: Subscription` with ISO 8601 `billingDuration`, plus `priceComponentType: ActivationFee` for sign-up fees. Free trial uses a two-element array (trial entry at `price: 0`, recurring entry second); deliberately does NOT emit `billingStart` since Schema.org types it `Number`, not Duration |
| `priceValidUntil` | — | ✓ when sale-end date is set | WC core |
| `review` | — | — | — |
| `seller` | ✓ §seller | ✓ (`Organization`) | WC core (plugin post-processes `seller.name` for HTML-entity decoding) |
| `serialNumber` | — | — | — |
| `shippingDetails` | ✓ §shipping | ✓ | Plugin |
| `sku` | — | — | — *(emitted at Product level)* |
| `validFrom` / `validThrough` | ✓ §sale-window | ✓ when on sale with a configured sale schedule | Plugin (#582) — full ISO 8601 with the store timezone offset via `add_sale_window()`; sourced from `get_date_on_sale_from()`/`get_date_on_sale_to()`; per-variant windows use each variation's own schedule with no parent fallback; skipped on `AggregateOffer` |
| `validForMemberTier` | — | — | — |
| `warranty` | — | — | — |

### Inherited from `Thing`

Most don't apply at Offer level. WC core sets `url` on offer to the product permalink.

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `description` / `name` / `image` | — | — | — |
| `url` | — | ✓ (product permalink) | WC core |
| Others (Thing-inherited) | — | — | — |

---

## Nested types: `OfferShippingDetails` and `ShippingDeliveryTime`

These nested types are emitted under `Offer.shippingDetails`. The Offer table above marks `shippingDetails` as ✓; this section breaks down the sub-tree.

### `OfferShippingDetails`

[Schema.org spec →](https://schema.org/OfferShippingDetails)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `deliveryTime` (`ShippingDeliveryTime`) | ✓ §shipping | ✓ when handling time configured | Plugin (see `ShippingDeliveryTime` table below) |
| `depth` / `height` / `width` / `weight` | ✓ §shipping | ✓ (#614) | Plugin — same `QuantitativeValue` values as `Product`-level (both drawn from WooCommerce's single set of dimension fields, which its own shipping methods already use to compute rates; the two properties answer different questions — item size vs. parcel size — the same split Google's `product_*`/`shipping_*` attributes make) |
| `doesNotShip` | — | — | — *(regional exclusions; merchant data exists in WC's restricted-shipping zones, but we don't reflect it here)* |
| `hasShippingService` (`ShippingService`) | ✓ §org-shipping | ✓ on the homepage `OnlineBusiness` block (#635) | Plugin (`WC_AI_Storefront_Shipping_Policy`) — WooCommerce shipping zones become `ShippingConditions`, one per destination and order-value band. This is the Organization-level surface, not `OfferShippingDetails`; it exists because a product can carry only one `MonetaryAmount` and so cannot express "free over $20, otherwise $20". |
| `shippingDestination` (`DefinedRegion`) | ✓ §shipping | ✓ (`addressCountry` from store base location) | Plugin |
| `shippingOrigin` (`DefinedRegion`) | — | — | — *(where the shipment ships from — useful for international agents to estimate transit time)* |
| `shippingRate` (`MonetaryAmount`) | ✓ §shipping | ✓ when unconditional free shipping configured (`value: 0`) | Plugin |
| `validForMemberTier` | — | — | — *(membership-tier-specific shipping; out of scope for current plugin)* |

### `ShippingDeliveryTime`

[Schema.org spec →](https://schema.org/ShippingDeliveryTime)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `businessDays` | ✓ §shipping | ✓ when dispatch days are selected (#637) | Plugin — Policies → Shipping. Bare `DayOfWeek` names, week-ordered. **Google does not document this on `ShippingDeliveryTime`** (only inside `ServicePeriod` at Organization level), so it is emitted here deliberately: the adjacent `handlingTime` that Google *does* read cannot become a delivery date without the working week. |
| `cutoffTime` | — | — | — *(no setting stores one. Note it is NOT limited to same-day dispatch — Google adds a day to the estimate for orders placed after the cutoff, so it is a real claim at any handling duration.)* |
| `handlingTime` (`QuantitativeValue`) | ✓ §shipping | ✓ when handling-time setting populated (`min`/`max`/`unitCode: DAY`) | Plugin |
| `transitTime` (`QuantitativeValue`) | — | — | — *(delivery duration AFTER dispatch; complements handlingTime for full delivery-estimate signal)* |

### Coverage gap summary for Shipping

Since 0.37.0 the shipping picture is largely complete. Product level carries the `OfferShippingDetails` wrapper, handling time, dispatch days, destination country and weight/dimensions; Organization level carries the full rate table as `hasShippingService` → `ShippingConditions`, including free-over-threshold bands and unreachable destinations.

Two signals remain unemitted, both deliberately. `transitTime` varies by carrier, service level and destination, and WooCommerce stores none of it — a single store-wide value would be inaccurate for anyone shipping to more than one place. `cutoffTime` has no setting behind it. `shippingOrigin` is the remaining easy win.

---

## `Action` (specifically `BuyAction` and `SearchAction`)

[Schema.org Action spec →](https://schema.org/Action)

The plugin emits two Action subtypes:
- `BuyAction` under `Product.potentialAction` (single product page)
- `SearchAction` under `OnlineStore.potentialAction` (homepage / shop)

### `BuyAction` (Product.potentialAction)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `@type: BuyAction` | ✓ §BuyAction | ✓ | Plugin |
| `target` (`EntryPoint`) | ✓ §BuyAction | ✓ | Plugin |
| `target.urlTemplate` | ✓ §BuyAction | ✓ (bare Shareable Checkout URL, no UTM/attribution parameters — #574) | Plugin |
| `target.actionPlatform` | ✓ §BuyAction (implicit) | ✓ (`DesktopWebPlatform`, `MobileWebPlatform`) | Plugin |
| `result` | — | — | — *(per Schema.org, expected `Order`; plugin omits)* |
| `actionStatus` / `agent` / `participant` / `provider` | — | — | — |
| `instrument` / `object` / `error` | — | — | — |
| `startTime` / `endTime` | — | — | — |
| `location` | — | — | — |

### `SearchAction` (OnlineStore.potentialAction)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `@type: SearchAction` | — | ✓ | Plugin |
| `target.urlTemplate` (with `{search_term_string}`) | — | ✓ | Plugin |
| `query-input` | — | ✓ | Plugin |

### Inherited from `Thing` (apply to both)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `name` / `description` / `url` | — | — | — |

---

## `Review` and `AggregateRating`

[Schema.org Review spec →](https://schema.org/Review) · [Schema.org AggregateRating spec →](https://schema.org/AggregateRating)

WC core emits both when reviews are enabled and the product has ≥1 rated review. The plugin doesn't enrich review data.

### `AggregateRating` (under `Product.aggregateRating`)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `@type: AggregateRating` | — | ✓ | WC core |
| `ratingValue` | — | ✓ (uses `$product->get_average_rating()`) | WC core |
| `reviewCount` | — | ✓ (uses `$product->get_review_count()`) | WC core |
| `bestRating` / `worstRating` | — | — | — *(WC core only emits these on the inner `Rating`, not on `AggregateRating`)* |
| `ratingCount` | — | — | — |

### `Review` (under `Product.review[]`, top 5 most recent)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `@type: Review` | — | ✓ | WC core |
| `reviewRating` (nested `Rating` with `ratingValue` / `bestRating: 5` / `worstRating: 1`) | — | ✓ | WC core |
| `author` (`Person` with `name`) | — | ✓ (uses `get_comment_author()`) | WC core |
| `reviewBody` | — | ✓ (uses `get_comment_text()`) | WC core |
| `datePublished` | — | ✓ (ISO 8601) | WC core |
| `itemReviewed` | — | — | — *(implicit by being under `Product.review`)* |
| `reviewAspect` / `negativeNotes` / `positiveNotes` | — | — | — |
| `associatedClaimReview` / `associatedMediaReview` / `associatedReview` | — | — | — |

### Inherited from `CreativeWork` (most don't apply to product reviews)

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `headline` / `dateCreated` / `dateModified` / `inLanguage` / `keywords` / `publisher` / `editor` | — | — | — |

### Inherited from `Thing`

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `name` / `description` / `url` | — | — | — |

---

## `Organization` (the Thing → Organization layer of `OnlineBusiness`)

[Schema.org spec →](https://schema.org/Organization)

The plugin emits `@type: OnlineBusiness` — one level above the deepest type in the chain ([decision](#why-onlinebusiness-and-not-onlinestore)). The Organization properties below cover everything we emit: `OnlineBusiness` defines no direct properties of its own, and `OnlineStore` adds only `isStoreOn` (which we don't emit).

### Direct properties — emitted

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `address` (`PostalAddress`) | ✓ §identity, §address | ✓ when WC store address fields set | Plugin (suppresses `streetAddress` for privacy) |
| `contactPoint` (`ContactPoint` with `email`, `contactType: Customer Service`) | ✓ §identity, §email | ✓ when valid email resolvable | Plugin (two-stage resolver: reply-to → from-address with noreply guard) |
| `hasOfferCatalog` (`OfferCatalog` with nested `OfferCatalog` entries) | — *(not yet in [`JSON-LD-SCHEMA.md`](./JSON-LD-SCHEMA.md))* | ✓ on homepage; top 10 root product_cat categories ordered by product count, each with `name`/`numberOfItems`/`url`, cached 1h | Plugin (`get_catalog_summary()`) |
| `logo` | ✓ §identity | ✓ when site icon or custom logo set | Plugin (precedence: custom_logo → site_icon) |

### Direct properties — not emitted

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `acceptedPaymentMethod` | — | — | — |
| `actionableFeedbackPolicy` | — | — | — |
| `agentInteractionStatistic` | — | — | — |
| `aggregateRating` | — | — | — |
| `alumni` | — | — | — |
| `areaServed` | — | — | — |
| `award` | — | — | — |
| `brand` | — | — | — |
| `companyRegistration` | — | — | — |
| `correctionsPolicy` | — | — | — |
| `department` | — | — | — |
| `dissolutionDate` | — | — | — |
| `diversityPolicy` / `diversityStaffingReport` | — | — | — |
| `duns` / `globalLocationNumber` / `iso6523Code` / `leiCode` / `naics` / `taxID` / `vatID` / `isicV4` | — | — | — *(business-identifier alphabet soup; useful for B2B / regulated stores)* |
| `email` | — | — | — *(plugin uses `contactPoint.email` instead)* |
| `employee` / `numberOfEmployees` | — | — | — |
| `ethicsPolicy` | — | — | — |
| `event` | — | — | — |
| `faxNumber` | — | — | — |
| `founder` / `foundingDate` / `foundingLocation` | — | — | — |
| `funder` / `funding` / `sponsor` | — | — | — |
| `hasCertification` / `hasCredential` / `hasGS1DigitalLink` | — | — | — |
| `hasMemberProgram` | — | — | — |
| `hasMerchantReturnPolicy` | ✓ §org-return | ✓ when policy configured | Plugin (`output_store_jsonld()` → `build_return_policy_block()` shared with per-Offer emission). Phase 1 of #337 — Org-level is purely additive alongside existing per-Offer emission. Phase 2 (making per-Offer conditional on the per-product final-sale override) is deferred. |
| `hasPOS` | — | — | — |
| `hasShippingService` | ✓ §org-shipping | ✓ (#635) | Plugin (`WC_AI_Storefront_Shipping_Policy`) — see the Shipping section above. |
| `interactionStatistic` | — | — | — |
| `keywords` | — | — | — |
| `knowsAbout` | ✓ §knowsAbout | ✓ when catalog non-empty | Plugin (`output_store_jsonld()`) — Text[] of top product category names sourced from cached `get_catalog_summary()`, no new query |
| `knowsLanguage` | — | — | — |
| `legalAddress` / `legalName` / `legalRepresentative` | — | — | — |
| `location` | — | — | — |
| `makesOffer` | — | — | — |
| `member` / `memberOf` | — | — | — |
| `nonprofitStatus` | — | — | — |
| `ownershipFundingInfo` / `owns` / `parentOrganization` / `subOrganization` | — | — | — |
| `publishingPrinciples` / `unnamedSourcesPolicy` | — | — | — |
| `review` | — | — | — |
| `seeks` | — | — | — |
| `skills` | — | — | — |
| `slogan` | — | — | — |
| `telephone` | — | — | — |

### Inherited from `Thing`

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `name` | — | ✓ (uses `get_bloginfo('name')`) | Plugin |
| `description` | — | ✓ (uses `get_bloginfo('description')`) | Plugin |
| `url` | — | ✓ (uses `home_url('/')`) | Plugin |
| `image` | — | — | — |
| `sameAs` | — | ✓ (auto-sourced from Jetpack Publicize / Yoast / RankMath) | Plugin (`collect_same_as()`) |
| `potentialAction` | — | ✓ (`SearchAction`) | Plugin |
| Others (Thing-inherited) | — | — | — |

---

## `OnlineBusiness` ← target type

[Schema.org spec →](https://schema.org/OnlineBusiness)

> ⚠️ **Pending status.** `OnlineBusiness` and its subtypes are in Schema.org's "new" area — recognized but not yet in the stable core vocabulary. Implementation feedback is still being collected.

`OnlineBusiness` defines **zero direct properties**. It's a marker type in the hierarchy:

```
Thing → Organization → OnlineBusiness → OnlineStore
```

All inherited properties are covered by the [Organization](#organization-the-thing--organization-layer-of-onlinebusiness) table.

### Why `OnlineBusiness` and not `OnlineStore`

Schema.org's `OnlineStore` description is *"An eCommerce site"* — strictly product-selling. WooCommerce's actual install base is broader:

- **Service businesses** (WooCommerce Bookings, consultancies, agencies)
- **Subscription / membership sites** (WooCommerce Memberships, paid content)
- **Donation / nonprofit sites** (charity stores, fundraising)
- **Lead-gen / signup forms** (WC checkout used as a sign-up flow)
- **Digital downloads** (sometimes counted as e-commerce, sometimes not)
- **Traditional product retail** (still the core, but not exhaustive)

Emitting `OnlineStore` for everything would mis-classify a meaningful fraction of merchants. `OnlineBusiness` covers the same intent ("this entity does business online") without claiming product retail.

The trade-off: `currenciesAccepted` is **not** a property of `OnlineBusiness` — and, contrary to what this document and the emitter docblock claimed until the audit re-verification, it is not a property of `OnlineStore` either. Its sole declared domain in the canonical vocabulary is `LocalBusiness`:

```turtle
schema:currenciesAccepted a rdf:Property ;
    schema:domainIncludes schema:LocalBusiness ;
    schema:rangeIncludes schema:Text .
```

`LocalBusiness` is a **sibling** branch, not an ancestor: it descends from `Organization` (and `Place`) in parallel with `OnlineBusiness`, so no inheritance path reaches our emitted type from either direction. The only property `OnlineStore` adds over `OnlineBusiness` is `isStoreOn`.

We continue to emit `currenciesAccepted` on `OnlineBusiness` as an **intentional non-domain pairing**: most consumers (AI agents, search crawlers) parse the property regardless of the enclosing type, and the machine-readable currency signal is too useful to drop. Strict validators may emit a non-fatal "unrecognized property for this type" warning — accepted tradeoff. `hasOfferCatalog`, `name`, `description`, `url`, and `potentialAction` are all defined on `Organization` (or `Thing`) and apply cleanly to `OnlineBusiness` via standard parent-to-child inheritance.

> **Historical note.** `currenciesAccepted` was on-domain before 0.10.0, when the plugin emitted `@type: Store` (`Thing → Organization/Place → LocalBusiness → Store`) and inherited it legitimately. It went off-domain at [#311](https://github.com/Automattic/woocommerce-ai-storefront/pull/311)'s move to `OnlineStore` — which left the `LocalBusiness` branch — not at #334's move to `OnlineBusiness`. #334 correctly identified that the property was off-domain but misattributed the boundary it had crossed.

### Counter-evidence: Google recommends the subtype

Google's [Organization structured data documentation](https://developers.google.com/search/docs/appearance/structured-data/organization) names this exact choice and comes down the other way:

> "We recommend using the most specific schema.org subtype of `Organization` that matches your organization. For example, if you have an ecommerce site, then we recommend using the `OnlineStore` subtype instead of `OnlineBusiness`."

Weighing it against the decision above:

- **The recommendation is conditional on the site.** It says to use the subtype *"that matches your organization"* — a per-store judgment. This plugin ships one emitter to every WC install and cannot know, at render time, whether a given store is retail. That's the whole basis for picking the parent.
- **Switching gains no properties.** `OnlineStore` declares exactly one property `OnlineBusiness` doesn't: `isStoreOn` (the marketplace an online store is listed on), which we don't emit. `hasMerchantReturnPolicy`, `acceptedPaymentMethod`, `makesOffer`, and `hasShippingService` are all `Organization`-domain properties and already apply to `OnlineBusiness` — verify with `schema:domainIncludes` in the vocabulary before accepting a claim to the contrary.
- **No rich-result eligibility depends on it.** Merchant listing / shopping experiences are built on `Product` and `Offer` markup; [Google's merchant listing documentation](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing) does not mention `OnlineStore` or `OnlineBusiness` at all. Claims that the subtype unlocks Merchant Center integration, storefront badges, or free listing feeds are unsourced.
- **Both types are pending.** `OnlineStore` and `OnlineBusiness` alike carry `schema:isPartOf <https://pending.schema.org>` — neither is stable core vocabulary, so "more specific" does not mean "more stable."

The unresolved tension is real: for the retail majority of WC installs, Google's guidance favours `OnlineStore`, and the plugin currently emits the parent for all of them. Merchants can already override the type through the `wc_ai_storefront_jsonld_store` filter, which receives the whole `$store_data` array before output. Auto-deriving the type per-store is tracked separately.

> **Status:** Resolved (issues [#334](https://github.com/Automattic/woocommerce-ai-storefront/issues/334) and [#337](https://github.com/Automattic/woocommerce-ai-storefront/issues/337) phase 1, bundled in one PR). The [`output_store_jsonld()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) method now emits `@type: OnlineBusiness`.

---

## `OnlineStore` (reference only — not our target type)

[Schema.org spec →](https://schema.org/OnlineStore) · ⚠️ **pending status (see [OnlineBusiness](#onlinebusiness--target-type) above)**

> Schema.org description: *"An eCommerce site."*

We **don't target** `OnlineStore` because its "eCommerce site" definition is too narrow for WC's actual user base ([rationale](#why-onlinebusiness-and-not-onlinestore)). This section is kept as a reference catalog.

### Direct properties

`isStoreOn` is the **only** property `OnlineStore` declares directly — verified against `schemaorg-current-https.ttl`, not the rendered spec page.

| Property | In doc? | Emitted under target type? | Source |
|---|---|---|---|
| `isStoreOn` (`OnlineMarketplace`) | — | — | — *(would be useful for product stores mirrored on Etsy / Amazon / eBay; out of scope while we target `OnlineBusiness`)* |

> `currenciesAccepted` previously appeared in this table. It does not belong to `OnlineStore` — its only declared domain is `LocalBusiness`. We emit it anyway; see [the trade-off above](#why-onlinebusiness-and-not-onlinestore).

Everything else on `OnlineStore` flows through the inheritance chain. See the [Organization](#organization-the-thing--organization-layer-of-onlinebusiness) and `Thing` tables above; those properties are still emitted under our target `OnlineBusiness` type.

### What changes if we ever wanted to emit `OnlineStore` (e.g. per-merchant opt-in)

A merchant who runs a strictly-products WC store could opt into `OnlineStore` for the more-specific signal. That would re-enable:
- `currenciesAccepted` as an idiomatic property
- `isStoreOn` for marketplace mirroring

For now, conservatism wins: the broader `OnlineBusiness` type accurately covers all WC use cases.

---

## Summary observations

1. **Coverage is biased toward "what we add beyond WC core"**. `JSON-LD-SCHEMA.md` documents fields the plugin contributes; WC-core-emitted fields (`name`, `description`, `image`, `url`, `aggregateRating`, `review[]`, top-level `OnlineStore` fields) are largely undocumented even though they're part of the shipped JSON-LD. This is a doc bias to consider correcting.

2. **`brand` IS emitted by WC core** via a separate handler. `WC_Brands::add_structured_data()` in `wp-content/plugins/woocommerce/includes/class-wc-brands.php` hooks `woocommerce_structured_data_product` at priority 20 — distinct from the main `WC_Structured_Data` class. An audit grep against just the main file would miss this; the lesson is to grep the broader plugin tree for filter handlers, not just the canonical class. Coverage is conditional: requires WC's modern brands feature and `product_brand` taxonomy values.

3. **#328/#373 shipped `ProductGroup`/`variesBy`/`hasVariant` and `Offer.checkoutPageURLTemplate`**. Still not emitted by deliberate choice or WC-core limit: `isVariantOf` (no back-pointer on variants — the parent's `hasVariant` is the canonical link) and `gtin8`/`gtin12`/`gtin13`/`gtin14` (WC core emits a generic `gtin` only).

4. **High-coverage areas**: shipping (`shippingDetails`, `handlingTime`, `shippingRate`), returns (`hasMerchantReturnPolicy`), pricing (`priceCurrency`, `priceSpecification`, `priceValidUntil`), inventory (`inventoryLevel`), reviews (`Review[]`, `aggregateRating`), and identity (`logo`, `address`, `contactPoint`, `currenciesAccepted`).

5. **Biggest uncovered surface**: organizational metadata. `Organization` has 50+ direct properties. We emit a handful (logo, address, contactPoint, hasOfferCatalog, sameAs, knowsAbout, name, description, url). Most of the rest are niche or B2B-specific (DUNS, VAT ID, NAICS), but a few are broad-interest gaps — see follow-ups.

6. **Audience scope: not just AI**. The plugin's JSON-LD enhancements are read by *both* AI agents AND traditional search crawlers (Google, Bing, Yandex) — the same Schema.org Product/Offer/Organization output is consumed by both audiences. This is worth reflecting in merchant-facing copy throughout the settings UI; current strings (`"Products available to AI agents"`, `"Control which products AI agents can see and recommend"`) are AI-only and undersell the SEO value. Tracked as a separate UX/copy issue, not in this audit's coverage table.

## Recommended follow-ups

In rough priority order:

1. ~~**`Organization.sameAs`** — array of merchant social-profile URLs (Twitter, Facebook, Instagram).~~ **Implemented in #445.** Auto-sourced from common providers (Jetpack Publicize connections, Yoast `wpseo_social`, RankMath social settings) by [`collect_same_as()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php), each provider independently guarded; URLs are sanitized, restricted to `http`/`https`, and de-duplicated. The `wc_ai_storefront_jsonld_store` filter remains the per-merchant override/augment seam.
2. **`Product.itemCondition`** — new vs refurbished. Useful for resale stores; merchant-config required.
3. **`Product.gtin8` / `gtin12` / `gtin13` / `gtin14`** — more specific GTIN forms when WC's GTIN field has a value with the right length.
4. **`Organization.telephone`** — currently suppressed by default. Worth a per-merchant opt-in toggle.
5. ~~**`Product.audience`** — target demographic (`PeopleAudience` with `audienceType`). Merchant-config; useful for kids/adult/professional/etc. categorization.~~ **Implemented in #618**, via `suggestedGender` / `suggestedAge` rather than `audienceType` — Google's Apparel & Accessories requirements read those two specific sub-properties, not the generic `audienceType` this line originally proposed. See [JSON-LD-SCHEMA.md §audience](./JSON-LD-SCHEMA.md#audience-gender-and-age-group--peopleaudience).
6. **Switch homepage `@type` from `OnlineStore` to `OnlineBusiness`** — broader fit for WC's full install base (services, bookings, memberships, donations, lead-gen, retail). Code change is one line in [`output_store_jsonld()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) plus a `JSON-LD-SCHEMA.md` update. Decision rationale captured in [the OnlineBusiness section](#onlinebusiness--target-type).
7. **Expand `ShippingDeliveryTime` emission carefully** — currently we emit only `handlingTime`. The spec also has `transitTime`, `cutoffTime`, `businessDays`, and `shippingOrigin`. **Caveat: shipping data is multi-dimensional in reality** — transit time depends on the buyer's destination, the chosen service level (ground vs expedited vs overnight), the merchant's shipping origin, and sometimes the day of week. A single static `transitTime: 3-5 days` claim hides this dimensionality. Two paths forward:
   - **Conservative**: emit only `cutoffTime` and `businessDays` (which DON'T depend on the buyer's destination — they're store-level operating windows). Skip `transitTime` until we can model destination/service-level shape.
   - **Multi-rate**: emit multiple `OfferShippingDetails` entries, one per shipping method × destination region, each with its own `transitTime`. Spec-compliant per Schema.org Example 1 ("Cheaper and slower: $5 in 5-7 days or Fast and expensive: $15 in 1-2 days"). Higher implementation cost; needs full shipping-zone walk.

   Recommend the conservative path first — it captures real merchant-known data without faking precision the multi-rate path would also be needed for. Multi-rate is a separate, larger initiative.

8. ~~**Restructure return-policy emission to match merchant model**~~ — **Shipped as Option A/B in [#520](https://github.com/Automattic/woocommerce-ai-storefront/pull/520).** Merchants now choose between Option A (direct return-policy link) and Option B (structured `MerchantReturnPolicy` block). Per-Offer emission remains override-aware (`MerchantReturnNotPermitted` for final-sale products, store-wide policy otherwise); both call sites share `build_return_policy_block()`.

These can be filed as standalone issues.

## Beyond the current types — Schema.org surfaces worth pursuing

Types we don't emit today but might extend AI-shopping and SEO leverage. Decision tiers reflect prioritization (active / deferred / ruled out).

### Already emitted (doc gap, not a coverage gap)

| Schema.org type | Source | Status |
|---|---|---|
| [`BreadcrumbList`](https://schema.org/BreadcrumbList) | WC core (`WC_Structured_Data::generate_breadcrumblist_data()`) | ✓ emitted on product pages with WC's breadcrumb data; not yet in `JSON-LD-SCHEMA.md`. **Doc fix:** add a `### BreadcrumbList` section. |

### Active follow-ups (in priority order)

All previously-active follow-ups have shipped or been ruled out. Remaining work is tracked in open issues directly.

| # | Schema.org type | Why pursue | Status |
|---|---|---|---|
| ~~1~~ | ~~[`Product.isRelatedTo`](https://schema.org/isRelatedTo) / [`isSimilarTo`](https://schema.org/isSimilarTo)~~ | ~~"People also bought" / "Similar products"~~ | ✓ Implemented in [#335](https://github.com/Automattic/woocommerce-ai-storefront/issues/335). |
| ~~2~~ | ~~[`Organization.knowsAbout`](https://schema.org/knowsAbout)~~ | ~~"What the store specializes in" signal for AI agents~~ | ✓ Implemented in [#334](https://github.com/Automattic/woocommerce-ai-storefront/issues/334) — Text[] of top category names from cached `get_catalog_summary()`, omitted when catalog empty. |

### Deferred (not now, but on the radar)

| Schema.org type | Why deferred |
|---|---|
| [`LocalBusiness`](https://schema.org/LocalBusiness) for omnichannel merchants | Needs merchant-config (physical address, opening hours) we don't collect today |
| [`HowTo`](https://schema.org/HowTo) for care/assembly instructions | Per-product merchant data input that doesn't yet exist; product-description integration is its own design |
| [`Event`](https://schema.org/Event) / [`SpecialAnnouncement`](https://schema.org/SpecialAnnouncement) for sales/closures | Needs a "Store Events" admin section; full standalone feature, not a small addition |

### Ruled out

- **[`WebSite`](https://schema.org/WebSite) + site-level `SearchAction`** — original motivation was Google's Sitelinks Search Box rich result, but [Google retired the feature in October 2024](https://developers.google.com/search/docs/appearance/structured-data/sitelinks-searchbox) ("the sitelinks search box feature is no longer available in Google Search results"). The existing `OnlineStore.potentialAction` SearchAction continues to serve AI agents that interpret the Action vocabulary. A separate top-level `WebSite` block now adds maintenance surface for marginal payoff (theoretical use by other search engines or AI graph traversers, no evidence of demand). Implementation was drafted in [PR #340](https://github.com/Automattic/woocommerce-ai-storefront/pull/340) and closed; preserved in PR history if a real consumer-driven need surfaces later.
- **[`FAQPage`](https://schema.org/FAQPage)** — out of scope. Detecting and parsing arbitrary policy pages into Q/A pairs is fragile, theme-dependent, and pulls the plugin into content-extraction territory better-served by dedicated SEO/FAQ plugins.
- **`Article` / `BlogPosting`** — content surfaces; out of scope for an e-commerce plugin (SEO plugins like Yoast/Rank Math handle these).
- **`SiteNavigationElement`** — generally redundant with theme HTML/menu structure; low leverage for the cost.
- **`AboutPage` / `ContactPage`** — page-type signals; mostly handled by SEO plugins; minimal AI-shopping value.
- **`Organization.review`** at the org level — store-as-entity reviews are a different surface from product reviews; needs merchant data we don't have today (Trustpilot integration etc.).
- **[`Quotation`](https://schema.org/Quotation)** — B2B/wholesale-specific; narrow audience.
- **[`MonetaryGrant`](https://schema.org/MonetaryGrant)** — store credit / gift card balance; post-purchase context, narrower use.
- **[`Course`](https://schema.org/Course)** — educational-product merchants only; niche.
- **[`Reservation`](https://schema.org/Reservation)** — bookable services / custom-order intake; needs WC Bookings or similar.
- **[`Certification`](https://schema.org/Certification), [`EnergyConsumptionDetails`](https://schema.org/EnergyConsumptionDetails)** — regulated industries only.
