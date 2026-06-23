import { formatIndexNowStatus, formatRelativeAge } from '../indexnow-card';

describe( 'formatRelativeAge', () => {
	it( 'returns "just now" under a minute', () => {
		expect( formatRelativeAge( 30 ) ).toBe( 'just now' );
	} );
	it( 'returns minutes, hours, days', () => {
		expect( formatRelativeAge( 120 ) ).toBe( '2m ago' );
		expect( formatRelativeAge( 7200 ) ).toBe( '2h ago' );
		expect( formatRelativeAge( 172800 ) ).toBe( '2d ago' );
	} );
	it( 'handles the exact cutoff boundaries (strict < comparisons)', () => {
		expect( formatRelativeAge( 59 ) ).toBe( 'just now' );
		expect( formatRelativeAge( 60 ) ).toBe( '1m ago' );
		expect( formatRelativeAge( 3599 ) ).toBe( '59m ago' );
		expect( formatRelativeAge( 3600 ) ).toBe( '1h ago' );
		expect( formatRelativeAge( 86399 ) ).toBe( '23h ago' );
		expect( formatRelativeAge( 86400 ) ).toBe( '1d ago' );
	} );
} );

describe( 'formatIndexNowStatus', () => {
	const now = 1750003600; // 1h after the timestamps below.
	it( 'reports no submissions when empty', () => {
		expect( formatIndexNowStatus( {}, now ) ).toBe( 'No submissions yet.' );
		expect( formatIndexNowStatus( undefined, now ) ).toBe(
			'No submissions yet.'
		);
		// PHP's empty array() JSON-encodes to [], which is the actual wire
		// value for "never submitted" — pin it, not just {} / undefined.
		expect( formatIndexNowStatus( [], now ) ).toBe( 'No submissions yet.' );
	} );
	it( 'reports a successful submission', () => {
		expect(
			formatIndexNowStatus(
				{ time: 1750000000, count: 12, code: 200, ok: true },
				now
			)
		).toBe( 'Last submitted: 12 URL(s) · HTTP 200 · 1h ago' );
	} );
	it( 'reports a failed submission with the code', () => {
		expect(
			formatIndexNowStatus(
				{ time: 1750000000, count: 3, code: 429, ok: false },
				now
			)
		).toBe( 'Last attempt failed: HTTP 429 · 1h ago' );
	} );
	it( 'reports a transport error (code 0) as a connection error', () => {
		expect(
			formatIndexNowStatus(
				{ time: 1750000000, count: 3, code: 0, ok: false },
				now
			)
		).toBe( 'Last attempt failed: connection error · 1h ago' );
	} );
} );
