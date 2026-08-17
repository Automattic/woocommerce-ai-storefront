/**
 * Tests for `derivePreview()` — the pure helper that mirrors the
 * server-side `WC_AI_Storefront_JsonLd::build_return_policy_block()`
 * so the derived block never drifts from what the server emits (note: no preview pane renders it today).
 *
 * Also locks single-vs-multi method emission (scalar vs. array)
 * since that's the most subtle of the three modes' output shapes.
 */

import {
	applyHandlingTimeMin,
	applyHandlingTimeMax,
	applyModeChange,
	derivePreview,
	applyBusinessDay,
	deriveDeliveryTimePreview,
} from '../policies-tab';

describe( 'derivePreview', () => {
	it( 'returns null for mode unconfigured', () => {
		expect( derivePreview( { mode: 'unconfigured' }, 'US' ) ).toBeNull();
	} );

	it( 'returns null when country is empty', () => {
		expect(
			derivePreview(
				{
					mode: 'details',
					category: 'returns_accepted',
					days: 30,
					fees: 'FreeReturn',
				},
				''
			)
		).toBeNull();
	} );

	// -- mode: link --

	it( 'link mode with pageLink emits merchantReturnLink only', () => {
		const block = derivePreview(
			{
				mode: 'link',
				page_id: 99,
				pageLink: 'https://example.com/returns',
			},
			'US'
		);
		expect( block[ '@type' ] ).toBe( 'MerchantReturnPolicy' );
		expect( block.merchantReturnLink ).toBe(
			'https://example.com/returns'
		);
		expect( block.returnPolicyCategory ).toBeUndefined();
		expect( block.applicableCountry ).toBeUndefined();
	} );

	it( 'link mode without pageLink returns null', () => {
		// page_id=0 or missing pageLink means no resolved URL.
		expect(
			derivePreview( { mode: 'link', page_id: 0 }, 'US' )
		).toBeNull();
		expect(
			derivePreview( { mode: 'link', page_id: 5 }, 'US' )
		).toBeNull();
	} );

	it( 'link mode emits even when country is empty', () => {
		const block = derivePreview(
			{
				mode: 'link',
				page_id: 1,
				pageLink: 'https://example.com/returns',
			},
			''
		);
		expect( block.merchantReturnLink ).toBe(
			'https://example.com/returns'
		);
	} );

	// -- mode: details, category: final_sale --

	it( 'details final_sale emits MerchantReturnNotPermitted', () => {
		const block = derivePreview(
			{ mode: 'details', category: 'final_sale' },
			'US'
		);
		expect( block.returnPolicyCategory ).toBe(
			'https://schema.org/MerchantReturnNotPermitted'
		);
		expect( block.applicableCountry ).toBe( 'US' );
		expect( block.merchantReturnLink ).toBeUndefined();
		expect( block.returnFees ).toBeUndefined();
	} );

	it( 'details final_sale emits without applicableCountry when country empty', () => {
		const block = derivePreview(
			{ mode: 'details', category: 'final_sale' },
			''
		);
		// The PHP emitter emits the block without applicableCountry.
		expect( block ).not.toBeNull();
		expect( block.applicableCountry ).toBeUndefined();
		expect( block.returnPolicyCategory ).toBe(
			'https://schema.org/MerchantReturnNotPermitted'
		);
	} );

	// -- mode: details, category: returns_accepted --

	it( 'details returns_accepted days > 0 emits FiniteReturnWindow', () => {
		const block = derivePreview(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 30,
				fees: 'FreeReturn',
				methods: [],
			},
			'US'
		);
		expect( block.returnPolicyCategory ).toBe(
			'https://schema.org/MerchantReturnFiniteReturnWindow'
		);
		expect( block.merchantReturnDays ).toBe( 30 );
		expect( block.applicableCountry ).toBe( 'US' );
		expect( block.returnFees ).toBe( 'https://schema.org/FreeReturn' );
		expect( block.returnMethod ).toBeUndefined();
		expect( block.merchantReturnLink ).toBeUndefined();
	} );

	it( 'details returns_accepted days 0 smart-degrades to Unspecified', () => {
		const block = derivePreview(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 0,
				fees: 'FreeReturn',
				methods: [],
			},
			'US'
		);
		expect( block.returnPolicyCategory ).toBe(
			'https://schema.org/MerchantReturnUnspecified'
		);
		expect( block.merchantReturnDays ).toBeUndefined();
	} );

	it( 'details returns_accepted with single method emits scalar returnMethod', () => {
		const block = derivePreview(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 14,
				fees: 'FreeReturn',
				methods: [ 'ReturnByMail' ],
			},
			'US'
		);
		expect( block.returnMethod ).toBe( 'https://schema.org/ReturnByMail' );
	} );

	it( 'details returns_accepted with multiple methods emits array returnMethod', () => {
		const block = derivePreview(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 14,
				fees: 'FreeReturn',
				methods: [ 'ReturnByMail', 'ReturnInStore' ],
			},
			'US'
		);
		expect( block.returnMethod ).toEqual( [
			'https://schema.org/ReturnByMail',
			'https://schema.org/ReturnInStore',
		] );
	} );

	it( 'details returns_accepted no country returns null', () => {
		expect(
			derivePreview(
				{
					mode: 'details',
					category: 'returns_accepted',
					days: 30,
					fees: 'FreeReturn',
					methods: [],
				},
				''
			)
		).toBeNull();
	} );

	it( 'unknown mode returns null', () => {
		expect( derivePreview( { mode: 'gibberish' }, 'US' ) ).toBeNull();
	} );

	it( 'details with unknown category returns null', () => {
		expect(
			derivePreview( { mode: 'details', category: 'gibberish' }, 'US' )
		).toBeNull();
	} );

	// -- allow-list parity with PHP emitter --

	it( 'invalid fees value defaults to schema.org/FreeReturn', () => {
		// The PHP emitter sanitizes fees against an allow-list at emission
		// time (EvilReturn → FreeReturn). derivePreview must mirror this so
		// invalid stored values don't produce a bogus schema.org URL.
		const block = derivePreview(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 30,
				fees: 'EvilReturn',
				methods: [],
			},
			'US'
		);
		expect( block.returnFees ).toBe( 'https://schema.org/FreeReturn' );
	} );

	it( 'non-allow-listed method is dropped from returnMethod', () => {
		// The PHP emitter filters methods to the allow-list. derivePreview
		// must do the same so a bogus stored method doesn't produce an
		// invalid schema.org URL.
		const block = derivePreview(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 14,
				fees: 'FreeReturn',
				methods: [ 'ReturnByMail', 'NotAValidMethod', 'ReturnInStore' ],
			},
			'US'
		);
		expect( block.returnMethod ).toEqual( [
			'https://schema.org/ReturnByMail',
			'https://schema.org/ReturnInStore',
		] );
	} );
} );

