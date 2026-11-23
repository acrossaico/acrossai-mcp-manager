<?php
/**
 * Feature 067 / issue #243 — normalise column gap rhythm.
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
 * Applies the MAJORITY column gap to every multi-lane row.
 *
 * Same reasoning as the section-spacing fix: the page's own most common value, not an
 * imported constant, because that is the rhythm it was already mostly keeping.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Fix_Visible_Gap_Rhythm extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'fix-visible-gap-rhythm';
	}

	protected function audit_label(): string {
		return __( 'Fix Elementor Gap Rhythm', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Give every multi-lane row in scope the same column gap, taken from the value most of them already use. Rows of the same shape using different gaps read as accidental rather than designed. Single-lane rows are untouched, and if no row sets a gap there is no majority to apply.', 'acrossai-abilities-manager' );
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
		$tallies = array();
		$targets = array();

		foreach ( (array) $model['rows'] as $row ) {
			if ( (int) $row['lane_count'] < 2 ) {
				continue;
			}
			$targets[] = (string) $row['id'];

			$gap = ( (array) $row['settings'] )['gap'] ?? null;
			if ( ! is_array( $gap ) || ! isset( $gap['size'] ) ) {
				continue;
			}
			$key             = wp_json_encode( array( $gap['size'], $gap['unit'] ?? 'px' ) );
			$tallies[ $key ] = ( $tallies[ $key ] ?? 0 ) + 1;
		}

		if ( array() === $tallies ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'No multi-lane row in scope sets its own gap, so there is no majority rhythm to apply and nothing was changed.', 'acrossai-abilities-manager' )
			);
		}

		arsort( $tallies );
		$winner = json_decode( (string) array_key_first( $tallies ), true );
		$size   = $winner[0] ?? '';
		$unit   = (string) ( $winner[1] ?? 'px' );

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $targets, $size, $unit ): array {
				if ( ! in_array( (string) ( $element['id'] ?? '' ), $targets, true ) ) {
					return array();
				}

				return Design_Mutator::set( $settings, 'gap', array( 'unit' => $unit, 'size' => $size, 'sizes' => array() ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			sprintf(
				/* translators: 1: gap size, 2: unit */
				__( 'Applied the majority column gap (%1$s%2$s) to every multi-lane row in scope.', 'acrossai-abilities-manager' ),
				(string) $size,
				$unit
			),
			__( 'Every multi-lane row already used the majority gap, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
