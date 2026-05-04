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
import { __, sprintf, _n } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-storefront/constants';
import { colors, typography, radii, spacing } from './tokens';
import { TabInputStyles } from './tab-input-styles';

const ENDPOINT_TAB_CLASS = 'ai-storefront-endpoint-tab';

const CRAWLER_GROUP_CLASS = 'ai-storefront-crawler-group';

/**
 * Maps a rollup interval slug to a human-readable "Updated X." subtitle.
 *
 * Exported for unit testing. The interval comes from the `rollup_interval`
 * field on the /crawl-stats API response, which reflects the effective value
 * of the `wc_ai_storefront_rollup_interval` filter on the server.
 *
 * @param {string|undefined} interval The interval slug from the API response.
 * @return {string} Localised subtitle string, always ending with a period.
 */
export function getRollupIntervalLabel( interval ) {
	const labels = {
		hourly: __( 'Updated hourly.', 'woocommerce-ai-storefront' ),
		twicedaily: __(
			'Updated every 12 hours.',
			'woocommerce-ai-storefront'
		),
		daily: __( 'Updated daily.', 'woocommerce-ai-storefront' ),
	};
	return (
		labels[ interval ] ??
		__( 'Updated periodically.', 'woocommerce-ai-storefront' )
	);
}

/**
 * Returns true when the no-activity empty state should render.
 *
 * Exported for unit testing. The three conditions guard against showing
 * "no activity" while data already exists but hasn't been rolled up yet:
 *
 *  1. total_requests — from the summary table; zero before the first rollup.
 *  2. top_queries    — from the raw log (search events only); zero when there
 *                      are no search queries yet.
 *  3. raw_event_count — total raw-log rows in the period; non-zero even for
 *                       llms.txt/UCP/product-page hits that haven't rolled up.
 *
 * We only show the empty state when all three are zero (or absent), so a
 * fresh install that already has raw traffic doesn't falsely claim no activity.
 *
 * @param {Object}  crawlStats      API response object (may be empty object).
 * @param {boolean} isLoading       True while the API request is in-flight.
 * @param {*}       crawlStatsError Truthy when the API call failed.
 * @return {boolean} Whether the empty-state message should be shown.
 */
export function shouldShowCrawlStatsEmptyState(
	crawlStats,
	isLoading,
	crawlStatsError
) {
	return (
		! crawlStatsError &&
		! isLoading &&
		crawlStats?.total_requests === 0 &&
		( ! crawlStats?.top_queries || crawlStats.top_queries.length === 0 ) &&
		! crawlStats?.raw_event_count
	);
}

// localStorage key for persisted crawler-group collapse/expand state.
// Stored value is JSON: `{ [groupKey]: boolean }`. Groups missing from
// the stored object fall back to their `defaultOpen` flag, so adding a
// new group doesn't disturb existing merchants' choices.
const EXPANDED_GROUPS_STORAGE_KEY =
	'wc_ai_storefront_discovery_expanded_groups';

/**
 * Setter for `useExpandedGroups`.
 *
 * Updates the in-memory state for one group and writes the merged
 * record through to localStorage. No-op when the new value matches
 * the current value for that key.
 *
 * @callback SetGroupExpanded
 * @param {string}  key    Stable group key (matches `CRAWLER_GROUPS[].key`).
 * @param {boolean} isOpen Whether the group is now expanded.
 * @return {void}
 */

