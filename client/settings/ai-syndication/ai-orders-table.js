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
import { colors } from './tokens';

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
 * Background color for each WooCommerce order status pill.
 *
 * Hardcoded here rather than pulled from WC's CSS because the status
 * pill classes (`.order-status`) live in wc-admin's own stylesheet
 * which isn't loaded on our submenu page — same enqueue gap that
 * killed the TableCard adoption. Inlining the colors keeps the pill
 * legible regardless of whether wc-admin's styles are present.
 *
 * Colors are sampled from wc-admin's own status-pill palette so the
 * pill reads as native-to-WooCommerce at a glance. If the merchant
 * has custom statuses (e.g. from a plugin), the lookup falls back
 * to a neutral gray + the localized label — never crashes.
 */
const STATUS_COLORS = {
	processing: { bg: '#c8d7e1', fg: '#2e4453' },
	completed: { bg: '#c8d7e1', fg: '#2e4453' },
	'on-hold': { bg: '#f8dda7', fg: '#94660c' },
	pending: { bg: '#e5e5e5', fg: '#777' },
	cancelled: { bg: '#e5e5e5', fg: '#777' },
	refunded: { bg: '#e5e5e5', fg: '#777' },
	failed: { bg: '#eba3a3', fg: '#761919' },
};

/**
 * Render the colored status pill.
 *
 * Memo-friendly via the `key` prop on the caller's row — DataViews
 * re-renders cells as pagination/sort changes, and pills are cheap.
 *
 * @param {Object} root0        Props.
 * @param {string} root0.status WC order status key (e.g. 'processing').
 * @param {string} root0.label  Localized display label.
 * @return {JSX.Element} The rendered pill.
 */
const StatusPill = ( { status, label } ) => {
	const { bg, fg } = STATUS_COLORS[ status ] || {
		bg: colors.surfaceMuted,
		fg: colors.textMuted,
	};
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '2px 8px',
				borderRadius: '12px',
				background: bg,
				color: fg,
				fontSize: '12px',
				fontWeight: '500',
				whiteSpace: 'nowrap',
			} }
		>
			{ label }
		</span>
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
				label: __( 'Order', 'woocommerce-ai-syndication' ),
				enableSorting: true,
				render: ( { item } ) => (
					<a
						href={ item.edit_url }
						style={ {
							color: colors.link,
							textDecoration: 'none',
							fontWeight: '500',
						} }
					>
						{ `#${ item.number }` }
					</a>
				),
				getValue: ( { item } ) => item.id,
			},
			{
				id: 'date',
				label: __( 'Date', 'woocommerce-ai-syndication' ),
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
				label: __( 'Status', 'woocommerce-ai-syndication' ),
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
				label: __( 'Agent', 'woocommerce-ai-syndication' ),
				enableSorting: true,
				render: ( { item } ) => <strong>{ item.agent || '—' }</strong>,
				getValue: ( { item } ) => item.agent,
			},
			{
				id: 'total',
				label: __( 'Total', 'woocommerce-ai-syndication' ),
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
					{ __( 'Recent AI Orders', 'woocommerce-ai-syndication' ) }
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
