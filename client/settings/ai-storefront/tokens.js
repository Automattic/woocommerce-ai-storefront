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

/**
 * WooCommerce order-status pill palette — bg + fg per status key.
 *
 * Lifted here out of `ai-orders-table.js` to satisfy the "colors
 * live in tokens.js" rule. These aren't tokens in the usual
 * semantic sense (textPrimary / surface / etc.) — they're verbatim
 * copies of what wc-admin's own `.order-status` CSS uses on the
 * native Orders list. The purpose of this block is to give the
 * AI Orders table's StatusPill component the exact visual
 * appearance a merchant sees on WC's native screens so the mental
 * mapping between our table and that one is instantaneous.
 *
 * We hardcode the palette (rather than sharing a CSS variable
 * with wc-admin) because wc-admin's stylesheet isn't loaded on
 * our custom submenu page — same CSS-enqueue gap that killed
 * PR #24's Woo TableCard adoption.
 *
 * If WooCommerce changes its native status-pill palette, update
 * these values to match; the visual invariant is "looks identical
 * to wc-admin's Orders list," not any particular hex.
 *
 * @see client/settings/ai-storefront/ai-orders-table.js StatusPill
 */
export const statusColors = {
	processing: { bg: '#c6e1c6', fg: '#5b841b' },
	completed: { bg: '#c8d7e1', fg: '#2e4453' },
	'on-hold': { bg: '#f8dda7', fg: '#94660c' },
	pending: { bg: '#e5e5e5', fg: '#777' },
	cancelled: { bg: '#e5e5e5', fg: '#777' },
	refunded: { bg: '#e5e5e5', fg: '#777' },
	failed: { bg: '#eba3a3', fg: '#761919' },
};
