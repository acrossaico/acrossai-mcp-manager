<?php
/**
 * Feature 067 / issue #243 — copy the majority row balance to matching rows.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Design_Mutator;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the MAJORITY width split to rows of the same lane count.
 *
 * Scoped by lane count on purpose: a three-lane row's widths say nothing about what a
 * two-lane row should be, and forcing one onto the other would be arithmetic rather
 * than design.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Copy_Row_Balance extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'copy-row-balance';
	}

	protected function audit_label(): string {
		return __( 'Copy Elementor Row Balance', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Apply the lane widths of the most common row shape to every other row with the same number of lanes, so rows meant to match actually line up. Rows with a different lane count are left alone, because their widths are answering a different question.', 'acrossai-abilities-manager' );
	}

	protected function is_destructive(): bool {
		return true;
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$by_count = array();
		foreach ( (array) $model['rows'] as $row ) {
			$count = (int) $row['lane_count'];
			if ( $count < 2 ) {
				continue;
			}
			$by_count[ $count ][] = $row;
		}

		$plan = array();
		foreach ( $by_count as $rows ) {
			if ( count( $rows ) < 2 ) {
				continue;
			}

			$tallies = array_count_values( array_map( static fn( array $r ): string => (string) $r['ratio'], $rows ) );
			arsort( $tallies );
			$winner = (string) array_key_first( $tallies );

			$template = null;
			foreach ( $rows as $row ) {
				if ( (string) $row['ratio'] === $winner ) {
					$template = $row;
					break;
				}
			}
			if ( null === $template ) {
				continue;
			}

			$widths = array_map( static fn( array $l ): array => (array) $l['settings'], (array) $template['lanes'] );

			foreach ( $rows as $row ) {
				if ( (string) $row['ratio'] === $winner ) {
					continue;
				}
				foreach ( array_values( (array) $row['lanes'] ) as $i => $lane ) {
					$source = $widths[ $i ] ?? array();
					if ( isset( $source['_column_size'] ) ) {
						$plan[ (string) $lane['id'] ]['_column_size'] = $source['_column_size'];
					}
					if ( isset( $source['width'] ) ) {
						$plan[ (string) $lane['id'] ]['width'] = $source['width'];
					}
				}
			}
		}

		if ( array() === $plan ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'Rows of each lane count already share one balance, so there was nothing to bring into line.', 'acrossai-abilities-manager' )
			);
		}

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $plan ): array {
				$id = (string) ( $element['id'] ?? '' );
				if ( ! isset( $plan[ $id ] ) ) {
					return array();
				}

				$changes = array();
				foreach ( $plan[ $id ] as $key => $value ) {
					$changes = array_merge( $changes, Design_Mutator::set( $settings, (string) $key, $value ) );
				}

				return $changes;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			__( 'Brought rows into line with the majority balance for their lane count.', 'acrossai-abilities-manager' ),
			__( 'Every row already matched the majority balance, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
