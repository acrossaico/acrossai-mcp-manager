<?php
/**
 * Feature 067 — aggregate design evaluator.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Design_Audit_Runner;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Compose every registered design audit into a single evaluation report.
 * Runs via Design_Audit_Runner::run_all().
 */
class Evaluate_Design extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/evaluate-design',
			'args' => array(
				'label'               => __( 'Evaluate Elementor Design', 'acrossai-abilities-manager' ),
				'description'         => __( 'Aggregate report across every registered Elementor design audit — score + findings + recommendations.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
						'subtree_id' => array( 'type' => 'string' ),
					),
					'required' => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'post_id'         => array( 'type' => 'integer' ),
						// Both are returned by Design_Audit_Runner::run_all() and were
						// absent here. With additionalProperties:false that made EVERY
						// call fail output validation — issue #243.
						'subtree_id'      => array( 'type' => 'string' ),
						'audit_count'     => array( 'type' => 'integer' ),
						'score'           => array( 'type' => array( 'number', 'null' ) ),
						'score_basis'     => array( 'type' => 'string' ),
						'lowest_score'    => array( 'type' => array( 'number', 'null' ) ),
						'findings'        => array( 'type' => 'array' ),
						'recommendations' => array( 'type' => 'array' ),
						'audits_run'      => array( 'type' => 'array' ),
						'source_policy'   => array( 'type' => 'string' ),
						'guidance_basis'  => array( 'type' => 'string' ),
						'message'         => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-design-audit', 'sub_group_label' => __( 'Design Audit', 'acrossai-abilities-manager' ) ),
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
		$post_id    = absint( $input['post_id'] ?? 0 );
		$subtree_id = isset( $input['subtree_id'] ) ? (string) $input['subtree_id'] : '';

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => __( 'Post not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}

		$report = Design_Audit_Runner::run_all( $post_id, $subtree_id );

		// "Design evaluation complete" with an empty findings list reads as "this page
		// is fine". When no audit ran, the truthful statement is that nothing looked.
		if ( 0 === (int) ( $report['audit_count'] ?? 0 ) ) {
			unset( $report['guidance_basis'] );

			return array_merge(
				array(
					'success' => true,
					'message' => __( 'No Elementor design audits are registered on this site, so nothing was evaluated. This is not a clean result — no check ran. See issue #243.', 'acrossai-abilities-manager' ),
				),
				$report
			);
		}

		return array_merge(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: number of audits run, 2: number of findings */
					__( 'Ran %1$d design audits and found %2$d issues.', 'acrossai-abilities-manager' ),
					(int) $report['audit_count'],
					count( (array) ( $report['findings'] ?? array() ) )
				),
			),
			$report
		);
	}
}
