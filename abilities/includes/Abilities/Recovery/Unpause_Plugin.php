<?php
/**
 * Feature 059 — Unpause plugin ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.17
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Plugin_Helpers;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Clears a plugin's paused-storage entry so WordPress retries loading it on
 * the next request. Distinct from `deactivate-plugin`: this does NOT change
 * the `active_plugins` option. If the plugin still fatally errors on retry,
 * WP re-pauses it.
 */
class Unpause_Plugin extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'recovery/unpause-plugin',
			'args' => array(
				'label'               => __( 'Unpause Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Clears the paused-storage entry for a plugin so WordPress retries loading it on the next request. Distinct from deactivate-plugin (which flips the active_plugins option); this ability leaves the active/inactive state alone. If the plugin still fatally errors when WP retries, it will be re-paused. Accepts a fuzzy plugin identifier (name, slug, or partial); when uncertain, returns a candidates list rather than acting.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-recovery',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug' => array(
							'type'        => 'string',
							'description' => __( 'Plugin identifier: full plugin file (e.g. "hello-dolly/hello.php"), directory slug, or plugin name. Fuzzy-matched via Plugin_Helpers::resolve_plugin().', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'                 => array( 'type' => 'boolean' ),
						'unpaused'                => array( 'type' => 'boolean' ),
						'plugin_file'             => array( 'type' => array( 'string', 'null' ) ),
						'plugin_name'             => array( 'type' => array( 'string', 'null' ) ),
						'was_paused'              => array( 'type' => 'boolean' ),
						'remaining_paused_count'  => array( 'type' => 'integer' ),
						'candidates'              => array( 'type' => 'array' ),
						'message'                 => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'recovery',
						'sub_group_label' => __( 'Recovery Mode', 'acrossai-abilities-manager' ),
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
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$blocked = File_Mods_Guard::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( ! function_exists( 'wp_paused_plugins' ) ) {
			return array(
				'success'  => false,
				'unpaused' => false,
				'message'  => __( 'WordPress paused-extensions storage is not available in this environment.', 'acrossai-abilities-manager' ),
			);
		}

		$identifier = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		if ( '' === trim( $identifier ) ) {
			return array(
				'success'  => false,
				'unpaused' => false,
				'message'  => __( 'Plugin identifier is required.', 'acrossai-abilities-manager' ),
			);
		}

		$resolved = Plugin_Helpers::resolve_plugin( $identifier );
		if ( null === $resolved['plugin_file'] || $resolved['certainty'] < 8.0 ) {
			return array(
				'success'    => false,
				'unpaused'   => false,
				'candidates' => $resolved['candidates'],
				'message'    => __( 'Ambiguous plugin identifier — pass a more specific slug or the full plugin_file value.', 'acrossai-abilities-manager' ),
			);
		}

		$paused_storage = wp_paused_plugins();
		$all_paused     = $paused_storage->get_all();
		$was_paused     = array_key_exists( $resolved['plugin_file'], $all_paused );

		if ( $was_paused ) {
			$paused_storage->delete( $resolved['plugin_file'] );
		}

		$remaining = $paused_storage->get_all();

		return array(
			'success'                => true,
			'unpaused'               => $was_paused,
			'plugin_file'            => $resolved['plugin_file'],
			'plugin_name'            => $resolved['plugin_name'],
			'was_paused'             => $was_paused,
			'remaining_paused_count' => count( $remaining ),
			'message'                => $was_paused
				? __( 'Paused entry cleared. WordPress will retry loading the plugin on the next request.', 'acrossai-abilities-manager' )
				: __( 'Plugin was not paused; nothing to do.', 'acrossai-abilities-manager' ),
		);
	}
}
