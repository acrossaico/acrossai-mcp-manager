<?php
/**
 * Feature 067 / issue #243 — audit competing high-emphasis sections.
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
 * Counts sections that are BOTH backgrounded and heavy.
 *
 * Either signal alone is ordinary. Together they are how a section says "look here",
 * and several saying it at once is the thing being reported.
 */
class Audit_Section_Rivalry extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-section-rivalry';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Section Rivalry', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Catch pages where too many sections act like simultaneous high points — several backgrounded, heavily-populated sections in a row, each competing to be the one you look at. A page can support one or two of these; past that they cancel each other out.', 'acrossai-abilities-manager' );
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
			return array( 'findings' => array(), 'score' => 100 );
		}

		$weights = array_map( static fn( array $r ): int => (int) $r['widget_count'], $rows );
		$mean    = array_sum( $weights ) / max( 1, count( $weights ) );

		$rivals = array_values(
			array_filter(
				$rows,
				static fn( array $r ): bool => ! empty( $r['has_background'] ) && (int) $r['widget_count'] >= $mean
			)
		);

		$findings = array();
		if ( count( $rivals ) >= 3 ) {
			$findings[] = array(
				'type'     => 'competing_climaxes',
				'rows'     => array_map( static fn( array $r ): string => (string) $r['id'], $rivals ),
				'severity' => count( $rivals ) >= 5 ? 'medium' : 'low',
				'message'  => sprintf(
					/* translators: %d: number of sections */
					__( '%d sections are both backgrounded and above average weight, so several are competing to be the focal point.', 'acrossai-abilities-manager' ),
					count( $rivals )
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Let the quieter sections be quiet. Dropping the background on two of these gives the remaining one somewhere to stand out from.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => max( 0, 100 - max( 0, count( $rivals ) - 2 ) * 12 ),
		);
	}
}
