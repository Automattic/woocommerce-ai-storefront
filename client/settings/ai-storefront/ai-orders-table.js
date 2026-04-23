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
import { STORE_NAME } from '../../data/ai-storefront/constants';
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

/**
 * A muted bar standing in for a text cell in the ghost row.
 *
 * @param {Object} root0       Props.
 * @param {string} root0.width Inline width (e.g. "55%") so the
 *                             ghost row's cells have natural
 *                             variation rather than reading as
 *                             identical bars.
 */
const GhostCell = ( { width } ) => (
	<div
		style={ {
			height: '12px',
			width,
			background: colors.surfaceMuted,
			borderRadius: '3px',
		} }
	/>
);

/**
 * A muted pill standing in for the colored status pill. Matches
 * the real StatusPill's 4px radius + 22px height so the ghost row
 * and the first populated row have the same vertical rhythm when
 * the table transitions from empty → first order.
 */
const GhostPill = () => (
	<div
		style={ {
			height: '22px',
			width: '70%',
			background: colors.surfaceMuted,
			borderRadius: '4px',
		} }
	/>
);

/**
 * Five-column header + single ghost body row, used by the
 * `EmptyState` card to hint at what the populated table looks like.
 *
 * Column-width caveat: DataViews renders with a real `<table>`
 * element on the populated path (current `@wordpress/dataviews`),
 * so its columns size from intrinsic `<td>` content + `table-layout:
 * auto`. This ghost uses a CSS grid with `repeat(5, minmax(0, 1fr))`
 * for equi-width columns instead — a deliberate approximation, not
 * a mirror. When the first real order arrives and the component
 * swaps from `EmptyState` to `<DataViews>`, expect a one-time
 * column-width reflow as the Order column grows and the narrower
 * ones (Agent / Total) shrink. That's a single transition the
 * merchant sees once per store's lifetime; avoiding it would
 * require measuring real `<table>` layout and hand-tuning a
 * matching grid, which isn't worth the maintenance cost.
 *
 * Opacity, not dashed borders: WP admin reserves dashed patterns
 * for placeholder / not-yet-built signaling, which is the wrong
 * semantic. This is a real surface showing real future content
 * structure, dimmed to say "dormant."
 *
 * Accessibility: the entire GhostTable is `aria-hidden` so screen
 * readers skip the pseudo-tabular visual scaffolding and announce
 * only the heading + explanatory copy that follow it inside
 * `EmptyState`. Column header text would otherwise read as a blob
 * of uppercase labels ("ORDER DATE STATUS AGENT TOTAL") with no
 * indication of tabular structure, followed by a hidden row — net
 * noise for AT users.
 */
const GhostTable = () => (
	<div
		aria-hidden="true"
		style={ {
			border: `1px solid ${ colors.borderSubtle }`,
			borderRadius: '3px',
			overflow: 'hidden',
		} }
	>
		<div
			style={ {
				display: 'grid',
				gridTemplateColumns: 'repeat(5, minmax(0, 1fr))',
				gap: '12px',
				padding: '12px 16px',
				background: colors.surfaceSubtle,
				borderBottom: `1px solid ${ colors.borderSubtle }`,
				fontSize: '12px',
				fontWeight: '600',
				color: colors.textSecondary,
				textTransform: 'uppercase',
				letterSpacing: '0.4px',
			} }
		>
			<span>{ __( 'Order', 'woocommerce-ai-storefront' ) }</span>
			<span>{ __( 'Date', 'woocommerce-ai-storefront' ) }</span>
			<span>{ __( 'Status', 'woocommerce-ai-storefront' ) }</span>
			<span>{ __( 'Agent', 'woocommerce-ai-storefront' ) }</span>
			<span>{ __( 'Total', 'woocommerce-ai-storefront' ) }</span>
		</div>
		<div
			style={ {
				display: 'grid',
				gridTemplateColumns: 'repeat(5, minmax(0, 1fr))',
				gap: '12px',
				padding: '14px 16px',
				opacity: 0.5,
			} }
		>
			<GhostCell width="45%" />
			<GhostCell width="75%" />
			<GhostPill />
			<GhostCell width="55%" />
			<GhostCell width="40%" />
		</div>
	</div>
);

