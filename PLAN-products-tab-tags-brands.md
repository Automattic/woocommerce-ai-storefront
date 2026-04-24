# PLAN: Products tab — Tags + Brands (PR-1 after v0.1.0)

**Status**: Locked scope, ready to implement.
**Target version**: 0.1.x (no release cut; work accumulates on main)
**Scope**: Extend the Products tab's scope-selection UI to support `tags` and `brands` modes alongside existing `all` / `categories` / `selected` modes. Apply the designer's round-4 through round-6 critique tweaks. Re-order the sample grid by recency.

This plan replaces the working doc from the design conversation with a durable design-decision record. Everything below is confirmed scope or deferred — no open design questions remain for PR-1.

---

## Context: what shipped in PR #60

The Products tab currently renders the radio-cards pattern that replaced the pre-rewrite `<SelectControl>` dropdown. Three modes:

- `all` — detail panel shows a 6-product sample grid fetched from `/wc/store/v1/products?per_page=6` (alphabetical-by-title, default Store API ordering)
- `categories` — SelectedTokens + SearchControl + CheckboxControl list of `product_cat` terms
- `selected` — SelectedTokens + SearchControl + CheckboxControl list of individual products

Enforcement points for the `categories` mode (each gets a parallel `tags` + `brands` branch):

1. `includes/class-wc-ai-storefront.php` — `get_products()` helper + settings schema + sanitizer
2. `includes/ai-storefront/ucp-rest/class-wc-ai-storefront-ucp-store-api-filter.php` — Store API `woocommerce_store_api_product_collection_query_args` filter
3. `includes/ai-storefront/class-wc-ai-storefront-llms-txt.php` — llms.txt generator

Admin REST routes under `wc/v3/ai-storefront/admin/search/`:
- `/categories` — exists
- `/products` — exists
- `/brands` — **to be added**
- `/tags` — **to be added**

---

## Confirmed scope (build this)

### 1. Taxonomy grouping (designer round 4)

Collapse `categories` + new `tags` + new `brands` into a single top-level radio row titled **"By taxonomy"**. Inside its detail panel, use `ToggleGroupControl` from `@wordpress/components` with three segments:

```
[ Categories | Tags | Brands ]
```

- Three top-level radio rows remain (not five): `All published products` / `By taxonomy` / `Specific products only`
- Server-side enum still branches per taxonomy (`product_selection_mode`: `'all' | 'categories' | 'tags' | 'brands' | 'selected'`)
- Client toggle state translates to the correct mode on save; each segment persists its own `selected_*` array

### 2. Brands graceful degradation

`product_brand` is a native WooCommerce taxonomy introduced in WC 9.5+. On earlier versions the segment is **hidden** (not disabled — don't offer it if it won't work).

```php
if ( taxonomy_exists( 'product_brand' ) ) { /* emit brands segment */ }
```

Client receives a `supportsBrands` flag from the admin bootstrap and hides the Brands segment + skips the `/search/brands` fetch if false.

### 3. ANY-match semantics

A product matches when it has **any** of the selected tags/categories/brands (not all). Matches the existing `categories` mode behavior; consistent with how `filters.brand[]` already works in the UCP catalog.search adapter. Documented in the disclosure line with bolded "any":

> Products are included when they have **any** of the selected tags. Auto-includes future products that match.

### 4. Summary headings removed (all three panels)

Drop the `"Currently sharing X · ANY-match · Y products"` heading from every detail panel. The badge (right of row) + chip list (inside panel) + disclosure line (bottom of panel) already cover count, specifics, and semantics respectively. Redundant reinforcement noise.

**Net effect:** panels open straight to the actionable UI (ToggleGroupControl for taxonomy panel; sample grid for all-mode; checkbox list for specific-mode). ~50px vertical reclaimed per panel.

### 5. Dashed borders removed (designer round 4, tweak #5)

No dashed borders anywhere in the Products tab. WP admin uses dashed patterns to signal "placeholder / not-yet-built" — using them for dividers confuses the signal. Switch to `--wp-divider` solid dividers.

