<?php
/**
 * Feature 067 / issue #243 — audit composition pacing.
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
 * Reports the longest run of consecutive same-shaped sections.
 *
 * Pacing is about sequence, not totals, so this is the one audit that cares about the
 * ORDER of rows. Two 50/50 rows at opposite ends of a page read differently from two
 * in a row, and only the latter is a run.
 */
class Audit_Composition_Rhythm extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-composition-rhythm';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Composition Rhythm', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Inspect the pacing of the page: how long it runs without variation in section shape. Reports the longest run of consecutive sections built the same way. A restrained page is not treated as a fault — only a page that never changes gear across a long stretch.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$rows = $model['rows'];
		if ( count( $rows ) < 4 ) {
			return array(
				'findings' => array(),
				'score'    => 100,
				'message'  => __( 'Too few sections for pacing to be meaningful.', 'acrossai-abilities-manager' ),
			);
		}

		$best_run  = 1;
		$best_shape = '';
		$run       = 1;

		for ( $i = 1, $n = count( $rows ); $i < $n; $i++ ) {
			$same = (string) $rows[ $i ]['ratio'] === (string) $rows[ $i - 1 ]['ratio']
				&& (int) $rows[ $i ]['lane_count'] === (int) $rows[ $i - 1 ]['lane_count'];

			$run = $same ? $run + 1 : 1;

			if ( $run > $best_run ) {
				$best_run   = $run;
				$best_shape = (string) $rows[ $i ]['ratio'];
			}
		}

		$findings = array();
		if ( $best_run >= 4 ) {
			$findings[] = array(
				'type'     => 'unbroken_run',
				'length'   => $best_run,
				'shape'    => $best_shape,
				'severity' => $best_run >= 6 ? 'medium' : 'low',
				'message'  => sprintf(
					/* translators: 1: run length, 2: shape */
					__( '%1$d consecutive sections use the same %2$s shape without a change of pace.', 'acrossai-abilities-manager' ),
					$best_run,
					'' === $best_shape ? __( 'single-lane', 'acrossai-abilities-manager' ) : $best_shape
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Interrupt the run — a full-width section, a different split, or simply more space — so the page has a beat somewhere in the middle.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => max( 0, 100 - max( 0, $best_run - 3 ) * 15 ),
			'extras'          => array( 'longest_run' => $best_run ),
		);
	}
}
