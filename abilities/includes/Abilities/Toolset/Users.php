<?php
/**
 * The Users Toolset.
 *
 * Users, roles and capabilities.
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
 * Dispatcher for the Users group.
 */
final class Users extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'users';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/users';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Users', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Manage users, roles and capabilities: list, create, update and delete users, reset passwords, inspect and modify role capabilities, and check what the current user is allowed to do. Many abilities here are security-sensitive and require administrator rights. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
