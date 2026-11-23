<?php
/**
 * Feature 067 — list Elementor Pro form submissions.
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
 * List Elementor Pro Form widget submissions. Uses Elementor Pro's own
 * Submissions module when available; degrades gracefully with an empty
 * list + note when Pro is present but submissions storage is missing.
 */
class List_Form_Submissions extends Ability_Definition {

	/** Custom DB table Elementor Pro uses for submissions. */
	public const TABLE = 'e_submissions';

	protected function ability(): array {
		return array(
			'name' => 'elementor/list-form-submissions',
			'args' => array(
				'label'               => __( 'List Elementor Pro Form Submissions', 'acrossai-abilities-manager' ),
				'description'         => __( 'List Elementor Pro Form widget submissions with optional form_id filter and include_values flag. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'form_id'        => array( 'type' => 'string' ),
						'limit'          => array( 'type' => 'integer', 'default' => 25 ),
						'offset'         => array( 'type' => 'integer', 'default' => 0 ),
						'include_values' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required' => array(),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'submissions' => array( 'type' => 'array' ),
						'count'       => array( 'type' => 'integer' ),
						'message'     => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
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
		$form_id        = isset( $input['form_id'] ) ? sanitize_text_field( (string) $input['form_id'] ) : '';
		$limit          = max( 1, (int) ( $input['limit'] ?? 25 ) );
		$offset         = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$include_values = ! empty( $input['include_values'] );

		$table = $wpdb->prefix . self::TABLE;
		// Guard: if the Pro submissions table doesn't exist, degrade gracefully.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array( 'success' => true, 'submissions' => array(), 'count' => 0, 'message' => __( 'Elementor Pro submissions storage not available.', 'acrossai-abilities-manager' ) );
		}
		$where  = '';
		$params = array();
		if ( '' !== $form_id ) {
			$where    = ' WHERE form_id = %s';
			$params[] = $form_id;
		}
		$params[]     = $limit;
		$params[]     = $offset;
		$query        = 'SELECT id, form_id, form_name, post_id, created_at FROM ' . $table . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$rows         = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );
		// phpcs:enable
		$submissions  = array();
		foreach ( (array) $rows as $row ) {
			$entry = array(
				'id'         => (int) $row['id'],
				'form_id'    => (string) $row['form_id'],
				'form_name'  => (string) ( $row['form_name'] ?? '' ),
				'post_id'    => (int) ( $row['post_id'] ?? 0 ),
				'created_at' => (string) ( $row['created_at'] ?? '' ),
			);
			if ( $include_values ) {
				$entry['values'] = self::fetch_values( (int) $row['id'] );
			}
			$submissions[] = $entry;
		}
		return array(
			'success'     => true,
			'submissions' => $submissions,
			'count'       => count( $submissions ),
			/* translators: %d: count */
			'message'     => sprintf( __( 'Returned %d form submissions.', 'acrossai-abilities-manager' ), count( $submissions ) ),
		);
	}

	/**
	 * Fetch field values from the submission-values table.
	 *
	 * @param int $submission_id
	 * @return array<int, array<string, string>>
	 */
	public static function fetch_values( int $submission_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'e_submissions_values';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT `key`, `value` FROM ' . $table . ' WHERE submission_id = %d', $submission_id ), ARRAY_A );
		// phpcs:enable
		$values = array();
		foreach ( (array) $rows as $row ) {
			$values[] = array( 'field' => (string) $row['key'], 'value' => (string) $row['value'] );
		}
		return $values;
	}
}
