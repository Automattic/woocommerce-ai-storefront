/**
 * Policies tab — store-wide policy signals exposed to AI agents.
 *
 * Today this tab hosts a single "Return & refund policy" section that
 * drives the structured-data emission of `hasMerchantReturnPolicy` at
 * the Offer level. Pre-PR-C the plugin emitted a structurally invalid
 * `MerchantReturnFiniteReturnWindow` block on every product (no
 * `merchantReturnDays`, no `merchantReturnLink`); Google's validators
 * reject that combination. The new flow lets merchants choose one of
 * three explicit modes (returns accepted / final sale / don't expose)
 * and smart-degrades to `MerchantReturnUnspecified` when days aren't
 * set, so we never publish a broken claim.
 *
 * Future tabs sections (shipping policy, legal pages) live here too —
 * see PR-E / PR-F. Today only the return-policy section ships.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalNumberControl as NumberControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';
import { colors } from './tokens';

const POLICY_MODES = {
	UNCONFIGURED: 'unconfigured',
	RETURNS_ACCEPTED: 'returns_accepted',
	FINAL_SALE: 'final_sale',
};

const FEE_OPTIONS = [
	{
		value: 'FreeReturn',
		label: __( 'Free returns', 'woocommerce-ai-storefront' ),
	},
	{
		value: 'ReturnFeesCustomerResponsibility',
		label: __( 'Customer pays return fees', 'woocommerce-ai-storefront' ),
	},
	{
		value: 'OriginalShippingFees',
		label: __( 'Original shipping fees', 'woocommerce-ai-storefront' ),
	},
	{
		value: 'RestockingFees',
		label: __( 'Restocking fees', 'woocommerce-ai-storefront' ),
	},
];

const METHOD_OPTIONS = [
	{
		value: 'ReturnByMail',
		label: __( 'Return by mail', 'woocommerce-ai-storefront' ),
	},
	{
		value: 'ReturnInStore',
		label: __( 'Return in store', 'woocommerce-ai-storefront' ),
	},
	{
		value: 'ReturnAtKiosk',
		label: __( 'Return at kiosk', 'woocommerce-ai-storefront' ),
	},
];

const DEFAULT_POLICY = {
	mode: POLICY_MODES.UNCONFIGURED,
	page_id: 0,
	days: 0,
	fees: 'FreeReturn',
	methods: [],
};

/**
 * Pure helper: derive the JSON-LD `hasMerchantReturnPolicy` block from
 * the merchant's current draft policy. Mirrors the server-side
 * `WC_AI_Storefront_JsonLd::build_return_policy_block()` so the live
 * preview matches what gets emitted on save.
 *
 * @param {Object} policy  Draft policy state.
 * @param {string} country Store base country (ISO 3166-1 alpha-2).
 * @return {Object|null}   Structured-data block, or `null` for
 *                         `unconfigured` (no emission).
 */
export const derivePreview = ( policy, country ) => {
	if ( ! country || policy.mode === POLICY_MODES.UNCONFIGURED ) {
		return null;
	}

	if ( policy.mode === POLICY_MODES.FINAL_SALE ) {
		const block = {
			'@type': 'MerchantReturnPolicy',
			applicableCountry: country,
			returnPolicyCategory:
				'https://schema.org/MerchantReturnNotPermitted',
		};
		if ( policy.page_id > 0 && policy.pageLink ) {
			block.merchantReturnLink = policy.pageLink;
		}
		return block;
	}

	// returns_accepted
	const days = Number( policy.days ) || 0;
	const block =
		days > 0
			? {
					'@type': 'MerchantReturnPolicy',
					applicableCountry: country,
					returnPolicyCategory:
						'https://schema.org/MerchantReturnFiniteReturnWindow',
					merchantReturnDays: days,
			  }
			: {
					'@type': 'MerchantReturnPolicy',
					applicableCountry: country,
					returnPolicyCategory:
						'https://schema.org/MerchantReturnUnspecified',
			  };

	if ( policy.page_id > 0 && policy.pageLink ) {
		block.merchantReturnLink = policy.pageLink;
	}
	block.returnFees = 'https://schema.org/' + ( policy.fees || 'FreeReturn' );

	const methods = Array.isArray( policy.methods ) ? policy.methods : [];
	if ( methods.length === 1 ) {
		block.returnMethod = 'https://schema.org/' + methods[ 0 ];
	} else if ( methods.length >= 2 ) {
		block.returnMethod = methods.map( ( m ) => 'https://schema.org/' + m );
	}

	return block;
};

