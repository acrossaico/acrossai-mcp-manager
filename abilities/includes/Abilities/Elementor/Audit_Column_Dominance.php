<?php
/**
 * Feature 067 / issue #243 — audit column dominance.
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
 * The mirror of Audit_Column_Balance: that one flags width without weight, this one
 * flags weight without width.
 */
class Audit_Column_Dominance extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-column-dominance';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Column Dominance', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Flag equal column splits that may be hiding a dominant side — a 50/50 row where one lane carries three times the content of the other. Equal widths suit peer content; when one side clearly leads, an equal split makes the reader work out the hierarchy for themselves.', 'acrossai-abilities-manager' );
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
			if ( array() === $sizes || max( $sizes ) - min( $sizes ) > 10 ) {
				continue;
			}

			$counts = array_map( static fn( array $l ): int => (int) $l['widget_count'], $row['lanes'] );
			$heavy  = max( $counts );
			$light  = min( $counts );

			// Three-to-one is the point at which "peers" stops being a fair description.
			// A one-versus-two row is ordinary and is left alone.
			if ( $light < 1 || $heavy < $light * 3 ) {
				continue;
			}

			$findings[] = array(
				'type'          => 'hidden_dominance',
				'row_id'        => (string) $row['id'],
				'ratio'         => (string) $row['ratio'],
				'widget_counts' => array_values( $counts ),
				'severity'      => 'low',
				'message'       => sprintf(
					/* translators: 1: row id, 2: heavier lane count, 3: lighter lane count */
					__( 'Row %1$s splits evenly, but one lane holds %2$d elements against %3$d.', 'acrossai-abilities-manager' ),
					(string) $row['id'],
					$heavy,
					$light
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array_map(
				static fn( array $f ): array => array(
					'row_id'     => $f['row_id'],
					'suggestion' => __( 'Give the fuller lane more width so the layout states the hierarchy the content already has.', 'acrossai-abilities-manager' ),
				),
				$findings
			),
			'score'           => max( 0, 100 - count( $findings ) * 15 ),
		);
	}
}
