<?php
/**
 * The Cache Toolset.
 *
 * Transients, the object cache and rewrite rules.
 *
 * Four declarations and no behaviour — everything else is
 * {@see Base_Toolset_Ability}. If this class ever needs more than these
 * methods, the shared class is missing something; add it there.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher for the Cache group.
 */
final class Cache extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'cache';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/cache';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Cache', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Clear what the site is holding in memory: read, list and delete transients including expired ones, flush the object cache, and flush rewrite rules. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
