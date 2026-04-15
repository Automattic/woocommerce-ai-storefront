import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	Button,
	TextControl,
	RadioControl,
	TabPanel,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import BotManager from './bot-manager';
import ProductSelection from './product-selection';
import AttributionStats from './attribution-stats';
import EndpointInfo from './endpoint-info';

const RATE_LIMIT_PRESETS = {
	conservative: { rpm: 20, rph: 200 },
	recommended: { rpm: 60, rph: 1000 },
	generous: { rpm: 200, rph: 5000 },
};

const getActivePreset = ( rpm, rph ) => {
	for ( const [ key, preset ] of Object.entries( RATE_LIMIT_PRESETS ) ) {
		if ( preset.rpm === rpm && preset.rph === rph ) {
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
			name: 'bots',
			title: __( 'Bot Permissions', 'woocommerce-ai-syndication' ),
		},
		{
			name: 'attribution',
			title: __( 'Attribution', 'woocommerce-ai-syndication' ),
		},
		{
			name: 'endpoints',
			title: __( 'Endpoints', 'woocommerce-ai-syndication' ),
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
						{ tab.name === 'bots' && <BotManager /> }
						{ tab.name === 'products' && (
							<ProductSelection
								settings={ settings }
								onChange={ updateSettingsValues }
								onSave={ saveSettings }
								isSaving={ isSaving }
							/>
						) }
						{ tab.name === 'attribution' && <AttributionStats /> }
						{ tab.name === 'endpoints' && <EndpointInfo /> }
					</div>
				) }
			</TabPanel>
		</div>
	);
};

// ---------------------------------------------------------------------------
// Value Pitch (shown before enabling)
// ---------------------------------------------------------------------------

const ValueCard = ( { title, children } ) => (
	<div
		style={ {
			flex: '1 1 0',
			minWidth: '200px',
			padding: '20px',
			background: '#fff',
			border: '1px solid #e0e0e0',
			borderRadius: '4px',
		} }
	>
		<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>{ title }</h3>
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

const PreEnableView = ( { onChange, onSave, isSaving } ) => (
	<>
		{ /* Status banner — same position as the enabled state */ }
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
			>
				{ isSaving
					? __( 'Enabling…', 'woocommerce-ai-syndication' )
					: __( 'Enable', 'woocommerce-ai-syndication' ) }
			</Button>
		</div>

		{ /* Hero pitch */ }
		<Card style={ { marginTop: '12px' } }>
			<CardBody>
				<div style={ { maxWidth: '720px' } }>
					<h2 style={ { fontSize: '20px', margin: '0 0 12px' } }>
						{ __(
							'Sell through every AI assistant — on your terms',
							'woocommerce-ai-syndication'
						) }
					</h2>
					<p
						style={ {
							fontSize: '14px',
							lineHeight: '1.7',
							color: '#50575e',
							margin: 0,
						} }
					>
						{ __(
							'When shoppers ask ChatGPT, Gemini, Claude, Perplexity, or Copilot for product recommendations, your catalog shows up. Checkout happens on your store — no platform fees, no middleman.',
							'woocommerce-ai-syndication'
						) }
					</p>
				</div>
			</CardBody>
		</Card>

		{ /* Value props */ }
		<div
			style={ {
				display: 'flex',
				gap: '12px',
				marginTop: '12px',
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

		{ /* What happens */ }
		<Card style={ { marginTop: '12px' } }>
			<CardBody>
				<h3 style={ { margin: '0 0 12px', fontSize: '14px' } }>
					{ __(
						'What happens when you enable',
						'woocommerce-ai-syndication'
					) }
				</h3>
				<ul
					style={ {
						margin: 0,
						paddingLeft: '20px',
						lineHeight: '1.8',
						color: '#50575e',
						fontSize: '13px',
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
			</CardBody>
		</Card>
	</>
);

// ---------------------------------------------------------------------------
// Dashboard (shown after enabling)
// ---------------------------------------------------------------------------

const StatCard = ( { label, value } ) => (
	<div
		style={ {
			flex: '1 1 0',
			minWidth: '140px',
			padding: '16px',
			background: '#fff',
			border: '1px solid #e0e0e0',
			borderRadius: '4px',
			textAlign: 'center',
		} }
	>
		<div
			style={ { fontSize: '24px', fontWeight: 'bold', color: '#1d2327' } }
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

const PostEnableView = ( { settings, onChange, onSave, isSaving } ) => {
	const stats = useSelect(
		( select ) => select( STORE_NAME ).getStats(),
		[]
	);
	const bots = useSelect( ( select ) => select( STORE_NAME ).getBots(), [] );

	const { fetchStats, fetchBots } = useDispatch( STORE_NAME );

	useEffect( () => {
		fetchStats( 'month' );
		fetchBots();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Fetch once on mount.

	const rpm = settings.rate_limit_rpm || 60;
	const rph = settings.rate_limit_rph || 1000;
	const [ customOverride, setCustomOverride ] = useState( false );
	const activePreset = customOverride
		? 'custom'
		: getActivePreset( rpm, rph );

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

	const botList = Array.isArray( bots ) ? bots : Object.values( bots || {} );

	return (
		<>
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
					onClick={ () => {
						onChange( { enabled: 'no' } );
						onSave();
					} }
				>
					{ __( 'Disable', 'woocommerce-ai-syndication' ) }
				</Button>
			</div>

			{ /* Stat cards */ }
			<div
				style={ {
					display: 'flex',
					gap: '12px',
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
						'Registered Agents',
						'woocommerce-ai-syndication'
					) }
					value={ botList.length }
				/>
				<StatCard
					label={ __(
						'AI Orders (Month)',
						'woocommerce-ai-syndication'
					) }
					value={ stats?.total_orders ?? '—' }
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
							: '—'
					}
				/>
			</div>

			{ /* Rate limits with presets */ }
			<Card style={ { marginTop: '12px' } }>
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
									'Recommended — 60/min, 1,000/hr (works well for most stores)',
									'woocommerce-ai-syndication'
								),
								value: 'recommended',
							},
							{
								label: __(
									'Conservative — 20/min, 200/hr (shared hosting or low-traffic stores)',
									'woocommerce-ai-syndication'
								),
								value: 'conservative',
							},
							{
								label: __(
									'Generous — 200/min, 5,000/hr (high-traffic stores on dedicated hosting)',
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
									rate_limit_rph:
										RATE_LIMIT_PRESETS[ value ].rph,
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
							<TextControl
								label={ __(
									'Requests per hour',
									'woocommerce-ai-syndication'
								) }
								type="number"
								value={ rph }
								onChange={ ( value ) =>
									onChange( {
										rate_limit_rph:
											parseInt( value ) || 1000,
									} )
								}
								min={ 1 }
								max={ 100000 }
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
				</CardBody>
			</Card>

			{ /* Save */ }
			<div style={ { marginTop: '12px' } }>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ onSave }
				>
					{ isSaving
						? __( 'Saving…', 'woocommerce-ai-syndication' )
						: __( 'Save Changes', 'woocommerce-ai-syndication' ) }
				</Button>
			</div>
		</>
	);
};

// ---------------------------------------------------------------------------
// Overview Tab (routes to pre/post enable views)
// ---------------------------------------------------------------------------

const OverviewTab = ( { settings, onChange, onSave, isSaving } ) => {
	if ( settings.enabled === 'yes' ) {
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
