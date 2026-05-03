/**
 * Unit tests for pure helpers exported from endpoint-info.js.
 *
 * React component rendering is not tested here — the component requires a
 * full @wordpress/data store setup. Only exported pure functions that can be
 * exercised without DOM or store mocks are covered.
 */

import { shouldShowCrawlStatsEmptyState } from '../endpoint-info';

describe( 'shouldShowCrawlStatsEmptyState', () => {
	it( 'returns true when total_requests is 0 and top_queries is empty', () => {
		expect(
			shouldShowCrawlStatsEmptyState(
				{ total_requests: 0, top_queries: [] },
				false,
				null
			)
		).toBe( true );
	} );

	it( 'returns true when total_requests is 0 and top_queries is absent', () => {
		expect(
			shouldShowCrawlStatsEmptyState( { total_requests: 0 }, false, null )
		).toBe( true );
	} );

	it( 'returns false when total_requests is 0 but top_queries has entries', () => {
		// This is the key regression case: top_queries reads the raw log
		// directly, so it can have data while the rollup summary still shows
		// total_requests = 0. The empty state must NOT render in this window.
		expect(
			shouldShowCrawlStatsEmptyState(
				{
					total_requests: 0,
					top_queries: [ { query: 'hoodie', count: 3 } ],
				},
				false,
				null
			)
		).toBe( false );
	} );

	it( 'returns false when total_requests is non-zero', () => {
		expect(
			shouldShowCrawlStatsEmptyState(
				{ total_requests: 5, top_queries: [] },
				false,
				null
			)
		).toBe( false );
	} );

	it( 'returns false while loading', () => {
		expect(
			shouldShowCrawlStatsEmptyState( { total_requests: 0 }, true, null )
		).toBe( false );
	} );

	it( 'returns false when there is an error', () => {
		expect(
			shouldShowCrawlStatsEmptyState(
				{ total_requests: 0 },
				false,
				new Error( 'network error' )
			)
		).toBe( false );
	} );
} );
