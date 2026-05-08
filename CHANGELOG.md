## [Unreleased]

### Features
### Fixes
### Refactors
### Tests
### Docs

---

## [0.11.0] – 2026-05-08

### Features

- **JSON-LD: known attributes now emit as typed Schema.org Product properties.** Closes #327.
  - WC attributes mapped to dedicated Schema.org typed properties: `pa_color`/`color`/`pa_colour`/`colour` → `color`, `pa_size`/`size` → `size`, `pa_material`/`material` → `material`, `pa_pattern`/`pattern` → `pattern`. All four target properties are `Text`-typed per spec; mapped attributes are excluded from `additionalProperty` to avoid double-emit.
  - Schema.org's primary directive — *"Always use specific schema.org properties when they exist"* — supersedes the generic `additionalProperty` route for these. AI agents reading typed `color: "Black"` get an unambiguous single-color signal that the joined `additionalProperty` fallback can't match.
  - Multi-value inputs (e.g. `Color: Black, Navy` on a misconfigured simple product) skip typed emission entirely and fall back to `additionalProperty` with the joined merchant string preserved. No silent data loss, no incorrect single-color claim.
  - Variation-defining attributes are skipped from both the typed property and `additionalProperty` on the parent — they describe variants, not the parent product. Per-variant emission is intentionally omitted until #328 (`ProductGroup` + `hasVariant`) lands.
  - Existing typed-property values in the markup (from WC core or other plugins) are not overwritten.

