<?php
/**
 * Feature 067 / issue #243 — audit repeated generic components.
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
 * Counts repeated FURNITURE widgets rather than all widgets.
 *
 * Ten headings on a page is a page with ten sections. Ten buttons is a page asking the
 * same thing ten times, which is the thing worth reporting — so the count is scoped to
 * Design_Model::FURNITURE_WIDGETS rather than to everything.
 */
class Audit_Generic_Component_Repetition extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-generic-component-repetition';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Component Repetition', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Report repeated landing-page furniture — the same button appearing in every section, stacks of identical icon boxes, rows of look-alike panels. Content widgets repeating is normal and is not counted; this looks only at the decorative components whose repetition is what makes a page feel templated.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$types    = (array) $model['widget_types'];
		$findings = array();

		foreach ( Design_Model::FURNITURE_WIDGETS as $widget ) {
			$count = (int) ( $types[ $widget ] ?? 0 );
			if ( $count < 4 ) {
				continue;
			}

			$per_row = $model['row_count'] > 0 ? $count / (int) $model['row_count'] : 0;

			$findings[] = array(
				'type'        => 'repeated_component',
				'widget'      => $widget,
				'count'       => $count,
				'per_row'     => round( $per_row, 2 ),
				'severity'    => $count >= 8 ? 'medium' : 'low',
				'message'     => sprintf(
					/* translators: 1: widget name, 2: count */
					__( 'The %1$s widget appears %2$d times.', 'acrossai-abilities-manager' ),
					$widget,
					$count
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array_map(
				static fn( array $f ): array => array(
					'widget'     => $f['widget'],
					'suggestion' => 'button' === $f['widget']
						? __( 'Repeating the same call to action in every section dilutes it. Keep the strongest one or two and let the rest be links.', 'acrossai-abilities-manager' )
						: __( 'Consider whether every instance is earning its place, or whether a native widget expresses the same thing once.', 'acrossai-abilities-manager' ),
				),
				$findings
			),
			'score'           => max( 0, 100 - count( $findings ) * 15 ),
			'extras'          => array( 'widget_types' => $types ),
		);
	}
}
