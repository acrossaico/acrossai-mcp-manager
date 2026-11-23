<?php
/**
 * Feature 104 — List MyISAM Tables.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Database_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/list-myisam-tables — List MyISAM Tables.
 */
final class List_Myisam_Tables extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'list-myisam-tables';
	}

	protected function ability_label(): string {
		return __( 'List MyISAM Tables', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which database tables still use the MyISAM engine rather than InnoDB. MyISAM locks the whole table on write, so a busy site with MyISAM tables stalls under load. This ability reports which ones need converting; database/convert-core-tables-to-innodb performs the conversion.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-database';
	}

	protected function suggested_abilities(): array {
		return array(
			'database/convert-core-tables-to-innodb',
			'database/audit-core-table-engines',
		);
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'tables' => array( 'type' => 'array' ),

			'count'  => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$tables = Database_Repository::myisam_tables();

		return array(
			'tables'  => $tables,
			'count'   => count( $tables ),
			'message' => array() === $tables
				? __( 'Every table uses InnoDB.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of tables */
					_n( '%d table still uses MyISAM.', '%d tables still use MyISAM.', count( $tables ), 'acrossai-abilities-manager' ),
					count( $tables )
				),
		);
	}
}
