<?php
/**
 * Feature 107 — Get Post Editor.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ClassicEditor
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ClassicEditor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ClassicEditor\Editor_Settings_Repository;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * editor/get-post-editor — which editor this post opens in, and why.
 */
final class Get_Post_Editor extends Base_Classic_Editor_Ability {

	protected function slug(): string {
		return 'editor/get-post-editor';
	}

	protected function ability_label(): string {
		return __( 'Get Post Editor', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which editor a given post will open in, and what decided it: a remembered choice from the last time it was edited, the block markup already in its content, the site default, or the post type\'s own support. Also reports when a remembered choice is being ignored — with user switching turned off there is no per-post logic at all, so a post that remembers the classic editor will still open in whatever the site default says.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'editor-resolution';
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The post, page or custom post type record.', 'acrossai-abilities-manager' ),
			),
			'user_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Resolve as this user would see it. Only consulted when users are allowed to choose.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array( 'post_id' );
	}

	protected function output_properties(): array {
		return array(
			'post' => array( 'type' => 'object' ),
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
				'slug'   => 'content/update-post-meta',
				'reason' => __( 'To pin a post to one editor, write the post meta key classic-editor-remember with the value classic-editor or block-editor — note those differ from the classic and block the settings use.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'content/delete-post-meta',
				'reason' => __( 'Clearing classic-editor-remember lets the post fall back to whether its content already contains blocks.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'content/list-posts',
				'reason' => __( 'To find every post pinned to an editor, pass meta_key=classic-editor-remember, optionally with meta_value.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'editor/get-editor-settings',
				'reason' => __( 'If a remembered choice is being ignored, the reason is in the site settings — user switching is off.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'unknown_post',
				sprintf(
					/* translators: %d: post ID */
					__( 'No post with ID %d.', 'acrossai-abilities-manager' ),
					$post_id
				)
			);
		}

		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$row     = Editor_Settings_Repository::editor_for_post( $post, $user_id );

		$message = sprintf(
			/* translators: 1: post ID, 2: classic or block, 3: what decided it */
			__( 'Post %1$d opens in the %2$s editor (decided by %3$s).', 'acrossai-abilities-manager' ),
			$row['post_id'],
			$row['editor'],
			$row['decided_by']
		);

		if ( '' !== $row['remembered'] && $row['remembered_ignored'] ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the remembered value */
				__( 'It remembers "%s", which is currently ignored because user switching is turned off.', 'acrossai-abilities-manager' ),
				$row['remembered']
			);
		}

		return array(
			'post'    => $row,
			'message' => $message,
		);
	}
}
