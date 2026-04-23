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

export function setRecentOrders( data ) {
	return { type: ACTION_TYPES.SET_RECENT_ORDERS, data };
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
				__( 'Settings saved.', 'woocommerce-ai-storefront' )
			);
		} catch ( error ) {
			dispatch.setIsSaving( false, error );
			globalDispatch( 'core/notices' ).createErrorNotice(
				__( 'Error saving settings.', 'woocommerce-ai-storefront' )
			);
			return;
		}

		dispatch.setIsSaving( false, null );

		// Saves can change endpoint reachability: toggling enabled adds
		// or removes the rewrite targets; saving Discovery settings can
		// change which crawlers robots.txt advertises. Re-probe so the
		// status badges reflect the new state rather than the pre-save
		// state.
		dispatch.checkEndpoints();

		// Refresh the AI Orders table too. The most common case where
		// a merchant returns to this page after generating a new
		// AI-attributed order in another tab is "adjust a setting,
		// hit save, notice the new order didn't appear in Recent
		// AI Orders." Coupling the refetch to saveSettings mirrors
		// the checkEndpoints pattern above — every save side-effect
		// that invalidates visible data triggers its own refresh.
		dispatch.fetchRecentOrders();
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

/**
 * Fetch the recent AI-attributed orders list that feeds the Overview
 * tab's DataViews table. Server returns agents already canonicalized
 * through KNOWN_AGENT_HOSTS so legacy hostnames display as brand names
 * (see /admin/recent-orders endpoint).
 *
 * @param {number} perPage How many orders to request (1-50, default 10).
 */
export function fetchRecentOrders( perPage = 10 ) {
	return async ( { dispatch } ) => {
		try {
			const result = await apiFetch( {
				path: `${ ADMIN_NAMESPACE }/recent-orders?per_page=${ perPage }`,
			} );
			dispatch.setRecentOrders( result );
		} catch ( error ) {
			// Silent failure — the table renders an empty state if
			// recentOrders stays null.
		}
	};
}

// -------------------------------------------------------------------
// Endpoint status probes
// -------------------------------------------------------------------

/**
 * Set the status for a single endpoint key.
 *
 * @param {string} key    Endpoint key (llms_txt / ucp / store_api).
 * @param {string} status One of: checking | reachable | unreachable | disabled.
 */
export function setEndpointStatus( key, status ) {
	return { type: ACTION_TYPES.SET_ENDPOINT_STATUS, key, status };
}

export function resetEndpointStatus() {
	return { type: ACTION_TYPES.RESET_ENDPOINT_STATUS };
}

/**
 * Probe each known discovery endpoint URL to classify its reachability.
 *
 * Runs in the browser: we `fetch(url, { method: 'HEAD' })` against the
 * same origin. HEAD returns just headers, so we can classify a response
 * without downloading the full body. We treat:
 *
 *   - HTTP 2xx              -> 'reachable'
 *   - HTTP 3xx/4xx/5xx      -> 'unreachable' (includes the 404 we expect
 *                              when syndication is disabled — except we
 *                              short-circuit that case below)
 *   - Network / CORS error  -> 'unreachable'
 *
 * When `settings.enabled !== 'yes'`, we skip the probes entirely and mark
 * all four endpoints (llms.txt, UCP manifest, Store API, robots.txt) as
 * 'disabled'. That's more honest than showing red X's for a state the
 * merchant intentionally chose.
 */
export function checkEndpoints() {
	return async ( { dispatch, select } ) => {
		const endpoints = select.getEndpoints();

		// Nothing to probe if the backend hasn't told us the URLs yet.
		if ( ! endpoints || ! endpoints.llms_txt ) {
			return;
		}

		// Syndication off -> endpoints are intentionally 404. Don't
		// probe; display 'disabled' badges instead of misleading X's.
		//
		// robots.txt is the one exception where the URL stays
		// technically reachable (WordPress always serves it) — but for
		// consistency with the other rows, 'disabled' here means "our
		// plugin's AI-crawler rules are not being appended right now."
		// The link in the UI stays clickable so the merchant can still
		// see what WordPress serves by default.
		const settings = select.getSettings();
		if ( settings.enabled !== 'yes' ) {
			dispatch.setEndpointStatus( 'llms_txt', 'disabled' );
			dispatch.setEndpointStatus( 'ucp', 'disabled' );
			dispatch.setEndpointStatus( 'store_api', 'disabled' );
			dispatch.setEndpointStatus( 'robots', 'disabled' );
			return;
		}

		// Mark all as 'checking' so the UI can render spinners
		// immediately. Each probe then resolves independently.
		dispatch.setEndpointStatus( 'llms_txt', 'checking' );
		dispatch.setEndpointStatus( 'ucp', 'checking' );
		dispatch.setEndpointStatus( 'store_api', 'checking' );
		dispatch.setEndpointStatus( 'robots', 'checking' );

		const probe = async ( key, url ) => {
			try {
				// `same-origin` credentials so cookie-auth staging sites
				// behind basic auth still reach their own endpoints.
				// `cache: 'no-store'` bypasses the browser's HTTP cache
				// so stale 404s from a previous broken deploy don't mask
				// a newly-working endpoint.
				const response = await fetch( url, {
					method: 'HEAD',
					credentials: 'same-origin',
					cache: 'no-store',
				} );
				dispatch.setEndpointStatus(
					key,
					response.ok ? 'reachable' : 'unreachable'
				);
			} catch ( error ) {
				// Network error, CORS rejection, DNS failure -> unreachable.
				dispatch.setEndpointStatus( key, 'unreachable' );
			}
		};

		await Promise.all( [
			probe( 'llms_txt', endpoints.llms_txt ),
			probe( 'ucp', endpoints.ucp ),
			probe( 'store_api', endpoints.store_api ),
			probe( 'robots', endpoints.robots ),
		] );
	};
}
