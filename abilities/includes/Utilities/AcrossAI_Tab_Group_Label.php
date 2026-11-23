<?php
/**
 * Single source of truth for ability tab-group display labels.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Converts a tab-group key into its display label.
 *
 * The Integrations admin page derives its tabs in JavaScript at render time
 * (PATTERN-ABILITY-LIBRARY-TAB-AUTO-DERIVE) using a `titleCaseTabLabel` helper.
 * Any server-side consumer that must agree with that page — the Quick Connect
 * wizard's integrations screen, per spec SC-004 — has to apply the identical
 * rule, or the two surfaces silently disagree.
 *
 * This class is that rule. The JS helper is pinned to it by a paired
 * PHPUnit/Jest fixture; change one and the other test fails.
 *
 * @since 0.0.34
 */
class AcrossAI_Tab_Group_Label {

	/**
	 * Format a tab-group key for display.
	 *
	 * Mirrors the JS `titleCaseTabLabel`: hyphens become spaces, then each word
	 * is capitalised. `content-search` becomes `Content Search`.
	 *
	 * @since  0.0.34
	 * @param  string $key Tab-group key, e.g. `content-search`.
	 * @return string Display label, e.g. `Content Search`. Empty input returns empty.
	 */
	public static function format( string $key ): string {
		if ( '' === $key ) {
			return '';
		}

		return ucwords( str_replace( '-', ' ', $key ) );
	}
}
