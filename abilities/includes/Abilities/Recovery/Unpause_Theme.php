<?php
/**
 * Feature 059 — Unpause theme ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.17
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Theme_Helpers;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Clears a theme's paused-storage entry so WordPress retries loading it on
 * the next request. If the theme still fatally errors on retry, WP re-pauses
 * it and falls back to the default theme.
 */
class Unpause_Theme extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'recovery/unpause-theme',
			'args' => array(
				'label'               => __( 'Unpause Theme', 'acrossai-abilities-manager' ),
				'description'         => __( 'Clears the paused-storage entry for a theme so WordPress retries loading it on the next request. If the theme still fatally errors when WP retries, it will be re-paused and WP falls back to the default theme. Accepts a fuzzy theme identifier (name, stylesheet, or partial); when uncertain, returns a candidates list rather than acting.', 'acrossai-abilities-manager' ),
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
							'description' => __( 'Theme identifier: stylesheet (directory slug) or theme name. Fuzzy-matched via Theme_Helpers::resolve_theme().', 'acrossai-abilities-manager' ),
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
						'stylesheet'              => array( 'type' => array( 'string', 'null' ) ),
						'theme_name'              => array( 'type' => array( 'string', 'null' ) ),
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

		if ( ! function_exists( 'wp_paused_themes' ) ) {
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
				'message'  => __( 'Theme identifier is required.', 'acrossai-abilities-manager' ),
			);
		}

		$resolved = Theme_Helpers::resolve_theme( $identifier );
		if ( null === $resolved['stylesheet'] || $resolved['certainty'] < 8.0 ) {
			return array(
				'success'    => false,
				'unpaused'   => false,
				'candidates' => $resolved['candidates'],
				'message'    => __( 'Ambiguous theme identifier — pass a more specific stylesheet or theme name.', 'acrossai-abilities-manager' ),
			);
		}

		$paused_storage = wp_paused_themes();
		$all_paused     = $paused_storage->get_all();
		$was_paused     = array_key_exists( $resolved['stylesheet'], $all_paused );

		if ( $was_paused ) {
			$paused_storage->delete( $resolved['stylesheet'] );
		}

		$remaining = $paused_storage->get_all();

		return array(
			'success'                => true,
			'unpaused'               => $was_paused,
			'stylesheet'             => $resolved['stylesheet'],
			'theme_name'             => $resolved['theme_name'],
			'was_paused'             => $was_paused,
			'remaining_paused_count' => count( $remaining ),
			'message'                => $was_paused
				? __( 'Paused entry cleared. WordPress will retry loading the theme on the next request.', 'acrossai-abilities-manager' )
				: __( 'Theme was not paused; nothing to do.', 'acrossai-abilities-manager' ),
		);
	}
}
