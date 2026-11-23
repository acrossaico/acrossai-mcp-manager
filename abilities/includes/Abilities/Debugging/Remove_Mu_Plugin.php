<?php
/**
 * Remove_Mu_Plugin ability (Feature 061).
 *
 * Slug: acrossai/conflict-test-remove-mu-plugin
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Remove the mu-plugin file, optionally clearing the overrides map in the same call.
 *
 * Idempotent — an already-absent mu-plugin returns success. Order matters:
 * the mu-plugin is removed first so that any partial-failure on the JSON
 * clear leaves the mu-plugin definitively gone (and any lingering overrides
 * become inert).
 */
class Remove_Mu_Plugin extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'acrossai/conflict-test-remove-mu-plugin',
			'args' => array(
				'label'               => __( 'Remove Conflict-Test Mu-Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deletes the mu-plugin that reads the conflict-test override map. When also_clear_overrides is true, also deletes the JSON overrides file. Idempotent — an already-absent mu-plugin returns success.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'also_clear_overrides' => array(
							'type'        => 'boolean',
							'description' => __( 'When true, additionally delete the JSON overrides file. Defaults to false.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'             => array( 'type' => 'boolean' ),
						'removed'             => array( 'type' => 'boolean' ),
						'file_existed_before' => array( 'type' => 'boolean' ),
						'overrides_cleared'   => array( 'type' => 'boolean' ),
						'message'             => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
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
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute — delete the mu-plugin file, optionally clear overrides too.
	 *
	 * @param array $input Input payload — also_clear_overrides flag.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$also_clear    = isset( $input['also_clear_overrides'] ) ? (bool) $input['also_clear_overrides'] : false;
		$store         = Overrides_Store::instance();
		$deployed_path = $store->mu_plugin_path();

		$file_existed_before = file_exists( $deployed_path );
		if ( $file_existed_before && ! unlink( $deployed_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fixed system-owned path
			return array(
				'success' => false,
				'message' => __( 'Could not remove mu-plugin file.', 'acrossai-abilities-manager' ),
			);
		}

		$overrides_cleared = false;
		if ( $also_clear ) {
			$clear_result      = $store->clear();
			$overrides_cleared = (bool) ( $clear_result['file_existed_before'] && $clear_result['cleared'] );
		}

		return array(
			'success'             => true,
			'removed'             => true,
			'file_existed_before' => $file_existed_before,
			'overrides_cleared'   => $overrides_cleared,
		);
	}
}
