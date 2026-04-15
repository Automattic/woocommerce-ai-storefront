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
	updateSettings,
	setStats,
	setIsSaving,
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
		};
		mockSelect = {
			getSettings: jest.fn( () => ( {
				enabled: 'yes',
				rate_limit_rpm: 25,
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
} );
