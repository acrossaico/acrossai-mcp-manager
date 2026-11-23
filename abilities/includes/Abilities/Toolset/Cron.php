<?php
/**
 * The Cron Toolset.
 *
 * Scheduled tasks and whether they are actually running.
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
 * Dispatcher for the Cron group.
 */
final class Cron extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'cron';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/cron';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Cron', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Inspect and manage scheduled tasks: list cron jobs and custom schedules, check whether a job exists or is overdue, find the next run, create, update, delete and run jobs on demand, and test that WP-Cron is firing at all. Start here when something that should happen automatically is not happening. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
