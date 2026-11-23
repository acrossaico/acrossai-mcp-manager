<?php
/**
 * Feature 067 — update an Elementor experiment feature flag state.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Update an Elementor experiment state via elementor_experiment_ option.
 */
class Update_Experiment extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/update-experiment',
			'args' => array(
				'label'               => __( 'Update Elementor Experiment', 'acrossai-abilities-manager' ),
				'description'         => __( 'Update an Elementor experiment state (active | inactive | default). Writes to elementor_experiment_<name> option.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'experiment' => array( 'type' => 'string' ),
						'state'      => array( 'type' => 'string', 'enum' => array( 'active', 'inactive', 'default' ) ),
					),
					'required' => array( 'experiment', 'state' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'experiment'     => array( 'type' => 'string' ),
						'previous_state' => array( 'type' => 'string' ),
						'new_state'      => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-system', 'sub_group_label' => __( 'System & Maintenance', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'experiment' => '', 'previous_state' => '', 'new_state' => '', 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$experiment = isset( $input['experiment'] ) ? sanitize_key( (string) $input['experiment'] ) : '';
		$state      = isset( $input['state'] ) ? sanitize_key( (string) $input['state'] ) : '';
		if ( '' === $experiment || ! in_array( $state, array( 'active', 'inactive', 'default' ), true ) ) {
			return array( 'success' => false, 'experiment' => $experiment, 'previous_state' => '', 'new_state' => '', 'message' => __( 'experiment and state are required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}
		$option_key = 'elementor_experiment-' . $experiment;
		$previous   = (string) get_option( $option_key, 'default' );
		update_option( $option_key, $state );
		Document_Repository::invalidate_cache( 0, 'site' );
		return array(
			'success'        => true,
			'experiment'     => $experiment,
			'previous_state' => $previous,
			'new_state'      => $state,
			/* translators: 1: experiment, 2: state */
			'message'        => sprintf( __( 'Experiment "%1$s" set to %2$s.', 'acrossai-abilities-manager' ), $experiment, $state ),
		);
	}
}
