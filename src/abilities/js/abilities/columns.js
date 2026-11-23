/**
 * Abilities list column preferences.
 *
 * Extracted from AbilitiesList.jsx in Feature 102 so both the list and its toolbar can read the
 * labels without importing a component, and so loadColumnPrefs() can be unit-tested directly
 * instead of through a wall of jest.mock() calls (task T017).
 *
 * @since 0.2.0
 */
import { __ } from '@wordpress/i18n';

export const COLUMN_DEFAULTS = {
	label: true,
	category: true,
	toolset: true,
	source: true,
	status: true,
	type: true,
	description: true,
	show_in_rest: true,
	mcp: true,
};

export const COLUMN_LABELS = {
	label: __( 'Label', 'acrossai-abilities-manager' ),
	category: __( 'Category', 'acrossai-abilities-manager' ),
	toolset: __( 'Toolset', 'acrossai-abilities-manager' ),
	source: __( 'Source', 'acrossai-abilities-manager' ),
	status: __( 'Status', 'acrossai-abilities-manager' ),
	type: __( 'Type', 'acrossai-abilities-manager' ),
	description: __( 'Description', 'acrossai-abilities-manager' ),
	show_in_rest: __( 'Show in REST', 'acrossai-abilities-manager' ),
	mcp: __( 'MCP', 'acrossai-abilities-manager' ),
};

export const LS_KEY = 'acrossai_abilities_columns';

export function loadColumnPrefs() {
	try {
		const saved = JSON.parse( localStorage.getItem( LS_KEY ) || '{}' );
		const result = { ...COLUMN_DEFAULTS };
		Object.keys( COLUMN_DEFAULTS ).forEach( ( key ) => {
			if ( key in saved ) {
				result[ key ] = !! saved[ key ];
			}
		} );
		return result;
	} catch {
		return { ...COLUMN_DEFAULTS };
	}
}
