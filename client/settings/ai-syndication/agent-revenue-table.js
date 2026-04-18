/**
 * Agent Revenue table
 *
 * Renders the per-AI-agent breakdown (orders + revenue) that sits in
 * the Overview tab under the stats row. This is the plugin's FIRST
 * adoption of a `@woocommerce/components` component; the pattern here
 * is the template for future Woo adoptions (stat cards, endpoint
 * reachability table, etc.). See `AGENTS.md` "Styling" for the
 * adoption rationale and the three-blocker checklist this component
 * satisfies.
 *
 * ## Runtime availability
 *
 * Woo components arrive via `window.wc.components`, provided at
 * runtime by the merchant's wc-admin bundle. On a correctly-
 * configured WooCommerce 9.9+ install the component is available;
 * on older versions or installs where wc-admin has been stripped
 * (some managed hosts do this), the global is undefined.
 *
 * We deliberately do NOT `import { TableCard } from
 * '@woocommerce/components'` here, even though the package is
 * installed as a devDependency. The reason: webpack's Woo
 * dependency-extraction plugin replaces that import with a
 * synchronous destructure like `const { TableCard } =
 * window.wc.components;` at build time. If `window.wc.components`
 * is undefined at script-load time, that destructure throws —
 * crashing the admin page BEFORE any React code runs. Explicit
 * runtime access via `window.wc?.components?.TableCard` (below)
 * degrades gracefully to `null`, lets us pick the fallback branch,
 * and never risks blowing up the page on older/stripped WC.
 *
 * The devDependency is still useful for: (a) IntelliSense on the
 * prop shapes, (b) the webpack externalizer being aware of the
 * package if we add `import`-based usage elsewhere, (c) keeping
 * the door open for migrating to the import form if WooCommerce
 * ever publishes a safer externalization pattern.
 *
 * This resolves blocker #3 from the 1.x AGENTS.md "Styling" note
 * ("graceful degradation when window.wc.components is undefined").
 *
 * ## Two rendering paths, same data
 *
 * The caller hands us `byAgent` (the object from the `/admin/stats`
 * REST endpoint) and `currency` (ISO 4217 string). We format both
 * branches from the same data so the fallback stays honest — a
 * merchant on an old WC version sees an intentionally-styled table,
 * not an obviously-broken one. Currency formatting uses
 * `Intl.NumberFormat` in both paths; right-alignment of numeric
 * columns is applied in both paths; a totals row is rendered in
 * both paths.
 *
 * The Woo path gets pagination, column sorting (currently disabled
 * — we can enable per-column later by flipping `isSortable: true`),
 * screen-reader labels, and the subtle wc-admin visual language that
 * matches the rest of the merchant's dashboard. The fallback path
 * gets a `<table className="widefat">` (the venerable WP admin CSS
 * class — same one that powers the posts/plugins lists) that's
 * functional and correct without looking out of place.
 *
 * @package
 */

import { Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { colors } from './tokens';

/**
 * Resolve the Woo TableCard component from the runtime global, or
 * null when the global isn't available.
 *
 * Extracted so the render path reads cleanly and so tests can see
 * the resolution decision explicitly. If WooCommerce ever namespaces
 * or renames the export, this is the single place that changes.
 *
 * @return {Function|null} The TableCard component, or null if not available.
 */
const getWooTableCard = () => {
	// eslint-disable-next-line no-undef -- Runtime global provided by WooCommerce's wc-admin bundle.
	if (
		typeof window === 'undefined' ||
		! window.wc ||
		! window.wc.components
	) {
		return null;
	}
	// eslint-disable-next-line no-undef
	return window.wc.components.TableCard || null;
};

/**
 * Format a revenue number as a currency string.
 *
 * Uses `Intl.NumberFormat` for locale-aware output (proper currency
 * symbol position, digit grouping, etc.) — a plain `$X.YZ` concat
 * would get the symbol wrong for JPY / EUR / GBP and mis-group
 * numbers above 999 for locales that use `.` as separator. The
 * `undefined` locale arg picks up the browser's locale, which
 * matches what the rest of wc-admin does.
 *
 * Defensive on currency: when the settings blob hasn't loaded
 * currency yet (edge case on fresh render), fall back to USD so
 * formatting doesn't throw. Value is coerced to a number because
 * the REST layer sometimes returns revenue as a string.
 *
 * @param {number|string} amount   Revenue amount.
 * @param {string}        currency ISO 4217 currency code (e.g. 'USD').
 * @return {string} Formatted currency string.
 */
const formatRevenue = ( amount, currency ) => {
	const value = parseFloat( amount ) || 0;
	const iso = currency || 'USD';
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: iso,
		} ).format( value );
	} catch ( _error ) {
		// Intl throws on invalid currency codes. Fall back to a
		// basic format so the UI stays readable rather than blank.
		return `${ iso } ${ value.toFixed( 2 ) }`;
	}
};

