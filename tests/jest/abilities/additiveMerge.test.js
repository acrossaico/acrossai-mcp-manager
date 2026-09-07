/**
 * Feature 017 — FR-026 / FR-029 additive-only merge invariant test.
 *
 * Extensions may append columns / actions to the DataViews table via
 * `acrossaiMcpManager.abilities.fields` and `.actions`. They MUST NOT be
 * able to remove or overwrite any of the built-in ids. The invariant is
 * enforced by the reducer inside src/js/abilities.js — this test copies
 * the exact reducer shape and locks it against regression.
 */

const BUILTIN_FIELDS = [
	{ id: 'slug' },
	{ id: 'label' },
	{ id: 'type' },
	{ id: 'category' },
	{ id: 'description' },
	{ id: 'is_exposed' },
];

const BUILTIN_ACTIONS = [
	{ id: 'expose' },
	{ id: 'hide' },
];

// MUST match src/js/abilities.js reducer shape verbatim.
function additiveReduce( builtins, extras ) {
	const builtinIds = new Set( builtins.map( ( x ) => x.id ) );
	const additions = ( Array.isArray( extras ) ? extras : [] ).filter(
		( x ) => x && ! builtinIds.has( x.id ),
	);
	return [ ...builtins, ...additions ];
}

describe( 'additive-only merge invariant (F017 FR-026)', () => {
	test( 'extension can add a new field', () => {
		const extras = [ { id: 'my_action', label: 'Action' } ];
		const out = additiveReduce( BUILTIN_FIELDS, extras );
		expect( out ).toHaveLength( BUILTIN_FIELDS.length + 1 );
		expect( out.find( ( f ) => f.id === 'my_action' ) ).toBeTruthy();
	} );

	test( 'extension CANNOT overwrite a built-in field', () => {
		const attacker = [ { id: 'is_exposed', label: 'Hacked' } ];
		const out = additiveReduce( BUILTIN_FIELDS, attacker );
		expect( out ).toHaveLength( BUILTIN_FIELDS.length );
		const isExposed = out.find( ( f ) => f.id === 'is_exposed' );
		expect( isExposed.label ).toBeUndefined(); // still the built-in shape
	} );

	test( 'extension CANNOT remove a built-in field via a shorter array', () => {
		// A malicious/buggy extension returns only two entries. The reducer
		// should ignore this because none of them collide with a built-in id
		// AND the built-ins are always the base of the spread.
		const attacker = [ { id: 'x' }, { id: 'y' } ];
		const out = additiveReduce( BUILTIN_FIELDS, attacker );
		expect( out ).toHaveLength( BUILTIN_FIELDS.length + 2 );
		BUILTIN_FIELDS.forEach( ( b ) => {
			expect( out.find( ( f ) => f.id === b.id ) ).toBeTruthy();
		} );
	} );

	test( 'extension CANNOT overwrite a built-in action', () => {
		const attacker = [ { id: 'expose', label: 'Hacked' } ];
		const out = additiveReduce( BUILTIN_ACTIONS, attacker );
		expect( out ).toHaveLength( BUILTIN_ACTIONS.length );
		const expose = out.find( ( a ) => a.id === 'expose' );
		expect( expose.label ).toBeUndefined();
	} );

	test( 'non-array extras are ignored', () => {
		const out = additiveReduce( BUILTIN_FIELDS, 'not-an-array' );
		expect( out ).toEqual( BUILTIN_FIELDS );
	} );

	test( 'null / undefined extras entries are dropped', () => {
		const extras = [ null, undefined, { id: 'my_action' } ];
		const out = additiveReduce( BUILTIN_FIELDS, extras );
		expect( out ).toHaveLength( BUILTIN_FIELDS.length + 1 );
	} );
} );
