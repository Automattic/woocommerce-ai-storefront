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
	BaseControl,
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';
import { colors, radii, shadows, spacing, typography } from './tokens';
import { TabInputStyles } from './tab-input-styles';

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

const DEFAULT_HANDLING_TIME = { min: 0, max: 0 };

/**
 * Segmented control for policy mode selection.
 *
 * Plain buttons styled to match the spec's `.seg-control` / `.seg-option`
 * pattern: light-fill track, white elevated pill on the active option.
 * This is CONFIGURATION (the choice drives conditional fields), not a
 * data filter — hence segmented control, not chips.
 */
const SEG_CONTROL_CLASS = 'ai-storefront-seg-control';
const POLICIES_TAB_CLASS = 'ai-storefront-policies-tab';

/**
 * Policies-tab-specific styles. The shared 32px input-height override
 * is provided by `TabInputStyles`; this component owns the segmented-
 * control chrome and the return-window webkit spin-button suppression.
 */
function PoliciesTabStyles() {
	return (
		<style>{ `
			.${ SEG_CONTROL_CLASS } {
				display: inline-flex;
				background: ${ colors.surfaceMuted };
				border-radius: ${ radii.md };
				padding: 2px;
				gap: 0;
			}
			.${ SEG_CONTROL_CLASS } button {
				background: transparent;
				border: 1px solid transparent;
				padding: 6px 14px;
				border-radius: calc(${ radii.md } - 2px);
				font-size: 13px;
				font-weight: 400;
				line-height: 1;
				color: ${ colors.textSecondary };
				cursor: pointer;
				white-space: nowrap;
			}
			.${ SEG_CONTROL_CLASS } button:hover {
				color: ${ colors.textPrimary };
			}
			.${ SEG_CONTROL_CLASS } button[aria-pressed="true"] {
				background: ${ colors.surface };
				color: ${ colors.textPrimary };
				font-weight: 600;
				box-shadow: ${ shadows.sm };
			}
			.${ SEG_CONTROL_CLASS } button:focus-visible {
				outline: 2px solid var(--wp-admin-theme-color, #2271b1);
				outline-offset: 2px;
			}
			@media (forced-colors: active) {
				.${ SEG_CONTROL_CLASS } button[aria-pressed="true"] {
					outline: 1px solid CanvasText;
				}
			}
			#wc-ai-storefront-return-window::-webkit-outer-spin-button,
			#wc-ai-storefront-return-window::-webkit-inner-spin-button,
			#wc-ai-storefront-handling-min::-webkit-outer-spin-button,
			#wc-ai-storefront-handling-min::-webkit-inner-spin-button,
			#wc-ai-storefront-handling-max::-webkit-outer-spin-button,
			#wc-ai-storefront-handling-max::-webkit-inner-spin-button {
				-webkit-appearance: none;
				margin: 0;
			}
		` }</style>
	);
}

const SegmentedControl = ( {
	value,
	onChange: onChangeProp,
	options,
	label,
} ) => (
	<div className={ SEG_CONTROL_CLASS } role="group" aria-label={ label }>
		{ options.map( ( opt ) => (
			<button
				key={ opt.value }
				type="button"
				aria-pressed={ value === opt.value }
				onClick={ () => onChangeProp( opt.value ) }
			>
				{ opt.label }
			</button>
		) ) }
	</div>
);

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
 * Pure helper: derive the JSON-LD `ShippingDeliveryTime.handlingTime` block
 * from a draft handling-time state. Mirrors the server-side
 * `WC_AI_Storefront_JsonLd::add_handling_time()` emission contract.
 *
 * Returns null when either value is 0 (unconfigured) or min > max (invalid
 * pair) — mirroring the PHP emitter's full guard:
 * `if ( $min <= 0 || $max <= 0 || $min > $max ) return;`.
 *
 * @param {Object} handlingTime Draft handling-time state `{ min, max }`.
 * @return {Object|null} handlingTime QuantitativeValue block, or null.
 */
