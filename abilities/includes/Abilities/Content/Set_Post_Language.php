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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Multilang_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Set_Post_Language ability class (absorbed).
 */
class Set_Post_Language extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/set-post-language',
			'args' => array(
				'label'               => __( 'Set Post Language', 'acrossai-abilities-manager' ),
				'description'         => __( 'Assign a language code to a post. Polylang uses pll_set_post_language(); WPML uses the wpml_set_element_language_details action.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'language' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'language' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'driver'   => array( 'type' => 'string' ),
						'language' => array( 'type' => 'string' ),
						'message'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'multilanguage',
						'sub_group_label' => __( 'Multilanguage', 'acrossai-abilities-manager' ),
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
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Post not found.', 'acrossai-abilities-manager' ),
			);
		}
		if ( '' === $language ) {
			return array(
				'success' => false,
				'message' => __( 'A language code is required.', 'acrossai-abilities-manager' ),
			);
		}

		$result = Multilang_Helpers::set_post_language( $post_id, $language );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success'  => true,
			'driver'   => Multilang_Helpers::detect(),
			'language' => $language,
			/* translators: 1: post ID, 2: language code */
			'message'  => sprintf( __( 'Set post #%1$d language to "%2$s".', 'acrossai-abilities-manager' ), $post_id, $language ),
		);
	}
}
