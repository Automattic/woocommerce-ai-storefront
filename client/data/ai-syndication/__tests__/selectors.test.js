import {
	getSettings,
	isSaving,
	getSavingError,
	getBots,
	isLoadingBots,
	getStats,
	getEndpoints,
	getNewBotKey,
} from '../selectors';

describe( 'AI Syndication selectors', () => {
	const fullState = {
		settings: { enabled: 'yes', rate_limit_rpm: 60 },
		isSaving: true,
		savingError: new Error( 'test' ),
		bots: [ { id: 'bot-1', name: 'ChatGPT' } ],
		isLoadingBots: true,
		stats: { total_orders: 5 },
		endpoints: { llms_txt: '/llms.txt' },
		newBotKey: { bot_id: 'id', api_key: 'key' },
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

		it( 'getBots returns bots array', () => {
			expect( getBots( fullState ) ).toEqual( fullState.bots );
		} );

		it( 'isLoadingBots returns loading flag', () => {
			expect( isLoadingBots( fullState ) ).toBe( true );
		} );

		it( 'getStats returns stats', () => {
			expect( getStats( fullState ) ).toEqual( fullState.stats );
		} );

		it( 'getEndpoints returns endpoints', () => {
			expect( getEndpoints( fullState ) ).toEqual( fullState.endpoints );
		} );

		it( 'getNewBotKey returns bot key data', () => {
			expect( getNewBotKey( fullState ) ).toEqual( fullState.newBotKey );
		} );
	} );

	describe( 'with undefined/null state', () => {
		it( 'getSettings returns empty object for undefined state', () => {
			const result = getSettings( undefined );
			expect( result ).toEqual( {} );
		} );

		it( 'isSaving returns false for undefined state', () => {
			expect( isSaving( undefined ) ).toBe( false );
		} );

		it( 'getSavingError returns null for undefined state', () => {
			expect( getSavingError( undefined ) ).toBeNull();
		} );

		it( 'getBots returns empty array for undefined state', () => {
			const result = getBots( undefined );
			expect( result ).toEqual( [] );
		} );

		it( 'isLoadingBots returns false for undefined state', () => {
			expect( isLoadingBots( undefined ) ).toBe( false );
		} );

		it( 'getStats returns null for undefined state', () => {
			expect( getStats( undefined ) ).toBeNull();
		} );

		it( 'getEndpoints returns empty object for undefined state', () => {
			const result = getEndpoints( undefined );
			expect( result ).toEqual( {} );
		} );

		it( 'getNewBotKey returns null for undefined state', () => {
			expect( getNewBotKey( undefined ) ).toBeNull();
		} );
	} );

	describe( 'referential stability', () => {
		it( 'getSettings returns same reference for repeated calls with same state', () => {
			const emptyState = {};
			expect( getSettings( emptyState ) ).toBe( getSettings( emptyState ) );
		} );

		it( 'getBots returns same reference for repeated calls with same state', () => {
			const emptyState = {};
			expect( getBots( emptyState ) ).toBe( getBots( emptyState ) );
		} );
	} );
} );
