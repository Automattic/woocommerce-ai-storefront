/**
 * Tests for `derivePreview()` — the pure helper that mirrors the
 * server-side `WC_AI_Storefront_JsonLd::build_return_policy_block()`
 * so the live-preview pane never drifts from what gets emitted.
 *
 * Also locks single-vs-multi method emission (scalar vs. array)
 * since that's the most subtle of the three modes' output shapes.
 */

import { derivePreview } from '../policies-tab';

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
