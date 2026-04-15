import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Card, CardBody, ExternalLink, Spinner } from '@wordpress/components';
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
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Fetch once on mount.

	const isEnabled = settings.enabled === 'yes';

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
							'These endpoints are automatically available when AI Syndication is enabled. AI crawlers and agents use these to discover your store.',
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
										<code>{ endpoints.catalog_api }</code>
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
										<code>{ endpoints.store_api }</code>
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
		</div>
	);
};

export default EndpointInfo;
