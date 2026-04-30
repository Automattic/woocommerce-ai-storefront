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
	infoBg: '#f0f6fc', // light blue-tint background — selected mode-card bg

	// Links / interactive accents
	link: '#2271b1', // WP admin-blue — default link color (matches wp-admin anchors)
	accent: '#2271b1', // alias of `link` for selected-card border / active tab underline
};

/**
 * Spacing scale (4-based). Use these in place of raw px in JSX
 * `style` props so vertical/horizontal rhythm stays consistent across
 * the settings UI.
 *
 * Step semantics (mostly stable across the design system):
 *   s1=4   hairline gap
 *   s2=8   tight cluster
 *   s3=12  inline form gap
 *   s4=16  card padding / row gap
 *   s5=20  card padding (large)
 *   s6=24  section gap
 *   s7=32  block separation
 *   s8=40  hero padding
 *   s9=48  page-top margin
 *   s10=64 reserved for vertical hero rhythm
 */
export const spacing = {
	s1: '4px',
	s2: '8px',
	s3: '12px',
	s4: '16px',
	s5: '20px',
	s6: '24px',
	s7: '32px',
	s8: '40px',
	s9: '48px',
	s10: '64px',
};

/**
 * Border-radius scale.
 *
 *   xs=2  status-badge corners, subtle pill chrome
 *   sm=3  inputs / segmented inner button
 *   md=4  card / standard corner (matches WP base)
 *   lg=8  hero / elevated surfaces
 *   pill=999 stadium pill (status badges, count chips)
 */
export const radii = {
	xs: '2px',
	sm: '3px',
	md: '4px',
	lg: '8px',
	pill: '999px',
};

/**
 * Box-shadow scale, hand-tuned to match the design-system bundle.
 *
 *   sm  for small lifted surfaces (segmented active option)
 *   lg  for elevated cards (selected mode-card)
 */
export const shadows = {
	sm: '0 1px 1px rgba(0,0,0,.04), 0 1px 2px rgba(0,0,0,.06)',
	lg: '0 2px 4px rgba(0,0,0,.05), 0 6px 12px rgba(0,0,0,.08)',
};

/**
 * Typography tokens for repeated micro-label patterns.
 *
 * The "eyebrow label" — uppercase tracked small caps used for table
 * column headers, StatCard labels, action-toolbar headings, inline
 * group titles, and status pre-titles — was previously inlined at
 * every such site with inconsistent letter-spacing values
 * (`0.4px`, `0.5px`, `0.8px`, `0.04em` all in use). The visual
 * intent at every site is the same: WordPress's
 * `.components-base-control__label` small-caps tracked treatment.
 * The tokenized value is `0.04em`, which scales with the element's
 * font-size — pixel values lock the tracking and read inconsistently
 * when font-size changes.
 *
 * Spread into a JSX `style={ ... }` prop:
 *   <span style={ { ...typography.eyebrowLabel, color: ... } }>
 *
 * Don't add new uppercase-tracked labels without using this token.
 * If a new label needs a different fontSize, override that key
 * specifically (`...typography.eyebrowLabel, fontSize: '11px'`) so the
 * tracking stays uniform.
 */
export const typography = {
	eyebrowLabel: {
		fontSize: '12px',
		fontWeight: '600',
		textTransform: 'uppercase',
		letterSpacing: '0.04em',
	},

	// Page-level h1 sitting above the TabPanel. Matches wp-admin's
	// own `.wrap > h1` rhythm (23px / 400) so the in-page title
	// reads at the same altitude as Settings, Plugins, Tools, etc.
	adminTitle: {
		fontSize: '23px',
		fontWeight: 400,
		lineHeight: 1.3,
	},

	// Section h2 used at the top of each tab. One step below the
	// page-level h1, names the operator's job for the tab. Pairs
	// with a 13px italic gray description sentence below it.
	sectionHeading: {
		fontSize: '18px',
		fontWeight: 600,
		lineHeight: 1.3,
	},

	// Stat-card display value. Tighter letter-spacing pulls the
	// digits together so a 6-card strip reads as a peer set rather
	// than as six separate panels. Color is always neutral
	// (`textPrimary`) — see "Stat-value color is always neutral"
	// in docs/design/settings-redesign-final.html.
	statValue: {
		fontSize: '20px',
		fontWeight: 700,
		letterSpacing: '-0.005em',
	},

	// Pre-enable hero headline. Bigger / tighter than sectionHeading
	// because the hero IS the section header on the pre-enable view
	// (no separate section-head block sits above it).
	heroHeadline: {
		fontSize: '28px',
		fontWeight: 700,
		letterSpacing: '-0.02em',
		lineHeight: 1.2,
	},
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
