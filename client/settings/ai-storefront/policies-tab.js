/**
 * Policies tab — store-wide policy signals exposed to AI agents.
 *
 * Today this tab hosts a single "Return & refund policy" section that
 * drives the structured-data emission of `hasMerchantReturnPolicy` at
 * the Offer level. Before this section shipped, the plugin emitted a
 * structurally invalid `MerchantReturnFiniteReturnWindow` block on
 * every product (no `merchantReturnDays`, no `merchantReturnLink`);
 * Google's validators reject that combination. The current flow lets
 * merchants choose one of three explicit modes (returns accepted /
 * final sale / don't expose) and smart-degrades to
 * `MerchantReturnUnspecified` when days aren't set, so the plugin
 * never publishes a broken claim.
 *
 * The tab is structured to host additional policy sections in the
 * future (shipping policy, legal pages); for now the return-policy
 * section is the only one rendered.
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
import {
	ToggleGroupStyles,
	TOGGLE_GROUP_CLASSNAME,
} from './toggle-group-styles';

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
 * a draft policy state. Mirrors the server-side
 * `WC_AI_Storefront_JsonLd::build_return_policy_block()` so a JS-side
 * caller can compute the would-be emission shape without a roundtrip.
 *
 * Currently consumed only by the unit-test suite
 * (`__tests__/policies-tab.test.js`), which exercises both this helper
 * and the server emitter against the same fixtures to keep the two
 * implementations in lockstep. No production render path uses this
 * function — the merchant-facing live-preview block was removed; the
 * Discovery tab's reachability check + the actual product page's
 * JSON-LD inspector are the wire-level verification surfaces.
 *
 * Retained as `export` because the test parity has real value
 * (catches client-server emission drift), and the helper is small +
 * pure. If a future preview surface comes back, this is the right
 * primitive to render from.
 *
 * @param {Object} policy  Draft policy state. Recognised fields:
 *                         `mode`, `page_id`, `days`, `fees`,
 *                         `methods[]`, `pageLink` — the last is a
 *                         test-input shape (production code resolves
 *                         the URL server-side).
 * @param {string} country Store base country (ISO 3166-1 alpha-2).
 *                         Empty string returns null, mirroring the
 *                         server-side `if ( $country && ... )` gate.
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

	// Fail closed for any unknown mode. `unconfigured` and
	// `final_sale` were handled above; only `returns_accepted` should
	// reach the structured-block construction below. A corrupted /
	// legacy / filter-mutated mode value would otherwise silently
	// produce a returns-accepted block in tests that disagrees with
	// the server's `build_return_policy_block()` (which also fails
	// closed). Mirrors the server's defense-in-depth so client-server
	// parity stays intact under malformed input.
	if ( policy.mode !== POLICY_MODES.RETURNS_ACCEPTED ) {
		return null;
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
 * The return & refund policy configuration section inside the Policies
 * tab. Renders the three-way mode toggle (returns accepted / final
 * sale / don't expose), conditional fields per mode, and a live
 * JSON-LD preview of what the server will emit.
 *
 * The section is purely presentational: every state change is bubbled
 * up through `onChange` so the parent (`PoliciesTab`) owns the
 * canonical draft. The preview is computed via `derivePreview()` and
 * mirrors the server-side `build_return_policy_block()` smart-degrade
 * logic — the two are exercised in lockstep by the test suite.
 *
 * @param {Object}   props
 * @param {Object}   props.policy       Current policy draft (mode + sub-fields).
 * @param {Function} props.onChange     Called with `(partialPolicy)` when any field changes.
 * @param {Array}    props.pages        Published pages list `[{id, title, link}]`.
 * @param {boolean}  props.pagesLoading Whether the pages list is still resolving.
 */
