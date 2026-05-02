/**
 * Unit tests for pure helpers exported from ai-orders-table.js.
 *
 * React component rendering is not tested here — the component
 * requires a full @wordpress/data store setup. Only the localStorage
 * persistence helpers (loadPersistedView, persistView) are exercised.
 */

import { loadPersistedView, persistView } from '../ai-orders-table';

const DEFAULT_FIELDS = [
	'order',
	'customer',
	'date',
	'status',
	'items',
	'agent',
	'total',
];

describe( 'loadPersistedView', () => {
	beforeEach( () => {
		window.localStorage.clear();
	} );

	it( 'returns DEFAULT_VIEW when nothing is stored', () => {
		const view = loadPersistedView();
		expect( view.type ).toBe( 'table' );
		expect( view.perPage ).toBe( 10 );
		expect( view.page ).toBe( 1 );
		expect( view.fields ).toEqual( DEFAULT_FIELDS );
	} );

	it( 'restores persisted perPage', () => {
		window.localStorage.setItem(
			'wc_ai_storefront_orders_view',
			JSON.stringify( { perPage: 25 } )
		);
		expect( loadPersistedView().perPage ).toBe( 25 );
	} );

	it( 'restores persisted sort', () => {
		const sort = { field: 'total', direction: 'asc' };
		window.localStorage.setItem(
			'wc_ai_storefront_orders_view',
			JSON.stringify( { sort } )
		);
		expect( loadPersistedView().sort ).toEqual( sort );
	} );

	it( 'restores persisted fields subset', () => {
		const fields = [ 'order', 'date', 'total' ];
		window.localStorage.setItem(
			'wc_ai_storefront_orders_view',
			JSON.stringify( { fields } )
		);
		expect( loadPersistedView().fields ).toEqual( fields );
	} );

	it( 'always resets page to 1 even when stored value differs', () => {
		window.localStorage.setItem(
			'wc_ai_storefront_orders_view',
			JSON.stringify( { page: 7, perPage: 50 } )
		);
		const view = loadPersistedView();
		expect( view.page ).toBe( 1 );
		expect( view.perPage ).toBe( 50 );
	} );

	it( 'falls back to defaults on malformed JSON', () => {
		window.localStorage.setItem(
			'wc_ai_storefront_orders_view',
			'not-valid-json{{'
		);
		const view = loadPersistedView();
		expect( view.perPage ).toBe( 10 );
		expect( view.type ).toBe( 'table' );
	} );

	it( 'falls back to defaults when stored value is not an object', () => {
		window.localStorage.setItem(
			'wc_ai_storefront_orders_view',
			JSON.stringify( 42 )
		);
		expect( loadPersistedView().perPage ).toBe( 10 );
	} );
} );

describe( 'persistView', () => {
	beforeEach( () => {
		window.localStorage.clear();
	} );

	it( 'writes perPage and type to localStorage', () => {
		persistView( { type: 'table', perPage: 25, page: 3, search: 'foo' } );
		const stored = JSON.parse(
			window.localStorage.getItem( 'wc_ai_storefront_orders_view' )
		);
		expect( stored.perPage ).toBe( 25 );
		expect( stored.type ).toBe( 'table' );
	} );

	it( 'does not persist page, search, or filters', () => {
		persistView( {
			type: 'table',
			perPage: 10,
			page: 5,
			search: 'test',
			filters: [ { field: 'status', value: 'processing' } ],
		} );
		const stored = JSON.parse(
			window.localStorage.getItem( 'wc_ai_storefront_orders_view' )
		);
		expect( stored ).not.toHaveProperty( 'page' );
		expect( stored ).not.toHaveProperty( 'search' );
		expect( stored ).not.toHaveProperty( 'filters' );
	} );

	it( 'persists sort when present', () => {
		const sort = { field: 'date', direction: 'desc' };
		persistView( { type: 'table', perPage: 10, sort } );
		const stored = JSON.parse(
			window.localStorage.getItem( 'wc_ai_storefront_orders_view' )
		);
		expect( stored.sort ).toEqual( sort );
	} );

	it( 'persists fields array', () => {
		const fields = [ 'order', 'total' ];
		persistView( { type: 'table', perPage: 10, fields } );
		const stored = JSON.parse(
			window.localStorage.getItem( 'wc_ai_storefront_orders_view' )
		);
		expect( stored.fields ).toEqual( fields );
	} );

	it( 'round-trips: persist then load returns the saved prefs', () => {
		const sort = { field: 'total', direction: 'asc' };
		persistView( {
			type: 'table',
			perPage: 50,
			sort,
			fields: [ 'order', 'date' ],
			page: 3,
		} );
		const view = loadPersistedView();
		expect( view.perPage ).toBe( 50 );
		expect( view.sort ).toEqual( sort );
		expect( view.fields ).toEqual( [ 'order', 'date' ] );
		expect( view.page ).toBe( 1 );
	} );
} );
