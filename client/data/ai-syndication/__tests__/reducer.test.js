import reducer from '../reducer';
import ACTION_TYPES from '../action-types';

describe( 'AI Syndication reducer', () => {
	const defaultState = {
		settings: {},
		isSaving: false,
		savingError: null,
		stats: null,
		endpoints: {},
		endpointStatus: {},
		recentOrders: null,
	};

	it( 'returns default state for undefined state', () => {
		const state = reducer( undefined, { type: 'INIT' } );
		expect( state ).toEqual( defaultState );
	} );

	it( 'returns state unchanged for unknown action type', () => {
		const state = { ...defaultState, settings: { enabled: 'yes' } };
		const result = reducer( state, { type: 'UNKNOWN_ACTION' } );
		expect( result ).toBe( state );
	} );

	describe( 'SET_SETTINGS', () => {
		it( 'replaces the full settings object', () => {
			const settings = { enabled: 'yes', rate_limit_rpm: 25 };
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_SETTINGS,
				data: settings,
			} );
			expect( state.settings ).toEqual( settings );
		} );
	} );

	describe( 'SET_SETTINGS_VALUES', () => {
		it( 'merges partial updates into existing settings', () => {
			const initial = {
				...defaultState,
				settings: { enabled: 'yes', rate_limit_rpm: 25 },
			};
			const state = reducer( initial, {
				type: ACTION_TYPES.SET_SETTINGS_VALUES,
				payload: { rate_limit_rpm: 50 },
			} );
			expect( state.settings ).toEqual( {
				enabled: 'yes',
				rate_limit_rpm: 50,
			} );
		} );

		it( 'clears savingError on settings update', () => {
			const initial = {
				...defaultState,
				savingError: new Error( 'previous error' ),
			};
			const state = reducer( initial, {
				type: ACTION_TYPES.SET_SETTINGS_VALUES,
				payload: { enabled: 'no' },
			} );
			expect( state.savingError ).toBeNull();
		} );
	} );

	describe( 'SET_IS_SAVING', () => {
		it( 'sets isSaving flag', () => {
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_IS_SAVING,
				isSaving: true,
				error: null,
			} );
			expect( state.isSaving ).toBe( true );
		} );

		it( 'stores error when save fails', () => {
			const error = new Error( 'Save failed' );
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_IS_SAVING,
				isSaving: false,
				error,
			} );
			expect( state.savingError ).toBe( error );
		} );
	} );

	describe( 'SET_STATS', () => {
		it( 'replaces stats data', () => {
			const stats = { total_orders: 10, total_revenue: 500 };
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_STATS,
				data: stats,
			} );
			expect( state.stats ).toEqual( stats );
		} );
	} );

	describe( 'SET_ENDPOINTS', () => {
		it( 'replaces endpoints data', () => {
			const endpoints = {
				llms_txt: '/llms.txt',
				ucp: '/.well-known/ucp',
			};
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_ENDPOINTS,
				data: endpoints,
			} );
			expect( state.endpoints ).toEqual( endpoints );
		} );
	} );

	describe( 'SET_ENDPOINT_STATUS', () => {
		it( 'sets a single endpoint status value', () => {
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_ENDPOINT_STATUS,
				key: 'llms_txt',
				status: 'reachable',
			} );
			expect( state.endpointStatus ).toEqual( {
				llms_txt: 'reachable',
			} );
		} );

		it( 'merges status updates without clobbering other keys', () => {
			// Two endpoints probed sequentially — each probe resolves
			// on its own clock. The reducer must preserve already-set
			// statuses rather than reset the whole map per dispatch.
			const afterLlms = reducer( defaultState, {
				type: ACTION_TYPES.SET_ENDPOINT_STATUS,
				key: 'llms_txt',
				status: 'reachable',
			} );
			const afterUcp = reducer( afterLlms, {
				type: ACTION_TYPES.SET_ENDPOINT_STATUS,
				key: 'ucp',
				status: 'unreachable',
			} );
			expect( afterUcp.endpointStatus ).toEqual( {
				llms_txt: 'reachable',
				ucp: 'unreachable',
			} );
		} );
	} );

	describe( 'RESET_ENDPOINT_STATUS', () => {
		it( 'clears the endpointStatus map', () => {
			const initial = {
				...defaultState,
				endpointStatus: {
					llms_txt: 'reachable',
					ucp: 'unreachable',
				},
			};
			const state = reducer( initial, {
				type: ACTION_TYPES.RESET_ENDPOINT_STATUS,
			} );
			expect( state.endpointStatus ).toEqual( {} );
		} );
	} );

	describe( 'SET_RECENT_ORDERS', () => {
		it( 'stores the recent orders payload', () => {
			const payload = {
				orders: [
					{
						id: 1,
						number: '1',
						date: '2026-04-19T10:15:30+00:00',
						date_display: 'April 19, 2026',
						status: 'processing',
						status_label: 'Processing',
						agent: 'Gemini',
						total: 55.36,
						currency: 'USD',
						edit_url:
							'https://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=1',
					},
				],
				total: 1,
				currency: 'USD',
			};
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_RECENT_ORDERS,
				data: payload,
			} );
			expect( state.recentOrders ).toEqual( payload );
		} );

		it( 'replaces previous recent orders on subsequent dispatches', () => {
			// The stored payload is wholesale-replaced on refetch;
			// no merging. Matches how SET_STATS / SET_SETTINGS
			// behave. Important for the refresh-on-save flow: a
			// save that triggers fetchRecentOrders() must fully
			// refresh the table, not append to it.
			const initial = {
				...defaultState,
				recentOrders: {
					orders: [ { id: 1, number: '1' } ],
					total: 1,
					currency: 'USD',
				},
			};
			const nextPayload = {
				orders: [
					{ id: 2, number: '2' },
					{ id: 3, number: '3' },
				],
				total: 2,
				currency: 'USD',
			};
			const state = reducer( initial, {
				type: ACTION_TYPES.SET_RECENT_ORDERS,
				data: nextPayload,
			} );
			expect( state.recentOrders ).toEqual( nextPayload );
			expect( state.recentOrders.orders ).toHaveLength( 2 );
		} );

		it( 'accepts empty orders array without crashing', () => {
			// The AI Orders table distinguishes "not fetched yet"
			// (null) from "fetched, zero results" (empty array).
			// An empty-array payload must land in state as-is so
			// the component can render its empty state instead of
			// flashing a skeleton.
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_RECENT_ORDERS,
				data: {
					orders: [],
					total: 0,
					currency: 'USD',
				},
			} );
			expect( state.recentOrders.orders ).toEqual( [] );
			expect( state.recentOrders.total ).toBe( 0 );
		} );
	} );
} );
