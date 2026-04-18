import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	Button,
	SelectControl,
	TabPanel,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import ProductSelection from './product-selection';
import EndpointInfo from './endpoint-info';
import { colors } from './tokens';

// Rate-limit UI (card + presets + RPM state) lives in the Discovery
// tab now — see `endpoint-info.js`. Rationale: rate limiting is a
// property of external-agent traffic policy, same conceptual bucket as
// the crawler allow-list. Keeping them colocated matches the merchant's
// mental model ("who gets in" + "how fast they can go" = one subject).
// Moved here in the 1.6.8 window; see AGENTS.md for the IA discussion.

const AISyndicationSettings = () => {
	const settings = useSelect(
		( select ) => select( STORE_NAME ).getSettings(),
		[]
	);
	const isSaving = useSelect(
		( select ) => select( STORE_NAME ).isSaving(),
		[]
	);
	const isLoading = useSelect( ( select ) => {
		const { isResolving, hasFinishedResolution } = select( STORE_NAME );
		return (
			isResolving( 'getSettings' ) ||
			! hasFinishedResolution( 'getSettings' )
		);
	}, [] );

	const { updateSettingsValues, saveSettings } = useDispatch( STORE_NAME );

	if ( isLoading ) {
		return (
			<div style={ { textAlign: 'center', padding: '40px' } }>
				<Spinner />
				<p>
					{ __( 'Loading settings…', 'woocommerce-ai-syndication' ) }
				</p>
			</div>
		);
	}

	const tabs = [
		{
			name: 'overview',
			title: __( 'Overview', 'woocommerce-ai-syndication' ),
		},
		{
			name: 'products',
			title: __( 'Product Visibility', 'woocommerce-ai-syndication' ),
		},
		{
			name: 'endpoints',
			title: __( 'Discovery', 'woocommerce-ai-syndication' ),
		},
	];

	return (
		<div className="wc-ai-syndication-settings">
			<TabPanel tabs={ tabs }>
				{ ( tab ) => (
					<div style={ { marginTop: '16px' } }>
						{ tab.name === 'overview' && (
							<OverviewTab
								settings={ settings }
								onChange={ updateSettingsValues }
								onSave={ saveSettings }
								isSaving={ isSaving }
							/>
						) }
						{ tab.name === 'products' && (
							<ProductSelection
								settings={ settings }
								onChange={ updateSettingsValues }
								onSave={ saveSettings }
								isSaving={ isSaving }
							/>
						) }
						{ tab.name === 'endpoints' && (
							<EndpointInfo
								settings={ settings }
								onChange={ updateSettingsValues }
								onSave={ saveSettings }
								isSaving={ isSaving }
							/>
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
};

// ---------------------------------------------------------------------------
// Shared components
// ---------------------------------------------------------------------------

// ValueCard is placed inside a FlexItem isBlock wrapper, which handles
// equal-width distribution and stretching. The card itself only owns its
// internal chrome (padding, fill, top-border accent). `height: 100%` lets
// the card fill the stretched FlexItem so all three cards end at the same
// baseline even when their copy wraps to different line counts.
const ValueCard = ( { title, children } ) => (
	<div
		style={ {
			height: '100%',
			padding: '20px',
			background: colors.surfaceSubtle,
			border: 'none',
			borderTop: `3px solid ${ colors.borderMuted }`,
			borderRadius: '4px',
		} }
	>
		<h3
			style={ {
				margin: '0 0 8px',
				fontSize: '14px',
				color: colors.textPrimary,
			} }
		>
			{ title }
		</h3>
		<p
			style={ {
				margin: 0,
				color: colors.textSecondary,
				fontSize: '13px',
				lineHeight: '1.6',
			} }
		>
			{ children }
		</p>
	</div>
);

// Hand-rolled stat card for the Overview stats row. We evaluated Woo's
// `SummaryNumber` from `@woocommerce/components` and deferred adoption —
// see AGENTS.md "Styling" section for the rationale. In short: Woo
// components need their stylesheet enqueued manually on custom admin
// pages (the script-dependency extraction only handles JS, not CSS),
// and the handle names drift between WC versions. Until wc-admin
// provides a reliable way to opt into its stylesheet from a custom
// submenu page, hand-rolled cards are lower-maintenance.
const StatCard = ( { label, value, subvalue, href } ) => {
	const cardStyle = {
		flex: '1 1 0',
		minWidth: '140px',
		padding: '16px',
		background: colors.surfaceSubtle,
		border: 'none',
		borderRadius: '4px',
		textAlign: 'center',
		textDecoration: 'none',
		display: 'block',
	};

	const inner = (
		<>
			<div
				style={ {
					fontSize: '24px',
					fontWeight: '600',
					color: colors.success,
				} }
			>
				{ value }
			</div>
			{ subvalue && (
				<div
					style={ {
						fontSize: '11px',
						color: colors.success,
						marginTop: '2px',
						fontWeight: '400',
					} }
				>
					{ subvalue }
				</div>
			) }
			<div
				style={ {
					fontSize: '12px',
					color: colors.textMuted,
					marginTop: '4px',
					textTransform: 'uppercase',
					letterSpacing: '0.5px',
				} }
			>
				{ label }
			</div>
		</>
	);

	if ( href ) {
		return (
			<a href={ href } style={ cardStyle }>
				{ inner }
			</a>
		);
	}

	return <div style={ cardStyle }>{ inner }</div>;
};

// ---------------------------------------------------------------------------
// Pre-enable view (value pitch)
// ---------------------------------------------------------------------------

const PreEnableView = ( { onChange, onSave, isSaving } ) => (
	<div>
		{ /* --------------------------------------------------------- */ }
		{ /* Group 1: Compact status banner                            */ }
		{ /* Mirrors the enabled-state banner: title + subtitle + CTA  */ }
		{ /* Same height, same rhythm. No bullet points here.          */ }
		{ /* --------------------------------------------------------- */ }
		<div
			style={ {
				background: colors.surface,
				border: `1px solid ${ colors.borderSubtle }`,
				borderLeft: `4px solid ${ colors.borderStrong }`,
				borderRadius: '4px',
				padding: '16px 20px',
				display: 'flex',
				justifyContent: 'space-between',
				alignItems: 'center',
			} }
		>
			<div>
				<strong style={ { color: colors.textSecondary } }>
					{ __(
						'AI Syndication is not enabled',
						'woocommerce-ai-syndication'
					) }
				</strong>
				<p
					style={ {
						margin: '4px 0 0',
						color: colors.textMuted,
						fontSize: '13px',
					} }
				>
					{ __(
						'Enable to make your products discoverable by AI assistants while keeping checkout and customer data on your store.',
						'woocommerce-ai-syndication'
					) }
				</p>
			</div>
			<Button
				variant="primary"
				isBusy={ isSaving }
				disabled={ isSaving }
				onClick={ () => {
					onChange( { enabled: 'yes' } );
					onSave();
				} }
				style={ { flexShrink: 0 } }
			>
				{ isSaving
					? __( 'Enabling…', 'woocommerce-ai-syndication' )
					: __( 'Enable', 'woocommerce-ai-syndication' ) }
			</Button>
		</div>

		{ /* --------------------------------------------------------- */ }
		{ /* Group 2: Value proposition cards                           */ }
		{ /* Mirrors the enabled-state stat cards row.                  */ }
		{ /* Same gap, same marginTop, same flex layout.                */ }
		{ /* Accent uses colors.borderStrong (gray) instead of success. */ }
		{ /* --------------------------------------------------------- */ }
		<Flex gap={ 4 } wrap align="stretch" style={ { marginTop: '24px' } }>
			<FlexItem isBlock>
				<ValueCard
					title={ __(
						'Universal Reach',
						'woocommerce-ai-syndication'
					) }
				>
					{ __(
						'Works with ChatGPT, Gemini, Claude, Perplexity, Copilot, and any future AI agent. One setup, universal reach — no per-platform integration.',
						'woocommerce-ai-syndication'
					) }
				</ValueCard>
			</FlexItem>
			<FlexItem isBlock>
				<ValueCard
					title={ __(
						'Data Sovereignty',
						'woocommerce-ai-syndication'
					) }
				>
					{ __(
						'Checkout stays on your domain. No delegated payments, no platform lock-in. You own the checkout experience and the customer relationship.',
						'woocommerce-ai-syndication'
					) }
				</ValueCard>
			</FlexItem>
			<FlexItem isBlock>
				<ValueCard
					title={ __(
						'Full Order Attribution',
						'woocommerce-ai-syndication'
					) }
				>
					{ __(
						'Every AI-referred sale is tracked using standard WooCommerce Order Attribution. See which agent drove each order and how much revenue it generated.',
						'woocommerce-ai-syndication'
					) }
				</ValueCard>
			</FlexItem>
		</Flex>

		{ /* --------------------------------------------------------- */ }
		{ /* Group 3: "What happens" card with Enable CTA              */ }
		{ /* Mirrors the enabled-state Rate Limits card: same Card     */ }
		{ /* wrapper, same internal structure with headline, body,     */ }
		{ /* content, then a divider + action button at the bottom.    */ }
		{ /* --------------------------------------------------------- */ }
		<Card style={ { marginTop: '32px' } }>
			<CardBody>
				<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
					{ __(
						'Your store, your checkout, your data — visible to every AI assistant',
						'woocommerce-ai-syndication'
					) }
				</h3>
				<p
					style={ {
						color: colors.textSecondary,
						fontSize: '13px',
						margin: '0 0 16px',
					} }
				>
					{ __(
						'When shoppers ask ChatGPT, Gemini, Claude, Perplexity, or Copilot for product recommendations, your catalog shows up. Every checkout happens on your store. No platform fees, no middleman, no data shared.',
						'woocommerce-ai-syndication'
					) }
				</p>

				<ul
					style={ {
						margin: '0',
						paddingLeft: '18px',
						color: colors.textSecondary,
						fontSize: '13px',
						lineHeight: '2',
						listStyle: 'disc',
					} }
				>
					<li>
						{ __(
							'Your product catalog becomes discoverable by AI agents',
							'woocommerce-ai-syndication'
						) }
					</li>
					<li>
						{ __(
							'A machine-readable store guide is published at /llms.txt',
							'woocommerce-ai-syndication'
						) }
					</li>
					<li>
						{ __(
							'AI-referred orders get automatic revenue attribution',
							'woocommerce-ai-syndication'
						) }
					</li>
					<li>
						{ __(
							'You control which products are exposed and who gets access',
							'woocommerce-ai-syndication'
						) }
					</li>
				</ul>

				<p
					style={ {
						color: colors.textMuted,
						fontSize: '12px',
						marginTop: '12px',
						marginBottom: 0,
					} }
				>
					{ __(
						'Nothing is modified. This adds read-only discovery endpoints. You can turn it off at any time.',
						'woocommerce-ai-syndication'
					) }
				</p>

				{ /* CTA inside the card, matching Rate Limits save button position */ }
				<div
					style={ {
						marginTop: '16px',
						paddingTop: '16px',
						borderTop: `1px solid ${ colors.surfaceMuted }`,
					} }
				>
					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving }
						onClick={ () => {
							onChange( { enabled: 'yes' } );
							onSave();
						} }
					>
						{ isSaving
							? __( 'Enabling…', 'woocommerce-ai-syndication' )
							: __(
									'Enable AI Syndication',
									'woocommerce-ai-syndication'
							  ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	</div>
);

// ---------------------------------------------------------------------------
// Post-enable view (dashboard)
// ---------------------------------------------------------------------------

const PostEnableView = ( { settings, onChange, onSave, isSaving } ) => {
	const stats = useSelect(
		( select ) => select( STORE_NAME ).getStats(),
		[]
	);

	const { fetchStats } = useDispatch( STORE_NAME );
	const [ period, setPeriod ] = useState( 'month' );
	const periodLabels = {
		day: __( '24h', 'woocommerce-ai-syndication' ),
		week: __( '7d', 'woocommerce-ai-syndication' ),
		month: __( '30d', 'woocommerce-ai-syndication' ),
		year: __( 'Year', 'woocommerce-ai-syndication' ),
	};

	useEffect( () => {
		fetchStats( period );
	}, [ period ] ); // eslint-disable-line react-hooks/exhaustive-deps -- Refetch when period changes.

	let productCount = __( 'All', 'woocommerce-ai-syndication' );
	if ( settings.product_selection_mode === 'categories' ) {
		productCount = sprintf(
			/* translators: %d: number of categories */
			__( '%d categories', 'woocommerce-ai-syndication' ),
			( settings.selected_categories || [] ).length
		);
	} else if ( settings.product_selection_mode === 'selected' ) {
		productCount = sprintf(
			/* translators: %d: number of products */
			__( '%d products', 'woocommerce-ai-syndication' ),
			( settings.selected_products || [] ).length
		);
	}

	return (
		<div>
			{ /* Status banner */ }
			<div
				style={ {
					background: colors.surface,
					border: `1px solid ${ colors.borderSubtle }`,
					borderLeft: `4px solid ${ colors.success }`,
					borderRadius: '4px',
					padding: '16px 20px',
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
				} }
			>
				<div>
					<strong style={ { color: colors.success } }>
						{ __(
							'AI Syndication is active',
							'woocommerce-ai-syndication'
						) }
					</strong>
					<p
						style={ {
							margin: '4px 0 0',
							color: colors.textSecondary,
							fontSize: '13px',
						} }
					>
						{ __(
							'Your products are discoverable by AI assistants. Checkout and customer data stay on your store.',
							'woocommerce-ai-syndication'
						) }
					</p>
				</div>
				<Button
					variant="secondary"
					isDestructive
					size="compact"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ () => {
						onChange( { enabled: 'no' } );
						onSave();
					} }
				>
					{ isSaving
						? __( 'Disabling…', 'woocommerce-ai-syndication' )
						: __( 'Disable', 'woocommerce-ai-syndication' ) }
				</Button>
			</div>

			{ /* Period selector + stat cards */ }
			<Flex justify="flex-end" style={ { marginTop: '24px' } }>
				<SelectControl
					__next40pxDefaultSize
					value={ period }
					options={ [
						{
							label: __(
								'Last 24 hours',
								'woocommerce-ai-syndication'
							),
							value: 'day',
						},
						{
							label: __(
								'Last 7 days',
								'woocommerce-ai-syndication'
							),
							value: 'week',
						},
						{
							label: __(
								'Last 30 days',
								'woocommerce-ai-syndication'
							),
							value: 'month',
						},
						{
							label: __(
								'Last year',
								'woocommerce-ai-syndication'
							),
							value: 'year',
						},
					] }
					onChange={ setPeriod }
					__nextHasNoMarginBottom
				/>
			</Flex>
			<div
				style={ {
					display: 'flex',
					gap: '16px',
					marginTop: '12px',
					flexWrap: 'wrap',
				} }
			>
				<StatCard
					label={ __(
						'Products Exposed',
						'woocommerce-ai-syndication'
					) }
					value={ productCount }
				/>
				<StatCard
					label={ sprintf(
						/* translators: %s: time period label */
						__( 'Total Orders (%s)', 'woocommerce-ai-syndication' ),
						periodLabels[ period ]
					) }
					value={ stats?.all_orders ?? '\u2014' }
				/>
				<StatCard
					label={ sprintf(
						/* translators: %s: time period label */
						__( 'AI Orders (%s)', 'woocommerce-ai-syndication' ),
						periodLabels[ period ]
					) }
					value={ stats?.ai_orders ?? '\u2014' }
					subvalue={
						stats && stats.ai_share_percent > 0
							? sprintf(
									/* translators: %s: percentage */
									__(
										'%1$s%% of total',
										'woocommerce-ai-syndication'
									),
									stats.ai_share_percent
							  )
							: undefined
					}
					href={
						/* global wcAiSyndicationParams */
						typeof wcAiSyndicationParams !== 'undefined'
							? wcAiSyndicationParams.ordersUrl
							: undefined
					}
				/>
				<StatCard
					label={ sprintf(
						/* translators: %s: time period label */
						__( 'AI Revenue (%s)', 'woocommerce-ai-syndication' ),
						periodLabels[ period ]
					) }
					value={
						stats
							? `${ stats.currency || '$' } ${ parseFloat(
									stats.ai_revenue || 0
							  ).toFixed( 2 ) }`
							: '\u2014'
					}
				/>
			</div>

			{ /* Per-agent breakdown */ }
			{ stats && Object.keys( stats.by_agent || {} ).length > 0 && (
				<Card style={ { marginTop: '16px' } }>
					<CardBody>
						<h3
							style={ {
								margin: '0 0 12px',
								fontSize: '14px',
							} }
						>
							{ __(
								'Revenue by Agent',
								'woocommerce-ai-syndication'
							) }
						</h3>
						<table className="widefat" style={ { margin: 0 } }>
							<thead>
								<tr>
									<th>
										{ __(
											'Agent',
											'woocommerce-ai-syndication'
										) }
									</th>
									<th>
										{ __(
											'Orders',
											'woocommerce-ai-syndication'
										) }
									</th>
									<th>
										{ __(
											'Revenue',
											'woocommerce-ai-syndication'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ Object.entries( stats.by_agent ).map(
									( [ agent, agentStats ] ) => (
										<tr key={ agent }>
											<td>
												<strong>{ agent }</strong>
											</td>
											<td>{ agentStats.orders }</td>
											<td>
												{ stats.currency || '$' }{ ' ' }
												{ parseFloat(
													agentStats.revenue
												).toFixed( 2 ) }
											</td>
										</tr>
									)
								) }
							</tbody>
						</table>
					</CardBody>
				</Card>
			) }
		</div>
	);
};

// ---------------------------------------------------------------------------
// Overview Tab (routes to pre/post enable views)
// ---------------------------------------------------------------------------

const OverviewTab = ( { settings, onChange, onSave, isSaving } ) => {
	// Track which view was active when a save started, so the view
	// doesn't flip mid-save (which swaps Enable/Disable labels).
	const [ viewState, setViewState ] = useState( settings.enabled );

	useEffect( () => {
		if ( ! isSaving ) {
			setViewState( settings.enabled );
		}
	}, [ isSaving, settings.enabled ] );

	const isEnabled = viewState === 'yes';

	if ( isEnabled ) {
		return (
			<PostEnableView
				settings={ settings }
				onChange={ onChange }
				onSave={ onSave }
				isSaving={ isSaving }
			/>
		);
	}

	return (
		<PreEnableView
			onChange={ onChange }
			onSave={ onSave }
			isSaving={ isSaving }
		/>
	);
};

export default AISyndicationSettings;
