<?php
/**
 * Feature 104 — Purge By Cache Tag.
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
 * litespeed/purge-by-tag — Purge By Cache Tag.
 */
final class Purge_By_Tag extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'purge-by-tag';
	}

	protected function ability_label(): string {
		return __( 'Purge By Cache Tag', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Purge by raw LiteSpeed cache tag, for callers that already know the tag scheme. The escape hatch when none of the other purge abilities addresses the right set of pages.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function input_properties(): array {
		return array(
			'tags' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'LiteSpeed cache tags.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'tags',
		);
	}

	protected function output_properties(): array {
		return array(
			'purged' => array( 'type' => 'array' ),
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
		$tags   = isset( $input['tags'] ) && is_array( $input['tags'] ) ? $input['tags'] : array();
		$purged = Purge_Repository::purge_tags( $tags );

		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		return array(
			'purged'  => $purged,
			'message' => sprintf(
				/* translators: %d: number of tags */
				_n( 'Purged %d cache tag.', 'Purged %d cache tags.', count( $purged ), 'acrossai-abilities-manager' ),
				count( $purged )
			),
		);
	}
}
