<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Database
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Database;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Explain_Db_Query ability class (absorbed).
 */
class Explain_Db_Query extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/explain-db-query',
			'args' => array(
				'label'               => __( 'Explain Query', 'acrossai-abilities-manager' ),
				'description'         => __( 'Runs EXPLAIN on a SELECT query and returns the MySQL query execution plan. Useful for diagnosing slow queries.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'sql' => array(
							'type'        => 'string',
							'description' => __( 'SELECT query to explain.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'sql' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'plan'    => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'plan' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'database',
						'sub_group'       => 'queries',
						'sub_group_label' => __( 'Queries', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		global $wpdb;

		$sql = trim( $input['sql'] ?? '' );

		if ( '' === $sql ) {
			return array(
				'success' => false,
				'plan'    => array(),
				'message' => __( 'sql is required.', 'acrossai-abilities-manager' ),
			);
		}

		// Only allow SELECT to be EXPLAINed via this ability.
		$stripped = preg_replace( '/\/\*.*?\*\/|--[^\n]*|#[^\n]*/s', '', $sql );
		preg_match( '/^\s*(\w+)/i', $stripped, $m );
		$first_keyword = strtoupper( $m[1] ?? '' );

		if ( 'SELECT' !== $first_keyword ) {
			return array(
				'success' => false,
				'plan'    => array(),
				'message' => __( 'Only SELECT queries can be explained via this ability.', 'acrossai-abilities-manager' ),
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
		// phpcs:enable

		if ( null === $plan ) {
			return array(
				'success' => false,
				'plan'    => array(),
				'message' => $wpdb->last_error ?: __( 'EXPLAIN returned null.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success' => true,
			'plan'    => $plan,
		);
	}
}
