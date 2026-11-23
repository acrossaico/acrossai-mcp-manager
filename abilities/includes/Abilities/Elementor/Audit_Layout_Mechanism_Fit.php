<?php
/**
 * Feature 067 / issue #243 — audit layout mechanism fit.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Design_Model;

defined( 'ABSPATH' ) || exit;

/**
 * Applies Elementor's own published guidance: equal peer columns belong in Grid.
 *
 * See Guidance_Catalog 'grid_vs_flexbox_equal_columns'. Also reports legacy
 * section/column rows, which cannot use Grid at all until converted.
 */
class Audit_Layout_Mechanism_Fit extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-layout-mechanism-fit';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Layout Mechanism Fit', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Recommend Container Grid over Flexbox for rows of equal, symmetric peer columns, per Elementor guidance, and flag legacy section/column rows that would be simpler as containers. Reports which rows would benefit and why, without rewriting anything.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$findings = array();
		$legacy   = array();

		foreach ( $model['rows'] as $row ) {
			if ( 'section' === (string) $row['el_type'] ) {
				$legacy[] = (string) $row['id'];
			}

			if ( (int) $row['lane_count'] < 3 ) {
				continue;
			}

			$sizes = Design_Model::lane_sizes( $row );
			if ( array() === $sizes || ( max( $sizes ) - min( $sizes ) ) > 2 ) {
				continue;
			}

			$findings[] = array(
				'type'       => 'grid_candidate',
				'row_id'     => (string) $row['id'],
				'lane_count' => (int) $row['lane_count'],
				'severity'   => 'low',
				'message'    => sprintf(
					/* translators: 1: row id, 2: lane count */
					__( 'Row %1$s holds %2$d equal peer lanes, which Elementor recommends building as a Container Grid rather than guessing Flexbox widths.', 'acrossai-abilities-manager' ),
					(string) $row['id'],
					(int) $row['lane_count']
				),
			);
		}

		if ( array() !== $legacy ) {
			$findings[] = array(
				'type'     => 'legacy_section',
				'rows'     => $legacy,
				'severity' => 'low',
				'message'  => sprintf(
					/* translators: %d: number of rows */
					__( '%d rows still use the legacy section/column structure, which cannot use Grid until converted to containers.', 'acrossai-abilities-manager' ),
					count( $legacy )
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Equal peer lanes are what Container Grid is for: one column count instead of a width per lane, and one place to change it per breakpoint.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => max( 0, 100 - count( $findings ) * 10 ),
		);
	}
}