### 6. Selected-state 3px WP-blue left border (designer round 4, tweak #3)

Selected radio row + its detail panel get a 3px solid `var(--wp-blue)` left border. Matches the WP-native Notice focus-ring pattern. More scannable than the existing background-tint-only treatment at real admin dimensions.

### 7. Tag chips: `#` prefix + pill shape (designer round 4, tweak #1 carry-over)

Tags are visually distinguished from categories (rectangular tokens) and brands (rectangular tokens) by:
- Pill-shaped token (fully-rounded border-radius)
- Leading `#` glyph before the tag name
- Otherwise identical behavior (click × to remove)

Categories and brands use the existing rectangular `SelectedTokens` component without modification.

### 8. Per-taxonomy search placeholders (designer round 4)

Search placeholder anchors which list the merchant is in:

- Categories: `"Filter categories…"`
- Tags: `"Filter tags (e.g. summer, sale)…"`
- Brands: `"Filter brands (e.g. Adidas, Nike)…"`

### 9. Sample grid: newest-ordered + renamed label

Switch the `SamplePreview` fetch from default alphabetical to:

```js
apiFetch( {
    path: '/wc/store/v1/products?per_page=6&orderby=date&order=desc',
    parse: false,
} )
```

Rename the label from `"Sample of what's shared"` to `"Recently added (6 of N,NNN)"` where N,NNN is `totalPublished.toLocaleString()`. Honest about selection criterion + provides count context. Falls back to `"Recently added"` when the total is in the `'error'` sentinel state (no count appended).

### 10. Sample grid stays "all"-only (designer round 5)

**Do not** add a sample grid to the taxonomy panels. Designer's round-5 call: taxonomy mode is edit mode, the grid would push Save below the fold, and the merchant's real doubt ("did I get the filter right?") is answered by the match count — not by 6 cherry-picked thumbnails that can't represent 71 items meaningfully. If we ever revisit, gate behind an opt-in toggle; don't make it permanent.

### 11. ANY-match disclosure copy

