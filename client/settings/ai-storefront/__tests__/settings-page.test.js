/**
 * Unit tests for pure helpers exported from settings-page.js.
 *
 * React component rendering is not tested here — the component
 * requires a full @wordpress/data store setup. Only exported pure
 * functions that can be exercised without DOM or store mocks are
 * covered.
 */

import { formatRevenuePercent, topChannelLabel } from '../settings-page';

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

describe( 'topChannelLabel', () => {
	// The wire-level utm_id values (`woo_ucp` / `woo_jsonld`) are
	// implementation details — merchants should never see those raw
	// strings. The helper maps them to the merchant-readable labels
	// chosen during design ("Agent" for live UCP sessions, "Referral"
	// for JSON-LD scrape traffic). The mapping lives in one helper so
	// future renames stay coherent across the StatCard + any other
	// future surface that needs to translate the same value.

	it( 'returns "Agent" for woo_ucp', () => {
		expect( topChannelLabel( 'woo_ucp' ) ).toBe( 'Agent' );
	} );

	it( 'returns "Referral" for woo_jsonld', () => {
		expect( topChannelLabel( 'woo_jsonld' ) ).toBe( 'Referral' );
	} );

	it( 'returns em dash for null', () => {
		// Mirrors derive_stats() returning top_channel === null when
		// by_channel is empty. The StatCard's empty-state convention
		// is an em-dash (matches the other cards' placeholder shape).
		expect( topChannelLabel( null ) ).toBe( '—' );
	} );

	it( 'returns em dash for undefined', () => {
		// Defensive: pre-0.15 cached payloads lack top_channel entirely.
		// Once the v2 transient key rolls out, undefined shouldn't
		// arise — but the guard makes a first-load post-deploy graceful.
		expect( topChannelLabel( undefined ) ).toBe( '—' );
	} );

	it( 'returns em dash for an unknown channel string and warns to console', () => {
		// Future-proofing: if a third channel id ever ships server-
		// side but the JS bundle isn't updated, the helper falls
		// through to the em-dash rather than rendering a raw
		// `woo_xxx` string in the merchant-facing UI.
		//
		// It also emits a `console.warn` so the PHP/JS contract drift
		// is debuggable. `@wordpress/jest-console` auto-fails the test
		// on unexpected console output; we explicitly acknowledge the
		// warning here via `toHaveWarned()` so the matcher clears it.
		expect( topChannelLabel( 'woo_future' ) ).toBe( '—' );
		expect( console ).toHaveWarned();
	} );
} );
