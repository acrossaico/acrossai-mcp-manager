<?php
/**
 * Feature 104 — shared shape for every settings reader.
 *
 * Twelve abilities read one area of LiteSpeed's configuration and differ only in which area, its
 * label and its description. Hoisting the schema and the run() here means the row shape is defined
 * once: rows, never a map, because an associative array encodes as a JSON object and fails an output
 * property declared `array` after the work is done (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one settings area.
 */
abstract class Base_Settings_Read_Ability extends Base_LiteSpeed_Ability {

	/**
	 * The settings area this ability reads, as keyed in Settings_Repository::areas().
	 *
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	abstract protected function areas_read(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'array' ),
			'count'    => array( 'type' => 'integer' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$rows = array();

		foreach ( $this->areas_read() as $area ) {
			$rows = array_merge( $rows, Settings_Repository::describe_area( $area ) );
		}

		return array(
			'settings' => $rows,
			'count'    => count( $rows ),
			'message'  => sprintf(
				/* translators: %d: number of settings */
				_n( '%d setting.', '%d settings.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
