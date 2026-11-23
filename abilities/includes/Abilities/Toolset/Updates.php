<?php
/**
 * The Updates Toolset.
 *
 * What is installed, and keeping it current — plugins, themes and WordPress core.
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
 * Dispatcher for the Updates group.
 */
final class Updates extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'updates';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/updates';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Updates', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Manage what is installed and its updates: install, update, activate, deactivate and uninstall plugins; install, update, activate and delete themes; and check, update, reinstall or roll back WordPress core. Also verifies checksums against the official manifests. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
