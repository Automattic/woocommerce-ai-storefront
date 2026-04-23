/**
 * AI Orders table (Overview tab).
 *
 * Renders recent AI-attributed orders as a DataViews table that
 * visually matches WooCommerce's native Orders list. Column order
 * follows WC convention — Order, Date, Status, Agent, Total — with
 * `Agent` filling the slot where `Origin` sits on the core Orders
 * screen.
 *
 * ## Why @wordpress/dataviews (and not @woocommerce/components)
 *
 * The earlier TableCard adoption (PR #24) failed in practice: Woo
 * components arrive as a runtime external (`window.wc.components`),
 * and their CSS is auto-enqueued only on native wc-admin screens.
 * On custom plugin submenu pages the CSS never loads, so the DOM
 * rendered but the component looked unstyled.
 *
 * DataViews avoids the problem entirely because it lives in the
 * `BUNDLED_PACKAGES` list of the WP dependency-extraction plugin —
 * webpack bundles the JS into our plugin's build and we import its
 * CSS directly (see `./index.js`). That means the styles travel
 * with our plugin and are enqueued alongside our own stylesheet;
 * no dependency on the merchant's wc-admin asset registration
 * order, no dance.
 *
 * Size cost: ~100–200 KB of bundled JS + ~20–40 KB of gzipped CSS,
 * loaded once when a merchant opens the settings page. Acceptable
 * for a dashboard-only admin surface.
 *
 * ## Data contract
 *
 * The backend (`/admin/recent-orders`) returns orders already
 * normalized for display: agent names are canonicalized through
 * `KNOWN_AGENT_HOSTS` (so legacy orders with raw hostnames show
 * up as brand names), date is ISO-8601 + a WC-formatted display
 * string, status is both the machine key (for pill coloring) and
 * the localized label, `edit_url` is HPOS-aware. Currency is
 * formatted client-side via Intl.NumberFormat for locale-correct
 * symbol placement + digit grouping.
 *
 * @package
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Card, CardBody } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import { colors, statusColors } from './tokens';

/**
 * Format a currency amount via Intl.NumberFormat with a safe fallback.
 *
 * Locale is pulled from the browser (`undefined` arg) so formatting
 * matches the merchant's environment — consistent with how wc-admin's
 * own analytics tables display money. We coerce to number and default
 * the currency to USD because the REST layer can return a numeric
 * string on some hosts and we don't want a format failure to blank
 * the cell.
 *
 * @param {number|string} amount   Order total.
 * @param {string}        currency ISO 4217 currency code.
 * @return {string} Formatted currency string.
 */
const formatCurrency = ( amount, currency ) => {
	const value = parseFloat( amount ) || 0;
	const iso = currency || 'USD';
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: iso,
		} ).format( value );
	} catch ( _error ) {
		return `${ iso } ${ value.toFixed( 2 ) }`;
	}
};

/**
 * Guard against non-HTTP(S) URL schemes in an href value.
 *
 * JSX escapes attribute *values* but does not filter URL schemes,
 * so rendering an arbitrary string into `<a href={...}>` could
 * evaluate as `javascript:…` under today's browsers. The current
 * server-side source for these URLs is `admin_url()`, which never
 * returns such a scheme — but if the REST shape ever surfaces a
 * merchant-provided URL (e.g. an external order tracker), this
 * guard removes the entire JS-URL regression class ahead of time.
 *
 * Returns a safe-to-render href, or `null` when the input fails
 * validation. Callers are expected to drop the anchor element on
 * null rather than render a broken link.
 *
 * @param {unknown} url Raw URL from the REST response.
 * @return {string|null} URL string safe to bind to `href`, or null.
 */
const safeHref = ( url ) => {
	if ( typeof url !== 'string' || url === '' ) {
		return null;
	}
	try {
		const parsed = new URL( url, window.location.origin );
		if ( parsed.protocol !== 'https:' && parsed.protocol !== 'http:' ) {
			return null;
		}
		return parsed.href;
	} catch ( _error ) {
		return null;
	}
};