- **JSON-LD: variable products now emit as Schema.org `ProductGroup` with per-variant `hasVariant` entries.** Closes #328.
  - Variable products with at least one attribute marked "Used for variations" emit `@type: ProductGroup` with `productGroupID` (parent SKU, or post ID fallback), `variesBy` (Schema.org property URLs for axes that actually differ across variations — e.g. `https://schema.org/color`, `https://schema.org/size`), and `hasVariant: [...]` containing one Product entry per variation.
  - Each `hasVariant` entry carries the variation's own `name`, `sku`, `image`, typed Schema.org property (`color`/`size`/`material`/`pattern`) for its differentiating attribute, an `Offer` (price, currency, availability), and a `BuyAction` whose URL targets the **variation ID** so an AI agent's deep-link resolves to the specific SKU instead of the parent's "choose your color" detour.
  - Parent-level `offers` and `potentialAction` are intentionally dropped on conversion — buyers can't purchase the parent of a variable product, and per Schema.org, concrete offers belong on the `hasVariant` Product entries.
  - Core-typed override: when the parent's "Used for variations" flag is unset on `pa_color` / `pa_size` / `pa_material` / `pa_pattern` but variation children still have distinct values stored under `attribute_<slug>` postmeta, the plugin reads that meta directly and emits ProductGroup with the correct `variesBy` URL and per-variant typed property. Limited to the four core typed slugs because they have canonical Schema.org typed-property mappings; unmapped custom attributes still honor the parent flag.
  - When neither path surfaces a varying axis — variations exist but no core typed attribute and no parent-flagged attribute factually differ — the plugin falls back to simple-Product emission. With no `variesBy` to advertise, `hasVariant` would just hand agents N near-identical blocks they can't tell apart — better to emit a working single-SKU shape.
  - `Offer.checkoutPageURLTemplate` (Schema.org `Offer` property — a URL template per [RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570) that points at the offer's checkout page) emits alongside the existing `BuyAction.target.urlTemplate`. Same Shareable Checkout URL on both, so consumers reading from either property resolve a working AI-attribution-tagged link.

- **JSON-LD: `BuyAction.target.urlTemplate` now uses the WooCommerce Shareable Checkout URL format.** Closes part of #328.
  - Now points at `{home}/checkout-link/?products={id}:1&utm_source={agent_id}&utm_medium=referral&utm_id=woo_ucp&ai_session_id={session_id}` instead of the prior product-permalink + `add-to-cart` form.
  - The `?products=ID:QUANTITY` format goes through WC's `/checkout-link/` rewrite handler, which adds the item to the cart and redirects directly to checkout — no intermediate landing page for the buyer.
  - The store-level `SearchAction.target.urlTemplate` is unchanged (it still points at the WP search endpoint with the canonical `utm_id=woo_ucp` attribution shape).

- **JSON-LD: cross-sells and upsells now emit as `Product.isRelatedTo` and `Product.isSimilarTo`.** Closes #335.
  - WC cross-sell IDs map to `isRelatedTo` (Schema.org: *"a pointer to another, somehow related product"*) — the cart-page complementary purchases.
  - WC upsell IDs map to `isSimilarTo` (Schema.org: *"a pointer to another, functionally similar product"*) — premium / alternate versions of the same item.
  - Each related product emits as `{"@id": permalink}` only, not a full Product block, to keep markup compact. AI agents dereference `@id` to retrieve the linked product's own structured data.
  - Three guards: (a) IDs that fail `is_product_syndicated()` are silently dropped so excluded products aren't reachable via graph traversal; (b) deleted/trashed products (`wc_get_product()` returns false) are skipped; (c) hard cap of 10 entries per property prevents markup blowout on stores with very large cross-sell lists.
  - Existing `isRelatedTo` / `isSimilarTo` values in markup (set by WC core or another plugin's filter at higher priority) are preserved — same deference pattern as the typed-property emission.
  - Survives the `ProductGroup` conversion: `add_related_products()` runs before `maybe_convert_to_product_group()`, and Schema.org's `ProductGroup` is a `Product` subtype where both properties are valid.

- **JSON-LD: homepage Organization type switched from `OnlineStore` to `OnlineBusiness`.** Closes #334.
  - `OnlineStore` ("an eCommerce site") was too narrow for WC's actual install base — services, subscriptions, donations, lead-gen, and digital-download stores all emit the same homepage block. `OnlineBusiness` is the parent type in the Schema.org hierarchy (`Thing → Organization → OnlineBusiness → OnlineStore`) and accurately describes any WC merchant doing business online without claiming product retail.
  - All previously-emitted properties except `currenciesAccepted` (`name`, `description`, `url`, `potentialAction`, `hasOfferCatalog`, `logo`, `address`, `contactPoint`) are defined on `Organization` (or `Thing`) and apply cleanly to `OnlineBusiness` via standard parent-to-child inheritance — no field requires removal.
  - `currenciesAccepted` continues to emit despite Schema.org defining it on the `OnlineStore` subtype, not on the `OnlineBusiness` parent. (Schema.org property inheritance flows parent → child only — a property scoped to a subtype is NOT picked up by its parent.) The decision is an intentional non-domain pairing: most consumers parse `currenciesAccepted` regardless of the enclosing type, and stripping a meaningful machine-readable currency signal would be a regression. Strict validators may emit a non-fatal "unrecognized property for this type" warning — accepted tradeoff.
  - Homepage `OnlineBusiness` block now also emits `knowsAbout` as a Text array of the store's top product category names, sourced from the existing `get_catalog_summary()` 1-hour transient — no new query, no new cache. Omitted when the catalog is empty.

- **JSON-LD: homepage `OnlineBusiness` block now emits `hasMerchantReturnPolicy` at Organization level.** Phase 1 of #337.
  - Emits the store-wide `MerchantReturnPolicy` block directly on the `OnlineBusiness` entity when a return policy is configured (`mode !== 'unconfigured'`) and the WC base country is set. Schema.org consumers read the Organization-level block as the default store-wide commitment; per-Offer emission (unchanged in this PR) is the per-product override surface.
  - Reuses `build_return_policy_block()` (now `protected`) — the same builder already used for per-Offer emission — so both call sites produce identical block shapes for the same configuration.
  - No behavior change to existing per-Offer emission. Per-Offer is already override-aware (emits `MerchantReturnNotPermitted` for flagged products, store-wide policy for the rest), so the new Org-level emission ships alongside it as intentional defensive redundancy — consumers that don't implement Schema.org's Org-level → Offer-level inheritance still get the right answer per-product.

### Fixes

- **JSON-LD: per-variant `@id` no longer collapses to the parent URL when the parent's "Used for variations" flag is unset.** Closes #341.
  - Symptom on misconfigured variable products: every `hasVariant` entry shared the same `@id` (the bare parent permalink), breaking variant-graph traversal for AI agents — they couldn't dereference one variant's `@id` and tell it apart from a sibling's. WC's `WC_Product_Variation::get_permalink()` is gated by the same parent flag that #338's typed-property override addresses; when the flag is unset, `get_permalink()` falls through to the parent URL instead of the parent + `?attribute_<slug>=value` query args.
  - `add_variant_basics()` now detects the fall-through (`$variation->get_permalink() === $parent_product->get_permalink()`) and synthesizes the query-args URL from the same `read_variation_core_attributes()` postmeta source the typed-property override consumes. Result: each variant's `@id` carries its specific core-attribute value, distinct per-variant.
  - Scope-capped to the four core typed slugs (`color` / `size` / `material` / `pattern`) — same scope as the existing typed-property override. Variants differing only by an unmapped attribute (Logo, Style, Heel Height) keep the bare parent URL; surfacing variation noise the merchant intentionally hid would over-step the override's narrow scope.
  - Properly-configured variable products are unchanged: when WC's `get_permalink()` returns a distinct URL, that URL flows through unchanged.

### Refactors

- **`build_return_policy_block()` visibility promoted from `private` to `protected`.** Both call sites are in the same class and could call a `private` method directly — `protected` doesn't change that. The promotion exists to unlock anonymous-subclass test seams (same pattern used for `build_postal_address()`), specifically the new `test_org_level_and_per_offer_return_policy_blocks_are_identical_for_same_config` regression guard that exposes the builder as public via inline subclass to assert the shared-shape contract. Zero behavior change — visibility-only refactor. Both call sites (Org-level in `output_store_jsonld()` and per-Offer in `add_return_policy()`) reuse the shared builder so they produce identical block shapes for the same configuration.

### Tests

- **`JsonLdTest.php`** — 14 new unit tests covering typed-property emission for all four mapped slugs, UK spelling (`colour`), free-text capitalized slugs, multi-value skip + fallback, variation-defining skip, existing-markup preservation, unmapped-attribute passthrough, invisible-attribute skip, and whitespace-only value handling. Existing `test_visible_attributes_are_emitted_as_additional_properties` updated to use unmapped slugs (`pa_style`, `pa_origin`) since `pa_color`/`pa_size` now route to typed properties.
- **`JsonLdTest.php`** (PR #328) — 24 new unit tests across `detect_varies_by()` (5), `build_variant_entry()` (7), full ProductGroup conversion (7 — including the misconfigured-variable fallback regression guard), `Offer.checkoutPageURLTemplate` (3), and `allow_product_group_type` (2). The `allow_product_group_type` pair pins the WC core type-allow-list registration that prevents `ProductGroup` blocks from being silently dropped at `WC_Structured_Data::get_structured_data()`.
- **`JsonLdTest.php`** (PR #335) — 14 new unit tests covering: empty-input no-op for both properties, `@id` shape for both, syndication-exclusion filtering for both, deleted-product skip, existing-markup preservation for both, the 10-entry hard cap, explicit-empty-array suppression for both (`isRelatedTo => array()` is "caller already decided"), the both-keys-set short-circuit (no `wc_get_product()` calls when both are pre-populated), and per-list de-duplication of source IDs (corrupted/imported postmeta with `[101, 101, 102]` resolves each ID exactly once).
- **`JsonLdTest.php`** (PR-C — #334 + #337 phase 1) — 7 new tests + 1 renamed: `OnlineBusiness` `@type` flip (renamed from `..._uses_onlinestore_type` → `..._uses_onlinebusiness_type`), `knowsAbout` emission from catalog summary (3: emits Text[], omits when empty, `get_catalog_summary()` runs exactly once per render via transient-read counter), Org-level `hasMerchantReturnPolicy` (3: emits when configured, omits when unconfigured, omits when setting absent), and shared-builder identity (anonymous-subclass exposes `build_return_policy_block()` as public, asserts identical output for the same input — pins the visibility promotion's contract). Test infrastructure: split `capture_store_jsonld_filter_value()` into `stub_store_jsonld_environment()` + `run_store_jsonld_capture()` so tests can override `get_terms` for non-empty catalog data without Brain Monkey's last-call-wins clobbering.
- **`JsonLdTest.php`** (PR #341) — 2 new tests pinning the variant `@id` override path: `test_variant_id_synthesizes_query_args_when_permalink_falls_through` (fall-through case, core-typed attribute, expect synthesized `?attribute_pa_color=red` URL) and `test_variant_id_stays_at_parent_when_only_unmapped_attributes_differ` (fall-through case, only unmapped `logo` attribute, expect bare parent URL — pins the scope-cap). Existing `test_variant_entry_emits_id_url_and_name` clarified to call out that its distinct-permalink fixture exercises the common path explicitly.

### Docs

- **`JSON-LD-SCHEMA.md`** — added a `color`/`material`/`pattern`/`size` typed-property section under "Field reference" with the slug mapping table, emission rules, and a worked example. Updated the `additionalProperty` section to reflect the new exclusion semantics.
- **`JSON-LD-SCHEMA.md`** — added a `ProductGroup` / `hasVariant` / `variesBy` section documenting the variable-product emission shape, the misconfigured-variable fallback rule, and `Offer.checkoutPageURLTemplate` coexistence with `BuyAction`.
- **`JSON-LD-SCHEMA.md`** + **`SCHEMA-ORG-COVERAGE.md`** — added an `isRelatedTo` / `isSimilarTo` field-reference section covering the cross-sell → `isRelatedTo` and upsell → `isSimilarTo` mapping, the three guards (visibility, deleted-product skip, 10-entry cap), and existing-key preservation. Audit doc's hierarchy table flips the `isRelatedTo`/`isSimilarTo` row to ✓; active-follow-ups table strikes through #1.
- **`JSON-LD-SCHEMA.md`** + **`SCHEMA-ORG-COVERAGE.md`** — section heading renamed `## Store homepage: OnlineStore schema` → `## Store homepage: OnlineBusiness schema`. Added `### knowsAbout` and `### hasMerchantReturnPolicy (Organization-level)` subsections. Audit doc's `Organization.knowsAbout` and `hasMerchantReturnPolicy` rows flip to ✓; "Why OnlineBusiness and not OnlineStore" status note updated from "documented decision; code currently emits OnlineStore" → "Implemented in PR #334". Recommended-follow-up #8 (return-policy restructure) marks phase 1 done; phase 2 (skipping the per-Offer block when redundant with Org-level) ruled out — see audit doc for rationale.
- **`JSON-LD-SCHEMA.md`** (PR #341) — expanded the "Core-typed override" paragraph to enumerate three layers (axis detection, per-variant typed properties, per-variant `@id`/`url`) and explain the fall-through detection mechanism. Clarified the override's narrow scope — variants differing only by an unmapped attribute keep the bare parent URL.

### Chores

---

## [0.10.3] – 2026-05-06

### Fixes

- **No-space comma queries now resolve correctly.** PR #320. Closes #319.
  - `"Hoodies,Belts"` (no space after comma) returned no results because `extract_search_terms()` silently dropped the comma, collapsing the pair into the single unresolvable token `"hoodiesbelts"`.
  - Commas are now converted to spaces before the punctuation-drop pass — the same treatment as hyphens and slashes — so `"Hoodies,Belts"` extracts `["hoodies", "belts"]` and OR-joins when both terms resolve to taxonomy matches.
  - Behaviour is now identical to the spaced-comma case `"Hoodies, Belts"`.

---

## [0.10.2] – 2026-05-06

### Fixes

- **Comma-separated and "or"-connected multi-category searches now return results.** PR #316. Closes #315.
  - Queries like `"Hoodies, Belts"` returned zero results because commas were stripped before the OR-vs-AND join decision, so the connector was never detected.
  - The connector regex now also matches a comma followed by optional whitespace (`/\s+and\s+|,\s*/i`), treating spaced comma-lists the same as `and`-connected lists.
  - `"Hat or Shoes"` now always OR-joins — `or` is an explicit choice, so the `$all_taxonomy_matched` guard (required for `and`/comma to prevent false positives on product-description queries like `"blue and hat"`) is bypassed.
  - Known limitation: no-space commas (`"Hoodies,Belts"`) collapse to a single token in `extract_search_terms()` and still fall back to a title `LIKE` rather than an OR join.

### Docs

- **User guide refreshed for 0.10.1.** PR #317.
  - All screenshots replaced with tight element-level captures at 640 px width; file sizes reduced 70–95%.
  - New rate-limits screenshot (`06b-rate-limits.png`) added alongside the endpoint-info card.
  - Rate-limit preset names corrected to match UI labels (Recommended / Conservative / Generous / Custom).
  - Version footer updated to 0.10.1.
- **Engineering ARCHITECTURE.md documents context-sensitive OR-join logic.** PR #316, #317, #318.
  - Describes `$has_or_connector` / `$has_and_connector` / `$all_taxonomy_matched` interaction and the known no-space-comma limitation.

---

## [0.10.1] – 2026-05-06

### Fixes

- **Multi-category searches now return results.** PR #314. Closes #315 (partial).
  - Queries like `"Hoodies and Belts"` previously returned zero results because every extracted term was AND-joined, requiring a single product to satisfy all category constraints simultaneously.
  - When the raw query contains a whitespace-surrounded `and` connector **and** every extracted term resolves to a product taxonomy match (category, tag, brand, or attribute), the per-term SQL clauses are now joined with `OR` instead of `AND`. Products from each category are returned independently.
  - If any term is unresolved (falls back to a title `LIKE`) — even with `and` present — `AND` is preserved. The user is most likely describing attributes of one product (`"blue and hat"`) rather than listing distinct categories.
  - Comma-separated multi-category queries (`"Hoodies, Belts"`) are tracked as a known limitation in issue #315.

### Chores

- **Pre-commit hook no longer bumps `.pot` timestamp on commits with no translatable string changes.** `wp i18n make-pot` always writes a fresh `POT-Creation-Date` header; the hook now strips that line before checksumming and restores the prior timestamp when no msgid or line reference changed, preventing spurious `.pot` diffs in PRs that don't touch i18n strings.

---

## [0.10.0] – 2026-05-06

### Features

- **Homepage `OnlineStore` JSON-LD with auto-sourced brand identity.** PR #311. Closes #308.
  - `output_store_jsonld()` now emits `@type: OnlineStore` (a Schema.org `Organization` subtype) instead of `@type: Store` (a `LocalBusiness` subtype). AI-readiness audits looking for an `Organization`-shaped entity now find one. The `OnlineStore` type is the most accurate fit — the plugin only loads inside an active WC install, so the site is definitionally an online store.
  - Three new identity sub-fields, all auto-sourced from existing WP/WC data — no new merchant settings, no new admin UI:
    - `logo` — custom-logo theme mod with `get_site_icon_url()` as fallback. Omitted entirely when neither is set (Schema.org's `logo` is for the merchant's primary brand mark; emitting a default WP favicon URL would mislead crawlers about brand identity).
    - `address` — Schema.org `PostalAddress` block built from `WC()->countries->get_base_*` (the same values WC's WooCommerce > Settings > General "Store Address" form populates). Each sub-key (`addressLocality`, `addressRegion`, `postalCode`) is omitted when WC has no value; the whole block is omitted when no base country is configured. **`streetAddress` is intentionally suppressed even when WC has it** — for an `OnlineStore` (vs. a `LocalBusiness`) the street address adds little verification value (buyers don't visit) but the privacy/safety risk is real: many small Woo merchants populate WooCommerce > Settings > General with their home address (the field is required at WC setup so tax calculations work) and don't realize it would be published in machine-readable form. City + region + postcode + country preserve every meaningful identity signal (jurisdiction, shipping origin, fraud-check disambiguation) without the residential-address leak.
    - `contactPoint.email` — two-stage resolution mirroring WC's own "where do customer replies land" logic: (1) `woocommerce_email_reply_to_address` when `woocommerce_email_reply_to_enabled === 'yes'`, then (2) `woocommerce_email_from_address` as a fallback, *but* rejected when its local-part matches a noreply pattern (`noreply@`, `no-reply@`, `donotreply@`, `do-not-reply@`, case-insensitive). Many merchants set From to a noreply address to avoid bounce-handling — publishing it as a customer-facing contact would route real questions into a black hole. Each candidate validated via `is_email`. Whole block omitted when neither stage produces a usable address. **Does NOT fall back to `admin_email`** — admin email is intentionally private (password resets, security notifications) and merchants do not expect it to be published in JSON-LD.
  - Phone (`contactPoint.telephone`) and social profiles (`sameAs`) are not emitted from this plugin. Neither has a canonical WP/WC source today; ecosystem plugins (Jetpack, Yoast, etc.) capture these via their own settings. The `wc_ai_storefront_jsonld_store` filter is the documented injection point — see the filter docblock at the call site for an example.
  - Backward compatibility: existing fields (`name`, `description`, `url`, `currenciesAccepted`, `potentialAction`, `hasOfferCatalog`) emit unchanged. The only schema-shape change is `@type: Store` → `@type: OnlineStore` plus optional new keys appended at the end of the JSON-LD body.

### Fixes

- **Manifest and llms.txt hits from UCP-aware clients (UCPScanner, UCPCheckerBot, etc.) recorded as zero.** PR #307. Closes #309.
  - `WC_AI_Storefront_Robots::detect_crawler_from_ua()` only matched substrings against the hardcoded `AI_CRAWLERS` allow-list (curated for the search-era AI training-bot ecosystem). Any UA outside that list — including legitimate UCP discovery scanners that identify themselves with well-formed product tokens like `UCPScanner/1.0 (+https://ucpscanner.com)` — returned `''`, and the call sites in `WC_AI_Storefront_UCP::serve_manifest()` and `WC_AI_Storefront_Llms_Txt::serve_llms_txt()` skipped `record()` entirely. Merchants saw zero hits in the analytics page even when UCP scanners actively crawled their stores.
  - Added a stage-2 fallback that extracts the leading RFC 7231 product token when stage 1 misses. `UCPScanner/1.0 (+...)` records as `UCPScanner`; `UCPCheckerBot/1.0 (+...)` records as `UCPCheckerBot`; version variants of the same scanner roll up under a single row. Real browser visits record as `Mozilla` — intentional, low frequency, useful forensic signal that a human is poking at the URL. Empty UAs continue to short-circuit so anonymous requests aren't recorded.
  - Stage 1 (known-crawler substring match, longest-first) is unchanged: realistic UAs like `Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)` still translate to `Claude` via the recorder's brand-name table, regardless of position within the UA string.

- **llms.txt hits always zero on CDN-fronted installs.** PR #307. Same shape as the 0.9.1 manifest fix (#283); llms.txt was missed in that hotfix.
  - `/llms.txt` was emitting `Cache-Control: public, max-age=3600`, causing Atomic/WordPress.com CDN edges (and any reverse proxy) to serve from cache — PHP never executed, so `WC_AI_Storefront_Crawl_Logger::record()` was never reached for crawler hits even when the UA was recognized.
  - Switched to `Cache-Control: no-store`. The llms.txt body is still cached internally via the existing transient (1-hour TTL, single-flight protected), so per-origin serving cost remains low while the edge is bypassed.

### Refactors
### Tests

- **`RobotsTest.php`** — 10 new unit tests for `detect_crawler_from_ua()` covering: missing UA, blank UA, known crawler in realistic Mozilla-preamble UA, longest-token-wins (`Microsoft-BingBot-Extended` vs. `Bingbot`), stage-2 product-token extraction for UCPScanner / UCPCheckerBot / curl / Mozilla browser UAs, unparseable leading characters, and version-stripping (UCPScanner/1.0 and UCPScanner/2.4.1-beta both → `UCPScanner`).
- **`JsonLdTest.php`** — 24 new unit tests for the homepage `OnlineStore` JSON-LD covering: `@type` switch, logo-precedence (custom-logo wins over site-icon), logo omit-when-empty, address auto-source from `WC()->countries`, `streetAddress` privacy guard (omitted even when WC has it), address omit-when-no-country, contactPoint reply-to-precedence, contactPoint fall-through-to-from when reply-to enabled but address blank, contactPoint omit-when-from-noreply, dataProvider locking 13 noreply patterns including plus-addressing variants, regression guards on `support@noreply.example.com` (domain-only, publishable) and `noreplies@store.com` (substring lookalike, publishable), and a no-`admin_email`-fallback regression guard.

### Docs

### Chores

- **Local development — project-root `docker-compose.yml`.** PR #312. Replaces the `wp-env`-based local dev workflow with a project-root compose file matching the house style of woopay, woocommerce-payments, and woocommerce-gateway-stripe. `docker compose up -d` from a fresh clone now produces correctly-named containers under project `woocommerce-ai-storefront`; a one-shot `bootstrap` service auto-installs WP, downloads + activates WooCommerce + this plugin, sets pretty permalinks, flushes rewrite rules, and enables plugin syndication. Idempotent — subsequent `up` calls are no-ops. The `.wp-env.json` config is intentionally retained for backward compat. See `AGENTS.md` "Local development" section for the full workflow.

---

## [0.9.1] – 2026-05-04

### Fixes

- **UCP manifest hits always zero on CDN-fronted installs.** Closes #283.
  - `/.well-known/ucp` was emitting `Cache-Control: public, max-age=3600`, causing Atomic/WordPress.com CDN edges (and any reverse proxy) to serve the manifest from cache — PHP never executed, so `WC_AI_Storefront_Crawl_Logger::record()` was never reached and the hit was never written to the database.
  - Switched to `Cache-Control: no-store`. The manifest is generated per-request (one settings read + JSON encode, no external calls), so per-origin serving has negligible cost and restores accurate hit recording.

---

## [0.9.0] – 2026-05-04

### Features

- **Policies tab — Shipping card with merchant-configurable handling time.** Closes #278.
  - New **Shipping** card on the Policies tab lets merchants declare their order handling time (minimum and maximum business days) via a pair of 0–365 stepper inputs.
  - When both values are > 0, the plugin emits `OfferShippingDetails.deliveryTime.handlingTime` as a Schema.org `ShippingDeliveryTime` + `QuantitativeValue` block in the product JSON-LD. AI agents that surface shipping timelines (e.g. "ships in 1–2 business days") can read this directly.
  - Clamping is symmetric with the PHP sanitizer: `max` is always raised to meet `min`, never the reverse. Inputs are clamped 0–365 on both client and server.
  - A live preview beneath the steppers shows the would-be structured-data block so merchants can verify the output before saving.
  - New `WC_AI_Storefront_Handling_Time` sanitizer class, exposed through the admin REST settings endpoint.

- **JSON-LD — emit `shippingRate: 0` for unconditional free shipping.** Closes #279.
  - When a WooCommerce shipping zone covers the store's base country and contains a free-shipping method with no minimum order requirement, the plugin adds `shippingRate: { "@type": "MonetaryAmount", "value": 0, "currency": "USD" }` to `OfferShippingDetails`.
  - AI agents that compare shipping costs across merchants can now read "free shipping" as a machine-readable fact rather than infer it from the absence of a rate.
  - Lookup is per-request cached to avoid repeated zone queries on catalog pages.

### Fixes

- **Orders table — missing comma before "+N more" in items column.** Closes #281.
  - The items column truncates long order line-item lists to "Product A, Product B +2 more". The separator before the overflow count was a plain space; now a comma+space, consistent with the comma-joined visible items.

### Tests

- **`HandlingTimeTest.php`** — 16 PHP unit tests for `WC_AI_Storefront_Handling_Time::sanitize()`: non-array input → zero pair, `null`/integer input, missing keys, negative values → 0, ceiling clamp at 365, `max < min` correction, string-number casting, and happy path.
- **`JsonLdTest.php`** — new PHP unit test asserting `handlingTime` block is omitted when a pre-stored `{min:5, max:2}` pair bypasses the sanitizer; existing free-shipping tests updated to use `WC_Shipping_Zones::$test_zones` stub property instead of overriding the protected method.
- **`policies-tab.test.js`** — 7 JS unit tests for `applyHandlingTimeMin` and `applyHandlingTimeMax` clamping helpers, including PHP-direction alignment cases and the `min > max` guard in `deriveHandlingTimePreview`.

---

## [0.8.8] – 2026-05-03

### Features

- **Discovery tab — boundary note for general-purpose SEO crawlers.** Closes #268.
  - The "Allowed AI agents" section now includes a one-line helper text clarifying that this list controls AI-specific crawlers (ChatGPT, Claude, Perplexity, Gemini, etc.).
  - Notes that general-purpose search engines (Google, Bing, Yandex, etc.) are managed by WordPress core and any installed SEO plugin — not by this plugin.
  - Resolves the "I don't see Googlebot here — does that mean I haven't allowed it?" confusion for merchants without an SEO plugin.

- **Expanded AI crawler allow-list.** Added four AI agents to the canonical crawler list:
  - `YouBot` (You.com) — live retrieval + training. Default-on, `LIVE_BROWSING_AGENTS` general subgroup. Brand: "You" (matches the `you.com` canonical entry in `WC_AI_Storefront_UCP_Agent_Header::KNOWN_AGENT_HOSTS`, so UA-token traffic and UCP-attributed traffic from You.com roll up under one brand).
  - `Mistralai-User` (Mistral) — live retrieval. Default-on, `LIVE_BROWSING_AGENTS` general subgroup, following the `-User` suffix convention used by OpenAI / Anthropic / Perplexity. Brand: "Mistral".
  - `anthropic-ai` — Anthropic's older crawler identifier still seen in real logs alongside the newer `ClaudeBot`. Default-off, `TRAINING_CRAWLERS`. Maps to the existing "Claude" brand so per-vendor stats consolidate cleanly.
  - `Diffbot` — Knowledge Graph builder licensed by several LLM vendors as training input. Default-off, `TRAINING_CRAWLERS`. Brand: "Diffbot".

  Token→brand map in `class-wc-ai-storefront-crawl-logger.php` updated; admin UI checkbox list in `endpoint-info.js` updated; existing `RobotsTest::test_ai_crawlers_is_union_of_live_training_and_test` continues to pass since the flat `AI_CRAWLERS` constant was updated in lockstep.

### Fixes

- **`/llms.txt` now announces the UCP API instead of the raw Store API.** Closes #271.
  - The "API Access" section was pointing AI agents at `/wp-json/wc/store/v1` (WooCommerce's raw Store API), which bypasses the UCP layer's agent fingerprinting, rate limiting, and access control.
  - Now announces `/wp-json/wc/ucp/v1` (the plugin's purpose-built UCP REST surface) as the AI-agent front door, paired with the existing `/.well-known/ucp` Commerce Protocol Manifest.
  - Stale comment in the generator that claimed the plugin "does NOT expose its own authenticated API" updated to describe the actual UCP-on-top-of-Store-API architecture.
  - Pinned test renamed from `test_api_access_section_points_to_store_api_and_ucp` to `test_api_access_section_points_to_ucp_api_and_manifest`; new regression guard asserts `wc/store/v1` does NOT appear in the output so a future change can't quietly re-announce it.

- **Discovery tab — "Products seen" now reflects search-result visibility, not just lookup hits.** Closes #273.
  - Previously `catalog/search` recorded a single row with `product_id = 0`, so the products an AI saw via search never counted toward "Products seen" (the `COUNT(DISTINCT product_id) WHERE product_id > 0` aggregate filtered them out).
  - The handler now emits a per-result impression row under a new `ENDPOINT_STORE_API_SEARCH_HIT` endpoint alongside the existing search-request row. Capped at 50 impressions per search by default to bound write volume; merchants can override via the new `wc_ai_storefront_search_impression_cap` filter (return 0 to disable impression recording entirely).
  - "Catalog queries" / "Top searches" / by-agent counts unchanged — the new endpoint is excluded from those aggregates so a search returning 4 hoodies doesn't inflate the request count from 1 → 4.
  - Existing installs will see "Products seen" jump from however much it under-counted before to the actual distinct products surfaced to AI agents. Not a data-migration; just newly-recorded data feeding the existing query.

- **Discovery tab — relabel "UCP API hits" to "UCP manifest hits".** The card was summing `ENDPOINT_UCP` events, which only fire when an agent reads the static `/.well-known/ucp` manifest — not when it calls the UCP REST surface (`catalog/search`, `catalog/lookup`). Those calls are recorded under `ENDPOINT_STORE_API_*` and roll up into the "Catalog queries" card. The previous label suggested API traffic was being counted, which led merchants to read a 0 there as "no UCP API activity" when it really meant "no fresh manifest fetches". The label now matches what the field counts, parallel to the adjacent "llms.txt hits" card. Underlying API field name (`ucp_hits`) and recording behavior are unchanged.

### Refactors

- **`robots.txt` opt-in block now uses a single grouped rule (RFC 9309 §2.2.1).** Pre-0.8.8 the plugin emitted a separate `User-agent:` block with a duplicated 9-line Allow/Disallow body per allowed bot, producing ~200 lines on a default install with ~20 default-on crawlers. The opt-in path now emits all allowed `User-agent:` lines first, followed by a single shared rule body — same shape the opt-out block (`Disallow: /` for unchecked bots) has used since 1.6.1. Output drops to ~30 lines without changing the rules any crawler sees. Closes #267.

### Tests

- New `test_opt_in_block_uses_grouped_user_agent_form` pinning the consolidated shape: each allowed bot appears as its own `User-agent:` line, and the Allow rules appear exactly once for the whole group. The pre-existing `test_allows_ucp_rest_endpoint_for_every_crawler` was renamed to `test_allows_ucp_rest_endpoint_in_consolidated_block` and its assertion updated from "once per crawler" to "exactly once for the group".
