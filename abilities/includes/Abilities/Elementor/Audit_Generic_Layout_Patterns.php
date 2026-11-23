<?php
/**
 * Feature 067 / issue #243 — audit generic landing-page layout patterns.
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
 * Reports the page's reliance on stock composition shapes.
 *
 * None of these patterns is a fault in isolation, so nothing is flagged for using one.
 * What is reported is a page built almost entirely from them, because that is the
 * thing a reader experiences as having seen this page before.
 */
class Audit_Generic_Layout_Patterns extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-generic-layout-patterns';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Generic Layout Patterns', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Report how much of the page is built from the stock landing-page shapes: the split hero, the repeated 50/50 row, the equal-width grid. Each is a reasonable pattern on its own — this reports how many of them the page leans on at once, which is what makes one page indistinguishable from the next.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$rows  = $model['rows'];
		$multi = array_values( array_filter( $rows, static fn( array $r ): bool => (int) $r['lane_count'] > 1 ) );
		$total = count( $rows );

		if ( $total < 2 ) {
			return array( 'findings' => array(), 'score' => 100 );
		}

		$findings = array();

		$fifty = array_values( array_filter( $multi, static fn( array $r ): bool => '50/50' === (string) $r['ratio'] ) );
		if ( count( $fifty ) >= 3 ) {
			$findings[] = array(
				'type'     => 'repeated_fifty_fifty',
				'rows'     => array_map( static fn( array $r ): string => (string) $r['id'], $fifty ),
				'severity' => count( $fifty ) >= 4 ? 'medium' : 'low',
				'message'  => sprintf(
					/* translators: %d: number of rows */
					__( '%d rows are split 50/50, which is the most recognisable stock landing-page shape there is.', 'acrossai-abilities-manager' ),
					count( $fifty )
				),
			);
		}

		$equal = array_values(
			array_filter(
				$multi,
				static function ( array $r ): bool {
					$sizes = Design_Model::lane_sizes( $r );
					return array() !== $sizes && ( max( $sizes ) - min( $sizes ) ) <= 2;
				}
			)
		);
		if ( count( $multi ) >= 3 && count( $equal ) === count( $multi ) ) {
			$findings[] = array(
				'type'     => 'uniform_equal_grid',
				'rows'     => array_map( static fn( array $r ): string => (string) $r['id'], $equal ),
				'severity' => 'low',
				'message'  => __( 'Every multi-lane row on the page splits evenly, so nothing in the layout distinguishes one section from another.', 'acrossai-abilities-manager' ),
			);
		}

		// The split hero: the first row is two lanes, one text and one image.
		$first = $rows[0] ?? array();
		if ( 2 === (int) ( $first['lane_count'] ?? 0 ) ) {
			$types = array_map( 'strval', (array) ( $first['widget_types'] ?? array() ) );
			$has_media = array() !== array_intersect( $types, array( 'image', 'video', 'image-carousel' ) );
			$has_text  = array() !== array_intersect( $types, array( 'heading', 'text-editor' ) );
			if ( $has_media && $has_text ) {
				$findings[] = array(
					'type'     => 'split_hero',
					'row_id'   => (string) ( $first['id'] ?? '' ),
					'severity' => 'low',
					'message'  => __( 'The page opens with a two-lane text-and-image hero, the default shape for this kind of page.', 'acrossai-abilities-manager' ),
				);
			}
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Break one of these shapes deliberately — a full-bleed row, an offset split, a section that is not a grid — so the page has at least one moment that is its own.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => max( 0, 100 - count( $findings ) * 18 ),
			'extras'          => array( 'row_count' => $total, 'multi_lane_rows' => count( $multi ) ),
		);
	}
}