export const deriveHandlingTimePreview = ( handlingTime ) => {
	const min = Math.trunc( Number( handlingTime?.min ) ) || 0;
	const max = Math.trunc( Number( handlingTime?.max ) ) || 0;
	if ( min <= 0 || max <= 0 || min > max ) {
		return null;
	}
	return {
		'@type': 'QuantitativeValue',
		minValue: min,
		maxValue: max,
		unitCode: 'DAY',
	};
};

/**
 * A stepper input (− / text / +) for integer values in the 0–365 range.
 *
 * @param {Object}   props
 * @param {string}   props.id        HTML id for the input.
 * @param {number}   props.value     Current integer value.
 * @param {Function} props.onChange  Called with the new integer value.
 * @param {number}   [props.min=0]   Minimum clamped value.
 * @param {number}   [props.max=365] Maximum clamped value.
 */
const StepperInput = ( { id, value, onChange, min = 0, max = 365 } ) => (
	<div
		style={ {
			display: 'inline-flex',
			alignItems: 'stretch',
			width: '120px',
			height: '32px',
			border: `1px solid ${ colors.borderStrong }`,
			borderRadius: radii.sm,
			background: colors.surface,
			overflow: 'hidden',
		} }
	>
		<button
			type="button"
			aria-label={ __( 'Decrease', 'woocommerce-ai-storefront' ) }
			onClick={ () => onChange( Math.max( min, value - 1 ) ) }
			style={ {
				width: '28px',
				flexShrink: 0,
				background: colors.surfaceSubtle,
				border: 'none',
				borderRight: `1px solid ${ colors.borderSubtle }`,
				color: colors.textPrimary,
				fontSize: '16px',
				fontWeight: 600,
				lineHeight: 1,
				cursor: 'pointer',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
			} }
		>
			{ '−' }
		</button>
		<input
			type="number"
			id={ id }
			min={ min }
			max={ max }
			value={ value }
			onChange={ ( e ) => {
				const parsed = parseInt( e.target.value, 10 );
				const normalized = Number.isNaN( parsed )
					? min
					: Math.min( max, Math.max( min, parsed ) );
				onChange( normalized );
			} }
			style={ {
				flex: 1,
				minWidth: 0,
				border: 'none',
				padding: '0 4px',
				fontSize: '13px',
				textAlign: 'center',
				background: 'transparent',
				color: colors.textPrimary,
				MozAppearance: 'textfield',
			} }
		/>
		<button
			type="button"
			aria-label={ __( 'Increase', 'woocommerce-ai-storefront' ) }
			onClick={ () => onChange( Math.min( max, value + 1 ) ) }
			style={ {
				width: '28px',
				flexShrink: 0,
				background: colors.surfaceSubtle,
				border: 'none',
				borderLeft: `1px solid ${ colors.borderSubtle }`,
				color: colors.textPrimary,
				fontSize: '16px',
				fontWeight: 600,
				lineHeight: 1,
				cursor: 'pointer',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
			} }
		>
			{ '+' }
		</button>
	</div>
);

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
			{ /*
			   Card-head divider is reserved for cards that carry
			   action chrome (toolbar affordances, filter dropdowns).
			   This card has only a title + describing paragraph, so
			   the title and its paragraph read as one continuous unit
			   with no divider between them. The h3 ("card-title")
			   lives inside CardBody.

			   See: "Card-head divider is for action chrome only"
			   in docs/design/settings-redesign-final.html.
			*/ }
			<CardBody>
				<h3
					style={ {
						margin: '0 0 8px',
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

				<SegmentedControl
					label={ __( 'Policy mode', 'woocommerce-ai-storefront' ) }
					value={ policy.mode }
					onChange={ ( val ) => handleField( 'mode', val ) }
					options={ [
						{
							value: POLICY_MODES.RETURNS_ACCEPTED,
							label: __(
								'Returns accepted',
								'woocommerce-ai-storefront'
							),
						},
						{
							value: POLICY_MODES.FINAL_SALE,
							label: __(
								'No returns',
								'woocommerce-ai-storefront'
							),
						},
						{
							value: POLICY_MODES.UNCONFIGURED,
							label: __(
								"Don't expose",
								'woocommerce-ai-storefront'
							),
						},
					] }
				/>

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
							{ /*
								Field order: Policy page → Return fees →
								Return window → Return methods. The two
								Select dropdowns sit adjacent at the top
								as a "what is your policy" pair, sharing
								the same 320px width so the eye tracks
								down the column rather than zigzagging.
								Return window is a numeric detail that
								follows the policy choice (96px input);
								Return methods is a multi-select that
								closes the section.

								Width rule:
								- Select dropdowns: 320px. Comfortably
								  fits the longest fee label
								  ("Customer pays return fees", 24
								  chars) and matches the WordPress core
								  settings-field rhythm. 480px (the
								  prior value) was wider than any
								  option content needed.
								- Number input: 96px on the input
								  itself; the BaseControl wrapper spans
								  the panel so its uppercase tracked
								  label "RETURN WINDOW (DAYS)" and
								  helper text don't truncate or wrap
								  to 4 narrow lines under a 120px
								  field. NumberControl's built-in
								  label/help props are deliberately
								  unused here — they would inherit the
								  96px input width.
							*/ }
							<div
								style={ {
									marginBottom: spacing.s4,
									maxWidth: '320px',
								} }
							>
								<BaseControl
									__nextHasNoMarginBottom
									id="wc-ai-storefront-policy-page"
									help={ __(
										'Link AI agents to a full-text policy page on your store.',
										'woocommerce-ai-storefront'
									) }
								>
									<BaseControl.VisualLabel
										style={ {
											...typography.eyebrowLabel,
											color: colors.textSecondary,
										} }
									>
										{ __(
											'Policy page (optional)',
											'woocommerce-ai-storefront'
										) }
									</BaseControl.VisualLabel>
									{ pagesLoading ? (
										<Spinner />
									) : (
										<SelectControl
											__nextHasNoMarginBottom
											id="wc-ai-storefront-policy-page"
											hideLabelFromVision
											label={ __(
												'Policy page (optional)',
												'woocommerce-ai-storefront'
											) }
											value={ String( policy.page_id ) }
											options={ pageOptions.map(
												( o ) => ( {
													...o,
													value: String( o.value ),
												} )
											) }
											onChange={ ( val ) =>
												handleField(
													'page_id',
													parseInt( val, 10 ) || 0
												)
											}
										/>
									) }
								</BaseControl>
							</div>

							<div
								style={ {
									marginBottom: spacing.s4,
									maxWidth: '320px',
								} }
							>
								<BaseControl
									__nextHasNoMarginBottom
									id="wc-ai-storefront-return-fees"
									help={ __(
										'Applied as the default for all returns. You can override this per product on the Product edit screen.',
										'woocommerce-ai-storefront'
									) }
								>
									<BaseControl.VisualLabel
										style={ {
											...typography.eyebrowLabel,
											color: colors.textSecondary,
										} }
									>
										{ __(
											'Return fees',
											'woocommerce-ai-storefront'
										) }
									</BaseControl.VisualLabel>
									<SelectControl
										__nextHasNoMarginBottom
										id="wc-ai-storefront-return-fees"
										hideLabelFromVision
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
								</BaseControl>
							</div>

							<div style={ { marginBottom: spacing.s4 } }>
								<label
									htmlFor="wc-ai-storefront-return-window"
									style={ {
										display: 'block',
										marginBottom: spacing.s1,
										...typography.eyebrowLabel,
										color: colors.textSecondary,
									} }
								>
									{ __(
										'Return window (days)',
										'woocommerce-ai-storefront'
									) }
								</label>
								<StepperInput
									id="wc-ai-storefront-return-window"
									value={ policy.days ?? 0 }
									onChange={ ( v ) =>
										handleField( 'days', v )
									}
								/>
								<p
									style={ {
										margin: `${ spacing.s1 } 0 0`,
										fontSize: '12px',
										color: colors.textMuted,
									} }
								>
									{ __(
										'Leave at 0 to publish "Unspecified" instead of a finite window.',
										'woocommerce-ai-storefront'
									) }
								</p>
							</div>

							{ /*
								Return methods: a CheckboxControl
								*group* labeled with the same uppercase
								tracked treatment as the three form
								fields above. We use
								`<BaseControl.VisualLabel>` rather than
								the BaseControl `label` prop because:

								- BaseControl's `label` prop renders a
								  `<label htmlFor={id}>` which expects
								  the `id` to belong to a single
								  labelable form control (input,
								  select, textarea). A `<div>` with
								  `role="group"` is NOT labelable, so
								  pointing `htmlFor` at it is invalid
								  HTML.
								- VisualLabel renders a plain `<span
								  class="components-base-control__label">`
								  with the same 11px / 500 / uppercase
								  styling, AND accepts an explicit
								  `id` we can target with the group
								  div's `aria-labelledby` for screen-
								  reader association.

								Per-checkbox `<label>` elements still
								provide keyboard / screen-reader
								association for each individual option;
								`role="group"` + `aria-labelledby`
								gives the cluster a single accessible
								name ("Return methods") that wraps the
								three options.

								`__nextHasNoMarginBottom` on each
								CheckboxControl strips WP's default
								bottom margin. Without a replacement
								gap the three options stack flush;
								flex-column + 6px gap restores
								breathing room.
							*/ }
							<BaseControl __nextHasNoMarginBottom>
								<BaseControl.VisualLabel
									id="wc-ai-storefront-return-methods-label"
									style={ {
										...typography.eyebrowLabel,
										color: colors.textSecondary,
									} }
								>
									{ __(
										'Return methods',
										'woocommerce-ai-storefront'
									) }
								</BaseControl.VisualLabel>
								<div
									role="group"
									aria-labelledby="wc-ai-storefront-return-methods-label"
									style={ {
										display: 'flex',
										flexDirection: 'column',
										gap: spacing.s1,
										marginTop: spacing.s2,
									} }
								>
									{ METHOD_OPTIONS.map( ( opt ) => (
										// eslint-disable-next-line jsx-a11y/label-has-associated-control -- Input is nested inside the label.
										<label
											key={ opt.value }
											style={ {
												display: 'flex',
												alignItems: 'center',
												gap: spacing.s2,
												paddingTop: '4px',
												paddingBottom: '4px',
												minHeight: '24px',
												fontSize: '13px',
												color: colors.textPrimary,
												cursor: 'pointer',
											} }
										>
											<input
												type="checkbox"
												checked={ (
													policy.methods || []
												).includes( opt.value ) }
												onChange={ ( e ) =>
													handleMethodToggle(
														opt.value,
														e.target.checked
													)
												}
												style={ {
													width: '16px',
													height: '16px',
													margin: 0,
													flexShrink: 0,
													cursor: 'pointer',
													accentColor: colors.accent,
												} }
											/>
											{ opt.label }
										</label>
									) ) }
								</div>
							</BaseControl>
						</>
					) }

					{ policy.mode === POLICY_MODES.FINAL_SALE && (
						<div
							style={ {
								marginBottom: spacing.s4,
								maxWidth: '320px',
							} }
						>
							<BaseControl
								__nextHasNoMarginBottom
								id="wc-ai-storefront-policy-page-final"
								help={ __(
									'Link AI agents to a "no returns" explainer on your store.',
									'woocommerce-ai-storefront'
								) }
							>
								<BaseControl.VisualLabel
									style={ {
										...typography.eyebrowLabel,
										color: colors.textSecondary,
									} }
								>
									{ __(
										'Policy page (optional)',
										'woocommerce-ai-storefront'
									) }
								</BaseControl.VisualLabel>
								{ pagesLoading ? (
									<Spinner />
								) : (
									<SelectControl
										__nextHasNoMarginBottom
										id="wc-ai-storefront-policy-page-final"
										hideLabelFromVision
										label={ __(
											'Policy page (optional)',
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
							</BaseControl>
						</div>
					) }
				</div>
			</CardBody>
		</Card>
	);
};

/**
 * Apply a new min value, bumping max up to match if max would fall below.
 *
 * Mirrors PHP `WC_AI_Storefront_Handling_Time::sanitize()` direction:
 * max is always raised to meet min, never lowered.
 *
 * @param {{ min: number, max: number }} current
 * @param {number}                       val
 * @return {{ min: number, max: number }} Updated handling-time pair.
 */
export const applyHandlingTimeMin = ( current, val ) => {
	const next = { ...current, min: val };
	if ( next.max > 0 && next.max < val ) {
		next.max = val;
	}
	return next;
};

/**
 * Apply a new max value, bumping max up to min when the entered value
 * falls below the current min.
 *
 * Mirrors PHP `WC_AI_Storefront_Handling_Time::sanitize()` direction:
 * max is raised to min, never min lowered to max.
 *
 * @param {{ min: number, max: number }} current
 * @param {number}                       val
 * @return {{ min: number, max: number }} Updated handling-time pair.
 */
export const applyHandlingTimeMax = ( current, val ) => {
	const next = { ...current, max: val };
	if ( next.min > 0 && val > 0 && val < next.min ) {
		next.max = next.min;
	}
	return next;
};

/**
 * Checkout policies section — agent-facing capabilities that take
 * effect at the `/checkout-sessions` boundary. Currently single-field
 * (`allow_discount_codes`); add siblings here for future checkout
 * extensions rather than scattering top-level toggles. (#376)
 *
 * Server-side, the discount capability is gated by BOTH this toggle
 * AND WC's core `wc_coupons_enabled()` setting — if WC coupons are
 * disabled in `WooCommerce → Settings → General`, the capability is
 * never advertised regardless of this checkbox. The helper text below
 * surfaces that dependency to the merchant; we don't grey out the
 * checkbox itself (would require exposing WC's setting state through
 * the admin REST surface, which is more wiring than the contract
 * warrants for v0.14.2).
 *
 * @param {Object}   props
 * @param {string}   props.allowDiscountCodes Current value (`'yes'` / `'no'`).
 * @param {Function} props.onChange           Called with the new yes/no string when the toggle flips.
 */
const CheckoutPoliciesSection = ( { allowDiscountCodes, onChange } ) => {
	return (
		<Card>
			<CardBody>
				<h3
					style={ {
						margin: '0 0 8px',
						fontSize: '14px',
						fontWeight: 600,
						color: colors.textPrimary,
					} }
				>
					{ __( 'Checkout', 'woocommerce-ai-storefront' ) }
				</h3>
				<p
					style={ {
						margin: '0 0 16px',
						color: colors.textSecondary,
						fontSize: '13px',
					} }
				>
					{ __(
						'AI agents can supply user-entered discount codes when sending shoppers to checkout. Requires WooCommerce coupons to be enabled in WooCommerce → Settings → General.',
						'woocommerce-ai-storefront'
					) }
				</p>
				<CheckboxControl
					label={ __(
						'Allow discount codes through AI agents',
						'woocommerce-ai-storefront'
					) }
					checked={ allowDiscountCodes === 'yes' }
					onChange={ ( checked ) =>
						onChange( checked ? 'yes' : 'no' )
					}
					__nextHasNoMarginBottom
				/>
			</CardBody>
		</Card>
	);
};

const ShippingPoliciesSection = ( { handlingTime, onChange } ) => {
	const handleMin = ( val ) =>
		onChange( applyHandlingTimeMin( handlingTime, val ) );
	const handleMax = ( val ) =>
		onChange( applyHandlingTimeMax( handlingTime, val ) );

	return (
		<Card>
			<CardBody>
				<h3
					style={ {
						margin: '0 0 8px',
						fontSize: '14px',
						fontWeight: 600,
						color: colors.textPrimary,
					} }
				>
					{ __( 'Shipping', 'woocommerce-ai-storefront' ) }
				</h3>
				<p
					style={ {
						margin: '0 0 16px',
						color: colors.textSecondary,
						fontSize: '13px',
					} }
				>
					{ __(
						'Set your typical order handling time so AI agents can quote an accurate fulfilment window before checkout.',
						'woocommerce-ai-storefront'
					) }
				</p>

				<div
					style={ {
						display: 'flex',
						flexWrap: 'wrap',
						gap: spacing.s4,
						alignItems: 'flex-end',
					} }
				>
					<div>
						<label
							htmlFor="wc-ai-storefront-handling-min"
							style={ {
								display: 'block',
								marginBottom: spacing.s1,
								...typography.eyebrowLabel,
								color: colors.textSecondary,
							} }
						>
							{ __(
								'Min handling time (days)',
								'woocommerce-ai-storefront'
							) }
						</label>
						<StepperInput
							id="wc-ai-storefront-handling-min"
							value={ handlingTime.min }
							onChange={ handleMin }
						/>
					</div>
					<div>
						<label
							htmlFor="wc-ai-storefront-handling-max"
							style={ {
								display: 'block',
								marginBottom: spacing.s1,
								...typography.eyebrowLabel,
								color: colors.textSecondary,
							} }
						>
							{ __(
								'Max handling time (days)',
								'woocommerce-ai-storefront'
							) }
						</label>
						<StepperInput
							id="wc-ai-storefront-handling-max"
							value={ handlingTime.max }
							onChange={ handleMax }
						/>
					</div>
				</div>
				<p
					style={ {
						margin: `${ spacing.s2 } 0 0`,
						fontSize: '12px',
						color: colors.textMuted,
					} }
				>
					{ __(
						'Leave both at 0 to omit handling time from structured data.',
						'woocommerce-ai-storefront'
					) }
				</p>
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

	// Handling-time draft — mirrors the return-policy draft pattern.
	const initialHandlingTime = useMemo(
		() => ( {
			...DEFAULT_HANDLING_TIME,
			...( settings.handling_time || {} ),
		} ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);
	const [ handlingDraft, setHandlingDraft ] = useState( initialHandlingTime );

	useEffect( () => {
		if ( ! settings.handling_time ) {
			return;
		}
		setHandlingDraft( ( prev ) => {
			const next = {
				...DEFAULT_HANDLING_TIME,
				...settings.handling_time,
			};
			if ( prev.min === next.min && prev.max === next.max ) {
				return prev;
			}
			return next;
		} );
	}, [ settings.handling_time ] );

	const handleHandlingTimeEdit = useCallback(
		( nextHandlingTime ) => {
			setHandlingDraft( nextHandlingTime );
			onChange( { handling_time: nextHandlingTime } );
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
		<div className={ POLICIES_TAB_CLASS }>
			<TabInputStyles tabClass={ POLICIES_TAB_CLASS } />
			<PoliciesTabStyles />
			<header style={ { marginBottom: '20px' } }>
				{ /*
				   Section h2 names the operator's job at a higher
				   altitude than the card title below ("Return &
				   refund policy"). They do not paraphrase each other.
				   "Store policies" pairs thematically with the
				   Discovery tab's "AI agent access" section h2 — both
				   read as merchant-side responsibilities, not agent
				   behaviors.
				*/ }
				<h2
					style={ {
						margin: '0 0 4px',
						...typography.sectionHeading,
						color: colors.textPrimary,
					} }
				>
					{ __( 'Store policies', 'woocommerce-ai-storefront' ) }
				</h2>
				<p
					style={ {
						margin: 0,
						color: colors.textSecondary,
						fontSize: '13px',
					} }
				>
					{ __(
						'Policies AI agents can quote to shoppers before checkout.',
						'woocommerce-ai-storefront'
					) }
				</p>
			</header>

			{ pagesError && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						"Could not load your pages. Page links won't be available.",
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

			<div style={ { marginTop: '16px' } }>
				<ShippingPoliciesSection
					handlingTime={ handlingDraft }
					onChange={ handleHandlingTimeEdit }
				/>
			</div>

			<div style={ { marginTop: '16px' } }>
				<CheckoutPoliciesSection
					allowDiscountCodes={
						settings.allow_discount_codes ?? 'yes'
					}
					onChange={ ( next ) =>
						onChange( { allow_discount_codes: next } )
					}
				/>
			</div>

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
					// right edge in LTR, left edge in RTL (Arabic,
					// Hebrew, Persian, Urdu). The Discovery and Product
					// Visibility footers carry the same value via PR
					// #103. Note the cross-PR coordination: until both
					// land, this tab is RTL-correct while the others
					// temporarily aren't. Reviewers seeing the Discovery
					// or Product Visibility footers still on `'right'`
					// in this PR's diff base are seeing the pre-#103
					// state — that's expected, not a regression.
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
