<?php
/**
 * Feature 067 / issue #243 — audit column balance.
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
 * Flags a lopsided row whose content weight does not match its width split.
 *
 * The check is content-relative on purpose: 70/30 is not wrong, it is wrong when the
 * 30 holds as much as the 70. A width difference alone is a design decision.
 */
class Audit_Column_Balance extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-column-balance';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Column Balance', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Flag asymmetric column rows whose content does not appear to earn the asymmetry — a row split 70/30 where both sides hold a similar amount of content. Rows whose widths are untouched are treated as an equal split rather than as evidence of imbalance. Reports the ratio and what each lane holds so you can judge it.', 'acrossai-abilities-manager' );
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
			if ( (int) $row['lane_count'] < 2 ) {
				continue;
			}

			$sizes = Design_Model::lane_sizes( $row );
			if ( array() === $sizes ) {
				continue;
			}

			$widest  = max( $sizes );
			$narrow  = min( $sizes );
			if ( $widest - $narrow < 20 ) {
				continue;
			}

			$counts = array_map( static fn( array $l ): int => (int) $l['widget_count'], $row['lanes'] );
			$wide_i = (int) array_search( $widest, $sizes, true );
			$thin_i = (int) array_search( $narrow, $sizes, true );

			// The asymmetry is unearned only when the narrow lane carries as much as the
			// wide one. A wide lane holding more is the split doing its job.
			if ( ( $counts[ $thin_i ] ?? 0 ) < ( $counts[ $wide_i ] ?? 0 ) ) {
				continue;
			}

			$findings[] = array(
				'type'         => 'unearned_asymmetry',
				'row_id'       => (string) $row['id'],
				'ratio'        => (string) $row['ratio'],
				'widget_counts' => array_values( $counts ),
				'severity'     => 'low',
				'message'      => sprintf(
					/* translators: 1: row id, 2: ratio */
					__( 'Row %1$s is split %2$s, but the narrower lane holds at least as much content as the wider one.', 'acrossai-abilities-manager' ),
					(string) $row['id'],
					(string) $row['ratio']
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array_map(
				static fn( array $f ): array => array(
					'row_id'     => $f['row_id'],
					'suggestion' => __( 'Even this row out, or move content so the wider lane carries the weight its width implies.', 'acrossai-abilities-manager' ),
				),
				$findings
			),
			'score'           => max( 0, 100 - count( $findings ) * 15 ),
		);
	}
}
