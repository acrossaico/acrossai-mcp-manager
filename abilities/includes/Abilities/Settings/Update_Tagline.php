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
 * Update_Tagline ability class (absorbed).
 */
class Update_Tagline extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'settings/update-tagline',
			'args' => array(
				'label'               => __( 'Update Tagline', 'acrossai-abilities-manager' ),
				'description'         => __( 'Updates the site tagline (the "blogdescription" option). Empty values are accepted to clear the tagline.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-settings',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'tagline' => array(
							'type'        => 'string',
							'description' => __( 'New tagline. Empty string clears it.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'tagline' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'          => array( 'type' => 'boolean' ),
						'message'          => array( 'type' => 'string' ),
						'tagline'          => array( 'type' => 'string' ),
						'previous_tagline' => array( 'type' => 'string' ),
						'updated'          => array( 'type' => 'boolean' ),
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
		if ( ! array_key_exists( 'tagline', $input ) ) {
			return array(
				'success' => false,
				'message' => __( 'A "tagline" value is required (pass an empty string to clear it).', 'acrossai-abilities-manager' ),
			);
		}

		$new      = sanitize_text_field( (string) $input['tagline'] );
		$previous = (string) get_option( 'blogdescription', '' );
		$updated  = update_option( 'blogdescription', $new );

		return array(
			'success'          => true,
			'message'          => '' === $new
				? __( 'Tagline cleared.', 'acrossai-abilities-manager' )
				/* translators: %s: new tagline */
				: sprintf( __( 'Tagline updated to "%s".', 'acrossai-abilities-manager' ), $new ),
			'tagline'          => wp_specialchars_decode( (string) get_option( 'blogdescription', '' ), ENT_QUOTES ),
			'previous_tagline' => wp_specialchars_decode( $previous, ENT_QUOTES ),
			'updated'          => (bool) $updated,
		);
	}
}
