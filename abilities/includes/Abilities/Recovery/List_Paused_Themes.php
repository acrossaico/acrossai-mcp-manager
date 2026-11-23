<?php
/**
 * Feature 059 — List paused themes ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.17
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerates every theme WordPress has paused after a fatal error, together
 * with the captured error details (type / file / line / message).
 */
class List_Paused_Themes extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'recovery/list-paused-themes',
			'args' => array(
				'label'               => __( 'List Paused Themes', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns every theme WordPress has paused after a fatal error, with the captured error details (type, file, line, message). Returns an empty array when no themes are paused.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-recovery',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'count'   => array( 'type' => 'integer' ),
						'paused'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'stylesheet' => array( 'type' => 'string' ),
									'theme_name' => array( 'type' => 'string' ),
									'error'      => array(
										'type'       => 'object',
										'properties' => array(
											'type'    => array( 'type' => 'integer' ),
											'file'    => array( 'type' => 'string' ),
											'line'    => array( 'type' => 'integer' ),
											'message' => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					),
					'required'             => array( 'success', 'count', 'paused' ),
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
		if ( ! function_exists( 'wp_paused_themes' ) ) {
			return array( 'success' => true, 'count' => 0, 'paused' => array() );
		}

		$paused = wp_paused_themes()->get_all();
		$out    = array();

		foreach ( $paused as $stylesheet => $error ) {
			$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme( $stylesheet ) : null;
			$name  = ( $theme && $theme->exists() ) ? (string) $theme->get( 'Name' ) : (string) $stylesheet;

			$out[] = array(
				'stylesheet' => (string) $stylesheet,
				'theme_name' => $name,
				'error'      => array(
					'type'    => isset( $error['type'] ) ? (int) $error['type'] : 0,
					'file'    => isset( $error['file'] ) ? (string) $error['file'] : '',
					'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
					'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
				),
			);
		}

		return array(
			'success' => true,
			'count'   => count( $out ),
			'paused'  => $out,
		);
	}
}
