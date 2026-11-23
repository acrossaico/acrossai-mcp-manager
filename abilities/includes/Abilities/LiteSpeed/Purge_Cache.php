<?php
/**
 * Feature 104 — Purge Cache.
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
 * litespeed/purge-cache — Purge Cache.
 */
final class Purge_Cache extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'purge-cache';
	}

	protected function ability_label(): string {
		return __( 'Purge Cache', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Purge LiteSpeed\'s cache by target. Targets: all (everything including generated CSS/JS), lscache (the page cache only), object (the object cache), opcache (the PHP opcode cache), css-js (generated assets only), ucss (generated unique CSS only), front-page (the front page only). Not confirm-gated because nothing is lost — but on a large site the next visitor to every page pays the rebuild, so prefer the narrowest target that fixes the problem.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/get-cache-status',
		);
	}

	protected function input_properties(): array {
		return array(
			'target' => array(
				'type'        => 'string',
				'enum'        => array( 'all', 'lscache', 'object', 'opcache', 'css-js', 'ucss', 'front-page' ),
				'description' => __( 'Which cache to purge.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'target',
		);
	}

	protected function output_properties(): array {
		return array(
			'target' => array( 'type' => 'string' ),
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
		$target = isset( $input['target'] ) ? (string) $input['target'] : '';
		$purged = Purge_Repository::purge( $target );

		if ( is_wp_error( $purged ) ) {
			return $purged;
		}

		return array(
			'target'  => $target,
			'message' => sprintf(
				/* translators: %s: purge target */
				__( 'Purged: %s.', 'acrossai-abilities-manager' ),
				$target
			),
		);
	}
}