/**
 * Persisted open/closed state for the Discovery tab's crawler groups.
 *
 * Returns `[expanded, setGroupExpanded]` where `expanded` is a record
 * keyed on the group's stable `key` and `setGroupExpanded( key, isOpen )`
 * updates state and writes through to localStorage.
 *
 * Why localStorage and not the settings store: collapse/expand of
 * read-only informational groups is UI memory, not configuration.
 * Persisting it via the settings POST would write to the database on
 * every click (or require a debounce + dirty-aware save flow), and it
 * would entangle UI prefs with the configuration model that translators
 * and the REST API surface care about. localStorage is the right scope:
 * survives navigation, page reload, and save-triggered re-renders;
 * doesn't sync cross-device, which is fine for this kind of state.
 *
 * Why lazy `useState` initializer: reading localStorage on first render
 * means the very first paint already reflects the merchant's prior
 * choices. A `useEffect` read would render with defaults first, then
 * flip on hydrate — visible flash on every visit.
 *
 * Note: `groups` is captured only by the lazy initializer. The defaults
 * snapshot is frozen at mount; if the group list is ever swapped to a
 * dynamic array (feature-flag groups, etc.), new entries won't appear
 * in `expanded` until remount. Today `CRAWLER_GROUPS` is a
 * module-level constant so this is theoretical.
 *
 * Failure modes: localStorage access can throw in private-browsing
 * modes on some browsers, and the JSON in storage may be malformed if
 * something else has tampered with it. Both branches fall through to
 * "use defaults," so the feature degrades to the pre-fix behavior
 * rather than throwing into the merchant's UI.
 *
 * @param {Array<{ key: string, defaultOpen: boolean }>} groups Group
 *                                                              definitions; only `key` and `defaultOpen` are read.
 * @return {[Object<string, boolean>, SetGroupExpanded]} A pair of
 *     `[expanded, setGroupExpanded]` — `expanded` maps each group's
 *     `key` to its current open state; `setGroupExpanded` updates one
 *     entry and persists the merged record to localStorage.
 */
