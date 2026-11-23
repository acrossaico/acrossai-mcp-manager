<?php
/**
 * Feature 104 — Get Autoloaded Options Summary.
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
 * litespeed/get-autoload-summary — Get Autoloaded Options Summary.
 */
final class Get_Autoload_Summary extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-autoload-summary';
	}

	protected function ability_label(): string {
		return __( 'Get Autoloaded Options Summary', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The size and count of autoloaded options — the data WordPress loads on every single request. A large autoload total is one of the most common causes of a site that is slow everywhere at once, and it is invisible from the front end.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-database';
	}

	protected function suggested_abilities(): array {
		return array(
			'database/audit-options-health',
			'database/set-option-autoload',
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
			'summary' => array( 'type' => 'array' ),
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
		$rows = Database_Repository::autoload_summary();

		return array(
			'summary' => $rows,
			'message' => sprintf(
				/* translators: %d: number of reported figures */
				__( '%d autoload figure(s).', 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
