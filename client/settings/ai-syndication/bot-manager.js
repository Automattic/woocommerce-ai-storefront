import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Card,
	CardBody,
	Button,
	TextControl,
	CheckboxControl,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../../data/ai-syndication/constants';

const KNOWN_AGENTS = [
	{ id: 'chatgpt', name: 'ChatGPT (OpenAI)' },
	{ id: 'gemini', name: 'Gemini (Google)' },
	{ id: 'claude', name: 'Claude (Anthropic)' },
	{ id: 'perplexity', name: 'Perplexity' },
	{ id: 'copilot', name: 'Microsoft Copilot' },
	{ id: 'meta-ai', name: 'Meta AI' },
	{ id: 'alexa', name: 'Amazon Alexa' },
	{ id: 'siri', name: 'Apple Siri' },
];

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
		createBots,
		deleteBot,
		updateBot,
		regenerateBotKey,
		setNewBotKey,
	} = useDispatch( STORE_NAME );

	const [ showCreateModal, setShowCreateModal ] = useState( false );
	const [ selectedAgents, setSelectedAgents ] = useState( [] );
	const [ customName, setCustomName ] = useState( '' );
	const [ confirmDelete, setConfirmDelete ] = useState( null );

	useEffect( () => {
		fetchBots();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Fetch once on mount.

	// Filter out agents that are already registered.
	const registeredNames = ( bots || [] ).map( ( b ) =>
		( b.name || '' ).toLowerCase()
	);
	const availableAgents = KNOWN_AGENTS.filter(
		( agent ) => ! registeredNames.includes( agent.name.toLowerCase() )
	);

	const toggleAgent = ( agentId ) => {
		setSelectedAgents( ( prev ) =>
			prev.includes( agentId )
				? prev.filter( ( id ) => id !== agentId )
				: [ ...prev, agentId ]
		);
	};

	const handleCreate = () => {
		const names = [];

		// Collect names from selected known agents.
		selectedAgents.forEach( ( agentId ) => {
			const agent = KNOWN_AGENTS.find( ( a ) => a.id === agentId );
			if ( agent ) {
				names.push( agent.name );
			}
		} );

		// Add custom name if provided.
		if ( customName.trim() ) {
			names.push( customName.trim() );
		}

		if ( names.length > 0 ) {
			createBots( names );
		}

		setSelectedAgents( [] );
		setCustomName( '' );
		setShowCreateModal( false );
	};

	const canCreate = selectedAgents.length > 0 || customName.trim().length > 0;

	const handleDelete = ( botId ) => {
		deleteBot( botId );
		setConfirmDelete( null );
	};

	return (
		<div>
			{ newBotKey && (
				<Notice
					status="warning"
					isDismissible={ true }
					onRemove={ () => setNewBotKey( null ) }
				>
					<p>
						<strong>
							{ __(
								'API Keys (copy now, shown only once):',
								'woocommerce-ai-syndication'
							) }
						</strong>
					</p>
					{ ( Array.isArray( newBotKey )
						? newBotKey
						: [ newBotKey ]
					).map( ( key ) => (
						<div
							key={ key.bot_id }
							style={ {
								marginBottom: '8px',
								padding: '8px',
								background: '#f0f0f0',
								borderRadius: '4px',
							} }
						>
							<strong>{ key.name }</strong>
							<code
								style={ {
									display: 'block',
									wordBreak: 'break-all',
									fontSize: '13px',
									marginTop: '4px',
								} }
							>
								{ key.api_key }
							</code>
						</div>
					) ) }
				</Notice>
			) }

			<Card>
				<CardBody>
					<div
						style={ {
							display: 'flex',
							justifyContent: 'space-between',
							alignItems: 'flex-start',
							marginBottom: '8px',
						} }
					>
						<h3 style={ { margin: '0', fontSize: '14px' } }>
							{ __(
								'Registered AI Agents',
								'woocommerce-ai-syndication'
							) }
						</h3>
						<Button
							variant="primary"
							onClick={ () => setShowCreateModal( true ) }
							size="compact"
						>
							{ __( 'Add Agent', 'woocommerce-ai-syndication' ) }
						</Button>
					</div>
					<p
						style={ {
							color: '#50575e',
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Register AI agents that can access your product catalog. Each agent gets a unique API key.',
							'woocommerce-ai-syndication'
						) }
					</p>

					{ isLoading && <Spinner /> }

					{ ! isLoading && bots.length === 0 && (
						<p
							style={ {
								color: '#757575',
								fontSize: '13px',
								margin: 0,
							} }
						>
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
									padding: '16px',
									marginBottom: '8px',
									background:
										bot.status === 'revoked'
											? '#fef7f1'
											: '#fff',
								} }
							>
								<div
									style={ {
										display: 'flex',
										justifyContent: 'space-between',
										alignItems: 'flex-start',
										gap: '16px',
									} }
								>
									<div style={ { flex: 1, minWidth: 0 } }>
										<strong style={ { fontSize: '14px' } }>
											{ bot.name }
										</strong>
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
										<div
											style={ {
												color: '#757575',
												fontSize: '12px',
												marginTop: '4px',
											} }
										>
											{ __(
												'Key:',
												'woocommerce-ai-syndication'
											) }{ ' ' }
											<code
												style={ { fontSize: '11px' } }
											>
												{ bot.key_prefix }
											</code>
											{ ' | ' }
											{ __(
												'Requests:',
												'woocommerce-ai-syndication'
											) }{ ' ' }
											{ bot.request_count || 0 }
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
										</div>
									</div>
									<div
										style={ {
											display: 'flex',
											gap: '8px',
											flexShrink: 0,
										} }
									>
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
									</div>
								</div>
							</div>
						) ) }
				</CardBody>
			</Card>

			{ showCreateModal && (
				<Modal
					title={ __(
						'Register AI Agents',
						'woocommerce-ai-syndication'
					) }
					onRequestClose={ () => {
						setShowCreateModal( false );
						setSelectedAgents( [] );
						setCustomName( '' );
					} }
				>
					{ availableAgents.length > 0 && (
						<>
							<p style={ { marginTop: 0 } }>
								{ __(
									'Select the AI agents that should access your catalog:',
									'woocommerce-ai-syndication'
								) }
							</p>
							<div
								style={ {
									border: '1px solid #ddd',
									borderRadius: '4px',
									padding: '12px',
									marginBottom: '16px',
								} }
							>
								{ availableAgents.map( ( agent ) => (
									<div
										key={ agent.id }
										style={ { marginBottom: '8px' } }
									>
										<CheckboxControl
											label={ agent.name }
											checked={ selectedAgents.includes(
												agent.id
											) }
											onChange={ () =>
												toggleAgent( agent.id )
											}
											__nextHasNoMarginBottom
										/>
									</div>
								) ) }
							</div>
						</>
					) }
					{ availableAgents.length === 0 && (
						<p style={ { color: '#757575', marginTop: 0 } }>
							{ __(
								'All known agents are already registered.',
								'woocommerce-ai-syndication'
							) }
						</p>
					) }
					<TextControl
						label={ __(
							'Custom agent name',
							'woocommerce-ai-syndication'
						) }
						help={ __(
							'Add a custom agent not in the list above.',
							'woocommerce-ai-syndication'
						) }
						value={ customName }
						onChange={ setCustomName }
						placeholder={ __(
							'My Custom Agent',
							'woocommerce-ai-syndication'
						) }
					/>
					<div style={ { display: 'flex', gap: '8px' } }>
						<Button
							variant="primary"
							onClick={ handleCreate }
							disabled={ ! canCreate }
						>
							{ __( 'Register', 'woocommerce-ai-syndication' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => {
								setShowCreateModal( false );
								setSelectedAgents( [] );
								setCustomName( '' );
							} }
						>
							{ __( 'Cancel', 'woocommerce-ai-syndication' ) }
						</Button>
					</div>
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
					<div style={ { display: 'flex', gap: '8px' } }>
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
					</div>
				</Modal>
			) }
		</div>
	);
};

export default BotManager;
