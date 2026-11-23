<?php
/**
 * Feature 067 / issue #243 — normalise section spacing rhythm.
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
 * Applies the MAJORITY vertical padding to every section.
 *
 * Derived from the page rather than from a constant: an arbitrary value would impose a
 * rhythm the page never had, while the majority value is the one it was already mostly
 * using. Horizontal padding is left alone — it usually carries layout meaning.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Normalize_Section_Spacing_Rhythm extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'normalize-section-spacing-rhythm';
	}

	protected function audit_label(): string {
		return __( 'Normalize Elementor Section Spacing Rhythm', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Give every top-level section in scope the same vertical padding, taken from the value most sections already use. This is for pages where spacing has drifted section by section; it makes the majority value the rule. Horizontal padding is untouched, and if no section sets vertical padding there is no majority to apply and nothing changes.', 'acrossai-abilities-manager' );
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
		// The target is read from the page before anything is written, so every section
		// gets the same value even as earlier sections are being changed.
		$tallies = array();
		foreach ( (array) $model['rows'] as $row ) {
			$padding = ( (array) $row['settings'] )['padding'] ?? null;
			if ( ! is_array( $padding ) || ! isset( $padding['top'], $padding['bottom'] ) ) {
				continue;
			}
			$key             = wp_json_encode( array( $padding['top'], $padding['bottom'], $padding['unit'] ?? 'px' ) );
			$tallies[ $key ] = ( $tallies[ $key ] ?? 0 ) + 1;
		}

		if ( array() === $tallies ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'No section in scope sets its own vertical padding, so there is no majority rhythm to apply and nothing was changed.', 'acrossai-abilities-manager' )
			);
		}

		arsort( $tallies );
		$winner = json_decode( (string) array_key_first( $tallies ), true );
		$top    = (string) ( $winner[0] ?? '' );
		$bottom = (string) ( $winner[1] ?? '' );
		$unit   = (string) ( $winner[2] ?? 'px' );

		$top_ids = array_map( static fn( array $r ): string => (string) $r['id'], (array) $model['rows'] );

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $top_ids, $top, $bottom, $unit ): array {
				if ( ! in_array( (string) ( $element['id'] ?? '' ), $top_ids, true ) ) {
					return array();
				}

				$padding = isset( $settings['padding'] ) && is_array( $settings['padding'] ) ? $settings['padding'] : array();
				$next    = array_merge(
					array( 'right' => $padding['right'] ?? '', 'left' => $padding['left'] ?? '', 'isLinked' => false ),
					array( 'unit' => $unit, 'top' => $top, 'bottom' => $bottom )
				);

				return Design_Mutator::set( $settings, 'padding', $next );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			sprintf(
				/* translators: 1: top padding, 2: bottom padding, 3: unit */
				__( 'Applied the page\u2019s majority section padding (%1$s/%2$s%3$s) to every section in scope.', 'acrossai-abilities-manager' ),
				$top,
				$bottom,
				$unit
			),
			__( 'Every section already used the majority padding, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
