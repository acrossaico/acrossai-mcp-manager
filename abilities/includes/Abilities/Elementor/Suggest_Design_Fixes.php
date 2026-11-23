<?php
/**
 * Feature 067 — turn design-audit findings into concrete fix suggestions.
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

class Suggest_Design_Fixes extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/suggest-design-fixes',
			'args' => array(
				'label'               => __( 'Suggest Elementor Design Fixes', 'acrossai-abilities-manager' ),
				'description'         => __( 'Turn aggregated design-audit findings into concrete fix recommendations.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'subtree_id' => array( 'type' => 'string' ) ), 'required' => array( 'post_id' ), 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'post_id' => array( 'type' => 'integer' ), 'audit_count' => array( 'type' => 'integer' ), 'recommendations' => array( 'type' => 'array' ), 'source_policy' => array( 'type' => 'string' ), 'message' => array( 'type' => 'string' ), 'error_code' => array( 'type' => 'string' ) ), 'required' => array( 'success' ), 'additionalProperties' => false ),
				'meta' => array( 'acrossai' => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-design-audit', 'sub_group_label' => __( 'Design Audit', 'acrossai-abilities-manager' ) ), 'show_in_rest' => true, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'post_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$post_id    = absint( $input['post_id'] ?? 0 );
		$subtree_id = isset( $input['subtree_id'] ) ? (string) $input['subtree_id'] : '';
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => __( 'Post not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		$report = Design_Audit_Runner::run_all( $post_id, $subtree_id );
		$fixes  = (array) ( $report['recommendations'] ?? array() );

		// Same reasoning as evaluate-design: "Generated 0 fix recommendations" reads as
		// "nothing needs fixing" when it actually means nothing was inspected.
		$message = 0 === (int) ( $report['audit_count'] ?? 0 )
			? __( 'No Elementor design audits are registered on this site, so no page was inspected and no fixes could be suggested. See issue #243.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: 1: number of recommendations, 2: number of audits run */
				__( 'Generated %1$d fix recommendations from %2$d design audits.', 'acrossai-abilities-manager' ),
				count( $fixes ),
				(int) $report['audit_count']
			);

		return array(
			'success'         => true,
			'post_id'         => $post_id,
			'audit_count'     => (int) ( $report['audit_count'] ?? 0 ),
			'recommendations' => $fixes,
			'source_policy'   => (string) ( $report['source_policy'] ?? 'elementor_docs_first' ),
			'message'         => $message,
		);
	}
}
