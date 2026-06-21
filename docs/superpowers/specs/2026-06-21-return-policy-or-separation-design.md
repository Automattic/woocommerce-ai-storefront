# Return Policy — Explicit Option A / Option B Separation

**Issue:** #520
**Status:** Design (approved in conversation 2026-06-21)
**Type:** MINOR (settings-shape change) — 0.25.0 candidate; may bundle with multi-currency Phase 2 (#517).

## Goal

Replace the Policies-tab return-policy section's lumped *mode + page dropdown + inline fields* layout with a single explicit choice that mirrors Google's return-policy doc ("choose Option A **or** Option B"), eliminating two defects:

1. **Silent precedence** — today a merchant can fill in `days`/`fees`/`methods` *and* select a returns page; `build_return_policy_block()` gives the page link precedence and silently drops the inline detail from the JSON-LD.
2. **Preview/emission divergence** — the JS preview helper (`derivePolicyBlock`) renders the inline block while the PHP emitter outputs link-only when a page is set, so the admin preview lies.

Google reference: <https://developers.google.com/search/docs/appearance/structured-data/return-policy#merchant-return-policy-properties> — Option A = inline (`applicableCountry` + `returnPolicyCategory` [+ `merchantReturnDays`/`returnMethod`/`returnFees` for a finite window]); Option B = `merchantReturnLink` (a URL, sufficient on its own).

## Non-goals

- Per-product return-policy overrides (out of scope; existing override scope, if any, is unchanged).
- **No data migration.** The plugin is pre-release and runs only on the user's test stores, so the settings shape changes in place with no back-compat shim. The merchant re-picks once.

## Data model

`return_policy` option becomes a single-source-of-truth, source-first shape:

```
return_policy: {
  mode:     'unconfigured' | 'link' | 'details',   // the A/B/none choice
  page_id:  int,                                    // used iff mode === 'link'
  category: 'returns_accepted' | 'final_sale',      // used iff mode === 'details'
  days:     int,                                     // used iff mode==='details' && category==='returns_accepted'
  fees:     <existing FEE enum>,                      //   "
  methods:  string[],                                //   "  (existing RETURN_METHOD options)
}
```

The previous enum (`unconfigured` / `returns_accepted` / `final_sale` as the top-level `mode`) is replaced: the accepted-vs-final-sale distinction moves **under** `details` as `category`; `link` becomes a first-class top-level option instead of "any mode with a `page_id`". The `days`/`fees`/`methods` field definitions and their option sets are unchanged.

## Emission mapping (`offers.hasMerchantReturnPolicy`)

| `mode` | `category` | Emitted block |
|---|---|---|
| `unconfigured` | — | *(omitted)* |
| `link` | — | `{ @type: MerchantReturnPolicy, merchantReturnLink: <resolved page permalink> }` (Option B) |
| `details` | `returns_accepted`, `days > 0` | `{ @type, applicableCountry, returnPolicyCategory: MerchantReturnFiniteReturnWindow, merchantReturnDays, returnMethod, returnFees, … }` (Option A) |
| `details` | `returns_accepted`, `days = 0` | preserve today's downgrade to `MerchantReturnUnspecified` (per the `policies-tab.js` header note) |
| `details` | `final_sale` | `{ @type, applicableCountry, returnPolicyCategory: MerchantReturnNotPermitted }` (Option A, no-returns) |

`mode: 'link'` requires no `returnPolicyCategory`/`applicableCountry` — the linked page describes the policy (Google Option B). The previous "final sale + optional link" combo is gone: to point at a page, pick `link` (the page may itself describe a no-returns policy).

There are **three** readers of the shape that must all move together so preview === emission:

1. **JS preview** — `derivePolicyBlock()` in `client/settings/ai-storefront/policies-tab.js`.
2. **PHP emitter** — `WC_AI_Storefront_JsonLd::build_return_policy_block()` in `includes/ai-storefront/class-wc-ai-storefront-jsonld.php`. The link-precedence branch is **deleted** (a `link` mode is now an explicit choice, never a silent override of inline detail). This emitter feeds both the product page (`add_return_policy`) and the root-URL ItemList stubs (added in #519).
3. **PHP sanitizer** — `WC_AI_Storefront_Return_Policy::sanitize()` (delegated from `WC_AI_Storefront::sanitize_return_policy()`).

## UI behavior (`policies-tab.js`)

A single control — **"How should returns be described?"** — with three options:

- **Not configured** → no further fields; emits nothing.
- **Link to a returns page** → reveals **only** the page dropdown.
- **Specify the details here** → reveals a **category** sub-choice (Returns accepted / Final sale); *Returns accepted* additionally reveals `days` / `fees` / `methods`; *Final sale* reveals nothing further.

Switching the top-level choice hides the other branch's fields. The live JSON-LD preview reads the same single state, so it always matches what the emitter will produce.

## Sanitization (`WC_AI_Storefront_Return_Policy::sanitize()`)

Persist only the fields meaningful for the resolved mode/category (drop the rest, so no stale `page_id` survives a switch to `details`, etc.):

- `unconfigured` → `{ mode }`
- `link` → `{ mode, page_id }`
- `details` + `final_sale` → `{ mode, category }`
- `details` + `returns_accepted` → `{ mode, category, days, fees, methods }`

Unknown/invalid `mode` or `category` → fail closed to `{ mode: 'unconfigured' }`.

## Files

- `client/settings/ai-storefront/policies-tab.js` — `POLICY_MODES`, `DEFAULT_*` shape, the segmented/radio control + conditional reveal, `derivePolicyBlock()`.
- `client/settings/ai-storefront/__tests__/policies-tab.test.js` — UI + preview tests.
- `includes/ai-storefront/class-wc-ai-storefront-jsonld.php` — `build_return_policy_block()` (drop link-precedence; read `mode`/`category`).
- `includes/.../class-wc-ai-storefront-return-policy.php` — `sanitize()` (mode/category-aware persistence) + any emission helpers it owns.
- `includes/class-wc-ai-storefront.php` — `return_policy` default array (`includes/class-wc-ai-storefront.php:96`).
- `includes/admin/class-wc-ai-storefront-admin-controller.php` — REST arg schema + default for `return_policy` (`:109`).
- Grep `mode`/`page_id`/`returns_accepted`/`final_sale` across `includes/` + `client/` to catch any other reader.

## Testing

- **JS** (`policies-tab.test.js`): `derivePolicyBlock` for each mode/category; conditional reveal; switching mode clears the other branch's fields from emitted state.
- **PHP sanitize** (`ReturnPolicyTest`): each mode/category persists exactly its fields; invalid input → `unconfigured`.
- **PHP emitter** (JsonLd return-policy tests): `link` → `merchantReturnLink` only (no category); `details`+`final_sale` → `MerchantReturnNotPermitted`; `details`+`returns_accepted` (days>0) → `MerchantReturnFiniteReturnWindow`; days=0 → `MerchantReturnUnspecified`; `unconfigured` → nothing. Confirm the #519 ItemList-stub path emits the same block.

## Out of scope / risks

- The #519 stub mirror calls `build_return_policy_block()` directly, so it inherits the new shape automatically — verify in tests, no extra wiring.
- All copy goes through `__()`; no em-dashes in merchant-facing strings (AGENTS.md); re-run `./bin/make-pot.sh` if any string refs shift.
