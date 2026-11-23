<?php
/**
 * Feature 106 — Reset Indexation.
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
 * seo/reset-indexing — Reset Indexation.
 */
final class Reset_Indexing extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/reset-indexing';
	}

	protected function ability_label(): string {
		return __( 'Reset Indexation', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Clear Yoast\'s indexation progress so the next pass starts from the beginning rather than resuming. Use after a bulk import or a permalink change, when the existing indexables record URLs that no longer exist. Discards progress, not content.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexing';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __(
			'Resetting discards indexation progress and the next full pass can be slow on a large site. Pass confirm: true to proceed.',
			'acrossai-abilities-manager'
		);
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-indexing-status',
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
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
	}

	protected function run( array $input ) {
		Tools_Repository::reset_indexing( 'Reset through an ability.' );

		return array(
			'status'  => Tools_Repository::indexing_status(),
			'message' => __( 'Indexation progress reset. The next pass starts from the beginning.', 'acrossai-abilities-manager' ),
		);
	}
}
