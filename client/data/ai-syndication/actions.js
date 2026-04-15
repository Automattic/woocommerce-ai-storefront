import ACTION_TYPES from './action-types';
import apiFetch from '@wordpress/api-fetch';
import { dispatch as globalDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { ADMIN_NAMESPACE } from './constants';

export function updateSettings( data ) {
	return { type: ACTION_TYPES.SET_SETTINGS, data };
}

export function updateSettingsValues( payload ) {
	return { type: ACTION_TYPES.SET_SETTINGS_VALUES, payload };
}

export function setStats( data ) {
	return { type: ACTION_TYPES.SET_STATS, data };
}

export function setEndpoints( data ) {
	return { type: ACTION_TYPES.SET_ENDPOINTS, data };
}

export function saveSettings() {
	return async ( { dispatch, select } ) => {
		const settings = select.getSettings();

		dispatch.setIsSaving( true, null );

		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/settings`,
				method: 'POST',
				data: settings,
			} );

			dispatch.updateSettings( result );
			globalDispatch( 'core/notices' ).createSuccessNotice(
				__( 'Settings saved.', 'woocommerce-ai-syndication' )
			);
		} catch ( error ) {
			dispatch.setIsSaving( false, error );
			globalDispatch( 'core/notices' ).createErrorNotice(
				__( 'Error saving settings.', 'woocommerce-ai-syndication' )
			);
			return;
		}

		dispatch.setIsSaving( false, null );
	};
}

export function setIsSaving( isSaving, error ) {
	return { type: ACTION_TYPES.SET_IS_SAVING, isSaving, error };
}

export function fetchStats( period ) {
	return async ( { dispatch } ) => {
		try {
			const stats = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/stats?period=${
					period || 'month'
				}`,
			} );
			dispatch.setStats( stats );
		} catch ( error ) {
			// Silent failure for stats.
		}
	};
}

export function fetchEndpoints() {
	return async ( { dispatch } ) => {
		try {
			const endpoints = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/endpoints`,
			} );
			dispatch.setEndpoints( endpoints );
		} catch ( error ) {
			// Silent failure.
		}
	};
}
