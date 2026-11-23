/**
 * Shared tab-group label rule.
 *
 * Extracted in Feature 099 when a second consumer appeared. Constitution §VI
 * requires common logic to be extracted to a shared module before its second
 * use rather than duplicated.
 *
 * Consumers:
 * - `src/js/abilities/` (the toolset strip on the abilities list)
 * - `src/js/quick-connect/` (wizard integrations screen)
 *
 * Must stay character-for-character identical to the PHP rule in
 * `includes/Utilities/AcrossAI_Tab_Group_Label.php`. A paired PHPUnit/Jest
 * fixture pins the two together; change one and the other fails.
 *
 * @package
 */

/**
 * Convert a sanitized tab_group identifier into a display label.
 *
 * Mirrors the PHP `ucwords( str_replace( '-', ' ', $value ) )` rule so
 * server-rendered and client-rendered labels match for the same identifier.
 *
 * Named export per PATTERN-NAMED-EXPORT-JEST.
 *
 * @param {string} value Sanitized tab_group identifier.
 * @return {string} Display label.
 */
export function titleCaseTabLabel( value ) {
	if ( typeof value !== 'string' || value === '' ) {
		return '';
	}
	return value
		.replace( /-/g, ' ' )
		.split( ' ' )
		.map( ( word ) =>
			word.length > 0 ? word[ 0 ].toUpperCase() + word.slice( 1 ) : word,
		)
		.join( ' ' );
}

export default titleCaseTabLabel;