/**
 * Render the colored status pill.
 *
 * Shape + sizing mirror wc-admin's native `.order-status`:
 *   - `border-radius: 4px` — rounded rectangle, not a pill-rounded
 *     oval. Native WC look; matches what merchants see on the
 *     main Orders list.
 *   - `line-height: 2.5em` + zero block padding — gives the pill
 *     its vertical air without pushing the row tall.
 *   - `padding: 0 1em` — horizontal breathing room that scales
 *     with font size.
 *   - subtle `border-bottom` in rgba(0,0,0,0.05) — the WC depth
 *     cue that distinguishes the pill from flat bg color.
 *   - negative vertical margin so the pill doesn't expand the row
 *     height relative to plain-text cells.
 *
 * `title` attribute gives the browser-native hover tooltip, matching
 * the accessibility affordance on WC's own Orders list — screen
 * readers announce the status, sighted users who hover see the
 * label again even when the pill is truncated by a narrow column.
 *
 * @param {Object} root0        Props.
 * @param {string} root0.status WC order status key (e.g. 'processing').
 * @param {string} root0.label  Localized display label.
 * @return {JSX.Element} The rendered pill.
 */
const StatusPill = ( { status, label } ) => {
	const { bg, fg } = statusColors[ status ] || {
		bg: colors.surfaceMuted,
		fg: colors.textMuted,
	};
	return (
		<mark
			className={ `order-status status-${ status }` }
			title={ label }
			style={ {
				display: 'inline-flex',
				alignItems: 'center',
				lineHeight: '2.5em',
				padding: '0 1em',
				borderRadius: '4px',
				background: bg,
				color: fg,
				fontSize: '13px',
				fontWeight: '400',
				whiteSpace: 'nowrap',
				borderBottom: '1px solid rgba(0, 0, 0, 0.05)',
				margin: '-0.25em 0',
			} }
		>
			{ label }
		</mark>
	);
};

/**
 * Initial DataViews view config — table layout, 10 rows per page,
 * all five columns visible. Users can toggle column visibility via
 * DataViews' built-in menu but the initial state is everything-on
 * because every column carries information a merchant actually
 * wants on first glance.
 */
const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 10,
	fields: [ 'order', 'date', 'status', 'agent', 'total' ],
};

