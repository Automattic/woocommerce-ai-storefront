/**
 * Unit tests for pure helpers exported from settings-page.js.
 *
 * React component rendering is not tested here — the component
 * requires a full @wordpress/data store setup. Only exported pure
 * functions that can be exercised without DOM or store mocks are
 * covered.
 */

import { formatRevenuePercent } from '../settings-page';

describe( 'formatRevenuePercent', () => {
	it( 'returns formatted percentage when all_revenue > 0', () => {
		expect(
			formatRevenuePercent( { ai_revenue: 50, all_revenue: 200 } )
		).toBe( '25.0%' );
	} );

	it( 'rounds to one decimal place', () => {
		expect(
			formatRevenuePercent( { ai_revenue: 1, all_revenue: 3 } )
		).toBe( '33.3%' );
	} );

	it( 'returns em dash when all_revenue is 0', () => {
		expect(
			formatRevenuePercent( { ai_revenue: 0, all_revenue: 0 } )
		).toBe( '—' );
	} );

	it( 'returns em dash when all_revenue is absent', () => {
		expect( formatRevenuePercent( { ai_revenue: 10 } ) ).toBe( '—' );
	} );

	it( 'returns em dash when stats is null', () => {
		expect( formatRevenuePercent( null ) ).toBe( '—' );
	} );

	it( 'treats missing ai_revenue as 0', () => {
		expect( formatRevenuePercent( { all_revenue: 100 } ) ).toBe( '0.0%' );
	} );
} );
