<?php
/**
 * Feature 067 / issue #243 — audit gutter rhythm across matching rows.
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
 * Groups rows by ratio and reports gap values that disagree within a group.
 *
 * Different ratios legitimately use different gutters, so the comparison is only ever
 * made between rows of the SAME shape, where a difference has no reason to exist.
 */
class Audit_Column_Alignment_Rhythm extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-column-alignment-rhythm';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Column Alignment Rhythm', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Report when rows sharing the same column ratio use different gutter or padding values. Two 50/50 rows set to different gaps do not look intentional — they look like two people built them. Reports the inconsistent values so they can be brought into line.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$groups = array();
		foreach ( $model['rows'] as $row ) {
			if ( (int) $row['lane_count'] < 2 ) {
				continue;
			}
			$settings = (array) $row['settings'];
			$gap      = $settings['gap'] ?? ( $settings['column_gap'] ?? null );
			$gap      = is_array( $gap ) ? ( $gap['size'] ?? null ) : $gap;

			$groups[ (string) $row['ratio'] ][] = array(
				'row_id' => (string) $row['id'],
				'gap'    => null === $gap ? 'default' : (string) $gap,
			);
		}

		$findings = array();
		foreach ( $groups as $ratio => $members ) {
			if ( count( $members ) < 2 ) {
				continue;
			}
			$values = array_unique( array_map( static fn( array $m ): string => $m['gap'], $members ) );
			if ( count( $values ) < 2 ) {
				continue;
			}

			$findings[] = array(
				'type'     => 'inconsistent_gutter',
				'ratio'    => (string) $ratio,
				'rows'     => $members,
				'values'   => array_values( $values ),
				'severity' => 'low',
				'message'  => sprintf(
					/* translators: 1: ratio, 2: comma-separated gap values */
					__( 'Rows split %1$s use different gaps (%2$s), so matching rows do not line up.', 'acrossai-abilities-manager' ),
					(string) $ratio,
					implode( ', ', $values )
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array_map(
				static fn( array $f ): array => array(
					'ratio'      => $f['ratio'],
					'suggestion' => __( 'Pick one gap for this ratio and apply it to every row that uses it. elementor/copy-lane-settings can copy one row onto the others.', 'acrossai-abilities-manager' ),
				),
				$findings
			),
			'score'           => max( 0, 100 - count( $findings ) * 15 ),
		);
	}
}
