import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	Button,
	CheckboxControl,
	ExternalLink,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-storefront/constants';
import { colors, typography, radii, spacing } from './tokens';
import { TabInputStyles } from './tab-input-styles';

const ENDPOINT_TAB_CLASS = 'ai-storefront-endpoint-tab';

const CRAWLER_GROUP_CLASS = 'ai-storefront-crawler-group';

/**
 * Endpoint-tab-specific styles. The shared 32px input-height override
 * is provided by `TabInputStyles`; this component owns the URL-cell
 * monospace font and the collapsible-crawler-group chrome.
 */
function EndpointTabStyles() {
	return (
		<style>{ `
			.${ ENDPOINT_TAB_CLASS } .endpoint-url-cell a {
				font-family: "JetBrains Mono", Menlo, Consolas, Monaco, monospace;
				font-size: 13px;
				font-weight: 500;
			}
			details.${ CRAWLER_GROUP_CLASS } {
				border: 1px solid ${ colors.borderSubtle };
				border-radius: ${ radii.sm };
				background: ${ colors.surface };
				margin-top: ${ spacing.s3 };
			}
			details.${ CRAWLER_GROUP_CLASS } summary {
				list-style: none;
				cursor: pointer;
				padding: 10px 12px;
				display: flex;
				align-items: center;
				gap: ${ spacing.s2 };
				justify-content: space-between;
				font: 600 13px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				color: ${ colors.textPrimary };
				user-select: none;
			}
			details.${ CRAWLER_GROUP_CLASS } summary::-webkit-details-marker { display: none; }
			details.${ CRAWLER_GROUP_CLASS } summary::before {
				content: "";
				display: inline-block;
				width: 0; height: 0;
				margin-right: 6px;
				flex-shrink: 0;
				border-left: 5px solid ${ colors.textMuted };
				border-top: 4px solid transparent;
				border-bottom: 4px solid transparent;
				transition: transform .15s;
			}
			details.${ CRAWLER_GROUP_CLASS }[open] summary::before {
				transform: rotate(90deg);
			}
			details.${ CRAWLER_GROUP_CLASS } .crawler-group-body {
				padding: 6px 14px 12px;
				border-top: 1px solid ${ colors.borderSubtle };
			}
			details.${ CRAWLER_GROUP_CLASS } .crawler-row {
				padding: 8px 0;
			}
		` }</style>
	);
}

/**
 * Rate-limit presets for the AI-agent request-throttling control.
 *
 * The three presets cover the bulk of real-world merchant hosting
 * situations: shared/low-traffic, typical, and dedicated/high-traffic.
 * The Custom option escapes the presets for merchants with unusual
 * needs (very high-volume stores, or very constrained hosts).
 *
 * Values here are the RPM (requests/minute) per-crawler cap enforced
 * by `WC_AI_Storefront_Store_Api_Rate_Limiter` via WooCommerce Store
 * API's built-in limiter. The same setting is the backing store for
 * both the UI and the rate-limit hook — no separate "display" vs.
 * "applied" values.
 */
const RATE_LIMIT_PRESETS = {
	conservative: { rpm: 10 },
	recommended: { rpm: 25 },
	generous: { rpm: 100 },
};

/**
 * Map an RPM integer back to its preset key.
 *
 * Used by the radio control to pre-select the right preset when the
 * page first renders. Returns 'custom' when the stored RPM doesn't
 * match any preset — which correctly reveals the custom RPM input
 * for merchants who've tuned the value manually.
 *
 * @param {number} rpm Requests-per-minute from the stored settings.
 * @return {string}    Preset key ('conservative'/'recommended'/'generous') or 'custom'.
 */
const getActivePreset = ( rpm ) => {
	for ( const [ key, preset ] of Object.entries( RATE_LIMIT_PRESETS ) ) {
		if ( preset.rpm === rpm ) {
			return key;
		}
	}
	return 'custom';
};

/**
 * Known AI crawler metadata, grouped by traffic category.
 *
 * The two categories map to different merchant value propositions:
 *
 *   - `live`     agents fetch during an active user query. They see
 *                fresh inventory and route the user to checkout —
 *                this is the revenue-path traffic for a commerce
 *                site. Recommended on.
 *
 *   - `training` crawlers index content for later use in model
 *                weights or cached snapshots. They do not route
 *                revenue. Stale inventory risk is real: a crawl
 *                captured in April may surface as "answer" in an
 *                AI response in October, by which point prices and
 *                availability have moved. Merchant discretion.
 *
 * The UCP protocol (v2026-04-08) is intentionally silent on
 * training-crawler policy — UCP is a live-commerce spec, training is
 * out of its scope. So the distinction is maintained here as a
 * merchant-facing UX cue, not a wire-format requirement.
 *
 * Keep this list in sync with the PHP constants
 * `WC_AI_Storefront_Robots::LIVE_BROWSING_AGENTS`,
 * `::TRAINING_CRAWLERS`, and `::TEST_CRAWLERS`. The frontend renders
 * from this constant; the backend sanitizes against the PHP-side
 * `AI_CRAWLERS` (which is the union of all three). Drift would
 * produce silently-dropped checkboxes on save.
 */