/**
 * Safe pretty-printer using textContent (NOT innerHTML) so a
 * malicious page title or other merchant-controlled string can't
 * inject markup into the preview. JSON.stringify gives us a string,
 * we render it inside a `<pre>` via React's normal text path.
 *
 * @param {Object|null} block Preview block, or null for unconfigured.
 * @return {string}           JSON pretty-print, or a placeholder note.
 */
const prettyPrint = ( block ) => {
	if ( block === null ) {
		return __(
			'(No hasMerchantReturnPolicy will be emitted)',
			'woocommerce-ai-storefront'
		);
	}
	return JSON.stringify( block, null, 2 );
};

const ReturnRefundPolicySection = ( {
	policy,
	onChange,
	country,
	pages,
	pagesLoading,
} ) => {
	const handleField = ( field, value ) => {
		onChange( { ...policy, [ field ]: value } );
	};

	const handleMethodToggle = ( method, checked ) => {
		const next = checked
			? Array.from( new Set( [ ...( policy.methods || [] ), method ] ) )
			: ( policy.methods || [] ).filter( ( m ) => m !== method );
		onChange( { ...policy, methods: next } );
	};

	// Resolve the selected page's permalink for the live preview. The
	// pages list returned by /wp/v2/pages includes a `link` field, so
	// we look it up from the cached array rather than firing a second
	// REST call.
	const pageLink = useMemo( () => {
		if ( ! policy.page_id || ! Array.isArray( pages ) ) {
			return '';
		}
		const match = pages.find( ( p ) => p.id === policy.page_id );
		return match ? match.link || '' : '';
	}, [ policy.page_id, pages ] );

	const previewBlock = useMemo(
		() => derivePreview( { ...policy, pageLink }, country ),
		[ policy, pageLink, country ]
	);

	const pageOptions = useMemo( () => {
		const opts = [
			{
				value: 0,
				label: __(
					'— No policy page selected —',
					'woocommerce-ai-storefront'
				),
			},
		];
		if ( Array.isArray( pages ) ) {
			pages.forEach( ( p ) => {
				opts.push( {
					value: p.id,
					label: decodeEntities( p.title?.rendered || p.title || '' ),
				} );
			} );
		}
		return opts;
	}, [ pages ] );

	return (
		<Card>
			<CardHeader>
				<h3
					style={ {
						margin: 0,
						fontSize: '14px',
						fontWeight: 600,
						color: colors.textPrimary,
					} }
				>
					{ __(
						'Return & refund policy',
						'woocommerce-ai-storefront'
					) }
				</h3>
			</CardHeader>
			<CardBody>
				<p
					style={ {
						margin: '0 0 16px',
						color: colors.textSecondary,
						fontSize: '13px',
					} }
				>
					{ __(
						'Choose what AI agents see for returns. The default is to expose nothing — pick a mode to publish a structured policy.',
						'woocommerce-ai-storefront'
					) }
				</p>

				<ToggleGroupControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					isBlock
					value={ policy.mode }
					onChange={ ( val ) => handleField( 'mode', val ) }
					label={ __( 'Policy mode', 'woocommerce-ai-storefront' ) }
					hideLabelFromVision
				>
					<ToggleGroupControlOption
						value={ POLICY_MODES.RETURNS_ACCEPTED }
						label={ __(
							'Returns accepted',
							'woocommerce-ai-storefront'
						) }
					/>
					<ToggleGroupControlOption
						value={ POLICY_MODES.FINAL_SALE }
						label={ __(
							'No returns (final sale)',
							'woocommerce-ai-storefront'
						) }
					/>
					<ToggleGroupControlOption
						value={ POLICY_MODES.UNCONFIGURED }
						label={ __(
							'Don’t expose',
							'woocommerce-ai-storefront'
						) }
					/>
				</ToggleGroupControl>

				<div style={ { marginTop: '20px' } }>
					{ policy.mode === POLICY_MODES.UNCONFIGURED && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'No return policy will be exposed to AI agents. Pick "Returns accepted" or "No returns" to publish one.',
								'woocommerce-ai-storefront'
							) }
						</Notice>
					) }

					{ policy.mode === POLICY_MODES.RETURNS_ACCEPTED && (
						<>
							<div style={ { marginBottom: '16px' } }>
								{ pagesLoading ? (
									<Spinner />
								) : (
									<SelectControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Policy page (optional)',
											'woocommerce-ai-storefront'
										) }
										help={ __(
											'Link AI agents to a full-text policy page on your store.',
											'woocommerce-ai-storefront'
										) }
										value={ String( policy.page_id ) }
										options={ pageOptions.map( ( o ) => ( {
											...o,
											value: String( o.value ),
										} ) ) }
										onChange={ ( val ) =>
											handleField(
												'page_id',
												parseInt( val, 10 ) || 0
											)
										}
									/>
								) }
							</div>

							<div
								style={ {
									marginBottom: '16px',
									maxWidth: '240px',
								} }
							>
								<NumberControl
									__next40pxDefaultSize
									label={ __(
										'Return window (days)',
										'woocommerce-ai-storefront'
									) }
									help={ __(
										'Leave at 0 to publish "Unspecified" instead of a finite window.',
										'woocommerce-ai-storefront'
									) }
									min={ 0 }
									max={ 365 }
									value={ policy.days }
									onChange={ ( val ) =>
										handleField(
											'days',
											parseInt( val, 10 ) || 0
										)
									}
								/>
							</div>

							<div
								style={ {
									marginBottom: '16px',
									maxWidth: '320px',
								} }
							>
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Return fees',
										'woocommerce-ai-storefront'
									) }
									value={ policy.fees }
									options={ FEE_OPTIONS }
									onChange={ ( val ) =>
										handleField( 'fees', val )
									}
								/>
							</div>

							<fieldset
								style={ {
									border: 'none',
									padding: 0,
									margin: 0,
								} }
							>
								<legend
									style={ {
										fontSize: '13px',
										fontWeight: 500,
										color: colors.textPrimary,
										marginBottom: '8px',
									} }
								>
									{ __(
										'Return methods',
										'woocommerce-ai-storefront'
									) }
								</legend>
								{ METHOD_OPTIONS.map( ( opt ) => (
									<CheckboxControl
										__nextHasNoMarginBottom
										key={ opt.value }
										label={ opt.label }
										checked={ (
											policy.methods || []
										).includes( opt.value ) }
										onChange={ ( checked ) =>
											handleMethodToggle(
												opt.value,
												checked
											)
										}
									/>
								) ) }
							</fieldset>
						</>
					) }

					{ policy.mode === POLICY_MODES.FINAL_SALE && (
						<div style={ { marginBottom: '16px' } }>
							{ pagesLoading ? (
								<Spinner />
							) : (
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Policy page (optional)',
										'woocommerce-ai-storefront'
									) }
									help={ __(
										'Link AI agents to a "no returns" explainer on your store.',
										'woocommerce-ai-storefront'
									) }
									value={ String( policy.page_id ) }
									options={ pageOptions.map( ( o ) => ( {
										...o,
										value: String( o.value ),
									} ) ) }
									onChange={ ( val ) =>
										handleField(
											'page_id',
											parseInt( val, 10 ) || 0
										)
									}
								/>
							) }
						</div>
					) }
				</div>

				<div style={ { marginTop: '24px' } }>
					<h4
						style={ {
							margin: '0 0 8px',
							fontSize: '12px',
							fontWeight: 600,
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							color: colors.textMuted,
						} }
					>
						{ __( 'Live preview', 'woocommerce-ai-storefront' ) }
					</h4>
					<pre
						style={ {
							margin: 0,
							padding: '12px 14px',
							background: colors.surfaceSubtle,
							border: `1px solid ${ colors.borderSubtle }`,
							borderRadius: '4px',
							fontSize: '12px',
							lineHeight: '1.5',
							color: colors.textPrimary,
							overflow: 'auto',
							whiteSpace: 'pre-wrap',
							wordBreak: 'break-word',
						} }
					>
						{ prettyPrint( previewBlock ) }
					</pre>
				</div>
			</CardBody>
		</Card>
	);
};

