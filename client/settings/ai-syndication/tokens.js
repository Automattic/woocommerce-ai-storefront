/**
 * Color tokens for AI Syndication admin UI.
 *
 * These values map to the WordPress admin color palette (see
 * `@wordpress/base-styles/_colors.scss`). Import and use these tokens
 * instead of inline hex literals so a future palette migration — or a
 * layer that switches to CSS custom properties (e.g.
 * `var( --wp-components-color-gray-700, #50575e )`) — can be made in
 * one place.
 *
 * Naming is semantic (textPrimary, surfaceSubtle) rather than raw
 * scale-level (gray-900) so visual intent survives a palette shift.
 * When a visual role has multiple variants in the same screen,
 * differentiate with a qualifier (borderSubtle vs borderStrong)
 * rather than inventing a new role.
 *
 * Best practice for this plugin:
 * - Never embed raw hex values in JSX `style={ ... }` props.
 * - If a new color is needed, add a semantic token here first.
 * - When composing multi-value strings (`'1px solid <hex>'`),
 *   use a template literal to interpolate the token:
 *     border: `1px solid ${ colors.borderSubtle }`
 */
export const colors = {
	// Text
	textPrimary: '#1d2327', // WP gray-900 — headings, emphasized labels
	textSecondary: '#50575e', // WP gray-700 — body copy, banner descriptions
	textMuted: '#757575', // WP gray-600 — helper text, captions

	// Surfaces (backgrounds)
	surface: '#fff', // card / banner background
	surfaceSubtle: '#f6f7f7', // section fills (value cards, checkbox wrappers)
	surfaceMuted: '#f0f0f0', // pill / chip background, thin dividers

	// Borders
	borderSubtle: '#e0e0e0', // WP gray-300 — card borders, list row dividers
	borderMuted: '#dcdcde', // ValueCard top accent (pre-enable state)
	borderStrong: '#c3c4c7', // WP gray-400 — pre-enable banner left accent

	// Status
	success: '#00a32a', // WP alert-green — active accents, stat values
	successBg: '#edfaef', // success tint — pill background when populated
	error: '#d63638', // WP alert-red — warning copy

	// Links
	link: '#2271b1', // WP admin-blue — default link color (matches wp-admin anchors)
};
