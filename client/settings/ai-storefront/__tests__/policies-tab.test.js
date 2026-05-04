/**
 * Tests for `derivePreview()` — the pure helper that mirrors the
 * server-side `WC_AI_Storefront_JsonLd::build_return_policy_block()`
 * so the live-preview pane never drifts from what gets emitted.
 *
 * Also locks single-vs-multi method emission (scalar vs. array)
 * since that's the most subtle of the three modes' output shapes.
 */

import {
	applyHandlingTimeMin,
	applyHandlingTimeMax,
	derivePreview,
	deriveHandlingTimePreview,
} from '../policies-tab';

describe( 'derivePreview', () => {
	it( 'returns null for unconfigured mode', () => {
		expect( derivePreview( { mode: 'unconfigured' }, 'US' ) ).toBeNull();
	} );

	it( 'returns null when country is empty', () => {
		expect(
			derivePreview(
				{ mode: 'returns_accepted', days: 30, fees: 'FreeReturn' },
				''
			)
		).toBeNull();
	} );

	it( 'emits FiniteReturnWindow with merchantReturnDays when days > 0', () => {
		const block = derivePreview(
			{
				mode: 'returns_accepted',
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
		expect( block.returnFees ).toBe( 'https://schema.org/FreeReturn' );
		expect( block.returnMethod ).toBeUndefined();
	} );

	it( 'smart-degrades to Unspecified when days is 0', () => {
		const block = derivePreview(
			{
				mode: 'returns_accepted',
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

	it( 'emits returnMethod as a scalar when one method is selected', () => {
		const block = derivePreview(
			{
				mode: 'returns_accepted',
				days: 14,
				fees: 'FreeReturn',
				methods: [ 'ReturnByMail' ],
			},
			'US'
		);
		expect( block.returnMethod ).toBe( 'https://schema.org/ReturnByMail' );
	} );

	it( 'emits returnMethod as an array when multiple methods are selected', () => {
		const block = derivePreview(
			{
				mode: 'returns_accepted',
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

	it( 'emits NotPermitted for final_sale mode', () => {
		const block = derivePreview( { mode: 'final_sale', page_id: 0 }, 'US' );
		expect( block.returnPolicyCategory ).toBe(
			'https://schema.org/MerchantReturnNotPermitted'
		);
		expect( block.merchantReturnLink ).toBeUndefined();
		expect( block.returnFees ).toBeUndefined();
	} );

	it( 'attaches merchantReturnLink when page_id and pageLink are set', () => {
		const block = derivePreview(
			{
				mode: 'final_sale',
				page_id: 17,
				pageLink: 'https://example.com/no-returns',
			},
			'US'
		);
		expect( block.merchantReturnLink ).toBe(
			'https://example.com/no-returns'
		);
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

describe( 'deriveHandlingTimePreview', () => {
	it( 'returns null when min is 0', () => {
		expect( deriveHandlingTimePreview( { min: 0, max: 3 } ) ).toBeNull();
	} );

	it( 'returns null when max is 0', () => {
		expect( deriveHandlingTimePreview( { min: 2, max: 0 } ) ).toBeNull();
	} );

	it( 'returns null for empty object', () => {
		expect( deriveHandlingTimePreview( {} ) ).toBeNull();
	} );

	it( 'returns null when min > max (pre-stored invalid pair, N2 guard)', () => {
		expect( deriveHandlingTimePreview( { min: 5, max: 2 } ) ).toBeNull();
	} );

	it( 'returns QuantitativeValue when both min and max are positive', () => {
		const result = deriveHandlingTimePreview( { min: 1, max: 3 } );
		expect( result ).toEqual( {
			'@type': 'QuantitativeValue',
			minValue: 1,
			maxValue: 3,
			unitCode: 'DAY',
		} );
	} );

	it( 'returns same value for min === max', () => {
		const result = deriveHandlingTimePreview( { min: 2, max: 2 } );
		expect( result?.minValue ).toBe( 2 );
		expect( result?.maxValue ).toBe( 2 );
	} );
} );
