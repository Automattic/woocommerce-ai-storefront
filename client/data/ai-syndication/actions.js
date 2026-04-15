import ACTION_TYPES from './action-types';
import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME, ADMIN_NAMESPACE } from './constants';

export function updateSettings( data ) {
	return { type: ACTION_TYPES.SET_SETTINGS, data };
}

export function updateSettingsValues( payload ) {
	return { type: ACTION_TYPES.SET_SETTINGS_VALUES, payload };
}

export function setBots( data ) {
	return { type: ACTION_TYPES.SET_BOTS, data };
}

export function setStats( data ) {
	return { type: ACTION_TYPES.SET_STATS, data };
}

export function setEndpoints( data ) {
	return { type: ACTION_TYPES.SET_ENDPOINTS, data };
}

export function setNewBotKey( data ) {
	return { type: ACTION_TYPES.SET_NEW_BOT_KEY, data };
}

export function saveSettings() {
	return async ( { select } ) => {
		const settings = select.getSettings();

		dispatch( STORE_NAME ).setIsSaving( true, null );

		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/settings`,
				method: 'POST',
				data: settings,
			} );

			dispatch( STORE_NAME ).updateSettings( result );
			dispatch( 'core/notices' ).createSuccessNotice(
				__( 'Settings saved.', 'woocommerce-ai-syndication' )
			);
		} catch ( error ) {
			dispatch( STORE_NAME ).setIsSaving( false, error );
			dispatch( 'core/notices' ).createErrorNotice(
				__( 'Error saving settings.', 'woocommerce-ai-syndication' )
			);
		}

		dispatch( STORE_NAME ).setIsSaving( false, null );
	};
}

export function setIsSaving( isSaving, error ) {
	return { type: ACTION_TYPES.SET_IS_SAVING, isSaving, error };
}

export function fetchBots() {
	return async () => {
		dispatch( STORE_NAME ).setIsLoadingBots( true );

		try {
			const bots = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/bots`,
			} );
			dispatch( STORE_NAME ).setBots( bots );
		} catch ( error ) {
			dispatch( 'core/notices' ).createErrorNotice(
				__( 'Error loading bots.', 'woocommerce-ai-syndication' )
			);
		}

		dispatch( STORE_NAME ).setIsLoadingBots( false );
	};
}

export function setIsLoadingBots( isLoading ) {
	return { type: ACTION_TYPES.SET_IS_LOADING_BOTS, isLoading };
}

export function createBot( name, permissions ) {
	return async () => {
		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/bots`,
				method: 'POST',
				data: { name, permissions },
			} );

			// Store the new API key for display (shown only once).
			dispatch( STORE_NAME ).setNewBotKey( result );

			// Refresh bots list.
			dispatch( STORE_NAME ).fetchBots();

			dispatch( 'core/notices' ).createSuccessNotice(
				__( 'Bot created. Copy the API key now - it won\'t be shown again.', 'woocommerce-ai-syndication' )
			);
		} catch ( error ) {
			dispatch( 'core/notices' ).createErrorNotice(
				__( 'Error creating bot.', 'woocommerce-ai-syndication' )
			);
		}
	};
}

export function deleteBot( botId ) {
	return async () => {
		try {
			await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/bots/${ botId }`,
				method: 'DELETE',
			} );

			dispatch( STORE_NAME ).fetchBots();

			dispatch( 'core/notices' ).createSuccessNotice(
				__( 'Bot deleted.', 'woocommerce-ai-syndication' )
			);
		} catch ( error ) {
			dispatch( 'core/notices' ).createErrorNotice(
				__( 'Error deleting bot.', 'woocommerce-ai-syndication' )
			);
		}
	};
}

export function updateBot( botId, data ) {
	return async () => {
		try {
			await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/bots/${ botId }`,
				method: 'PUT',
				data,
			} );

			dispatch( STORE_NAME ).fetchBots();
		} catch ( error ) {
			dispatch( 'core/notices' ).createErrorNotice(
				__( 'Error updating bot.', 'woocommerce-ai-syndication' )
			);
		}
	};
}

export function regenerateBotKey( botId ) {
	return async () => {
		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/bots/${ botId }/regenerate-key`,
				method: 'POST',
			} );

			dispatch( STORE_NAME ).setNewBotKey( result );
			dispatch( STORE_NAME ).fetchBots();

			dispatch( 'core/notices' ).createSuccessNotice(
				__( 'API key regenerated. Copy it now - it won\'t be shown again.', 'woocommerce-ai-syndication' )
			);
		} catch ( error ) {
			dispatch( 'core/notices' ).createErrorNotice(
				__( 'Error regenerating key.', 'woocommerce-ai-syndication' )
			);
		}
	};
}

export function fetchStats( period ) {
	return async () => {
		try {
			const stats = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/stats?period=${ period || 'month' }`,
			} );
			dispatch( STORE_NAME ).setStats( stats );
		} catch ( error ) {
			// Silent failure for stats.
		}
	};
}

export function fetchEndpoints() {
	return async () => {
		try {
			const endpoints = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/endpoints`,
			} );
			dispatch( STORE_NAME ).setEndpoints( endpoints );
		} catch ( error ) {
			// Silent failure.
		}
	};
}
