import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	CardHeader,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';

const AttributionStats = () => {
	const [ period, setPeriod ] = useState( 'month' );
	const stats = useSelect(
		( select ) => select( STORE_NAME ).getStats(),
		[]
	);

	const { fetchStats } = useDispatch( STORE_NAME );

	useEffect( () => {
		fetchStats( period );
	}, [ period ] );

	return (
		<>
			<Card>
				<CardHeader>
					<h2>
						{ __(
							'AI Agent Attribution',
							'woocommerce-ai-syndication'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Orders attributed to AI agent referrals via standard WooCommerce Order Attribution (utm_medium=ai_agent).',
							'woocommerce-ai-syndication'
						) }
					</p>

					<SelectControl
						label={ __( 'Period', 'woocommerce-ai-syndication' ) }
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
					/>

					{ ! stats ? (
						<Spinner />
					) : (
						<div style={ { marginTop: '16px' } }>
							<div
								style={ {
									display: 'grid',
									gridTemplateColumns: '1fr 1fr',
									gap: '16px',
									marginBottom: '24px',
								} }
							>
								<StatCard
									label={ __(
										'AI-Referred Orders',
										'woocommerce-ai-syndication'
									) }
									value={ stats.total_orders }
								/>
								<StatCard
									label={ __(
										'AI-Referred Revenue',
										'woocommerce-ai-syndication'
									) }
									value={ `${ stats.currency } ${ parseFloat(
										stats.total_revenue
									).toFixed( 2 ) }` }
								/>
							</div>

							{ Object.keys( stats.by_agent || {} ).length >
								0 && (
								<>
									<h3>
										{ __(
											'By Agent',
											'woocommerce-ai-syndication'
										) }
									</h3>
									<table
										className="widefat"
										style={ { marginTop: '8px' } }
									>
										<thead>
											<tr>
												<th>
													{ __(
														'Agent',
														'woocommerce-ai-syndication'
													) }
												</th>
												<th>
													{ __(
														'Orders',
														'woocommerce-ai-syndication'
													) }
												</th>
												<th>
													{ __(
														'Revenue',
														'woocommerce-ai-syndication'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ Object.entries(
												stats.by_agent
											).map(
												( [
													agent,
													agentStats,
												] ) => (
													<tr key={ agent }>
														<td>
															<strong>
																{ agent }
															</strong>
														</td>
														<td>
															{
																agentStats.orders
															}
														</td>
														<td>
															{
																stats.currency
															}{ ' ' }
															{ parseFloat(
																agentStats.revenue
															).toFixed(
																2
															) }
														</td>
													</tr>
												)
											) }
										</tbody>
									</table>
								</>
							) }

							{ Object.keys( stats.by_agent || {} ).length ===
								0 && (
								<p style={ { color: '#757575' } }>
									{ __(
										'No AI-attributed orders in this period.',
										'woocommerce-ai-syndication'
									) }
								</p>
							) }
						</div>
					) }
				</CardBody>
			</Card>

			<Card style={ { marginTop: '16px' } }>
				<CardHeader>
					<h2>
						{ __(
							'How Attribution Works',
							'woocommerce-ai-syndication'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'AI Syndication uses standard WooCommerce Order Attribution. When an AI agent links a customer to your store, the URL includes:',
							'woocommerce-ai-syndication'
						) }
					</p>
					<ul style={ { listStyle: 'disc', paddingLeft: '20px' } }>
						<li>
							<code>utm_source</code> &mdash;{ ' ' }
							{ __(
								'Agent identifier (chatgpt, gemini, etc.)',
								'woocommerce-ai-syndication'
							) }
						</li>
						<li>
							<code>utm_medium=ai_agent</code> &mdash;{ ' ' }
							{ __(
								'Identifies AI traffic',
								'woocommerce-ai-syndication'
							) }
						</li>
						<li>
							<code>ai_session_id</code> &mdash;{ ' ' }
							{ __(
								'Conversation tracking',
								'woocommerce-ai-syndication'
							) }
						</li>
					</ul>
					<p>
						{ __(
							'This data flows through WooCommerce\'s native order attribution system and appears in order details.',
							'woocommerce-ai-syndication'
						) }
					</p>
				</CardBody>
			</Card>
		</>
	);
};

const StatCard = ( { label, value } ) => (
	<div
		style={ {
			border: '1px solid #ddd',
			borderRadius: '4px',
			padding: '16px',
			textAlign: 'center',
		} }
	>
		<div style={ { fontSize: '24px', fontWeight: 'bold' } }>
			{ value }
		</div>
		<div style={ { color: '#757575', fontSize: '13px', marginTop: '4px' } }>
			{ label }
		</div>
	</div>
);

export default AttributionStats;
