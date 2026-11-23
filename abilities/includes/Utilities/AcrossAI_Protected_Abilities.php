<?php
/**
 * Manages protected system abilities that are hidden from REST endpoints and the UI.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides a single source of truth for protected ability slugs.
 *
 * Protected abilities are excluded from:
 * - GET /sitewide/abilities list response
 * - GET /sitewide/abilities/{slug} endpoints (returns 404)
 *
 * Extensible via the 'acrossai_abilities_manager_protected_slugs' WordPress filter.
 *
 * @since 0.1.0
 */
class AcrossAI_Protected_Abilities {

	/**
	 * Get the list of protected ability slugs.
	 *
	 * Default protected slugs are:
	 * - mcp-adapter/discover-abilities
	 * - mcp-adapter/execute-ability
	 * - mcp-adapter/get-ability-info
	 *
	 * The Toolset dispatchers are protected too, but they are not listed here:
	 * each one adds its own slug through the filter below, from
	 * `Base_Toolset_Ability`. A Toolset is the only thing that knows its own
	 * slug, so repeating the set here would be a second copy free to drift from
	 * the first every time a group is added or renamed.
	 *
	 * Other plugins can extend this list via the filter.
	 *
	 * @since  0.1.0
	 * @return string[] Array of protected ability slugs.
	 */
	public static function get_protected_slugs(): array {
		$default = array(
			'mcp-adapter/discover-abilities',
			'mcp-adapter/execute-ability',
			'mcp-adapter/get-ability-info',
		);

		/**
		 * Filters the list of protected ability slugs.
		 *
		 * Protected abilities are hidden from REST endpoints and the UI.
		 * They are excluded from GET /sitewide/abilities and return 404 from
		 * GET /sitewide/abilities/{slug}.
		 *
		 * @since 0.1.0
		 * @param string[] $default Array of default protected ability slugs.
		 */
		return (array) apply_filters( 'acrossai_abilities_manager_protected_slugs', $default );
	}

	/**
	 * Check if an ability slug is protected.
	 *
	 * @since  0.1.0
	 * @param  string $slug Ability slug to check.
	 * @return bool True if the slug is protected, false otherwise.
	 */
	public static function is_protected( string $slug ): bool {
		$protected_slugs = self::get_protected_slugs();
		return in_array( $slug, $protected_slugs, true );
	}
}
