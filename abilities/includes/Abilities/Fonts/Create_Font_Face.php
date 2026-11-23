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
 * Creates a font face under an existing font family.
 *
 * This ability supports the URL-source path only (external font files referenced
 * by absolute URL, e.g. Google Fonts). The multipart/form-data file-upload path
 * exposed by the core controller is not surfaced here — it requires the caller
 * to attach actual file resources, which is outside the scope of an ability
 * dispatched over JSON.
 *
 * WordPress core does not expose dedicated wp_*_font_face functions —
 * everything goes through the REST controller, so this ability does too.
 */
class Create_Font_Face extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'fonts/create-font-face',
			'args' => array(
				'label'               => __( 'Create Font Face', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a Font Library font face (wp_font_face CPT) under an existing font family. fontFamily and src are required. src must be one or more absolute URLs — uploaded font files are not supported through this ability.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-fonts',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'font_family_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Parent font family post ID.', 'acrossai-abilities-manager' ),
						),
						'fontFamily'     => array(
							'type'        => 'string',
							'description' => __( 'CSS font-family value, must match the parent family.', 'acrossai-abilities-manager' ),
						),
						'src'            => array(
							'type'        => array( 'string', 'array' ),
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Absolute URL (or array of URLs) to the font file(s).', 'acrossai-abilities-manager' ),
						),
						'fontStyle'      => array(
							'type'    => 'string',
							'default' => 'normal',
						),
						'fontWeight'     => array(
							'type'    => array( 'string', 'integer' ),
							'default' => '400',
						),
						'fontDisplay'    => array(
							'type'    => 'string',
							'enum'    => array( 'auto', 'block', 'fallback', 'swap', 'optional' ),
							'default' => 'fallback',
						),
						'preview'        => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Optional URL to a preview image of this font face.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'font_family_id', 'fontFamily', 'src' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'face'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'font-faces',
						'sub_group_label' => __( 'Font Faces', 'acrossai-abilities-manager' ),
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
		$family_id   = (int) ( $input['font_family_id'] ?? 0 );
		$font_family = (string) ( $input['fontFamily'] ?? '' );
		$src         = $input['src'] ?? '';

		if ( $family_id <= 0 || '' === $font_family || ( '' === $src && array() === $src ) ) {
			return array(
				'success' => false,
				'message' => __( 'font_family_id, fontFamily, and src are required.', 'acrossai-abilities-manager' ),
			);
		}

		$src_list = is_array( $src ) ? array_values( array_filter( array_map( 'sanitize_url', $src ) ) ) : sanitize_url( (string) $src );
		if ( ( is_array( $src_list ) && empty( $src_list ) ) || ( is_string( $src_list ) && '' === $src_list ) ) {
			return array(
				'success' => false,
				'message' => __( 'src must contain at least one valid URL.', 'acrossai-abilities-manager' ),
			);
		}

		$settings = array(
			'fontFamily'  => $font_family,
			'src'         => $src_list,
			'fontStyle'   => sanitize_text_field( (string) ( $input['fontStyle'] ?? 'normal' ) ),
			'fontWeight'  => is_int( $input['fontWeight'] ?? null ) ? (int) $input['fontWeight'] : sanitize_text_field( (string) ( $input['fontWeight'] ?? '400' ) ),
			'fontDisplay' => sanitize_text_field( (string) ( $input['fontDisplay'] ?? 'fallback' ) ),
		);

		$preview = sanitize_url( (string) ( $input['preview'] ?? '' ) );
		if ( '' !== $preview ) {
			$settings['preview'] = $preview;
		}

		$request = new \WP_REST_Request( 'POST', '/wp/v2/font-families/' . $family_id . '/font-faces' );
		$request->set_param( 'font_face_settings', wp_json_encode( $settings ) );

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
			/* translators: %s: font family CSS value */
			'message' => sprintf( __( 'Created font face for "%s".', 'acrossai-abilities-manager' ), $font_family ),
			'face'    => (array) $response->get_data(),
		);
	}
}