/**
 * Shape of a section in the AI Crawlers list.
 *
 * The render path filters `KNOWN_CRAWLERS` by category to populate
 * each section. Most groups are 1:1 with a backend category (e.g.
 * `live`); when a single merchant-facing section spans multiple
 * backend categories (e.g. "Training and Test Crawlers" covers
 * `training` + `test`), supply the full list via `categories`.
 *
 * @typedef {Object} CrawlerGroup
 * @property {string}   key          Stable React reconciliation key,
 *                                   AND the default backend-category
 *                                   filter when `categories` isn't
 *                                   supplied. Required.
 * @property {string}   title        Section heading (translated).
 * @property {string}   subtitle     One-line context paragraph below
 *                                   the heading (translated).
 * @property {string[]} [categories] When this section spans more than
 *                                   one backend category, list all of
 *                                   them. Missing OR empty → fall back
 *                                   to `[key]` (single-category mode).
 *                                   See the render-time guard for the
 *                                   exact fallback rule.
 */

const KNOWN_CRAWLERS = [
	// ----------------------------------------------------------------
	// Live browsing — user-initiated fetches + live-answer indexing.
	// Recommended on; these route revenue. Sub-grouped for scannability;
	// alphabetical within each sub-group.
	// ----------------------------------------------------------------

	// General-purpose AI assistants.
	{
		id: 'Applebot',
		label: 'Applebot (Apple Siri / Spotlight)',
		category: 'live',
		subgroup: 'general',
	},
	{
		id: 'ChatGPT-User',
		label: 'ChatGPT-User (OpenAI)',
		category: 'live',
		subgroup: 'general',
	},
	{
		id: 'Claude-SearchBot',
		label: 'Claude-SearchBot (Anthropic)',
		category: 'live',
		subgroup: 'general',
	},
	{
		id: 'Claude-User',
		label: 'Claude-User (Anthropic)',
		category: 'live',
		subgroup: 'general',
	},
	{
		id: 'DuckAssistBot',
		label: 'DuckAssistBot (DuckDuckGo)',
		category: 'live',
		subgroup: 'general',
	},
	{
		id: 'OAI-SearchBot',
		label: 'OAI-SearchBot (OpenAI SearchGPT)',
		category: 'live',
		subgroup: 'general',
	},
	{
		id: 'Perplexity-User',
		label: 'Perplexity-User (Perplexity)',
		category: 'live',
		subgroup: 'general',
	},
	{
		id: 'PerplexityBot',
		label: 'PerplexityBot (Perplexity)',
		category: 'live',
		subgroup: 'general',
	},

	// Agentic shopping — AI that places orders, not just reads.
	{
		id: 'AmazonBuyForMe',
		label: 'AmazonBuyForMe (Amazon Rufus)',
		category: 'live',
		subgroup: 'agentic_shopping',
	},
	{
		id: 'KlarnaBot',
		label: 'KlarnaBot (Klarna AI)',
		category: 'live',
		subgroup: 'agentic_shopping',
	},

	// Commerce search engines.
	{
		id: 'AdIdxBot',
		label: 'AdIdxBot (Microsoft Shopping / Copilot)',
		category: 'live',
		subgroup: 'commerce_search',
	},
	{
		id: 'Storebot-Google',
		label: 'Storebot-Google (Google Shopping AI)',
		category: 'live',
		subgroup: 'commerce_search',
	},

	// Regional — Asia.
	{
		id: 'ERNIEBot',
		label: 'ERNIEBot (Baidu / China)',
		category: 'live',
		subgroup: 'regional_asia',
	},
	{
		id: 'NaverBot',
		label: 'NaverBot (Naver / Korea)',
		category: 'live',
		subgroup: 'regional_asia',
	},
	{
		id: 'PetalBot',
		label: 'PetalBot (Huawei / Global)',
		category: 'live',
		subgroup: 'regional_asia',
	},
	{
		id: 'WRTNBot',
		label: 'WRTNBot (Wrtn / Korea)',
		category: 'live',
		subgroup: 'regional_asia',
	},
	{
		id: 'YiyanBot',
		label: 'YiyanBot (Baidu Conversational / China)',
		category: 'live',
		subgroup: 'regional_asia',
	},

	// Regional — Europe.
	{
		id: 'YandexBot',
		label: 'YandexBot (Yandex / Russia + E. Europe)',
		category: 'live',
		subgroup: 'regional_europe',
	},

	// ----------------------------------------------------------------
	// Training crawlers — alphabetical (case-insensitive). Brand-
	// strategy decision; default off.
	// ----------------------------------------------------------------
	{
		id: 'Amazonbot',
		label: 'Amazonbot (Amazon / Alexa)',
		category: 'training',
	},
	{
		id: 'Applebot-Extended',
		label: 'Applebot-Extended (Apple Intelligence)',
		category: 'training',
	},
	{
		id: 'Bytespider',
		label: 'Bytespider (ByteDance / TikTok)',
		category: 'training',
	},
	{ id: 'CCBot', label: 'CCBot (CommonCrawl)', category: 'training' },
	{ id: 'ClaudeBot', label: 'ClaudeBot (Anthropic)', category: 'training' },
	{ id: 'cohere-ai', label: 'cohere-ai (Cohere)', category: 'training' },
	{
		id: 'Google-Extended',
		label: 'Google-Extended (Gemini training)',
		category: 'training',
	},
	{ id: 'GPTBot', label: 'GPTBot (OpenAI)', category: 'training' },
	{
		id: 'Meta-ExternalAgent',
		label: 'Meta-ExternalAgent (Meta AI)',
		category: 'training',
	},
	{
		id: 'Microsoft-BingBot-Extended',
		label: 'Microsoft-BingBot-Extended (Copilot training)',
		category: 'training',
	},

	// ----------------------------------------------------------------
	// Test / validation crawlers — alphabetical for forward-compat.
	// Third-party UCP validation tools merchants run against their
	// own store. Visually grouped with training under "Training and
	// Test Crawlers" in the UI.
	// ----------------------------------------------------------------
	{
		id: 'UCPPlayground',
		label: 'UCPPlayground (ucpplayground.com — UCP validation tool)',
		category: 'test',
	},
];

