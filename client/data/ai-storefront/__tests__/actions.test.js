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
	fetchEndpoints,
	fetchRecentOrders,
	updateSettings,
	setStats,
	setStatsError,
	setEndpoints,
	setEndpointsError,
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
		// `jest.clearAllMocks()` clears call history but does NOT reset
		// mock IMPLEMENTATIONS. Without this restore, a nested describe
		// that calls `dispatch.mockImplementation(...)` (the
		// "error notice detail surfacing" block below does this for
		// each test to capture `createErrorNoticeMock`) would leak its
		// per-test implementation into every subsequent test in the
		// file — they'd see stale mock methods bound to closures from
		// already-completed tests instead of fresh `jest.fn()`s. Reset
		// the implementation to the default factory here so every test
		// starts with the same `dispatch()` return shape regardless of
		// describe-block ordering.
		// eslint-disable-next-line @typescript-eslint/no-require-imports, global-require
		const { dispatch } = require( '@wordpress/data' );
		dispatch.mockImplementation( () => ( {
			createSuccessNotice: jest.fn(),
			createErrorNotice: jest.fn(),
		} ) );
		mockDispatch = {
			setIsSaving: jest.fn(),
			updateSettings: jest.fn(),
			setStats: jest.fn(),
			setStatsError: jest.fn(),
			setEndpoints: jest.fn(),
			setEndpointsError: jest.fn(),
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
				ucp_api: 'https://example.com/wp-json/wc/ucp/v1',
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

		it( 'setStatsError returns SET_STATS_ERROR action with the error', () => {
			const error = new Error( 'stats fetch failed' );
			const action = setStatsError( error );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_STATS_ERROR' );
			expect( action.error ).toBe( error );
		} );

		it( 'setEndpoints returns SET_ENDPOINTS action', () => {
			const data = { llms_txt: 'https://example.com/llms.txt' };
			const action = setEndpoints( data );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_ENDPOINTS' );
			expect( action.data ).toEqual( data );
		} );

		it( 'setEndpointsError returns SET_ENDPOINTS_ERROR action with the error', () => {
			const error = new Error( 'endpoints fetch failed' );
			const action = setEndpointsError( error );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_ENDPOINTS_ERROR' );
			expect( action.error ).toBe( error );
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

		// The merchant-facing notice should carry the server's error
		// detail when available, not a generic placeholder. Pre-fix,
		// every error mode (400 validation, 403 nonce-expired, 500
		// fatal, network drop) collapsed to the same five-word
		// message. These tests pin the message-detail fallback chain.
		describe( 'error notice detail surfacing', () => {
			let createErrorNoticeMock;

			beforeEach( () => {
				createErrorNoticeMock = jest.fn();
				const { dispatch } = require( '@wordpress/data' );
				dispatch.mockImplementation( () => ( {
					createSuccessNotice: jest.fn(),
					createErrorNotice: createErrorNoticeMock,
				} ) );
			} );

			it( 'surfaces error.message when present (typical WP_Error case)', async () => {
				// WP_Error from `apiFetch` rejection carries a
				// server-translated `message` field. The merchant
				// sees the server's detail verbatim.
				const error = Object.assign( new Error(), {
					code: 'rest_invalid_param',
					message: 'Invalid parameter: rate_limit_rpm.',
					data: { status: 400 },
				} );
				apiFetch.mockRejectedValue( error );

				const thunk = saveSettings();
				await thunk( {
					dispatch: mockDispatch,
					select: mockSelect,
				} );

				expect( createErrorNoticeMock ).toHaveBeenCalledWith(
					'Invalid parameter: rate_limit_rpm.'
				);
			} );

			it( 'falls back to generic when error.message is empty string', async () => {
				// Rare but possible: an exotic rejection shape with
				// no message (e.g. a misbehaving `apiFetch` filter
				// throwing a bare object). The empty-string guard
				// catches this.
				apiFetch.mockRejectedValue( { message: '' } );

				const thunk = saveSettings();
				await thunk( {
					dispatch: mockDispatch,
					select: mockSelect,
				} );

				expect( createErrorNoticeMock ).toHaveBeenCalledWith(
					'Error saving settings.'
				);
			} );

			it( 'falls back to generic when error has no message field', async () => {
				// Some thrown values (raw strings, Response objects)
				// have no `message` property at all. Optional-chain +
				// type check handle this.
				apiFetch.mockRejectedValue( { code: 'something_broke' } );

				const thunk = saveSettings();
				await thunk( {
					dispatch: mockDispatch,
					select: mockSelect,
				} );

				expect( createErrorNoticeMock ).toHaveBeenCalledWith(
					'Error saving settings.'
				);
			} );

			it( 'falls back to generic when error.message is non-string (defensive)', async () => {
				// Defensive: a filter that mistakenly nests an object
				// under `message` shouldn't render `[object Object]`
				// in the merchant's notice.
				apiFetch.mockRejectedValue( {
					message: { nested: 'oops' },
				} );

				const thunk = saveSettings();
				await thunk( {
					dispatch: mockDispatch,
					select: mockSelect,
				} );

				expect( createErrorNoticeMock ).toHaveBeenCalledWith(
					'Error saving settings.'
				);
			} );

			it( 'surfaces native Error.message (network failure case)', async () => {
				// `apiFetch` wraps a network failure in a TypeError
				// with a meaningful message ("Failed to fetch") in
				// most browsers. The merchant sees that directly.
				apiFetch.mockRejectedValue(
					new TypeError( 'Failed to fetch' )
				);

				const thunk = saveSettings();
				await thunk( {
					dispatch: mockDispatch,
					select: mockSelect,
				} );

				expect( createErrorNoticeMock ).toHaveBeenCalledWith(
					'Failed to fetch'
				);
			} );

			it( 'falls back to generic when error is null (defensive)', async () => {
				// Optional-chain branch: `null?.message` is undefined,
				// the typeof check fails, fallback wins. Pinning this
				// because a Promise rejection without a value (e.g. a
				// middleware that does `Promise.reject()` with no
				// argument) lands here.
				apiFetch.mockRejectedValue( null );

				const thunk = saveSettings();
				await thunk( {
					dispatch: mockDispatch,
					select: mockSelect,
				} );

				expect( createErrorNoticeMock ).toHaveBeenCalledWith(
					'Error saving settings.'
				);
			} );

			it( 'falls back to generic when a raw string is thrown', async () => {
				// `'boom'.message === undefined` — a primitive string
				// has no `message` property, so the optional chain +
				// typeof check both fall through to the generic
				// fallback. Same shape applies to thrown numbers,
				// booleans, etc.
				apiFetch.mockRejectedValue( 'boom' );

				const thunk = saveSettings();
				await thunk( {
					dispatch: mockDispatch,
					select: mockSelect,
				} );

				expect( createErrorNoticeMock ).toHaveBeenCalledWith(
					'Error saving settings.'
				);
			} );
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
				'ucp_api',
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

			// One probe per endpoint: llms_txt, ucp, ucp_api, robots.
			expect( global.fetch ).toHaveBeenCalledTimes( 4 );
			expect( global.fetch ).toHaveBeenCalledWith(
				'https://example.com/llms.txt',
				expect.objectContaining( { method: 'HEAD' } )
			);
			// Pin the UCP API URL explicitly. Without this, a future
			// rename (e.g. `wc/ucp/v1` → `wc/ucp/v2` without updating
			// rest_url() in the controller) would silently pass the
			// "fetch called 4 times" check while every probe targeted
			// a wrong URL — the kind of regression an integer-count
			// assertion can't catch.
			expect( global.fetch ).toHaveBeenCalledWith(
				'https://example.com/wp-json/wc/ucp/v1',
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
				'ucp_api',
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
				'ucp_api',
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
				'ucp_api',
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
		it( 'loads stats from API and dispatches setStats on success', async () => {
			const stats = { total_orders: 10, total_revenue: 500 };
			apiFetch.mockResolvedValue( stats );

			const thunk = fetchStats( 'month' );
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setStats ).toHaveBeenCalledWith( stats );
			expect( mockDispatch.setStatsError ).not.toHaveBeenCalled();
		} );

		it( 'dispatches setStatsError on failure', async () => {
			// Regression for issue #161: the catch block was empty,
			// so the error was swallowed silently. Now setStatsError
			// is called so the store carries an observable error state.
			const error = new Error( 'stats API failed' );
			apiFetch.mockRejectedValue( error );

			const thunk = fetchStats( 'month' );
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setStats ).not.toHaveBeenCalled();
			expect( mockDispatch.setStatsError ).toHaveBeenCalledWith( error );
		} );

		it( 'uses "month" as the default period', async () => {
			apiFetch.mockResolvedValue( {} );

			const thunk = fetchStats();
			await thunk( { dispatch: mockDispatch } );

			expect( apiFetch ).toHaveBeenCalledWith( {
				path: expect.stringContaining( 'period=month' ),
			} );
		} );
	} );

	describe( 'fetchEndpoints', () => {
		it( 'loads endpoints from API and dispatches setEndpoints on success', async () => {
			const endpoints = {
				llms_txt: 'https://example.com/llms.txt',
				ucp: 'https://example.com/.well-known/ucp',
			};
			apiFetch.mockResolvedValue( endpoints );

			const thunk = fetchEndpoints();
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setEndpoints ).toHaveBeenCalledWith(
				endpoints
			);
			expect( mockDispatch.setEndpointsError ).not.toHaveBeenCalled();
		} );

		it( 'dispatches setEndpointsError on failure', async () => {
			// Regression for issue #161: the catch block was empty,
			// so endpoint fetch failures were unobservable. Now
			// setEndpointsError is called so consumers can surface
			// the error state rather than staying stuck on an empty
			// endpoints object.
			const error = new Error( 'endpoints API failed' );
			apiFetch.mockRejectedValue( error );

			const thunk = fetchEndpoints();
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setEndpoints ).not.toHaveBeenCalled();
			expect( mockDispatch.setEndpointsError ).toHaveBeenCalledWith(
				error
			);
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