/**
 * Compute the totals row data used by both rendering paths.
 *
 * Orders accumulate as integers (always exact); revenue accumulates
 * as a float (precision acceptable since it's display-only — the
 * actual order totals live in WC, this is a dashboard summary). The
 * summary shape matches Woo TableCard's `summary` prop convention:
 * an array of `{ label, value }` objects rendered as a row beneath
 * the table body.
 *
 * @param {Array<Array>} agents   Entries from `Object.entries( byAgent )`.
 * @param {string}       currency ISO 4217 currency code.
 * @return {{orders: number, revenue: string}} Totals for orders + formatted revenue.
 */
const computeTotals = ( agents, currency ) => {
	let orders = 0;
	let revenue = 0;
	for ( const [ , stats ] of agents ) {
		orders += stats.orders;
		revenue += parseFloat( stats.revenue ) || 0;
	}
	return { orders, revenue: formatRevenue( revenue, currency ) };
};

/**
 * Woo TableCard variant of the agent revenue table.
 *
 * Extracted so the fallback variant can share the same data-prep
 * logic without nesting. When this branch runs the merchant sees
 * the same visual language they see on wc-admin's Analytics screens.
 *
 * @param {Object}   root0           Props.
 * @param {Function} root0.TableCard The resolved Woo TableCard component.
 * @param {Array}    root0.agents    `Object.entries( byAgent )`.
 * @param {string}   root0.currency  ISO 4217 currency code.
 */
const WooVariant = ( { TableCard, agents, currency } ) => {
	const headers = [
		{
			key: 'agent',
			label: __( 'Agent', 'woocommerce-ai-syndication' ),
			isLeftAligned: true,
			required: true,
		},
		{
			key: 'orders',
			label: __( 'Orders', 'woocommerce-ai-syndication' ),
			isNumeric: true,
		},
		{
			key: 'revenue',
			label: __( 'Revenue', 'woocommerce-ai-syndication' ),
			isNumeric: true,
		},
	];

	// TableCard row shape: array of arrays, each inner array a series
	// of { display, value } objects — one per column. `display` is
	// the React node rendered; `value` is the sort key (also used
	// for accessibility text). Keeping them in lockstep means
	// sorting will work correctly if we flip `isSortable: true` on
	// the headers later.
	const rows = agents.map( ( [ agent, stats ] ) => [
		{
			display: <strong>{ agent }</strong>,
			value: agent,
		},
		{
			display: stats.orders,
			value: stats.orders,
		},
		{
			display: formatRevenue( stats.revenue, currency ),
			value: parseFloat( stats.revenue ) || 0,
		},
	] );

	const totals = computeTotals( agents, currency );
	const summary = [
		{
			label: __( 'Total orders', 'woocommerce-ai-syndication' ),
			value: totals.orders,
		},
		{
			label: __( 'Total revenue', 'woocommerce-ai-syndication' ),
			value: totals.revenue,
		},
	];

	return (
		<TableCard
			title={ __( 'Revenue by Agent', 'woocommerce-ai-syndication' ) }
			headers={ headers }
			rows={ rows }
			totalRows={ agents.length }
			// 10 is plenty — the agent count is bounded by KNOWN_AGENT_HOSTS
			// size (~10 vendors), so 10 rows per page effectively shows
			// everything while staying future-proof if the map grows.
			rowsPerPage={ 10 }
			summary={ summary }
			isLoading={ false }
			// Keep the ellipsis "Download"/"Columns" menu off — we're
			// not wiring export or column toggles, and showing a menu
			// with only noop items is merchant-confusing.
			showMenu={ false }
		/>
	);
};

