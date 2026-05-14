## [Unreleased]

### Features
### Fixes
### Refactors
### Tests
### Docs

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
