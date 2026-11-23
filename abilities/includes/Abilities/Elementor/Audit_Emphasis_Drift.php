<?php
/**
 * Feature 067 / issue #243 — audit emphasis drift across sections.
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
 * Measures how much section weight varies across the page.
 *
 * Uses element count as the weight proxy — crude, but it is the one signal available
 * without rendering, and it correlates with how much of the viewport a section takes.
 * Reported as a spread so the caller can see the evidence rather than trust a verdict.
 */
class Audit_Emphasis_Drift extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-emphasis-drift';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Emphasis Drift', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Check whether sections across the page all carry similar visual weight. When every section is roughly the same size and treatment, nothing reads as the main event and the reader has no route through the page. Reports the spread of section weights rather than prescribing a shape.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$rows = $model['rows'];
		if ( count( $rows ) < 3 ) {
			return array(
				'findings' => array(),
				'score'    => 100,
				'message'  => __( 'Fewer than three sections, so there is no emphasis pattern to read.', 'acrossai-abilities-manager' ),
			);
		}

		$weights = array_map( static fn( array $r ): int => (int) $r['widget_count'], $rows );
		$max     = max( $weights );
		$min     = min( $weights );
		$mean    = array_sum( $weights ) / count( $weights );

		$findings = array();

		// Every section within one element of every other: nothing leads.
		if ( $max - $min <= 1 && $mean >= 1 ) {
			$findings[] = array(
				'type'     => 'flat_emphasis',
				'weights'  => $weights,
				'severity' => 'medium',
				'message'  => sprintf(
					/* translators: %d: number of sections */
					__( 'All %d sections carry almost identical weight, so none of them reads as the main one.', 'acrossai-abilities-manager' ),
					count( $rows )
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Let one section be clearly the largest — more space, fewer competing elements — so the page has somewhere to start.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => array() === $findings ? 100 : 70,
			'extras'          => array( 'section_weights' => $weights, 'mean_weight' => round( $mean, 2 ) ),
		);
	}
}