/**
 * Empty-state card rendered when AI attribution is active but no
 * orders have been referred yet.
 *
 * Visual anatomy
 * --------------
 * Three stacked elements inside the same Card shell AIOrdersTable
 * uses on the loaded-with-data path, so the "before first order"
 * and "after first order" states read as the same surface:
 *
 *  1. Header ("Recent AI Orders" — same as the populated card).
 *  2. `GhostTable` — column header strip + single dimmed ghost
 *     body row (aria-hidden, purely visual).
 *  3. Primary copy ("Ready for your first AI order") + supporting
 *     sentence, centered below the ghost row. This block carries
 *     all the semantic meaning for screen-reader users; the ghost
 *     table above it is decorative scaffolding that just happens
 *     to preview the column layout for sighted merchants.
 *
 * Copy decisions
 * --------------
 * Positive framing rather than "No orders yet" — the merchant has
 * JUST enabled AI discovery and this state will be the default for
 * every fresh store, so the empty state shouldn't read like a
 * problem. It's the start of the pipeline, not an error.
 *
 * The sentence enumerates the concrete trigger ("refers a shopper
 * who buys") rather than leaving the merchant to guess what
 * "appearing here" depends on. Specificity > generality when the
 * goal is managing expectation.
 *
 * We deliberately do NOT list the five assistant names (ChatGPT,
 * Gemini, etc.) — they already appear prominently on the pre-enable
 * hero and again in the Discovery tab. Repeating them here would
 * sprawl the empty state into marketing territory when its job is
 * just "tell the merchant what lands here."
 *
 * Not exported — only used by AIOrdersTable.
 */
const EmptyState = () => (
	<Card style={ { marginTop: '16px' } }>
		<CardBody>
			<h3 style={ { margin: '0 0 12px', fontSize: '14px' } }>
				{ __( 'Recent AI Orders', 'woocommerce-ai-storefront' ) }
			</h3>

			<GhostTable />

			<div
				style={ {
					textAlign: 'center',
					padding: '20px 16px 4px',
				} }
			>
				<p
					style={ {
						margin: '0 0 6px',
						fontSize: '14px',
						fontWeight: '600',
						color: colors.textPrimary,
					} }
				>
					{ __(
						'Ready for your first AI order',
						'woocommerce-ai-storefront'
					) }
				</p>
				<p
					style={ {
						margin: 0,
						fontSize: '13px',
						color: colors.textSecondary,
						maxWidth: '520px',
						marginLeft: 'auto',
						marginRight: 'auto',
						lineHeight: '1.5',
					} }
				>
					{ __(
						'Your store is discoverable by AI shopping assistants. When one refers a shopper who buys, the order will appear here with the referring agent.',
						'woocommerce-ai-storefront'
					) }
				</p>
			</div>
		</CardBody>
	</Card>
);

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

	// Render a "ready for your first order" empty state when there
	// are no AI-attributed orders yet. Earlier revisions hid the
	// card entirely in this state, but the empty space made the
	// Overview tab look under-configured — merchants who just
	// enabled the plugin couldn't see what the capability would
	// actually produce. A medium empty state (ghost row framed by
	// the real column headers + positive-framing copy) fixes that:
	// the table structure is visible so the layout self-documents
	// what's coming, and the copy sets realistic expectations
	// without over-promising.
	//
	// Distinct from the `recentOrders` not-yet-loaded state — if
	// the fetch hasn't resolved we return null so DataViews owns
	// the loading render downstream. The empty state below is for
	// `recentOrders` resolved with zero rows.
	if ( recentOrders && data.length === 0 ) {
		return <EmptyState />;
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
