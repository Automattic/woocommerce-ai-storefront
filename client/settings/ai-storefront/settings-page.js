import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { TabPanel, Spinner } from '@wordpress/components';
// Inline SVGs for the pre-enable value cards. Using stroke-based icons
// from the design spec instead of @wordpress/icons — WP admin CSS can
// force SVG fill to inherit from dark text, fighting the purple intent.
const IconGlobe = () => (
	<svg
		width="32"
		height="32"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.6"
		aria-hidden="true"
	>
		<circle cx="12" cy="12" r="9" />
		<path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
	</svg>
);
const IconShield = () => (
	<svg
		width="32"
		height="32"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.6"
		aria-hidden="true"
	>
		<path d="M12 3l8 3v6c0 4.5-3.4 8.4-8 9-4.6-.6-8-4.5-8-9V6l8-3z" />
		<path d="M9 12l2 2 4-4" />
	</svg>
);
const IconChartBar = () => (
	<svg
		width="32"
		height="32"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.6"
		aria-hidden="true"
	>
		<path d="M3 21h18" />
		<rect x="5" y="13" width="3" height="6" />
		<rect x="10.5" y="9" width="3" height="10" />
		<rect x="16" y="5" width="3" height="14" />
	</svg>
);
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { STORE_NAME } from '../../data/ai-storefront/constants';
import ProductSelection from './product-selection';
import EndpointInfo from './endpoint-info';
import AIOrdersTable from './ai-orders-table';
import PoliciesTab from './policies-tab';
import { colors, typography, spacing, radii } from './tokens';

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
	const isEnabled = settings?.enabled === 'yes';

	if ( ! isEnabled ) {
		return (
			<div className="wc-ai-storefront-settings">
				<OverviewTab
					settings={ settings }
					onChange={ updateSettingsValues }
					onSave={ saveSettings }
					isSaving={ isSaving }
				/>
			</div>
		);
	}

	const tabs = [
		{
			name: 'overview',
			title: __( 'Overview', 'woocommerce-ai-storefront' ),
		},
		{
			name: 'products',
			// Sentence case ("Product visibility" not "Product Visibility")
			// per the cross-cutting copy rule. Audited together with
			// other Title Case strings on this surface in the
			// settings-redesign editorial pass.
			title: __( 'Product visibility', 'woocommerce-ai-storefront' ),
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

	const tabNames = tabs.map( ( t ) => t.name );
	const hashTab = window.location.hash.replace( '#', '' );
	const initialTab = tabNames.includes( hashTab ) ? hashTab : tabs[ 0 ].name;

	return (
		<div className="wc-ai-storefront-settings">
			<TabPanel
				tabs={ tabs }
				initialTabName={ initialTab }
				onSelect={ ( tabName ) => {
					window.location.hash = tabName;
				} }
			>
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
const ValueCard = ( { Icon: IconComponent, title, children } ) => (
	<div
		style={ {
			height: '100%',
			padding: '20px',
			background: colors.surfaceSubtle,
			border: `1px solid ${ colors.borderSubtle }`,
			borderRadius: radii.sm,
		} }
	>
		<div
			style={ {
				color: colors.wooPurple50,
				marginBottom: '12px',
				display: 'inline-block',
			} }
		>
			<IconComponent />
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
// IS the visual signal. Purple tint bg + dark purple text matches
// the `.assistant-badge` spec in the design file.
const AssistantChip = ( { children } ) => (
	<span
		style={ {
			display: 'inline-flex',
			alignItems: 'center',
			justifyContent: 'center',
			padding: '4px 12px',
			background: colors.wooPurple10,
			borderRadius: radii.pill,
			fontSize: '12px',
			fontWeight: '600',
			lineHeight: 1.4,
			color: colors.wooPurple90,
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

// Like formatMoney but rounds to nearest integer and adds locale digit
// grouping — used for the AI Revenue primary value and all_revenue reference
// where decimals add noise rather than precision.
const formatMoneyRounded = ( stats, amount ) => {
	const rounded = Math.round( parseFloat( amount || 0 ) ).toLocaleString();
	if ( stats?.currency_symbol ) {
		return `${ stats.currency_symbol }${ rounded }`;
	}
	if ( stats?.currency ) {
		return `${ stats.currency } ${ rounded }`;
	}
	return `$${ rounded }`;
};

// Hand-rolled stat card for the Overview stats row. We evaluated Woo's
// `SummaryNumber` from `@woocommerce/components` and deferred adoption —
// see AGENTS.md "Styling" section for the rationale. In short: Woo
// components need their stylesheet enqueued manually on custom admin
// pages (the script-dependency extraction only handles JS, not CSS),
// and the handle names drift between WC versions. Until wc-admin
// provides a reliable way to opt into its stylesheet from a custom
// submenu page, hand-rolled cards are lower-maintenance.
// `reference`, when passed, renders inline after `value` as a
// denominator (e.g. "14 / 128"). Used to fold a baseline metric onto
// its primary numerator card (AI orders / total orders) instead of
// parking the baseline as a peer card. Source pattern: Stripe
// Dashboard, GitHub Insights, GA4 channel cards. Color and weight
// keep the denominator subordinate so the AI metric reads first.
const StatCard = ( { label, value, reference, href, background } ) => {
	const cardStyle = {
		// `flex: 1 1 0; min-width: 140px` removed — the parent grid
		// container now controls card width via
		// `grid-template-columns: repeat(auto-fit, minmax(...))`.
		// See OverviewTab's stat-card grid for the formula and the
		// 4-column-cap rationale.
		padding: '14px 16px',
		background: background ?? colors.surface,
		border: `1px solid ${ colors.borderSubtle }`,
		borderRadius: radii.sm,
		textDecoration: 'none',
		display: 'block',
		color: 'inherit',
	};

	// Value above label, two rows. Cards previously supported an
	// optional `subvalue` row between value and label, but that
	// added per-card height variance — cards with subvalues were
	// taller than cards without, breaking the cross-card baseline
	// alignment merchants rely on to scan the strip. Removed in
	// 0.6.1; if a stat needs companion data, it goes in its own
	// adjacent card OR is inlined as a denominator (see the AI
	// orders card's `reference` prop in PostEnableView).
	//
	// Color is always `textPrimary` (neutral) — see "Stat-value
	// color is always neutral" in
	// docs/design/settings-redesign-final.html. Earlier revisions
	// rendered AI metrics in green; that mixed sentiment-channel
	// (green = good) with category-channel (green = AI), and made
	// future delta rows (e.g. "+12%") ambiguous. Reference values
	// are now inlined as denominators (e.g. "14 / 128") rather
	// than sitting as sibling cards.
	const inner = (
		<>
			<div
				style={ {
					...typography.eyebrowLabel,
					color: colors.textMuted,
					marginBottom: '6px',
				} }
			>
				{ label }
			</div>
			<div
				style={ {
					...typography.statValue,
					color: colors.textPrimary,
					overflowWrap: 'anywhere',
				} }
			>
				{ value }
				{ reference !== null && reference !== undefined && (
					<span
						style={ {
							marginLeft: '6px',
							fontSize: '14px',
							fontWeight: 400,
							color: colors.textMuted,
							letterSpacing: 'normal',
						} }
					>
						/ { reference }
					</span>
				) }
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

const useIsMobile = () => {
	const [ isMobile, setIsMobile ] = useState(
		() => window.innerWidth < 782
	);
	useEffect( () => {
		const mq = window.matchMedia( '(max-width: 781px)' );
		const handler = ( e ) => setIsMobile( e.matches );
		mq.addEventListener( 'change', handler );
		return () => mq.removeEventListener( 'change', handler );
	}, [] );
	return isMobile;
};

const PreEnableView = ( { onChange, onSave, isSaving } ) => {
	const [ ctaHovered, setCtaHovered ] = useState( false );
	const isMobile = useIsMobile();
	return (
		<div>
			{ /* Hero block: purple-tinted gradient bg, 1.4fr / 1fr grid.
		   The gradient signals "brand moment" and disappears once
		   the merchant enables — the post-enable dashboard is
		   neutral. WP `<Card>/<Flex>` removed because their blue-
		   accent base styles fight the purple intent at every node. */ }
			<div
				style={ {
					background: `linear-gradient(135deg, ${ colors.heroBg }, ${ colors.surface } 60%)`,
					border: `1px solid ${ colors.borderSubtle }`,
					borderRadius: radii.sm,
					padding: `${ spacing.s7 } ${ spacing.s6 }`,
					display: 'grid',
					gridTemplateColumns: isMobile ? '1fr' : '1.4fr 1fr',
					gap: spacing.s5,
					alignItems: 'center',
				} }
			>
				{ /* Left column: headline + CTA + reassurance */ }
				<div>
					<h2
						style={ {
							margin: `0 0 ${ spacing.s2 }`,
							...typography.heroHeadline,
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
							margin: `0 0 ${ spacing.s5 }`,
							fontSize: '15px',
							lineHeight: '1.5',
							color: colors.textSecondary,
						} }
					>
						{ __(
							'Go live in one click. Checkout stays on your store.',
							'woocommerce-ai-storefront'
						) }
					</p>
					{ /* btn-brand: Woo purple. Not using WP `<Button variant="primary">` —
				     WP's primary button is wp-admin-blue and there's no variant for
				     purple. Hover darkens to wooPurple70 per `.btn-brand:hover`
				     in the design spec. */ }
					<button
						type="button"
						disabled={ isSaving }
						onMouseEnter={ () => setCtaHovered( true ) }
						onMouseLeave={ () => setCtaHovered( false ) }
						onClick={ () => {
							onChange( { enabled: 'yes' } );
							onSave();
						} }
						style={ {
							background:
								isSaving || ctaHovered
									? colors.wooPurple70
									: colors.wooPurple50,
							border: `1px solid ${
								isSaving || ctaHovered
									? colors.wooPurple70
									: colors.wooPurple50
							}`,
							color: colors.surface,
							padding: '8px 16px',
							borderRadius: radii.sm,
							font: `600 14px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`,
							cursor: isSaving ? 'not-allowed' : 'pointer',
							display: 'inline-flex',
							alignItems: 'center',
							opacity: isSaving ? 0.8 : 1,
						} }
					>
						{ isSaving
							? __( 'Enabling…', 'woocommerce-ai-storefront' )
							: __(
									'Enable AI Storefront',
									'woocommerce-ai-storefront'
							  ) }
					</button>
					{ /* Reassurance line sits directly under the CTA — de-risking
				    text belongs next to the action that carries the risk. */ }
					<p
						style={ {
							margin: '12px 0 0',
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
				</div>

				{ /* Right column: assistant-name chips, 2-column grid.
			    Purple tint bg + dark purple text = Woo brand chips.
			    Concrete names convert better than "all AI agents". */ }
				<div
					style={ {
						display: 'grid',
						gridTemplateColumns: 'repeat(2, 1fr)',
						gap: spacing.s2,
					} }
				>
					<AssistantChip>ChatGPT</AssistantChip>
					<AssistantChip>Gemini</AssistantChip>
					<AssistantChip>Claude</AssistantChip>
					<AssistantChip>Perplexity</AssistantChip>
					<AssistantChip>Copilot</AssistantChip>
				</div>
			</div>

			{ /* Value-prop grid: 3-column CSS grid matching `.value-grid`
		    in the design spec. Icon color is Woo purple to match the
		    hero above (both disappear on enable). */ }
			<div
				style={ {
					display: 'grid',
					gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, 1fr)',
					gap: spacing.s4,
					marginTop: spacing.s7,
				} }
			>
				<ValueCard
					Icon={ IconGlobe }
					title={ __(
						'One setup, every AI assistant',
						'woocommerce-ai-storefront'
					) }
				>
					{ __(
						'Your catalog becomes visible to ChatGPT, Gemini, Claude, Perplexity, and Copilot, with no per-platform work when new agents launch.',
						'woocommerce-ai-storefront'
					) }
				</ValueCard>
				<ValueCard
					Icon={ IconShield }
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
				<ValueCard
					Icon={ IconChartBar }
					title={ __(
						'See which AI drove each sale',
						'woocommerce-ai-storefront'
					) }
				>
					{ __(
						'Every AI-referred order is tagged with its source agent and revenue, using standard WooCommerce Order Attribution.',
						'woocommerce-ai-storefront'
					) }
				</ValueCard>
			</div>
		</div>
	);
};

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
			{ /*
			   Enabled-state UI is communicated by the populated
			   dashboard itself (period selector, stat cards, and
			   recent orders). There is no separate positive-status
			   banner at the top of this view.

			   The Disable control remains in the .disable-row footer.
			   The .notice CSS pattern still exists for warning/error
			   notices elsewhere; only the positive-status usage was
			   removed from this view.
			*/ }

			{ /* Section-head block: mirrors the pattern used on the other
			    three tabs (Catalog access, Store policies, AI agent
			    access). The h2 names the merchant's job on this tab;
			    the italic description gives one-line context. */ }
			<header style={ { marginBottom: spacing.s5 } }>
				<h2
					style={ {
						margin: `0 0 ${ spacing.s1 }`,
						...typography.sectionHeading,
						color: colors.textPrimary,
					} }
				>
					{ __(
						'AI traffic and orders',
						'woocommerce-ai-storefront'
					) }
				</h2>
				<p
					style={ {
						margin: 0,
						color: colors.textSecondary,
						fontSize: '13px',
						fontStyle: 'italic',
					} }
				>
					{ __(
						'Live dashboard of your AI-attributed traffic and orders.',
						'woocommerce-ai-storefront'
					) }
				</p>
			</header>

			{ /* Period chip-row — filter pattern (browse without committing),
			    distinct from the .seg-control configuration pattern used
			    on Policies + Product Visibility. Active chip: blue tint
			    bg + blue border + blue 600-weight text. Inactive chip:
			    white bg + subtle border + neutral text. Matches the
			    `.chip` / `.chip.active` spec in the design file.
			    Labels match the design spec exactly. */ }
			<div
				style={ {
					display: 'flex',
					justifyContent: 'flex-end',
					marginBottom: spacing.s3,
				} }
			>
				<div
					role="radiogroup"
					aria-label={ __(
						'Date range',
						'woocommerce-ai-storefront'
					) }
					style={ {
						display: 'flex',
						flexWrap: 'wrap',
						gap: spacing.s1,
					} }
				>
					{ [
						{
							label: __( 'Today', 'woocommerce-ai-storefront' ),
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
								'Last 90 days',
								'woocommerce-ai-storefront'
							),
							value: 'quarter',
						},
						{
							label: __(
								'Last 12 months',
								'woocommerce-ai-storefront'
							),
							value: 'year',
						},
					].map( ( option ) => {
						const isActive = period === option.value;
						return (
							<button
								key={ option.value }
								role="radio"
								aria-checked={ isActive }
								type="button"
								onClick={ () => setPeriod( option.value ) }
								style={ {
									display: 'inline-flex',
									alignItems: 'center',
									border: `1px solid ${
										isActive
											? colors.accent
											: colors.borderSubtle
									}`,
									borderRadius: radii.sm,
									background: isActive
										? colors.infoBg
										: colors.surface,
									color: isActive
										? colors.accent
										: colors.textPrimary,
									padding: '4px 12px',
									fontSize: '13px',
									fontWeight: isActive ? '600' : '400',
									lineHeight: '1.3',
									cursor: 'pointer',
								} }
							>
								{ option.label }
							</button>
						);
					} ) }
				</div>
			</div>
			{ /*
				Stat-card grid: max 4 cards per row, with cards
				expanding to fill horizontal space until they hit
				the 4-column cap. Layout shape on a typical
				1100-1300px WP-admin content area:
				- 6 cards (current): 4 + 2 (last 2 cards in row 2,
				  left-aligned, columns 3-4 of row 2 stay empty)
				- 8 cards (RSM goal): 4 + 4 (clean 4×2 grid)

				The `max(180px, calc((100% - 36px) / 4))` formula:
				- `(100% - 36px) / 4` = card width if 4 columns fit
				  (36px = 3 gaps × 12px gap)
				- `max(180px, ...)` = each card is at least 180px
				- On wide containers (≥756px), the calc value wins
				  and caps column count at 4
				- On narrow containers, the 180px floor wins and
				  `auto-fit` packs as many ≥180px columns as fit
				- `auto-fit` collapses empty trailing slots so the
				  4+2 case left-aligns the partial row.

				`min-width: 0` overrides the default `min-width: auto`
				on this grid container so it can shrink below its
				own min-content size when wedged into a narrow
				flex/grid parent (e.g. very narrow viewports in a
				drawer or zoom). Per-card overflow defense lives on
				the value div via `overflow-wrap: anywhere`.
			*/ }
			<div
				style={ {
					display: 'grid',
					gridTemplateColumns:
						'repeat(auto-fit, minmax(max(180px, calc((100% - 36px) / 4)), 1fr))',
					gap: spacing.s3,
					minWidth: 0,
				} }
			>
				<StatCard
					label={ __(
						'Products exposed',
						'woocommerce-ai-storefront'
					) }
					value={ productCountDisplay }
					background={ colors.surfaceSubtle }
				/>
				{ /* Card labels omit the time-period suffix
				     (e.g. "AI orders (7d)"); the period chip-row
				     above already conveys time scope and is the
				     single source of truth.

				     Card order: AI orders precedes the rest of the
				     volume metrics. Merchants land on this tab to
				     scan AI signal first; AI orders is the headline
				     metric.

				     Total orders is folded onto the AI orders card as
				     a denominator (e.g. "14 / 128") via the
				     `reference` prop, instead of sitting as a peer
				     card. Total orders is a baseline, not a peer
				     metric; pairing it with its numerator is more
				     honest than parking it as an AI-prefixed sibling.
				     Source pattern: Stripe Dashboard, GitHub
				     Insights, GA4 channel cards. */ }
				<StatCard
					label={ __( 'AI orders', 'woocommerce-ai-storefront' ) }
					value={ stats?.ai_orders ?? '\u2014' }
					reference={ stats?.all_orders ?? null }
					href={
						/* global wcAiSyndicationParams */
						typeof wcAiSyndicationParams !== 'undefined'
							? wcAiSyndicationParams.ordersUrl
							: undefined
					}
				/>
				<StatCard
					label={ __( 'AI order rate', 'woocommerce-ai-storefront' ) }
					value={
						stats?.all_orders > 0
							? `${ (
									( ( stats.ai_orders || 0 ) /
										stats.all_orders ) *
									100
							  ).toFixed( 1 ) }%`
							: '\u2014'
					}
				/>
				<StatCard
					label={ __( 'AI revenue', 'woocommerce-ai-storefront' ) }
					value={
						stats
							? formatMoneyRounded( stats, stats.ai_revenue )
							: '\u2014'
					}
					reference={
						stats !== null && stats?.all_revenue !== undefined
							? formatMoneyRounded( stats, stats.all_revenue )
							: null
					}
				/>
				<StatCard
					label={ __( 'AI AOV', 'woocommerce-ai-storefront' ) }
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
				/>
				{ /* Top agent's share of AI orders — promoted from a
				     subvalue on the Top Agent card to its own card in
				     0.6.1. Sits adjacent so the two cards read as a
				     pair (e.g. "Top agent / UCPPlayground" + "Top
				     agent share / 100%"). The shared "Top agent"
				     label prefix is what does the visual linking now
				     that the subvalue is gone — earlier the label
				     was "% of AI orders" but that read as a
				     standalone metric and lost the connection to the
				     adjacent card. Promoting the percent to a full
				     card preserves cross-card baseline alignment
				     (the subvalue created per-card height variance)
				     and gives the figure its own visual weight at
				     24px green numerics. */ }
				<StatCard
					label={ __(
						'Top agent share',
						'woocommerce-ai-storefront'
					) }
					value={
						/* `!= null` covers undefined and null without
						   coercing legitimate 0 (which can happen mid-
						   period when a brief AI agent fallout drops to
						   no orders) to the em-dash. The defensive
						   inner-field guard hardens against schema
						   drift in `derive_stats()` \u2014 the outer
						   `top_agent` could exist while
						   `share_percent` is missing if a future
						   refactor added `top_agent.name` separately. */
						stats?.top_agent &&
						stats.top_agent.share_percent !== null &&
						stats.top_agent.share_percent !== undefined
							? sprintf(
									/* translators: %1$s: percent share of AI orders attributed to the top agent. The literal trailing percent sign comes from `%%` in the format string. Ordered placeholder (`%1$s`) keeps the file consistent with @wordpress/valid-sprintf rules. */
									__( '%1$s%%', 'woocommerce-ai-storefront' ),
									stats.top_agent.share_percent
							  )
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

			{ /*
			   Disable affordance — sits at the bottom of the panel,
			   below the recent-orders table. Hairline-divider top
			   separates it from the data above. Two-column flex
			   (label/description on the left, button on the right).

			   Single click disables — `onChange({ enabled: 'no' })`
			   then `onSave()`. No confirmation modal: disabling is
			   reversible (re-enabling restores all settings), and
			   the inline description above the button already makes
			   consequences visible BEFORE the click. Modal dialogs
			   for reversible actions just train users to dismiss
			   them; reserve modal confirmations for irreversible
			   destructive actions only.
			*/ }
			<div
				style={ {
					marginTop: spacing.s7,
					paddingTop: spacing.s5,
					borderTop: `1px solid ${ colors.borderSubtle }`,
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					gap: spacing.s4,
					flexWrap: 'wrap',
				} }
			>
				<div>
					<strong style={ { color: colors.textPrimary } }>
						{ __(
							'Disable AI Storefront',
							'woocommerce-ai-storefront'
						) }
					</strong>
					<p
						style={ {
							margin: '4px 0 0',
							color: colors.textSecondary,
							fontSize: '13px',
							lineHeight: 1.5,
						} }
					>
						{ __(
							'Stops AI agents from accessing your store. Settings are preserved.',
							'woocommerce-ai-storefront'
						) }
					</p>
				</div>
				{ /* btn-danger-outline: white bg + red border + red text.
				     NOT using WP `<Button isDestructive>` — WP's base
				     button styles override the red intent on secondary
				     variant. Inline styles win reliably here. */ }
				<button
					type="button"
					disabled={ isSaving }
					onClick={ () => {
						onChange( { enabled: 'no' } );
						onSave();
					} }
					style={ {
						background: colors.surface,
						border: `1px solid ${ colors.error }`,
						color: colors.error,
						padding: '4px 12px',
						borderRadius: radii.sm,
						font: `400 13px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`,
						cursor: isSaving ? 'not-allowed' : 'pointer',
						flexShrink: 0,
						minHeight: '30px',
						opacity: isSaving ? 0.6 : 1,
					} }
				>
					{ isSaving
						? __( 'Disabling…', 'woocommerce-ai-storefront' )
						: __( 'Disable', 'woocommerce-ai-storefront' ) }
				</button>
			</div>
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
