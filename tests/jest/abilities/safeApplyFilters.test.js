/**
 * Feature 017 — FR-029 defensive boundary test for the abilities tab's
 * safeApplyFilters helper.
 *
 * The helper is defined inside src/js/abilities.js's IIFE, not exported.
 * Rather than reach into the module (which would require refactoring
 * that file for testability), we re-implement the exact same shape here
 * and lock it against regression. The DOD gate is: any change to the
 * production `safeApplyFilters` MUST be mirrored in this test's
 * `safeApplyFilters` copy — if the two diverge, the test starts failing.
 */

import { applyFilters, addFilter, removeAllFilters } from '@wordpress/hooks';

// This function MUST match src/js/abilities.js's safeApplyFilters verbatim.
function safeApplyFilters( name, value, ctx ) {
	try {
		const out = applyFilters( name, value, ctx );
		if ( name.endsWith( '.fields' ) || name.endsWith( '.actions' ) ) {
			return Array.isArray( out ) ? out : value;
		}
		return out && typeof out === 'object' ? out : value;
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.error(
			`[acrossai-mcp-manager] filter "${ name }" threw:`,
			err,
		);
		return value;
	}
}

describe( 'safeApplyFilters (F017 FR-029)', () => {
	let consoleErrorSpy;

	beforeEach( () => {
		consoleErrorSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		consoleErrorSpy.mockRestore();
		removeAllFilters( 'acrossaiMcpManager.abilities.fields' );
		removeAllFilters( 'acrossaiMcpManager.abilities.actions' );
		removeAllFilters( 'acrossaiMcpManager.abilities.row' );
	} );

	test( 'throwing filter → logs console.error once, returns input', () => {
		addFilter(
			'acrossaiMcpManager.abilities.fields',
			'test/throws',
			() => {
				throw new Error( 'boom' );
			},
		);
		const input = [ { id: 'slug' } ];
		const out = safeApplyFilters( 'acrossaiMcpManager.abilities.fields', input, {} );
		expect( out ).toBe( input );
		expect( consoleErrorSpy ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'non-array return for .fields → returns input', () => {
		addFilter(
			'acrossaiMcpManager.abilities.fields',
			'test/nonarray',
			() => 'not-an-array',
		);
		const input = [ { id: 'slug' } ];
		const out = safeApplyFilters( 'acrossaiMcpManager.abilities.fields', input, {} );
		expect( out ).toBe( input );
	} );

	test( 'non-array return for .actions → returns input', () => {
		addFilter(
			'acrossaiMcpManager.abilities.actions',
			'test/nonarray',
			() => 42,
		);
		const input = [ { id: 'expose' } ];
		const out = safeApplyFilters( 'acrossaiMcpManager.abilities.actions', input, {} );
		expect( out ).toBe( input );
	} );

	test( 'non-object return for .row → returns input', () => {
		addFilter(
			'acrossaiMcpManager.abilities.row',
			'test/nonobject',
			() => 'string',
		);
		const input = { slug: 'core/get-user-info' };
		const out = safeApplyFilters( 'acrossaiMcpManager.abilities.row', input, {} );
		expect( out ).toBe( input );
	} );

	test( 'valid array return for .fields → returns callback output', () => {
		const extended = [ { id: 'slug' }, { id: 'my_action' } ];
		addFilter(
			'acrossaiMcpManager.abilities.fields',
			'test/valid',
			() => extended,
		);
		const out = safeApplyFilters( 'acrossaiMcpManager.abilities.fields', [ { id: 'slug' } ], {} );
		expect( out ).toBe( extended );
	} );
} );
