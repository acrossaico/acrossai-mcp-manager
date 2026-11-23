<?php
/**
 * Feature 106 — Get Indexation Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Tools_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-indexing-status — Get Indexation Status.
 */
final class Get_Indexing_Status extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-indexing-status';
	}

	protected function ability_label(): string {
		return __( 'Get Indexation Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Whether Yoast has built its indexables, when it last ran and why. Indexables are the table behind archive titles, breadcrumbs and internal-link reporting, so an empty index is the usual explanation for those returning nothing. Note Yoast refuses to build them outside a production environment, which this reports plainly rather than leaving you to guess.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexing';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-indexation-counts',
		);
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'status' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$status = Tools_Repository::indexing_status();

		return array(
			'status'  => $status,
			'message' => $status['indexables_built']
				? ( $status['completed'] ? __( 'Indexation is complete.', 'acrossai-abilities-manager' ) : __( 'Indexables exist but indexation has not finished.', 'acrossai-abilities-manager' ) )
				: __( 'No indexables have been built. On a non-production environment Yoast will not build them at all.', 'acrossai-abilities-manager' ),
		);
	}
}
