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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern\Pattern_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a block pattern from one storage location. Auto-detects the
 * source; returns multiple_locations when ambiguous. For source=theme,
 * prefers the child theme over the parent so callers don't accidentally
 * mutate the parent — pass theme_type=parent explicitly to delete a
 * parent-theme file.
 */
class Delete_Block_Pattern extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/delete-block-pattern',
			'args' => array(
				'label'               => __( 'Delete Block Pattern', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deletes a pattern from one storage location: db, theme /patterns, or plugin /patterns. Auto-detects the source; returns error_code=multiple_locations on ambiguity. For theme deletions, the child theme is preferred unless theme_type=parent is set explicitly.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'        => array( 'type' => 'string' ),
						'source'      => array(
							'type' => 'string',
							'enum' => array( 'db', 'theme', 'plugin' ),
						),
						'theme_type'  => array(
							'type' => 'string',
							'enum' => array( 'child', 'parent', 'theme' ),
						),
						'plugin_slug' => array( 'type' => 'string' ),
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
						'deleted'    => array( 'type' => 'object' ),
						'locations'  => array( 'type' => 'array' ),
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
						'readonly'    => false,
						'destructive' => true,
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
		$blocked = File_Mods_Guard::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return array(
				'success'    => false,
				'message'    => __( 'slug is required.', 'acrossai-abilities-manager' ),
				'error_code' => 'invalid_slug',
			);
		}

		$source      = sanitize_text_field( $input['source'] ?? '' );
		$theme_type  = sanitize_text_field( $input['theme_type'] ?? '' );
		$plugin_slug = sanitize_key( $input['plugin_slug'] ?? '' );

		$locations = Pattern_Detector::locate( $slug );

		// Default theme deletions to child when both exist.
		if ( '' === $theme_type && 'theme' === $source ) {
			$child = array_values(
				array_filter(
					$locations,
					static function ( $l ): bool {
						return ( $l['source'] ?? '' ) === 'theme' && ( $l['theme_type'] ?? '' ) === 'child';
					}
				)
			);
			if ( ! empty( $child ) ) {
				$theme_type = 'child';
			}
		}

		$selected = Pattern_Detector::select( $locations, $source, $theme_type, $plugin_slug );

		if ( is_wp_error( $selected ) ) {
			$code = $selected->get_error_code();
			$data = $selected->get_error_data();
			return array(
				'success'    => false,
				'message'    => $selected->get_error_message(),
				'error_code' => $code,
				'locations'  => $data['locations'] ?? $locations,
			);
		}

		if ( 'db' === $selected['source'] ) {
			$post = get_post( (int) $selected['post_id'] );
			if ( ! $post || ! Pattern_Db::delete( $post ) ) {
				return array(
					'success'    => false,
					'message'    => __( 'Could not delete pattern post.', 'acrossai-abilities-manager' ),
					'error_code' => 'delete_failed',
				);
			}
			return array(
				'success' => true,
				'message' => __( 'Pattern deleted from the database.', 'acrossai-abilities-manager' ),
				'deleted' => $selected,
			);
		}

		// File-based
		$path = (string) $selected['path'];
		if ( ! is_writable( $path ) ) {
			return array(
				'success'    => false,
				'message'    => sprintf(
					/* translators: %s: file path */
					__( 'Pattern file is not writable (%s).', 'acrossai-abilities-manager' ),
					$path
				),
				'error_code' => 'file_not_writable',
			);
		}
		if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'success'    => false,
				'message'    => __( 'Could not delete pattern file.', 'acrossai-abilities-manager' ),
				'error_code' => 'delete_failed',
			);
		}

		$message = ( 'plugin' === $selected['source'] && empty( $selected['plugin_active'] ) )
			? __( 'Pattern file deleted from inactive plugin.', 'acrossai-abilities-manager' )
			: __( 'Pattern file deleted.', 'acrossai-abilities-manager' );

		return array(
			'success' => true,
			'message' => $message,
			'deleted' => $selected,
		);
	}
}
