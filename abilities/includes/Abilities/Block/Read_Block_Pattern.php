<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Detector;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a single block pattern from any storage layer. Resolves source
 * automatically when there is one obvious location, or returns a
 * multiple_locations error with the list when the caller hasn't picked one.
 */
class Read_Block_Pattern extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/read-block-pattern',
			'args' => array(
				'label'               => __( 'Read Block Pattern', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reads a pattern by slug from one of: db (wp_block CPT), theme /patterns folder, or plugin /patterns folder. Omit "source" to auto-detect — if the slug exists in more than one location the call fails with error_code=multiple_locations and the list of locations.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'Bare pattern slug (the post_name for DB; the filename minus .php for files).', 'acrossai-abilities-manager' ),
						),
						'source'      => array(
							'type' => 'string',
							'enum' => array( 'db', 'theme', 'plugin' ),
						),
						'theme_type'  => array(
							'type' => 'string',
							'enum' => array( 'child', 'parent', 'theme' ),
						),
						'plugin_slug' => array(
							'type' => 'string',
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
						'locations'  => array( 'type' => 'array' ),
						'pattern'    => array( 'type' => 'object' ),
					),
					'required'   => array( 'success' ),
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'patterns',
						'sub_group_label' => __( 'Patterns', 'acrossai-abilities-manager' ),
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
		$slug        = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$source      = sanitize_text_field( $input['source'] ?? '' );
		$theme_type  = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );

		if ( '' === $slug ) {
			return array(
				'success'    => false,
				'message'    => __( 'slug is required.', 'acrossai-abilities-manager' ),
				'error_code' => 'invalid_slug',
			);
		}

		$locations = Pattern_Detector::locate( $slug );
		$selected  = Pattern_Detector::select( $locations, $source, $theme_type, $plugin_slug );

		if ( is_wp_error( $selected ) ) {
			$data = $selected->get_error_data();
			return array(
				'success'    => false,
				'message'    => $selected->get_error_message(),
				'error_code' => $selected->get_error_code(),
				'locations'  => isset( $data['locations'] ) ? $data['locations'] : $locations,
			);
		}

		if ( 'db' === $selected['source'] ) {
			$post = get_post( (int) $selected['post_id'] );
			if ( ! $post ) {
				return array(
					'success'    => false,
					'message'    => __( 'Pattern post disappeared.', 'acrossai-abilities-manager' ),
					'error_code' => 'not_found',
				);
			}
			return array(
				'success' => true,
				'pattern' => Pattern_Db::to_row( $post ),
			);
		}

		// File-based (theme or plugin)
		$contents = file_get_contents( $selected['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return array(
				'success'    => false,
				'message'    => __( 'Could not read pattern file.', 'acrossai-abilities-manager' ),
				'error_code' => 'read_failed',
			);
		}
		$parsed = Pattern_Helper::parse_file( $contents );

		$pattern = array_merge(
			$selected,
			array(
				'headers' => $parsed['headers'],
				'body'    => $parsed['body'],
			)
		);

		return array(
			'success' => true,
			'pattern' => $pattern,
		);
	}
}
