<?php
/**
 * Feature 104 — Purge Posts.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Purge_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/purge-post — Purge Posts.
 */
final class Purge_Post extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'purge-post';
	}

	protected function ability_label(): string {
		return __( 'Purge Posts', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Purge the cached copy of one or more posts by ID. The usual call after an edit made outside WordPress, which does not trigger LiteSpeed\'s automatic purge. Post IDs that do not exist are reported back rather than silently skipped.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function input_properties(): array {
		return array(
			'post_ids' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Post IDs to purge.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_ids',
		);
	}

	protected function output_properties(): array {
		return array(
			'purged'  => array( 'type' => 'array' ),

			'missing' => array( 'type' => 'array' ),
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
		$ids    = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? $input['post_ids'] : array();
		$result = Purge_Repository::purge_posts( $ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'purged'  => $result['purged'],
			'missing' => $result['missing'],
			'message' => sprintf(
				/* translators: 1: number purged, 2: number missing */
				__( 'Purged %1$d post(s); %2$d id(s) did not exist.', 'acrossai-abilities-manager' ),
				count( $result['purged'] ),
				count( $result['missing'] )
			),
		);
	}
}