describe( 'applyModeChange', () => {
	it( 'sets the new mode', () => {
		const next = applyModeChange( { mode: 'unconfigured' }, 'link' );
		expect( next.mode ).toBe( 'link' );
	} );

	it( 'defaults category to returns_accepted when entering details with no category', () => {
		const next = applyModeChange( { mode: 'unconfigured' }, 'details' );
		expect( next.mode ).toBe( 'details' );
		expect( next.category ).toBe( 'returns_accepted' );
	} );

	it( 'preserves an existing category when re-entering details', () => {
		const next = applyModeChange(
			{ mode: 'details', category: 'final_sale' },
			'details'
		);
		expect( next.mode ).toBe( 'details' );
		expect( next.category ).toBe( 'final_sale' );
	} );

	it( 'does not mutate its input', () => {
		const input = { mode: 'details', category: 'final_sale', days: 30 };
		const snapshot = JSON.parse( JSON.stringify( input ) );
		applyModeChange( input, 'link' );
		expect( input ).toEqual( snapshot );
	} );

	it( 'clears details fields when switching details -> link (no stale days/fees/methods)', () => {
		// Core anti-stale-value contract: switching modes must reset the
		// other branch's fields to DEFAULT_POLICY so a leftover
		// days/fees/methods never leaks back into emitted JSON-LD. Mutating
		// applyModeChange to spread `...policy` instead of `...DEFAULT_POLICY`
		// makes these assertions fail.
		const next = applyModeChange(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 30,
				fees: 'RestockingFees',
				methods: [ 'ReturnByMail', 'ReturnInStore' ],
			},
			'link'
		);
		expect( next.mode ).toBe( 'link' );
		expect( next.days ).toBe( 0 );
		expect( next.fees ).toBe( 'FreeReturn' );
		expect( next.methods ).toEqual( [] );
		expect( next.page_id ).toBe( 0 );
	} );

	it( 'clears the link page_id when switching link -> details', () => {
		const next = applyModeChange(
			{ mode: 'link', page_id: 42 },
			'details'
		);
		expect( next.mode ).toBe( 'details' );
		expect( next.page_id ).toBe( 0 );
	} );

	it( 'clears all branch fields when switching details -> unconfigured', () => {
		const next = applyModeChange(
			{
				mode: 'details',
				category: 'returns_accepted',
				days: 30,
				fees: 'RestockingFees',
				methods: [ 'ReturnByMail' ],
			},
			'unconfigured'
		);
		expect( next.mode ).toBe( 'unconfigured' );
		expect( next.days ).toBe( 0 );
		expect( next.fees ).toBe( 'FreeReturn' );
		expect( next.methods ).toEqual( [] );
		expect( next.page_id ).toBe( 0 );
	} );
} );

describe( 'applyHandlingTimeMin', () => {
	it( 'sets min without touching max when max >= new min', () => {
		expect( applyHandlingTimeMin( { min: 1, max: 5 }, 3 ) ).toEqual( {
			min: 3,
			max: 5,
		} );
	} );

	it( 'bumps max up to new min when max would fall below (mirrors PHP direction)', () => {
		expect( applyHandlingTimeMin( { min: 1, max: 2 }, 5 ) ).toEqual( {
			min: 5,
			max: 5,
		} );
	} );

	it( 'does not touch max when max is 0 (not-set)', () => {
		expect( applyHandlingTimeMin( { min: 0, max: 0 }, 3 ) ).toEqual( {
			min: 3,
			max: 0,
		} );
	} );
} );

