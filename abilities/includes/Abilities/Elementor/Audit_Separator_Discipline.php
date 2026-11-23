<?php
/**
 * Feature 067 / issue #243 — audit separator and spacer discipline.
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
 * Compares separator count against section count.
 *
 * One divider per section is the point at which they stop marking exceptions and start
 * being the rhythm — at which they no longer separate anything in particular.
 */
class Audit_Separator_Discipline extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-separator-discipline';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Separator Discipline', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Warn when dividers and spacers start doing the job that section structure should be doing. A few separators group related content; a page with one between every block is using them to substitute for hierarchy, and they flatten it instead.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$types      = (array) $model['widget_types'];
		$separators = (int) ( $types['divider'] ?? 0 ) + (int) ( $types['spacer'] ?? 0 );
		$rows       = max( 1, (int) $model['row_count'] );

		$findings = array();
		if ( $separators >= 4 && $separators >= $rows ) {
			$findings[] = array(
				'type'       => 'separator_overuse',
				'separators' => $separators,
				'sections'   => $rows,
				'severity'   => 'low',
				'message'    => sprintf(
					/* translators: 1: separator count, 2: section count */
					__( '%1$d dividers or spacers across %2$d sections — roughly one per section, which is structure doing the work of separators.', 'acrossai-abilities-manager' ),
					$separators,
					$rows
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Use section spacing to separate sections and keep dividers for grouping inside one. Spacers in particular are hard to keep consistent across breakpoints.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => max( 0, 100 - max( 0, $separators - $rows ) * 10 - ( array() === $findings ? 0 : 10 ) ),
		);
	}
}
