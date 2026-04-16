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

const KNOWN_CRAWLERS = [
	{ id: 'GPTBot', label: 'GPTBot (OpenAI)' },
	{ id: 'ChatGPT-User', label: 'ChatGPT-User (OpenAI)' },
	{ id: 'OAI-SearchBot', label: 'OAI-SearchBot (OpenAI Search)' },
	{ id: 'Google-Extended', label: 'Google-Extended (Gemini)' },
	{ id: 'Gemini', label: 'Gemini (Google)' },
	{ id: 'PerplexityBot', label: 'PerplexityBot (Perplexity)' },
	{ id: 'Perplexity-User', label: 'Perplexity-User (Perplexity)' },
	{ id: 'ClaudeBot', label: 'ClaudeBot (Anthropic)' },
	{ id: 'Claude-User', label: 'Claude-User (Anthropic)' },
	{ id: 'Meta-ExternalAgent', label: 'Meta-ExternalAgent (Meta AI)' },
	{ id: 'Amazonbot', label: 'Amazonbot (Alexa)' },
	{ id: 'Applebot-Extended', label: 'Applebot-Extended (Siri)' },
];

const EndpointInfo = ( { settings, onChange, onSave, isSaving } ) => {
	const endpoints = useSelect(
		( select ) => select( STORE_NAME ).getEndpoints(),
		[]
	);

	const { fetchEndpoints } = useDispatch( STORE_NAME );

	useEffect( () => {
		fetchEndpoints();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Fetch once on mount.

	const isEnabled = settings.enabled === 'yes';
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
							color: '#50575e',
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
								color: '#d63638',
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
									{ __(
										'Universal Commerce Protocol — declares capabilities',
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
									{ __(
										'WooCommerce Store API for product search and cart (public)',
										'woocommerce-ai-syndication'
									) }
								</td>
							</tr>
						</tbody>
					</table>
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
							color: '#50575e',
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
								color: '#1d2327',
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
											? '#edfaef'
											: '#f0f0f0',
									color:
										checkedCount > 0
											? '#00a32a'
											: '#757575',
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

					<div
						style={ {
							background: '#f6f7f7',
							borderRadius: '4px',
							padding: '4px 16px',
						} }
					>
						{ KNOWN_CRAWLERS.map( ( crawler, index ) => (
							<div
								key={ crawler.id }
								style={ {
									padding: '6px 0',
									borderBottom:
										index < KNOWN_CRAWLERS.length - 1
											? '1px solid #e0e0e0'
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

					<p
						style={ {
							color: '#757575',
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
							borderTop: '1px solid #f0f0f0',
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
