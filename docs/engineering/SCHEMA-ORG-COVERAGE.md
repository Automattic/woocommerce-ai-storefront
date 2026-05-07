# Schema.org Coverage Audit

> Last reviewed: 2026-05-07 (post-[#331](https://github.com/Automattic/woocommerce-ai-storefront/pull/331))

This document audits the plugin's JSON-LD output against [Schema.org](https://schema.org) for every type we emit. It complements [`JSON-LD-SCHEMA.md`](./JSON-LD-SCHEMA.md) (which describes *what we emit and how*) by enumerating *what the spec offers* and where we have coverage gaps.

## Why this doc exists

The plugin's primary value to AI agents is the structured semantic data they can read at scale without per-store integration. Coverage gaps against Schema.org are coverage gaps against AI-agent intelligence — every property we don't emit is a question agents can't answer about the merchant's store.

Use this audit to:
- Decide which Schema.org properties to add next.
- Spot drift between the plugin and its documentation (gaps where `JSON-LD-SCHEMA.md` doesn't describe an emitted field).
- Onboard contributors who need to know *what's possible* before deciding *what to add*.

## Methodology

1. Properties pulled directly from Schema.org spec pages for `Product`, `Offer`, `Action`, `Review`, `Organization`, `OnlineBusiness`, `OnlineStore`.
2. Implementation cross-referenced against [`class-wc-ai-storefront-jsonld.php`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) and WooCommerce core's `WC_Structured_Data::generate_product_data()` (in `wp-content/plugins/woocommerce/includes/class-wc-structured-data.php`).
3. Doc coverage measured against [`JSON-LD-SCHEMA.md`](./JSON-LD-SCHEMA.md).

## Type hierarchy at-a-glance

The plugin emits two top-level JSON-LD blocks:

| Surface | Currently emitted `@type` | Target `@type` | Schema.org chain |
|---|---|---|---|
| Single product page | `Product` | (no change) | Thing → Product |
| Homepage / shop | `OnlineStore` | `OnlineBusiness` *([decision](#why-onlinebusiness-and-not-onlinestore))* | Thing → Organization → OnlineBusiness → OnlineStore |

Nested types in either block: `Offer`, `BuyAction`, `EntryPoint`, `QuantitativeValue`, `MonetaryAmount`, `OfferShippingDetails`, `ShippingDeliveryTime`, `MerchantReturnPolicy`, `DefinedRegion`, `PostalAddress`, `ContactPoint`, `OfferCatalog`, `Review`, `Rating`, `AggregateRating`, `Person`, `PropertyValue`, `SearchAction`.

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
| `audience` | — | — | — |
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
| `hasAdultConsideration` | — | — | — |
| `hasCertification` | — | — | — |
| `hasEnergyConsumptionDetails` | — | — | — |
| `hasGS1DigitalLink` | — | — | — |
| `hasMeasurement` | — | — | — |
| `hasMerchantReturnPolicy` | ✓ §return | ✓ at `offers[0]` level | Plugin |
| `height` | ✓ §dimensions | ✓ | Plugin |
| `inProductGroupWithID` | — | — | Future [#328](https://github.com/Automattic/woocommerce-ai-storefront/issues/328) |
| `isAccessoryOrSparePartFor` / `isConsumableFor` / `isRelatedTo` / `isSimilarTo` | — | — | — |
| `isVariantOf` | — | — | Future [#328](https://github.com/Automattic/woocommerce-ai-storefront/issues/328) |
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

## `Offer`

[Schema.org spec →](https://schema.org/Offer)

### Direct properties

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `acceptedPaymentMethod` | — | — | — |
| `addOn` | — | — | — |
| `additionalProperty` | — | — | — |
| `advanceBookingRequirement` | — | — | — |
| `aggregateRating` | — | — | — *(WC core emits at Product level instead — single-merchant stores don't need per-offer rating differentiation)* |
| `areaServed` | — | — | — |
| `asin` | — | — | — |
| `availability` | — | ✓ (`InStock`/`OutOfStock`/`BackOrder`) | WC core |
| `availabilityEnds` / `availabilityStarts` | — | — | — |
| `availableAtOrFrom` / `availableDeliveryMethod` | — | — | — |
| `businessFunction` | — | — | — |
| `category` | — | — | — *(emitted at Product level)* |
| `checkoutPageURLTemplate` | — | — | Future [#328](https://github.com/Automattic/woocommerce-ai-storefront/issues/328) |
| `deliveryLeadTime` | — | — | — *(handlingTime is in `shippingDetails` instead)* |
| `eligibleCustomerType` / `eligibleDuration` / `eligibleQuantity` / `eligibleRegion` / `eligibleTransactionVolume` | — | — | — |
| `gtin` / `gtin8/12/13/14` / `mpn` | — | — | — *(emitted at Product level when set)* |
| `hasAdultConsideration` / `hasGS1DigitalLink` / `hasMeasurement` | — | — | — |
| `hasMerchantReturnPolicy` | ✓ §return | ✓ | Plugin |
| `includesObject` / `ineligibleRegion` | — | — | — |
| `inventoryLevel` | ✓ | ✓ when stock managed | Plugin |
| `isFamilyFriendly` | — | — | — |
| `itemCondition` | — | — | — |
| `itemOffered` | — | — | — |
| `leaseLength` | — | — | — |
| `mobileUrl` | — | — | — |
| `offeredBy` | — | — | — |
| `price` | — | ✓ | WC core |
| `priceCurrency` | ✓ | ✓ | Plugin (hoists from `priceSpecification[0]` to outer Offer) |
| `priceSpecification` | — | ✓ (`UnitPriceSpecification` with sale variants) | WC core |
| `priceValidUntil` | — | ✓ when sale-end date is set | WC core |
| `review` | — | — | — |
| `seller` | ✓ §seller | ✓ (`Organization`) | WC core (plugin post-processes `seller.name` for HTML-entity decoding) |
| `serialNumber` | — | — | — |
| `shippingDetails` | ✓ §shipping | ✓ | Plugin |
| `sku` | — | — | — *(emitted at Product level)* |
| `validForMemberTier` / `validFrom` / `validThrough` | — | — | — |
| `warranty` | — | — | — |

### Inherited from `Thing`

Most don't apply at Offer level. WC core sets `url` on offer to the product permalink.

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `description` / `name` / `image` | — | — | — |
| `url` | — | ✓ (product permalink) | WC core |
| Others (Thing-inherited) | — | — | — |

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
| `target.urlTemplate` | ✓ §BuyAction | ✓ (with `{agent_id}` / `{session_id}` UTM placeholders) | Plugin |
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

## `Organization` (the Thing → Organization layer of `OnlineStore`)

[Schema.org spec →](https://schema.org/Organization)

The plugin emits `@type: OnlineStore` (deepest in the chain — see hierarchy section). The Organization properties below cover the entire chain since `OnlineBusiness` adds none and `OnlineStore` adds only `currenciesAccepted`.

### Direct properties — emitted

| Property | In doc? | Emitted? | Source |
|---|---|---|---|
| `address` (`PostalAddress`) | ✓ §identity, §address | ✓ when WC store address fields set | Plugin (suppresses `streetAddress` for privacy) |
| `contactPoint` (`ContactPoint` with `email`, `contactType: Customer Service`) | ✓ §identity, §email | ✓ when valid email resolvable | Plugin (two-stage resolver: reply-to → from-address with noreply guard) |
| `hasOfferCatalog` (`OfferCatalog` with `itemListElement`) | ✓ implicit | ✓ | Plugin |
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
| `hasMerchantReturnPolicy` | — | — | — *(emitted at Offer level instead)* |
| `hasPOS` / `hasShippingService` | — | — | — |
| `interactionStatistic` | — | — | — |
| `keywords` | — | — | — |
| `knowsAbout` / `knowsLanguage` | — | — | — |
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
| `sameAs` | — | — | — *(see "Recommended follow-ups")* |
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

All inherited properties are covered by the [Organization](#organization-the-thing--organization-layer-of-onlinestore) table.

### Why `OnlineBusiness` and not `OnlineStore`

Schema.org's `OnlineStore` description is *"An eCommerce site"* — strictly product-selling. WooCommerce's actual install base is broader:

- **Service businesses** (WooCommerce Bookings, consultancies, agencies)
- **Subscription / membership sites** (WooCommerce Memberships, paid content)
- **Donation / nonprofit sites** (charity stores, fundraising)
- **Lead-gen / signup forms** (WC checkout used as a sign-up flow)
- **Digital downloads** (sometimes counted as e-commerce, sometimes not)
- **Traditional product retail** (still the core, but not exhaustive)

Emitting `OnlineStore` for everything would mis-classify a meaningful fraction of merchants. `OnlineBusiness` covers the same intent ("this entity does business online") without claiming product retail.

The trade-off: we lose the ability to emit `currenciesAccepted` and `hasOfferCatalog` semantically (those are technically valid on Organization too, but they're more idiomatic on the OnlineStore subtype). For our audience, that's fine — better to be accurate at the type level than to over-claim retail-specific shape.

> **Status:** documented decision; the [code currently emits `@type: OnlineStore`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php#L662). Switching to `OnlineBusiness` is tracked as follow-up.

---

## `OnlineStore` (reference only — not our target type)

[Schema.org spec →](https://schema.org/OnlineStore) · ⚠️ **pending status (see [OnlineBusiness](#onlinebusiness--target-type) above)**

> Schema.org description: *"An eCommerce site."*

We **don't target** `OnlineStore` because its "eCommerce site" definition is too narrow for WC's actual user base ([rationale](#why-onlinebusiness-and-not-onlinestore)). This section is kept as a reference catalog.

### Direct properties

| Property | In doc? | Emitted under target type? | Source |
|---|---|---|---|
| `currenciesAccepted` | — | n/a (`OnlineBusiness` doesn't carry this — Organization can, but the property is most idiomatic on `OnlineStore`) | — |
| `isStoreOn` (`OnlineMarketplace`) | — | — | — *(would be useful for product stores mirrored on Etsy / Amazon / eBay; out of scope while we target `OnlineBusiness`)* |

Everything else on `OnlineStore` flows through the inheritance chain. See the [Organization](#organization-the-thing--organization-layer-of-onlinestore) and `Thing` tables above; those properties are still emitted under our target `OnlineBusiness` type.

### What changes if we ever wanted to emit `OnlineStore` (e.g. per-merchant opt-in)

A merchant who runs a strictly-products WC store could opt into `OnlineStore` for the more-specific signal. That would re-enable:
- `currenciesAccepted` as an idiomatic property
- `isStoreOn` for marketplace mirroring

For now, conservatism wins: the broader `OnlineBusiness` type accurately covers all WC use cases.

---

## Summary observations

1. **Coverage is biased toward "what we add beyond WC core"**. `JSON-LD-SCHEMA.md` documents fields the plugin contributes; WC-core-emitted fields (`name`, `description`, `image`, `url`, `aggregateRating`, `review[]`, top-level `OnlineStore` fields) are largely undocumented even though they're part of the shipped JSON-LD. This is a doc bias to consider correcting.

2. **`brand` IS emitted by WC core** via a separate handler. `WC_Brands::add_structured_data()` in `wp-content/plugins/woocommerce/includes/class-wc-brands.php` hooks `woocommerce_structured_data_product` at priority 20 — distinct from the main `WC_Structured_Data` class. An audit grep against just the main file would miss this; the lesson is to grep the broader plugin tree for filter handlers, not just the canonical class. Coverage is conditional: requires WC's modern brands feature and `product_brand` taxonomy values.

3. **Future #328 fills three gaps**: `Product.inProductGroupWithID`, `Product.isVariantOf`, and per-variant `Product` emission via `hasVariant`. Plus migrating `BuyAction.urlTemplate` to the WC Shareable Checkout URL format (`?products=ID:1`).

4. **High-coverage areas**: shipping (`shippingDetails`, `handlingTime`, `shippingRate`), returns (`hasMerchantReturnPolicy`), pricing (`priceCurrency`, `priceSpecification`, `priceValidUntil`), inventory (`inventoryLevel`), reviews (`Review[]`, `aggregateRating`), and identity (`logo`, `address`, `contactPoint`, `currenciesAccepted`).

5. **Biggest uncovered surface**: organizational metadata. `Organization` has 50+ direct properties. We emit a handful (logo, address, contactPoint, hasOfferCatalog, name, description, url). Most are niche or B2B-specific (DUNS, VAT ID, NAICS), but a few are broad-interest gaps — see follow-ups.

## Recommended follow-ups

In rough priority order:

1. **`Organization.sameAs`** — array of merchant social-profile URLs (Twitter, Facebook, Instagram). Filterable per-merchant in admin settings or auto-detected from common SEO plugins.
2. **`Product.itemCondition`** — new vs refurbished. Useful for resale stores; merchant-config required.
3. **`Product.gtin8` / `gtin12` / `gtin13` / `gtin14`** — more specific GTIN forms when WC's GTIN field has a value with the right length.
4. **`Organization.telephone`** — currently suppressed by default. Worth a per-merchant opt-in toggle.
5. **`Product.audience`** — target demographic (`PeopleAudience` with `audienceType`). Merchant-config; useful for kids/adult/professional/etc. categorization.
6. **Switch homepage `@type` from `OnlineStore` to `OnlineBusiness`** — broader fit for WC's full install base (services, bookings, memberships, donations, lead-gen, retail). Code change is one line in [`output_store_jsonld()`](../../includes/ai-storefront/class-wc-ai-storefront-jsonld.php) plus a `JSON-LD-SCHEMA.md` update. Decision rationale captured in [the OnlineBusiness section](#onlinebusiness--target-type).

These can be filed as standalone issues; none are blocked by the current PR pipeline (#328 → ProductGroup work).
