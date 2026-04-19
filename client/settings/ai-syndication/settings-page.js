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
import { Icon, globe, shield, chartBar, check } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import ProductSelection from './product-selection';
import EndpointInfo from './endpoint-info';
import AIOrdersTable from './ai-orders-table';
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

// ValueCard renders one of the three icon-led value-proposition cards
// on the pre-enable landing. A 32px @wordpress/icons glyph at the top
// replaces the gray top-border accent the earlier version used — the
// icon IS the accent. Per the marketing+design review (combined
// recommendation documented in the PR that introduced this rewrite),
// titles are benefit-first and bodies are capped at ~22 words.
//
// The Icon component comes from @wordpress/icons which is in the WP
// extractor's BUNDLED_PACKAGES list, so it ships inside our build
// just like @wordpress/dataviews — no runtime dep on wc-admin.
const ValueCard = ( { icon, title, children } ) => (
	<div
		style={ {
			height: '100%',
			padding: '20px',
			background: colors.surface,
			border: `1px solid ${ colors.borderSubtle }`,
			borderRadius: '4px',
		} }
	>
		<div
			style={ {
				color: colors.success,
				marginBottom: '12px',
				// Inline-block so the icon doesn't stretch to the full
				// width of its flex parent in cards where the text is
				// shorter than the icon's computed width.
				display: 'inline-block',
			} }
			aria-hidden="true"
		>
			<Icon icon={ icon } size={ 32 } />
		</div>
		<h3
			style={ {
				margin: '0 0 8px',
				fontSize: '15px',
				fontWeight: '600',
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

// AssistantChip renders one of the five AI-assistant name chips in the
// hero block's right-hand column. Text-only (no logos) to avoid
// trademark entanglement and keep the dep graph clean — the name
// IS the visual signal.
const AssistantChip = ( { children } ) => (
	<span
		style={ {
			display: 'inline-flex',
			alignItems: 'center',
			padding: '8px 12px',
			background: colors.surfaceSubtle,
			borderRadius: '6px',
			fontSize: '13px',
			fontWeight: '500',
			color: colors.textPrimary,
		} }
	>
		{ children }
	</span>
);

// TrustItem renders one of the four reassurance items in the pre-enable
// trust strip. Small green check + short text, pairs kept compact so
// four items fit in one row on the constrained wp-admin width.
const TrustItem = ( { children } ) => (
	<span
		style={ {
			display: 'inline-flex',
			alignItems: 'center',
			gap: '6px',
			color: colors.textSecondary,
			fontSize: '13px',
		} }
	>
		<span
			style={ { color: colors.success, display: 'inline-flex' } }
			aria-hidden="true"
		>
			<Icon icon={ check } size={ 20 } />
		</span>
		{ children }
	</span>
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

	// Value + optional subvalue render on ONE baseline-aligned row.
	// Stacking the subvalue below the value (pre-fix) made the AI
	// Orders card visually taller than the three subvalue-less
	// cards next to it — the big number's vertical center shifted
	// up, breaking the four-across row alignment the merchant reads
	// at a glance. Inlining with `align-items: baseline` keeps the
	// "1" and "10% of total" sharing the same type baseline, so
	// every card's big number sits at the same y-coordinate.
	const inner = (
		<>
			<div
				style={ {
					display: 'flex',
					justifyContent: 'center',
					alignItems: 'baseline',
					gap: '6px',
				} }
			>
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
							fontWeight: '400',
						} }
					>
						{ subvalue }
					</div>
				) }
			</div>
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
		{ /* --------------------------------------------------------- */ }
		{ /* Block 1: Hero — benefit-led headline + single primary CTA */ }
		{ /* + assistant-name chips carrying the visual weight on the */ }
		{ /* right. Replaces the prior "status banner" + redundant     */ }
		{ /* bottom card. Single source of conversion intent; the user */ }
		{ /* shouldn't have to scroll to find "what does this do and   */ }
		{ /* how do I turn it on." The 4px success-green top border is */ }
		{ /* the only place green appears above the fold — scarcity    */ }
		{ /* makes it a focal point rather than noise.                 */ }
		{ /* --------------------------------------------------------- */ }
		<Card
			style={ {
				borderTop: `4px solid ${ colors.success }`,
			} }
		>
			<CardBody>
				<Flex align="center" gap={ 6 } wrap>
					<FlexItem isBlock>
						<p
							style={ {
								margin: '0 0 8px',
								fontSize: '11px',
								fontWeight: '600',
								textTransform: 'uppercase',
								letterSpacing: '0.8px',
								color: colors.textMuted,
							} }
						>
							{ __(
								'Status: Not enabled',
								'woocommerce-ai-syndication'
							) }
						</p>
						<h2
							style={ {
								margin: '0 0 8px',
								fontSize: '22px',
								lineHeight: '1.3',
								fontWeight: '600',
								color: colors.textPrimary,
							} }
						>
							{ __(
								'Make your products discoverable by AI shopping assistants',
								'woocommerce-ai-syndication'
							) }
						</h2>
						<p
							style={ {
								margin: '0 0 20px',
								fontSize: '14px',
								lineHeight: '1.5',
								color: colors.textSecondary,
							} }
						>
							{ __(
								'Turn on discovery in one click. Checkout stays on your store — no fees, no middleman, fully reversible.',
								'woocommerce-ai-syndication'
							) }
						</p>
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
								? __(
										'Enabling…',
										'woocommerce-ai-syndication'
								  )
								: __(
										'Enable AI Syndication',
										'woocommerce-ai-syndication'
								  ) }
						</Button>
					</FlexItem>
					<FlexItem isBlock>
						{ /* Right column: assistant-name chips in a
						    2-column grid. Concrete names (not an
						    abstract "all AI agents") convert better
						    per the marketing review. Text-only chips
						    sidestep trademark / logo licensing and
						    stay design-system-native. */ }
						<div
							style={ {
								display: 'grid',
								gridTemplateColumns: 'repeat(2, 1fr)',
								gap: '8px',
							} }
						>
							<AssistantChip>ChatGPT</AssistantChip>
							<AssistantChip>Gemini</AssistantChip>
							<AssistantChip>Claude</AssistantChip>
							<AssistantChip>Perplexity</AssistantChip>
							<AssistantChip>Copilot</AssistantChip>
						</div>
					</FlexItem>
				</Flex>
			</CardBody>
		</Card>

		{ /* --------------------------------------------------------- */ }
		{ /* Block 2: Three icon-led value-prop cards. Per design      */ }
		{ /* review: icons replace the prior gray top-border accent —  */ }
		{ /* the icon IS the accent. Titles are benefit-first and      */ }
		{ /* bodies capped near 22 words each.                          */ }
		{ /* --------------------------------------------------------- */ }
		<Flex gap={ 4 } wrap align="stretch" style={ { marginTop: '32px' } }>
			<FlexItem isBlock>
				<ValueCard
					icon={ globe }
					title={ __(
						'One setup, every AI assistant',
						'woocommerce-ai-syndication'
					) }
				>
					{ __(
						'Your catalog becomes visible to ChatGPT, Gemini, Claude, Perplexity, and Copilot — with no per-platform work when new agents launch.',
						'woocommerce-ai-syndication'
					) }
				</ValueCard>
			</FlexItem>
			<FlexItem isBlock>
				<ValueCard
					icon={ shield }
					title={ __(
						'Checkout stays on your store',
						'woocommerce-ai-syndication'
					) }
				>
					{ __(
						'No platform fees. No delegated payments. You keep the customer, the checkout, and the data.',
						'woocommerce-ai-syndication'
					) }
				</ValueCard>
			</FlexItem>
			<FlexItem isBlock>
				<ValueCard
					icon={ chartBar }
					title={ __(
						'See which AI drove each sale',
						'woocommerce-ai-syndication'
					) }
				>
					{ __(
						'Every AI-referred order is tagged with its source agent and revenue — using standard WooCommerce Order Attribution.',
						'woocommerce-ai-syndication'
					) }
				</ValueCard>
			</FlexItem>
		</Flex>

		{ /* --------------------------------------------------------- */ }
		{ /* Block 3: Compact trust strip. Replaces the entire prior   */ }
		{ /* bottom card (headline + paragraph + bullet list + CTA).   */ }
		{ /* Four reassurance items with green check icons, one row,   */ }
		{ /* muted background — gives the page a clean footer without  */ }
		{ /* restating value props that already appear above.          */ }
		{ /* --------------------------------------------------------- */ }
		<div
			style={ {
				marginTop: '32px',
				padding: '16px 20px',
				background: colors.surfaceSubtle,
				borderRadius: '4px',
			} }
		>
			<Flex gap={ 5 } wrap>
				<TrustItem>
					{ __(
						'Read-only — no store data changed',
						'woocommerce-ai-syndication'
					) }
				</TrustItem>
				<TrustItem>
					{ __( 'Reversible anytime', 'woocommerce-ai-syndication' ) }
				</TrustItem>
				<TrustItem>
					{ __(
						'No frontend changes',
						'woocommerce-ai-syndication'
					) }
				</TrustItem>
				<TrustItem>
					{ __(
						'Works with existing products',
						'woocommerce-ai-syndication'
					) }
				</TrustItem>
			</Flex>
		</div>
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

			{ /*
				Recent AI-attributed orders. One row per order (not
				per agent) — the per-agent aggregate is already
				conveyed by the stat cards above. See
				ai-orders-table.js for why this uses
				@wordpress/dataviews rather than @woocommerce/components.
			*/ }
			<AIOrdersTable />
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
