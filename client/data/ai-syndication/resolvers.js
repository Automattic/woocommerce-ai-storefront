import apiFetch from '@wordpress/api-fetch';
import { ADMIN_NAMESPACE } from './constants';

export const getSettings =
	() =>
	async ( { dispatch } ) => {
		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/settings`,
			} );
			dispatch.updateSettings( result || {} );
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'AI Syndication: failed to load settings', error );
		}
	};

export const getBots =
	() =>
	async ( { dispatch } ) => {
		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/bots`,
			} );
			dispatch.setBots( result );
		} catch ( error ) {
			// Bots will remain empty.
		}
	};

export const getEndpoints =
	() =>
	async ( { dispatch } ) => {
		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/endpoints`,
			} );
			dispatch.setEndpoints( result );
		} catch ( error ) {
			// Endpoints will remain empty.
		}
	};
