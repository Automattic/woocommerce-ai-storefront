# UI Conventions

Rules for writing the React admin UI. These are conventions, not architecture — read [`ARCHITECTURE.md`](ARCHITECTURE.md) for what the plugin does, this doc for how the UI code should look.

## Component-library precedence

`@wordpress/components` is the default. For data tables, **`@wordpress/dataviews` is preferred over `@woocommerce/components`'s `TableCard`** — see the adoption note below for the full reasoning.

The runtime model matters:

| Package | Runtime model | Implication |
|---------|---------------|-------------|
| `@wordpress/components` | Externalized → `window.wp.components` | Merchant's WP provides it; CSS shipped under the `wp-components` handle we already enqueue. |
| `@woocommerce/components` | Externalized → `window.wc.components` | Merchant's wc-admin provides it; **CSS auto-enqueues only on native wc-admin screens** — not on custom plugin submenu pages. This is the trap. |
| `@wordpress/dataviews` | **Bundled** into our plugin's JS | Listed in the WP extractor's `BUNDLED_PACKAGES`. JS ships with our build; CSS imported via `client/settings/ai-storefront/index.js` so it travels with our own stylesheet. No dependency on the merchant's wc-admin asset registration. |

Adopted bundled components today:

| From | Component | Where |
|------|-----------|-------|
| `@wordpress/dataviews` | `DataViews` | `client/settings/ai-storefront/ai-orders-table.js` (Recent AI Orders) |

## Why DataViews and not `@woocommerce/components`'s TableCard

We tried TableCard first (PR #24) and reverted. The trap: `wc-components` CSS isn't auto-enqueued on custom plugin submenu pages, so the component DOM rendered but the summary-row layout collapsed ("1Total orders" with no spacing), column widths went wrong, the whole thing looked broken.

Three workarounds — none clean:

1. **Enqueue `wc-components` via `wp_style_is('registered')`** — guards pass through silently because the handle isn't registered on non-wc-admin pages.
2. **Bump `admin_enqueue_scripts` to a later priority** — makes no difference; handle registration is tied to screen detection, not hook ordering.
3. **Direct CSS import from `@woocommerce/components/build-style/...`** — works but breaks our tree-shaking story and pins us to a specific WC version.

DataViews is bundled, so the package ships with our build and CSS extracts to our own stylesheet via the import in `client/settings/ai-storefront/index.js`. Same enqueue path as every other style we ship. No merchant-environment dependency.

## Adoption recipe for new DataViews tables

```js
// 1. Import DataViews + the filter/sort/paginate helper.
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

// 2. Declare fields with id/label/render/getValue. `getValue` is what
//    sort/filter operate on; `render` is what the cell displays.
const fields = [
    {
        id: 'order',
        label: __( 'Order', 'woocommerce-ai-storefront' ),
        enableSorting: true,
        render: ( { item } ) => <a href={ item.edit_url }>#{ item.number }</a>,
        getValue: ( { item } ) => item.id,
    },
    // ...
];

// 3. Keep view state local; DataViews calls onChangeView when the
//    user paginates, sorts, or toggles column visibility.
const [ view, setView ] = useState( {
    type: 'table', page: 1, perPage: 10,
    fields: [ 'order', 'date', 'status', 'agent', 'total' ],
} );

// 4. Process data through the helper before passing to DataViews —
//    the component expects the current-page slice, not the raw list.
const { data: processedData, paginationInfo } = useMemo(
    () => filterSortAndPaginate( rawData, view, fields ),
    [ rawData, view, fields ]
);
```

See `ai-orders-table.js` for the full template, including status-pill styling (inline because wc-admin's `.order-status` CSS isn't loaded on our page — we render the pill ourselves with WC's palette values).

**CSS import location:** the DataViews stylesheet import in `client/settings/ai-storefront/index.js` is the one place we bring in bundled Woo/WP design-system CSS. New bundled WP packages with their own CSS go next to it — not scattered across component files — so the stylesheet bundle stays auditable.

**The `@woocommerce/components` devDependency has been removed.** Kept previously for IntelliSense on Woo prop shapes; at zero runtime usage and 40+ transitive deps, the cost outweighed the benefit. Webpack extractor wiring (`@woocommerce/dependency-extraction-webpack-plugin`) is still configured so a future re-adoption only needs `npm install --save-dev @woocommerce/components` plus an import.

## Candidate DataViews migrations

- **Discovery tab endpoint-reachability list** — natural fit for DataViews' `table` view with status-pill rendering.
- **Order attribution table** (if we add a dedicated page) — larger data set, where pagination and sort earn their weight.

Stat cards and selection-count pills should stay hand-rolled — no tabular data, and `@wordpress/components` primitives are sufficient.

## Deferred: order preview modal

WC's Orders list has an eye-icon that opens a Backbone modal with a compact order summary. Reusing it in the AI Orders DataViews table was evaluated and intentionally deferred.

The WC preview modal is **not cleanly reusable**. It's jQuery + Backbone code baked into wc-admin's `wc-orders.js` bundle, tightly coupled to the Orders list DOM. Its CSS auto-enqueues only on the Orders list screen (same class of problem that killed PR #24's Woo TableCard adoption). The AJAX endpoint (`admin-ajax.php?action=woocommerce_get_order_details`) returns pre-rendered HTML, not structured data.

Three options if merchant demand surfaces:

1. **Reuse WC's Backbone modal as-is.** Enqueue `wc-orders` JS + CSS, render rows with `class="order-preview" data-order-id="..."`. ~4–6 hours of dependency-debugging. HIGH coupling risk — wc-admin internals change across WC minors.
2. **Call the AJAX endpoint, render its HTML response inside our own `@wordpress/components/Modal`.** ~2–3 hours. Mixes React and jQuery idioms; couples to WC's HTML response shape; requires sanitization of the server response (treat as untrusted).
3. **Build a custom preview from scratch.** New REST endpoint returning structured order data, React modal rendered from tokens. ~1 full day. Zero coupling, full control, but disproportionate effort for the observed value.

Today: order numbers in the AI Orders table link directly to the WC order edit screen (same tab). The smallest quick-fix if "I want a peek without losing context" surfaces in testing is `target="_blank"` on the order-number link.

## Inline styles + design tokens

The admin UI uses React components with inline `style={ ... }` props (no stylesheet). **Colors MUST come from `client/settings/ai-storefront/tokens.js`.** Raw hex literals in JSX are a lint-review red flag.

```js
import { colors } from './tokens';

// Standalone — reference the token directly.
<p style={ { color: colors.textSecondary } }>…</p>

// Embedded in a multi-value string — use a template literal.
<div style={ { border: `1px solid ${ colors.borderSubtle }` } } />
```

**Adding a new color:** define a semantic token in `tokens.js` first (e.g. `warningBg`, not `yellow100`). Map it to the nearest value in the WordPress admin palette (`@wordpress/base-styles/_colors.scss`). Choosing an existing palette value is almost always preferable to inventing a new one.

**Why:** a future migration to CSS custom properties (e.g. `var( --wp-components-color-gray-700, #50575e )`) — or a palette shift in WP core — becomes a single-file change instead of hunt-and-replace across every component.

## Layout primitives

Use `Flex` / `FlexItem` / `FlexBlock` from `@wordpress/components` instead of hand-rolling `style={{ display: 'flex', gap: ... }}`. The primitives inherit WP's spacing scale and responsive behavior. Hand-rolled flex is acceptable only for single-child containers where the "layout" is really just alignment or max-width.

## See also

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — what each component does
- [`../README.md`](../README.md) — documentation index
- [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md) — branch naming, code review, PR conventions
