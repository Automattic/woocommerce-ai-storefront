/**
 * Tests for AI Syndication thunk actions.
 */

import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch' );
jest.mock( '@wordpress/data', () => ( {
	dispatch: jest.fn( () => ( {
		createSuccessNotice: jest.fn(),
		createErrorNotice: jest.fn(),
	} ) ),
} ) );
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

import {
	saveSettings,
	fetchStats,
	fetchRecentOrders,
	updateSettings,
	setStats,
	setIsSaving,
	setEndpointStatus,
	resetEndpointStatus,
	checkEndpoints,
} from '../actions';

describe( 'AI Syndication actions', () => {
	let mockDispatch;
	let mockSelect;

	beforeEach( () => {
		jest.clearAllMocks();
		mockDispatch = {
			setIsSaving: jest.fn(),
			updateSettings: jest.fn(),
			setStats: jest.fn(),
			setEndpoints: jest.fn(),
			setEndpointStatus: jest.fn(),
			resetEndpointStatus: jest.fn(),
			checkEndpoints: jest.fn(),
			setRecentOrders: jest.fn(),
			fetchRecentOrders: jest.fn(),
		};
		mockSelect = {
			getSettings: jest.fn( () => ( {
				enabled: 'yes',
				rate_limit_rpm: 25,
			} ) ),
			getEndpoints: jest.fn( () => ( {
				llms_txt: 'https://example.com/llms.txt',
				ucp: 'https://example.com/.well-known/ucp',
				store_api: 'https://example.com/wp-json/wc/store/v1',
				robots: 'https://example.com/robots.txt',
			} ) ),
		};
	} );

	describe( 'sync actions', () => {
		it( 'updateSettings returns SET_SETTINGS action', () => {
			const action = updateSettings( { enabled: 'yes' } );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_SETTINGS' );
			expect( action.data ).toEqual( { enabled: 'yes' } );
		} );

		it( 'setStats returns SET_STATS action', () => {
			const action = setStats( { total_orders: 5 } );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_STATS' );
		} );

		it( 'setIsSaving returns SET_IS_SAVING action', () => {
			const action = setIsSaving( true, null );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_IS_SAVING' );
			expect( action.isSaving ).toBe( true );
		} );

		it( 'setEndpointStatus carries key and status', () => {
			const action = setEndpointStatus( 'llms_txt', 'reachable' );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_ENDPOINT_STATUS' );
			expect( action.key ).toBe( 'llms_txt' );
			expect( action.status ).toBe( 'reachable' );
		} );

		it( 'resetEndpointStatus returns the reset action', () => {
			const action = resetEndpointStatus();
			expect( action.type ).toBe(
				'RESET_AI_SYNDICATION_ENDPOINT_STATUS'
			);
		} );
	} );

	describe( 'saveSettings', () => {
		it( 'sends settings to API and dispatches result on success', async () => {
			const apiResult = { enabled: 'yes', rate_limit_rpm: 25 };
			apiFetch.mockResolvedValue( apiResult );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setIsSaving ).toHaveBeenCalledWith(
				true,
				null
			);
			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( { method: 'POST' } )
			);
			expect( mockDispatch.updateSettings ).toHaveBeenCalledWith(
				apiResult
			);
			expect( mockDispatch.setIsSaving ).toHaveBeenCalledWith(
				false,
				null
			);
		} );

		it( 'triggers endpoint re-probe after a successful save', async () => {
			// Toggling enabled or editing crawlers can change endpoint
			// reachability; saveSettings should refresh the status.
			apiFetch.mockResolvedValue( { enabled: 'yes' } );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.checkEndpoints ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does NOT re-probe endpoints when save fails', async () => {
			// If the save itself didn't land, there's nothing to re-probe.
			apiFetch.mockRejectedValue( new Error( 'Network error' ) );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.checkEndpoints ).not.toHaveBeenCalled();
		} );

		it( 'refreshes recent orders after a successful save', async () => {
			// Saving settings that affect AI order flow (enabled
			// state, crawler list, rate limits) can produce new
			// AI-attributed orders between when the tab was
			// loaded and when the save completes — the AI Orders
			// table must refetch so the merchant sees them.
			// Symmetric with the checkEndpoints re-probe above.
			apiFetch.mockResolvedValue( { enabled: 'yes' } );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.fetchRecentOrders ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does NOT refresh recent orders when save fails', async () => {
			// Save failed → settings didn't change → no reason
			// to believe the orders list is stale. Skipping the
			// refetch saves a REST round-trip on the failure
			// path. Symmetric with the checkEndpoints skip above.
			apiFetch.mockRejectedValue( new Error( 'Network error' ) );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.fetchRecentOrders ).not.toHaveBeenCalled();
		} );

		it( 'dispatches error notice on failure and preserves error state', async () => {
			const error = new Error( 'Network error' );
			apiFetch.mockRejectedValue( error );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setIsSaving ).toHaveBeenCalledWith(
				false,
				error
			);
			const calls = mockDispatch.setIsSaving.mock.calls;
			const lastCall = calls[ calls.length - 1 ];
			expect( lastCall ).toEqual( [ false, error ] );
		} );
	} );

	describe( 'checkEndpoints', () => {
		beforeEach( () => {
			// jsdom provides `fetch` by default but we need control over
			// its resolution. Replace with a Jest mock per test.
			global.fetch = jest.fn();
		} );

		afterEach( () => {
			delete global.fetch;
		} );

		it( 'short-circuits when endpoints are not yet loaded', async () => {
			mockSelect.getEndpoints = jest.fn( () => ( {} ) );

			const thunk = checkEndpoints();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setEndpointStatus ).not.toHaveBeenCalled();
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'marks everything disabled when syndication is off', async () => {
			// When the merchant has toggled off, llms.txt / UCP manifest
			// intentionally return 404 — probing would produce misleading
			// red X's. robots.txt stays reachable at the URL level (WP
			// always serves it), but our AI-crawler rules aren't being
			// appended, so we flag it disabled for consistency.
			mockSelect.getSettings = jest.fn( () => ( { enabled: 'no' } ) );

			const thunk = checkEndpoints();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'llms_txt',
				'disabled'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'ucp',
				'disabled'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'store_api',
				'disabled'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'robots',
				'disabled'
			);
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'probes each endpoint with HEAD and resolves to reachable on 2xx', async () => {
			global.fetch.mockResolvedValue( { ok: true, status: 200 } );

			const thunk = checkEndpoints();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			// One probe per endpoint: llms_txt, ucp, store_api, robots.
			expect( global.fetch ).toHaveBeenCalledTimes( 4 );
			expect( global.fetch ).toHaveBeenCalledWith(
				'https://example.com/llms.txt',
				expect.objectContaining( { method: 'HEAD' } )
			);
			expect( global.fetch ).toHaveBeenCalledWith(
				'https://example.com/robots.txt',
				expect.objectContaining( { method: 'HEAD' } )
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'llms_txt',
				'reachable'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'ucp',
				'reachable'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'store_api',
				'reachable'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'robots',
				'reachable'
			);
		} );

		it( 'marks unreachable on non-2xx response', async () => {
			// The exact 404 case our rewrite-flush bug produced: the URL
			// exists in theory but WordPress routes it as "not found"
			// because the in-memory rewrite rules are stale.
			global.fetch.mockResolvedValue( { ok: false, status: 404 } );

			const thunk = checkEndpoints();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'llms_txt',
				'unreachable'
			);
		} );

		it( 'marks unreachable on network error', async () => {
			// CORS rejection, DNS failure, origin down — all of these
			// cause fetch to reject rather than resolve. They should
			// render the same as an HTTP error: ✗ Not reachable.
			global.fetch.mockRejectedValue(
				new TypeError( 'Failed to fetch' )
			);

			const thunk = checkEndpoints();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'llms_txt',
				'unreachable'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'ucp',
				'unreachable'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'store_api',
				'unreachable'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'robots',
				'unreachable'
			);
		} );

		it( 'sets checking state before probing', async () => {
			// UI relies on this to show spinners during the probe.
			let resolveFetch;
			global.fetch.mockReturnValue(
				new Promise( ( resolve ) => {
					resolveFetch = resolve;
				} )
			);

			const thunk = checkEndpoints();
			const promise = thunk( {
				dispatch: mockDispatch,
				select: mockSelect,
			} );

			// Before resolving any fetch, the four 'checking' dispatches
			// must have landed.
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'llms_txt',
				'checking'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'ucp',
				'checking'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'store_api',
				'checking'
			);
			expect( mockDispatch.setEndpointStatus ).toHaveBeenCalledWith(
				'robots',
				'checking'
			);

			// Resolve to let the thunk complete so Jest doesn't leak.
			resolveFetch( { ok: true, status: 200 } );
			await promise;
		} );
	} );

	describe( 'fetchStats', () => {
		it( 'loads stats from API', async () => {
			const stats = { total_orders: 10, total_revenue: 500 };
			apiFetch.mockResolvedValue( stats );

			const thunk = fetchStats( 'month' );
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setStats ).toHaveBeenCalledWith( stats );
		} );

		it( 'silently fails on error', async () => {
			apiFetch.mockRejectedValue( new Error( 'fail' ) );

			const thunk = fetchStats( 'month' );
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setStats ).not.toHaveBeenCalled();
		} );
	} );

	// `fetchRecentOrders` powers the Overview tab's AI Orders table.
	// Two test-worthy contracts:
	//   1. On success, the full endpoint payload is forwarded to the
	//      reducer so downstream code can rely on
	//      `{ orders, total, currency }`.
	//   2. On failure (network / server / auth error), the thunk
	//      dispatches an empty-shape payload — NOT silent-null — so
	//      the component's "fetched with zero rows" branch renders
	//      the empty-state card instead of leaving the UI stuck on
	//      the loading sentinel forever.
	describe( 'fetchRecentOrders', () => {
		it( 'forwards the response to setRecentOrders on success', async () => {
			const payload = {
				orders: [ { id: 1, number: '1001' } ],
				total: 1,
				currency: 'USD',
			};
			apiFetch.mockResolvedValue( payload );

			const thunk = fetchRecentOrders( 10 );
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setRecentOrders ).toHaveBeenCalledWith(
				payload
			);
		} );

		it( 'dispatches a full-shape empty payload on error', async () => {
			// Scenario: /admin/recent-orders returns 500, or the
			// network drops. Before this contract, `recentOrders`
			// stayed at its initial `null` and the AIOrdersTable
			// component rendered nothing at all — no loading,
			// no empty state, just absence. We dispatch a
			// shape-compatible empty result so the "fetched +
			// empty" branch wins.
			apiFetch.mockRejectedValue( new Error( 'boom' ) );

			const thunk = fetchRecentOrders( 10 );
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setRecentOrders ).toHaveBeenCalledWith( {
				orders: [],
				total: 0,
				currency: null,
			} );
		} );

		it( 'defaults perPage to 10', async () => {
			apiFetch.mockResolvedValue( {
				orders: [],
				total: 0,
				currency: 'USD',
			} );

			const thunk = fetchRecentOrders();
			await thunk( { dispatch: mockDispatch } );

			expect( apiFetch ).toHaveBeenCalledWith( {
				path: expect.stringContaining( 'per_page=10' ),
			} );
		} );
	} );
} );
