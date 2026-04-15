import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';

const EndpointInfo = () => {
	const endpoints = useSelect(
		( select ) => select( STORE_NAME ).getEndpoints(),
		[]
	);
	const settings = useSelect(
		( select ) => select( STORE_NAME ).getSettings(),
		[]
	);

	const { fetchEndpoints } = useDispatch( STORE_NAME );

	useEffect( () => {
		fetchEndpoints();
	}, [] );

	const isEnabled = settings.enabled === 'yes';

	return (
		<>
			<Card>
				<CardHeader>
					<h2>
						{ __(
							'Discovery Endpoints',
							'woocommerce-ai-syndication'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					{ ! isEnabled && (
						<p style={ { color: '#d63638' } }>
							{ __(
								'AI Syndication is currently disabled. Enable it in General settings to activate these endpoints.',
								'woocommerce-ai-syndication'
							) }
						</p>
					) }

					<p>
						{ __(
							'These endpoints are automatically available when AI Syndication is enabled. AI crawlers and agents use these to discover your store.',
							'woocommerce-ai-syndication'
						) }
					</p>

					<table className="widefat" style={ { marginTop: '16px' } }>
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
										<ExternalLink
											href={ endpoints.ucp }
										>
											{ endpoints.ucp }
										</ExternalLink>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									{ __(
										'Universal Commerce Protocol - declares capabilities',
										'woocommerce-ai-syndication'
									) }
								</td>
							</tr>
							<tr>
								<td>
									<strong>Catalog API</strong>
								</td>
								<td>
									{ endpoints.catalog_api ? (
										<code>
											{ endpoints.catalog_api }
										</code>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									{ __(
										'REST API for product search (requires API key)',
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
										<code>
											{ endpoints.store_api }
										</code>
									) : (
										<Spinner />
									) }
								</td>
								<td>
									{ __(
										'WooCommerce Store API for cart sync',
										'woocommerce-ai-syndication'
									) }
								</td>
							</tr>
						</tbody>
					</table>
				</CardBody>
			</Card>

			<Card style={ { marginTop: '16px' } }>
				<CardHeader>
					<h2>
						{ __(
							'Integration Guide',
							'woocommerce-ai-syndication'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					<h3>
						{ __(
							'For AI Agent Developers',
							'woocommerce-ai-syndication'
						) }
					</h3>
					<ol style={ { paddingLeft: '20px' } }>
						<li>
							{ __(
								'Read /llms.txt to understand the store structure',
								'woocommerce-ai-syndication'
							) }
						</li>
						<li>
							{ __(
								'Read /.well-known/ucp for API capabilities and checkout policy',
								'woocommerce-ai-syndication'
							) }
						</li>
						<li>
							{ __(
								'Use the Catalog API with your X-AI-Agent-Key to search products',
								'woocommerce-ai-syndication'
							) }
						</li>
						<li>
							{ __(
								'Generate redirect URLs with utm_source, utm_medium=ai_agent, and ai_session_id',
								'woocommerce-ai-syndication'
							) }
						</li>
						<li>
							{ __(
								'Optionally use /cart/prepare to pre-populate the cart before redirect',
								'woocommerce-ai-syndication'
							) }
						</li>
					</ol>

					<h3 style={ { marginTop: '16px' } }>
						{ __(
							'Checkout Policy',
							'woocommerce-ai-syndication'
						) }
					</h3>
					<p>
						{ __(
							'This store uses web-redirect checkout only. No in-chat or delegated payments. When an AI says "Buy Now," it generates a link that takes the customer to your checkout page.',
							'woocommerce-ai-syndication'
						) }
					</p>
				</CardBody>
			</Card>
		</>
	);
};

export default EndpointInfo;
