import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	CardHeader,
	Button,
	TextControl,
	Modal,
	Notice,
	Spinner,
	Flex,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';

const BotManager = () => {
	const bots = useSelect( ( select ) => select( STORE_NAME ).getBots(), [] );
	const isLoading = useSelect(
		( select ) => select( STORE_NAME ).isLoadingBots(),
		[]
	);
	const newBotKey = useSelect(
		( select ) => select( STORE_NAME ).getNewBotKey(),
		[]
	);

	const {
		fetchBots,
		createBot,
		deleteBot,
		updateBot,
		regenerateBotKey,
		setNewBotKey,
	} = useDispatch( STORE_NAME );

	const [ showCreateModal, setShowCreateModal ] = useState( false );
	const [ newBotName, setNewBotName ] = useState( '' );
	const [ confirmDelete, setConfirmDelete ] = useState( null );

	useEffect( () => {
		fetchBots();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Fetch once on mount.

	const handleCreate = () => {
		if ( newBotName.trim() ) {
			createBot( newBotName.trim() );
			setNewBotName( '' );
			setShowCreateModal( false );
		}
	};

	const handleDelete = ( botId ) => {
		deleteBot( botId );
		setConfirmDelete( null );
	};

	return (
		<>
			{ newBotKey && (
				<Notice
					status="warning"
					isDismissible={ true }
					onRemove={ () => setNewBotKey( null ) }
				>
					<p>
						<strong>
							{ __(
								'API Key (copy now, shown only once):',
								'woocommerce-ai-syndication'
							) }
						</strong>
					</p>
					<code
						style={ {
							display: 'block',
							padding: '8px',
							background: '#f0f0f0',
							wordBreak: 'break-all',
							fontSize: '13px',
						} }
					>
						{ newBotKey.api_key }
					</code>
					<p style={ { marginTop: '8px' } }>
						{ __( 'Bot:', 'woocommerce-ai-syndication' ) }{ ' ' }
						<strong>{ newBotKey.name }</strong>
					</p>
				</Notice>
			) }

			<Card>
				<CardHeader>
					<Flex>
						<h2>
							{ __(
								'Registered AI Agents',
								'woocommerce-ai-syndication'
							) }
						</h2>
						<Button
							variant="primary"
							onClick={ () => setShowCreateModal( true ) }
							size="compact"
						>
							{ __( 'Add Agent', 'woocommerce-ai-syndication' ) }
						</Button>
					</Flex>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Register AI agents that can access your product catalog. Each agent gets a unique API key.',
							'woocommerce-ai-syndication'
						) }
					</p>

					{ isLoading && <Spinner /> }

					{ ! isLoading && bots.length === 0 && (
						<p style={ { color: '#757575' } }>
							{ __(
								'No agents registered yet. Click "Add Agent" to get started.',
								'woocommerce-ai-syndication'
							) }
						</p>
					) }

					{ ! isLoading &&
						bots.map( ( bot ) => (
							<div
								key={ bot.id }
								style={ {
									border: '1px solid #ddd',
									borderRadius: '4px',
									padding: '12px',
									marginBottom: '8px',
									background:
										bot.status === 'revoked'
											? '#fef7f1'
											: '#fff',
								} }
							>
								<Flex alignment="top">
									<div style={ { flex: 1 } }>
										<strong>{ bot.name }</strong>
										{ bot.status === 'revoked' && (
											<span
												style={ {
													color: '#d63638',
													marginLeft: '8px',
													fontSize: '12px',
												} }
											>
												{ __(
													'Revoked',
													'woocommerce-ai-syndication'
												) }
											</span>
										) }
										<br />
										<small style={ { color: '#757575' } }>
											{ __(
												'Key:',
												'woocommerce-ai-syndication'
											) }{ ' ' }
											<code>{ bot.key_prefix }</code>
											{ ' | ' }
											{ __(
												'Requests:',
												'woocommerce-ai-syndication'
											) }{ ' ' }
											{ bot.request_count }
											{ bot.last_access && (
												<>
													{ ' | ' }
													{ __(
														'Last access:',
														'woocommerce-ai-syndication'
													) }{ ' ' }
													{ bot.last_access }
												</>
											) }
										</small>
									</div>
									<Flex spacing={ 2 }>
										{ bot.status === 'active' ? (
											<Button
												variant="secondary"
												size="compact"
												isDestructive
												onClick={ () =>
													updateBot( bot.id, {
														status: 'revoked',
													} )
												}
											>
												{ __(
													'Revoke',
													'woocommerce-ai-syndication'
												) }
											</Button>
										) : (
											<Button
												variant="secondary"
												size="compact"
												onClick={ () =>
													updateBot( bot.id, {
														status: 'active',
													} )
												}
											>
												{ __(
													'Reactivate',
													'woocommerce-ai-syndication'
												) }
											</Button>
										) }
										<Button
											variant="secondary"
											size="compact"
											onClick={ () =>
												regenerateBotKey( bot.id )
											}
										>
											{ __(
												'Regenerate Key',
												'woocommerce-ai-syndication'
											) }
										</Button>
										<Button
											variant="tertiary"
											size="compact"
											isDestructive
											onClick={ () =>
												setConfirmDelete( bot.id )
											}
										>
											{ __(
												'Delete',
												'woocommerce-ai-syndication'
											) }
										</Button>
									</Flex>
								</Flex>
							</div>
						) ) }
				</CardBody>
			</Card>

			{ showCreateModal && (
				<Modal
					title={ __(
						'Register AI Agent',
						'woocommerce-ai-syndication'
					) }
					onRequestClose={ () => setShowCreateModal( false ) }
				>
					<TextControl
						label={ __(
							'Agent Name',
							'woocommerce-ai-syndication'
						) }
						help={ __(
							'e.g., ChatGPT, Gemini, Perplexity, Custom Agent',
							'woocommerce-ai-syndication'
						) }
						value={ newBotName }
						onChange={ setNewBotName }
						placeholder="ChatGPT"
					/>
					<Flex>
						<Button
							variant="primary"
							onClick={ handleCreate }
							disabled={ ! newBotName.trim() }
						>
							{ __( 'Create', 'woocommerce-ai-syndication' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setShowCreateModal( false ) }
						>
							{ __( 'Cancel', 'woocommerce-ai-syndication' ) }
						</Button>
					</Flex>
				</Modal>
			) }

			{ confirmDelete && (
				<Modal
					title={ __( 'Delete Agent', 'woocommerce-ai-syndication' ) }
					onRequestClose={ () => setConfirmDelete( null ) }
				>
					<p>
						{ __(
							'Are you sure you want to permanently delete this agent? This cannot be undone.',
							'woocommerce-ai-syndication'
						) }
					</p>
					<Flex>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => handleDelete( confirmDelete ) }
						>
							{ __( 'Delete', 'woocommerce-ai-syndication' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setConfirmDelete( null ) }
						>
							{ __( 'Cancel', 'woocommerce-ai-syndication' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</>
	);
};

export default BotManager;
