<?php
/**
 * Feature 104 — Purge Taxonomy Archives.
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
 * litespeed/purge-taxonomy — Purge Taxonomy Archives.
 */
final class Purge_Taxonomy extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'purge-taxonomy';
	}

	protected function ability_label(): string {
		return __( 'Purge Taxonomy Archives', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Purge the cached archive pages for categories or tags. Accepts slugs or numeric term IDs — IDs are resolved to slugs, because LiteSpeed\'s own purge takes a slug and ignores anything it does not recognise. Terms that do not exist are reported back.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-purge';
	}

	protected function input_properties(): array {
		return array(
			'taxonomy' => array(
				'type'        => 'string',
				'enum'        => array( 'category', 'post_tag' ),
				'description' => __( 'Which taxonomy.', 'acrossai-abilities-manager' ),
			),

			'terms'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Term slugs or numeric IDs.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'taxonomy',
			'terms',
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
		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
		$terms    = isset( $input['terms'] ) && is_array( $input['terms'] ) ? $input['terms'] : array();
		$result   = Purge_Repository::purge_taxonomy( $taxonomy, $terms );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'purged'  => $result['purged'],
			'missing' => $result['missing'],
			'message' => sprintf(
				/* translators: 1: number purged, 2: number missing */
				__( 'Purged %1$d archive(s); %2$d term(s) did not exist.', 'acrossai-abilities-manager' ),
				count( $result['purged'] ),
				count( $result['missing'] )
			),
		);
	}
}
