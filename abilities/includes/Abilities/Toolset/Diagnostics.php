<?php
/**
 * The Diagnostics Toolset.
 *
 * Something is wrong — find out what.
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
 * Dispatcher for the Diagnostics group.
 */
final class Diagnostics extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'diagnostics';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/diagnostics';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Diagnostics', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Diagnose a broken or misbehaving site: read Site Health status and info, generate a maintenance report, toggle maintenance mode, inspect recovery mode and recent fatal errors, un-pause plugins and themes WordPress has disabled, and bisect a plugin conflict by toggling plugins without touching the active-plugins option. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