const useExpandedGroups = ( groups ) => {
	const [ expanded, setExpanded ] = useState( () => {
		const defaults = Object.fromEntries(
			groups.map( ( g ) => [ g.key, !! g.defaultOpen ] )
		);
		if ( typeof window === 'undefined' ) {
			return defaults;
		}
		try {
			const stored = window.localStorage.getItem(
				EXPANDED_GROUPS_STORAGE_KEY
			);
			if ( ! stored ) {
				return defaults;
			}
			const parsed = JSON.parse( stored );
			if ( parsed && typeof parsed === 'object' ) {
				// Merge stored state on top of defaults. Groups absent
				// from storage keep their `defaultOpen`; groups present
				// in storage with non-boolean values fall back to default.
				// Keys that aren't in `defaults` (i.e. stored from a
				// prior version that included a group we've since
				// removed) are ignored — they'll be pruned on the next
				// setter call when we re-stringify `next`, since `next`
				// is built from the current defaults.
				const merged = { ...defaults };
				for ( const [ key, value ] of Object.entries( parsed ) ) {
					if (
						typeof value === 'boolean' &&
						Object.prototype.hasOwnProperty.call( defaults, key )
					) {
						merged[ key ] = value;
					}
				}
				return merged;
			}
			return defaults;
		} catch ( _err ) {
			return defaults;
		}
	} );

	const setGroupExpanded = ( key, isOpen ) => {
		setExpanded( ( prev ) => {
			if ( prev[ key ] === isOpen ) {
				return prev;
			}
			const next = { ...prev, [ key ]: isOpen };
			if ( typeof window !== 'undefined' ) {
				try {
					window.localStorage.setItem(
						EXPANDED_GROUPS_STORAGE_KEY,
						JSON.stringify( next )
					);
				} catch ( _err ) {
					// Quota exceeded, private-browsing block, etc.
					// State still updates in memory for the session.
				}
			}
			return next;
		} );
	};

	return [ expanded, setGroupExpanded ];
};

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
			@media (max-width: 600px) {
				.${ ENDPOINT_TAB_CLASS } table.widefat thead {
					position: absolute;
					width: 1px;
					height: 1px;
					padding: 0;
					margin: -1px;
					overflow: hidden;
					clip: rect(0, 0, 0, 0);
					white-space: nowrap;
					border: 0;
				}
				.${ ENDPOINT_TAB_CLASS } table.widefat tbody tr {
					display: grid;
					grid-template-columns: 1fr auto;
					grid-template-rows: auto auto;
					gap: 4px 8px;
					padding: 10px 12px;
					border-bottom: 1px solid ${ colors.borderSubtle };
				}
				.${ ENDPOINT_TAB_CLASS } table.widefat td {
					display: block;
					padding: 0;
					border: none;
				}
				.${ ENDPOINT_TAB_CLASS } table.widefat td:nth-child(1) {
					grid-column: 1; grid-row: 1;
					align-self: center;
				}
				.${ ENDPOINT_TAB_CLASS } table.widefat td:nth-child(2) {
					grid-column: 1 / -1; grid-row: 2;
					overflow: hidden;
					text-overflow: ellipsis;
					white-space: nowrap;
					max-width: 100%;
				}
				.${ ENDPOINT_TAB_CLASS } table.widefat td:nth-child(2) a {
					font-size: 12px !important;
				}
				.${ ENDPOINT_TAB_CLASS } table.widefat td:nth-child(3) {
					grid-column: 2; grid-row: 1;
					align-self: center;
					justify-self: end;
				}
				.${ ENDPOINT_TAB_CLASS } table.widefat td:nth-child(4) { display: none; }
			}
			details.ai-storefront-top-searches summary { list-style: none; }
			details.ai-storefront-top-searches summary::-webkit-details-marker { display: none; }
			details.ai-storefront-top-searches summary .top-searches-chevron {
				margin-left: auto;
				font-size: 14px;
				line-height: 1;
				display: inline-block;
				transition: transform .15s;
				transform: rotate(-90deg);
			}
			details.ai-storefront-top-searches[open] summary .top-searches-chevron {
				transform: rotate(0deg);
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
 * Strip hostname from a URL, returning only the path and query string.
 *
 * @param {string} url Full URL to shorten.
 * @return {string}    Path + query string, or the original string if not a valid URL.
 */
const urlPath = ( url ) => {
	try {
		const { pathname, search } = new URL( url );
		return pathname + search;
	} catch ( _e ) {
		return url;
	}
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
		id: 'Mistralai-User',
		label: 'Mistralai-User (Mistral AI)',
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
	{
		id: 'YouBot',
		label: 'YouBot (You.com)',
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
		id: 'anthropic-ai',
		label: 'anthropic-ai (Anthropic legacy)',
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
		id: 'Diffbot',
		label: 'Diffbot (Knowledge Graph for AI)',
		category: 'training',
	},
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

// Crawler-group definitions for the Discovery tab's collapsible chrome.
// Lifted out of the JSX render block so the same list can feed both
// the rendered `<details>` elements and the `useExpandedGroups` hook
// (the hook needs the `key` + `defaultOpen` pairs at the top of the
// component; constructing a fresh array inside the render and passing
// it would create a new identity each render, useless as a dependency).
//
// Adding a new group: append here and ensure the `subgroup` matches
// what `KNOWN_CRAWLERS` declares; the dev-mode orphan check below
// surfaces mismatches.
const CRAWLER_GROUPS = [
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
		title: __( 'Agentic shopping', 'woocommerce-ai-storefront' ),
		categories: [ 'live' ],
		subgroup: 'agentic_shopping',
		defaultOpen: true,
	},
	{
		key: 'commerce_search',
		title: __( 'Commerce search engines', 'woocommerce-ai-storefront' ),
		categories: [ 'live' ],
		subgroup: 'commerce_search',
		defaultOpen: true,
	},
	{
		key: 'regional_asia',
		title: __( 'Regional Asia', 'woocommerce-ai-storefront' ),
		categories: [ 'live' ],
		subgroup: 'regional_asia',
		defaultOpen: true,
	},
	{
		key: 'regional_europe',
		title: __( 'Regional Europe', 'woocommerce-ai-storefront' ),
		categories: [ 'live' ],
		subgroup: 'regional_europe',
		defaultOpen: true,
	},
	{
		key: 'training_and_test',
		title: __( 'Training and test crawlers', 'woocommerce-ai-storefront' ),
		categories: [ 'training', 'test' ],
		subgroup: null,
		defaultOpen: false,
	},
];

const CRAWL_PERIODS = [
	{ value: 'day', label: __( 'Day', 'woocommerce-ai-storefront' ) },
	{ value: 'week', label: __( '7 days', 'woocommerce-ai-storefront' ) },
	{
		value: 'month',
		label: __( '30 days', 'woocommerce-ai-storefront' ),
	},
	{
		value: 'quarter',
		label: __( '90 days', 'woocommerce-ai-storefront' ),
	},
];

/**
 * Single stat tile used inside CrawlerActivityCard.
 *
 * @param {Object}        root0
 * @param {string}        root0.label Descriptive label below the value.
 * @param {string|number} root0.value Big number / formatted value to display.
 * @param {string}        [root0.sub] Optional small sub-line (e.g. "updated daily").
 */
const StatTile = ( { label, value, sub } ) => (
	<div
		style={ {
			textAlign: 'center',
			padding: `${ spacing.s4 } ${ spacing.s3 }`,
		} }
	>
		<div
			style={ {
				...typography.statValue,
				color: colors.textPrimary,
				lineHeight: 1,
				marginBottom: '4px',
			} }
		>
			{ value }
		</div>
		<div
			style={ {
				fontSize: '12px',
				color: colors.textSecondary,
				lineHeight: 1.3,
			} }
		>
			{ label }
		</div>
		{ sub && (
			<div
				style={ {
					fontSize: '11px',
					color: colors.textMuted,
					marginTop: '2px',
				} }
			>
				{ sub }
			</div>
		) }
	</div>
);

/**
 * AI agent activity stat card for the Discovery tab.
 *
 * Shows aggregate visibility stats from the daily crawl summary table.
 * Data reflects activity up to end of yesterday (nightly cron rollup).
 * A period selector lets the merchant explore different trailing windows.
 */
const CrawlerActivityCard = () => {
	const crawlStats = useSelect(
		( select ) => select( STORE_NAME ).getCrawlStats(),
		[]
	);
	const crawlStatsError = useSelect(
		( select ) => select( STORE_NAME ).getCrawlStatsError(),
		[]
	);
	const { fetchCrawlStats } = useDispatch( STORE_NAME );

	const [ period, setPeriod ] = useState( 'month' );

	useEffect( () => {
		fetchCrawlStats( period );
	}, [ period ] ); // eslint-disable-line react-hooks/exhaustive-deps -- Stable dispatch.

	const isLoading =
		! crawlStatsError &&
		( crawlStats === null || crawlStats.period !== period );

	const fmt = ( n ) => new Intl.NumberFormat().format( n );

	return (
		<Card style={ { marginTop: '32px' } }>
			<CardBody>
				{ /* Card header + period chips */ }
				<div
					style={ {
						display: 'flex',
						justifyContent: 'space-between',
						alignItems: 'flex-start',
						marginBottom: '16px',
						flexWrap: 'wrap',
						gap: spacing.s2,
					} }
				>
					<div>
						<h3 style={ { margin: '0 0 4px', fontSize: '14px' } }>
							{ __(
								'AI agent activity',
								'woocommerce-ai-storefront'
							) }
						</h3>
						{ ! crawlStatsError &&
							! isLoading &&
							crawlStats.total_requests > 0 && (
								<p
									style={ {
										margin: 0,
										fontSize: '12px',
										color: colors.textMuted,
									} }
								>
									{ getRollupIntervalLabel(
										crawlStats.rollup_interval
									) }
								</p>
							) }
					</div>
					{ /* Period chip strip */ }
					<div
						role="radiogroup"
						style={ {
							display: 'flex',
							gap: '4px',
							flexWrap: 'wrap',
						} }
					>
						{ CRAWL_PERIODS.map( ( p ) => {
							const isActive = p.value === period;
							return (
								<button
									key={ p.value }
									role="radio"
									aria-checked={ isActive }
									type="button"
									onClick={ () => setPeriod( p.value ) }
									style={ {
										cursor: 'pointer',
										padding: '3px 10px',
										fontSize: '12px',
										fontWeight: isActive ? '600' : '400',
										borderRadius: radii.pill,
										border: `1px solid ${
											isActive
												? colors.accent
												: colors.borderSubtle
										}`,
										background: isActive
											? colors.infoBg
											: colors.surface,
										color: isActive
											? colors.accent
											: colors.textSecondary,
									} }
								>
									{ p.label }
								</button>
							);
						} ) }
					</div>
				</div>

				{ /* Stat grid */ }
				{ crawlStatsError && (
					<p
						style={ {
							color: colors.error,
							textAlign: 'center',
							padding: `${ spacing.s4 } 0`,
						} }
					>
						{ __(
							'Could not load crawler stats. Please refresh the page.',
							'woocommerce-ai-storefront'
						) }
					</p>
				) }
				{ ! crawlStatsError && isLoading && (
					<div
						style={ {
							textAlign: 'center',
							padding: `${ spacing.s4 } 0`,
						} }
					>
						<Spinner />
					</div>
				) }
				{ ! crawlStatsError && ! isLoading && (
					<div
						style={ {
							display: 'grid',
							gridTemplateColumns:
								'repeat(auto-fit, minmax(100px, 1fr))',
							gap: '1px',
							background: colors.borderSubtle,
							borderRadius: radii.sm,
							overflow: 'hidden',
							border: `1px solid ${ colors.borderSubtle }`,
						} }
					>
						<div style={ { background: colors.surface } }>
							<StatTile
								label={ __(
									'Catalog queries',
									'woocommerce-ai-storefront'
								) }
								value={ fmt( crawlStats.store_api_queries ) }
							/>
						</div>
						<div style={ { background: colors.surface } }>
							<StatTile
								label={ __(
									'llms.txt hits',
									'woocommerce-ai-storefront'
								) }
								value={ fmt( crawlStats.llms_txt_hits ) }
							/>
						</div>
						<div style={ { background: colors.surface } }>
							<StatTile
								label={ __(
									'UCP manifest hits',
									'woocommerce-ai-storefront'
								) }
								value={ fmt( crawlStats.ucp_hits ) }
							/>
						</div>
						<div style={ { background: colors.surface } }>
							<StatTile
								label={ __(
									'Throttle rate',
									'woocommerce-ai-storefront'
								) }
								value={
									crawlStats.total_requests > 0
										? crawlStats.throttle_rate + '%'
										: '—'
								}
								sub={
									crawlStats.throttle_count > 0
										? sprintf(
												/* translators: %d: number of throttled requests */
												_n(
													'%d request throttled',
													'%d requests throttled',
													crawlStats.throttle_count,
													'woocommerce-ai-storefront'
												),
												crawlStats.throttle_count
										  )
										: undefined
								}
							/>
						</div>
					</div>
				) }

				{ /* By-agent breakdown — only shown when there's data */ }
				{ ! crawlStatsError &&
					! isLoading &&
					crawlStats.by_agent &&
					crawlStats.by_agent.length > 0 && (
						<div
							style={ {
								marginTop: '16px',
								paddingTop: '12px',
								borderTop: `1px solid ${ colors.borderSubtle }`,
							} }
						>
							<p
								style={ {
									margin: '0 0 8px',
									fontSize: '12px',
									fontWeight: '600',
									color: colors.textSecondary,
									textTransform: 'uppercase',
									letterSpacing: '0.05em',
								} }
							>
								{ __(
									'By AI agent',
									'woocommerce-ai-storefront'
								) }
							</p>
							<div
								style={ {
									display: 'flex',
									flexWrap: 'wrap',
									gap: '6px',
								} }
							>
								{ crawlStats.by_agent.map( ( entry ) => (
									<span
										key={ entry.agent }
										style={ {
											display: 'inline-flex',
											alignItems: 'center',
											gap: '4px',
											fontSize: '12px',
											background: colors.surfaceMuted,
											color: colors.textSecondary,
											borderRadius: radii.pill,
											padding: '3px 10px',
										} }
									>
										<strong
											style={ {
												color: colors.textPrimary,
											} }
										>
											{ entry.agent }
										</strong>
										<span
											style={ {
												color: colors.textMuted,
											} }
										>
											{ fmt( entry.requests ) }
										</span>
									</span>
								) ) }
							</div>
						</div>
					) }

				{ /* Top search queries — always shown after load so merchants
				     see the feature before any bot traffic arrives.
				     Populated: collapsible <details> open by default.
				     Empty: ghost rows + one-line copy (aria-hidden on
				     skeleton, same rationale as the orders GhostTable). */ }
				{ ! crawlStatsError && ! isLoading && (
					<div
						style={ {
							marginTop: '16px',
							paddingTop: '12px',
							borderTop: `1px solid ${ colors.borderSubtle }`,
						} }
					>
						{ crawlStats.top_queries &&
						crawlStats.top_queries.length > 0 ? (
							<details
								open
								className="ai-storefront-top-searches"
							>
								<summary
									style={ {
										listStyle: 'none',
										cursor: 'pointer',
										display: 'flex',
										alignItems: 'center',
										gap: '6px',
										margin: '0 0 8px',
										fontSize: '12px',
										fontWeight: '600',
										color: colors.textSecondary,
										textTransform: 'uppercase',
										letterSpacing: '0.05em',
										userSelect: 'none',
									} }
								>
									{ sprintf(
										/* translators: %d: number of unique search queries */
										_n(
											'Top searches (%d)',
											'Top searches (%d)',
											crawlStats.top_queries.length,
											'woocommerce-ai-storefront'
										),
										crawlStats.top_queries.length
									) }
									{ /* When the requested period exceeds the
									     raw-log retention window (currently 30
									     days), the API clamps the top-searches
									     lookback. Surface the effective window
									     so merchants don't see "90 days" on the
									     chip strip but get only the last 30 in
									     the search list. */ }
									{ typeof crawlStats.top_queries_window_days ===
										'number' &&
										crawlStats.top_queries_window_days <
											( {
												day: 1,
												week: 7,
												month: 30,
												quarter: 90,
											}[ period ] ?? 0 ) && (
											<span
												style={ {
													fontWeight: 'normal',
													textTransform: 'none',
													letterSpacing: 'normal',
													color: colors.textMuted,
												} }
											>
												{ sprintf(
													/* translators: %d: number of days of search history available. */
													__(
														'last %d days',
														'woocommerce-ai-storefront'
													),
													crawlStats.top_queries_window_days
												) }
											</span>
										) }
									<span
										className="top-searches-chevron"
										aria-hidden="true"
									>
										{ '▾' }
									</span>
								</summary>
								{ /* 5×2 desktop grid, 10×1 mobile stack.
								     JS split gives column-major order (ranks 1–5
								     left, 6–10 right). CSS auto-fit collapses to
								     one column when the container is < 520px. */ }
								{ ( () => {
									const queries = crawlStats.top_queries;
									const half = Math.ceil(
										queries.length / 2
									);
									const cols = [
										queries.slice( 0, half ),
										queries.slice( half ),
									].filter( ( col ) => col.length > 0 );
									return (
										<div
											style={ {
												display: 'grid',
												gridTemplateColumns:
													'repeat(auto-fit, minmax(260px, 1fr))',
												columnGap: '20px',
												alignItems: 'start',
											} }
										>
											{ cols.map( ( col, colIdx ) => (
												<div
													key={ colIdx }
													style={ {
														display: 'flex',
														flexDirection: 'column',
														gap: '4px',
													} }
												>
													{ col.map(
														( entry, rowIdx ) => {
															const rank =
																colIdx * half +
																rowIdx +
																1;
															return (
																<div
																	key={
																		entry.query
																	}
																	style={ {
																		display:
																			'grid',
																		gridTemplateColumns:
																			'20px 1fr auto',
																		alignItems:
																			'center',
																		gap: '8px',
																		padding:
																			'5px 8px',
																		background:
																			colors.surfaceSubtle,
																		borderRadius:
																			radii.sm,
																	} }
																>
																	<span
																		style={ {
																			fontSize:
																				'12px',
																			color: colors.textMuted,
																			fontVariantNumeric:
																				'tabular-nums',
																			textAlign:
																				'right',
																		} }
																	>
																		{ rank }
																	</span>
																	<div
																		style={ {
																			minWidth: 0,
																		} }
																	>
																		<div
																			style={ {
																				fontSize:
																					'13px',
																				color: colors.textPrimary,
																				overflow:
																					'hidden',
																				textOverflow:
																					'ellipsis',
																				whiteSpace:
																					'nowrap',
																			} }
																			title={
																				entry.query
																			}
																		>
																			{
																				entry.query
																			}
																		</div>
																		{ entry
																			.agents
																			.length >
																			0 && (
																			<div
																				style={ {
																					fontSize:
																						'11px',
																					color: colors.textMuted,
																					overflow:
																						'hidden',
																					textOverflow:
																						'ellipsis',
																					whiteSpace:
																						'nowrap',
																					marginTop:
																						'2px',
																				} }
																			>
																				{ [
																					...entry.agents.slice(
																						0,
																						3
																					),
																					...( entry
																						.agents
																						.length >
																					3
																						? [
																								`+${
																									entry
																										.agents
																										.length -
																									3
																								}`,
																						  ]
																						: [] ),
																				].join(
																					' · '
																				) }
																			</div>
																		) }
																	</div>
																	<span
																		style={ {
																			fontSize:
																				'13px',
																			fontWeight:
																				'600',
																			color: colors.textPrimary,
																			fontVariantNumeric:
																				'tabular-nums',
																			textAlign:
																				'right',
																			whiteSpace:
																				'nowrap',
																		} }
																	>
																		{ fmt(
																			entry.count
																		) }
																	</span>
																</div>
															);
														}
													) }
												</div>
											) ) }
										</div>
									);
								} )() }
							</details>
						) : (
							<>
								<p
									style={ {
										margin: '0 0 8px',
										fontSize: '12px',
										fontWeight: '600',
										color: colors.textSecondary,
										textTransform: 'uppercase',
										letterSpacing: '0.05em',
										display: 'flex',
										alignItems: 'center',
										gap: '6px',
									} }
								>
									{ __(
										'Top searches',
										'woocommerce-ai-storefront'
									) }
									{ typeof crawlStats.top_queries_window_days ===
										'number' &&
										crawlStats.top_queries_window_days <
											( {
												day: 1,
												week: 7,
												month: 30,
												quarter: 90,
											}[ period ] ?? 0 ) && (
											<span
												style={ {
													fontWeight: 'normal',
													textTransform: 'none',
													letterSpacing: 'normal',
													color: colors.textMuted,
												} }
											>
												{ sprintf(
													/* translators: %d: number of days of search history available. */
													__(
														'last %d days',
														'woocommerce-ai-storefront'
													),
													crawlStats.top_queries_window_days
												) }
											</span>
										) }
								</p>
								{ /* Ghost rows — aria-hidden, purely visual preview */ }
								<div
									aria-hidden="true"
									style={ {
										display: 'flex',
										flexDirection: 'column',
										gap: '4px',
										opacity: 0.4,
									} }
								>
									{ [ '75%', '55%', '40%' ].map( ( w ) => (
										<div
											key={ w }
											style={ {
												display: 'flex',
												justifyContent: 'space-between',
												alignItems: 'center',
												padding: '5px 8px',
												background:
													colors.surfaceSubtle,
												borderRadius: radii.sm,
												gap: '8px',
											} }
										>
											<div
												style={ {
													height: '13px',
													width: w,
													background:
														colors.surfaceMuted,
													borderRadius: '3px',
												} }
											/>
											<div
												style={ {
													height: '13px',
													width: '32px',
													background:
														colors.surfaceMuted,
													borderRadius: '3px',
													flexShrink: 0,
												} }
											/>
										</div>
									) ) }
								</div>
								<p
									style={ {
										position: 'absolute',
										width: '1px',
										height: '1px',
										padding: 0,
										margin: '-1px',
										overflow: 'hidden',
										clip: 'rect(0,0,0,0)',
										whiteSpace: 'nowrap',
										border: 0,
									} }
								>
									{ __(
										'Search queries from AI agents will appear here.',
										'woocommerce-ai-storefront'
									) }
								</p>
							</>
						) }
					</div>
				) }

				{ /* Empty state — shown when no requests at all for the period
				     AND no top searches in the raw log. The latter check
				     matters because top_queries reads from the raw log
				     directly, while total_requests comes from the summary
				     table which is updated on the rollup cadence. Within the
				     gap (e.g. between rollup runs on a fresh install), the
				     raw log can have rows that the summary doesn't yet, so
				     this check prevents the contradictory state of showing
				     "No AI agent activity recorded…" while the Top searches
				     panel above is rendering real query terms. */ }
				{ shouldShowCrawlStatsEmptyState(
					crawlStats,
					isLoading,
					crawlStatsError
				) && (
					<p
						style={ {
							marginTop: '16px',
							marginBottom: 0,
							fontSize: '13px',
							color: colors.textMuted,
							textAlign: 'center',
						} }
					>
						{ __(
							'No AI agent activity recorded for this period. Stats appear here after the first AI agent visits your store.',
							'woocommerce-ai-storefront'
						) }
					</p>
				) }
			</CardBody>
		</Card>
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
	// Persisted collapse/expand state for the crawler-group `<details>`
	// elements rendered below. Reads from localStorage on first render
	// (lazy initializer in useExpandedGroups) so the very first paint
	// already reflects the merchant's prior choices — no flash from
	// defaults to stored state. Writes through on every toggle.
	const [ expandedGroups, setGroupExpanded ] =
		useExpandedGroups( CRAWLER_GROUPS );

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
		getActivePreset( rpm ) === 'custom' ? rpm : 25
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
											{ urlPath( endpoints.llms_txt ) }
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
											{ urlPath( endpoints.ucp ) }
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
											{ urlPath( endpoints.robots ) }
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
											{ urlPath( endpoints.ucp_api ) }
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

			<CrawlerActivityCard />

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
							'Control which AI agents are allowed to discover your store via robots.txt.',
							'woocommerce-ai-storefront'
						) }
					</p>

					{ /*
						Boundary note: clarifies what this list covers
						(AI-specific crawlers) and what it doesn't
						(general-purpose search engines like Google,
						Bing, Yandex, etc.), so merchants without an SEO
						plugin don't read the absence of Googlebot here
						as "I haven't allowed Googlebot." General SEO
						bots are allowed by default via WordPress core's
						`User-agent: *` block and managed by SEO plugins
						(Yoast, Rank Math, AIOSEO) where applicable.
						Styled as helper text (muted color, 12px) rather
						than primary copy (13px) so it reads as secondary
						context to the intro paragraph above rather than
						a competing instruction. See issue #268.
					*/ }
					<p
						style={ {
							color: colors.textMuted,
							fontSize: '12px',
							margin: '0 0 12px',
							lineHeight: '1.5',
						} }
					>
						{ __(
							'This list controls AI-specific crawlers (ChatGPT, Claude, Perplexity, Gemini, etc.). General-purpose search engines like Google and Bing are managed by WordPress core and your SEO plugin (if any) — adjust those in your SEO plugin settings or robots.txt directly.',
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
					{ CRAWLER_GROUPS.map( ( group, _idx, allGroups ) => {
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
								open={ expandedGroups[ group.key ] }
								onToggle={ ( e ) =>
									setGroupExpanded(
										group.key,
										e.currentTarget.open
									)
								}
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
										? `${ customRpm || 'x' }/min`
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
											marginBottom: '4px',
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
									{ __(
										'/ min',
										'woocommerce-ai-storefront'
									) }
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
