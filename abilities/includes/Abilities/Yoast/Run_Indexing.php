<?php
/**
 * Feature 106 — Request Indexation.
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
 * seo/run-indexing — Request Indexation.
 */
final class Run_Indexing extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/run-indexing';
	}

	protected function ability_label(): string {
		return __( 'Request Indexation', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Mark Yoast\'s indexation as needing to run, so it rebuilds on its next scheduled pass. Deliberately does NOT rebuild synchronously: a full index on a large site takes far longer than a request, and a half-finished synchronous run leaves the table inconsistent. Poll seo/get-indexing-status for progress.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexing';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-indexing-status',
		);
	}

	protected function input_properties(): array {
		return array(
			'reason' => array(
				'type'        => 'string',
				'default'     => 'Requested through an ability.',
				'description' => __( 'Why indexation was requested, recorded in Yoast\'s log.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'requested' => array( 'type' => 'boolean' ),

			'status' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		Tools_Repository::reset_indexing( isset( $input['reason'] ) ? (string) $input['reason'] : 'Requested through an ability.' );

		return array(
			'requested' => true,
			'status'    => Tools_Repository::indexing_status(),
			'message'   => __( 'Indexation requested. Yoast rebuilds on its next scheduled pass; poll seo/get-indexing-status.', 'acrossai-abilities-manager' ),
		);
	}
}
