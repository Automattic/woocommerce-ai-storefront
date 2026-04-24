# Changelog

## [0.1.2] – 2026-04-24

### Features
- **Products tab: decouple browse-state from scoping commit** — Clicking a taxonomy tab (Categories / Tags / Brands) inside the By-taxonomy row is now browse-only. The persisted scoping mode is no longer flipped on tab click, which removed a spurious "No tags selected, products hidden" warning that fired for merchants with a valid brand-scoped catalog who clicked the Tags tab to look around. The warning now tracks the *enforcing* mode, not the viewed tab. ([#71](https://github.com/Automattic/woocommerce-ai-storefront/pull/71))

### Fixes
- **llms.txt: hide category list when scoping by brand / tag / selected products** — Previously, the Product Categories section emitted the top-20 store categories in every mode, so a merchant scoping to a single brand saw their llms.txt advertise categories unrelated to the scoped catalog. Now only emitted for `all` and `categories` modes. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))
- **llms.txt: use prefixed UCP IDs in the Attribution request-body example** — Changed `"id": "123"` to `"id": "prod_123"` to match the wire format UCP catalog/search/lookup responses actually emit; agents copy-pasting the example would otherwise POST a raw numeric ID and be rejected by `parse_ucp_id()`. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))

### UI polish
- **Remove sample-product grid from the "All products" row** — The 6-tile preview rendered as half-empty placeholder boxes on fresh-install / staging stores, reading as "looks half-broken" rather than "here's your catalog." Count pill + deep link carry the information value. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))
- **By-taxonomy row: multi-count badge + conditional copy** — Badge now surfaces every non-zero taxonomy count (e.g. "3 categories · 1 brand") so a merchant flipping tabs doesn't lose sight of their full scope. Separator exposed as a translatable string for RTL locales. Badge is suppressed when the By-taxonomy row isn't selected and no counts apply. ([#71](https://github.com/Automattic/woocommerce-ai-storefront/pull/71))
- **llms.txt declutter** — Dropped stale post-v2.0.0 archaeology comments, URL-template patterns that contradicted the POST-first checkout posture, and the merchant-facing agent-mapping table. Collapsed programmatic-verification bullets into a single pointer. ([#70](https://github.com/Automattic/woocommerce-ai-storefront/pull/70))

---

## [0.1.1] – 2026-04-24

### Features
- **Products tab: tags, brands & taxonomy grouping** — Merchants can now restrict AI catalog syndication to specific product tags or WooCommerce Brands (requires WC ≥ 9.5). Taxonomy groups are displayed in a grouped selector. Per-product gate added to `llms.txt`, JSON-LD, and Store API output. ([#65](https://github.com/Automattic/woocommerce-ai-storefront/pull/65))

### Fixes
- **Overview: empty state for AI orders table** — Resolved a blank-panel regression when no AI-influenced orders exist yet. ([#67](https://github.com/Automattic/woocommerce-ai-storefront/pull/67))

### CI / Infra
- Add `.pot` freshness check — CI now fails if translatable strings are changed without regenerating the translation template. ([#66](https://github.com/Automattic/woocommerce-ai-storefront/pull/66))
- Expand PHP test matrix to 8.1 – 8.4 and align `composer.json` platform floor to tested reality. ([#68](https://github.com/Automattic/woocommerce-ai-storefront/pull/68))
- Add build-freshness and supply-chain audit jobs to CI. ([#69](https://github.com/Automattic/woocommerce-ai-storefront/pull/69))

---

## [0.1.0] – initial release
