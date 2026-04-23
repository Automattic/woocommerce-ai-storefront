import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	Button,
	CheckboxControl,
	ExternalLink,
	RadioControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import { colors } from './tokens';

/**
 * Rate-limit presets for the Store API request-throttling control.
 *
 * The three presets cover the bulk of real-world merchant hosting
 * situations: shared/low-traffic, typical, and dedicated/high-traffic.
 * The Custom option escapes the presets for merchants with unusual
 * needs (very high-volume stores, or very constrained hosts).
 *
 * Values here are the RPM (requests/minute) per-crawler cap enforced
 * by `WC_AI_Syndication_Store_Api_Rate_Limiter` via WooCommerce Store
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
 * `WC_AI_Syndication_Robots::LIVE_BROWSING_AGENTS` and
 * `::TRAINING_CRAWLERS`. The frontend renders from this constant;
 * the backend sanitizes against the PHP list. Drift would produce
 * silently-dropped checkboxes on save.
 */
const KNOWN_CRAWLERS = [
	// Live browsing — user-initiated fetches + live-answer indexing.
	// Recommended on; these route revenue.
	{ id: 'ChatGPT-User', label: 'ChatGPT-User (OpenAI)', category: 'live' },
	{
		id: 'OAI-SearchBot',
		label: 'OAI-SearchBot (OpenAI SearchGPT)',
		category: 'live',
	},
	{ id: 'Claude-User', label: 'Claude-User (Anthropic)', category: 'live' },
	{
		id: 'Claude-SearchBot',
		label: 'Claude-SearchBot (Anthropic)',
		category: 'live',
	},
	{
		id: 'PerplexityBot',
		label: 'PerplexityBot (Perplexity)',
		category: 'live',
	},
	{
		id: 'Perplexity-User',
		label: 'Perplexity-User (Perplexity)',
		category: 'live',
	},
	{
		id: 'Applebot',
		label: 'Applebot (Apple Siri / Spotlight)',
		category: 'live',
	},

	// Agentic shopping — AI that places orders, not just reads.
	{
		id: 'AmazonBuyForMe',
		label: 'AmazonBuyForMe (Amazon Rufus)',
		category: 'live',
	},
	{ id: 'KlarnaBot', label: 'KlarnaBot (Klarna AI)', category: 'live' },

	// Google Shopping AI — distinct from Googlebot + Google-Extended.
	{
		id: 'Storebot-Google',
		label: 'Storebot-Google (Google Shopping AI)',
		category: 'live',
	},

	// Microsoft Shopping / Bing Ads — parallel to Storebot-Google.
	// Feeds Copilot's shopping answers, so keeping this on is the
	// commerce prerequisite for Copilot discoverability.
	{
		id: 'AdIdxBot',
		label: 'AdIdxBot (Microsoft Shopping / Copilot)',
		category: 'live',
	},

	// Regional search + AI — Asia.
	{ id: 'ERNIEBot', label: 'ERNIEBot (Baidu / China)', category: 'live' },
	{
		id: 'YiyanBot',
		label: 'YiyanBot (Baidu Conversational / China)',
		category: 'live',
	},
	{ id: 'WRTNBot', label: 'WRTNBot (Wrtn / Korea)', category: 'live' },
	{ id: 'NaverBot', label: 'NaverBot (Naver / Korea)', category: 'live' },
	{ id: 'PetalBot', label: 'PetalBot (Huawei / Global)', category: 'live' },

	// Regional search + AI — Europe.
	{
		id: 'YandexBot',
		label: 'YandexBot (Yandex / Russia + E. Europe)',
		category: 'live',
	},

	// Training crawlers — brand-strategy decision.
	{ id: 'GPTBot', label: 'GPTBot (OpenAI)', category: 'training' },
	{
		id: 'Google-Extended',
		label: 'Google-Extended (Gemini training)',
		category: 'training',
	},
	{ id: 'ClaudeBot', label: 'ClaudeBot (Anthropic)', category: 'training' },
	{
		id: 'Meta-ExternalAgent',
		label: 'Meta-ExternalAgent (Meta AI)',
		category: 'training',
	},
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
		id: 'Microsoft-BingBot-Extended',
		label: 'Microsoft-BingBot-Extended (Copilot training)',
		category: 'training',
	},
	{
		id: 'Bytespider',
		label: 'Bytespider (ByteDance / TikTok)',
		category: 'training',
	},
	{ id: 'CCBot', label: 'CCBot (CommonCrawl)', category: 'training' },
	{ id: 'cohere-ai', label: 'cohere-ai (Cohere)', category: 'training' },
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

	if ( effective === 'checking' ) {
		return (
			<span
				style={ {
					display: 'inline-flex',
					alignItems: 'center',
					gap: '6px',
					color: colors.textMuted,
					fontSize: '12px',
				} }
			>
				<Spinner />
				{ __( 'Checking…', 'woocommerce-ai-storefront' ) }
			</span>
		);
	}

	const config = {
		reachable: {
			icon: '✓',
			label: __( 'Reachable', 'woocommerce-ai-storefront' ),
			color: colors.success,
		},
		unreachable: {
			icon: '✗',
			label: __( 'Not reachable', 'woocommerce-ai-storefront' ),
			color: colors.error,
		},
		disabled: {
			icon: '—',
			label: __( 'Not published', 'woocommerce-ai-storefront' ),
			color: colors.textMuted,
		},
	}[ effective ] || {
		icon: '?',
		label: effective,
		color: colors.textMuted,
	};

	return (
		<span
			style={ {
				display: 'inline-flex',
				alignItems: 'center',
				gap: '6px',
				color: config.color,
				fontSize: '13px',
				fontWeight: '500',
			} }
		>
			<span
				aria-hidden="true"
				style={ { fontSize: '14px', lineHeight: '1' } }
			>
				{ config.icon }
			</span>
			{ config.label }
		</span>
	);
};

