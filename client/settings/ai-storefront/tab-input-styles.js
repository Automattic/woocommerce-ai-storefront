/**
 * Shared 32px input-height override for settings tabs.
 *
 * The design spec calls for 32px-tall form fields, but WP's `TextControl`
 * and `SelectControl` render at 36–40px depending on `__next40pxDefaultSize`.
 * Each tab's wrapper has its own scoped class (e.g.
 * `ai-storefront-policies-tab`) and renders this `<style>` block to clamp
 * those inner inputs to 32px without leaking to other admin pages.
 *
 * Use:
 *   <div className="ai-storefront-foo-tab">
 *     <TabInputStyles tabClass="ai-storefront-foo-tab" />
 *     ...
 *   </div>
 *
 * Why this lives in its own module: the same WP-component override block
 * was previously copy-pasted into three per-tab `<style>` components, and
 * a similar drift pattern was extracted in PR #91 (`toggle-group-styles.js`).
 * Centralizing here means a fourth tab inherits the rule for free, and a
 * future WP component-bundle bump that changes input defaults can be
 * resolved in one place.
 *
 * @param {Object} root0          Component props.
 * @param {string} root0.tabClass The tab's wrapper class (must match the
 *                                wrapping `<div className="...">`).
 */
export function TabInputStyles( { tabClass } ) {
	return (
		<style>{ `
			.${ tabClass } .components-search-control .components-input-control__input,
			.${ tabClass } .components-text-control__input,
			.${ tabClass } .components-select-control__input {
				height: 32px;
				min-height: 32px;
			}
		` }</style>
	);
}
