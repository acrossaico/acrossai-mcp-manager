<?php
/**
 * Feature 067 — delete an Elementor Pro form submission.
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
 * Delete an Elementor Pro Form widget submission (plus its field-values
 * rows). Requires confirm=true to prevent accidental deletion.
 */
class Delete_Form_Submission extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/delete-form-submission',
			'args' => array(
				'label'               => __( 'Delete Elementor Pro Form Submission', 'acrossai-abilities-manager' ),
				'description'         => __( 'Permanently delete an Elementor Pro Form widget submission plus its associated field values. Requires confirm=true. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'submission_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'confirm'       => array( 'type' => 'boolean' ),
					),
					'required' => array( 'submission_id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'submission_id' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-forms', 'sub_group_label' => __( 'Form Submissions', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$pro = Document_Repository::assert_elementor_pro_available();
		if ( is_wp_error( $pro ) ) {
			return array( 'success' => false, 'message' => (string) $pro->get_error_message(), 'error_code' => (string) $pro->get_error_code() );
		}
		$submission_id = absint( $input['submission_id'] ?? 0 );
		if ( empty( $input['confirm'] ) ) {
			return array( 'success' => false, 'submission_id' => $submission_id, 'message' => __( 'confirm=true is required.', 'acrossai-abilities-manager' ), 'error_code' => 'force_delete_required' );
		}
		global $wpdb;
		$table = $wpdb->prefix . List_Form_Submissions::TABLE;
		$vals  = $wpdb->prefix . 'e_submissions_values';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array( 'success' => false, 'submission_id' => $submission_id, 'message' => __( 'Elementor Pro submissions storage not available.', 'acrossai-abilities-manager' ), 'error_code' => 'submission_storage_missing' );
		}
		$wpdb->delete( $vals, array( 'submission_id' => $submission_id ), array( '%d' ) );
		$deleted = $wpdb->delete( $table, array( 'id' => $submission_id ), array( '%d' ) );
		// phpcs:enable
		if ( ! $deleted ) {
			return array( 'success' => false, 'submission_id' => $submission_id, 'message' => __( 'Submission not found.', 'acrossai-abilities-manager' ), 'error_code' => 'submission_not_found' );
		}
		return array(
			'success'       => true,
			'submission_id' => $submission_id,
			/* translators: %d: id */
			'message'       => sprintf( __( 'Deleted submission #%d.', 'acrossai-abilities-manager' ), $submission_id ),
		);
	}
}
