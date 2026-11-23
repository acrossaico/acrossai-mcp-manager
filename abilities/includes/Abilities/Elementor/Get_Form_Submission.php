<?php
/**
 * Feature 067 — read one Elementor Pro form submission.
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

class Get_Form_Submission extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/get-form-submission',
			'args' => array(
				'label'               => __( 'Get Elementor Pro Form Submission', 'acrossai-abilities-manager' ),
				'description'         => __( 'Read one Elementor Pro Form widget submission by ID; optional include_values to fetch field values. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'submission_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
						'include_values' => array( 'type' => 'boolean', 'default' => true ),
					),
					'required' => array( 'submission_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'submission' => array( 'type' => 'object' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-forms', 'sub_group_label' => __( 'Form Submissions', 'acrossai-abilities-manager' ) ),
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
		$pro = Document_Repository::assert_elementor_pro_available();
		if ( is_wp_error( $pro ) ) {
			return array( 'success' => false, 'message' => (string) $pro->get_error_message(), 'error_code' => (string) $pro->get_error_code() );
		}
		global $wpdb;
		$submission_id  = absint( $input['submission_id'] ?? 0 );
		$include_values = ! isset( $input['include_values'] ) || (bool) $input['include_values'];
		$table          = $wpdb->prefix . List_Form_Submissions::TABLE;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array( 'success' => false, 'message' => __( 'Elementor Pro submissions storage not available.', 'acrossai-abilities-manager' ), 'error_code' => 'submission_storage_missing' );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, form_id, form_name, post_id, created_at FROM ' . $table . ' WHERE id = %d', $submission_id ), ARRAY_A );
		// phpcs:enable
		if ( ! $row ) {
			return array( 'success' => false, 'message' => __( 'Submission not found.', 'acrossai-abilities-manager' ), 'error_code' => 'submission_not_found' );
		}
		$submission = array(
			'id'         => (int) $row['id'],
			'form_id'    => (string) $row['form_id'],
			'form_name'  => (string) ( $row['form_name'] ?? '' ),
			'post_id'    => (int) ( $row['post_id'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
		);
		if ( $include_values ) {
			$submission['values'] = List_Form_Submissions::fetch_values( $submission_id );
		}
		return array(
			'success'    => true,
			'submission' => $submission,
			/* translators: %d: id */
			'message'    => sprintf( __( 'Returned submission #%d.', 'acrossai-abilities-manager' ), $submission_id ),
		);
	}
}