/**
 * Hand-rolled fallback. Same data, widefat styling, right-aligned
 * numeric columns and a totals row that match the Woo variant's
 * shape.
 *
 * Used when `window.wc.components.TableCard` is unavailable (older
 * WC, wc-admin stripped). The visual fidelity gap is acceptable —
 * wc-admin < 9.9 is outside our support contract anyway; this path
 * exists so the admin page never breaks, not so it looks pristine.
 * @param {Object} root0          Props.
 * @param {Array}  root0.agents   `Object.entries( byAgent )`.
 * @param {string} root0.currency ISO 4217 currency code.
 */
const FallbackVariant = ( { agents, currency } ) => {
	const totals = computeTotals( agents, currency );

	return (
		<Card style={ { marginTop: '16px' } }>
			<CardBody>
				<h3 style={ { margin: '0 0 12px', fontSize: '14px' } }>
					{ __( 'Revenue by Agent', 'woocommerce-ai-syndication' ) }
				</h3>
				<table className="widefat" style={ { margin: 0 } }>
					<thead>
						<tr>
							<th>
								{ __( 'Agent', 'woocommerce-ai-syndication' ) }
							</th>
							<th style={ { textAlign: 'right' } }>
								{ __( 'Orders', 'woocommerce-ai-syndication' ) }
							</th>
							<th style={ { textAlign: 'right' } }>
								{ __(
									'Revenue',
									'woocommerce-ai-syndication'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ agents.map( ( [ agent, stats ] ) => (
							<tr key={ agent }>
								<td>
									<strong>{ agent }</strong>
								</td>
								<td style={ { textAlign: 'right' } }>
									{ stats.orders }
								</td>
								<td style={ { textAlign: 'right' } }>
									{ formatRevenue( stats.revenue, currency ) }
								</td>
							</tr>
						) ) }
					</tbody>
					<tfoot>
						<tr
							style={ {
								fontWeight: '600',
								background: colors.surfaceSubtle,
							} }
						>
							<td>
								{ __( 'Total', 'woocommerce-ai-syndication' ) }
							</td>
							<td style={ { textAlign: 'right' } }>
								{ totals.orders }
							</td>
							<td style={ { textAlign: 'right' } }>
								{ totals.revenue }
							</td>
						</tr>
					</tfoot>
				</table>
			</CardBody>
		</Card>
	);
};

/**
 * Agent Revenue table — entry point.
 *
 * Picks the Woo variant when available, the hand-rolled fallback
 * otherwise. Caller doesn't need to think about which path ran.
 *
 * @param {Object} root0          Props.
 * @param {Object} root0.byAgent  { [agent]: { orders, revenue } } map from stats REST endpoint.
 * @param {string} root0.currency ISO 4217 currency code (e.g. 'USD').
 * @return {JSX.Element|null} The rendered table, or null if no data.
 */
const AgentRevenueTable = ( { byAgent, currency } ) => {
	const agents = Object.entries( byAgent || {} );
	if ( agents.length === 0 ) {
		return null;
	}

	const TableCard = getWooTableCard();
	if ( TableCard ) {
		return (
			<WooVariant
				TableCard={ TableCard }
				agents={ agents }
				currency={ currency }
			/>
		);
	}

	return <FallbackVariant agents={ agents } currency={ currency } />;
};

export default AgentRevenueTable;
