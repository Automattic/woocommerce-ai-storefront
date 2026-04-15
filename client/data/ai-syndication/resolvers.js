import apiFetch from '@wordpress/api-fetch';
import { updateSettings, setBots, setEndpoints } from './actions';
import { ADMIN_NAMESPACE } from './constants';

export function* getSettings() {
	try {
		const result = yield apiFetch( {
			path: `${ ADMIN_NAMESPACE }/settings`,
		} );
		yield updateSettings( result );
	} catch ( error ) {
		// Settings will remain at defaults.
	}
}

export function* getBots() {
	try {
		const result = yield apiFetch( { path: `${ ADMIN_NAMESPACE }/bots` } );
		yield setBots( result );
	} catch ( error ) {
		// Bots will remain empty.
	}
}

export function* getEndpoints() {
	try {
		const result = yield apiFetch( {
			path: `${ ADMIN_NAMESPACE }/endpoints`,
		} );
		yield setEndpoints( result );
	} catch ( error ) {
		// Endpoints will remain empty.
	}
}
