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
import { Icon, globe, shield, chartBar } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { STORE_NAME } from '../../data/ai-storefront/constants';
import ProductSelection from './product-selection';
import EndpointInfo from './endpoint-info';
import AIOrdersTable from './ai-orders-table';
import PoliciesTab from './policies-tab';
import { colors, typography } from './tokens';

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
	// Dirty-aware Save: each tab's footer disables its Save button
	// when the merchant hasn't actually changed anything away from
	// the saved snapshot. Conceptually mirrors the WooCommerce /
	// Block Editor convention (different mechanism — see selectors.js
	// for details). The selector is GLOBAL on purpose: any unsaved
	// change on any tab enables Save on every tab, because the save
	// callback POSTs the full settings blob — clicking Save on
	// Endpoints correctly persists pending Policies edits too. This
	// avoids the surprise of a merchant editing on tab A, switching
	// to tab B, and losing the affordance to save. See
	// `client/data/ai-storefront/selectors.js::isDirty` for the
	// comparison rule and `reducer.js::SET_SETTINGS` for the save-
	// success resync that flips dirty back to clean.
	const isDirty = useSelect(
		( select ) => select( STORE_NAME ).isDirty(),
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
					{ __( 'Loading settings…', 'woocommerce-ai-storefront' ) }
				</p>
			</div>
		);
	}

	// Tab order: Overview (read) → Product Visibility (what's exposed)
	// → Policies (additional signal on what's exposed) → Discovery
	// (how AI agents find the store; reachability checks). Visibility
	// + Policies are the "content" tabs (what AI agents see); Discovery
	// is the "plumbing" tab (how they get to it). Pairing the content
	// tabs makes the merchant journey conceptually cleaner: configure
	// what's shown first, verify it's discoverable second.
	const tabs = [
		{
			name: 'overview',
			title: __( 'Overview', 'woocommerce-ai-storefront' ),
		},
		{
			name: 'products',
			title: __( 'Product Visibility', 'woocommerce-ai-storefront' ),
		},
		{
			name: 'policies',
			title: __( 'Policies', 'woocommerce-ai-storefront' ),
		},
		{
			name: 'endpoints',
			title: __( 'Discovery', 'woocommerce-ai-storefront' ),
		},
	];

	return (
		<div className="wc-ai-storefront-settings">
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
								isDirty={ isDirty }
							/>
						) }
						{ tab.name === 'endpoints' && (
							<EndpointInfo
								settings={ settings }
								onChange={ updateSettingsValues }
								onSave={ saveSettings }
								isSaving={ isSaving }
								isDirty={ isDirty }
							/>
						) }
						{ tab.name === 'policies' && (
							<PoliciesTab
								settings={ settings }
								onChange={ updateSettingsValues }
								onSave={ saveSettings }
								isSaving={ isSaving }
								isDirty={ isDirty }
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

// Format a money amount using the /stats response's currency hints.
// Prefers `currency_symbol` (e.g. "$", "€"); falls back to the ISO
// `currency` code (e.g. "USD") with a space separator so it doesn't
// render glued to the digits like "USD42.00"; finally falls back to
// "$" for the very-degraded case where the backend response is missing
// both fields. Shared by AI Revenue and AOV cards (and any future
// money-shaped card) so currency presentation is consistent everywhere.
const formatMoney = ( stats, amount ) => {
	const numeric = parseFloat( amount || 0 ).toFixed( 2 );
	if ( stats?.currency_symbol ) {
		return `${ stats.currency_symbol }${ numeric }`;
	}
	if ( stats?.currency ) {
		// Space separator: "USD 42.00" reads cleanly; "USD42.00"
		// looks like a typo. The symbol path above doesn't need
		// the space because "$42.00" is the conventional form.
		return `${ stats.currency } ${ numeric }`;
	}
	return `$${ numeric }`;
};

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
		// `flex: 1 1 0; min-width: 140px` removed — the parent grid
		// container now controls card width via
		// `grid-template-columns: repeat(auto-fit, minmax(...))`.
		// See OverviewTab's stat-card grid for the formula and the
		// 4-column-cap rationale.
		padding: '16px',
		background: colors.surfaceSubtle,
		border: 'none',
		borderRadius: '4px',
		textAlign: 'center',
		textDecoration: 'none',
		display: 'block',
	};

	// Value, optional subvalue, and label stack vertically — three
	// rows in card order. Pre-0.5.1 we tried inline-baseline for
	// value+subvalue (with flex-wrap as a fallback) to keep the big
	// numbers visually composed. That broke on the Top Agent card
	// when the subvalue grew long enough that the flex child shrank
	// to ~1ch and every word landed on its own line. Per ui-designer
	// review: at 24px green numerics the value already dominates
	// regardless of subvalue placement, and the cross-card baseline
	// alignment goal is preserved by virtue of the value being row 1
	// of every card. Stacking eliminates an entire class of
	// per-card-height-variance bugs.
	const inner = (
		<>
			<div
				style={ {
					fontSize: '24px',
					fontWeight: '600',
					color: colors.success,
					// Defense against ultra-long agent brand names
					// (e.g. "PerplexityShopping") overflowing the
					// card's grid track. Without this, a 20-char
					// value pushes the card wider than its track and
					// breaks the row layout for adjacent cards.
					overflowWrap: 'anywhere',
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
						marginTop: '2px',
					} }
				>
					{ subvalue }
				</div>
			) }
			<div
				style={ {
					color: colors.textMuted,
					marginTop: '4px',
					...typography.eyebrowLabel,
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
		{ /* how do I turn it on."                                     */ }
		{ /*                                                           */ }
		{ /* NO green accent here — green is reserved for the enabled  */ }
		{ /* state's status banner, so the two states read as visually */ }
		{ /* distinct at a glance. Using green on both would signal    */ }
		{ /* "already active" in both modes and confuse the merchant.  */ }
		{ /* --------------------------------------------------------- */ }
		<Card>
			<CardBody>
				<Flex align="center" gap={ 4 } wrap>
					<FlexItem isBlock>
						<p
							style={ {
								margin: '0 0 8px',
								...typography.eyebrowLabel,
								// Smaller variant: this eyebrow lives
								// inside the pre-enable hero, paired
								// with a 22px h2 right below — 11px
								// keeps it visually subordinate while
								// the rest of the eyebrow stack uses
								// the token's default 12px.
								fontSize: '11px',
								color: colors.textMuted,
							} }
						>
							{ __(
								'Status: Not enabled',
								'woocommerce-ai-storefront'
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
								'Make your store ready for AI shopping assistants',
								'woocommerce-ai-storefront'
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
								'Go live in one click. Checkout stays on your store.',
								'woocommerce-ai-storefront'
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
								? __( 'Enabling…', 'woocommerce-ai-storefront' )
								: __(
										'Enable AI Storefront',
										'woocommerce-ai-storefront'
								  ) }
						</Button>
						{ /* Inline reassurance — the de-risking text
						    belongs next to the CTA that carries the
						    risk, not in a separate strip elsewhere
						    on the page. Three concise points,
						    dot-separated, gray body weight.
						    Merchants glance at it for a second and
						    either click through or keep reading the
						    value cards below. */ }
						<p
							style={ {
								margin: '10px 0 0',
								fontSize: '12px',
								color: colors.textMuted,
								lineHeight: '1.5',
							} }
						>
							{ __(
								'Read-only · Reversible anytime · No frontend changes',
								'woocommerce-ai-storefront'
							) }
						</p>
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
						'woocommerce-ai-storefront'
					) }
				>
					{ __(
						'Your catalog becomes visible to ChatGPT, Gemini, Claude, Perplexity, and Copilot — with no per-platform work when new agents launch.',
						'woocommerce-ai-storefront'
					) }
				</ValueCard>
			</FlexItem>
			<FlexItem isBlock>
				<ValueCard
					icon={ shield }
					title={ __(
						'Checkout stays on your store',
						'woocommerce-ai-storefront'
					) }
				>
					{ __(
						'No AI-platform checkout fees. No delegated payments. You keep the customer, the checkout, and the data.',
						'woocommerce-ai-storefront'
					) }
				</ValueCard>
			</FlexItem>
			<FlexItem isBlock>
				<ValueCard
					icon={ chartBar }
					title={ __(
						'See which AI drove each sale',
						'woocommerce-ai-storefront'
					) }
				>
					{ __(
						'Every AI-referred order is tagged with its source agent and revenue — using standard WooCommerce Order Attribution.',
						'woocommerce-ai-storefront'
					) }
				</ValueCard>
			</FlexItem>
		</Flex>

		{ /*
		    The compact trust strip that sat here in the first-pass
		    redesign was removed — the check-list items read as
		    out-of-place when divorced from the CTA they de-risk.
		    The concise three-point reassurance line under the hero's
		    Enable button does that job where it's actionable.
		*/ }
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

	// Products Exposed card — actual count of products that will
	// reach AI agents. Fetched from `/admin/product-count` so the
	// UI mirrors what the Store API filter returns, not what
	// client-side settings state looks like.
	//
	// Three display states:
	//   - `null` — initial load / pending fetch (renders as "—")
	//   - a number — successful fetch (renders as "N products")
	//   - `'error'` — fetch failed (renders localized "Couldn't
	//     load") so a stuck "—" doesn't read as "no products"
	//
	// Debounce + AbortController: rapid taxonomy toggling (or
	// "Select all" against a large term list) would otherwise
	// burst-fire admin REST requests, each of which runs a real
	// UNION query server-side. The 400ms debounce coalesces
	// taxonomy-tap sequences into one request; the
	// AbortController cancels in-flight requests when the
	// signature changes mid-flight so the displayed count never
	// reflects a stale resolution.
	const [ productCount, setProductCount ] = useState( null );
	const productSelectionSignature = JSON.stringify( [
		settings.product_selection_mode,
		settings.selected_categories || [],
		settings.selected_tags || [],
		settings.selected_brands || [],
		settings.selected_products || [],
	] );
	useEffect( () => {
		let cancelled = false;
		const controller = new AbortController();
		const timeoutId = setTimeout( () => {
			apiFetch( {
				path: '/wc/v3/ai-storefront/admin/product-count',
				signal: controller.signal,
			} )
				.then( ( response ) => {
					if (
						! cancelled &&
						response &&
						typeof response.count === 'number'
					) {
						setProductCount( response.count );
					}
				} )
				.catch( ( error ) => {
					if ( error?.name === 'AbortError' ) {
						return;
					}
					if ( ! cancelled ) {
						setProductCount( 'error' );
						// Dev-visible log so a persistent endpoint
						// failure shows up in browser console without
						// the merchant having to infer it from the UI.
						// eslint-disable-next-line no-console
						console.error(
							'woocommerce-ai-storefront: product-count fetch failed',
							error
						);
					}
				} );
		}, 400 );
		return () => {
			cancelled = true;
			clearTimeout( timeoutId );
			controller.abort();
		};
	}, [ productSelectionSignature ] );

	let productCountDisplay;
	if ( productCount === 'error' ) {
		productCountDisplay = __(
			'Couldn\u2019t load',
			'woocommerce-ai-storefront'
		);
	} else if ( productCount === null ) {
		productCountDisplay = '\u2014';
	} else {
		productCountDisplay = sprintf(
			/* translators: %d: number of products exposed to AI */
			_n(
				'%d product',
				'%d products',
				productCount,
				'woocommerce-ai-storefront'
			),
			productCount
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
							'AI Storefront is active',
							'woocommerce-ai-storefront'
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
							'Your store is ready for AI shopping assistants. Checkout and customer data stay on your store.',
							'woocommerce-ai-storefront'
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
						? __( 'Disabling…', 'woocommerce-ai-storefront' )
						: __( 'Disable', 'woocommerce-ai-storefront' ) }
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
								'woocommerce-ai-storefront'
							),
							value: 'day',
						},
						{
							label: __(
								'Last 7 days',
								'woocommerce-ai-storefront'
							),
							value: 'week',
						},
						{
							label: __(
								'Last 30 days',
								'woocommerce-ai-storefront'
							),
							value: 'month',
						},
						{
							label: __(
								'Last year',
								'woocommerce-ai-storefront'
							),
							value: 'year',
						},
					] }
					onChange={ setPeriod }
					__nextHasNoMarginBottom
				/>
			</Flex>
			{ /*
				Stat-card grid: max 4 cards per row, with cards
				expanding to fill horizontal space until they hit
				the 4-column cap. Layout shape on a typical
				1100-1300px WP-admin content area:
				- 6 cards (current): 4 + 2 (last 2 cards in row 2,
				  left-aligned, columns 3-4 of row 2 stay empty)
				- 8 cards (RSM goal): 4 + 4 (clean 4×2 grid)

				The `max(240px, calc((100% - 48px) / 4))` formula:
				- `(100% - 48px) / 4` = card width if 4 columns fit
				  (48px = 3 gaps × 16px gap)
				- `max(240px, ...)` = each card is at least 240px
				- On wide containers (≥1008px), the calc value wins
				  and caps column count at 4
				- On narrow containers (<1008px), 240px wins and
				  cards reflow to fewer per row (3 ≤ ~1024px,
				  2 ≤ ~544px, 1 ≤ ~304px)
				- `auto-fit` collapses empty trailing slots so the
				  4+2 case left-aligns the partial row

				`min-width: 0` defends against sub-250px viewport
				horizontal scroll (rare but reachable on phone in
				narrow drawers / zoom).
			*/ }
			<div
				style={ {
					display: 'grid',
					gridTemplateColumns:
						'repeat(auto-fit, minmax(max(240px, calc((100% - 48px) / 4)), 1fr))',
					gap: '16px',
					marginTop: '12px',
					minWidth: 0,
				} }
			>
				<StatCard
					label={ __(
						'Products Exposed',
						'woocommerce-ai-storefront'
					) }
					value={ productCountDisplay }
				/>
				{ /* Card labels intentionally omit the time-period
				     suffix (e.g. "Total Orders (7d)"). The period
				     dropdown above the cards already conveys the
				     time scope; repeating it on every card was
				     redundant noise. The dropdown is the single
				     source of truth \u2014 change it once and all five
				     cards refetch with the new period. */ }
				<StatCard
					label={ __( 'Total Orders', 'woocommerce-ai-storefront' ) }
					value={ stats?.all_orders ?? '\u2014' }
				/>
				<StatCard
					label={ __( 'AI Orders', 'woocommerce-ai-storefront' ) }
					value={ stats?.ai_orders ?? '\u2014' }
					subvalue={
						stats && stats.ai_share_percent > 0
							? sprintf(
									/* translators: %s: percentage */
									__(
										'%1$s%% of total',
										'woocommerce-ai-storefront'
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
					label={ __( 'AI Revenue', 'woocommerce-ai-storefront' ) }
					value={
						stats
							? formatMoney( stats, stats.ai_revenue )
							: '\u2014'
					}
				/>
				<StatCard
					label={ __( 'AOV', 'woocommerce-ai-storefront' ) }
					value={
						stats && stats.ai_orders > 0
							? formatMoney( stats, stats.ai_aov )
							: '\u2014'
					}
				/>
				<StatCard
					label={ __( 'Top agent', 'woocommerce-ai-storefront' ) }
					/* `||` (not `??`) so an empty-string agent name from a corrupt
					   utm_source also falls through to the em-dash. The backend
					   already filters empty meta_value at the SQL level + skips
					   empty names in derive_stats(); this is belt-and-suspenders. */
					value={ stats?.top_agent?.name || '\u2014' }
					/* Subvalue carries only the share percent. Pre-0.5.1 it
					   also included the order count ("N orders | M% of AI
					   orders") but that duplicated the "AI Orders" card's
					   value, made the subvalue too long to fit at 4-column
					   card widths, and tripped the "1 orders" pluralization
					   bug. The percent is the unique signal of the Top
					   Agent card; the order count's natural home is the
					   AI Orders card next to it. */
					subvalue={
						stats && stats.top_agent
							? sprintf(
									/* translators: %1$s: percent share of AI orders attributed to the top agent. */
									__(
										'%1$s%% of AI orders',
										'woocommerce-ai-storefront'
									),
									stats.top_agent.share_percent
							  )
							: undefined
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