const PoliciesTab = ( { settings, onChange, onSave, isSaving } ) => {
	// Hydrate from saved settings, falling back to safe defaults.
	const initial = useMemo(
		() => ( {
			...DEFAULT_POLICY,
			...( settings.return_policy || {} ),
		} ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);
	const [ draft, setDraft ] = useState( initial );

	// Reflect external setting changes (e.g. server-side migration on
	// reload) into the draft when the saved policy actually changes.
	useEffect( () => {
		if ( settings.return_policy ) {
			setDraft( ( prev ) => ( {
				...DEFAULT_POLICY,
				...settings.return_policy,
				// Preserve in-flight edits that haven't been saved yet
				// — only sync from server when the saved object differs
				// from our last-known sync. Cheap deep-compare via JSON.
				...( JSON.stringify( prev ) ===
				JSON.stringify( {
					...DEFAULT_POLICY,
					...settings.return_policy,
				} )
					? prev
					: {} ),
			} ) );
		}
	}, [ settings.return_policy ] );

	const [ pages, setPages ] = useState( [] );
	const [ pagesLoading, setPagesLoading ] = useState( true );
	const [ pagesError, setPagesError ] = useState( false );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setPagesLoading( true );
		apiFetch( {
			path: '/wp/v2/pages?per_page=100&status=publish&_fields=id,title,link',
		} )
			.then( ( resp ) => {
				if ( ! cancelled ) {
					setPages( Array.isArray( resp ) ? resp : [] );
					setPagesLoading( false );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setPagesError( true );
					setPagesLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	const country =
		typeof window !== 'undefined' && window.wcAiSyndicationParams
			? window.wcAiSyndicationParams.storeCountry || 'US'
			: 'US';

	const handleSave = () => {
		onChange( { return_policy: draft } );
		Promise.resolve( onSave() ).then( () => setSaved( true ) );
	};

	return (
		<div>
			<header style={ { marginBottom: '20px' } }>
				<h2
					style={ {
						margin: '0 0 4px',
						fontSize: '18px',
						fontWeight: 600,
						color: colors.textPrimary,
					} }
				>
					{ __(
						'Policies exposed to AI agents',
						'woocommerce-ai-storefront'
					) }
				</h2>
				<p
					style={ {
						margin: 0,
						color: colors.textSecondary,
						fontSize: '13px',
					} }
				>
					{ __(
						'Configure the policy signals AI agents see for your store.',
						'woocommerce-ai-storefront'
					) }
				</p>
			</header>

			{ pagesError && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Could not load your pages. Page links won’t be available.',
						'woocommerce-ai-storefront'
					) }
				</Notice>
			) }

			<ReturnRefundPolicySection
				policy={ draft }
				onChange={ ( next ) => {
					setDraft( next );
					setSaved( false );
				} }
				country={ country }
				pages={ pages }
				pagesLoading={ pagesLoading }
			/>

			<div
				style={ {
					marginTop: '20px',
					display: 'flex',
					gap: '12px',
					alignItems: 'center',
				} }
			>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ handleSave }
				>
					{ isSaving
						? __( 'Saving…', 'woocommerce-ai-storefront' )
						: __( 'Save changes', 'woocommerce-ai-storefront' ) }
				</Button>
				{ saved && ! isSaving && (
					<span
						style={ {
							color: colors.success,
							fontSize: '13px',
						} }
					>
						{ __( 'Settings saved.', 'woocommerce-ai-storefront' ) }
					</span>
				) }
			</div>
		</div>
	);
};

export default PoliciesTab;
