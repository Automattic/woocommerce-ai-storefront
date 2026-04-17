import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	Button,
	CheckboxControl,
	ExternalLink,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';
import { colors } from './tokens';

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
	// Live browsing — user-initiated fetches, recommended on.
	{ id: 'ChatGPT-User', label: 'ChatGPT-User (OpenAI)', category: 'live' },
	{ id: 'OAI-SearchBot', label: 'OAI-SearchBot (OpenAI Search)', category: 'live' },
	{ id: 'Perplexity-User', label: 'Perplexity-User (Perplexity)', category: 'live' },
	{ id: 'Claude-User', label: 'Claude-User (Anthropic)', category: 'live' },

	// Training crawlers — brand-strategy decision.
	{ id: 'GPTBot', label: 'GPTBot (OpenAI)', category: 'training' },
	{ id: 'Google-Extended', label: 'Google-Extended (Gemini)', category: 'training' },
	{ id: 'Gemini', label: 'Gemini (Google)', category: 'training' },
	{ id: 'PerplexityBot', label: 'PerplexityBot (Perplexity)', category: 'training' },
	{ id: 'ClaudeBot', label: 'ClaudeBot (Anthropic)', category: 'training' },
	{ id: 'Meta-ExternalAgent', label: 'Meta-ExternalAgent (Meta AI)', category: 'training' },
	{ id: 'Amazonbot', label: 'Amazonbot (Alexa)', category: 'training' },
	{ id: 'Applebot-Extended', label: 'Applebot-Extended (Siri)', category: 'training' },
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
				{ __( 'Checking…', 'woocommerce-ai-syndication' ) }
			</span>
		);
	}

	const config = {
		reachable: {
			icon: '✓',
			label: __( 'Reachable', 'woocommerce-ai-syndication' ),
			color: colors.success,
		},
		unreachable: {
			icon: '✗',
			label: __( 'Not reachable', 'woocommerce-ai-syndication' ),
			color: colors.error,
		},
		disabled: {
			icon: '—',
			label: __( 'Not published', 'woocommerce-ai-syndication' ),
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
							'woocommerce-ai-syndication'
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
							'These endpoints are automatically available when AI Syndication is enabled.',
							'woocommerce-ai-syndication'
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
								'AI Syndication is currently disabled. Enable it in the Overview tab to activate these endpoints.',
								'woocommerce-ai-syndication'
							) }
						</p>
					) }

					<table className="widefat" style={ { margin: 0 } }>
						<thead>
							<tr>
								<th>
									{ __(
										'Endpoint',
										'woocommerce-ai-syndication'
									) }
								</th>
								<th>
									{ __(
										'URL',
										'woocommerce-ai-syndication'
									) }
								</th>
								<th>
									{ __(
										'Status',
										'woocommerce-ai-syndication'
									) }
								</th>
								<th>
									{ __(
										'Purpose',
										'woocommerce-ai-syndication'
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
										'woocommerce-ai-syndication'
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
										'woocommerce-ai-syndication'
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
										'woocommerce-ai-syndication'
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
										'woocommerce-ai-syndication'
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
								'woocommerce-ai-syndication'
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
								'woocommerce-ai-syndication'
							) }
						</span>
						<Button
							variant="secondary"
							size="compact"
							onClick={ () => checkEndpoints() }
						>
							{ __( 'Re-check', 'woocommerce-ai-syndication' ) }
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
							'woocommerce-ai-syndication'
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
							'woocommerce-ai-syndication'
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
								'woocommerce-ai-syndication'
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
										'woocommerce-ai-syndication'
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
									'woocommerce-ai-syndication'
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
								{ __( 'Clear', 'woocommerce-ai-syndication' ) }
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
								'woocommerce-ai-syndication'
							),
							subtitle: __(
								'User-initiated fetches during an active query. These agents see fresh inventory and route revenue — recommended on.',
								'woocommerce-ai-syndication'
							),
						},
						{
							key: 'training',
							title: __(
								'Training crawlers',
								'woocommerce-ai-syndication'
							),
							subtitle: __(
								'Static crawls that feed AI model training. Captured snapshots may surface as stale answers months later, with wrong prices or availability. Merchant discretion.',
								'woocommerce-ai-syndication'
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
													toggleCrawler(
														crawler.id
													)
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
							marginBottom: 0,
						} }
					>
						{ __(
							'These rules are added to your robots.txt. Well-behaved AI crawlers respect robots.txt directives.',
							'woocommerce-ai-syndication'
						) }
					</p>

					{ /* Save button inside card */ }
					<div
						style={ {
							marginTop: '16px',
							paddingTop: '16px',
							borderTop: `1px solid ${ colors.surfaceMuted }`,
						} }
					>
						<Button
							variant="primary"
							isBusy={ isSaving }
							disabled={ isSaving }
							onClick={ onSave }
						>
							{ isSaving
								? __( 'Saving…', 'woocommerce-ai-syndication' )
								: __(
										'Save Changes',
										'woocommerce-ai-syndication'
								  ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</div>
	);
};

export default EndpointInfo;
