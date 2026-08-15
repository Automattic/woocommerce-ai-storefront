## [Unreleased]

---

## [0.36.0] – 2026-08-15

### Added

- **`/products.json` now emits the full Shopify variant and image shape (#627).**
  - Variants gain `grams`, `taxable`, `position`, `product_id`, `created_at`, `updated_at` and `featured_image`. Images gain `width`, `height`, `position`, `product_id`, `created_at`, `updated_at`, `alt` and `variant_ids` — previously each image was just `{ id, src }`.
  - `grams` converts from the store's configured weight unit rather than assuming kilograms, and rounds to an integer because Shopify types the field as one. An unrecorded weight emits `0`, matching a live feed where 6 of 413 variants carry `grams: 0` and none carry `null`.
  - **`images[]` now also includes photos assigned to individual variations.** WooCommerce keeps those outside the parent gallery, while Shopify guarantees a variant's image is always one of the product's images. Without this, `variant_ids` would come back empty on every image for a typical store and the field would do nothing. Expect `images[]` to grow on variable products whose variations have their own photos.
  - `variant_ids` reverses WooCommerce's relation — each variation points at one image; Shopify lists, per image, every variant using it — so one colourway photo maps to all of its sizes. That is how an agent picks the right photo when a shopper chooses a colour.
  - `alt` is emitted in **both** positions, deliberately diverging from Shopify, which carries it on `featured_image` but omits it from `images[]`. Alt text is often the only description of what is actually in a photo, which is exactly what an agent that cannot see the image needs.
  - A simple product's variant emits `featured_image: null` even when the product has photos, matching Shopify on 73 of 73 single-variant products measured. The field marks a photo belonging to one variant, and a simple product has no sibling to differ from — its photos are already in `images[]`.
  - Cost: about 8 extra queries on a 17-product store. Those are first reads of variation-owned attachments that were never fetched before because they were never emitted, not repeat reads of existing ones.

### Fixed

- **Simple products were missing their `options` key (#627).**
  - Shopify emits `options` on every product, including ones with nothing to choose, as `[{ "name": "Title", "position": 1, "values": ["Default Title"] }]`. We omitted it while still emitting a variant whose `option1` was `"Default Title"` — half the convention, leaving that value with nothing to say what it meant.
  - The cost was a crash rather than a missing fact. A client written against Shopify's shape assumes the key exists, so `product.options.map(…)` threw and `product["options"]` raised `KeyError` — on exactly the products a single-SKU store sells most of.
  - #627 originally scoped this out as "already at parity". That was measured against a catalogue with zero single-variant products in 250, so the sample could not show it.

- **Variable products could emit duplicate variant `position` values (#627).**
  - A four-variation product emitted positions `[1, 1, 2, 3]`. WooCommerce already returns children sorted by menu order, and its menu order is 0-based while Shopify positions start at 1, so reading the raw value and falling back to a 1-based index for "unset" mixed two numbering schemes and collided. Position is now the loop index alone, which is both dense and unique.
  - Found by comparing real output against a live store, not by the test suite — every fixture returned menu order 0, which never produced the collision.

- **Attribute seeding no longer races on every release — it can still race, just rarely (#629).**
  - Seeding was keyed to the plugin version, and that check runs on *every* request until one of them writes the new version. After an upgrade, several concurrent requests could each conclude that seeding had not happened yet and each start it. On a live store that produced two `Gender` attributes (#628).
  - It is now keyed to a version of the **attribute set** rather than of the plugin, and the check happens before any work is scheduled: an already-seeded store schedules nothing at all, rather than scheduling a no-op that could still race.
  - **The honest comparison: the window did not get narrower.** `needs_seeding()` is a read, and the flag it checks is written only at the very end of `seed()`, after all six `create_attribute()` calls have run. Pre-fix, the window closed the moment the *plugin*-version option was written, on `plugins_loaded`. Post-fix, on the deferred (non-activation) path, it closes later — after the six creates, on `init` — which is wider, not narrower. What actually improved is frequency: the window used to open on every release, on every store; now it opens only when a future change to the attribute set bumps `SEED_VERSION`.
  - Activation now calls `seed()` directly, which is a real improvement for the common case: only the activating request itself normally runs it, instead of every request racing until one of them wins. It is not race-free, though. WordPress core writes the plugin into `active_plugins` *before* it fires the activation hook, so a request that lands in that gap loads the plugin, sees the version mismatch, finds `needs_seeding()` still true, and defers its own seed run to `init` — racing the activation request's inline call.
  - Seeding is gated on the version change specifically, not on the whole branch. That branch also opens when a merchant toggles the syndication setting, and the toggle path carries the same multi-request exposure — every request sees the flag until the first one clears it. Seeding from a settings save would therefore have re-opened the very race this closes, on any store whose seed flag happened to be stale. A settings toggle is not an install event and has no business provisioning taxonomies.
  - The version-mismatch check stays as a backstop, because WordPress does not re-run the activation hook on an in-place upgrade — activation alone can never reach a store that already has the plugin. That backstop has no activation hook to serialise it, so a future `SEED_VERSION` bump reopens the same shape of race #628 hit, across every store, on whichever concurrent post-upgrade request gets there first.
  - **This release attempts one seeding run everywhere, not zero.** The new flag has never been written before, so every existing 0.35.0 store finds `needs_seeding()` true on this upgrade. It's harmless — #623 already created all six taxonomies, so every `create_attribute()` call returns `false` and nothing new is created — but it is a real run, not the skip that later releases at the same `SEED_VERSION` will get.
  - No merchant action needed: only the pre-release test store ran the affected version.

---

## [0.35.0] – 2026-08-14

### Added

- **Weight and dimensions now emit on `OfferShippingDetails` and on each variant (#614, #615).**
  - Schema.org defines `weight`, `height`, `width` and `depth` on `OfferShippingDetails` as well as on `Product`, and Google draws the same distinction: `product_*` describes the item, `shipping_*` describes the parcel. Both now carry the same values.
  - WooCommerce has exactly one set of dimension fields — filed under the product editor's **Shipping** tab, and consumed by WooCommerce's own shipping methods to compute rates — so that one set legitimately answers both questions. For a single-item order the numbers are identical; any divergence is packaging overhead, the same approximation Google Merchant Center already accepts.
  - **Each variant now carries dimensions too.** A consumer reading a single `hasVariant` entry — the node holding the purchasable offer — previously saw none. Values resolve per variant: its own if set, the parent's otherwise, which is WooCommerce's own getter inheritance rather than logic this plugin wrote. Every variant emits, including inherited values, so a variant read in isolation never looks like it has no shipping data.
  - Virtual products, whether simple or a variation, continue to emit no shipping/dimension data — the existing `needs_shipping()` / `has_weight()` / `has_dimensions()` gates are unchanged. Downloadable is a separate WooCommerce checkbox and does not suppress either field: a physical product sold with a bundled download still ships and still carries its dimensions.

- **The six recommended product attributes are now created automatically (#623).**
  - A fresh WooCommerce store ships with no product attributes at all, so merchants build them ad hoc, name them freely, and type values freely. The plugin now creates `pa_gender`, `pa_age_group`, `pa_color`, `pa_size`, `pa_material` and `pa_pattern` if they are missing. Seeding is attempted on activation, on every plugin upgrade, and whenever a merchant toggles the syndication enabled setting (the same internal flag that triggers a rewrite-rule flush) — not just on a version change. This is a no-op almost every time, since each attribute is skipped once it exists; the one visible case is a merchant who deliberately deleted one of these attributes seeing it recreated on the next such trigger. Use the `wc_ai_storefront_seed_attributes` filter (below) to opt out first if that's not wanted.
  - **Gender and Age group carry Google's complete accepted values** (`male`/`female`/`unisex`, and `newborn`/`infant`/`toddler`/`kids`/`adult`), because Google defines those lists exhaustively. Merchants should not need to add to them.
  - **Color, Size, Material and Pattern carry a small starting set** that merchants extend. Google treats these as free text and asks that submitted values match the merchant's own product page, so a canonical list would be wrong. Size deliberately uses abbreviations (e.g. `S`, `M`, `L` — the full set is `XS` through `3XL` plus `One size`) per Google's consistency guidance, rather than the `Small`/`Medium`/`Large` form WooCommerce's own sample data creates.
  - **An attribute that already exists is left completely alone**, terms included, decided per attribute rather than all-or-nothing. Existing terms may be variation axes, so adding to or renaming them would break variations and orphan product data.
  - New `wc_ai_storefront_seed_attributes` filter returns `false` to skip seeding entirely.

- **Gender and Age group now emit as a typed `Product.audience` block (#618).**
  - Google requires `gender` and `age_group` on all Apparel & Accessories products, and reads them from a typed `PeopleAudience` block, not from `additionalProperty`. A merchant's Gender attribute previously reached the markup only as a generic `additionalProperty` entry — published, but invisible to Google for that purpose. For an apparel product that means disapproval rather than a thinner listing.
  - Sourced from the `pa_gender` / `pa_age_group` attributes the plugin now seeds (#623), with a bare `gender` / `age_group` custom attribute accepted as a compatibility fallback. When both forms are present with different values, `pa_gender` wins over a bare `gender` (same for age group) — order-independent, via a `priority` on each map entry. The losing attribute is never discarded; it still emits as its own `additionalProperty` entry.
  - **Gender is not validated.** Any non-empty value emits as `suggestedGender` — a value matching `male`/`female`/`unisex` is normalised to lowercase, anything else (even a value Google itself rejects, like "Womens") emits trimmed and verbatim. `schema:suggestedGender` is Text-ranged, so this is valid markup either way; Google's Merchant Center and Search Console are the intended place to flag a bad value to the merchant, not silent validation here.
  - **Age group IS mapped, and can't follow gender's rule.** `suggestedAge` is a `QuantitativeValue` needing `minValue`/`maxValue`/`unitCode`, so an unrecognised bucket (e.g. "Grown-up") has no honest numbers to emit and falls back to `additionalProperty` instead. This is a data-model constraint, not an inconsistency with gender's pass-through behaviour.
  - Recognised buckets: `newborn` (0–3 `MON`), `infant` (3–12 `MON`), `toddler` (1–5 `ANN`), `kids` (5–13 `ANN`), `adult` (13+ `ANN`, no upper bound — matching Google's own worked example).
  - Variable products advertise `suggestedGender` / `suggestedAge` in `variesBy` when that's the varying axis, and each variant carries its own resolved `audience`, inherited per-field from the parent when the variation doesn't define its own.

### Fixed

- **Only the dimension axes WooCommerce actually holds are emitted.**
  - `has_dimensions()` in WooCommerce core is true when **any one** of length, width or height is set. The emitter published all three off that single gate, and WooCommerce stores an unset axis as an empty string — which casts to `0`. A merchant who recorded only a height was therefore publishing `depth: 0` and `width: 0`: fabricated measurements, presented as real ones.
  - Each axis is now emitted independently, gated on its own value. A `0` the merchant actually typed still emits — the check is for an empty value, not a falsy one, so a deliberate (if unphysical) zero is preserved rather than silently dropped.
  - Present since dimensions were first emitted, and made more convincing by the numeric cast below: `"depth": ""` reads as obviously absent, `"depth": 0` reads as a measurement. Caught in review before it reached three placements instead of one.

- **Product dimension values now emit as JSON numbers instead of quoted strings (#613).**
  - `depth`, `width`, and `height` published their `QuantitativeValue.value` as a string literal (`"value": "10"`) while `weight` on the same product published a number (`"value": 1.5`). Two adjacent properties in one block disagreed on type.
  - `QuantitativeValue.value` is Number-ranged. The markup was not invalid, since the property also accepts `Text`, but a consumer that doesn't coerce read a string where every sibling gave a number.
  - The weight cast was added as audit bug #4 for exactly this reason — WooCommerce persists both weight and dimensions as free-form decimal strings (`.5`, `10`). `get_dimensions( false )` returns those props untouched, and the fix was never applied to the dimension branch. All three now cast through `(float)`.
  - No change to which products emit dimensions, to unit codes, or to any other field.

### Changed

- **Dev tooling: bump `squizlabs/php_codesniffer` to 3.13.6 for CVE-2026-67434 (#611).** An OS-command-injection advisory (high severity, affecting `<3.13.6` and `>=4.0.0,<4.0.2`) was published 2026-08-05 and failed the `composer audit` CI gate on every open PR. The package is `require-dev` only and is not shipped in the plugin zip, so no released version exposed merchants to it; the risk was confined to running lint locally or in CI.

- **Dev tooling: clear ten npm advisories from the dev dependency tree (#616).** Transitive `devDependencies` only — nothing in the tree reaches the built bundle. Six alerts remain and are tracked in #596: `extract-zip` has no upstream fix at all, and `adm-zip`, `@opentelemetry/core` and `webpack-dev-server` each need a `@wordpress/scripts` semver-major that would be a larger change than the risk warrants. All are dev-scope, and the CI security gate scopes npm auditing to production dependencies.

### Tests

- **`JsonLdTest.php`** — new `test_dimension_and_weight_values_encode_as_json_numbers` asserts on the `wp_json_encode` output rather than the array. The pre-existing dimension test used `assertEquals( '10', … )`, which passes against both a string and a float under loose comparison and so could not detect this defect in either direction; the quotes are only visible after encoding. Also asserts `assertIsFloat` per value and that no `QuantitativeValue` anywhere in the markup encodes its value quoted.

---

## [0.34.3] – 2026-07-29

### Fixed

- **Out-of-stock products no longer advertise a buy link that errors at the cart (#606).**
  - An out-of-stock product emitted `potentialAction` (BuyAction) and `offers[].checkoutPageURLTemplate` alongside `availability: OutOfStock`. Following that advertised URL didn't merely dead-end — it landed on an empty cart carrying `?wc_error=You cannot add "…" to the cart because the product is out of stock`, so an AI agent or search crawler acting on the published JSON-LD reached a broken flow.
  - Both emission sites gated on `is_purchasable()` alone, which in WooCommerce core is `exists && published && has a price` and **never consults stock** — while `WC_Cart::add_to_cart()` rejects on `! is_in_stock()`. The two gates were checking different things. Buy-link emission now additionally requires `is_in_stock()`, at both the parent/simple path and the per-variant path.
  - Descriptive fields (`@id`, `name`, `sku`, `image`, `offers[].price`, `offers[].availability`) still emit, so agents continue to see that the product exists and why it can't be bought — they just aren't handed a URL that fails.
  - **Backordered products keep their buy links.** The predicate is `is_in_stock()`, not a stock-quantity test: a backordered product reports in-stock, WooCommerce accepts it at cart-add, and its availability is `BackOrder`. Gating on quantity would have suppressed exactly the oversold-but-orderable variants corrected in #601.

---

## [0.34.2] – 2026-07-29

### Fixed

- **Backordered variants no longer emit `availability: InStock` alongside a negative `inventoryLevel` (#601).**
  - A variable product oversold under an allow-backorders setting published `availability: https://schema.org/InStock` on every variant Offer while the same Offer carried `inventoryLevel.value: -4`, so the two fields told an AI agent contradictory stories about the same variant.
  - `WC_Product::is_in_stock()` is a lossy two-state view of WooCommerce's three-state `stock_status` — it reports `'outofstock' !== $stock_status`, so `onbackorder` reads as `true`. The per-variant Offer builder branched on that bool alone; the inventory emitter read `get_stock_quantity()` directly, which is not lossy. Variant Offers now map the full three states, matching WooCommerce core's own `WC_Structured_Data`, which checks the backorder case ahead of plain in-stock and has done so since WC 7.8 — comfortably below this plugin's WC 9.9 floor.
  - Only per-variant Offers were affected. Simple products and parent Offers are built by WooCommerce core, which already handled this correctly across the whole supported WC range. The UCP and `products.json` surfaces express availability as a required bool per spec and are unchanged.
  - `inventoryLevel.value` is now clamped to `0` instead of publishing a negative quantity. schema.org defines the property as the "current approximate inventory level", so `0` misrepresents nothing it promised to be exact, and `availability: BackOrder` carries the "still orderable" signal instead. A level of exactly `0` remains meaningful ("none on hand") and is still emitted; only an untracked (`null`) quantity suppresses the property.

### Changed

- **Dev tooling: bump `wp-coding-standards/wpcs` to 3.4.1 for CVE-2026-45293.** The WordPressCS arbitrary-code-execution advisory (high severity, affecting `>=0.14.1,<3.4.1`) was published 2026-07-28 and failed the `composer audit` CI gate on every open PR. The package is `require-dev` only — it is the PHPCS ruleset and is not shipped in the plugin zip — so no released version exposed users to it; the risk was confined to running lint locally or in CI.
  - `composer.json` already declared the constraint as `*`, so only `composer.lock` moved. `phpcsstandards/phpcsutils` (1.2.2 → 1.2.3) and `phpcsstandards/phpcsextra` (1.5.0 → 1.5.1) are bumped alongside it, because wpcs 3.4.1 requires them; a lockfile update naming wpcs alone silently resolves *backwards* to 0.14.0 (which predates the advisory range) instead of forwards. Repo-wide PHPCS is clean on the new ruleset with no code changes.

### Docs

- **JSON-LD field reference updated for the three-state availability mapping and the `inventoryLevel` clamp (#601).** `JSON-LD-SCHEMA.md` now documents why the out-of-stock branch is checked first (the `woocommerce_product_is_in_stock` filter can decouple the bool from `stock_status`), that Google's merchant-listing spec never reads `inventoryLevel`, and the shared-pool caveat — when stock is managed on a variable *parent*, every variant reports the same inherited number, which is faithful to WooCommerce but is not a per-variant figure.

---

## [0.34.1] – 2026-07-21

### Fixed

- **AI agent attribution no longer falls back to `ucp_unknown` for `Product/Version`-form `UCP-Agent` headers (#588).**
  - An agent that identified itself with a `Product/Version` header carrying a trailing comment — e.g. `UCP-Agent: Claude/4.6 (Anthropic)` — was attributed as `utm_source=ucp_unknown` instead of its brand. The parser rejected the parenthesized comment outright, and even a clean `Claude/4.6` had no product-token mapping. The order was still correctly counted as an AI order (via `utm_id=woo_ucp`); only the *which agent* was lost.
  - The header parser now tolerates and discards a single trailing `( … )` comment (RFC 7231 §5.5.3), taking only the leading product token. The comment body is not scanned for a nested product, so a browser-style User-Agent still buckets as "Other AI" rather than letting a bot name inside the comment masquerade as a known agent.
  - Added product-token mappings for the known answer agents (ChatGPT, Claude, Gemini, Perplexity, Copilot) plus their crawler user-agent tokens (`GPTBot`, `ClaudeBot`, `Perplexity-User`, …), so a client that sends its User-Agent value in the `UCP-Agent` header resolves to the correct brand and hostname instead of fragmenting into "Other AI".
  - Because the same header parser feeds the UCP access gate, a merchant who has disabled a brand's crawlers now blocks that brand's `Product/Version`-form requests too — consistent with how the profile-URL form already behaved.

---

## [0.34.0] – 2026-07-10

### Added

- **Product JSON-LD now emits `validFrom`/`validThrough` sale windows (#582).**
  - Google's Merchant Listing structured data reads these Offer properties to know when a sale price is active; the plugin previously emitted only the date-only `priceValidUntil` (from WooCommerce core), leaving the sale's start unstated.
  - When a product has a configured WooCommerce sale schedule and is currently on sale, the Offer now carries `validFrom` and `validThrough` as full ISO 8601 datetimes with the store timezone offset (e.g. `2026-07-31T23:59:59+01:00`). Each field is emitted independently, so an open-ended sale (start-only or end-only) is represented faithfully.
  - Variable products emit per-variant windows: each variation uses its own sale schedule, and a variant that is not on sale carries no window (the parent's window is never inherited). `priceValidUntil`, `priceSpecification`, and the flat `price` are unchanged.

---

## [0.33.0] – 2026-07-04

### Fixed

- **robots.txt no longer blocks advertised `/checkout-link/` buy-links (#578).**
  - The named AI-crawler group disallowed `/cart`, `/checkout`, and `/my-account`. The plugin's JSON-LD advertises `/checkout-link/?products=ID:1` buy-links (added in #575) that 302-redirect through cart/checkout; because Google evaluates robots.txt against the redirect *target*, Search Console reported those advertised buy-links as "Blocked by robots.txt" — defeating the crawled-checkout discovery the plugin exists to enable.
  - Removes all three page-level `Disallow:` rules from the AI-crawler group. Cart and checkout are dropped so buy-links resolve; My Account is dropped so crawlers can reach the store login link. This reverses the "blocks cart/checkout/account" behavior introduced in #571.
  - Note: WooCommerce core's `Disallow: /*?add-to-cart=` rules live in the `User-agent: *` group, which named crawlers do not read (RFC 9309), so these AI crawlers were never covered by them regardless. This is acceptable — robots.txt is advisory, buy-links target `/checkout-link/`, and the Store API rate limiter is the real enforcement. The opt-out `Disallow: /` block for unchecked bots is unchanged.

---

## [0.32.0] – 2026-07-03

### Changed

- **Structured-data action URLs are now bare (#574).**
  - `Offer.checkoutPageURLTemplate`, `BuyAction.urlTemplate`, and the homepage `SearchAction` target URL previously carried a `utm_source={agent_id}` placeholder plus attribution UTMs. When a search engine surfaced one of these to a human, the unsubstituted `{agent_id}` corrupted order attribution and mis-labeled the sale as an AI referral.
  - The checkout links now emit a bare `/checkout-link/?products=ID:1` (or the product permalink for bundle and grouped products), and the homepage search action emits a bare `?s={search_term}&post_type=product` (keeping only the required search-term placeholder). WooCommerce then records the real per-engine source (`Organic: Google`, `Referral: bing.com`). Agent attribution is unaffected: agents identify via the `UCP-Agent` header on `/checkout-sessions`, not these URLs.
  - Orders that originated from a checkout link are flagged from WooCommerce's own landing-page data for future reporting.

---

## [0.31.0] – 2026-07-02

### Added

- **IndexNow now submits product brand archive URLs (#569).**
  - Brand (`product_brand`) archive pages were the one taxonomy IndexNow skipped: "Submit entire catalog now" gathered products and categories but not brands, and editing a brand never pinged search engines.
  - Adds brand enumeration to the full-catalog submission and per-term change detection for `created`/`edited`/`delete` on `product_brand`, mirroring the existing category handling.
  - Permalinks resolve through `get_term_link()`, so the correct registered base (`/brand/` or `/product-brand/`) is used automatically. Stores without the brand taxonomy are unaffected.

### Changed

- **robots.txt per-bot rules are now a deny-list (#571).**
  - The named-crawler group listed `Allow:` lines for permitted paths; a named group ignores `User-agent: *` (RFC 9309), so an allow-list risked silently blocking any path not explicitly listed by omission.
  - It now blocks only cart/checkout/account and lets everything else be crawled by default (including product/category archives and sitemap paths). Two defensive `Allow:` lines for the `/wp-json/` commerce endpoints are kept as forward-looking insurance against a future broad `/wp-json/` block.
  - No behavior change for standards-compliant crawlers (an unlisted path was already crawlable); the win is robustness for naive parsers and future-proofing. WordPress core's `admin-ajax.php` exception and the opted-out-bot block are untouched.

---

## [0.30.0] – 2026-07-02

### Added

- **Yahoo's Slurp crawler is now a recognized regional (Asia) crawler (#568).**
  - Added to the `REGIONAL_CRAWLERS` allow-list (default-off, opt-in), since Yahoo's global web results are served by Bing (already covered by Bingbot) and Slurp's residual crawling is low-volume.
  - Discovery stats roll Slurp traffic up under the "Yahoo" brand.

---

## [0.29.2] – 2026-06-26

### Fixes

- **Plugin updates now refresh the archive `ItemList` cache (#562).**
  - The version-upgrade routine previously omitted the archive `ItemList` transient family, so a fix to ItemList generation (e.g. #559) kept serving stale output for up to the 1-hour TTL after an update.
  - The upgrade path now purges that cache alongside the existing llms.txt and feed-version busts, so corrected markup is live on the next request (DB-backed transients; a persistent object cache still expires at the 1-hour TTL).

---

## [0.29.1] – 2026-06-26

### Fixes

- **Archive product lists now match the theme's actual per-page (#559).** On block themes (the WooCommerce Product Collection block), the archive `ItemList` listed `posts_per_page` items — often fewer than the page renders (e.g. 10 listed vs 16 shown) — which violated Google's "list all items on the page" rule for the carousel. The list now follows the page's main query, so it reflects exactly the products shown, in order, on classic and block themes alike. (`numberOfItems` was already correct.)

---

## [0.29.0] – 2026-06-26

### Changed

- **Archive product lists now link to product pages instead of embedding partial products (#556).** The shop, category, tag, and product-search `ItemList` JSON-LD switched from Google's all-in-one carousel (each entry embedded a partial `Product`) to the summary-page format (each entry is a `name` + `url` pointer to the product page). Google now validates the full product on each product page — which already carries `size`, `color`, price, and every other field — so archive pages are no longer flagged for missing recommended product fields. AI agents get catalog data from the `/products.json` feed and the UCP API (advertised in `llms.txt`), not the archive markup.

### Fixes

- **Corrected the IndexNow card wording (#553).** The card no longer says engines "re-crawl in seconds." The instant part of IndexNow is the notification; the re-crawl is faster than waiting for an organic pass (days or weeks) but not literally seconds, and indexing is never guaranteed. The card now says engines "re-crawl promptly."
- **Return-policy selector no longer overflows the viewport on mobile (#552).** On narrow screens the return-mode and category segmented controls now wrap and stack vertically instead of running off the right edge.

---

## [0.28.3] – 2026-06-25

### Fixes

- **Product SEO titles no longer repeat the brand (#549).** On stores where the brand matches the store name, or the product name already contains the brand, the page `<title>` duplicated it — e.g. `Camp Shirt | Saltwarp – Saltwarp` and `Saltwarp x Thornwick Tote | Thornwick – Saltwarp`.
  - The brand is now appended only when it adds information: suppressed (case-insensitively) when it equals the store name or is already present in the product name.
  - Results become `Camp Shirt – Saltwarp` and `Saltwarp x Thornwick Tote – Saltwarp`; a genuinely distinct brand still appears, e.g. `Field Boot | Thornwick – Saltwarp`.
  - Structured data is unchanged — the brand remains a separate field in the product JSON-LD.

### Docs

- **Documented the brand-redundancy title behavior (#551)** in the User Guide metadata table and the Yoast-coexistence reference.

---

## [0.28.2] – 2026-06-23

### Features

- **IndexNow generates its verification key automatically when you enable it (#546).** Previously the card showed "(not generated yet)" until you clicked "Generate key"; enabling IndexNow now creates the key on the spot, so the card is ready to use immediately. The key is public-by-design (engines fetch it to confirm ownership), so there is nothing to defer. The Regenerate button still rotates it manually.

### Docs

- **User guide + API reference now document IndexNow.** Added §6 "Instant indexing (IndexNow)" subsection to the User Guide (opt-in toggle, verification key, Submit entire catalog now, status line, engine list with Google caveat) plus a §10 troubleshooting entry for HTTP 202 / key-validation issues. Added IndexNow admin routes (`POST /regenerate-indexnow-key`, `POST /indexnow-submit-all`, `GET /{key}.txt`) to the API Reference.

---

## [0.28.1] – 2026-06-23

### Fixes

- **IndexNow key file now serves directly, so submissions actually validate (#542).** On sites with trailing-slash permalinks (the WordPress default), the `/{key}.txt` ownership file was 301-redirected to `/{key}.txt/`. IndexNow validators fetch the exact `/{key}.txt` and don't follow that redirect, so key validation never completed and submitted URLs were silently dropped (the submission stays at HTTP 202 "validation pending"). The key file now returns a 200 at `/{key}.txt`, matching the llms.txt / UCP / products.json endpoints.

---

## [0.28.0] – 2026-06-23

### Features

- **IndexNow settings, controls, and a "Submit entire catalog now" action (#534, #540).** The IndexNow integration added in 0.27.0 now has a Discovery-tab card: turn it on or off, view and regenerate the ownership key (with a link to the `/{key}.txt` verification file), and see the last submission result.
  - **Now opt-in:** IndexNow defaults to off and is enabled from the new card (it was on-by-default in 0.27.0).
  - **Force-submit + first-enable seed:** a "Submit entire catalog now" button, plus an automatic seed when you first enable it, pushes every published product and category URL at once, so a new or freshly-imported store gets its whole catalog submitted right away instead of waiting for the change feed.
  - **Accurate scope:** the card names the engines that actually consume IndexNow (Bing, Yandex, Seznam, Naver, Yep); Google does not.

### Fixes

- **Category and product pages always emit a meta description again (#537).** The 0.27.0 Jetpack-coexistence fix suppressed Jetpack's description on commerce pages, but pages whose source text was empty (a category with no term description, a product with no short or long description) were then left with no `<meta name="description">` and no `og:description` at all. Bing Webmaster flagged the missing descriptions on category pages.
  - Categories now fall back to a generated "Shop {category} at {store}." description; products fall back to "{product} at {store}." when they have no authored description.
  - The shop page is unchanged (it already fell back to the store tagline).

---

## [0.27.0] – 2026-06-22

### Features

- **Instant indexing: catalog changes are pushed to IndexNow (#530).** When a product, category, or shop page changes, the affected URLs plus your AI-discovery surfaces (homepage, `/shop/`, `llms.txt`, `products.json`) are submitted to IndexNow, so participating search engines (Bing, Yandex, Seznam, Naver, Yep) re-crawl quickly, keeping the catalog current in AI-powered search results. Google does not consume IndexNow, so this complements (not replaces) your existing Google structured data and sitemap.
  - **Default on, gated behind AI syndication** and a new `indexnow_enabled` setting; the auto-generated key is served at a virtual `/{key}.txt` for ownership verification.
  - **Debounced and batched:** changes are deduped and submitted in a single WP-Cron batch — no per-save HTTP, no editor latency, and a bulk import collapses into one submission per window.
  - **Fail-safe:** unsupported responses are handled without retry storms; submission never blocks a save or surfaces to shoppers.

### Fixes

- **Single, correct Open Graph and meta description on commerce pages when Jetpack is active (#527).** On Jetpack-enabled stores (every WordPress.com / Atomic store) the plugin and Jetpack both emitted social/SEO tags on product, category, and shop pages, producing a duplicate `<meta name="description">` and duplicate — sometimes conflicting — Open Graph tags (e.g. `og:title` "Shop" vs the store brand). Bing Webmaster flagged the duplication.
  - Our own archive Open Graph is completed first: adds `og:locale`, an `og:image` fallback (configured default via the new `wc_ai_storefront_og_default_image` filter → site logo → site icon), and uses the store brand for `og:title` when the shop archive is the site front page.
  - Jetpack's overlapping output is then suppressed only on those commerce pages: its Open Graph block is removed (`wp_head` priority 9 — after Jetpack's priority-1 loader, before its priority-10 emit) and its SEO meta description dropped via the `jetpack_seo_meta_tags` filter. Off commerce pages Jetpack is untouched.
  - The SEO-plugin detector now recognizes Jetpack as auto-handled, so the "deactivate your SEO plugin" notice never names it.

---

## [0.26.0] – 2026-06-22

### Features

- **UCP catalog and checkout prices are now returned in the agent's requested currency (#517).** When an agent sends `context.currency` (e.g. CAD) on `catalog/search`, `catalog/lookup`, or `checkout-sessions`, prices come back converted to that currency — full WooPayments conversion (exchange rate + rounding + charm pricing), matching the buyer-facing product page — instead of the store base currency:
  - **Requires WooPayments >= 10.9** (its Store-API currency support). On older WooPayments the prices stay in the base currency, exactly as before.
  - **Unsupported currencies fail safe:** a requested currency the store does not accept (or no `context.currency` at all) returns base-currency prices and surfaces a `currency_conversion_unsupported` warning, so an agent never quotes a base-currency price as if it were the requested currency.

---

## [0.25.0] – 2026-06-21

### Features

- **Return and refund policy is now an explicit "Option A or Option B" choice in the Policies tab (#520).** Google's return-policy structured-data docs let a store express `hasMerchantReturnPolicy` two ways: **Option A**, inline structured fields (`applicableCountry` + `returnPolicyCategory`, plus return window / fees / methods); or **Option B**, a single `merchantReturnLink` URL pointing at the store's returns policy page. The plugin previously lumped both into one mode (a returns-page dropdown shown alongside the structured fields), which let them conflict; it is now one control matching Google's either/or model:
  - **Three top-level choices** — Not configured; **Link to a returns page** (Google's Option B, emits `merchantReturnLink`); or **Specify the details here** (Google's Option A, emits inline `returnPolicyCategory` with the return window / fees / methods) — revealing only the fields that apply.
  - **Fixes the silent-precedence bug** where a configured returns page quietly dropped the merchant's inline days/fees/methods from the emitted structured data, and the preview/emission divergence that came with it.
  - **JSON-LD and llms.txt readers kept in parity** — link mode requires a published page of the correct post type, and the inline returns-accepted claim is gated on the store base country, matching the emitter exactly.

### Fixes

- **The homepage and shop product list now include each product's description and return policy (#518).** Google's Rich Results flagged the root-domain list with "missing field description" and "missing field hasMerchantReturnPolicy (in offers)" because the lightweight list entries omitted them. Each entry now mirrors the product page, clearing both merchant-listing flags.

---

## [0.24.0] – 2026-06-20

### Features

- **Self-emitted SERP and social metadata on commerce pages, so a lean store can drop a separate SEO plugin (#511).** Product, category, and shop pages now get human-facing `<head>` metadata built automatically from WooCommerce/WordPress core data, with no configuration:
  - **Title** enriched with the core Brand taxonomy (`Product Name | Brand`), winning over an active SEO plugin via late `document_title_parts` priority — a single `<title>`, no duplication.
  - **Meta description** derived from core fields (product short/long description; category term description; shop page content, then store tagline), HTML/shortcode-stripped and truncated on a word boundary; omitted rather than emitted empty.
  - **Open Graph and Twitter Card** tags (product type with price and image; `website` type on archives); empty tags are omitted.
  - **Opinionated `robots noindex`** for `catalog_visibility=hidden` products and internal product-search results.
  - **Developer filters** are the only override surface: `wc_ai_storefront_emit_meta_tags`, `wc_ai_storefront_meta_title_parts`, `wc_ai_storefront_meta_description`, `wc_ai_storefront_og_tags`, `wc_ai_storefront_robots_noindex`.
  - **Migration nudge:** a dismissible admin notice appears when Yoast WooCommerce SEO, Rank Math, or All in One SEO is active, inviting deactivation with an inline pre-flight checklist (breadcrumbs, redirects, custom noindex, sitemap) and a link to a new coexistence guide. The plugin never reads an SEO plugin's stored metadata; WooCommerce/WordPress core is the single source of truth.

---

## [0.23.7] – 2026-06-20

### Fixes

- **The homepage/shop product list now includes each product's star rating (`aggregateRating`) when the product has reviews (#510).** Google's Rich Results flagged the root-domain list with a non-critical "no aggregateRating / review" merchant-listing recommendation, because the lightweight list entries omitted ratings even when the product pages carry them. Each entry now mirrors the product page: `aggregateRating` (rating value + review count) is emitted only when the product actually has reviews and reviews are enabled (matching WooCommerce core's own gate). Ratings are never fabricated, and individual review objects are intentionally not listed — the summary list carries the aggregate only. (Products without reviews are unaffected; the recommendation persists, on the product pages too, until products have real reviews.)

---

## [0.23.6] – 2026-06-20

### Fixes

- **The homepage/shop product list now includes each product's brand (and GTIN, when set) (#507).** Google's Rich Results flagged the root-domain product `ItemList` with a non-critical "missing brand / gtin" merchant-listing warning, because the lightweight list entries omitted both even though the full product pages already carry them. Each list entry now mirrors the product page: `brand` from the product's `product_brand` term (matching WooCommerce's `WC_Brands`), and `gtin` from the product's global unique ID — normalized and validated the same way WooCommerce core does, so a configured GTIN emits identically on the list and the product page. Products without a brand or GTIN are unaffected. (The GTIN half of the warning is a data gap: it persists, on the product pages too, until products actually have a GTIN set.)

---

## [0.23.5] – 2026-06-20

### Fixes

- **Simple products no longer report Google's critical "Missing field price" Rich Results error (#502).** Recent WooCommerce core builds the product `Offer` with the price *only* inside `priceSpecification` and never sets a flat `offers.price` — the field Google's merchant listing reads. The plugin now hoists the current price from `priceSpecification[0]` up to `offers.price` (mirroring how it already hoists `priceCurrency`), without overwriting an existing flat value and without disturbing the `ListPrice` entry, so sale prices still render. Variable products were already unaffected because their offers are built with a flat price.
- **`shippingDetails` is no longer emitted for virtual or downloadable products (#504).** The JSON-LD shipping block gated only on the store base country, so a no-ship product still advertised a full `shippingDetails` block (and its nested `handlingTime`) — contradicting the product itself and mismatching the Shopify-compatible feed, which already reports `requires_shipping`. Shipping schema is now gated on `needs_shipping()` per product and per variation; if the method is unavailable the code fails safe and still emits, never suppressing shipping for a real product.

---

## [0.23.4] – 2026-06-19

### Fixes

- **The homepage/shop product carousel no longer triggers Google's "Unnamed item" Rich Results error (#499).** The archive `ItemList` put a top-level `url` *and* a nested `item` on each `ListItem`, mixing Google's two mutually exclusive carousel shapes (summary-page vs all-in-one). Google read the top-level `url` as a summary entry, ignored the inline product, and couldn't resolve a name. Each `ListItem` is now `position` + the nested `item` only — the nested `Product` already carries the name, url, price, and image — so it's a clean all-in-one carousel.

---

## [0.23.3] – 2026-06-19

### Fixes

- **Product return-policy structured data no longer triggers Google's "two or more mutually exclusive properties" error (#496).** When a return-policy page is configured, the `MerchantReturnPolicy` block now emits only the page link (`merchantReturnLink`); otherwise it emits the inline detail (return window, fees, methods). Previously it could emit both, which Google Rich Results flags as an error on product listings/carousels. The recommended-only `refundType` field is no longer emitted, clearing a separate "missing field" warning.
- **Removed the visible per-product "Agent checkout" anchor (#496).** It printed raw checkout URLs and unsubstituted `{variation_id}`/`{agent_id}` placeholders near the top of every product page, where shoppers could see them. Agents still get the deterministic checkout URL from the `BuyAction` structured data and the UCP checkout-session flow; markdown-extraction agents fall back to the product page URL and `/products/{handle}.json`.

---

## [0.23.2] – 2026-06-18

### Added

- **A "Settings" link on the plugin's row in the Plugins admin screen (#494).** Prepends a Settings link (alongside Deactivate) that opens WooCommerce → AI Storefront directly, instead of making merchants hunt for it under the WooCommerce menu. Standard `plugin_action_links` affordance; the admin page slug is now shared via a single `WC_AI_Storefront::ADMIN_PAGE_SLUG` constant so the link and the menu can't drift.

---

## [0.23.1] – 2026-06-18

### Changed

- **The `/llms.txt` discovery link is no longer shown to human shoppers — it's now advertised to agents machine-only (#491).** 0.23.0 moved a visible "Machine-readable store data for AI agents" line to the top of every page so markdown-extraction agents could reach it past the truncation cut; that was intrusive to shoppers, and the casual fetchers it targeted already read products straight from the visible page (they hand the buyer a link rather than calling the API the doc enumerates). The visible line is replaced by two zero-pixel signals: an HTTP `Link: <…/llms.txt>; rel="alternate"; type="text/markdown"` response header (for header-inspecting clients) and a `<head>` `<link rel="alternate" type="text/markdown">` (for HTML-parsing crawlers — notably Googlebot, feeding the search-index discovery path, since `/llms.txt` stays indexable). The per-product "Agent checkout" link is unaffected.

---

## [0.23.0] – 2026-06-18

Agent-reachable surfaces: a cluster of fixes so markdown-extraction AI shopping agents (ChatGPT, Perplexity, Claude) reliably reach products, prices, checkout links, and images on every page they fetch.

### Features

- **`/llms.txt` (and its byte-identical `/agents.md` mirror) now point agents to the `.json` feeds for product images (#480).** A line in the read-only-browsing section tells agents that image URLs live in the feeds' `images[].src` — page `<img>` tags and JSON-LD `image` are stripped by markdown-extraction tools, so an agent reading the page HTML never finds them. Gated on the `products.json` feed being enabled.

### Fixes

- **The homepage now carries the product `ItemList` JSON-LD when the front page is the shop archive (#479).** Previously the root of a shop-as-homepage store (e.g. saltwarp.shop) emitted only navigational `OnlineBusiness`/`WebSite` JSON-LD, so an agent fetching the bare domain got no products or prices and had to discover `/shop/` separately. The front-page shop now emits the same `ItemList` + `Product` blocks (with prices) as `/shop/`.
- **The product checkout anchor and the `/llms.txt` discovery anchor now render near the top of `<body>` (via `wp_body_open`) instead of `wp_footer` (#477, #489).** On long pages — most visibly the front-page shop — footer output sits past the point where markdown-extraction fetch tools truncate, so the anchors were never reached. Checkout URLs also render as visible `<code>` text instead of `<a href>` (whose URL is dropped in extraction). A structural guard test keeps both anchors out of the footer; the WooCommerce structured-data `<script>` block deliberately stays on `wp_footer` (it needs footer-time data accumulation and is stripped regardless of position).
- **The bulk and per-collection product feeds now emit a single image per product (#478).** `/products.json` and `/collections/{handle}/products.json` could be truncated by agents before reaching deeper products; emitting one image each (at least one whenever any image exists, falling back to the first gallery image) keeps the payload small. The single-product feed `/products/{handle}.json` still returns every image.
- **The Discovery tab's activity card is now labeled "AI shopping-API activity" (#481).** The previous "AI agent activity" over-claimed coverage — the card counts only UCP catalog searches and lookups, not page/feed/llms.txt fetches (most of which are served from cache and never reach the server). Adds a scope clarifier and matching empty-state copy.
- **Discovery settings copy no longer contains em-dashes**, matching the project's merchant-copy convention (em-dashes have rendering edge cases in CSV-split and ASCII-only tools).

---

## [0.22.1] – 2026-06-18

### Fixes

- **The Shopify-compatible product feed now always reports prices in the store's base currency, not a visitor's geo-detected currency (#474).** With WooPayments multi-currency active, `/products.json`, `/products/{handle}.json`, and `/collections/{handle}/products.json` could cache and serve a converted presentment currency (e.g. CAD) to AI agents regardless of where the request originated — so an agent could quote the wrong price. The feed now reads base-currency prices (`get_price('edit')`, bypassing the multi-currency display filter) and bumps its cache version on upgrade to drop any previously cached converted prices. The currency-aware UCP catalog API and per-currency `context.currency` pricing are unchanged.

---

## [0.22.0] – 2026-06-17

### Features

- **Product pages now expose the deterministic checkout link as a visible, agent-readable footer anchor (#472).** Markdown-extraction AI agents (e.g. claude.ai `web_fetch`) strip the `<script>` JSON-LD where the `BuyAction` lives; this re-exposes the same checkout URL in the rendered page body so those agents can hand the buyer a working link. The per-product counterpart to the `/llms.txt` body anchor — closes the discover→handoff loop that previously broke at the BuyAction step.
  - **Simple products** render one direct checkout link; **bundle/grouped** render the permalink-based link.
  - **Variable products** render a construct kit — the per-variation URL template plus a link to `/products/{handle}.json` (when the products.json feed is enabled) — and concrete labeled per-variant links when there are ≤4 purchasable variations (above that, just the template, to avoid flooding).
  - Clickable links carry the esc_url-safe `ucp_unknown` attribution source; the `{agent_id}` placeholder stays on the non-clickable `<code>` template (the faithful `BuyAction` urlTemplate mirror) for agents that substitute their own id. Gated on `enabled` + product syndication; non-purchasable variations/products are skipped.

---

## [0.21.0] – 2026-06-17

### Features

- **Agent-reachable discovery: `/llms.txt` (and its byte-identical `/agents.md` mirror) now bootstrap fetch-only agents with real examples and a body-visible link. Closes #462.**
  - **Real catalog-lookup examples.** The `## For agents` "Batch lookup" line and the `## Read-only browsing` "Look up" bullet now lead with real syndicated-product ids and a real `?slug={handle}` example, drawn from a new `get_example_catalog_refs()` helper (falling back to the placeholder only when the catalog is empty). Allowlist-based agent fetch tools (e.g. claude.ai `web_fetch`) snap to the literal example query string they have seen — the old placeholder `?ids=prod_1,prod_2,…` resolved to a `not_found` stub, where a real example returns real product data.
  - **Bulk `/products.json` now listed in `## Read-only browsing`** (gated on `products_json_enabled`) as the simple no-params, no-POST whole-catalog surface for fetch-only agents, alongside the parameterless `/products/{handle}.json`. The UCP catalog endpoints still lead as the preferred structured option. This reverses the earlier "deliberately not listed / silent catch" stance for the read-only-browsing section only.
  - **Visible footer anchor to `/llms.txt`.** A new `render_discovery_link()` method, hooked on `wp_footer`, prints a small visible `<a href=".../llms.txt" rel="alternate" type="text/markdown">` in the page body on every page. Markdown-extraction fetch tools strip `<head>` `<link rel>` tags and `<script>` JSON-LD and only fetch URLs seen in prior fetched content, so the existing `<head>` UCP `<link rel>` is unreachable to them; a body anchor lets them reach `/llms.txt` and bootstrap discovery (which enumerates every other endpoint). This is the body-visible counterpart to the existing `<head>` link, not a replacement.
  - All three changes are gated on the master `enabled` setting, and `/agents.md` inherits them automatically.

- **Agent attribution can now be derived from the `User-Agent` header when no explicit identity signal resolves the agent. Closes #464.**
  - **New `UA_AGENT_HOSTS` map on `WC_AI_Storefront_UCP_Agent_Header`** — answer-agents only: `ChatGPT-User` / `GPTBot` / `OAI-SearchBot` → `chatgpt.com`, `Claude-User` / `ClaudeBot` / `Claude-SearchBot` → `claude.ai`, `Perplexity-User` / `PerplexityBot` → `perplexity.ai`. Generic indexers (Bingbot, Googlebot, Applebot) are deliberately not mapped and stay `ucp_unknown`.
  - **New pure method `classify_user_agent(?string $ua = null)`** reuses `WC_AI_Storefront_Robots::detect_crawler_from_ua()` (which gained an optional `?string $ua` arg) plus the existing `canonicalize_host()` / `normalize_host_string()`, returning the same `{name, source_host, raw_host}` triple the resolvers already produce, or `null`.
  - **Wired into both resolvers as the step before `ucp_unknown`:** REST `resolve_agent_host()` ("Path 3.5") and MCP `resolve_agent_data_from_name()` (empty-name branch only). Precedence: UCP-Agent profile → UCP-Agent product → body meta `source` / declared MCP name → UA-derived → `ucp_unknown`. Explicit signals always win.
  - **Merge with provenance.** An inferred ChatGPT attributes identically to a declared one (brand "ChatGPT", `utm_source=chatgpt.com` — same bucket), and the raw UA token (e.g. `ChatGPT-User`) is stored in the existing `_wc_ai_storefront_agent_host_raw` order meta, so merchants can distinguish declared (hostname) from inferred (UA token).
  - **Attribution only.** This does not touch access control (`check_agent_access` is untouched): a spoofed UA gains attribution credit but zero access. No new merchant setting.

- **`/llms.txt` (and its `/agents.md` mirror) now narrate the agent flow and a read-only-browsing surface.**
  - **`## Typical agent flow`** — a numbered sequence grounded in this store's real UCP endpoints: discover `/.well-known/ucp` → search → look up → create a checkout-session → hand the buyer the `continue_url` to pay on the store's own checkout (buyer-confirmed; no delegated or in-chat payment). A closing line points MCP-capable agents at the `catalog_search` / `catalog_lookup` / `checkout_create` tools — emitted only when the MCP transport is enabled.
  - **`## Read-only browsing`** — for agents that only read: the UCP catalog endpoints lead (structured, currency-aware), and the scoped `/products/{handle}.json`, `/collections/{handle}/products.json`, `/collections.json` paths follow as a Shopify-compatible convenience (only when the feed toggle is on). The bulk `/products.json` is now listed here too (when the feed toggle is on) as the no-params, no-POST whole-catalog surface for fetch-only agents, alongside the parameterless `/products/{handle}.json`; the UCP catalog endpoints remain the preferred structured path.

- **Scoped v2 endpoints for the Shopify-compatible feed — per-product, per-collection, and collection-list paths agents drill into after the bulk feed.**
  - **`GET /products/{handle}.json`** — a single product by slug. Returns `{ "product": { … } }` (a **singular `product` object**, not the bulk feed's `{ "products": [array] }`), the identical shape as one bulk-feed item. **404s** when the slug is unknown or resolves only to a hidden/unsyndicated product (never leaks; the 404 isn't cached).
  - **`GET /collections/{handle}/products.json`** — the products in one category (by slug), in the bulk `{ "products": [ … ] }` shape, paginated via `?limit` (default 30, max 250) and `?page`. An unknown or empty-after-gate category returns a uniform `200 { "products": [] }`, never a 404, so it can't leak which category slugs exist. A rewrite lookahead keeps `/collections/all/products.json` resolving to the bulk feed.
  - **`GET /collections.json`** — the store's category list in Shopify's `{ "collections": [ { id, handle, title, body_html, published_at, updated_at, products_count } ] }` shape. Only categories with at least one catalog-visible, syndicated product are listed, and `products_count` is that post-gate count, so the list never advertises a category the per-collection endpoint would return empty for. `published_at` / `updated_at` are `null` (the `wp_terms` table has no timestamps, and fabricating one would poison agent diff-sync).
  - All three sit behind the **same `products_json_enabled` toggle** as the bulk feed (no new setting), share the same edge-cache headers (`Cache-Control` via `discovery_cache_control()`, `Vary: Host`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex`, CORS `GET, OPTIONS`, `OPTIONS`→204), and apply the same `visibility=catalog` + `is_product_syndicated()` gate. Each response is cached under its own host-scoped, versioned-key transient family (`wc_ai_sf_prod_`, `wc_ai_sf_coll_`, `wc_ai_sf_colls_`), and the shared `wc_ai_storefront_products_feed_version` bump — now also fired on `product_cat` create/edit/delete — orphans them all at once.
  - **Per-product feeds now emit timestamps:** every mapped product carries `published_at`, `created_at` (both the WC created date), and `updated_at` (the WC modified date) as RFC 3339 UTC strings, or `null` when unset. Applies to the bulk `/products.json` feed and the new `/products/{handle}.json` endpoint.
  - **New filter `wc_ai_storefront_products_feed_collection`** (per-collection override; mirrors `wc_ai_storefront_products_feed_product`). Does not touch the UCP manifest, REST/MCP, `/llms.txt`, or JSON-LD.

- **Shopify-compatible `/products.json` catalog feed — a non-UCP, additive compatibility surface for AI agents trained to probe that endpoint. Closes #449.**
  - Serves the syndicated catalog at **`GET /products.json`** and **`GET /collections/all/products.json`** (an alias resolving to the same all-products handler — the primary and secondary catalog-probe paths we observe). AI shopping assistants that learned to read catalogs from Shopify stores try `/products.json` first against unknown stores; answering in the shape they parse zero-shot lets them ingest the whole catalog in one request.
  - **Shopify product shape** (pragmatic full): `{ "products": [ { id, title, handle, body_html, vendor, product_type, tags, variants[ { id, title, option1/2/3, sku, price, compare_at_price, available, requires_shipping } ], images[ { id, src } ], options[ { name, position, values } ] } ] }`. Sourced directly from WooCommerce product objects (not the UCP translator). `vendor` falls back to `null` (no brand), `product_type` to `""`; simple products emit a single `Default Title` variant and no `options`. Shopify-internal fields with no WC meaning (`admin_graphql_api_id`, `template_suffix`, `published_scope`) are omitted.
  - **Paginated:** `?limit=` (default 30, max 250, Shopify-style clamp) and `?page=` (1-based; out-of-range returns `{ "products": [] }`).
  - **On by default** via a new Discovery-tab toggle (`products_json_enabled`, default `'yes'`); merchants who don't want a bulk catalog mirror can switch it off (the endpoint then 404s, affecting nothing else). Gated on `enabled` + `products_json_enabled` + the existing per-product visibility/syndication rule, so the feed exposes exactly the same products as every other AI-facing surface.
  - **Edge-cacheable** like the other discovery surfaces (rewrite path, `Cache-Control: public, max-age` via `discovery_cache_control()`, `Vary: Host`, CORS, `OPTIONS`→204), so agent discovery bursts are absorbed by the CDN. Origin caches each `(limit, page)` page under a host-scoped, versioned-key transient; the `wc_ai_storefront_products_feed_version` option is bumped on product save/delete and settings change, orphaning every cached page at once.
  - **New filter `wc_ai_storefront_products_feed_product`** (per-product override; mirrors `wc_ai_storefront_ucp_product`). Does not touch the UCP manifest, REST/MCP, `/llms.txt`, or JSON-LD.

- **Tier 1 discovery enrichment: real policy links and agent rules in `/llms.txt`, plus auto-sourced `sameAs` social profiles in homepage JSON-LD. Closes #445.**
  - **`/llms.txt` `## Policies` section** (after `## Shipping & Returns`): links the store's actual Privacy (`get_privacy_policy_url()`), Terms (`wc_terms_and_conditions_page_id()`), and Refunds & returns (`woocommerce_refund_returns_page_id`) pages. Each line is emitted only when that page is configured, and the whole section is omitted when none are — no placeholders, matching the existing convention.
  - **`/llms.txt` `## Rules for agents` section**: three static rules in the store's terse voice — pace requests and back off on HTTP 429, checkout is buyer-confirmed (no delegated/in-chat payment; send the buyer the `continue_url`), and send `context.currency` (ISO 4217) for accurate pricing.
  - **Homepage `OnlineBusiness.sameAs` is now auto-sourced** from Jetpack Publicize connections, Yoast (`wpseo_social`, including handle-to-URL expansion for Twitter/X), and RankMath (`social_url_*`). Each provider is independently guarded; values are sanitized, restricted to `http`/`https`, and de-duplicated. The existing `wc_ai_storefront_jsonld_store` filter still runs after, so merchants can override or augment the result. The Jetpack read uses Jetpack's own cached connection transient (never a blocking remote fetch on the page-render path).

- **`/agents.md` mirror endpoint — serves the agent doc at the emerging canonical path. Closes #446.**
  - The store now answers `/agents.md` with a byte-identical copy of `/llms.txt` (some storefronts, e.g. Allbirds, publish both). One generator, one host-keyed content cache (no second cache key), so the two surfaces can never drift and the existing cache invalidation covers both.
  - Same edge-cache and CORS headers as `/llms.txt` (`Cache-Control` via `discovery_cache_control()`, `Vary: Host`, `X-Content-Type-Options: nosniff`, `Access-Control-Allow-Origin: *`), so agent discovery bursts are absorbed by the CDN. The one intentional difference is `Content-Type: text/markdown` (vs `text/plain`) because the URL carries a `.md` extension.
  - The shared document gains an **Agent doc** line under `## For agents` pointing at the canonical `/agents.md` URL.
  - The new rewrite rule rides the existing `add_rewrite_rules()`, so it is registered after the next rewrite flush (which the plugin already performs on activation and on plugin-version bump).

---

## [0.20.1] – 2026-06-13

### Fixes

- **Variable-product JSON-LD variants were missing `description` (plus `brand`, `category`, and offer `seller` / `priceValidUntil`). Closes #443.**
  - The `ProductGroup` conversion rebuilds each variation `Product` node from scratch and dropped the WooCommerce-core base fields that simple products keep — so Google Search Console reported every variant as having "no description," and variants also lacked `priceValidUntil` (a common Google "missing field" warning), `brand`, `category`, and the offer's `seller`.
  - Variants now inherit those fields from the parent: `description` (or the variation's own, if set, formatted identically to WC core), `brand`, `category`, and the offer's `seller` / `priceValidUntil`, plus an offer `url` pointing at the specific variation. Brings variants to structured-data parity with simple products.

---

## [0.20.0] – 2026-06-13

### Features

- **Discovery surfaces (`/.well-known/ucp`, `/llms.txt`) are now edge-cacheable.** Both were served `Cache-Control: no-store` to preserve per-request hit logging, which meant every AI-agent discovery fetch booted WordPress and counted against the WordPress.com/Atomic platform's per-origin request rate limit (HTTP 429 once a burst exceeds ~10 requests in a short window). They now send `Cache-Control: public, max-age=300` (filterable via the new `wc_ai_storefront_discovery_cache_max_age` filter); `Vary: Host` is retained. Because there is no edge-purge hook, `max-age` is the freshness SLA — a settings change propagates within that window.

- **Retired the "UCP manifest hits" and "llms.txt hits" stat cards** (and their `ucp_hits` / `llms_txt_hits` crawl-stats API fields). Once those endpoints are edge-cached, a cache hit never reaches PHP, so per-request counting is no longer accurate. Accurate counting for cached surfaces will move to edge logs in a later change. Note: because those endpoints are no longer logged per request, the surviving `total_requests`, per-agent request counts, and `throttle_rate` metrics no longer include `/.well-known/ucp` and `/llms.txt` fetches, so those numbers will step down after upgrade (expected, not a regression); per-agent discovery attribution will return via the planned edge-logs work.

- **`GET /catalog/lookup?ids=` batch lookup.** Fetch-only agents (that can't POST) can now look up many products in one request by passing the comma-separated UCP ids they got from `catalog/search` (e.g. `?ids=prod_22,var_4079`), instead of one request per product. Reuses the existing batch core: partial results, `not_found` messages for unresolved ids, capped at 100. The single `?id=`/`?slug=` forms are unchanged; a non-string `?ids[]=` returns 400. Over-cap requests now return the UCP-conformant `request_too_large` (was `invalid_input`) on both GET and POST.

- **MCP tool results now put the data in the model-visible text channel.** `catalog_search`, `catalog_lookup`, and `checkout_create` previously returned all product data only under `structuredContent`, with a bare label (e.g. "Catalog search") in the `content` text block — so MCP clients that surface only `content` to the model showed it no results. The `content` text now carries a compact, bounded summary (result count, `Title (id) PRICE` lines capped at 10 with an "…and N more" note, pagination/not-found hints, and the checkout `continue_url`); `structuredContent` is unchanged for clients that consume it. Prices render with the correct minor-unit decimals per currency.

- **MCP `initialize` echoes the client's requested protocol version.** When a client handshakes with a supported version (e.g. `2025-03-26`), the server now responds with that same version per the MCP lifecycle spec, instead of always returning its latest (`2025-06-18`).

---

## [0.19.1] – 2026-06-09

### Bug Fixes

- **`&amp;` in JSON-LD checkout URLs breaks non-browser consumers.** WooCommerce's `wc_esc_json()` serializer HTML-encodes every `&` to `&amp;` at print time, after all data filters run — meaning `checkoutPageURLTemplate`, `BuyAction.urlTemplate`, and variation `@id` URLs all contained literal `&amp;` in the raw HTTP response. `curl`, Python `requests`, and LLM tool calls would receive broken URLs. Fixed by replacing WC's `wp_footer` structured-data callback with our own that uses `wp_json_encode` with `JSON_HEX_AMP` (encoding `&` as `&`) and skips `wc_esc_json` entirely. Email order details are unaffected.

---

## [0.19.0] – 2026-06-08

### Features

- **Archive `ItemList` JSON-LD on shop, category, tag, and product-search pages.** Every WooCommerce archive page now emits an `ItemList` schema block (priority 6 on `wp_head`) so agents can read the product listing without following each individual product URL.
  - Each item carries `@type: ListItem`, a 1-based `position` (globally correct across pages), a nested `Product` stub with `name`, `url`, `sku`, `image`, and a `Offer` with `price` and `priceCurrency`.
  - `numberOfItems` is emitted only in `all` syndication mode (where every queried product is syndicated) to avoid inflated or misleading counts in `selected`/`by_taxonomy` modes.
  - Positions are globally offset by page: page 2 with `per_page=12` starts at position 13.
  - Cache keyed on `wc_ai_storefront_itemlist_{context}_{page}` (TTL 1 hour); **search pages are intentionally never cached** to prevent attacker-controlled query strings from flooding the options table with transients.
  - Output is guarded by `wp_json_encode` false-check — malformed UTF-8 suppresses the block rather than emitting broken JSON-LD or poisoning the cache.

- **`GET /catalog/search` and `GET /catalog/lookup` — public, query-string catalog endpoints.** Fetch-based agents that cannot POST a JSON body can now query the catalog directly via query-string parameters. Both routes are permissionless (`__return_true`) and share the exact same validation, filter, and response logic as the existing POST routes via shared transport-neutral cores.
  - `GET /catalog/search` — `?q`, `?category`, `?min_price`, `?max_price`, `?in_stock`, `?attribute[color]=blue`, `?page`, `?per_page`
  - `GET /catalog/lookup` — `?id=42` or `?slug=day-hoodie`; slug resolves via `get_page_by_path()`; both missing → 400; slug not found → empty result set
  - Both return a 503 envelope immediately when syndication is disabled, before any validation or DB query (parity with POST 503 behaviour).

- **Global `WebSite` + `SearchAction` JSON-LD on every page** (priority 4, before product and archive blocks). Advertises two search entry points to agents — the native HTML product-search URL and the new `GET /catalog/search` REST endpoint — using the `SearchAction` shape Google already exercises for sitelinks search boxes.

- **OpenSearch descriptor at `/opensearch.xml`** and `<link rel="search">` in every page `<head>`. Agents that scan `<head>` for a search interface find a machine-readable pointer to both the HTML search page and the REST catalog endpoint. The descriptor includes both a `text/html` URL template and an `application/json` REST URL template.

### Bug fixes

- **Validation-parity fix for `GET /catalog/search` numeric params.** The `(int)` pre-cast on `$min_price`, `$max_price`, and `$per_page` previously coerced non-numeric strings (e.g. `"abc"`) to `0` before they reached the shared `is_integer_like_non_negative()` validator — effectively bypassing rejection. Raw values are now forwarded to the validator unchanged.
- **Dead `is_woocommerce()` branch on search pages fixed.** `is_woocommerce()` is defined as `is_shop() || is_product_taxonomy() || is_product()`, all of which are false on WordPress search results pages, so the ItemList block was never emitted for product searches. The gate is now `'product' === get_query_var('post_type')`.

### Tests

- **`UcpCatalogGetRoutesTest`** — full coverage of GET search and lookup handlers: query-param mapping, non-numeric param forwarding (not coerced to zero), 503 parity, slug resolution, and missing-param 400 responses.
- **`JsonLdArchiveItemListTest`** — product-search gate, non-product-search exclusion, search-page cache skip (spy pattern), `wp_json_encode` false guard, `numberOfItems` mode guard, `found_posts` total count, page-offset position math, and contiguous positions when a product is filtered out.
- **`UcpOpenSearchTest`** — empty blogname fallback, 16-char truncation, and plugin-disabled 404 (with `@runInSeparateProcess`).
- **`uninstall.php`** — `wc_ai_storefront_website_jsonld` fixed-key transient and `wc_ai_storefront_itemlist_*` key-family wildcard deletions added to both single-site and multisite uninstall paths.

---

## [0.18.0] – 2026-06-09

### Features

- **`<link rel="ucp-agent">` injected in every page `<head>` pointing at `/.well-known/ucp`.** Caught by head-scraping agents (Perplexity, Bing, etc.) that read `<head>` before loading the DOM and may never reach `llms.txt`. Only emitted when AI Storefront is enabled.
- **Zero-results `hints` block in `/catalog/search` responses.** When a search returns no products, the response body now includes a `hints.zero_results` flag and `hints.recovery_steps` — a four-step recipe instructing agents to retry with a bare product noun, drop over-restricting filters one at a time, browse category slugs via the extension schema, and only then report unavailability. Agents that skip `llms.txt` cold-call recovery guidance at exactly the moment they need it.

### Docs

- **`docs/engineering/UCP-BUY-FLOW.md`** — added `<link rel="ucp-agent">` to the "Belt-and-suspenders surfaces" section.
- **`docs/engineering/ARCHITECTURE.md`** — updated discovery layer table to note `<head>` injection via `wp_head`.
- **`docs/engineering/API-REFERENCE.md`** — documented the `hints.zero_results` / `hints.recovery_steps` response shape under `POST /catalog/search`.

- **Catalog responses are now quoted in the agent's requested currency when WooPayments multi-currency is active.**
  - `POST /catalog/search` / `POST /catalog/lookup`: when `context.currency` is in `accepted_currencies`, every `price.currency` field in the response carries the agent's currency with amounts converted via WooPayments' exchange rate, rounding rule, and charm pricing. Prices are returned via the WC Store API's native `?currency=` query param — WooPayments applies its full price-mutation pipeline automatically at that layer.
  - `filters.price` bounds are pre-converted from agent currency to base currency via WooPayments' `get_raw_conversion()` before the Store API query, replacing Phase 1's filter-drop + warning fallback.
  - `POST /checkout-sessions` accepts `expected_unit_price.currency` in any currency present in `accepted_currencies`; the comparison runs in the agent's currency (e.g. EUR vs EUR).
- **Graceful fallback paths.** When the requested currency is not in `accepted_currencies`, or WCPay throws mid-dispatch, the response degrades to base currency with a `currency_conversion_unsupported` warning at `$.context.currency` (or `$.filters.price` for the filter-only path) — never an HTTP error.
- **`convert_amount()` helper added to `WC_AI_Storefront_Multi_Currency`.** Converts minor-unit price amounts between currencies via WooPayments' `get_raw_conversion()` (rate-only, no charm). Used today by the filter-bound pre-conversion path; will be dropped from that path once WooPayments adds Store API `min_price`/`max_price` conversion ([WOOPMNT-6166](https://linear.app/a8c/issue/WOOPMNT-6166) — Track B follow-up). A `TODO(WOOPMNT-6165 Track B)` breadcrumb at the call site marks the future cleanup.
- **Public MCP (Model Context Protocol) transport for external shopping agents.** A new JSON-RPC 2.0 / Streamable-HTTP endpoint at `POST /wp-json/wc/ucp/v1/mcp` exposes the same shopping capabilities as MCP tools, for agents that engage with MCP but not the UCP REST transport ([#418](https://github.com/Automattic/woocommerce-ai-storefront/issues/418)).
  - Three tools — `catalog_search`, `catalog_lookup`, `checkout_create` — wrap the **same** logic as the UCP REST endpoints through shared transport-neutral cores (one code path, two transports; the REST handlers were refactored into thin wrappers over those cores with no behavior change).
  - Public, gated by the existing UCP agent allow-list. Tool *discovery* (`tools/list`, `ping`) is open; *invoking* a tool requires an allowed identity — supplied by an optional MCP session (`clientInfo.name` from `initialize`, re-checked per call) or, sessionless, treated as an anonymous agent subject to the `allow_unknown_ucp_agents` policy. CORS is configured so cross-origin browser agents can read/echo `Mcp-Session-Id`; `initialize` is rate-limited. Checkout stays stateless — a `continue_url` handoff, never an inline order. MCP-originated orders resolve the same WC Order Attribution `agent_data` as the REST transport.
  - New **`mcp_enabled`** setting (default on when syndication is enabled) with an admin toggle; the `/.well-known/ucp` manifest advertises the `mcp` transport binding only when the endpoint is live.
  - Hand-rolled deliberately to preserve the plugin's zero-runtime-dependency design and WP 6.7 floor; the intent is to standardize on the official `wordpress/mcp-adapter` (the WordPress Abilities API + MCP Adapter stack WooCommerce core's `mcp_integration` uses) once it reaches 1.0 and the engagement hypothesis is validated.

### Compatibility

- **WCPay dependency for end-to-end filter + currency composition.** Filter bounds combined with a non-base `currency=` require a WCPay version including the [WOOPMNT-6165](https://linear.app/a8c/issue/WOOPMNT-6165) fix (Store API REST requests with `currency=` and `min_price`/`max_price` no longer redirected with bounds stripped). On older WCPay versions, `filters.price` is silently dropped for non-base-currency requests with the existing `currency_conversion_unsupported` warning — same fallback as Phase 1, no regression.

### Tests

- **New `MultiCurrencyTest` coverage** for `convert_amount()`: delegation to `get_raw_conversion`, negative-input guard, same-currency short-circuit.
- **`UcpCatalogSearchTest`, `UcpCatalogLookupTest`, `UcpCheckoutSessionsTest`** assert that the outgoing `WP_REST_Request` carries `currency=<code>` when `context.currency` is in the UCP request, and that filter bounds are pre-converted via `convert_amount` before the Store API dispatch.
- **MCP transport coverage** — `McpSessionTest` (identity gate: allow / deny / blank-name / known-brand), `McpToolsTest` (tool definitions + UCP-envelope → MCP result mapping), and `McpServerTest` (JSON-RPC dispatch, the full status taxonomy `400`/`403`/`404`/`429` and `-32700`/`-32600`/`-32601`/`-32602`, per-call re-gate, `initialize` rate-limit). `UcpNeutralCoresTest` proves the REST-handler refactor is behavior-preserving and that MCP attribution resolves the same `agent_data` as REST.

### Docs

- **ARCHITECTURE multi-currency entry** updated to describe the native `currency=` param mechanism and three public helpers (`get_accepted_currencies`, `stamp_currency_query`, `convert_amount`). Adds a caching guardrail note: catalog responses are currency-dependent, so any future cache layer must key on active presentment currency.

---

## [0.17.3] – 2026-05-18

### Docs

- **Catalog taxonomy guidance for AI discoverability.**
  - New USER-GUIDE §5b walks merchants through five principles for a strong AI-friendly catalog taxonomy: naming categories by what the product is (not who/when it's for), a two-level depth ceiling, every product on a leaf (not a parent), aligning with Google Product Taxonomy, and surfacing a Google Product Category per term. Includes a before/after worked example and a step-by-step reshape recipe.
  - JSON-LD-SCHEMA `Product.category` field expanded with a new *Sourcing and merchant control* subsection explaining the passthrough principle (no name transformation, no canonical-taxonomy normalization at emission time), a *Why not normalize to Google Product Taxonomy in the plugin?* design-rationale subsection that links to the canonical-taxonomy POC in #412, and a concrete description of the deepest-leaf breadcrumb selection algorithm with a worked Capsule/Activewear example.
- **MCP integration positioning updated to reflect WooCommerce core's native MCP support.**
  - README and ARCHITECTURE replace the absolute "No MCP support" stance with an accurate framing: WooCommerce core (10.3.0+) now ships native MCP behind the `mcp_integration` feature flag; AI Storefront does not register its own MCP abilities yet, but the path is open via the WordPress Abilities API. Clarifies that MCP is admin-side (merchants' own AI assistants) while UCP is shopper-side (external buyers' shopping agents) and the two are orthogonal audiences.
  - USER-GUIDE §5b adds an optional "let an AI assistant help you with the reshape" subsection that points merchants on WC 10.3+ to WooCommerce's MCP setup guide, with a least-privilege guidance for the WC REST API key, a developer-preview caveat, and fallback paths (WP-CLI, REST + Application Password) for stores not on MCP-supporting setups.

---

## [0.17.2] – 2026-05-15

### Fixes

- **Multi-currency support is now correctly hidden when WooPayments' multi-currency feature is toggled off.**
  - The probe now checks `\WC_Payments_Features::is_customer_multi_currency_enabled()` at runtime in addition to `function_exists('WC_Payments_Multi_Currency')`. The function persists from `plugins_loaded:12` and `get_enabled_currencies()` returns the historical configured set regardless of whether the feature is currently on — so without the runtime gate, merchants who toggle multi-currency off still saw their previously-configured currencies advertised.
  - UCP manifest `store_context.accepted_currencies` is now omitted entirely on single-currency stores (instead of emitting a 1-element `[base]` list that falsely signals multi-currency support). Matches the existing llms.txt behaviour.

### Tests

- **Two new test cases.**
  - `MultiCurrencyTest::test_get_accepted_currencies_wcpay_feature_disabled_returns_base_only_even_with_configured_currencies`: covers the runtime feature-flag gate.
  - `UcpTest::test_store_context_fields_include_accepted_currencies_on_multi_currency_store`: pins the multi-currency manifest shape now that single-currency omits the key.

---

## [0.17.1] – 2026-05-16

### Fixes

- **WooPayments multi-currency detection corrected for WCPay 10.x.**
  - Replaced the `\WCPay\MultiCurrency\MultiCurrency::instance()` probe (which returns `null` until WCPay's bootstrap completes) with `function_exists('WC_Payments_Multi_Currency')` plus a call to that global function. The global is registered by WCPay's compat layer only when the multi-currency feature is enabled, so its existence is both the feature-flag check and the singleton accessor.
  - Removed the `is_multi_currency_enabled()` guard, which does not exist on the `MultiCurrency` class in WCPay 10.x and caused `accepted_currencies` to always fall back to the store base currency on multi-currency-enabled stores.
- **Observability: catch blocks in the multi-currency probe now log diagnostic messages.**
  - Both `try`/`catch` blocks (WCPay probe and `wc_ai_storefront_accepted_currencies` filter) call `WC_AI_Storefront_Logger::debug()` on exception, making silent fallback-to-base-currency detectable in debug logs.

### Tests

- **Two new `MultiCurrencyTest` cases.**
  - `test_get_accepted_currencies_wcpay_function_returns_non_object_falls_back_to_base`: exercises the `is_object()` guard when the WCPay function returns a non-null scalar.
  - `test_get_accepted_currencies_memoizes_wcpay_call`: verifies the WCPay probe is behind the cache guard via Mockery `->once()` assertion.

---

## [0.17.0] – 2026-05-15

### Features

- **WooPayments multi-currency exposure across UCP, JSON-LD, and llms.txt.**
  - UCP manifest `store_context` gains an `accepted_currencies` array (base currency first).
  - Homepage `OnlineBusiness` JSON-LD `currenciesAccepted` becomes a space-separated list on multi-currency stores.
  - llms.txt gains an `**Accepted currencies**` line when more than one currency is enabled.
  - UCP `continue_url` and per-product `url` fields carry `?currency=XXX` when the agent sends `context.currency` in the accepted set, activating WooPayments' page-level currency switcher on the destination.
  - Per-product page JSON-LD already reflects `?currency=XXX` automatically (WooPayments handles the switch before our enricher runs).
  - New filter `wc_ai_storefront_accepted_currencies` for integrators using non-WooPayments multi-currency plugins.
  - Catalog response prices remain quoted in the store's base currency (live currency switching of UCP responses is Phase 2).

### Fixes
### Refactors
### Tests
### Docs

---

## [0.16.1] – 2026-05-14

### Features
### Fixes
### Refactors
### Tests
### Docs

- **User guide: reframe GMC compatibility, layer the smoke test, and add channel-split + sitemap diagnostics.** Reflects the January 2026 Google + Shopify UCP launch and live-fire findings from running the verification flow against a real store where Gemini hallucinated product results without ever fetching the domain.
  - **§1.2 GMC compatibility** rewritten from "doesn't compete" to "stacks with it." The Shopping Graph (retrieval, populated by GMC + Schema.org markup) and UCP (checkout handoff) are now two layers of the same Gemini agentic flow, not parallel channels. "Keep GMC if you have it" stays; "this plugin adds the layer GMC doesn't address" makes the stacking explicit. The matching bullet in "What this plugin does not do" upgrades "complementary, not substitutes" to "complementary and stacked: GMC feeds the retrieval layer, this plugin feeds the checkout-handoff layer."
  - **§4 smoke test** restructured into three verification layers, ordered most-reliable-first: Layer 1 (direct endpoint check of `/llms.txt` + `/.well-known/ucp`, deterministic), Layer 2 (UCPPlayground / UCPChecker, fetch-independent), Layer 3 (live AI assistant query, variable). The previous single-paragraph smoke test promised "real product names with prices within 3–10 seconds" — empirically too optimistic. Gemini in particular sometimes hallucinates plausible-sounding products without fetching at all. Adds the diagnostic prompt `"Did you actually fetch [your-store.com]?"` to surface hallucinations to the merchant directly. Per-engine fetch behavior (ChatGPT typically fetches, Gemini sometimes, Claude / Perplexity varies) is now stated explicitly. "If layers 1 and 2 succeed but layer 3 hallucinates, your store is correctly publishing" decouples plugin-correctness from AI-engine cooperation.
  - **§3 Agent / Referral stat card** picks up a diagnostic-use sentence: a near-zero Agent share when an agent like Gemini is sending traffic typically means UCP-manifest discovery is failing and the agent is deep-linking from Schema.org instead. Points back at Layer 1 of §4.
  - **§10 Troubleshooting** new entry "`/llms.txt` doesn't list a sitemap." `SITEMAP_CACHE_KEY` has a 24h TTL and is invalidated only on plugin-settings save (verified at `WC_AI_Storefront_Cache_Invalidator::invalidate_sitemap_cache()`, hooked to `update_option_<SETTINGS_OPTION>`). Merchants on fresh installs (where the sitemap takes time to emerge) or who just enabled an SEO plugin can see "no sitemap" cached for up to a day; the entry tells them save-any-setting will bust the cache.

---

## [0.16.0] – 2026-05-14

### Features

- **Admin: HelpMenu hairline separator between actions and Version row.** Wraps Documentation + Support in one `<MenuGroup>` and the disabled Version row in a second `<MenuGroup>`, so the rendered menu shows a single hairline between the action group and the metadata row. Same pattern as Gutenberg's More-options popovers and the WordPress.com admin user menu. Communicates the action-vs-metadata distinction structurally, so the disabled Version row reads as "info about the plugin" rather than a flat third action.

- **`/llms.txt`: restructure into seven catalog-discovery H2s.** Closes #398. The previous UCP-heavy structure (`## Store Information`, `## API Access`, `## Sitemaps`, `## Product Categories/Tags/Brands`, `## Checkout Policy`, `## Attribution`, `## UCP Extension`) becomes a discovery-first format matching the dominant 2026 ecommerce convention: `## Store`, `## Browse`, `## Catalog`, `## Shipping & Returns`, `## Structured data`, `## For agents`, `## Extension schema`.
  - **`## Store`** (currency + location + logo + support) draws on `WC_AI_Storefront_JsonLd::build_identity_fields()` and `::build_postal_address()`, the same single source feeding the homepage `OnlineBusiness` JSON-LD. Country codes resolve to human-readable names ("United States" not "US") via WC's bundled country list. No new merchant settings.
  - **`## Browse`** (Shop archive URL, Search URL template, sitemap discovery) carries the new `utm_medium=referral&utm_id=woo_llms` channel marker on publicly clickable URLs. No `utm_source` in the URL: the actual referring domain populates it from `Referer` downstream.
  - **`## Catalog`** reuses `JsonLd::get_catalog_summary()` so a single transient hit serves both surfaces. Adds a `Specializes in:` line mirroring `knowsAbout` from the homepage JSON-LD.
  - **`## Shipping & Returns`** sources from the same Policies-tab settings driving JSON-LD `OfferShippingDetails` (handling time) and `MerchantReturnPolicy` (return window / fees / country). Final-sale override preserved.
  - **`## Structured data`** explicitly directs agents at `BuyAction.urlTemplate` on product pages as the canonical deterministic cart link (handles per-type routing across simple / variable / bundle / grouped without requiring agents to memorize URL shapes).
  - **`## For agents`** collapses the previous `## API Access` + `## Checkout Policy` + `## Attribution` into three bullets covering capability discovery (manifest), API base (REST root), and checkout escalation. No UTMs on machine endpoints.
  - **`## Extension schema`** (renamed from `## UCP Extension: com.woocommerce.ai_storefront` — that title oversold the single-URL content beneath it and exposed package-namespace syntax to merchants reading their own llms.txt). The `<a id="ucp-extension">` anchor is preserved unchanged so the UCP manifest's `spec` URL still resolves.
  - Cart-link URL shape decision (revised from the issue body): llms.txt does NOT emit a cart-link URL template directly. Agents follow JSON-LD `BuyAction.urlTemplate` on each product page, which routes correctly across product types deterministically. Avoids committing the cart-link URL shape as a public stability contract.
  - Attribution: new `WC_AI_Storefront_Attribution::WOO_LLMS_ID = 'woo_llms'` joins `WOO_UCP_ID` and `WOO_JSONLD_ID` in the STRICT recognition gate and the `by_channel` SQL aggregation (both HPOS and legacy postmeta branches). `woo_llms` slots into the Referral channel alongside `woo_jsonld` in the merchant dashboard.
  - JsonLd visibility widening (refactor): `build_identity_fields()`, `build_postal_address()`, and `get_catalog_summary()` move from private/protected to public so the llms.txt builder can call them directly. Behavior unchanged; same data, two consumers.
  - LlmsTxtTest overhaul: 28 obsolete test methods removed (asserting old section names), 19 new tests added covering the new sections + UTM hygiene + section ordering. Net 54 → 48 tests, file 1,453 → 906 lines. Full PHP suite stays green (1,422/1,422).

### Fixes
### Refactors

- **CI: retire `release-drafter`, fold release-notes extraction into `release.yml`.** The drafter maintained a parallel draft Release populated from PR labels; the hand-curated `CHANGELOG.md` is the actual source of truth, so the two always drifted in formatting and scope. The drafter also auto-incremented PATCH from the last published Release, which mispredicted every MINOR cut and forced a manual UI rename of the draft. New flow: `release.yml` runs on `v*` tag push, extracts the matching `## [X.Y.Z]` block from `CHANGELOG.md` via awk, creates the Release with that body, and uploads the zip — all in one workflow run. Deleted `.github/workflows/release-drafter.yml` and `.github/release-drafter.yml`. RELEASE.md updated to reflect the simpler flow.

- **CI: drop GitHub "Pre-release" flag from releases.** GitHub's pre-release flag carries the semantic "not for production use," which contradicts what readme.txt's Description tells merchants under the Beta framing ("Production use is supported"). The Beta lifecycle status is already signaled through four in-product surfaces (PageHeader pill, HelpMenu Version line, readme Description, USER-GUIDE intro), where it reaches merchants who are actually using the plugin. Removing `prerelease: true` from `release.yml` also fixes a side effect: pre-release tags don't compete for GitHub's "Latest" badge, so the Releases page was showing v0.14.2 as "Latest" while v0.15.0 (pre-release) sat above it — actively misleading for merchants browsing direct. Also retroactively flipped `v0.15.0` to a full release so it inherits the badge. `make_latest: legacy` keeps the badge tied to chronological order, so hotfixes cut off older tags don't accidentally demote the newest release.

### Tests
### Docs

- **User guide: new "What this plugin does not do" section.** Heads off four common merchant misconceptions before they land as disappointment: this isn't a product feed for Google Shopping / Bing / Copilot / Meta Catalog (those need merchant-center uploads, this is crawler-discovered); it isn't for in-chat agentic checkout (UCP is shopper-present handoff, not ACP-style delegated payments); it isn't an AI chatbot widget for the merchant's own site; and it doesn't write or improve product copy. Placed directly after "What this plugin does" so the boundary reads next to the positive scope — merchants forming wrong expectations don't have to scroll to §1.1 to discover the limit.

---

## [0.15.0] – 2026-05-13

### Features

- **Attribution: split AI orders into Agent vs Referral channels.** Closes #387. JSON-LD `PotentialAction` URL templates now emit `utm_id=woo_jsonld` (the new "Referral" channel), distinct from `/checkout-sessions` `continue_url` which keeps `utm_id=woo_ucp` ("Agent"). Both stay in the STRICT trust bucket; the split lets merchants tell convertible AI traffic (live agent shopping sessions) apart from exposure AI traffic (JSON-LD-surfaced search results that a human clicks through).
  - New "Agent / Referral" stat card in the Overview grid, using the existing slash-comparison shape (e.g. "60% / 40%") — same visual pattern as the "AI orders: 5 / 5" card. Both channels named in the label, both shares in the value-line; merchants read the comparison by eye.
  - Stats payload extended with `by_channel` (per-channel orders + revenue + self-normalized share_percent) and `top_channel` (string utm_id or null). Transient cache key bumped to `_v2_` so v1-shaped cached payloads can't crash the new UI shape on rollout; `bust_stats_cache()` deletes both keys during the transition.
  - `share_percent` self-normalizes against the channel-known subset, NOT against `ai_orders` — a store with 100 AI orders / only 10 channel-known would otherwise render "Agent 7% / Referral 3%" against an unstated 90% denominator. The card answers "which channel matters more," not "what fraction of all AI orders carry a channel signature."
  - Transition note: existing crawler caches keep serving the old `woo_ucp` template for ~2-6 weeks post-deploy, biasing the split toward Agent during that window. Accepted as one-shot transition noise.
  - JSON-LD `BuyAction` / `Offer.checkoutPageURLTemplate` no longer emit the `ai_session_id={session_id}` placeholder. The channel-split design makes the placeholder semantically incoherent — `woo_jsonld` exists precisely to mark stateless scrape traffic, and no realistic consumer (crawler, AI surface, search-result re-server) has a session id to substitute. Session-bound attribution remains on the `/checkout-sessions` `continue_url` path where it's authentically present.

- **Admin: help menu in the PageHeader.** Closes #388. New help icon in the top-right of the plugin's admin header opens a popover with three items:
  - **Documentation** opens an in-product user guide in a new tab. The guide is rendered from `docs/user-guide/USER-GUIDE.md` to `USER-GUIDE.html` at `npm run build` time (new `bin/build-user-guide.mjs`, `marked` as a build-time dep) and ships co-located with the markdown source so screenshot images resolve from the merchant's own host via relative paths.
  - **Support** opens woocommerce.com's authenticated support contact form. Canonical support channel for WooCommerce-published plugins; routes merchants to a human, contextualized by their wc.com account.
  - **Version** shows the running plugin version as static text, read from `WC_AI_STOREFRONT_VERSION` via the localize-script payload.
  - Built on `@wordpress/components` `<DropdownMenu>` for keyboard accessibility, focus management, ARIA roles, and Esc / outside-click dismissal out of the box. Anchor semantics use real `<a href target="_blank">` so right-click "Open in new tab" works.
  - PageHeader's `aria-hidden` scope tightened: previously the entire header was hidden from screen readers (to avoid duplicate page-name announcement with the server-rendered `<h1>`); now only the brand-chrome wrapper is hidden, leaving the help button reachable. ARIA spec doesn't allow "unhiding" descendants of an aria-hidden ancestor, so the scope reduction was required for the help button to be accessible.

- **Admin: Beta status markers across three surfaces.** Lightweight indicators that signal the plugin's pre-1.0 lifecycle to merchants without disruptive renames:
  - PageHeader: subtle uppercase "BETA" pill next to the "AI Storefront" title. Eyebrow-label typography with a neutral gray background that doesn't compete with the brand chrome.
  - HelpMenu Version line: "Version X.Y.Z" becomes "Version X.Y.Z (Beta)". Free signal when merchants are already looking up the version (usually meaning "I have a question").
  - readme.txt Description: lead paragraph stating "Status: Beta" and framing the production-supported / feedback-shapes-1.0 expectation. Visible on wp.org plugin pages and the WP admin "View details" modal.
  - USER-GUIDE.md intro: matching blockquote near the top.
  - All four markers removable as a single grep+delete pass when the plugin reaches stable 1.0. No name changes, no version-tag complications, no migration.

### Fixes

- **HelpMenu tooltip position.** The hover tooltip on the help icon was rendering with the default centered-below position. On a top-right-anchored button this clipped against the WP admin chrome edge. Switched to `tooltipPosition: 'middle left'` via `<DropdownMenu>`'s `toggleProps` so the tooltip extends leftward and stays inside the viewport.

### Refactors

- **User-guide footer self-updates via build-time version injection.** `bin/build-user-guide.mjs` now reads `package.json`'s version and substitutes a `{{VERSION}}` placeholder in the markdown before rendering. The previous hand-edited literal ("Covers AI Storefront 0.10.1") was four releases stale. Structural fix prevents this drift category entirely going forward; also softened the WP/WC version baseline claim that came with it.

### Tests
### Docs

- **Em-dash cleanup across merchant-facing copy.** AGENTS.md forbids em-dashes in user-facing copy (rendering edge cases in CSV-split tools and ASCII renderers — the convention originates from a plugin Description: header rendering issue, and extends to readme.txt + all merchant-facing UI strings). Cleaned 5 in `docs/user-guide/USER-GUIDE.md` and 28 in `readme.txt`. Each replacement context-appropriate: colons for label-style flow, periods for sentence joins, parens for parentheticals.

- **User guide: independent validation tools section.** New section 4a points merchants at three widely-used community tools for verifying their UCP setup beyond what the plugin's own UI can show: UCPPlayground.com (end-to-end agent simulation), UCPChecker.com (protocol-conformance validation), and UCPRegistry.com (optional directory submission). Framed as third-party and independent of Automattic, with a fallback pointer to https://ucp.dev/ if any link goes stale. The existing 4a "What the homepage publishes" section becomes 4b; the inbound link from the URL-verification table updated accordingly.

- **User guide: broaden the "shopping assistant" framing to lead with shopper-present co-shopping.** Lead paragraph and door-1 description previously said "browse and buy on a shopper's behalf" / "agents that act on a shopper's behalf" — that's the autonomous-agent case, but it's not what UCP primarily addresses today. UCP's `/checkout-sessions` flow is explicitly designed around handing the shopper off to the merchant's web checkout, which is the dominant 2026 reality: the buyer stays in the chat to browse and compare, then walks through to the merchant's checkout when they're ready. Rewrote both surfaces to lead with that case, with on-behalf-of agents as the secondary scenario. Same plugin, more accurate framing for what merchants are actually buying into.

---

## [0.14.2] – 2026-05-11

### Features

- **UCP catalog: relocate `status` + timestamps under `metadata` per spec.** Closes #374. Post-repo-access audit found that `product.json` does not define `status`, `published_at`, or `updated_at` as first-class properties — they're business-defined extension fields, and the spec's `metadata` block is the official escape hatch. New shape:
  - `metadata.lifecycle.status` — always `"published"` (catalog handlers only translate Store-API-returned products, which already filters out drafts/private).
  - `metadata.timestamps.published_at` / `metadata.timestamps.updated_at` — ISO 8601 strings; the whole `timestamps` sub-block is omitted when no timestamps are available rather than emitted as empty scaffolding.
  - Top-level `status`, `published_at`, `updated_at` are dropped. Hard cutover — prerelease, no merchant adoption to migrate.

### Fixes

- **UCP / JSON-LD: unpurchasable variations no longer leak to agents.** Closes #373. A misconfigured variation (e.g. missing a price) was emitted with a usable-looking variant ID and a checkout URL that WC then refused at cart-add. Three coordinated guards:
  - **UCP catalog/search/lookup:** `fetch_variations_for()` now drops variations where `is_purchasable: false` before they reach the product translator. If every variation of a variable parent is unpurchasable, falls through to the existing `synthesize_default()` placeholder so the schema's `variants: minItems: 1` constraint still holds.
  - **UCP /checkout-sessions:** a stale or guessed unpurchasable variant ID is rejected with the new `item_unpurchasable` error code (distinct from `out_of_stock`) so agents can tell "no inventory" apart from "merchant misconfiguration / not for sale". Mixed carts retain purchasable line items while excluding unpurchasable ones from `continue_url`, `line_items[]`, and `totals`.
  - **JSON-LD:** unpurchasable variant entries under `hasVariant[]` emit descriptive fields (`@id`, `name`, `sku`, `image`, `offers[].price`) but suppress `BuyAction` and `Offer.checkoutPageURLTemplate`. Same treatment for the parent shape on an unpurchasable simple/variable parent.
- **UCP catalog: synthesized variant carries parent's `short_description`.** Closes #375. For simple / bundle / grouped products (and malformed-variable parents), the translator's `synthesize_default()` was emitting `description: { plain: "" }` unconditionally. For a UCP agent that drills into the variant ID directly — the variant entity IS what they see — that meant the variant looked uninformative even when the parent had useful copy. Now reuses the same `extract_description()` helper as the real-variation path (strip-tags + decode-entities). Graceful degradation preserved: when the parent has no `short_description`, the variant still emits `description.plain = ""`.

### Refactors

- **Dev tooling: bump wp-env memory limit to 512M.** Local `wp-env` stack was hitting PHP `Allowed memory size of 134217728 bytes exhausted` fatals during plugin activation (WC core + WC Subscriptions + WC Payments + Jetpack + this plugin overran 128M) and on `action-scheduler run` catch-up commands. Added `WP_MEMORY_LIMIT` + `WP_MAX_MEMORY_LIMIT` set to `512M` in `.wp-env.json`'s `config` block. Dev-env-only — production / staging WP hosts configure `memory_limit` through their own php.ini or wp-config.php directly.
- **Revert: UCP discount-code pass-through.** Reverts #380 (issue #376). Live testing on the Jurassic Tube tunnel with Gemini 3 Flash and Gemini 3 Pro via UCPPlayground revealed a structural gap: current agent harnesses don't yet expand their tool schemas based on UCP capability advertisements. The `create_checkout` tool was hardcoded with `line_items + quantity` only, so the agent had no `discounts.codes` parameter to populate regardless of what our `/.well-known/ucp` manifest or per-response envelope advertised. A spike test that added a minimum `discounts.applied[]` response shape produced no behavior change. Rolling back rather than ship a capability advertisement compliant agents can't yet use. Issue #376 reopened with a re-shipping checklist; revisit when major harnesses (UCPPlayground at minimum, first-party agent flows as they emerge) support capability-driven tool schema expansion.

### Tests
### Docs

---

## [0.14.0] – 2026-05-11

### Features

- **UCP: variable + variable-subscription products become first-class.** Closes #369. Three coordinated fixes ride together; each one alone would deliver partial value.
  - **Gap #1 — variant enumeration unlocked for `variable-subscription`.** `fetch_variations_for()` had a strict `'variable' !== $type` gate that excluded the WC Subscriptions extension type. Subscriptions parents collapsed to a single synthesized `var_<pid>_default` placeholder regardless of how many real `subscription_variation` children they had. Widened to accept both types — mirrors the existing `validate_product_type()` pattern. Variable-subscription parents now enumerate one variant per child, addressable by ID for agent-driven term selection.
  - **Gap #2 — subscription line items accepted at `/checkout-sessions`.** `subscription` and `subscription_variation` were rejected with `product_type_unsupported`. The rationale was empirically contradicted by the PR #367 audit ([comment 1](https://github.com/Automattic/woocommerce-ai-storefront/pull/367#issuecomment-4416019003), [comment 2](https://github.com/Automattic/woocommerce-ai-storefront/pull/367#issuecomment-4416036798)): WC's Shareable Checkout URL (`/checkout-link/?products=ID:1`) handles subscription sign-ups correctly, routing through the standard checkout-session flow with recurring billing intact. Removed both types from the rejection list. The docblock now cites the audit.
  - **Featured-variant precision (`match: featured`) + `variants[0]` reordering.** Pre-#369 catalog responses marked every variant as `featured` when an agent looked up a parent product — spec-legal but loose, contradicting UCP's "One featured variant per product" design expectation. New logic picks exactly one featured variant using `_default_attributes` when set (merchant signal) and falls back to first-by-`menu_order` otherwise. Sibling variants emit `inputs: [{id: <input>}]` with no `match` field (spec-clean per `input_correlation.json` where `match` is optional). The featured variant is also reordered to `variants[0]` to satisfy `product.json`'s verbatim _"First item is the featured variant for listings"_ — both signals agree.
  - **Permalink fallback for parent-only variable + variable-subscription inputs.** Sending the parent ID to `/checkout-sessions` previously hard-failed with `variation_required`. Now follows the configurable-bundle/grouped pattern: `continue_url` = parent's permalink with UTM, `messages` carries a `field_required` / `requires_buyer_input` entry explaining the buyer must pick a variation on the PDP. Status becomes `requires_escalation`. Crucially, this does NOT auto-resolve `_default_attributes` server-side — that would let agents bypass presenting choice to the buyer. The merchant's preselection still pre-fills the PDP dropdown (WC core behavior); the buyer retains the final choice.
  - **New pure helper `WC_AI_Storefront_UCP_Product_Translator::resolve_default_variation_id()`** — single source of truth for "does the merchant have an addressable default?" Returns the resolved variation ID when `_default_attributes` covers every variation axis, null when defaults are absent, partial, "any"-selection (empty-string slug), or pointing at an orphaned slug. Handles both WC Store API variation shapes: legacy `attributes[]` array (slug-keyed) and the WC 9.x default where `attributes[]` is empty and the active option set lives in the formatted `variation` string (label-keyed). 9 unit tests pin the behavior.
  - **Test infrastructure**: `bin/seed-subscription-fixtures.{php,sh}` idempotently provisions four WC Subscriptions products on local wp-env (simple sub, variable sub with merchant default, variable sub without default, malformed variable sub). Captured Store API responses under `tests/fixtures/store-api/subscriptions/`. Empirical verification against pierorocca.com fixtures cross-referenced in the PR.
- **JSON-LD: emit subscription billing terms on `Offer` for WC Subscriptions products.** Closes #368. Schema.org defines vocabulary for recurring-pricing terms (`UnitPriceSpecification.billingDuration`, `billingStart`, `priceComponentType`; `Offer.addOn`; `Offer.eligibleDuration`) — pre-#368 our enhancer left all of these unset, so a subscription product's JSON-LD looked identical to a one-shot purchase. AI agents and rich-result consumers had no way to learn "this is a $10/month recurring subscription with a 14-day free trial and a $5 sign-up fee" from the structured data alone.
  - **Recurring price** emitted as `priceSpecification: [UnitPriceSpecification]` with `priceComponentType: https://schema.org/Subscription` and an ISO 8601 `billingDuration` (e.g. `P1M` for monthly, `P1Y` for annual) derived from `WC_Subscriptions_Product::get_period()` + `get_interval()`.
  - **Free trial**, when set, is expressed as a two-element `priceSpecification` array — first entry at `price: 0` with `billingDuration` set to the trial window, followed by the recurring entry at full price. Schema.org has no dedicated trial property as of 2026-05 (no `trialDuration` / `freeTrialDuration` in the published vocabulary); array-position + price=0 conveys the trial-then-paid sequence without abusing `billingStart` (which is typed `Number`, not Duration — see https://schema.org/billingStart).
  - **Sign-up fee**, when set, is emitted as **both** an inline `UnitPriceSpecification` entry with `priceComponentType: https://schema.org/ActivationFee` AND a separate `Offer.addOn` block. Duplication is intentional — Schema.org's `ActivationFee` page carries the disclaimer "This term is in the 'new' area," so `addOn` (released vocabulary) covers consumers that haven't adopted the enum yet.
  - **Finite-length subscriptions** (`get_length() > 0`) emit `Offer.eligibleDuration` as a `QuantitativeValue` with UN/CEFACT `unitCode` (DAY/WEE/MON/ANN). Indefinite subscriptions omit the field.
  - **Variable-subscription parents** emit per-variation `priceSpecification` blocks under each `hasVariant[i].offers[0]` — `subscription_variation` children can have different billing periods (one variant monthly, another yearly), so each carries its own metadata.
  - **Gating**: every emission path requires `function_exists('wcs_is_subscription')` + `class_exists('WC_Subscriptions_Product', false)` + `is_subscription( $product )` to pass. Stores without WC Subscriptions installed get unchanged JSON-LD output.
  - **What `variesBy` does NOT change**: Schema.org has no normalized vocabulary for naming the subscription-duration variant axis — `Product.variesBy` accepts plain text or `DefinedTerm`. We pass through the merchant's WC attribute label (e.g. "Length", "Term", "Plan") as-is rather than overriding to an invented term. Machine-readable subscription semantics live on each variation's `priceSpecification`, not on the parent's `variesBy` label.
  - **UCP coverage**: this is pure Schema.org enrichment — verified that the UCP spec defines no recurring-billing primitives (grep across `Universal-Commerce-Protocol/ucp` source schemas for subscription/recurring/billing/trial returned no hits). No UCP REST changes ride along; PR #369 handled the UCP-side product-type unlocks.

### Fixes
### Refactors
### Tests
### Docs

---

## [0.13.2] – 2026-05-10

### Fixes

- **Fix bundle and grouped products surfacing broken AI-checkout links to crawlers and AI agents.** The Schema.org `BuyAction.target.urlTemplate` and `Offer.checkoutPageURLTemplate` emitted on every PDP routed bundles and grouped products to a checkout shortcut they couldn't satisfy, so AI agents and rich-result consumers that followed the link saw a silent failure or a no-op cart redirect.
  - **What was broken:** for bundle products, the emitted `/checkout-link/?products=BUNDLE_ID:1` would route to WC's add-to-cart handler with no per-bundled-item configuration — the bundle parent isn't independently addable. For grouped products, `/checkout-link/?products=GROUPED_ID:1` would try to add the UX-wrapper parent, which has no SKU or inventory of its own (only the children do).
  - **The fix:** `build_checkout_url_template()` now branches on `is_type('bundle')` / `is_type('grouped')` and emits the product permalink with the canonical UTM placeholders (`utm_source={agent_id}`, `utm_medium=referral`, `utm_id=woo_ucp`, `ai_session_id={session_id}`). The buyer lands on the merchant PDP where WC's existing bundle/grouped configurator runs; UTM attribution still flows through.
  - **Why permalink, not the deterministic `/checkout/?add-to-cart=…` form:** the deterministic shape used by the UCP REST `continue_url` would need child-resolution plumbing the JSON-LD path doesn't have, and would still fall back to the permalink for any configurable case (optional bundled items, variable children without bundle-author defaults). Permalink covers both cases with one shape.
  - **What's preserved:** simple, variable parent, and per-variation entries under `hasVariant` continue to emit the Shareable Checkout `?products=ID:1` form. WC core variations have `type === 'variation'` (distinct from `bundle`/`grouped`), so the type branch always falls through.

---

## [0.13.1] – 2026-05-10

### Fixes

- **Fix fatal `Call to a member function add_rule() on null` on plugin upgrade.** The version-mismatch detection branch in `WC_AI_Storefront::register_rewrite_rules()` was calling `add_rewrite_rule()` and `flush_rewrite_rules()` synchronously on `plugins_loaded`. WordPress core instantiates `$wp_rewrite` AFTER `plugins_loaded` (in `wp-settings.php`, between `plugins_loaded` and `init`) — so the inline call dereferences a null pointer and aborts the request, taking the entire plugin and any downstream `plugins_loaded` hooks with it.
  - **Why it fired now:** the buggy branch only runs when the stored `wc_ai_storefront_version` option doesn't match `WC_AI_STOREFRONT_VERSION`. On a same-version load (no upgrade) the option already matches and the branch is skipped. On 0.12.0 → 0.13.0 upgrades the mismatch is real, the branch fires, and every site fatals on the first frontend request post-upgrade. WordPress.com's auto-revert detected this and rolled affected sites back to 0.12.0.
  - **The fix:** removed the inline `add_rewrite_rules()` + `flush_rewrite_rules(false)` calls. The deferred `add_action( 'init', 'flush_rewrite_rules', 99 )` (already present in the same block) handles the flush at the right WordPress lifecycle point — `$wp_rewrite` is initialized before `init` fires, and `init:99` completes before `parse_request` runs in `wp()` (called from `wp-blog-header.php`). The current request still resolves `/llms.txt` and `/.well-known/ucp` correctly.
  - **Impact:** ships as a critical hotfix. All sites that hit the v0.13.0 fatal can upgrade directly to v0.13.1 without rolling back.

---

## [0.13.0] – 2026-05-10

### Features

- **UCP: WooCommerce Product Bundles plugin support.** Closes #358 (V2 deterministic-URL scope merged from #361).
  - Detects `type === 'bundle'` and the Bundles plugin's Store API extension under `extensions.bundles`. Bundles previously emitted as flat single-variant simple products with broken `/checkout-link/?products=ID:1` continue_urls.
  - **Catalog response enrichment:** `price_range` and `list_price_range` now come from `bundle_price.price.{min,max}` and `bundle_price.regular_price.{min,max}` — the actual buyable range spanning optional add-ons and per-child discounts (e.g. $20–$36.20 instead of flat $20). New `metadata.bundle = { min_size, max_size, items: [...] }` block exposes bundled-item structure so agents can describe the bundle accurately.
  - **Deterministic-bundle continue_url:** when every bundled item is required (no optional toggle) and every variable child has bundle-author-set defaults via `override_default_variation_attributes` covering all axes, the controller constructs `/checkout/?add-to-cart=BUNDLE&bundle_quantity_<bid>=…&bundle_attribute_<attr>_<bid>=…` directly. Buyer lands on `/checkout/` (2-step internal redirect via WC's `?add-to-cart=` form handler — buyer perceives one navigation). UTM attribution preserved via `with_woo_ucp_utm()`.
  - **Configurable-bundle continue_url:** when any bundled item is optional or any variable child lacks pre-set defaults, the URL builder returns null and the continue_url falls back to the bundle's product permalink. The buyer configures their choices on the PDP, then completes the purchase normally. Handler emits a spec-defined `field_required` error with `severity: requires_buyer_input` (per UCP checkout error-handling spec) so agents understand the bundle-specific escalation shape.
  - **Mixed/multi-bundle cart rejection:** when a bundle is sent alongside other line items (or multiple bundles in one cart), the handler returns `status: incomplete` with one spec-defined `field_required` error per bundle line item (path-attributed via `$.line_items[N]`) carrying `severity: recoverable` (per `message_error.json`: "platform can resolve by modifying inputs and retrying via API"). No `continue_url`. Agent must split the cart into separate `/checkout-sessions` requests. Replaces the earlier silent-drop + custom advisory message — this version aligns with `error_code.json`'s standard codes.
  - **Threading:** `process_line_item()` records `wc_type`, `permalink`, and `bundle_url_query` on each `$processed` entry. `build_continue_url()` reads these to branch (deterministic URL → permalink → checkout-link fallback).
- **UCP: WooCommerce Grouped Product support.** Closes #359.
  - Detects `type === 'grouped'` and the Store API's flat `grouped_products[]` int array on the parent. Grouped parents are UX wrappers around N independent children — previously rejected with `product_type_unsupported`; now routed through the same continue_url machinery as bundles.
  - **Catalog response enrichment:** new `metadata.grouped = { children: [int, ...] }` block exposes the parent's child product IDs so agents can cross-reference each child's individual catalog entry. Parent's `price_range` is left untouched — WC core already populates `prices.price_range` with aggregated min/max from the children.
  - **Deterministic-grouped continue_url:** when every child is `type === 'simple'`, the controller constructs `/checkout/?add-to-cart=PARENT&quantity[CHILD_ID]=N` directly. Uses PHP's `quantity[]=` array-querystring syntax which WC's legacy `?add-to-cart=` form handler parses into the documented grouped-products contract (`$_REQUEST['quantity'] = [<cid> => <qty>]`). UTM attribution preserved via `with_woo_ucp_utm()`. Top-level `quantity: N` from the agent multiplies each child's quantity (grouped has no parent inventory; per-child quantities are absolute).
  - **Configurable-grouped continue_url:** when any child is variable / external / etc., the URL builder returns null and continue_url falls back to the grouped parent's permalink. Handler emits a spec-defined `field_required` error with `severity: requires_buyer_input`.
  - **Mixed/multi-grouped cart rejection:** when a grouped parent is sent alongside other line items (or multiple grouped parents in one cart), the handler returns `status: incomplete` with one `field_required` error per grouped line item carrying `severity: recoverable`. No `continue_url`. Agent must split the cart. Same routing rationale as bundles: `/checkout-link/?products=` would add the grouped parent (a UX wrapper, not purchasable on its own) instead of the children.
  - **Threading:** `process_line_item()` records `grouped_url_query` on each `$processed` entry alongside the existing `bundle_url_query`. `build_continue_url()` reads both to branch (bundle → grouped → checkout-link fallback).

### Fixes

- **UCP: revert simple-product reserved-attribute promotion (#356).** Closes UCP Playground feedback "Issue C."
  - **What changed:** simple, bundle, and grouped products with Color / Size / Pattern / Material attributes (the four schema.org reserved variant names) now emit those attributes in `metadata.attributes` instead of `product.options[]`. The synthesized default variant emits no `options[]` (the array of `selected_option`-shaped entries that locks in a buyer's variant pick). Variable products are unaffected — their `has_variations: true` axes still emit `options[]` legitimately.
  - **Why:** UCP `product_option.json` characterizes options by example as size, color, or material — variant-selection axes the buyer chooses between, not descriptive properties. Non-variable products have no `has_variations: true` axis, so there's nothing to select. The picker an agent renders for a single-value `options[]` axis is theatrical at best and dishonest at worst.
  - **Concrete bug fixed:** the `$promote_to_options` block silently dropped values 2..N from multi-value reserved attributes. Production prod_24 (T-Shirt) was emitting `Color: [Beige]` and `Size: [XS]` to agents despite the merchant having declared `[Beige, Blue, Gray]` and `[XS, S, M, L, XL, XXL]`. Demoting to `metadata.attributes` emits all values without truncation.
  - **Schema.org alignment:** schema.org's `Product` accepts `color`, `size`, `material`, `pattern` as descriptive properties without requiring `ProductGroup`. The promotion was overreach.
  - **HTML entity decoding from #356 is preserved** — the `decode()` helper at every `name` read site stays in place. Only the simple-product → product-group promotion is reverted.
- **`SCHEMA_VARIANT_ATTRIBUTES` constant removed** from the product translator (no longer load-bearing — kept tightly associated with the promotion logic that's now gone).

### Refactors

### Tests

- Added 8 product-translator tests for bundle catalog handling (price_range, list_price_range, metadata.bundle structure, fallbacks).
- Added 9 checkout-sessions tests for bundle URL handling: configurable-bundle permalink + `field_required` error emission with `severity: requires_buyer_input`, deterministic-bundle constructed `/checkout/?add-to-cart=` URL with bundle_quantity_* params, optional-bundled-item makes bundle configurable, mixed cart with bundle rejected with `field_required`, multi-bundle cart rejected with one error per bundle, deterministic bundle alongside simple still rejects (must-split rule), missing permalink defensive fallback to `/checkout-link/`, UTM attribution flows through both deterministic and permalink paths.
- Added 9 product-translator tests for grouped catalog handling: `metadata.grouped` children-list emission, dedup + invalid-child-ID filtering, omission for non-grouped products + empty children lists, `build_grouped_url_query()` quantity-map output for all-simple children, null returns for variable children + fetcher misses, lazy-fetcher short-circuit on first failure, no accidental `metadata.bundle` cross-contamination.
- Added 11 checkout-sessions tests for grouped URL handling: configurable-grouped permalink + `field_required` error emission with `severity: requires_buyer_input`, deterministic-grouped constructed `/checkout/?add-to-cart=PARENT&quantity[CHILD]=N` URL, line-item quantity propagation (multiplication into per-child quantities), mixed cart with grouped rejected with `field_required` recoverable, multi-grouped cart rejected with one error per grouped, missing-permalink fallback to `incomplete`, missing-child fallback to permalink, request-index round-trip through dedup, UTM attribution on both paths.

### Docs

- **Removed stale `validate_product_type()` docblock claim** about a `purchase_urls.checkout_link.unsupported` UCP manifest field that doesn't exist in this plugin. Replaced with an accurate runtime-enforcement clarification (#363).
- **Engineering-doc sweep for bundle + grouped URL routing.** `UCP-BUY-FLOW.md`, `CART-MODELS.md`, `ARCHITECTURE.md`, and `API-REFERENCE.md` all assumed `continue_url` was always WooCommerce's `/checkout-link/?products=ID:QTY` Shareable Checkout grammar. Updated to reflect the five outcomes the controller now produces (Shareable Checkout for simple/variation; `/checkout/?add-to-cart=…` for deterministic bundle/grouped; PDP permalink for configurable bundle/grouped with permalink; no `continue_url` for mixed/multi container-type carts; no `continue_url` for configurable bundle/grouped without permalink). Added a Model 3 caveat to `CART-MODELS.md` clarifying that agent-constructed URLs aren't viable for bundle/grouped — not because the IDs are missing (catalog exposes them via `metadata.bundle.items[].bundled_item_id` and `metadata.grouped.children[]`), but because constructing a deterministic `/checkout/?add-to-cart=…` URL requires replicating server-side resolution (per-child stock checks, `add_to_cart.minimum` reading, default-attribute resolution for variable bundle children) that `/checkout-sessions` already performs.
- **Realigned `/checkout-sessions` request/response examples in `API-REFERENCE.md`** to the actual wire contract: `line_items: [{item: {id}, quantity}]` (not `items: [{variant_id}]`), `totals` as an array of `{type, amount}` entries (not a keyed object), HTTP 201 on the requires_escalation happy path. Removed `ai_session_id` from the example continue_url (the plugin's `with_woo_ucp_utm()` helper doesn't stamp it; agents append their own session-correlation token if they want it captured into order meta). Updated the `severity: advisory` claim on the `buyer_handoff_required` info message to match UCP `message_info.json` (info messages carry no `severity` field). Aligned `ARCHITECTURE.md`'s canonical-UTM-payload block with the helper's actual output, and `CART-MODELS.md` Model 2's flow text with the `line_items` request contract.

---

## [0.12.0] – 2026-05-08

### Features

- **UCP enrichment: stable `option_value.id` and `selected_option.id` from WC taxonomy slugs.** Closes #350 (option_value).
  - Format: `<taxonomy>:<slug>` (e.g. `pa_color:black`). Per `option_value.json` and `selected_option.json` (release/2026-04-08), `id` is optional but "the server SHOULD use it for matching" — letting agents echo the stable identifier back via `selected_option.id` for cross-locale variant matching, instead of relying on the displayed `label` (which may be translated).
  - Emission gated on `taxonomy` starting with `pa_` (real WC taxonomy attributes only). Custom inline attributes have no canonical identifier and omit `id` per the spec's optional-field semantics.
  - Variant translator threads a per-axis `term_slug_map` (built once per product from the parent's `attributes[].terms[].{name,slug}`) to emit `selected_option.id` on every variation. Mirrors the `parent_attribute_names` pattern from #348 — pure-function contract preserved (no `wc_get_product()` / `get_term()` inside translators).
- **UCP enrichment: hierarchical category strings per `category.json`.** Closes #350 (category hierarchy).
  - Per UCP `category.json` (release/2026-04-08), hierarchy is encoded as a `>`-delimited string in the `value` field (e.g. `"Clothing > Tshirts"`). Pre-#350 we emitted bare leaf names; post-#350 we emit the full ancestry path when available.
  - Controller pre-builds a `category_paths` map once per request via a new `build_category_paths_map()` helper that walks parent chains and batch-fetches missing ancestors via `GET /wc/store/v1/products/categories?include=<csv>`. Iteration capped at 10 for cycle defense.
  - Brands stay flat (`product_brand` has no hierarchy in WC). Categories without resolvable parents fall back to bare `name` for graceful degradation. Backwards-compat: nullable parameter, legacy callers unchanged.
- **Simple products with schema.org reserved attributes are promoted to product-group shape.** Closes #356.
  - When a simple WC product carries any of the four reserved variant attributes — Color, Size, Pattern, or Material (case-insensitive) — the UCP translator now emits it in product-group shape: `options[]` is populated and a synthesized default variant is included, instead of treating it as a flat product with no variant axes.
  - Non-reserved informational attributes (e.g. Fabric Weight, Origin) stay in `metadata.attributes`.
  - Restricted to the four schema.org reserved names only; all other attributes are unaffected.

### Fixes

- **UCP wire-format compliance with `release/2026-04-08`.** Closes #349.
  - Audit on prod_22 (simple) + prod_35 (variable, T-Shirt with Logo) found 6 critical + 5 important spec gaps. We were declaring `PROTOCOL_VERSION = "2026-04-08"` in our manifest while emitting non-conformant payloads — strict-validating agents were already rejecting our responses. This release brings the implementation in line with the spec.
  - **Variant `price` rename.** Spec `variant.json` requires `price` (current selling price); we were emitting under `list_price`. Renamed the output key in both `translate()` and `synthesize_default()`.
  - **`compare_at_price` → `list_price` (strikethrough).** Spec uses `list_price` for the optional pre-discount strikethrough; `compare_at_price` is non-spec. Helper renamed `extract_compare_at_price()` → `extract_list_price()`.
  - **`selected_option` shape `{name, label}`.** Spec `selected_option.json` requires `[name, label]`; we were emitting `[attribute, value]`. Renamed at both array and parsed-string emission paths.
  - **`option_value` shape `[{label}]`.** Spec `product_option.json` references `option_value.json` for each value; bare strings rejected. We now emit `[{label: "Black"}, ...]`.
  - **`product.rating` shape `{value, scale_min, scale_max, count}`.** Spec `rating.json` requires this shape; we were emitting `{average, count}`. Hardcoded `scale_min: 1` / `scale_max: 5` (WC core star scale is inflexible).
  - **Seller moves from product to variant.** Spec defines `seller` inline on `variant.json` only; no `product.seller` field exists. Threaded through `extract_variants()` so every emitted variant carries the same seller. `country` field dropped from `build_seller()` (not in spec).
  - **Lookup per-variant `inputs[]` correlation.** Per `catalog_lookup.json#/$defs/lookup_variant`, every variant in a lookup response must carry `inputs: [{id, match}]` with `minItems: 1`. Top-level envelope `inputs` echo was non-spec and is dropped. Each variant now declares `[{id: <input>, match: 'featured'}]`.
  - **Message shape compliance.** `message_error.json` requires `content` (we omitted on `not_found`); `message_warning.json` and `message_info.json` have no `severity` field (we emitted `severity: 'advisory'` as a non-spec extension on 14 sites). Added required content; dropped severity from all warning/info emissions.
  - **Release context.** Pre-1.0 minor bump for substantive wire-format scope. Spec-conforming agents reading our responses today are already getting validation rejections; this release fixes that. No coordination needed with public agent integrations because none read our specific non-conformant keys.
- **Decode HTML entities in UCP name fields.** Closes #356.
  - The WC Store API returns `name` values with HTML entities intact (e.g. `Shirt &#8211; Green`). Both translators now decode at every Store API name read site — product title, variant title, category/tag/brand names, attribute axis labels, and option term labels — so UCP JSON always emits plain Unicode.
  - Decode order: `html_entity_decode()` first, then `wp_strip_all_tags()`. Reversing the order would miss encoded markup (`&lt;b&gt;`) that only becomes strippable after decoding.
  - Category names decoded at both the leaf (`build_category_paths_map()`) and ancestor (`fetch_category_terms()`) fetch sites so the full `>`-delimited hierarchy string is clean Unicode end-to-end.

### Tests

- Added `UcpShapeTest` running JSON Schema validation against the canonical UCP `release/2026-04-08` schemas vendored at `tests/fixtures/ucp-schemas/`. 12 new tests cover all touched shapes including the `lookup_variant` allOf merge.
- Added `opis/json-schema` v2.6 as a dev dependency — only PHP library with complete draft-2020-12 / `$defs` / cross-file `$ref` support.
- Updated existing translator + REST controller tests for the new key names and message shapes (~15 sites). Hard-cut regression guards (`assertArrayNotHasKey`) for the old keys.
- Added 4 enrichment tests for `option_value.id` (#354): present for taxonomy attributes, omitted for custom attributes, omitted when term slug missing, end-to-end flow through the variant translator's parsed-string path with `term_slug_map`.
- Added 4 enrichment tests for hierarchical categories (#354): path string emission, graceful degradation when path missing, backwards-compat with no path map, brands stay flat regardless of map.
- Added entity-decode tests to `UcpProductTranslatorTest` and `UcpVariantTranslatorTest` (#356): encoded axis labels, encoded term values, encoded category names, and HTML-tag-after-decode stripping.

---

## [0.11.1] – 2026-05-08

### Fixes

- **UCP: per-variant `title` and `options` now emit distinct values for variable-product variations.** Closes #347.
  - Pre-fix: WC's Store API leaves `attributes[]` empty for every variable-product variation as of WC 9.x and puts the active option set in a `variation` formatted string (e.g. `"Color: Tan, Size: 9"`). The translator was only consulting `attributes[]`, so every variation in a set emitted empty `options` and fell back to the parent product's `name` for its title. A 22-variation product like Leather Shoes shipped 22 indistinguishable variants — agents asking for "Tan / 9" got back the wrong variation.
  - Fix: parse `variation` into structured `[{attribute, value}]` pairs whenever it is non-empty. Existing array-shape callers (simple-product fixtures, future Store API versions that backfill the array) still take precedence when they produce any usable pair.
  - Optional `$parent_attribute_names` parameter on the variant translator's `translate()` provides comma-in-value disambiguation (e.g. a value literally `"Red, White"`). Anchored regex split treats `, ` as a pair boundary only when followed by `<known_name>: `; without the anchor list, falls back to a naive `, ` split — correct for the overwhelming majority of attribute values.
  - Product translator extracts the parent's variation-axis attribute names once (filtered on `has_variations: true`) and threads them down so the variant translator stays a pure function with no WP API calls.
  - Bug shipped because the unit-test fixture matched a documented `attributes[]` shape that the live WC Store API never actually returns for variations. Fixture rewritten to match real Store API output.

### Tests

- Added `test_translate_parses_variation_string_into_options`, `test_translate_builds_title_from_variation_string`, `test_translate_parses_variation_string_without_anchor_list`, `test_translate_anchor_list_disambiguates_comma_in_value`, `test_translate_anchor_list_with_regex_metacharacters`, `test_translate_falls_back_to_name_when_variation_string_empty`, `test_translate_attributes_array_takes_precedence_over_variation_string`, `test_translate_falls_back_to_variation_when_attributes_all_filtered_out`, `test_translate_preserves_zero_string_value_in_title_and_options`, `test_translate_drops_false_and_null_values_from_title_and_options` to the variant translator.
- Added `test_parent_attribute_names_flow_to_variant_translator` integration test on the product translator end-to-end.

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
