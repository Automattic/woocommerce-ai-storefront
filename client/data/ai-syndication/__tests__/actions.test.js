/**
 * Tests for AI Syndication thunk actions.
 */

import apiFetch from '@wordpress/api-fetch';
import { dispatch as globalDispatch } from '@wordpress/data';

// Mock the modules before importing actions.
jest.mock( '@wordpress/api-fetch' );
jest.mock( '@wordpress/data', () => ( {
	dispatch: jest.fn( () => ( {
		createSuccessNotice: jest.fn(),
		createErrorNotice: jest.fn(),
	} ) ),
} ) );
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	sprintf: ( str, ...args ) => {
		let result = str;
		args.forEach( ( arg, i ) => {
			result = result.replace( `%${ i + 1 }$s`, arg ).replace( `%${ i + 1 }$d`, arg );
			result = result.replace( '%s', arg ).replace( '%d', arg );
		} );
		return result;
	},
} ) );

import {
	saveSettings,
	fetchBots,
	createBots,
	deleteBot,
	updateSettings,
	setBots,
	setIsSaving,
	setNewBotKey,
} from '../actions';

describe( 'AI Syndication actions', () => {
	let mockDispatch;
	let mockSelect;

	beforeEach( () => {
		jest.clearAllMocks();
		mockDispatch = {
			setIsSaving: jest.fn(),
			updateSettings: jest.fn(),
			setIsLoadingBots: jest.fn(),
			setBots: jest.fn(),
			setNewBotKey: jest.fn(),
			fetchBots: jest.fn(),
			setStats: jest.fn(),
			setEndpoints: jest.fn(),
		};
		mockSelect = {
			getSettings: jest.fn( () => ( { enabled: 'yes', rate_limit_rpm: 60 } ) ),
		};
	} );

	// ------------------------------------------------------------------
	// Sync action creators
	// ------------------------------------------------------------------

	describe( 'sync actions', () => {
		it( 'updateSettings returns SET_SETTINGS action', () => {
			const action = updateSettings( { enabled: 'yes' } );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_SETTINGS' );
			expect( action.data ).toEqual( { enabled: 'yes' } );
		} );

		it( 'setBots returns SET_BOTS action', () => {
			const action = setBots( [ { id: 'bot-1' } ] );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_BOTS' );
		} );

		it( 'setIsSaving returns SET_IS_SAVING action', () => {
			const action = setIsSaving( true, null );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_IS_SAVING' );
			expect( action.isSaving ).toBe( true );
		} );

		it( 'setNewBotKey returns SET_NEW_BOT_KEY action', () => {
			const action = setNewBotKey( { bot_id: 'id', api_key: 'key' } );
			expect( action.type ).toBe( 'SET_AI_SYNDICATION_NEW_BOT_KEY' );
		} );
	} );

	// ------------------------------------------------------------------
	// saveSettings thunk
	// ------------------------------------------------------------------

	describe( 'saveSettings', () => {
		it( 'sends settings to API and dispatches result on success', async () => {
			const apiResult = { enabled: 'yes', rate_limit_rpm: 60 };
			apiFetch.mockResolvedValue( apiResult );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setIsSaving ).toHaveBeenCalledWith( true, null );
			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( { method: 'POST' } )
			);
			expect( mockDispatch.updateSettings ).toHaveBeenCalledWith( apiResult );
			expect( mockDispatch.setIsSaving ).toHaveBeenCalledWith( false, null );
		} );

		it( 'dispatches error notice on failure and preserves error state', async () => {
			const error = new Error( 'Network error' );
			apiFetch.mockRejectedValue( error );

			const thunk = saveSettings();
			await thunk( { dispatch: mockDispatch, select: mockSelect } );

			expect( mockDispatch.setIsSaving ).toHaveBeenCalledWith( false, error );
			// Should NOT call setIsSaving(false, null) after error — the return prevents it.
			const calls = mockDispatch.setIsSaving.mock.calls;
			const lastCall = calls[ calls.length - 1 ];
			expect( lastCall ).toEqual( [ false, error ] );
		} );
	} );

	// ------------------------------------------------------------------
	// fetchBots thunk
	// ------------------------------------------------------------------

	describe( 'fetchBots', () => {
		it( 'loads bots from API', async () => {
			const bots = [ { id: 'bot-1', name: 'ChatGPT' } ];
			apiFetch.mockResolvedValue( bots );

			const thunk = fetchBots();
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setIsLoadingBots ).toHaveBeenCalledWith( true );
			expect( mockDispatch.setBots ).toHaveBeenCalledWith( bots );
			expect( mockDispatch.setIsLoadingBots ).toHaveBeenCalledWith( false );
		} );

		it( 'shows error notice on failure', async () => {
			apiFetch.mockRejectedValue( new Error( 'fail' ) );

			const thunk = fetchBots();
			await thunk( { dispatch: mockDispatch } );

			expect( globalDispatch ).toHaveBeenCalledWith( 'core/notices' );
			expect( mockDispatch.setIsLoadingBots ).toHaveBeenCalledWith( false );
		} );
	} );

	// ------------------------------------------------------------------
	// createBots thunk
	// ------------------------------------------------------------------

	describe( 'createBots', () => {
		it( 'creates multiple bots sequentially', async () => {
			apiFetch
				.mockResolvedValueOnce( { bot_id: 'id-1', name: 'ChatGPT', api_key: 'key-1' } )
				.mockResolvedValueOnce( { bot_id: 'id-2', name: 'Gemini', api_key: 'key-2' } );

			const thunk = createBots( [ 'ChatGPT (OpenAI)', 'Gemini (Google)' ] );
			await thunk( { dispatch: mockDispatch } );

			expect( apiFetch ).toHaveBeenCalledTimes( 2 );
			// Should store all keys as an array.
			expect( mockDispatch.setNewBotKey ).toHaveBeenCalledWith( [
				expect.objectContaining( { bot_id: 'id-1' } ),
				expect.objectContaining( { bot_id: 'id-2' } ),
			] );
			// Should only fetch bots once at the end.
			expect( mockDispatch.fetchBots ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'handles partial failure gracefully', async () => {
			apiFetch
				.mockResolvedValueOnce( { bot_id: 'id-1', name: 'ChatGPT', api_key: 'key-1' } )
				.mockRejectedValueOnce( new Error( 'server error' ) );

			const thunk = createBots( [ 'ChatGPT (OpenAI)', 'Gemini (Google)' ] );
			await thunk( { dispatch: mockDispatch } );

			// First bot succeeded, second failed.
			expect( mockDispatch.setNewBotKey ).toHaveBeenCalledWith( [
				expect.objectContaining( { bot_id: 'id-1' } ),
			] );
			// Error notice for the failed one.
			expect( globalDispatch ).toHaveBeenCalledWith( 'core/notices' );
			// Still fetches bots (first one was created).
			expect( mockDispatch.fetchBots ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does nothing when all creations fail', async () => {
			apiFetch.mockRejectedValue( new Error( 'server error' ) );

			const thunk = createBots( [ 'ChatGPT' ] );
			await thunk( { dispatch: mockDispatch } );

			expect( mockDispatch.setNewBotKey ).not.toHaveBeenCalled();
			expect( mockDispatch.fetchBots ).not.toHaveBeenCalled();
		} );
	} );

	// ------------------------------------------------------------------
	// deleteBot thunk
	// ------------------------------------------------------------------

	describe( 'deleteBot', () => {
		it( 'deletes bot and refreshes list', async () => {
			apiFetch.mockResolvedValue( {} );

			const thunk = deleteBot( 'bot-1' );
			await thunk( { dispatch: mockDispatch } );

			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( { method: 'DELETE' } )
			);
			expect( mockDispatch.fetchBots ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'shows error notice on failure', async () => {
			apiFetch.mockRejectedValue( new Error( 'fail' ) );

			const thunk = deleteBot( 'bot-1' );
			await thunk( { dispatch: mockDispatch } );

			expect( globalDispatch ).toHaveBeenCalledWith( 'core/notices' );
		} );
	} );
} );
