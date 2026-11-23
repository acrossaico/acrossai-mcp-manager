<?php
/**
 * The Configuration Toolset.
 *
 * Site configuration — options, permalinks, the admin menu and upload policy.
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
 * Dispatcher for the Configuration group.
 */
final class Configuration extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'configuration';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/configuration';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Configuration', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Read and change site configuration: arbitrary options including nested values, permalink structure and rewrite rules, the admin menu tree and Settings API registry, and which file types the site accepts on upload. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
