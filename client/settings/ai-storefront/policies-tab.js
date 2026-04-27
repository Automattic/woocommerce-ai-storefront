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

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
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

							{ /*
								Width: 120px. Bounded numeric (0–365)
								renders within ~3 digit slots — a
								wider field would lie about how much
								input is expected and create a ragged
								right edge against the 480px dropdowns
								above and below. Designer-validated
								"field width = expected content
								magnitude" pattern; matches the
								WooCommerce convention for short
								numeric inputs.
							*/ }
							<div
								style={ {
									marginBottom: '16px',
									maxWidth: '120px',
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

							{ /*
								Width: 480px to match the Policy page
								dropdown above. The two SelectControls
								form a vertical "policy attributes"
								column — uniform width keeps the eye
								tracking down the column rather than
								zigzagging between widths. NumberControl
								between them deliberately diverges
								(120px) because numeric magnitude is a
								different signal than dropdown choice.
							*/ }
							<div
								style={ {
									marginBottom: '16px',
									maxWidth: '480px',
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
 * @param {boolean}  props.isDirty  Whether the merchant has unsaved changes (disables Save when false).
 */
const PoliciesTab = ( { settings, onChange, onSave, isSaving, isDirty } ) => {
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

	// Bubble user edits up to the store as they happen. Without this,
	// the local `draft` state diverges from `state.settings.return_policy`
	// until `handleSave()` runs — which means the global `isDirty`
	// selector reads clean (settings.return_policy still equals
	// savedSettings.return_policy) and the dirty-aware Save button stays
	// disabled, locking the merchant out of saving their edits.
	//
	// The hydration `useEffect` above intentionally uses raw `setDraft`
	// (no store propagation) so initial mount + server-side migration
	// reflows don't falsely mark the form as dirty before the merchant
	// has touched anything. Only this user-edit path bubbles to the
	// store. No automated regression test today — the test harness for
	// this file (`policies-tab.test.js`) covers `derivePreview` only,
	// not the React component. Manual verify: edit any field on the
	// Policies tab → Save button enables; revert the edit → Save
	// button disables.
	const handleUserEdit = useCallback(
		( nextPolicy ) => {
			setDraft( nextPolicy );
			onChange( { return_policy: nextPolicy } );
		},
		[ onChange ]
	);

	const [ pages, setPages ] = useState( [] );
	const [ pagesLoading, setPagesLoading ] = useState( true );
	const [ pagesError, setPagesError ] = useState( false );

	// Fetch the page list for the policy-page dropdown.
	//
	// Main fetch: the plugin's own `/policy-pages` endpoint. It
	// returns only published pages and excludes WC system pages
	// (Cart, Checkout, My Account, Shop) server-side via
	// `wc_get_page_id()`. Doing the system-page filter on the server
	// means it survives merchant renames (slug matching client-side
	// wouldn't) and keeps the inclusion rule in one place.
	//
	// Optional second fetch: when a `page_id` is already saved in
	// settings, also resolve that id by `include=` against
	// `/wp/v2/pages`. This recovers the case where the saved page
	// has since been moved to draft or trash — `/policy-pages`
	// would no longer include it, so the dropdown would render the
	// stored id as "blank" and the merchant would have no signal
	// that their previous selection is now invisible. The fallback
	// fetch surfaces the title so the dropdown can show the saved
	// row even when it's no longer published. Server-side emission
	// already gates `merchantReturnLink` on a published page, so a
	// since-unpublished id silently drops from JSON-LD; the
	// dropdown row is just a "you previously picked this, here's
	// its name" affordance.
	const savedPageId = settings.return_policy?.page_id || 0;
	useEffect( () => {
		let cancelled = false;
		setPagesLoading( true );
		setPagesError( false );

		// `Promise.allSettled` so a failure of the optional `include=`
		// fetch doesn't tank the main list. We distinguish "no
		// published pages exist" (main resolves with []) from "pages
		// endpoint broke" (main rejects) — only the latter shows the
		// merchant the warning notice.
		const requests = [
			apiFetch( {
				path: '/wc/v3/ai-storefront/admin/policy-pages',
			} ),
		];
		if ( savedPageId > 0 ) {
			// Recover the saved id when it's a WC system page that
			// `/policy-pages` excludes. Stores that selected a system
			// page (e.g. Cart, Checkout) as their refund-policy link
			// before the server-side `wc_get_page_id()` exclusion was
			// added still have that id stored in settings — without
			// this fallback the dropdown would render the saved row as
			// blank "selected" because `/policy-pages` filters it out.
			// `?include=` resolves any published page by id regardless
			// of the system-page filter, so the title comes back.
			//
			// Draft / trash / private pages stay invisible by design
			// (`status=publish`): an unpublished saved page is
			// intentionally hidden, and the server-side JSON-LD gate
			// already drops `merchantReturnLink` for non-published
			// pages — so a stale dropdown value wouldn't ship anyway.
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
			// for this merchant. The optional `include=` request
			// (index 1, if present) is best-effort: it only adds a
			// system-page row to the dropdown when the merchant
			// previously saved one, so its failure is not user-facing.
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
		//
		// No `onChange( { return_policy: draft } )` call here — every
		// user edit already routed through `handleUserEdit` which
		// bubbled the new policy up to the store synchronously, so the
		// store's draft is already current. A pre-save sync would just
		// dispatch a redundant action.
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
				onChange={ handleUserEdit }
				pages={ pages }
				pagesLoading={ pagesLoading }
			/>

			{ /*
				Page-level Save footer. Right-aligned + 24px top margin
				to match the Discovery (Endpoint Info) and Product
				Visibility tabs. The button is dirty-aware: disabled
				when `isDirty` is false, even if the merchant clicks
				rapidly during a save (`isSaving` keeps it disabled
				through the in-flight window). Mirrors WC Settings +
				Block Editor's pattern.
			*/ }
			<div
				style={ {
					marginTop: '24px',
					// `'end'` (logical) instead of `'right'` (physical)
					// so the Save button respects writing direction —
					// right edge in LTR, left edge in RTL. Matches the
					// fix landing concurrently for the Discovery and
					// Product Visibility footers (PR #103).
					textAlign: 'end',
				} }
			>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving || ! isDirty }
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
