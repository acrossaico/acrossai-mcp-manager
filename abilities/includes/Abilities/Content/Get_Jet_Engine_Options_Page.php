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
 * Get_Jet_Engine_Options_Page ability class (absorbed).
 */
class Get_Jet_Engine_Options_Page extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/get-jet-engine-options-page',
			'args' => array(
				'label'               => __( 'Get Options Page', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a Jet Engine options page by slug, including the stored field values from wp_options.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug' => array( 'type' => 'string' ),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'page'    => array( 'type' => 'object' ),
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
		$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return array(
				'success' => false,
				'message' => __( 'slug is required.', 'acrossai-abilities-manager' ),
			);
		}

		$page = Jet_Engine_Helpers::get_page( $slug );
		if ( is_wp_error( $page ) ) {
			return array(
				'success' => false,
				'message' => $page->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'page'    => $page,
		);
	}
}