const EndpointInfo = ( { settings, onChange, onSave, isSaving } ) => {
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
			endpointStatus.store_api === 'unreachable' ||
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

	// Count only crawlers that are actually rendered as checkboxes. Right
	// after a plugin upgrade that rotated AI_CRAWLERS, the stored array
	// can still contain deprecated IDs (stripped on the next save by
	// WC_AI_Syndication_Robots::sanitize_allowed_crawlers), but until
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
		<div>
			<Card>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __(
							'Discovery Endpoints',
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
									{ __(
										'URL',
										'woocommerce-ai-storefront'
									) }
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
								<td>
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
										'Machine-readable store guide for AI crawlers',
										'woocommerce-ai-storefront'
									) }
								</td>
							</tr>
							<tr>
								<td>
									<strong>UCP Manifest</strong>
								</td>
								<td>
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
								<td>
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
									<strong>Store API</strong>
								</td>
								<td>
									{ endpoints.store_api ? (
										<code>{ endpoints.store_api }</code>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									<StatusBadge
										status={ endpointStatus.store_api }
									/>
								</td>
								<td>
									{ __(
										'WooCommerce Store API for product search and cart (public)',
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
						<span
							style={ {
								fontSize: '12px',
								color: colors.textMuted,
							} }
						>
							{ __(
								'Reachability is checked from your browser.',
								'woocommerce-ai-storefront'
							) }
						</span>
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

			{ /* AI Crawler Allowlist */ }
			<Card style={ { marginTop: '32px' } }>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __(
							'AI Crawler Access',
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
							'Control which AI crawlers are allowed to discover your store via robots.txt. Unchecked crawlers will be blocked from crawling your product pages.',
							'woocommerce-ai-storefront'
						) }
					</p>

					<div
						style={ {
							display: 'flex',
							justifyContent: 'space-between',
							alignItems: 'center',
							marginBottom: '12px',
						} }
					>
						<span
							style={ {
								fontSize: '13px',
								fontWeight: '500',
								color: colors.textPrimary,
							} }
						>
							{ __(
								'Allowed crawlers',
								'woocommerce-ai-storefront'
							) }
						</span>
						<span>
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
									fontWeight:
										checkedCount > 0 ? '600' : '400',
									fontSize: '12px',
									borderRadius: '10px',
									padding: '2px 10px',
									marginRight: '8px',
								} }
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
								{ __(
									'Select all',
									'woocommerce-ai-storefront'
								) }
							</Button>
							{ ' | ' }
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
						</span>
					</div>

					{ /*
						Render the two crawler categories in separate
						visual groups. This makes the merchant's
						decision legible: the top group (live
						browsing) is the revenue-path AI traffic that
						most commerce sites want; the bottom group
						(training) is a brand-strategy decision where
						accepting means your catalog becomes training
						data — potentially surfacing stale answers
						months later. See KNOWN_CRAWLERS above for
						category-assignment rationale.
					*/ }
					{ [
						{
							key: 'live',
							title: __(
								'Live browsing',
								'woocommerce-ai-storefront'
							),
							subtitle: __(
								'User-initiated fetches during an active query. These agents see fresh inventory and route revenue — recommended on.',
								'woocommerce-ai-storefront'
							),
						},
						{
							key: 'training',
							title: __(
								'Training crawlers',
								'woocommerce-ai-storefront'
							),
							subtitle: __(
								'Static crawls that feed AI model training. Captured snapshots may surface as stale answers months later, with wrong prices or availability. Merchant discretion.',
								'woocommerce-ai-storefront'
							),
						},
					].map( ( group, groupIndex ) => {
						const crawlers = KNOWN_CRAWLERS.filter(
							( c ) => c.category === group.key
						);
						return (
							<div
								key={ group.key }
								style={ {
									marginTop: groupIndex === 0 ? 0 : '16px',
								} }
							>
								<div
									style={ {
										fontSize: '12px',
										fontWeight: '600',
										color: colors.textPrimary,
										marginBottom: '2px',
										textTransform: 'uppercase',
										letterSpacing: '0.04em',
									} }
								>
									{ group.title }
								</div>
								<p
									style={ {
										color: colors.textMuted,
										fontSize: '12px',
										marginTop: 0,
										marginBottom: '8px',
									} }
								>
									{ group.subtitle }
								</p>
								<div
									style={ {
										background: colors.surfaceSubtle,
										borderRadius: '4px',
										padding: '4px 16px',
									} }
								>
									{ crawlers.map( ( crawler, index ) => (
										<div
											key={ crawler.id }
											style={ {
												padding: '6px 0',
												borderBottom:
													index < crawlers.length - 1
														? `1px solid ${ colors.borderSubtle }`
														: 'none',
											} }
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
							</div>
						);
					} ) }

					<p
						style={ {
							color: colors.textMuted,
							fontSize: '12px',
							marginTop: '12px',
							marginBottom: '6px',
						} }
					>
						{ __(
							'These rules are added to your robots.txt. Well-behaved AI crawlers respect robots.txt directives.',
							'woocommerce-ai-storefront'
						) }
					</p>
					{ /*
						Bridge the two naming conventions: the list above
						uses crawler User-Agent IDs (ChatGPT-User, etc.)
						because that's what robots.txt directives target,
						while WooCommerce's built-in "Origin" column on
						the Orders list displays short brand names
						(ChatGPT, Gemini, Claude) sourced from the
						continue_url's utm_source. Without this note a
						merchant may wonder why the Orders list names
						don't match the checkboxes. Single sentence,
						same muted-footer style as the robots.txt note
						above so it reads as "one more thing to know"
						not a headline. See
						WC_AI_Syndication_UCP_Agent_Header::KNOWN_AGENT_HOSTS
						for the hostname→brand-name map driving the
						display names.
					*/ }
					<p
						style={ {
							color: colors.textMuted,
							fontSize: '12px',
							marginTop: 0,
							marginBottom: 0,
						} }
					>
						{ __(
							'AI-referred orders appear in the Orders list under WooCommerce\u2019s built-in Origin column as each agent\u2019s brand name (e.g. "Source: ChatGPT", "Source: Gemini") rather than the technical crawler IDs shown above.',
							'woocommerce-ai-storefront'
						) }
					</p>
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
						{ __( 'Rate Limits', 'woocommerce-ai-storefront' ) }
					</h3>
					<p
						style={ {
							color: colors.textSecondary,
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Control how frequently AI crawlers can query your Store API. Higher limits allow faster product discovery but use more server resources.',
							'woocommerce-ai-storefront'
						) }
					</p>

					<RadioControl
						selected={ activePreset }
						options={ [
							{
								label: __(
									'Recommended — 25/min (works well for most stores)',
									'woocommerce-ai-storefront'
								),
								value: 'recommended',
							},
							{
								label: __(
									'Conservative — 10/min (shared hosting or low-traffic stores)',
									'woocommerce-ai-storefront'
								),
								value: 'conservative',
							},
							{
								label: __(
									'Generous — 100/min (high-traffic stores on dedicated hosting)',
									'woocommerce-ai-storefront'
								),
								value: 'generous',
							},
							{
								label: __(
									'Custom',
									'woocommerce-ai-storefront'
								),
								value: 'custom',
							},
						] }
						onChange={ ( value ) => {
							if ( RATE_LIMIT_PRESETS[ value ] ) {
								setCustomOverride( false );
								onChange( {
									rate_limit_rpm:
										RATE_LIMIT_PRESETS[ value ].rpm,
								} );
							} else {
								setCustomOverride( true );
							}
						} }
					/>

					{ activePreset === 'custom' && (
						<div
							style={ {
								display: 'flex',
								gap: '16px',
								marginTop: '12px',
								maxWidth: '400px',
							} }
						>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Requests per minute',
									'woocommerce-ai-storefront'
								) }
								type="number"
								value={ rpm }
								onChange={ ( value ) =>
									onChange( {
										rate_limit_rpm: parseInt( value ) || 60,
									} )
								}
								min={ 1 }
								max={ 1000 }
							/>
						</div>
					) }

					<p
						style={ {
							color: colors.textMuted,
							fontSize: '12px',
							marginTop: '12px',
							marginBottom: 0,
						} }
					>
						{ __(
							'Limits are applied per AI crawler (identified by user-agent string) using the WooCommerce Store API rate limiter. Your regular store traffic is not affected.',
							'woocommerce-ai-storefront'
						) }
					</p>
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
			<div
				style={ {
					marginTop: '24px',
					textAlign: 'right',
				} }
			>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ onSave }
				>
					{ isSaving
						? __( 'Saving…', 'woocommerce-ai-storefront' )
						: __( 'Save Changes', 'woocommerce-ai-storefront' ) }
				</Button>
			</div>
		</div>
	);
};

export default EndpointInfo;
