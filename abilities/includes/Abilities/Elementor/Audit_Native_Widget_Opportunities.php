<?php
/**
 * Feature 067 / issue #243 — audit native widget opportunities.
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
 * Recognises hand-built shapes that duplicate a native widget.
 *
 * The argument is not tidiness: a native widget brings keyboard behaviour, ARIA state
 * and responsive rules that a stack of containers has to reimplement and usually does
 * not. Matching is structural and intentionally conservative.
 */
class Audit_Native_Widget_Opportunities extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-native-widget-opportunities';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Native Widget Opportunities', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Spot hand-built patterns that a native Elementor widget already does — stacks of repeated icon-and-text pairs that are an Icon List, repeated heading-and-text blocks that are an Accordion or Nested Tabs, a heading-text-button trio that is a Call to Action. Native widgets carry their own responsive behaviour and accessibility, which hand-built stacks do not.', 'acrossai-abilities-manager' );
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
			$types = array_map( 'strval', (array) $row['widget_types'] );
			if ( array() === $types ) {
				continue;
			}

			$counts = array_count_values( $types );

			// Counted PER LANE, not per row. Three icon-boxes spread one-per-lane across a
			// three-column row is the standard feature grid and is not an Icon List —
			// Icon List is a vertical list inside one lane. Counting per row flagged that
			// correct pattern, which is the kind of advice that teaches people to ignore
			// the tool.
			$lanes = (array) $row['lanes'];
			if ( array() === $lanes ) {
				$lanes = array( array( 'id' => (string) $row['id'], 'widget_types' => $types ) );
			}

			foreach ( $lanes as $lane ) {
				$lane_types = array_count_values( array_map( 'strval', (array) ( $lane['widget_types'] ?? array() ) ) );
				$icons      = (int) ( $lane_types['icon'] ?? 0 ) + (int) ( $lane_types['icon-box'] ?? 0 );

				if ( $icons < 3 ) {
					continue;
				}

				$findings[] = array(
					'type'     => 'icon_list_candidate',
					'row_id'   => (string) $row['id'],
					'lane_id'  => (string) ( $lane['id'] ?? '' ),
					'widget'   => 'icon-list',
					'severity' => 'low',
					'message'  => sprintf(
						/* translators: 1: lane id, 2: icon count */
						__( 'Lane %1$s stacks %2$d icon elements in a single column, which the Icon List widget does in one — with its own spacing and list semantics.', 'acrossai-abilities-manager' ),
						(string) ( $lane['id'] ?? '' ),
						$icons
					),
				);
			}

			$headings = (int) ( $counts['heading'] ?? 0 );
			$texts    = (int) ( $counts['text-editor'] ?? 0 );
			if ( $headings >= 3 && $texts >= 3 && (int) $row['lane_count'] <= 1 ) {
				$findings[] = array(
					'type'     => 'accordion_candidate',
					'row_id'   => (string) $row['id'],
					'widget'   => 'accordion',
					'severity' => 'low',
					'message'  => sprintf(
						/* translators: %s: row id */
						__( 'Row %s repeats heading-and-text pairs in a single lane, the shape an Accordion or Nested Tabs widget provides with keyboard and ARIA behaviour built in.', 'acrossai-abilities-manager' ),
						(string) $row['id']
					),
				);
			}
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array_map(
				static fn( array $f ): array => array(
					'row_id'     => $f['row_id'],
					'widget'     => $f['widget'],
					'suggestion' => sprintf(
						/* translators: %s: widget name */
						__( 'Rebuild this with the native %s widget, which brings responsive and accessibility behaviour a hand-built stack has to reimplement.', 'acrossai-abilities-manager' ),
						$f['widget']
					),
				),
				$findings
			),
			'score'           => max( 0, 100 - count( $findings ) * 10 ),
		);
	}
}
