<?php
/**
 * Feature 067 / issue #243 — even out the gap between consecutive sections.
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
 * Evens the CONTRIBUTED gap between consecutive sections.
 *
 * The visible gap between two sections is the sum of one's bottom padding and the
 * next's top padding, which is why it is so easy to get inconsistent — two people can
 * each set a sensible value and produce a different gap at every boundary.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Enforce_Boundary_Coherence extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'enforce-boundary-coherence';
	}

	protected function audit_label(): string {
		return __( 'Enforce Elementor Boundary Coherence', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Make section boundaries agree: where consecutive sections both set bottom and top padding, the pair is evened out so the visible gap between them is the same everywhere. Two sections each contributing a different amount produce gaps that look arbitrary and are hard to reason about when editing.', 'acrossai-abilities-manager' );
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
		$rows = array_values( (array) $model['rows'] );
		if ( count( $rows ) < 3 ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'Fewer than three sections in scope, so there is no boundary rhythm to enforce.', 'acrossai-abilities-manager' )
			);
		}

		$bottoms = array();
		foreach ( $rows as $row ) {
			$padding = ( (array) $row['settings'] )['padding'] ?? null;
			if ( is_array( $padding ) && isset( $padding['bottom'] ) && is_numeric( $padding['bottom'] ) ) {
				$bottoms[] = (string) $padding['bottom'];
			}
		}

		if ( count( $bottoms ) < 2 ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'Too few sections set their own bottom padding for a majority boundary to exist.', 'acrossai-abilities-manager' )
			);
		}

		$tallies = array_count_values( $bottoms );
		arsort( $tallies );
		$target  = (string) array_key_first( $tallies );
		$row_ids = array_map( static fn( array $r ): string => (string) $r['id'], $rows );

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $row_ids, $target ): array {
				if ( ! in_array( (string) ( $element['id'] ?? '' ), $row_ids, true ) ) {
					return array();
				}
				if ( ! isset( $settings['padding'] ) || ! is_array( $settings['padding'] ) ) {
					return array();
				}

				$padding           = $settings['padding'];
				$padding['bottom'] = $target;

				return Design_Mutator::set( $settings, 'padding', $padding );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			sprintf(
				/* translators: %s: bottom padding value */
				__( 'Set every section that defines its own bottom padding to the majority value (%s), so each boundary contributes the same gap.', 'acrossai-abilities-manager' ),
				$target
			),
			__( 'Every section boundary already contributed the same gap, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
