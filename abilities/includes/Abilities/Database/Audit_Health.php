<?php
/**
 * Feature 086 — combined database health snapshot.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Database
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Database;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded snapshot combining storage totals + engine mix + index issue counts
 * + options / autoload / transient rollup. A one-call orientation read; delegates
 * detail to audit-index-health and audit-options-health.
 */
class Audit_Health extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/audit-health',
			'args' => array(
				'label'               => __( 'Audit Database Health', 'acrossai-abilities-manager' ),
				'description'         => __( 'Bounded snapshot combining storage totals, engine mix, index issue counts, and options/autoload/transient rollup. One-call orientation read; delegates detail to audit-index-health and audit-options-health.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'     => array( 'type' => 'boolean' ),
						'observed_at' => array( 'type' => 'string' ),
						'scope'       => array( 'type' => 'string' ),
						'coverage'    => array( 'type' => 'object' ),
						'storage'     => array( 'type' => 'object' ),
						'indexes'     => array( 'type' => 'object' ),
						'options'     => array( 'type' => 'object' ),
						'message'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'database',
						'sub_group' => 'audit',
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		unset( $input );

		$index_ability   = new Audit_Index_Health();
		$options_ability = new Audit_Options_Health();

		$idx_result = $index_ability->execute( array( 'limit' => 1, 'offset' => 0 ) );
		$opt_result = $options_ability->execute( array( 'limit' => 5 ) );

		return array(
			'success'     => true,
			'observed_at' => gmdate( 'c' ),
			'scope'       => 'current-site',
			'coverage'    => (object) array(
				'storage_and_indexes' => true,
				'options'             => true,
				'core_data_integrity' => false,
				'query_workload'      => false,
			),
			'storage'     => (object) array(
				'table_count'  => (int) ( $idx_result['total_table_count'] ?? 0 ),
				'data_bytes'   => (int) ( $idx_result['total_data_bytes'] ?? 0 ),
				'index_bytes'  => (int) ( $idx_result['total_index_bytes'] ?? 0 ),
				'free_bytes'   => (int) ( $idx_result['total_free_bytes'] ?? 0 ),
				'engine_counts' => $idx_result['engine_counts'] ?? new \stdClass(),
			),
			'indexes'     => (object) array(
				'issue_count' => (int) ( $idx_result['issue_count'] ?? 0 ),
				'issues'      => $idx_result['issues'] ?? array(),
			),
			'options'     => (object) array(
				'option_count'             => (int) ( $opt_result['option_count'] ?? 0 ),
				'total_value_bytes'        => (int) ( $opt_result['total_value_bytes'] ?? 0 ),
				'autoload_count'           => (int) ( $opt_result['autoload_count'] ?? 0 ),
				'autoload_bytes'           => (int) ( $opt_result['autoload_bytes'] ?? 0 ),
				'oversized_autoload_count' => (int) ( $opt_result['oversized_autoload_count'] ?? 0 ),
				'expired_transient_count'  => (int) ( $opt_result['expired_transient_count'] ?? 0 ),
				'issue_count'              => (int) ( $opt_result['issue_count'] ?? 0 ),
			),
			'message'     => __( 'Database health snapshot returned.', 'acrossai-abilities-manager' ),
		);
	}
}
