<?php
/**
 * Feature 067 / issue #243 — audit whether a column split earns its complexity.
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
 * Flags rows that are structurally multi-lane but effectively single-lane.
 *
 * An empty lane is not a style choice — it is a lane that still has to be maintained
 * across every breakpoint while contributing nothing.
 */
class Audit_Column_Necessity extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-column-necessity';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Column Necessity', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Flag column splits that may not be earning their complexity — a multi-lane row where every lane but one is empty, or a two-lane row holding a single element in total. These read more clearly as one lane, and they cost a breakpoint to maintain.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$findings = array();

		foreach ( $model['rows'] as $row ) {
			$lane_count = (int) $row['lane_count'];
			if ( $lane_count < 2 ) {
				continue;
			}

			$counts   = array_map( static fn( array $l ): int => (int) $l['widget_count'], $row['lanes'] );
			$occupied = count( array_filter( $counts ) );

			if ( $occupied > 1 && array_sum( $counts ) > 1 ) {
				continue;
			}

			$findings[] = array(
				'type'          => 0 === $occupied ? 'empty_row' : 'single_occupant_split',
				'row_id'        => (string) $row['id'],
				'lane_count'    => $lane_count,
				'occupied_lanes' => $occupied,
				'severity'      => 0 === $occupied ? 'medium' : 'low',
				'message'       => 0 === $occupied
					? sprintf(
						/* translators: 1: row id, 2: lane count */
						__( 'Row %1$s has %2$d lanes and no content in any of them.', 'acrossai-abilities-manager' ),
						(string) $row['id'],
						$lane_count
					)
					: sprintf(
						/* translators: 1: row id, 2: lane count */
						__( 'Row %1$s is split into %2$d lanes to hold a single element.', 'acrossai-abilities-manager' ),
						(string) $row['id'],
						$lane_count
					),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array_map(
				static fn( array $f ): array => array(
					'row_id'     => $f['row_id'],
					'suggestion' => __( 'Collapse this to a single lane. Fewer lanes is one less thing to lay out at every breakpoint.', 'acrossai-abilities-manager' ),
				),
				$findings
			),
			'score'           => max( 0, 100 - count( $findings ) * 12 ),
		);
	}
}