/**
 * Small badge showing reachability state for one endpoint.
 *
 * Five visual states:
 *   - checking:    spinner + "Checking…"
 *   - reachable:   green ✓ + "Reachable"
 *   - unreachable: red ✗ + "Not reachable" (plus recovery hint below the table)
 *   - disabled:    gray — + "Not published" (syndication toggled off)
 *   - (no value):  same rendering as 'checking' — probe hasn't started yet
 *
 * @param {Object} root0        Props.
 * @param {string} root0.status One of checking/reachable/unreachable/disabled.
 */
const StatusBadge = ( { status } ) => {
	const effective = status || 'checking';

	// Checking state: spinner inline — no pill background yet since
	// we don't have a resolved state to color-code.
	if ( effective === 'checking' ) {
		return (
			<span
				style={ {
					display: 'inline-flex',
					alignItems: 'center',
					gap: '6px',
					background: colors.infoBg,
					color: colors.accent,
					fontSize: '12px',
					fontWeight: '500',
					padding: '3px 10px',
					borderRadius: radii.pill,
				} }
			>
				<Spinner style={ { width: '12px', height: '12px' } } />
				{ __( 'Checking…', 'woocommerce-ai-storefront' ) }
			</span>
		);
	}

	// Pill config: bg + fg + dot color per state. Matches the design's
	// `.status-badge` pill pattern (background fill + 6px dot + label).
	const config = {
		reachable: {
			bg: colors.successBg,
			fg: colors.success,
			dot: colors.success,
			label: __( 'Reachable', 'woocommerce-ai-storefront' ),
		},
		unreachable: {
			bg: colors.errorBg,
			fg: colors.error,
			dot: colors.error,
			label: __( 'Not reachable', 'woocommerce-ai-storefront' ),
		},
		disabled: {
			bg: colors.surfaceMuted,
			fg: colors.textMuted,
			dot: colors.textMuted,
			label: __( 'Not published', 'woocommerce-ai-storefront' ),
		},
	}[ effective ] || {
		bg: colors.surfaceMuted,
		fg: colors.textMuted,
		dot: colors.textMuted,
		label: effective,
	};

	return (
		<span
			style={ {
				display: 'inline-flex',
				alignItems: 'center',
				gap: '4px',
				background: config.bg,
				color: config.fg,
				fontSize: '12px',
				fontWeight: '500',
				padding: '3px 10px',
				borderRadius: radii.pill,
				lineHeight: 1,
			} }
		>
			<span
				aria-hidden="true"
				style={ {
					width: '6px',
					height: '6px',
					borderRadius: '50%',
					background: config.dot,
					flexShrink: 0,
				} }
			/>
			{ config.label }
		</span>
	);
};

