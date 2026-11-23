<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Jet_Engine_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Jet_Engine_Options_Page_Field ability class (absorbed).
 */
class Update_Jet_Engine_Options_Page_Field extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/update-jet-engine-options-page-field',
			'args' => array(
				'label'               => __( 'Update Options Page Field', 'acrossai-abilities-manager' ),
				'description'         => __( 'Write a single field value into a Jet Engine options page. The field value is stored inside the page\'s wp_options row.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'  => array( 'type' => 'string' ),
						'field' => array( 'type' => 'string' ),
						'value' => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
					),
					'required'             => array( 'slug', 'field' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'slug'    => array( 'type' => 'string' ),
						'field'   => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'options-pages',
						'sub_group_label' => __( 'Options Pages', 'acrossai-abilities-manager' ),
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
		$slug  = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		$field = sanitize_key( (string) ( $input['field'] ?? '' ) );
		if ( '' === $slug || '' === $field ) {
			return array(
				'success' => false,
				'message' => __( 'slug and field are required.', 'acrossai-abilities-manager' ),
			);
		}

		$result = Jet_Engine_Helpers::update_field( $slug, $field, $input['value'] ?? '' );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'slug'    => $slug,
			'field'   => $field,
			/* translators: 1: field, 2: slug */
			'message' => sprintf( __( 'Updated field "%1$s" on options page "%2$s".', 'acrossai-abilities-manager' ), $field, $slug ),
		);
	}
}
