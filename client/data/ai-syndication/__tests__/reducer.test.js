import reducer from '../reducer';
import ACTION_TYPES from '../action-types';

describe( 'AI Syndication reducer', () => {
	const defaultState = {
		settings: {},
		isSaving: false,
		savingError: null,
		bots: [],
		isLoadingBots: false,
		stats: null,
		endpoints: {},
		newBotKey: null,
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
			const settings = { enabled: 'yes', rate_limit_rpm: 60 };
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
				settings: { enabled: 'yes', rate_limit_rpm: 60 },
			};
			const state = reducer( initial, {
				type: ACTION_TYPES.SET_SETTINGS_VALUES,
				payload: { rate_limit_rpm: 120 },
			} );
			expect( state.settings ).toEqual( {
				enabled: 'yes',
				rate_limit_rpm: 120,
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
			expect( state.savingError ).toBeNull();
		} );

		it( 'stores error when save fails', () => {
			const error = new Error( 'Save failed' );
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_IS_SAVING,
				isSaving: false,
				error,
			} );
			expect( state.isSaving ).toBe( false );
			expect( state.savingError ).toBe( error );
		} );
	} );

	describe( 'SET_BOTS', () => {
		it( 'replaces the bots array', () => {
			const bots = [
				{ id: 'bot-1', name: 'ChatGPT' },
				{ id: 'bot-2', name: 'Gemini' },
			];
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_BOTS,
				data: bots,
			} );
			expect( state.bots ).toEqual( bots );
		} );
	} );

	describe( 'SET_IS_LOADING_BOTS', () => {
		it( 'sets loading flag', () => {
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_IS_LOADING_BOTS,
				isLoading: true,
			} );
			expect( state.isLoadingBots ).toBe( true );
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

	describe( 'SET_NEW_BOT_KEY', () => {
		it( 'stores newly created bot key data', () => {
			const botKeyData = {
				bot_id: 'uuid-123',
				api_key: 'wc_ai_abc123',
			};
			const state = reducer( defaultState, {
				type: ACTION_TYPES.SET_NEW_BOT_KEY,
				data: botKeyData,
			} );
			expect( state.newBotKey ).toEqual( botKeyData );
		} );
	} );
} );
