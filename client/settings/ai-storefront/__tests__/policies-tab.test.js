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
	applyModeChange,
	derivePreview,
	deriveHandlingTimePreview,
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