describe( 'applyHandlingTimeMax', () => {
	it( 'sets max without touching min when max >= min', () => {
		expect( applyHandlingTimeMax( { min: 2, max: 3 }, 7 ) ).toEqual( {
			min: 2,
			max: 7,
		} );
	} );

	it( 'bumps max up to min when entered value is below min — not min down (mirrors PHP direction)', () => {
		expect( applyHandlingTimeMax( { min: 5, max: 5 }, 2 ) ).toEqual( {
			min: 5,
			max: 5,
		} );
	} );

	it( 'does not touch min when min is 0 (not-set)', () => {
		expect( applyHandlingTimeMax( { min: 0, max: 0 }, 3 ) ).toEqual( {
			min: 0,
			max: 3,
		} );
	} );

	it( 'does not touch min when new val is 0 (clearing max)', () => {
		expect( applyHandlingTimeMax( { min: 3, max: 5 }, 0 ) ).toEqual( {
			min: 3,
			max: 0,
		} );
	} );
} );

describe( 'deriveDeliveryTimePreview', () => {
	it( 'returns null when min is 0', () => {
		expect( deriveDeliveryTimePreview( { min: 0, max: 3 } ) ).toBeNull();
	} );

	it( 'returns null when max is 0', () => {
		expect( deriveDeliveryTimePreview( { min: 2, max: 0 } ) ).toBeNull();
	} );

	it( 'returns null for empty object', () => {
		expect( deriveDeliveryTimePreview( {} ) ).toBeNull();
	} );

	it( 'returns null when min > max (pre-stored invalid pair, N2 guard)', () => {
		expect( deriveDeliveryTimePreview( { min: 5, max: 2 } ) ).toBeNull();
	} );

	it( 'wraps the QuantitativeValue in a ShippingDeliveryTime block', () => {
		// The helper returns the whole deliveryTime block now, because
		// businessDays is a sibling of handlingTime rather than nested in it.
		const result = deriveDeliveryTimePreview( { min: 1, max: 3 } );
		expect( result ).toEqual( {
			'@type': 'ShippingDeliveryTime',
			handlingTime: {
				'@type': 'QuantitativeValue',
				minValue: 1,
				maxValue: 3,
				unitCode: 'DAY',
			},
		} );
	} );

	it( 'returns same value for min === max', () => {
		const result = deriveDeliveryTimePreview( { min: 2, max: 2 } );
		expect( result?.handlingTime?.minValue ).toBe( 2 );
		expect( result?.handlingTime?.maxValue ).toBe( 2 );
	} );

	it( 'emits days in week order regardless of click order', () => {
		const out = deriveDeliveryTimePreview( {
			min: 1,
			max: 2,
			business_days: [ 'Friday', 'Monday' ],
		} );
		expect( out.businessDays ).toEqual( [ 'Monday', 'Friday' ] );
	} );

	it( 'emits days with no handling time', () => {
		const out = deriveDeliveryTimePreview( {
			min: 0,
			max: 0,
			business_days: [ 'Monday' ],
		} );
		expect( out.businessDays ).toEqual( [ 'Monday' ] );
		expect( out.handlingTime ).toBeUndefined();
	} );

	it( 'omits businessDays when no day is selected', () => {
		const out = deriveDeliveryTimePreview( {
			min: 1,
			max: 2,
			business_days: [],
		} );
		expect( out.businessDays ).toBeUndefined();
		expect( out.handlingTime.minValue ).toBe( 1 );
	} );

	it( 'returns null when neither is configured', () => {
		expect(
			deriveDeliveryTimePreview( { min: 0, max: 0, business_days: [] } )
		).toBeNull();
	} );
} );

describe( 'applyBusinessDay', () => {
	it( 'adds a day in week order, not click order', () => {
		let state = { min: 1, max: 2, business_days: [] };
		state = applyBusinessDay( state, 'Friday', true );
		state = applyBusinessDay( state, 'Monday', true );
		expect( state.business_days ).toEqual( [ 'Monday', 'Friday' ] );
	} );

	it( 'removes a day', () => {
		const state = applyBusinessDay(
			{ business_days: [ 'Monday', 'Friday' ] },
			'Monday',
			false
		);
		expect( state.business_days ).toEqual( [ 'Friday' ] );
	} );

	it( 'ignores a duplicate add', () => {
		const state = applyBusinessDay(
			{ business_days: [ 'Monday' ] },
			'Monday',
			true
		);
		expect( state.business_days ).toEqual( [ 'Monday' ] );
	} );

	it( 'preserves the handling-time pair', () => {
		const state = applyBusinessDay(
			{ min: 2, max: 4, business_days: [] },
			'Monday',
			true
		);
		expect( state.min ).toBe( 2 );
		expect( state.max ).toBe( 4 );
	} );
} );
