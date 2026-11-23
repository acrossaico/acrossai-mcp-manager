<?php
/**
 * Feature 067 — list Elementor experiments.
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
 * List Elementor experiment feature flags with their current state.
 */
class List_Experiments extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/list-experiments',
			'args' => array(
				'label'               => __( 'List Elementor Experiments', 'acrossai-abilities-manager' ),
				'description'         => __( 'List Elementor experiment feature flags with their current state (active | inactive | default) and default state.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'required' => array(), 'additionalProperties' => false ),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'experiments' => array( 'type' => 'array' ),
						'count'       => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-system', 'sub_group_label' => __( 'System & Maintenance', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$experiments = array();
		$instance    = \Elementor\Plugin::$instance;
		if ( isset( $instance->experiments ) && method_exists( $instance->experiments, 'get_features' ) ) {
			$features = (array) $instance->experiments->get_features();
			foreach ( $features as $name => $feature ) {
				if ( ! is_array( $feature ) ) {
					continue;
				}
				$experiments[] = array(
					'name'          => (string) $name,
					'title'         => (string) ( $feature['title'] ?? $name ),
					'state'         => (string) ( $feature['state'] ?? 'default' ),
					'default_state' => (string) ( $feature['default'] ?? 'inactive' ),
					'description'   => (string) ( $feature['description'] ?? '' ),
					'release_status' => (string) ( $feature['release_status'] ?? '' ),
				);
			}
		}
		return array(
			'success'     => true,
			'experiments' => $experiments,
			'count'       => count( $experiments ),
			/* translators: %d: count */
			'message'     => sprintf( __( 'Returned %d experiments.', 'acrossai-abilities-manager' ), count( $experiments ) ),
		);
	}
}
