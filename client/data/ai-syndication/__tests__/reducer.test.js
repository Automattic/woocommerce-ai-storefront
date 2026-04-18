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
} );
