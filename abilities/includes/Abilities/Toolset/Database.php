<?php
/**
 * The Database Toolset.
 *
 * The data layer — inspect, audit, and write directly.
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
 * Dispatcher for the Database group.
 */
final class Database extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'database';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/database';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Database', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Work with the database: inspect the schema, tables and prefix, audit health, index health, autoloaded options and storage engines, optimise tables, and run direct SELECT, INSERT, UPDATE and DELETE queries or a search and replace. Several abilities here operate on live data with wide blast radius. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
