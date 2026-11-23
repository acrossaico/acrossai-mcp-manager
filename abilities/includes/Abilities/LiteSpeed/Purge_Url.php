<?php
/**
 * Feature 104 — Purge URLs.
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
 * litespeed/purge-url — Purge URLs.
 */
final class Purge_Url extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'purge-url';
	}

	protected function ability_label(): string {
		return __( 'Purge URLs', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Purge one or more specific URLs from the cache, leaving everything else intact. The targeted alternative to a full purge when one page is stale.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function input_properties(): array {
		return array(
			'urls' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Absolute URLs to purge.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'urls',
		);
	}

	protected function output_properties(): array {
		return array(
			'purged' => array( 'type' => 'array' ),

			'count'  => array( 'type' => 'integer' ),
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
		$urls   = isset( $input['urls'] ) && is_array( $input['urls'] ) ? $input['urls'] : array();
		$purged = Purge_Repository::purge_urls( $urls );

		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		return array(
			'purged'  => $purged,
			'count'   => count( $purged ),
			'message' => sprintf(
				/* translators: %d: number of URLs */
				_n( 'Purged %d URL.', 'Purged %d URLs.', count( $purged ), 'acrossai-abilities-manager' ),
				count( $purged )
			),
		);
	}
}
