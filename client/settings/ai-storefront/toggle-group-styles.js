/**
 * Shared styles for the segmented `ToggleGroupControl` used across the
 * AI Storefront settings tabs.
 *
 * Two consumers today:
 *   - `product-selection.js` (Product Visibility tab — taxonomy/all/selected)
 *   - `policies-tab.js`      (Policies tab — returns_accepted/final_sale/unconfigured)
 *
 * Each consumer renders `<ToggleGroupStyles />` where the toggle is used.
 * Because this component returns a React-managed `<style>` element, the
 * node is mounted and unmounted with that consumer, and multiple mounted
 * consumers will produce multiple identical `<style>` tags in the DOM.
 * The duplication is harmless here — the CSS rules are idempotent, the
 * runtime cost is negligible, and it keeps the styling colocated with
 * the tab that needs it. If a future tab needs the styling without
 * being a sibling consumer (e.g. portaled outside the tab tree), that's
 * the moment to switch to a one-shot `useEffect` that injects a tagged
 * `<style>` into `document.head` with a `data-*` guard for dedup.
 *
 * Why a shared component instead of a global stylesheet:
 *  - `wp-scripts` build pipeline already inlines per-component styles
 *    via `<style>` so there's no extra HTTP request.
 *  - Keeps the visual-treatment authority colocated with the React
 *    component tree rather than a parallel `.scss` file the next
 *    contributor has to learn about.
 *  - Easier to delete: when the toggle treatment is replaced, one
 *    component goes away and no orphan stylesheet lingers.
 *
 * Why this uses the `[aria-checked="true"]` selector and selective
 * `!important` overrides instead of a stable Emotion class:
 *  WP `@wordpress/components` 28.x uses Emotion CSS-in-JS with
 *  dynamically-generated class names — there is no stable
 *  `.components-toggle-group-control-backdrop` etc. to target. The
 *  scoped className (`TOGGLE_GROUP_CLASSNAME`) plus ARIA-state
 *  selectors are the only contract that survives library version
 *  bumps. `!important` is applied selectively — only to declarations
 *  that must reliably win specificity against Emotion's hash-classed
 *  rules (background, border, box-shadow, padding, color). Other
 *  declarations (font-weight, outline, outline-offset, border-radius)
 *  don't need it because Emotion doesn't ship competing values for
 *  those properties on this control.
 *
 * Historical context: pre-0.1.11 a different selector
 * (`.components-toggle-group-control-backdrop`) was used, which never
 * matched anything in 28.x. Tracked down + fixed in 0.1.10/0.1.11.
 *
 * @see CHANGELOG.md [0.1.10] / [0.1.11] for the full debug history.
 *
 * @return {JSX.Element} A `<style>` element with the rules above.
 */
export const TOGGLE_GROUP_CLASSNAME = 'ai-storefront-toggle-group';

export function ToggleGroupStyles() {
	return (
		<style>{ `
			/* Padding override on each option button. WP component's
			   default is 12px horizontal which renders the selected
			   pill cramped against the text edges. Bumped to 18px for
			   visible breathing room — the white pill needs ~6px of
			   "halo" on each side of the label to read as elevated. */
			.${ TOGGLE_GROUP_CLASSNAME } [role="radio"] {
				padding-left: 18px !important;
				padding-right: 18px !important;
			}
			/* Track: recessed neutral surface so the pill reads as
			   floating above it. Targets the wrapping element directly
			   via our scope class — WP components 28.x uses Emotion
			   CSS-in-JS with dynamically-generated class names (no
			   stable .components-toggle-group-control class), so the
			   pre-0.1.10 selectors targeting that name didn't match
			   anything. */
			.${ TOGGLE_GROUP_CLASSNAME } {
				background: rgba( 0, 0, 0, 0.04 ) !important;
				border: 1px solid rgba( 0, 0, 0, 0.08 ) !important;
				box-shadow: inset 0 1px 1px rgba( 0, 0, 0, 0.04 ) !important;
				border-radius: 6px !important;
			}
			/* The "moving pill" is the WP component's :before
			   pseudo-element on the same wrapping div (positioned
			   absolutely, animated via CSS variables). Override the
			   default COLORS.gray[900] black background with white +
			   a soft contact shadow + 0.5px ring, yielding the
			   elevation cue the design needs. */
			.${ TOGGLE_GROUP_CLASSNAME }::before {
				background: #fff !important;
				box-shadow:
					0 1px 2px rgba( 0, 0, 0, 0.12 ),
					0 0 0 0.5px rgba( 0, 0, 0, 0.08 ) !important;
			}
			/* Suppress WP's :focus-within border that uses the admin
			   theme color (vivid pink/magenta on Sunrise, blue on
			   Modern, etc.) so the loud color doesn't compete with
			   the elevated-pill visual signal. Keyboard-only focus
			   indication moves to the selected button below via
			   :focus-visible. */
			.${ TOGGLE_GROUP_CLASSNAME }:focus-within {
				border-color: rgba( 0, 0, 0, 0.08 ) !important;
				box-shadow: inset 0 1px 1px rgba( 0, 0, 0, 0.04 ) !important;
			}
			/* Selected button text. WP defaults to white because the
			   pill was black; the pill is now white, so the text needs
			   to be dark for contrast. Selector keys off
			   [aria-checked="true"] which is stable across WP component
			   versions (ARIA is the contract). */
			.${ TOGGLE_GROUP_CLASSNAME } [aria-checked="true"] {
				color: #1e1e1e !important;
				font-weight: 500;
			}
			/* Unselected hover affordance — only on the
			   [aria-checked="false"] options so it doesn't fight the
			   active pill. */
			.${ TOGGLE_GROUP_CLASSNAME } [aria-checked="false"]:hover {
				color: #1e1e1e !important;
			}
			/* Keyboard-only focus ring on the selected button using
			   the WP admin theme color. :focus-visible (not :focus)
			   prevents the ring from appearing on mouse clicks — the
			   Gutenberg standard since WP 6.0. */
			.${ TOGGLE_GROUP_CLASSNAME } [aria-checked="true"]:focus-visible {
				outline: 2px solid var( --wp-admin-theme-color, #3858e9 );
				outline-offset: 2px;
				border-radius: 5px;
			}
			/* Windows High Contrast mode: ensure the pill carries a
			   visible edge when forced colors strip the box-shadow. */
			@media ( forced-colors: active ) {
				.${ TOGGLE_GROUP_CLASSNAME }::before {
					outline: 1px solid CanvasText !important;
				}
			}
		` }</style>
	);
}
