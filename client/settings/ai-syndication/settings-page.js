import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	CardHeader,
	Button,
	ToggleControl,
	SelectControl,
	TextControl,
	Notice,
	TabPanel,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import BotManager from './bot-manager';
import ProductSelection from './product-selection';
import AttributionStats from './attribution-stats';
import EndpointInfo from './endpoint-info';

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

	const { updateSettingsValues, saveSettings } =
		useDispatch( STORE_NAME );

	if ( isLoading ) {
		return (
			<div style={ { textAlign: 'center', padding: '40px' } }>
				<Spinner />
				<p>{ __( 'Loading settings...', 'woocommerce-ai-syndication' ) }</p>
			</div>
		);
	}

	const tabs = [
		{
			name: 'general',
			title: __( 'General', 'woocommerce-ai-syndication' ),
		},
		{
			name: 'bots',
			title: __( 'Bot Permissions', 'woocommerce-ai-syndication' ),
		},
		{
			name: 'products',
			title: __( 'Product Selection', 'woocommerce-ai-syndication' ),
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
						{ tab.name === 'general' && (
							<GeneralSettings
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

const GeneralSettings = ( { settings, onChange, onSave, isSaving } ) => {
	return (
		<>
			<Card>
				<CardHeader>
					<h2>
						{ __(
							'AI Syndication Settings',
							'woocommerce-ai-syndication'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Enable AI-assisted product discovery for your store. AI agents (ChatGPT, Gemini, Perplexity, Claude) can discover and recommend your products to shoppers. All purchases complete on your website.',
							'woocommerce-ai-syndication'
						) }
					</p>

					<ToggleControl
						label={ __(
							'Enable AI Syndication',
							'woocommerce-ai-syndication'
						) }
						help={ __(
							'When enabled, your store exposes product data via llms.txt, JSON-LD, and a REST API for AI agents.',
							'woocommerce-ai-syndication'
						) }
						checked={ settings.enabled === 'yes' }
						onChange={ ( value ) =>
							onChange( {
								enabled: value ? 'yes' : 'no',
							} )
						}
					/>
				</CardBody>
			</Card>

			{ settings.enabled === 'yes' && (
				<Card style={ { marginTop: '16px' } }>
					<CardHeader>
						<h2>
							{ __(
								'Rate Limits',
								'woocommerce-ai-syndication'
							) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							{ __(
								'Control how many API requests each bot can make.',
								'woocommerce-ai-syndication'
							) }
						</p>
						<Flex>
							<FlexItem>
								<TextControl
									label={ __(
										'Requests per minute',
										'woocommerce-ai-syndication'
									) }
									type="number"
									value={ settings.rate_limit_rpm || 60 }
									onChange={ ( value ) =>
										onChange( {
											rate_limit_rpm:
												parseInt( value ) || 60,
										} )
									}
									min={ 1 }
									max={ 1000 }
								/>
							</FlexItem>
							<FlexItem>
								<TextControl
									label={ __(
										'Requests per hour',
										'woocommerce-ai-syndication'
									) }
									type="number"
									value={
										settings.rate_limit_rph || 1000
									}
									onChange={ ( value ) =>
										onChange( {
											rate_limit_rph:
												parseInt( value ) || 1000,
										} )
									}
									min={ 1 }
									max={ 100000 }
								/>
							</FlexItem>
						</Flex>
					</CardBody>
				</Card>
			) }

			<div style={ { marginTop: '16px' } }>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ onSave }
				>
					{ isSaving
						? __( 'Saving...', 'woocommerce-ai-syndication' )
						: __(
								'Save Changes',
								'woocommerce-ai-syndication'
						  ) }
				</Button>
			</div>
		</>
	);
};

export default AISyndicationSettings;
