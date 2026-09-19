<?php
/**
 * The Backups Toolset.
 *
 * What protects this site: backup state, inventory, exposure, and taking or restoring one.
 *
 * Four declarations and no behaviour — everything else is
 * {@see Base_Toolset_Ability}. If this class ever needs more than these
 * methods, the shared class is missing something; add it there.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

namespace AcrossAI_MCP_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher for the Cache group.
 */
final class Backups extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'backups';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/backups';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Backups', 'acrossai-mcp-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Whether this site can be recovered: which backup plugins are active, when each last ran and whether it worked, what backup sets exist, and whether the archives are reachable over HTTP. Also takes a backup, deletes one, and restores from one. Works with UpdraftPlus and All-in-One WP Migration through one set of abilities. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-mcp-manager' );
	}
}
