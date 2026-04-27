import {
	getSettings,
	getSavedSettings,
	isSaving,
	getSavingError,
	getStats,
	getEndpoints,
	getEndpointStatus,
	isDirty,
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

	describe( 'getSavedSettings', () => {
		it( 'returns the savedSettings slice', () => {
			const state = {
				settings: { enabled: 'yes' },
				savedSettings: { enabled: 'no' },
			};
			expect( getSavedSettings( state ) ).toEqual( { enabled: 'no' } );
		} );

		it( 'returns empty object when state is undefined', () => {
			expect( getSavedSettings( undefined ) ).toEqual( {} );
		} );

		it( 'returns empty object when savedSettings is missing', () => {
			expect(
				getSavedSettings( { settings: { enabled: 'yes' } } )
			).toEqual( {} );
		} );
	} );

	describe( 'isDirty', () => {
		// Pin the dirty-aware Save contract: the selector must return
		// false on the post-load and post-save sync points and true
		// only when the merchant has edits in flight. The Save button
		// state across all four settings tabs depends on this returning
		// the correct boolean — a regression here makes the button
		// always-enabled (rendering the feature inert) or never-enabled
		// (locking out saves entirely).

		it( 'returns false when settings and savedSettings are deeply equal', () => {
			const state = {
				settings: { enabled: 'yes', rate_limit_rpm: 25 },
				savedSettings: { enabled: 'yes', rate_limit_rpm: 25 },
			};
			expect( isDirty( state ) ).toBe( false );
		} );

		it( 'returns true when a draft field differs from saved', () => {
			const state = {
				settings: { enabled: 'yes', rate_limit_rpm: 50 },
				savedSettings: { enabled: 'yes', rate_limit_rpm: 25 },
			};
			expect( isDirty( state ) ).toBe( true );
		} );

		it( 'returns false on the initial-load empty-on-empty case', () => {
			// Both halves of the comparison are `{}` until the first
			// SET_SETTINGS resolves. That window must read as clean
			// (otherwise the Save button would be enabled before the
			// merchant has even seen the form).
			expect( isDirty( {} ) ).toBe( false );
			expect( isDirty( undefined ) ).toBe( false );
		} );

		it( 'returns true on a draft-against-empty-saved (pre-load edit)', () => {
			// Selectors comment notes this corner: any edit that lands
			// before the initial fetch resolves registers as dirty
			// against the empty snapshot. That's correct — the merchant
			// did type something, the Save button should reflect it.
			const state = {
				settings: { enabled: 'yes' },
				savedSettings: {},
			};
			expect( isDirty( state ) ).toBe( true );
		} );

		it( 'returns false on type-then-undo round-trip', () => {
			// The whole point of value-based comparison: edit, then
			// edit back to the saved value, button re-disables. JSON
			// stringify gets this for free as long as object key order
			// is stable, which it is for this reducer's spread paths.
			const saved = { enabled: 'yes', rate_limit_rpm: 25 };
			const dirty = {
				settings: { enabled: 'yes', rate_limit_rpm: 50 },
				savedSettings: saved,
			};
			expect( isDirty( dirty ) ).toBe( true );

			const reverted = {
				settings: { enabled: 'yes', rate_limit_rpm: 25 },
				savedSettings: saved,
			};
			expect( isDirty( reverted ) ).toBe( false );
		} );

		it( 'is sensitive to array order (treats reorder as a real change)', () => {
			// Documented behavior: every array in our settings has
			// meaningful order — `selected_categories` is a curated
			// list, `allowed_crawlers` is shown in display order, etc.
			// A future "drag to reorder" UI should register the reorder
			// as dirty.
			const state = {
				settings: { selected_categories: [ 1, 2, 3 ] },
				savedSettings: { selected_categories: [ 3, 2, 1 ] },
			};
			expect( isDirty( state ) ).toBe( true );
		} );

		it( 'distinguishes nested object differences', () => {
			// `return_policy` is a nested object — JSON.stringify
			// recurses, so a change inside the nested shape correctly
			// reads as dirty.
			const state = {
				settings: {
					return_policy: { mode: 'returns_accepted', days: 30 },
				},
				savedSettings: {
					return_policy: { mode: 'returns_accepted', days: 14 },
				},
			};
			expect( isDirty( state ) ).toBe( true );
		} );
	} );
} );
