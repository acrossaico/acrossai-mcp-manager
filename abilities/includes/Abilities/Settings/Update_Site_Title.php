<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Settings
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Settings;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Site_Title ability class (absorbed).
 */
class Update_Site_Title extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'settings/update-site-title',
			'args' => array(
				'label'               => __( 'Update Site Title', 'acrossai-abilities-manager' ),
				'description'         => __( 'Updates the site title (the "blogname" option). Whitespace is trimmed; the value cannot be empty.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-settings',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title' => array(
							'type'        => 'string',
							'description' => __( 'New site title.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'title'          => array( 'type' => 'string' ),
						'previous_title' => array( 'type' => 'string' ),
						'updated'        => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'site-identity',
						'sub_group_label' => __( 'Site Identity', 'acrossai-abilities-manager' ),
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
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$new = trim( (string) ( $input['title'] ?? '' ) );
		if ( '' === $new ) {
			return array(
				'success' => false,
				'message' => __( 'Site title cannot be empty.', 'acrossai-abilities-manager' ),
			);
		}

		$previous = (string) get_option( 'blogname', '' );
		$updated  = update_option( 'blogname', sanitize_text_field( $new ) );

		return array(
			'success'        => true,
			/* translators: %s: new site title */
			'message'        => sprintf( __( 'Site title updated to "%s".', 'acrossai-abilities-manager' ), $new ),
			'title'          => wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES ),
			'previous_title' => wp_specialchars_decode( $previous, ENT_QUOTES ),
			'updated'        => (bool) $updated,
		);
	}
}