The bottom-of-panel disclosure surfaces the ANY-match semantics with bold emphasis (since it's the one thing a merchant can get wrong):

- **Tags panel (Tags sub-mode active)**:  
  *"Products are included when they have **any** of the selected tags. Auto-includes future products that match."*
- **Categories panel (Categories sub-mode active)**:  
  *"Auto-includes future products added to these categories."* (existing copy; ANY-match is implicit for category-tree matching)
- **Brands panel (Brands sub-mode active)**:  
  *"Products are included when they belong to **any** of the selected brands. Auto-includes future products that match."*
- **Specific products panel** (unchanged):  
  *"New products are not auto-included. Return here to add them manually as your catalog grows."* (Notice status=warning)

---

## Explicitly skipped (NOT in this PR)

- **Tweak #2** (included-fields footer: monospace tokens + "What's not shared" counter-line) — user skipped this round. Keep existing pill chips + "Included fields" label.
- **Overview orders-table empty state** — separate PR-2.
- **Crawler stat cards** (Agent visits + Products seen) — deferred; need agent-hit instrumentation first.
- **Agent-hit instrumentation** (DB writer + aggregation) — separate future PR when we decide to populate the stat cards with real data.

---

## Settings schema changes

### `includes/class-wc-ai-storefront.php`

**Defaults** (`get_default_settings()` or equivalent):
```php
'product_selection_mode' => 'all',
'selected_categories'    => [],
'selected_tags'          => [],  // NEW
'selected_brands'        => [],  // NEW
'selected_products'      => [],
```

**Sanitizer** (the `in_array` enum check):
```php
'product_selection_mode' => in_array(
    $merged['product_selection_mode'],
    [ 'all', 'categories', 'tags', 'brands', 'selected' ],  // +tags, +brands
    true
) ? $merged['product_selection_mode'] : 'all',
'selected_tags' => array_map(
    'absint',
    (array) ( $merged['selected_tags'] ?? [] )
),
'selected_brands' => array_map(
    'absint',
    (array) ( $merged['selected_brands'] ?? [] )
),
```

### `get_products()` helper

Add branches mirroring the existing `categories` branch:

```php
if ( 'categories' === $mode && ! empty( $settings['selected_categories'] ) ) {
    $args['category'] = $settings['selected_categories'];
}
if ( 'tags' === $mode && ! empty( $settings['selected_tags'] ) ) {
    $args['tag'] = $settings['selected_tags'];
}
if ( 'brands' === $mode && ! empty( $settings['selected_brands'] ) ) {
    $args['tax_query'] = [ [
        'taxonomy' => 'product_brand',
        'field'    => 'term_id',
        'terms'    => array_map( 'absint', $settings['selected_brands'] ),
    ] ];
}
```

Note: `wc_get_products` doesn't accept `brand` as a direct param (unlike `category` and `tag`), so we use `tax_query`.

### Store API filter

`class-wc-ai-storefront-ucp-store-api-filter.php` currently has:

```php
if ( 'categories' === $mode && ! empty( $settings['selected_categories'] ) ) {
    $args['tax_query'][] = [
        'taxonomy' => 'product_cat',
        'field'    => 'term_id',
        'terms'    => array_map( 'absint', $settings['selected_categories'] ),
    ];
}
```

Add parallel branches:

```php
if ( 'tags' === $mode && ! empty( $settings['selected_tags'] ) ) {
    $args['tax_query'][] = [
        'taxonomy' => 'product_tag',
        'field'    => 'term_id',
        'terms'    => array_map( 'absint', $settings['selected_tags'] ),
    ];
}
if ( 'brands' === $mode && ! empty( $settings['selected_brands'] ) ) {
    $args['tax_query'][] = [
        'taxonomy' => 'product_brand',
        'field'    => 'term_id',
        'terms'    => array_map( 'absint', $settings['selected_brands'] ),
    ];
}
```

### llms.txt generator

`class-wc-ai-storefront-llms-txt.php` currently has:

```php
if ( 'categories' === $product_mode && ! empty( $settings['selected_categories'] ) ) {
    $args['include'] = array_map( 'absint', $settings['selected_categories'] );
}
```

Wait — this is using `include` for categories which is suspicious (should be `category`/tax-query). Read the surrounding code to confirm the pattern; if it's using `wc_get_products()` args, align `tags` + `brands` with that convention. Don't blindly-copy until you've verified the context.

---

## Admin REST routes

### `includes/admin/class-wc-ai-storefront-admin-controller.php`

Add two new routes mirroring `/search/categories`:

```php
register_rest_route(
    'wc/v3/ai-storefront/admin',
    '/search/tags',
    [
        'methods'             => 'GET',
        'callback'            => [ $this, 'search_tags' ],
        'permission_callback' => [ $this, 'check_admin_permission' ],
    ]
);

register_rest_route(
    'wc/v3/ai-storefront/admin',
    '/search/brands',
    [
        'methods'             => 'GET',
        'callback'            => [ $this, 'search_brands' ],
        'permission_callback' => [ $this, 'check_admin_permission' ],
    ]
);
```

Callbacks use `get_terms()` against `product_tag` and `product_brand` respectively. Pattern to follow: existing `search_categories()` method. Return shape: `[{ id, name, count }]`.

**Brands callback has an additional guard**: returns `[]` if `! taxonomy_exists( 'product_brand' )` — same graceful degradation as the client.

---

## Client changes (`product-selection.js`)

### New state
- `tags` array (parallel to `categories`)
- `brands` array (parallel to `categories`)
- `tagSearch`, `brandSearch` (parallel to `categorySearch`)
- `isLoadingTags`, `isLoadingBrands`
- `selectedTagTokens`, `selectedBrandTokens`
- `filteredTags`, `filteredBrands`
- `supportsBrands` boolean (from bootstrap)
- `activeTaxonomy` local state: `'categories' | 'tags' | 'brands'` — drives the ToggleGroupControl + which detail renders

### Mode mapping on save
When the user clicks Save while in By-taxonomy mode:
- If `activeTaxonomy === 'categories'` → `product_selection_mode: 'categories'`
- If `activeTaxonomy === 'tags'` → `product_selection_mode: 'tags'`
- If `activeTaxonomy === 'brands'` → `product_selection_mode: 'brands'`

On initial load from persisted settings, derive `activeTaxonomy` from the persisted `product_selection_mode` (if it's one of the three taxonomy modes; else default to `categories`).

### Toggle group component
Use `ToggleGroupControl` + `ToggleGroupControlOption` from `@wordpress/components` (stable, non-experimental). Three options; Brands option conditionally rendered.

### Tag token visual
Create a new small `TagToken` component (or pass a `variant='tag'` prop to `SelectedTokens`) that renders with `border-radius: 12px` + `#` prefix. Categories and brands use existing `SelectedTokens` without modification.

---

## Test plan

### PHPUnit

1. **Settings sanitizer**: `product_selection_mode` accepts `'tags'` + `'brands'`; rejects unknown values.
2. **`selected_tags` + `selected_brands`** sanitize to `array<int>` via `array_map('absint')`.
3. **Store API filter — tags mode**: asserts `tax_query` receives `product_tag` + `IN` operator + term-id array.
4. **Store API filter — brands mode**: same pattern for `product_brand`.
5. **Store API filter — brands mode with empty selection**: no `tax_query` added (don't filter down to nothing).
6. **llms.txt generator — tags mode**: verifies term IDs are forwarded to `wc_get_products()` correctly.
7. **llms.txt generator — brands mode**: same for brands.
8. **Brands graceful degradation — taxonomy missing**: `get_products()` in brands mode returns the full catalog (or throws a warning; match existing behavior) when `product_brand` taxonomy doesn't exist.
9. **Admin REST — `/search/tags`**: returns shape-compatible data.
10. **Admin REST — `/search/brands`**: returns empty array when `product_brand` taxonomy doesn't exist.

### JS (if there's a test runner for React components — verify via `npm run test:js`)

If Jest + RTL is configured:
1. Renders three radio rows (not five)
2. Selecting "By taxonomy" + clicking Tags segment → tag chip list rendered
3. Toggle persistence: selecting Tags, typing in search, selecting back to Categories → category chip list renders (no cross-contamination)
4. Brands segment hidden when `supportsBrands` is false

If no React tests — PR can skip and rely on PHPUnit + manual smoke. Consistent with prior practice.

---

## Out-of-scope follow-ups (for after PR-1 merges)

- **PR-2**: Overview orders-table medium empty state (ghost row + explanatory copy)
- **PR-3 (deferred)**: Crawler stat cards as "Data collecting" placeholders on Overview
- **PR-4 (far-future)**: Agent-hit instrumentation — DB writer + aggregation + retention policy + wire the real numbers into the placeholder cards from PR-3

---

## Expected size

- PHP: ~120 LoC (schema + sanitizer + filter branches + 2 REST callbacks + tests)
- JS: ~300 LoC (ToggleGroupControl integration + 2 new taxonomy fetches + toggle state mapping + UI tweaks)
- PHPUnit: ~150 LoC (10 new tests)
- `.pot` regeneration: automatic
- Build rebuild: automatic

Total ~600 LoC changed, of which ~150 are tests.

---

## Why this PR is a single unit

All of items 1–11 are tightly coupled:
- Adding tags without brands leaves the implementation asymmetric (wasted cycle if brands lands "soon").
- Adding taxonomy grouping without designer tweaks (#4–#7) ships a worse UI than before.
- Adding the taxonomy grouping requires the summary-heading removal (#4) to not crowd the panels.
- The sample-grid change (#9) is orthogonal but one-line, cheap to bundle.

Splitting this into sub-PRs buys nothing and costs review thrash. One cohesive Products-tab rework.