const AIOrdersTable = () => {
	const { fetchRecentOrders } = useDispatch( STORE_NAME );

	const recentOrders = useSelect(
		( select ) => select( STORE_NAME ).getRecentOrders(),
		[]
	);

	useEffect( () => {
		fetchRecentOrders( 10 );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Fetch once on mount; settings-change triggers refetch elsewhere.

	const [ view, setView ] = useState( DEFAULT_VIEW );

	// Field definitions — the `id` strings must match keys on the
	// row objects (`order`, `date`, etc.). DataViews uses `render`
	// for the cell's React output and `getValue` (when present) for
	// sorting/filtering. Keeping both in lockstep lets us sort the
	// table on display values without divergence.
	const fields = useMemo(
		() => [
			{
				id: 'order',
				label: __( 'Order', 'woocommerce-ai-storefront' ),
				enableSorting: true,
				render: ( { item } ) => {
					const href = safeHref( item.edit_url );
					// If the URL failed scheme validation fall back
					// to plain text — a broken anchor is worse UX
					// than a non-clickable order number.
					if ( ! href ) {
						return (
							<span
								style={ { fontWeight: '500' } }
							>{ `#${ item.number }` }</span>
						);
					}
					return (
						<a
							href={ href }
							style={ {
								color: colors.link,
								textDecoration: 'none',
								fontWeight: '500',
							} }
						>
							{ `#${ item.number }` }
						</a>
					);
				},
				getValue: ( { item } ) => item.id,
			},
			{
				id: 'date',
				label: __( 'Date', 'woocommerce-ai-storefront' ),
				enableSorting: true,
				render: ( { item } ) => (
					<span title={ item.date }>{ item.date_display }</span>
				),
				// Sort by ISO date string — lexicographic sort on
				// RFC3339 is chronological, no Date parsing needed.
				getValue: ( { item } ) => item.date,
			},
			{
				id: 'status',
				label: __( 'Status', 'woocommerce-ai-storefront' ),
				enableSorting: true,
				// `elements` declares the closed enum of valid values
				// for the field. DataViews treats element-typed fields
				// specially — future filter UI would auto-populate a
				// dropdown from this list, and internal sort / display
				// code can do value → label lookups without us
				// repeating the map at every render. Shipping the
				// declaration now is cheap hygiene; activating the
				// filter UI later becomes a 1-line prop change on
				// this field.
				//
				// Labels use the 'woocommerce' text domain so they
				// inherit WC core's translations — a merchant running
				// a French or Japanese store sees the same "En cours"
				// / "処理中" that appears on the native Orders list,
				// not a separately-translated copy we'd have to
				// maintain. This is the standard pattern for plugins
				// integrating with WC's own data model.
				elements: [
					{
						// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
						value: 'processing',
						label: __( 'Processing', 'woocommerce' ),
					},
					{
						// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
						value: 'completed',
						label: __( 'Completed', 'woocommerce' ),
					},
					{
						// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
						value: 'on-hold',
						label: __( 'On hold', 'woocommerce' ),
					},
					{
						// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
						value: 'pending',
						label: __( 'Pending payment', 'woocommerce' ),
					},
					{
						// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
						value: 'cancelled',
						label: __( 'Cancelled', 'woocommerce' ),
					},
					{
						// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
						value: 'refunded',
						label: __( 'Refunded', 'woocommerce' ),
					},
					{
						// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
						value: 'failed',
						label: __( 'Failed', 'woocommerce' ),
					},
				],
				render: ( { item } ) => (
					<StatusPill
						status={ item.status }
						label={ item.status_label }
					/>
				),
				// Sort on the status key (not the label) so the order
				// is stable across locales — an English merchant and
				// a French merchant get the same sort sequence when
				// clicking the Status header. Labels remain the
				// display text.
				getValue: ( { item } ) => item.status,
			},
			{
				id: 'agent',
				label: __( 'Agent', 'woocommerce-ai-storefront' ),
				enableSorting: true,
				render: ( { item } ) => <strong>{ item.agent || '—' }</strong>,
				getValue: ( { item } ) => item.agent,
			},
			{
				id: 'total',
				label: __( 'Total', 'woocommerce-ai-storefront' ),
				enableSorting: true,
				render: ( { item } ) =>
					formatCurrency( item.total, item.currency ),
				// Sort by numeric total (not the formatted string),
				// so "$1,000" sorts higher than "$200" not lower.
				getValue: ( { item } ) => item.total,
			},
		],
		[]
	);

	// Wrap the array-or-fallback expression in a memo so its
	// referential identity is stable when the underlying orders
	// haven't changed. Without this, the `|| []` creates a fresh
	// empty array each render, invalidating every downstream useMemo
	// that depends on `data` (below) on every parent re-render.
	const data = useMemo( () => recentOrders?.orders || [], [ recentOrders ] );

	// DataViews expects the data already filtered/sorted/paginated.
	// The `filterSortAndPaginate` helper applies the current view
	// config against the raw rows and returns the slice to render
	// plus pagination metadata.
	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( data, view, fields ),
		[ data, view, fields ]
	);

	// Hide the card entirely when there are no AI-attributed orders
	// yet. The stat cards above already tell the merchant "0 AI
	// orders" — a visible empty table here would be redundant noise
	// before the first agent-sourced sale. First sale, the card
	// appears naturally.
	if ( recentOrders && data.length === 0 ) {
		return null;
	}

	return (
		<Card style={ { marginTop: '16px' } }>
			<CardBody>
				<h3
					style={ {
						margin: '0 0 12px',
						fontSize: '14px',
					} }
				>
					{ __( 'Recent AI Orders', 'woocommerce-ai-storefront' ) }
				</h3>
				<DataViews
					data={ processedData }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					// Row ID maps each row for React keys + selection
					// tracking. Our rows use the WC order ID, which
					// is stable and unique.
					getItemId={ ( item ) => String( item.id ) }
					// We don't wire selection/bulk-actions for this
					// read-only dashboard view, so pass an empty
					// actions array. Keeping the prop avoids a
					// console warning from DataViews.
					actions={ [] }
				/>
			</CardBody>
		</Card>
	);
};

export default AIOrdersTable;