const ReturnRefundPolicySection = ( {
	policy,
	onChange,
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
						'AI agents read this to decide whether to recommend your products and place buy actions. Without a clear return policy, they typically downgrade or skip your products in favour of competitors who publish one.',
						'woocommerce-ai-storefront'
					) }
				</p>

				<ToggleGroupStyles />
				<ToggleGroupControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					className={ TOGGLE_GROUP_CLASSNAME }
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
							'No returns',
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
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'AI agents may downgrade your products in recommendations, or skip them entirely. Pick "Returns accepted" or "No returns" to publish a policy.',
								'woocommerce-ai-storefront'
							) }
						</Notice>
					) }

					{ policy.mode === POLICY_MODES.RETURNS_ACCEPTED && (
						<>
							<div
								style={ {
									marginBottom: '16px',
									maxWidth: '480px',
								} }
							>
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
									onChange={ ( val ) => {
										// Clamp to [0, 365] explicitly. The
										// `min`/`max` props on NumberControl
										// are advisory in browsers — users
										// can still type values outside the
										// range, and `parseInt(val, 10) || 0`
										// preserves negatives (e.g. `-5` is
										// truthy). Clamp here so the UI
										// state, preview, and save payload
										// all agree on a valid value.
										const parsed = parseInt( val, 10 );
										const normalized = Number.isNaN(
											parsed
										)
											? 0
											: Math.min(
													365,
													Math.max( 0, parsed )
											  );
										handleField( 'days', normalized );
									} }
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
								{ /*
								   `__nextHasNoMarginBottom` strips WP's default
								   bottom margin on each CheckboxControl. Without
								   a replacement gap on the fieldset the three
								   options stack flush against each other (no
								   row breathing room). Flex-column + 6px gap
								   restores the visual rhythm and is more
								   deterministic than the legacy margin
								   collapsing-via-stylesheet pattern.
								*/ }
								<div
									style={ {
										display: 'flex',
										flexDirection: 'column',
										gap: '6px',
									} }
								>
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
								</div>
							</fieldset>
						</>
					) }

					{ policy.mode === POLICY_MODES.FINAL_SALE && (
						<div
							style={ {
								marginBottom: '16px',
								maxWidth: '480px',
							} }
						>
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
			</CardBody>
		</Card>
	);
};

/**
 * Top-level Policies tab component. Owns the local draft of all
 * policy sections, hydrates it from saved settings, fetches the
 * published-pages list once on mount, and orchestrates save +
 * feedback (success / error notice).
 *
 * Plugs into the same `useSelect(getSettings) /
 * useDispatch(updateSettingsValues, saveSettings)` flow as the other
 * tabs (Settings, Discovery, Overview) — the parent passes settings
 * + onChange + onSave + isSaving rather than the tab fetching its
 * own.
 *
 * @param {Object}   props
 * @param {Object}   props.settings Full plugin settings from the data store.
 * @param {Function} props.onChange Called with `(partialSettings)` to sync local edits to the store.
 * @param {Function} props.onSave   Called with no args; returns a promise that resolves on REST success.
 * @param {boolean}  props.isSaving Whether a save is in flight (drives Save button busy state).
 */
