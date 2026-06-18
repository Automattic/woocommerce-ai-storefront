/**
 * Unit tests for pure helpers exported from endpoint-info.js.
 *
 * React component rendering is not tested here — the component requires a
 * full @wordpress/data store setup. Only exported pure functions that can be
 * exercised without DOM or store mocks are covered, including the
 * CrawlerActivityCard copy helpers, so the Discovery-tab activity card's
 * merchant-facing wording stays scoped to the shopping API.
 */

import {
	getRollupIntervalLabel,
	shouldShowCrawlStatsEmptyState,
	getCrawlerActivityTitle,
	getCrawlerActivityScopeNote,
	getCrawlerActivityEmptyState,
} from '../endpoint-info';

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

	it( 'returns false when raw_event_count is non-zero even if total_requests and top_queries are empty', () => {
		// Non-search raw-log hits (llms.txt, UCP, product-page) land in the raw
		// log before the first rollup, so total_requests stays 0 and top_queries
		// is empty — but raw_event_count is already non-zero. The empty state
		// must NOT render while actual traffic exists in the raw log.
		expect(
			shouldShowCrawlStatsEmptyState(
				{ total_requests: 0, top_queries: [], raw_event_count: 4 },
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

describe( 'getRollupIntervalLabel', () => {
	it( 'returns "Updated hourly." for hourly', () => {
		expect( getRollupIntervalLabel( 'hourly' ) ).toBe( 'Updated hourly.' );
	} );

	it( 'returns "Updated every 12 hours." for twicedaily', () => {
		expect( getRollupIntervalLabel( 'twicedaily' ) ).toBe(
			'Updated every 12 hours.'
		);
	} );

	it( 'returns "Updated daily." for daily', () => {
		expect( getRollupIntervalLabel( 'daily' ) ).toBe( 'Updated daily.' );
	} );

	it( 'falls back to "Updated periodically." for unknown values', () => {
		expect( getRollupIntervalLabel( 'weekly' ) ).toBe(
			'Updated periodically.'
		);
		expect( getRollupIntervalLabel( undefined ) ).toBe(
			'Updated periodically.'
		);
	} );
} );

describe( 'CrawlerActivityCard copy', () => {
	it( 'titles the card "AI shopping-API activity"', () => {
		// Scoped to the shopping API — the old broad "AI agent activity"
		// over-claimed coverage of page/feed/llms.txt fetches that the card
		// does not count.
		expect( getCrawlerActivityTitle() ).toBe( 'AI shopping-API activity' );
	} );

	it( 'clarifies the scope is UCP shopping-API searches & lookups', () => {
		expect( getCrawlerActivityScopeNote() ).toMatch(
			/Catalog searches & lookups through the UCP shopping API/
		);
		// And it explains why the other fetches are not counted — joined with a
		// sentence break, NOT an em-dash (AGENTS.md bars em-dashes in
		// merchant-facing copy). Pinning the period guards against the dash
		// creeping back in.
		expect( getCrawlerActivityScopeNote() ).toMatch(
			/Page, feed, and llms\.txt fetches aren’t counted\. Most are served from cache/
		);
		expect( getCrawlerActivityScopeNote() ).not.toContain( '—' );
	} );

	it( 'uses the scoped empty-state copy', () => {
		expect( getCrawlerActivityEmptyState() ).toBe(
			'No AI shopping-API activity recorded for this period. Stats appear here after the first AI agent uses your shopping API.'
		);
	} );
} );
