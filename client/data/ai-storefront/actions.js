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

export function setStatsError( error ) {
	return { type: ACTION_TYPES.SET_STATS_ERROR, error };
}

export function setEndpoints( data ) {
	return { type: ACTION_TYPES.SET_ENDPOINTS, data };
}

export function setEndpointsError( error ) {
	return { type: ACTION_TYPES.SET_ENDPOINTS_ERROR, error };
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
			// Surface the server's error detail so the merchant has
			// something actionable. `@wordpress/api-fetch` rejects
			// with an object carrying the WP_Error fields (`code`,
			// `message`, `data.status`); the message is already
			// server-translated via WP's locale so we use it
			// directly without wrapping in `__()` (the i18n
			// extractor only handles literal strings, and the
			// message isn't ours to translate).
			//
			// Pre-fix this surfaced a hardcoded "Error saving
			// settings." for every failure mode — 400 (validation
			// rejected one field), 403 (nonce expired after the tab
			// sat open for 24+ hours), 500 (PHP fatal in a settings
			// filter), network drop. Merchants got zero actionable
			// signal. Now they see the underlying message verbatim,
			// which is the most useful thing we can do without
			// per-error-code routing logic that would drift from
			// server-side error vocabulary.
			//
			// Fallback to the generic when `error.message` is empty
			// or non-string (rare — usually network failures still
			// resolve a TypeError with a message). String check
			// guards against an exotic rejection shape (e.g. a
			// raw `Response` thrown by a misbehaving filter) where
			// `error.message` could be `undefined` or an object.
			//
			// Safety: `createErrorNotice( string )` from
			// `@wordpress/notices` renders the body via React children,
			// which text-escapes — so a server emitting `<script>` in a
			// WP_Error message cannot XSS the admin. DO NOT switch to
			// `createErrorNotice( str, { __unstableHTML: true } )` or
			// any HTML-rendering variant without first sanitizing
			// `detail` server-side or via `wp_strip_all_tags`-equivalent
			// here. The text-escaping is the only thing that makes
			// passing the raw server message safe.
			const detail =
				typeof error?.message === 'string' && error.message
					? error.message
					: __(
							'Error saving settings.',
							'woocommerce-ai-storefront'
					  );
			globalDispatch( 'core/notices' ).createErrorNotice( detail );
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
			dispatch.setStatsError( error );
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
			dispatch.setEndpointsError( error );
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
			// On error, dispatch an empty-orders payload so the
			// selector returns a non-null value and the UI can
			// render its empty-state treatment. Without this,
			// `recentOrders` would stay at its initial `null`
			// forever — the AIOrdersTable component now uses that
			// `null` as a "not yet fetched" sentinel to avoid
			// flashing DataViews' "no results" row during the
			// loading window, and would never surface the empty
			// state if the fetch never resolves.
			//
			// Conflating error and genuine-empty is deliberate: the
			// merchant-facing UX for "we couldn't fetch your
			// orders right now" and "you haven't received any AI
			// orders yet" is the same — show the "Ready for your
			// first AI order" card. A dedicated error notice would
			// only matter if the fetch path were unreliable enough
			// that we expected merchants to hit it; in practice the
			// endpoint is same-origin REST to an admin already
			// authenticated to load this page. If real-world
			// telemetry shows this path fires often, split the
			// states then.
			// Matches the shape the /recent-orders REST endpoint
			// returns on success (`{ orders, total, currency }`) so
			// downstream code can treat `recentOrders` consistently
			// regardless of fetch outcome. A future consumer that
			// reads `recentOrders.total` or `.currency` will see a
			// sensible zero-state rather than `undefined`.
			dispatch.setRecentOrders( {
				orders: [],
				total: 0,
				currency: null,
			} );
		}
	};
}

// -------------------------------------------------------------------
// Endpoint status probes
// -------------------------------------------------------------------

/**
 * Set the status for a single endpoint key.
 *
 * @param {string} key    Endpoint key (llms_txt / ucp / ucp_api / robots).
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
 * all four endpoints (llms.txt, UCP manifest, UCP API, robots.txt) as
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
			dispatch.setEndpointStatus( 'ucp_api', 'disabled' );
			dispatch.setEndpointStatus( 'robots', 'disabled' );
			return;
		}

		// Mark all as 'checking' so the UI can render spinners
		// immediately. Each probe then resolves independently.
		dispatch.setEndpointStatus( 'llms_txt', 'checking' );
		dispatch.setEndpointStatus( 'ucp', 'checking' );
		dispatch.setEndpointStatus( 'ucp_api', 'checking' );
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
			probe( 'ucp_api', endpoints.ucp_api ),
			probe( 'robots', endpoints.robots ),
		] );
	};
}
