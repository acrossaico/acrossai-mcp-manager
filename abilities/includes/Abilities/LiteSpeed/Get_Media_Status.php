<?php
/**
 * Feature 104 — Get Media Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-media-status — Get Media Status.
 */
final class Get_Media_Status extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-media-status';
	}

	protected function ability_label(): string {
		return __( 'Get Media Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Whether lazy loading is active for images and iframes, what placeholder is in use, and how many exclusions are configured. The diagnostic when images do not appear — which is usually lazy loading plus a theme that moves images with JavaScript.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-media';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'status'           => array( 'type' => 'array' ),

			'exclusion_counts' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$rows   = Settings_Repository::describe_area( 'media-lazyload' );
		$rows   = array_merge( $rows, Settings_Repository::describe_area( 'media-placeholder' ) );
		$counts = array();

		foreach ( Settings_Repository::describe_area( 'media-exclusions' ) as $row ) {
			$counts[] = array(
				'key'   => $row['key'],
				'count' => is_array( $row['value'] ) ? count( $row['value'] ) : 0,
			);
		}

		return array(
			'status'           => $rows,
			'exclusion_counts' => $counts,
			'message'          => Settings_Repository::value( 'media-lazy' )
				? __( 'Image lazy loading is on.', 'acrossai-abilities-manager' )
				: __( 'Image lazy loading is off.', 'acrossai-abilities-manager' ),
		);
	}
}
