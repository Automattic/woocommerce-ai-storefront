import {
	getSettings,
	isSaving,
	getSavingError,
	getStats,
	getEndpoints,
	getEndpointStatus,
} from '../selectors';

describe( 'AI Syndication selectors', () => {
	const fullState = {
		settings: { enabled: 'yes', rate_limit_rpm: 25 },
		isSaving: true,
		savingError: new Error( 'test' ),
		stats: { total_orders: 5 },
		endpoints: { llms_txt: '/llms.txt' },
		endpointStatus: { llms_txt: 'reachable', ucp: 'unreachable' },
	};

	describe( 'with populated state', () => {
		it( 'getSettings returns settings object', () => {
			expect( getSettings( fullState ) ).toEqual( fullState.settings );
		} );

		it( 'isSaving returns saving flag', () => {
			expect( isSaving( fullState ) ).toBe( true );
		} );

		it( 'getSavingError returns error', () => {
			expect( getSavingError( fullState ) ).toBe( fullState.savingError );
		} );

		it( 'getStats returns stats', () => {
			expect( getStats( fullState ) ).toEqual( fullState.stats );
		} );

		it( 'getEndpoints returns endpoints', () => {
			expect( getEndpoints( fullState ) ).toEqual( fullState.endpoints );
		} );

		it( 'getEndpointStatus returns status map', () => {
			expect( getEndpointStatus( fullState ) ).toEqual(
				fullState.endpointStatus
			);
		} );
	} );

	describe( 'with undefined/null state', () => {
		it( 'getSettings returns empty object', () => {
			expect( getSettings( undefined ) ).toEqual( {} );
		} );

		it( 'isSaving returns false', () => {
			expect( isSaving( undefined ) ).toBe( false );
		} );

		it( 'getSavingError returns null', () => {
			expect( getSavingError( undefined ) ).toBeNull();
		} );

		it( 'getStats returns null', () => {
			expect( getStats( undefined ) ).toBeNull();
		} );

		it( 'getEndpoints returns empty object', () => {
			expect( getEndpoints( undefined ) ).toEqual( {} );
		} );

		it( 'getEndpointStatus returns empty object', () => {
			expect( getEndpointStatus( undefined ) ).toEqual( {} );
		} );
	} );

	describe( 'referential stability', () => {
		it( 'getSettings returns same reference for repeated calls', () => {
			const emptyState = {};
			expect( getSettings( emptyState ) ).toBe(
				getSettings( emptyState )
			);
		} );
	} );
} );
