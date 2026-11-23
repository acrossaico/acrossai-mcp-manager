<?php
/**
 * Feature 106 — Set Cornerstone Flag.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Content_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * seo/set-cornerstone — Set Cornerstone Flag.
 */
final class Set_Cornerstone extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/set-cornerstone';
	}

	protected function ability_label(): string {
		return __( 'Set Cornerstone Flag', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Mark a post as cornerstone content, or unmark it. Cornerstone posts are analysed against stricter readability and SEO rules and are the pages Yoast expects other content to link to.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/list-cornerstone-content',
		);
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post ID.', 'acrossai-abilities-manager' ),
			),

			'cornerstone' => array(
				'type'        => 'boolean',
				'description' => __( 'True to mark, false to unmark.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_id',
			'cornerstone',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),

			'cornerstone' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'unknown_post',
				sprintf( /* translators: %d: post id */ __( 'No post with id %d.', 'acrossai-abilities-manager' ), $post_id )
			);
		}

		$on = ! empty( $input['cornerstone'] );
		Content_Repository::set_cornerstone( $post_id, $on );

		return array(
			'post_id'     => $post_id,
			'cornerstone' => $on,
			'message'     => $on
				? __( 'Marked as cornerstone content.', 'acrossai-abilities-manager' )
				: __( 'Cornerstone flag removed.', 'acrossai-abilities-manager' ),
		);
	}
}
