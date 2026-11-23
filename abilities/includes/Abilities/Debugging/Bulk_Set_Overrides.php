<?php
/**
 * Bulk_Set_Overrides ability (Feature 061).
 *
 * Slug: acrossai/conflict-test-bulk-set-overrides
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Set the effective active state of many plugins in a single operation.
 *
 * Best-effort with a per-plugin classification (applied / no_op / skipped) —
 * unknown plugins and plugins that fatal on the sandbox-scrape probe are
 * reported under `skipped` rather than aborting the whole call.
 *
 * No cascade — the caller controls the exact list per FR-010.
 */
class Bulk_Set_Overrides extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'acrossai/conflict-test-bulk-set-overrides',
			'args' => array(
				'label'               => __( 'Bulk Set Conflict-Test Overrides', 'acrossai-abilities-manager' ),
				'description'         => __( 'Set the effective active state of many plugins in one atomic write. Best-effort with a per-plugin report — unknowns and fatals do not abort the call, they are recorded under skipped.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin_files' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'active'       => array(
							'type' => 'boolean',
						),
					),
					'required'             => array( 'plugin_files', 'active' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'applied' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'plugin_file' => array( 'type' => 'string' ),
									'active'      => array( 'type' => 'boolean' ),
								),
								'required'             => array( 'plugin_file', 'active' ),
								'additionalProperties' => false,
							),
						),
						'no_op'   => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'plugin_file' => array( 'type' => 'string' ),
									'reason'      => array( 'type' => 'string' ),
								),
								'required'             => array( 'plugin_file', 'reason' ),
								'additionalProperties' => false,
							),
						),
						'skipped' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'plugin_file' => array( 'type' => 'string' ),
									'reason'      => array( 'type' => 'string' ),
								),
								'required'             => array( 'plugin_file', 'reason' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'success', 'applied', 'no_op', 'skipped' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'conflict-testing',
						'sub_group_label' => __( 'Conflict Testing', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the bulk override.
	 *
	 * @param array $input Input payload — plugin_files + active.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$plugin_files = isset( $input['plugin_files'] ) && is_array( $input['plugin_files'] )
			? array_values( array_unique( array_map( 'sanitize_text_field', $input['plugin_files'] ) ) )
			: array();
		$active       = (bool) ( $input['active'] ?? false );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();

		$applied = array();
		$no_op   = array();
		$skipped = array();
		$pending = array();

		foreach ( $plugin_files as $plugin_file ) {
			if ( '' === $plugin_file ) {
				continue;
			}

			if ( ! isset( $installed[ $plugin_file ] ) ) {
				$skipped[] = array(
					'plugin_file' => $plugin_file,
					'reason'      => 'plugin-not-installed',
				);
				continue;
			}

			if ( $active && false === Set_Override::sandbox_scrape( $plugin_file ) ) {
				$skipped[] = array(
					'plugin_file' => $plugin_file,
					'reason'      => 'plugin-fatal-on-load',
				);
				continue;
			}

			$pending[ $plugin_file ] = $active;
		}

		if ( ! empty( $pending ) ) {
			$batch = Overrides_Store::instance()->write_many( $pending );
			$applied = array_merge( $applied, $batch['applied'] );
			$no_op   = array_merge( $no_op, $batch['no_op'] );
		}

		return array(
			'success' => true,
			'applied' => $applied,
			'no_op'   => $no_op,
			'skipped' => $skipped,
		);
	}
}
