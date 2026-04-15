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
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import ProductSelection from './product-selection';
import EndpointInfo from './endpoint-info';

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
			title: __( 'Product Selection', 'woocommerce-ai-syndication' ),
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
						{ tab.name === 'endpoints' && <EndpointInfo /> }
					</div>
				) }
			</TabPanel>
		</div>
	);
};

// ---------------------------------------------------------------------------
// Shared components
// ---------------------------------------------------------------------------

const ValueCard = ( { title, children } ) => (
	<div
		style={ {
			flex: '1 1 0',
			minWidth: '200px',
			padding: '20px',
			background: '#f6f7f7',
			border: 'none',
			borderTop: '3px solid #dcdcde',
			borderRadius: '4px',
		} }
	>
		<h3 style={ { margin: '0 0 8px', fontSize: '14px', color: '#1d2327' } }>
			{ title }
		</h3>
		<p
			style={ {
				margin: 0,
				color: '#50575e',
				fontSize: '13px',
				lineHeight: '1.6',
			} }
		>
			{ children }
		</p>
	</div>
);

const StatCard = ( { label, value } ) => (
	<div
		style={ {
			flex: '1 1 0',
			minWidth: '140px',
			padding: '16px',
			background: '#f6f7f7',
			border: 'none',
			borderRadius: '4px',
			textAlign: 'center',
		} }
	>
		<div
			style={ { fontSize: '24px', fontWeight: '600', color: '#00a32a' } }
		>
			{ value }
		</div>
		<div
			style={ {
				fontSize: '12px',
				color: '#757575',
				marginTop: '4px',
				textTransform: 'uppercase',
				letterSpacing: '0.5px',
			} }
		>
			{ label }
		</div>
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
				background: '#fff',
				border: '1px solid #e0e0e0',
				borderLeft: '4px solid #c3c4c7',
				borderRadius: '4px',
				padding: '16px 20px',
				display: 'flex',
				justifyContent: 'space-between',
				alignItems: 'center',
			} }
		>
			<div>
				<strong style={ { color: '#50575e' } }>
					{ __(
						'AI Syndication is not enabled',
						'woocommerce-ai-syndication'
					) }
				</strong>
				<p
					style={ {
						margin: '4px 0 0',
						color: '#757575',
						fontSize: '13px',
					} }
				>
					{ __(
						'Enable to make your products discoverable by AI assistants.',
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
		{ /* Accent color #c3c4c7 (gray) instead of enabled green.     */ }
		{ /* --------------------------------------------------------- */ }
		<div
			style={ {
				display: 'flex',
				gap: '16px',
				marginTop: '24px',
				flexWrap: 'wrap',
			} }
		>
			<ValueCard
				title={ __( 'Universal Reach', 'woocommerce-ai-syndication' ) }
			>
				{ __(
					'Works with ChatGPT, Gemini, Claude, Perplexity, Copilot, and any future AI agent. One setup, universal reach — no per-platform integration.',
					'woocommerce-ai-syndication'
				) }
			</ValueCard>
			<ValueCard
				title={ __( 'Data Sovereignty', 'woocommerce-ai-syndication' ) }
			>
				{ __(
					'No marketplace middleman. No delegated payments. No platform lock-in. Your checkout, your customer data, your brand experience.',
					'woocommerce-ai-syndication'
				) }
			</ValueCard>
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
		</div>

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
						'Sell through every AI assistant — on your terms',
						'woocommerce-ai-syndication'
					) }
				</h3>
				<p
					style={ {
						color: '#50575e',
						fontSize: '13px',
						margin: '0 0 16px',
					} }
				>
					{ __(
						'When shoppers ask ChatGPT, Gemini, Claude, Perplexity, or Copilot for product recommendations, your catalog shows up. Checkout happens on your store — no platform fees, no middleman.',
						'woocommerce-ai-syndication'
					) }
				</p>

				<ul
					style={ {
						margin: '0',
						paddingLeft: '18px',
						color: '#50575e',
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
						color: '#757575',
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
						borderTop: '1px solid #f0f0f0',
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
					background: '#fff',
					border: '1px solid #e0e0e0',
					borderLeft: '4px solid #00a32a',
					borderRadius: '4px',
					padding: '16px 20px',
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
				} }
			>
				<div>
					<strong style={ { color: '#00a32a' } }>
						{ __(
							'AI Syndication is active',
							'woocommerce-ai-syndication'
						) }
					</strong>
					<p
						style={ {
							margin: '4px 0 0',
							color: '#50575e',
							fontSize: '13px',
						} }
					>
						{ __(
							'Your products are discoverable by ChatGPT, Gemini, Claude, Perplexity, Copilot, and other AI assistants.',
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
			<div
				style={ {
					display: 'flex',
					justifyContent: 'flex-end',
					marginTop: '24px',
				} }
			>
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
			</div>
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
					label={ __(
						'AI Orders (Month)',
						'woocommerce-ai-syndication'
					) }
					value={ stats?.total_orders ?? '\u2014' }
				/>
				<StatCard
					label={ __(
						'AI Revenue (Month)',
						'woocommerce-ai-syndication'
					) }
					value={
						stats
							? `${ stats.currency || '$' } ${ parseFloat(
									stats.total_revenue || 0
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

			{ /* Rate limits with presets + save button inside */ }
			<Card style={ { marginTop: '32px' } }>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __( 'Rate Limits', 'woocommerce-ai-syndication' ) }
					</h3>
					<p
						style={ {
							color: '#50575e',
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Control how frequently each AI agent can query your catalog. Higher limits allow faster product discovery but use more server resources.',
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
							color: '#757575',
							fontSize: '12px',
							marginTop: '12px',
							marginBottom: 0,
						} }
					>
						{ __(
							'These limits apply per registered agent. Your regular store traffic is never affected.',
							'woocommerce-ai-syndication'
						) }
					</p>

					{ /* Save button inside the card */ }
					<div
						style={ {
							marginTop: '16px',
							paddingTop: '16px',
							borderTop: '1px solid #f0f0f0',
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
