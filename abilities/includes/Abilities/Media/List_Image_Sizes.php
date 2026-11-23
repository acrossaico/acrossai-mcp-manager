<?php
/**
 * Site introspection read — every registered image size (Feature 063).
 *
 * Enumerates every intermediate image size known to WordPress core and
 * enriches each entry with its declared width/height/crop by mirroring
 * the resolution algorithm the WP Media Settings admin page uses.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List_Image_Sizes ability class.
 */
class List_Image_Sizes extends Ability_Definition {

	/**
	 * The four WordPress-core default image sizes whose dimensions live in
	 * the options table (name_size_w, name_size_h, name_crop).
	 *
	 * @var array<int,string>
	 */
	private const CORE_SIZES = array( 'thumbnail', 'medium', 'medium_large', 'large' );

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/list-image-sizes',
			'args' => array(
				'label'               => __( 'List Image Sizes', 'acrossai-abilities-manager' ),
				'description'         => __( 'Enumerate every registered image size (WordPress core defaults plus theme/plugin-registered sizes) with declared width, height, and crop mode.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
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
						'sizes'   => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'introspection',
						'sub_group_label' => __( 'Introspection', 'acrossai-abilities-manager' ),
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
		$names      = get_intermediate_image_sizes();
		$additional = function_exists( 'wp_get_additional_image_sizes' )
			? wp_get_additional_image_sizes()
			: array();

		if ( ! is_array( $names ) ) {
			$names = array();
		}
		if ( ! is_array( $additional ) ) {
			$additional = array();
		}

		$sizes = array();
		foreach ( $names as $name ) {
			$name = (string) $name;

			if ( isset( $additional[ $name ] ) && is_array( $additional[ $name ] ) ) {
				$sizes[] = array(
					'name'   => $name,
					'width'  => (int) ( $additional[ $name ]['width'] ?? 0 ),
					'height' => (int) ( $additional[ $name ]['height'] ?? 0 ),
					'crop'   => (bool) ( $additional[ $name ]['crop'] ?? false ),
				);
				continue;
			}

			if ( in_array( $name, self::CORE_SIZES, true ) ) {
				$sizes[] = array(
					'name'   => $name,
					'width'  => (int) get_option( $name . '_size_w' ),
					'height' => (int) get_option( $name . '_size_h' ),
					'crop'   => (bool) get_option( $name . '_crop' ),
				);
				continue;
			}

			$sizes[] = array(
				'name'   => $name,
				'width'  => 0,
				'height' => 0,
				'crop'   => false,
			);
		}

		return array(
			'success' => true,
			'sizes'   => $sizes,
			'message' => __( 'Image sizes fetched.', 'acrossai-abilities-manager' ),
		);
	}
}