const EndpointInfo = ( { settings, onChange, onSave, isSaving, isDirty } ) => {
	const endpoints = useSelect(
		( select ) => select( STORE_NAME ).getEndpoints(),
		[]
	);
	const endpointStatus = useSelect(
		( select ) => select( STORE_NAME ).getEndpointStatus(),
		[]
	);

	const { fetchEndpoints, checkEndpoints } = useDispatch( STORE_NAME );

	useEffect( () => {
		fetchEndpoints();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Fetch once on mount.

	// Probe endpoints as soon as we know the URLs. Runs again if the
	// enabled state changes — toggling adds/removes rewrite targets.
	useEffect( () => {
		if ( endpoints && endpoints.llms_txt ) {
			checkEndpoints();
		}
	}, [ endpoints.llms_txt, settings.enabled ] ); // eslint-disable-line react-hooks/exhaustive-deps -- Stable dispatch.

	const isEnabled = settings.enabled === 'yes';
	const anyUnreachable =
		isEnabled &&
		( endpointStatus.llms_txt === 'unreachable' ||
			endpointStatus.ucp === 'unreachable' ||
			endpointStatus.ucp_api === 'unreachable' ||
			endpointStatus.robots === 'unreachable' );
	const allowedCrawlers =
		settings.allowed_crawlers || KNOWN_CRAWLERS.map( ( c ) => c.id );

	// Rate-limit state. `customOverride` is a local UI flag that lets
	// the merchant see the custom RPM input even when their manually-
	// typed value happens to match a preset — without it, typing `25`
	// into the custom input would collapse back to "Recommended" on
	// the next render and hide the input they were just using.
	const rpm = settings.rate_limit_rpm || 25;
	const [ customOverride, setCustomOverride ] = useState( false );
	const activePreset = customOverride ? 'custom' : getActivePreset( rpm );
	// Tracks the value shown in the Custom card badge independently of which
	// preset is active. Initialised once from settings; only updated when the
	// merchant types in the custom RPM input — not when clicking preset cards.
	const [ customRpm, setCustomRpm ] = useState( () =>
		getActivePreset( rpm ) === null ? rpm : 25
	);

	// Count only crawlers that are actually rendered as checkboxes. Right
	// after a plugin upgrade that rotated AI_CRAWLERS, the stored array
	// can still contain deprecated IDs (stripped on the next save by
	// WC_AI_Storefront_Robots::sanitize_allowed_crawlers), but until
	// then `allowedCrawlers.length` would exceed the visible checkbox
	// count — producing displays like "13 of 12".
	const knownCrawlerIds = KNOWN_CRAWLERS.map( ( c ) => c.id );
	const checkedCount = allowedCrawlers.filter( ( id ) =>
		knownCrawlerIds.includes( id )
	).length;

	const toggleCrawler = ( crawlerId ) => {
		const updated = allowedCrawlers.includes( crawlerId )
			? allowedCrawlers.filter( ( id ) => id !== crawlerId )
			: [ ...allowedCrawlers, crawlerId ];
		onChange( { allowed_crawlers: updated } );
	};

	const selectAll = () => {
		onChange( {
			allowed_crawlers: KNOWN_CRAWLERS.map( ( c ) => c.id ),
		} );
	};

	const clearAll = () => {
		onChange( { allowed_crawlers: [] } );
	};

	return (
		<div className={ ENDPOINT_TAB_CLASS }>
			<TabInputStyles tabClass={ ENDPOINT_TAB_CLASS } />
			<EndpointTabStyles />
			{ /*
			   Section-head block: section h2 names the operator's
			   job at a higher altitude than the cards below. "AI
			   agent access" is intentionally umbrella-level —
			   "Endpoints" is too technical and "Crawlers" is too
			   narrow (the cards inside still use the precise terms
			   where they apply, e.g. the "Training and test
			   crawlers" subgroup name).
			*/ }
			<header style={ { marginBottom: '20px' } }>
				<h2
					style={ {
						margin: '0 0 4px',
						...typography.sectionHeading,
						color: colors.textPrimary,
					} }
				>
					{ __( 'AI agent access', 'woocommerce-ai-storefront' ) }
				</h2>
				<p
					style={ {
						margin: 0,
						color: colors.textSecondary,
						fontSize: '13px',
					} }
				>
					{ __(
						'How AI agents find and interact with your site.',
						'woocommerce-ai-storefront'
					) }
				</p>
			</header>

			<Card>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __(
							'Discovery endpoints',
							'woocommerce-ai-storefront'
						) }
					</h3>
					<p
						style={ {
							color: colors.textSecondary,
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'These endpoints are automatically available when AI Storefront is enabled.',
							'woocommerce-ai-storefront'
						) }
					</p>
					<p
						style={ {
							color: colors.textMuted,
							fontSize: '12px',
							margin: '-8px 0 16px',
						} }
					>
						{ /*
						   Moved up from the toolbar at the bottom of
						   this card per the redesign editorial pass:
						   the reachability scope ("from your
						   browser") is meta-context about the table
						   below, so it reads better when paired with
						   the table's intro than when buried in the
						   re-check toolbar.
						*/ }
						{ __(
							'Reachability is checked from your browser.',
							'woocommerce-ai-storefront'
						) }
					</p>

					{ ! isEnabled && (
						<p
							style={ {
								color: colors.error,
								fontSize: '13px',
								margin: '0 0 16px',
							} }
						>
							{ __(
								'AI Storefront is currently disabled. Enable it in the Overview tab to activate these endpoints.',
								'woocommerce-ai-storefront'
							) }
						</p>
					) }

					<table className="widefat" style={ { margin: 0 } }>
						<thead>
							<tr>
								<th>
									{ __(
										'Endpoint',
										'woocommerce-ai-storefront'
									) }
								</th>
								<th>
									{ __( 'URL', 'woocommerce-ai-storefront' ) }
								</th>
								<th>
									{ __(
										'Status',
										'woocommerce-ai-storefront'
									) }
								</th>
								<th>
									{ __(
										'Purpose',
										'woocommerce-ai-storefront'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>
									<strong>llms.txt</strong>
								</td>
								<td className="endpoint-url-cell">
									{ endpoints.llms_txt ? (
										<ExternalLink
											href={ endpoints.llms_txt }
										>
											{ endpoints.llms_txt }
										</ExternalLink>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									<StatusBadge
										status={ endpointStatus.llms_txt }
									/>
								</td>
								<td>
									{ __(
										'Machine-readable store guide for AI agents',
										'woocommerce-ai-storefront'
									) }
								</td>
							</tr>
							<tr>
								<td>
									<strong>UCP Manifest</strong>
								</td>
								<td className="endpoint-url-cell">
									{ endpoints.ucp ? (
										<ExternalLink href={ endpoints.ucp }>
											{ endpoints.ucp }
										</ExternalLink>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									<StatusBadge
										status={ endpointStatus.ucp }
									/>
								</td>
								<td>
									{ __(
										'Universal Commerce Protocol — declares capabilities',
										'woocommerce-ai-storefront'
									) }
								</td>
							</tr>
							<tr>
								<td>
									<strong>robots.txt</strong>
								</td>
								<td className="endpoint-url-cell">
									{ endpoints.robots ? (
										<ExternalLink href={ endpoints.robots }>
											{ endpoints.robots }
										</ExternalLink>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									<StatusBadge
										status={ endpointStatus.robots }
									/>
								</td>
								<td>
									{ __(
										'AI-crawler allow-list (Allow/Disallow directives appended to your site\u2019s robots.txt)',
										'woocommerce-ai-storefront'
									) }
								</td>
							</tr>
							<tr>
								<td>
									<strong>UCP API</strong>
								</td>
								<td className="endpoint-url-cell">
									{ endpoints.ucp_api ? (
										<ExternalLink
											href={ endpoints.ucp_api }
										>
											{ endpoints.ucp_api }
										</ExternalLink>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									<StatusBadge
										status={ endpointStatus.ucp_api }
									/>
								</td>
								<td>
									{ __(
										'Structured commerce API for AI agents — catalog search, lookup, and checkout sessions',
										'woocommerce-ai-storefront'
									) }
								</td>
							</tr>
						</tbody>
					</table>

					{ /* Recovery hint when any endpoint is unreachable. */ }
					{ anyUnreachable && (
						<p
							style={ {
								marginTop: '12px',
								marginBottom: 0,
								padding: '10px 12px',
								background: colors.surfaceSubtle,
								borderLeft: `3px solid ${ colors.error }`,
								borderRadius: '2px',
								color: colors.textSecondary,
								fontSize: '13px',
							} }
						>
							{ __(
								'One or more endpoints are not reachable. If you just upgraded the plugin, try Settings → Permalinks → Save Changes to flush rewrite rules, then click Re-check.',
								'woocommerce-ai-storefront'
							) }
						</p>
					) }

					<div
						style={ {
							marginTop: '12px',
							display: 'flex',
							justifyContent: 'space-between',
							alignItems: 'center',
						} }
					>
						{ /*
						   Reachability note moved to the card intro
						   (paired with "These endpoints are
						   automatically available..."). The toolbar
						   now hosts only the Re-check button,
						   right-aligned via flex.
						*/ }
						<div />
						<Button
							variant="secondary"
							size="compact"
							onClick={ () => checkEndpoints() }
						>
							{ __( 'Re-check', 'woocommerce-ai-storefront' ) }
						</Button>
					</div>
				</CardBody>
			</Card>

			{ /* Allowed AI agents */ }
			<Card style={ { marginTop: '32px' } }>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __(
							'Allowed AI agents',
							'woocommerce-ai-storefront'
						) }
					</h3>
					<p
						style={ {
							color: colors.textSecondary,
							fontSize: '13px',
							margin: '0 0 8px',
						} }
					>
						{ __(
							'Control which AI agents are allowed to discover your store via robots.txt. Unchecked agents will be blocked from crawling your product pages.',
							'woocommerce-ai-storefront'
						) }
					</p>

					{ /*
						Action toolbar: count pill ("X of Y") + bulk
						Select all / Clear actions, right-aligned above
						the first crawler-category group. The previous
						"Allowed crawlers" left-side label was redundant
						with the card heading "AI Crawler Access" plus
						the eyebrow group titles below ("LIVE BROWSING",
						"TRAINING AND TEST CRAWLERS"), which already
						establish what each row is. Dropping it removed
						an orphan heading; trimmed margins (`<p>` 16→8,
						this div 12→8) eliminate the residual whitespace
						that the old label-bearing row used to occupy.
					*/ }
					<div
						style={ {
							display: 'flex',
							justifyContent: 'flex-end',
							alignItems: 'center',
							marginBottom: '8px',
						} }
					>
						{ /*
							Sighted users see "X of Y" beside the "Allowed
							AI agents" card heading and the eyebrow group
							titles below — the context is visually obvious.
							Screen-reader users hear the pill in isolation
							when scanning the toolbar, so `aria-label` adds
							the missing context ("Allowed AI agents: X of Y")
							that the surrounding visual hierarchy carries for
							sighted users. Mirrors WP core's pattern for
							status pills with unit-implicit numerals.
						*/ }
						<span
							style={ {
								display: 'inline-block',
								background:
									checkedCount > 0
										? colors.successBg
										: colors.surfaceMuted,
								color:
									checkedCount > 0
										? colors.success
										: colors.textMuted,
								fontWeight: checkedCount > 0 ? '600' : '400',
								fontSize: '12px',
								borderRadius: '10px',
								padding: '2px 10px',
								marginRight: '8px',
							} }
							aria-label={ sprintf(
								/* translators: %1$d: number of allowed AI agents, %2$d: total AI agents */
								__(
									'Allowed AI agents: %1$d of %2$d',
									'woocommerce-ai-storefront'
								),
								checkedCount,
								KNOWN_CRAWLERS.length
							) }
						>
							{ sprintf(
								/* translators: %1$d: allowed count, %2$d: total count */
								__(
									'%1$d of %2$d',
									'woocommerce-ai-storefront'
								),
								checkedCount,
								KNOWN_CRAWLERS.length
							) }
						</span>
						<Button
							variant="link"
							style={ {
								fontSize: '12px',
								padding: 0,
								minHeight: 'auto',
							} }
							onClick={ selectAll }
						>
							{ __( 'Select all', 'woocommerce-ai-storefront' ) }
						</Button>
						{ /*
							Wrap the separator in a span so it's an
							explicit flex item with controllable
							spacing — a bare ` | ` text node sandwiched
							between two flex Button children renders
							with whitespace handling that depends on
							the parent's flex behavior, and reads
							inconsistently across browsers / zoom
							levels. The span gives the divider its own
							layout box.
						*/ }
						<span
							style={ {
								padding: '0 6px',
								color: colors.textMuted,
								fontSize: '12px',
							} }
							aria-hidden="true"
						>
							|
						</span>
						<Button
							variant="link"
							style={ {
								fontSize: '12px',
								padding: 0,
								minHeight: 'auto',
							} }
							onClick={ clearAll }
						>
							{ __( 'Clear', 'woocommerce-ai-storefront' ) }
						</Button>
					</div>

					{ /*
						Render the two crawler categories in separate
						visual groups. This makes the merchant's
						decision legible: the top group (live
						browsing) is the revenue-path AI traffic that
						most commerce sites want; the bottom group
						(training + test) is a brand-strategy decision
						where accepting means your catalog becomes
						training data — potentially surfacing stale
						answers months later. See KNOWN_CRAWLERS above
						for category-assignment rationale. Each group
						entry conforms to the CrawlerGroup typedef
						declared above the component.
					*/ }
					{ [
						{
							key: 'general',
							title: __(
								'General-purpose AI assistants',
								'woocommerce-ai-storefront'
							),
							categories: [ 'live' ],
							subgroup: 'general',
							defaultOpen: true,
						},
						{
							key: 'agentic_shopping',
							title: __(
								'Agentic shopping',
								'woocommerce-ai-storefront'
							),
							categories: [ 'live' ],
							subgroup: 'agentic_shopping',
							defaultOpen: true,
						},
						{
							key: 'commerce_search',
							title: __(
								'Commerce search engines',
								'woocommerce-ai-storefront'
							),
							categories: [ 'live' ],
							subgroup: 'commerce_search',
							defaultOpen: true,
						},
						{
							key: 'regional_asia',
							title: __(
								'Regional Asia',
								'woocommerce-ai-storefront'
							),
							categories: [ 'live' ],
							subgroup: 'regional_asia',
							defaultOpen: true,
						},
						{
							key: 'regional_europe',
							title: __(
								'Regional Europe',
								'woocommerce-ai-storefront'
							),
							categories: [ 'live' ],
							subgroup: 'regional_europe',
							defaultOpen: true,
						},
						{
							key: 'training_and_test',
							title: __(
								'Training and test crawlers',
								'woocommerce-ai-storefront'
							),
							categories: [ 'training', 'test' ],
							subgroup: null,
							defaultOpen: false,
						},
					].map( ( group, _idx, allGroups ) => {
						const crawlers = KNOWN_CRAWLERS.filter(
							( c ) =>
								group.categories.includes( c.category ) &&
								( group.subgroup === null ||
									c.subgroup === group.subgroup )
						);
						// Dev-mode safeguard: if a `live` crawler is added
						// with a subgroup that no rendered group claims,
						// it would silently vanish from the merchant UI.
						// Surface it during development so the missing
						// group/subgroup mismatch can be fixed at source.
						if (
							process.env.NODE_ENV !== 'production' &&
							group.key === allGroups[ 0 ].key
						) {
							const knownSubgroups = new Set(
								allGroups
									.filter( ( g ) => g.subgroup !== null )
									.map( ( g ) => g.subgroup )
							);
							const orphans = KNOWN_CRAWLERS.filter(
								( c ) =>
									c.category === 'live' &&
									! knownSubgroups.has( c.subgroup )
							);
							if ( orphans.length > 0 ) {
								// eslint-disable-next-line no-console -- Dev-only orphan warning.
								console.warn(
									'[ai-storefront] Live crawlers with no matching group:',
									orphans.map( ( o ) => o.id )
								);
							}
						}
						if ( crawlers.length === 0 ) {
							return null;
						}
						const allowedCount = crawlers.filter( ( c ) =>
							allowedCrawlers.includes( c.id )
						).length;
						const isZero = allowedCount === 0;
						return (
							<details
								key={ group.key }
								className={ CRAWLER_GROUP_CLASS }
								open={ group.defaultOpen || undefined }
							>
								<summary>
									<span style={ { flex: 1 } }>
										{ group.title }
									</span>
									<span
										style={ {
											display: 'inline-flex',
											alignItems: 'center',
											background: isZero
												? colors.surfaceMuted
												: colors.successBg,
											color: isZero
												? colors.textMuted
												: colors.success,
											fontWeight: isZero ? '400' : '600',
											fontSize: '11px',
											lineHeight: 1,
											padding: '3px 8px',
											borderRadius: radii.pill,
											flexShrink: 0,
										} }
									>
										{ sprintf(
											/* translators: %1$d allowed, %2$d total */
											__(
												'%1$d/%2$d allowed',
												'woocommerce-ai-storefront'
											),
											allowedCount,
											crawlers.length
										) }
									</span>
								</summary>
								<div className="crawler-group-body">
									{ crawlers.map( ( crawler ) => (
										<div
											key={ crawler.id }
											className="crawler-row"
										>
											<CheckboxControl
												label={ crawler.label }
												checked={ allowedCrawlers.includes(
													crawler.id
												) }
												onChange={ () =>
													toggleCrawler( crawler.id )
												}
												__nextHasNoMarginBottom
											/>
										</div>
									) ) }
								</div>
							</details>
						);
					} ) }

					{ /*
						Unknown-agent toggle. Lives inside the same Card
						as the per-brand crawler list because both
						control the same gate at different granularities:
						the list above is per-brand opt-in; this toggle
						is the catch-all for hostnames the server-side
						canonicalizer maps to `OTHER_AI_BUCKET`.

						See `WC_AI_Storefront_UCP_REST_Controller::check_agent_access()`
						for the gate's full rationale (the asymmetry,
						the secure-by-default trade-off, the open-spec
						alternative). Keeping the narrative there avoids
						the four-copies drift surface that would
						otherwise grow as this toggle accumulates
						context over time.
					*/ }
					<div
						style={ {
							marginTop: '20px',
							paddingTop: '16px',
							borderTop: `1px solid ${ colors.borderSubtle }`,
						} }
					>
						<h4
							style={ {
								margin: '0 0 4px',
								fontSize: '13px',
								fontWeight: 600,
								lineHeight: 1.4,
								color: colors.textPrimary,
							} }
						>
							{ __(
								'Other AI agents',
								'woocommerce-ai-storefront'
							) }
						</h4>
						<p
							style={ {
								color: colors.textMuted,
								fontSize: '12px',
								marginTop: 0,
								marginBottom: '8px',
							} }
						>
							{ __(
								'When checked, AI agents whose brand isn\u2019t in the list can access your store.',
								'woocommerce-ai-storefront'
							) }
						</p>
						<CheckboxControl
							label={ __(
								'Allow agents not on the list',
								'woocommerce-ai-storefront'
							) }
							checked={
								settings.allow_unknown_ucp_agents === 'yes'
							}
							onChange={ ( checked ) =>
								onChange( {
									allow_unknown_ucp_agents: checked
										? 'yes'
										: 'no',
								} )
							}
							__nextHasNoMarginBottom
						/>
					</div>
				</CardBody>
			</Card>

			{ /*
				Rate Limits card. Placed after the Crawler Access card
				because the narrative order is "who's allowed → how
				fast they can go" — allow-list decisions read first,
				rate-limit decisions follow. Moved here from the
				Overview tab during the 1.6.7→1.6.8 window on the
				principle that rate limits configure the same external-
				agent traffic surface the allow-list controls.

				Save button used to live inside each card, but both
				saved the full settings blob — identical wiring with
				misleading "per-card" visual framing. Consolidated
				to a single page-level Save footer below this Card
				per WP admin convention (Settings → General,
				Writing, Reading, and every WC Settings tab all use
				one footer save).
			*/ }
			<Card style={ { marginTop: '32px' } }>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __( 'Rate limits', 'woocommerce-ai-storefront' ) }
					</h3>
					<p
						style={ {
							color: colors.textSecondary,
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Control how frequently AI agents can query your store. Higher limits allow faster product discovery but use more server resources.',
							'woocommerce-ai-storefront'
						) }
					</p>

					{ /* 2×2 selectable card grid. Each card shows a title,
					     the RPM value at display weight, and a short
					     description — matching the rate-card spec. Selected
					     card: blue-tint bg + blue border (same treatment as
					     ModeRow in product-selection.js). */ }
					<div
						style={ {
							display: 'grid',
							gridTemplateColumns: 'repeat(2, 1fr)',
							gap: '12px',
						} }
					>
						{ [
							{
								value: 'recommended',
								label: __(
									'Recommended',
									'woocommerce-ai-storefront'
								),
								rpm: '25/min',
								desc: __(
									'Works well for most stores.',
									'woocommerce-ai-storefront'
								),
							},
							{
								value: 'conservative',
								label: __(
									'Conservative',
									'woocommerce-ai-storefront'
								),
								rpm: '10/min',
								desc: __(
									'Shared hosting or low-traffic stores.',
									'woocommerce-ai-storefront'
								),
							},
							{
								value: 'generous',
								label: __(
									'Generous',
									'woocommerce-ai-storefront'
								),
								rpm: '100/min',
								desc: __(
									'High-traffic stores on dedicated hosting.',
									'woocommerce-ai-storefront'
								),
							},
							{
								value: 'custom',
								label: __(
									'Custom',
									'woocommerce-ai-storefront'
								),
								rpm:
									activePreset === 'custom'
										? `${ customRpm }/min`
										: 'x/min',
								desc: __(
									'Set your own requests-per-minute cap.',
									'woocommerce-ai-storefront'
								),
							},
						].map( ( card ) => {
							const isSelected = activePreset === card.value;
							return (
								<button
									key={ card.value }
									type="button"
									onClick={ () => {
										if (
											RATE_LIMIT_PRESETS[ card.value ]
										) {
											setCustomOverride( false );
											onChange( {
												rate_limit_rpm:
													RATE_LIMIT_PRESETS[
														card.value
													].rpm,
											} );
										} else {
											setCustomOverride( true );
										}
									} }
									style={ {
										textAlign: 'left',
										cursor: 'pointer',
										border: `1px solid ${
											isSelected
												? colors.accent
												: colors.borderSubtle
										}`,
										borderRadius: radii.sm,
										background: isSelected
											? colors.infoBg
											: colors.surface,
										padding: '16px',
									} }
								>
									<div
										style={ {
											fontSize: '13px',
											fontWeight: '600',
											color: colors.textPrimary,
											marginBottom: '4px',
										} }
									>
										{ card.label }
									</div>
									<div
										style={ {
											...typography.statValue,
											color: colors.textPrimary,
											marginBottom: '6px',
										} }
									>
										{ card.rpm }
									</div>
									<p
										style={ {
											margin: 0,
											fontSize: '13px',
											color: colors.textMuted,
										} }
									>
										{ card.desc }
									</p>
								</button>
							);
						} ) }
						{ /* Spacer occupies col 1; input anchors to col 2 —
						     directly below the Custom card. */ }
						{ activePreset === 'custom' && (
							<div aria-hidden="true" />
						) }
						{ activePreset === 'custom' && (
							<div
								style={ {
									paddingTop: spacing.s3,
									borderTop: `1px solid ${ colors.borderSubtle }`,
									display: 'flex',
									alignItems: 'center',
									gap: spacing.s2,
								} }
							>
								<TextControl
									__nextHasNoMarginBottom
									id="wc-ai-storefront-rpm"
									hideLabelFromVision
									label={ __(
										'Requests per minute',
										'woocommerce-ai-storefront'
									) }
									type="number"
									value={ customRpm }
									onChange={ ( value ) => {
										const parsed = parseInt( value, 10 );
										setCustomRpm(
											isNaN( parsed ) ? '' : parsed
										);
										if (
											! isNaN( parsed ) &&
											parsed >= 1
										) {
											onChange( {
												rate_limit_rpm: parsed,
											} );
										}
									} }
									min={ 1 }
									max={ 1000 }
									style={ { width: '96px' } }
								/>
								<span
									style={ {
										fontSize: '13px',
										color: colors.textMuted,
										whiteSpace: 'nowrap',
									} }
									aria-hidden="true"
								>
									{ __( '/ min', 'woocommerce-ai-storefront' ) }
								</span>
							</div>
						) }
					</div>

					{ /*
					   Pre-emptive footer "Limits are applied per AI
					   crawler (identified by user-agent string). Your
					   regular store traffic is not affected." dropped
					   in the redesign:
					   - The card intro already establishes this is
					     about AI agents, so non-AI traffic exclusion
					     is implicit.
					   - The per-crawler-vs-aggregate detail is
					     engineering-level (most merchants don't ask).
					   - The user-agent-string parenthetical is
					     jargon.
					   If support tickets surface confusion about
					   per-bot limits, address it via a "?" tooltip
					   on the rate-limit cards or a USER-GUIDE.md
					   entry.
					*/ }
				</CardBody>
			</Card>

			{ /*
				Page-level Save footer. Consolidates what used to be
				two per-card "Save Changes" buttons (one inside
				Crawler Access, one inside Rate Limits) — both posted
				the full settings blob, so per-card buttons were
				misleading about scope. Matches the WP admin
				convention used on every native Settings tab and
				WC Settings tab: one save, at the bottom, labeled
				generically.
			*/ }
			{ /*
				`textAlign: 'end'` instead of `'right'` so the Save
				button sits on the visual end side under any writing
				direction — the right edge in LTR, the left edge in
				RTL (Arabic, Hebrew, Persian, Urdu). The CSS logical
				property tracks `direction` automatically; the
				physical-property form does not. The Product Visibility
				footer (`product-selection.js`) ships the same value in
				this PR; the Policies footer follows in PR #102.
			*/ }
			<div
				style={ {
					marginTop: '24px',
					textAlign: 'end',
				} }
			>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving || ! isDirty }
					onClick={ onSave }
				>
					{ isSaving
						? __( 'Saving…', 'woocommerce-ai-storefront' )
						: __( 'Save changes', 'woocommerce-ai-storefront' ) }
				</Button>
			</div>
		</div>
	);
};

export default EndpointInfo;