const PoliciesTab = ( { settings, onChange, onSave, isSaving } ) => {
	// Hydrate from saved settings, falling back to safe defaults.
	// Normalize sanitized server values into UI-friendly defaults.
	// PHP's sanitizer maps `days = 0` to `null` on persistence (the
	// "no window configured" sentinel — emission smart-degrades to
	// MerchantReturnUnspecified). The UI's NumberControl, however,
	// expects an integer; rendering `null` produces an empty input
	// even though the helper text says "Leave at 0 for no specific
	// window". Map `null` → `0` for the draft so the input always
	// reflects a concrete value the merchant can read and edit.
	const hydrate = ( returnPolicy ) => {
		const merged = { ...DEFAULT_POLICY, ...( returnPolicy || {} ) };
		if ( merged.days === null || merged.days === undefined ) {
			merged.days = 0;
		}
		return merged;
	};

	const initial = useMemo(
		() => hydrate( settings.return_policy ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);
	const [ draft, setDraft ] = useState( initial );

	// Reflect external setting changes (e.g. server-side migration on
	// reload) into the draft when the saved policy actually changes.
	// Preserves in-flight edits by comparing field-by-field rather than
	// stringifying — JSON.stringify is order-sensitive (a server response
	// that returns keys in a different order than the local draft would
	// look "different" even when semantically identical), and any future
	// schema addition would silently break the comparison until both
	// sides are updated. Explicit field compare is robust to both.
	useEffect( () => {
		if ( ! settings.return_policy ) {
			return;
		}
		setDraft( ( prev ) => {
			const merged = hydrate( settings.return_policy );
			const same =
				prev.mode === merged.mode &&
				prev.page_id === merged.page_id &&
				prev.days === merged.days &&
				prev.fees === merged.fees &&
				Array.isArray( prev.methods ) &&
				Array.isArray( merged.methods ) &&
				prev.methods.length === merged.methods.length &&
				prev.methods.every( ( m, i ) => m === merged.methods[ i ] );
			return same ? prev : merged;
		} );
	}, [ settings.return_policy ] );

	const [ pages, setPages ] = useState( [] );
	const [ pagesLoading, setPagesLoading ] = useState( true );
	const [ pagesError, setPagesError ] = useState( false );

	// Fetch the published-pages list for the policy-page dropdown.
	// `per_page=100` covers the common case (most stores have <100
	// published pages). Stores with more pages would risk silently
	// missing the merchant's saved selection, which would in turn
	// drop `merchantReturnLink` from the live preview while PHP
	// emission still includes it (drift between preview and output).
	// Defense: also fetch the saved `page_id` explicitly via
	// `include=` so the selected page is ALWAYS present in `pages`,
	// regardless of pagination position. The two fetches run in
	// parallel; results merge into a deduped list.
	const savedPageId = settings.return_policy?.page_id || 0;
	useEffect( () => {
		let cancelled = false;
		setPagesLoading( true );
		setPagesError( false );

		// Use `Promise.allSettled` so a partial failure (e.g. include
		// fetch fails, main list succeeds) doesn't lose all pages.
		// Tracking failure explicitly per-request lets us distinguish
		// "no published pages exist" (success with empty result) from
		// "pages endpoint failed" (network/auth/CORS error). Only the
		// former is benign; the latter shows the warning notice.
		// Use the plugin's own `/policy-pages` endpoint instead of
		// `/wp/v2/pages` so WC system pages (Cart, Checkout, My
		// Account, Shop) are excluded server-side via `wc_get_page_id()`.
		// That filter respects merchant-renamed system pages (slug
		// matching wouldn't), and centralises the inclusion rule so
		// the dropdown semantic is enforced on the server, not
		// client-side via keyword guessing.
		const requests = [
			apiFetch( {
				path: '/wc/v3/ai-storefront/admin/policy-pages',
			} ),
		];
		if ( savedPageId > 0 ) {
			// Best-effort recovery for the case where the saved page
			// has been moved to draft/trash since save: the main
			// `/policy-pages` fetch only returns published pages, so
			// the saved id may not appear in the list. The fallback
			// `/wp/v2/pages?include=...` resolves the title (gracefully
			// handles a since-unpublished page by returning empty —
			// the server-side emission gate already drops the link in
			// that case, and the dropdown agrees so the merchant
			// doesn't see a "selected" value that silently won't be
			// emitted).
			requests.push(
				apiFetch( {
					path: `/wp/v2/pages?include=${ savedPageId }&status=publish&_fields=id,title,link`,
				} )
			);
		}
		Promise.allSettled( requests ).then( ( results ) => {
			if ( cancelled ) {
				return;
			}
			// Main fetch (index 0) is the load-bearing request — its
			// failure indicates the pages endpoint is genuinely broken
			// for this merchant. The optional `include=` request (index
			// 1, if present) is best-effort: it covers the
			// >100-pages drift case but is not the canonical pages
			// list, so its failure is not user-facing.
			const mainFailed = results[ 0 ].status === 'rejected';
			const all = results.flatMap( ( r ) =>
				r.status === 'fulfilled' && Array.isArray( r.value )
					? r.value
					: []
			);
			// Dedupe by id; first occurrence wins.
			const seen = new Set();
			const deduped = [];
			for ( const p of all ) {
				if ( ! seen.has( p.id ) ) {
					seen.add( p.id );
					deduped.push( p );
				}
			}
			setPages( deduped );
			setPagesError( mainFailed );
			setPagesLoading( false );
		} );
		return () => {
			cancelled = true;
		};
	}, [ savedPageId ] );

	const handleSave = () => {
		// Save feedback (success + error) is owned by `saveSettings()`
		// in the data-store layer, which dispatches global
		// `core/notices` notices on both paths. The previous inline
		// "Settings saved." span and inline error Notice rendered
		// duplicate feedback for the same save action — dropped per
		// review feedback in favor of relying on the global notices.
		// `saveSettings` swallows rejections internally (catches and
		// dispatches an error notice rather than rethrowing), so no
		// `.catch` is needed here either — the promise always resolves.
		onChange( { return_policy: draft } );
		Promise.resolve( onSave() );
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
						"WooCommerce doesn't have a built-in setting for your return policy, but AI agents need it to confidently recommend your products. Set it once here.",
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
				onChange={ setDraft }
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
			</div>
		</div>
	);
};

export default PoliciesTab;
