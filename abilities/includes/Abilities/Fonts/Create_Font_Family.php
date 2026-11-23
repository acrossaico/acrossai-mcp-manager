<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Fonts
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Fonts;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a font family in the WordPress Font Library.
 *
 * The core REST endpoint accepts font_family_settings as a JSON-encoded string
 * (because the create endpoint shares the multipart/form-data shape used by
 * font-face uploads). This ability accepts plain fields and serialises them
 * before dispatching the inner request.
 *
 * WordPress core does not expose dedicated wp_*_font_family functions —
 * everything goes through the REST controller, so this ability does too.
 */
class Create_Font_Family extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'fonts/create-font-family',
			'args' => array(
				'label'               => __( 'Create Font Family', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a Font Library font family (wp_font_family CPT). Requires name, slug, and fontFamily — matches the theme.json font family preset shape.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-fonts',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'       => array(
							'type'        => 'string',
							'description' => __( 'Human-readable font family name, e.g. "Inter".', 'acrossai-abilities-manager' ),
						),
						'slug'       => array(
							'type'        => 'string',
							'description' => __( 'Kebab-case unique identifier, e.g. "inter".', 'acrossai-abilities-manager' ),
						),
						'fontFamily' => array(
							'type'        => 'string',
							'description' => __( 'CSS font-family value, e.g. "Inter, sans-serif".', 'acrossai-abilities-manager' ),
						),
						'preview'    => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Optional URL to a preview image.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'name', 'slug', 'fontFamily' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'family'  => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'font-families',
						'sub_group_label' => __( 'Font Families', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
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
		$name        = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$slug        = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$font_family = (string) ( $input['fontFamily'] ?? '' );
		$preview     = sanitize_url( (string) ( $input['preview'] ?? '' ) );

		if ( '' === $name || '' === $slug || '' === $font_family ) {
			return array(
				'success' => false,
				'message' => __( 'name, slug, and fontFamily are required.', 'acrossai-abilities-manager' ),
			);
		}

		$settings = array(
			'name'       => $name,
			'slug'       => $slug,
			'fontFamily' => $font_family,
		);
		if ( '' !== $preview ) {
			$settings['preview'] = $preview;
		}

		$request = new \WP_REST_Request( 'POST', '/wp/v2/font-families' );
		$request->set_param( 'font_family_settings', wp_json_encode( $settings ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			return array(
				'success' => false,
				'message' => $error->get_error_message(),
			);
		}

		return array(
			'success' => true,
			/* translators: %s: font family name */
			'message' => sprintf( __( 'Created font family "%s".', 'acrossai-abilities-manager' ), $name ),
			'family'  => (array) $response->get_data(),
		);
	}
}
