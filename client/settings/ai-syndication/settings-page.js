import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	Button,
	TextControl,
	SelectControl,
	RadioControl,
	TabPanel,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
// Woo composes SummaryList/SummaryNumber out of WP primitives and ships
// them as part of wc-admin — the admin always has them available at
// runtime via the @woocommerce/dependency-extraction-webpack-plugin
// configured in webpack.config.js. Rebuilding equivalents with WP
// primitives would duplicate work that's already done and cause visual
// drift vs. native wc-admin screens. See AGENTS.md "Styling" section.
import { SummaryList, SummaryNumber } from '@woocommerce/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import ProductSelection from './product-selection';
import EndpointInfo from './endpoint-info';
import { colors } from './tokens';

const RATE_LIMIT_PRESETS = {
	conservative: { rpm: 10 },
	recommended: { rpm: 25 },
	generous: { rpm: 100 },
};

const getActivePreset = ( rpm ) => {
	for ( const [ key, preset ] of Object.entries( RATE_LIMIT_PRESETS ) ) {
		if ( preset.rpm === rpm ) {
			return key;
		}
	}
	return 'custom';
};

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

	const rpm = settings.rate_limit_rpm || 25;
	const [ customOverride, setCustomOverride ] = useState( false );
	const activePreset = customOverride ? 'custom' : getActivePreset( rpm );

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
			<div style={ { marginTop: '12px' } }>
				<SummaryList>
					{ () => [
						<SummaryNumber
							key="products"
							label={ __(
								'Products Exposed',
								'woocommerce-ai-syndication'
							) }
							value={ productCount }
						/>,
						<SummaryNumber
							key="total-orders"
							label={ sprintf(
								/* translators: %s: time period label */
								__(
									'Total Orders (%s)',
									'woocommerce-ai-syndication'
								),
								periodLabels[ period ]
							) }
							value={ stats?.all_orders ?? '\u2014' }
						/>,
						<SummaryNumber
							key="ai-orders"
							label={ sprintf(
								/* translators: %s: time period label */
								__(
									'AI Orders (%s)',
									'woocommerce-ai-syndication'
								),
								periodLabels[ period ]
							) }
							value={ stats?.ai_orders ?? '\u2014' }
							// SummaryNumber renders `delta` as a trend pill
							// next to the value. We use it here to surface the
							// AI share of total orders (not a period-over-
							// period change), which fits the visual role.
							// `reverseTrend` is left at its default (false)
							// because higher AI share reads as positive for
							// the merchant.
							delta={
								stats && stats.ai_share_percent > 0
									? stats.ai_share_percent
									: undefined
							}
							href={
								/* global wcAiSyndicationParams */
								typeof wcAiSyndicationParams !== 'undefined'
									? wcAiSyndicationParams.ordersUrl
									: undefined
							}
							// SummaryNumber defaults hrefType to 'wc-admin'
							// which wraps the link in wc-admin navigation.
							// 'external' routes a plain anchor — correct for
							// our direct /wp-admin/ orders URL.
							hrefType="external"
						/>,
						<SummaryNumber
							key="ai-revenue"
							label={ sprintf(
								/* translators: %s: time period label */
								__(
									'AI Revenue (%s)',
									'woocommerce-ai-syndication'
								),
								periodLabels[ period ]
							) }
							value={
								stats
									? `${ stats.currency || '$' } ${ parseFloat(
											stats.ai_revenue || 0
									  ).toFixed( 2 ) }`
									: '\u2014'
							}
						/>,
					] }
				</SummaryList>
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

			{ /* Rate limits with presets + save button inside */ }
			<Card style={ { marginTop: '32px' } }>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __( 'Rate Limits', 'woocommerce-ai-syndication' ) }
					</h3>
					<p
						style={ {
							color: colors.textSecondary,
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Control how frequently AI crawlers can query your Store API. Higher limits allow faster product discovery but use more server resources.',
							'woocommerce-ai-syndication'
						) }
					</p>

					<RadioControl
						selected={ activePreset }
						options={ [
							{
								label: __(
									'Recommended — 25/min (works well for most stores)',
									'woocommerce-ai-syndication'
								),
								value: 'recommended',
							},
							{
								label: __(
									'Conservative — 10/min (shared hosting or low-traffic stores)',
									'woocommerce-ai-syndication'
								),
								value: 'conservative',
							},
							{
								label: __(
									'Generous — 100/min (high-traffic stores on dedicated hosting)',
									'woocommerce-ai-syndication'
								),
								value: 'generous',
							},
							{
								label: __(
									'Custom',
									'woocommerce-ai-syndication'
								),
								value: 'custom',
							},
						] }
						onChange={ ( value ) => {
							if ( RATE_LIMIT_PRESETS[ value ] ) {
								setCustomOverride( false );
								onChange( {
									rate_limit_rpm:
										RATE_LIMIT_PRESETS[ value ].rpm,
								} );
							} else {
								setCustomOverride( true );
							}
						} }
					/>

					{ activePreset === 'custom' && (
						<div
							style={ {
								display: 'flex',
								gap: '16px',
								marginTop: '12px',
								maxWidth: '400px',
							} }
						>
							<TextControl
								label={ __(
									'Requests per minute',
									'woocommerce-ai-syndication'
								) }
								type="number"
								value={ rpm }
								onChange={ ( value ) =>
									onChange( {
										rate_limit_rpm: parseInt( value ) || 60,
									} )
								}
								min={ 1 }
								max={ 1000 }
							/>
						</div>
					) }

					<p
						style={ {
							color: colors.textMuted,
							fontSize: '12px',
							marginTop: '12px',
							marginBottom: 0,
						} }
					>
						{ __(
							'Limits are applied per AI crawler (identified by user-agent string) using the WooCommerce Store API rate limiter. Your regular store traffic is not affected.',
							'woocommerce-ai-syndication'
						) }
					</p>

					{ /* Save button inside the card */ }
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
							onClick={ onSave }
						>
							{ isSaving
								? __( 'Saving…', 'woocommerce-ai-syndication' )
								: __(
										'Save Changes',
										'woocommerce-ai-syndication'
								  ) }
						</Button>
					</div>
				</CardBody>
			</Card>
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
