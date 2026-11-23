<?php
/**
 * Feature 107 — Get Post Type Editor Support.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ClassicEditor
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ClassicEditor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ClassicEditor\Editor_Settings_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * editor/get-post-type-editor-support — which editors each post type allows.
 */
final class Get_Post_Type_Editor_Support extends Base_Classic_Editor_Ability {

	protected function slug(): string {
		return 'editor/get-post-type-editor-support';
	}

	protected function ability_label(): string {
		return __( 'Get Post Type Editor Support', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which editors each post type allows — the classic editor needs the post type to support "editor", and the block editor needs it to be block-editor eligible. Post type support has the last word over every other setting: a post type the block editor cannot handle will open in the classic editor whatever the site default, the user preference or the post\'s remembered choice say. Answers "why will this post type not use blocks" before anyone tries to change it.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'editor-resolution';
	}

	protected function input_properties(): array {
		return array(
			'post_type' => array(
				'type'        => 'string',
				'description' => __( 'One post type. Omit for every registered post type.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'post_types' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'count'      => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'content/list-post-types',
				'reason' => __( 'For the post types themselves — labels, capabilities, whether they are public — rather than which editors they allow.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$requested = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		if ( '' !== $requested && ! post_type_exists( $requested ) ) {
			return new WP_Error(
				'unknown_post_type',
				sprintf(
					/* translators: %s: post type name */
					__( 'No post type named "%s".', 'acrossai-abilities-manager' ),
					$requested
				)
			);
		}

		$names = '' !== $requested ? array( $requested ) : get_post_types( array(), 'names' );
		$rows  = array();

		foreach ( (array) $names as $name ) {
			$name    = (string) $name;
			$editors = Editor_Settings_Repository::editors_for_post_type( $name );

			$rows[] = array(
				'post_type'         => $name,
				'classic_available' => $editors['classic_editor'],
				'block_available'   => $editors['block_editor'],
				'neither'           => ! $editors['classic_editor'] && ! $editors['block_editor'],
			);
		}

		return array(
			'post_types' => $rows,
			'count'      => count( $rows ),
			'message'    => sprintf(
				/* translators: %d: number of post types */
				_n( '%d post type inspected.', '%d post types inspected.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
