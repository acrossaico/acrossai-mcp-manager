<?php
/**
 * Feature 067 / issue #243 — audit repeated column ratios.
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
 * Counts how many rows share a ratio, and reports the dominant one.
 *
 * Deliberately NOT a rule that asymmetry is better. A page of equal thirds may be a
 * grid done well. What is reportable is one shape crowding out every other, so the
 * score tracks the dominant ratio's share rather than the presence of repetition.
 */
class Audit_Column_Patterns extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-column-patterns';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Column Patterns', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Report how often the page reuses the same column ratio — four 50/50 rows in a row, or a page built entirely from equal thirds. Repetition is reported as a proportion, not condemned: a consistent grid is a legitimate choice and this does not assume asymmetry is better. The score falls only as one ratio comes to dominate the whole page.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$rows = $model['rows'];
		$multi = array_values( array_filter( $rows, static fn( array $r ): bool => (int) $r['lane_count'] > 1 ) );

		if ( count( $multi ) < 2 ) {
			return array(
				'findings' => array(),
				'score'    => 100,
				'message'  => __( 'Fewer than two multi-lane rows, so there is no ratio pattern to report.', 'acrossai-abilities-manager' ),
			);
		}

		$counts = array_count_values( array_map( static fn( array $r ): string => (string) $r['ratio'], $multi ) );
		arsort( $counts );
		$dominant = (string) array_key_first( $counts );
		$share    = $counts[ $dominant ] / count( $multi );

		$findings = array();
		if ( $share >= 0.6 && $counts[ $dominant ] >= 3 ) {
			$findings[] = array(
				'type'     => 'dominant_column_ratio',
				'ratio'    => $dominant,
				'rows'     => $counts[ $dominant ],
				'of'       => count( $multi ),
				'severity' => $share >= 0.85 ? 'medium' : 'low',
				'message'  => sprintf(
					/* translators: 1: ratio, 2: matching rows, 3: total multi-lane rows */
					__( '%2$d of %3$d multi-lane rows use the same %1$s split.', 'acrossai-abilities-manager' ),
					$dominant,
					$counts[ $dominant ],
					count( $multi )
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Vary the split on at least one row if the content justifies it — a different ratio is how a reader tells one section from the next. Keep it if the rows are genuinely peers.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => (int) round( 100 - max( 0, $share - 0.5 ) * 120 ),
			'extras'          => array( 'ratio_counts' => $counts ),
		);
	}
}
