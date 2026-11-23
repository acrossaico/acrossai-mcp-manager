<?php
/**
 * The Files Toolset.
 *
 * The filesystem — files, directories, backups, wp-config and the debug log.
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
 * Dispatcher for the Files group.
 */
final class Files extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'files';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/files';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Files', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Work with the site\'s filesystem: list, read, create, edit, copy, move and delete files and directories; create, list, download, upload, extract and delete zip backups; read and edit wp-config and its constants; and read or clear the debug log. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
