<?php
/**
 * Feature 106 — Get Internal Links For A Post.
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
 * seo/get-internal-links — Get Internal Links For A Post.
 */
final class Get_Internal_Links extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-internal-links';
	}

	protected function ability_label(): string {
		return __( 'Get Internal Links For A Post', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The internal links out of one post and the number pointing back at it, from Yoast\'s own link index. Incoming links are how Yoast judges whether a page is reachable and important; a page with none is invisible to that model however good its content.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/list-orphaned-content',
		);
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_id',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),

			'outgoing' => array( 'type' => 'array' ),

			'incoming' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
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

		$links = Content_Repository::links( $post_id );

		return array(
			'post_id'  => $post_id,
			'outgoing' => $links['outgoing'],
			'incoming' => $links['incoming'],
			'message'  => sprintf(
				/* translators: 1: outgoing count, 2: incoming count */
				__( '%1$d outgoing, %2$d incoming.', 'acrossai-abilities-manager' ),
				count( $links['outgoing'] ),
				$links['incoming']
			),
		);
	}
}
