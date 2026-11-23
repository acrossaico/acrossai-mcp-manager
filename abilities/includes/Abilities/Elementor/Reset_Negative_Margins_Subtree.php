<?php
/**
 * Feature 067 / issue #243 — reset negative margins.
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
 * Removes only NEGATIVE margin values.
 *
 * Positive margins are ordinary spacing and are left alone. A negative one is nearly
 * always compensating for something else, and it is the compensation that fails first
 * at a different viewport width.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Reset_Negative_Margins_Subtree extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'reset-negative-margins-subtree';
	}

	protected function audit_label(): string {
		return __( 'Reset Elementor Negative Margins In Subtree', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Find and remove negative margin values in a subtree. Negative margins are usually a local fix for a spacing problem elsewhere, and they break unpredictably at other breakpoints because the overlap they create does not scale. Only negative values are touched; positive margins are left exactly as they are.', 'acrossai-abilities-manager' );
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
		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ): array {
				$changes = array();

				foreach ( array( 'margin', 'margin_tablet', 'margin_mobile' ) as $key ) {
					if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
						continue;
					}

					$margin   = $settings[ $key ];
					$negative = false;
					foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
						if ( isset( $margin[ $side ] ) && is_numeric( $margin[ $side ] ) && (float) $margin[ $side ] < 0 ) {
							$negative          = true;
							$margin[ $side ]   = '0';
						}
					}

					if ( $negative ) {
						$changes = array_merge( $changes, Design_Mutator::set( $settings, $key, $margin ) );
					}
				}

				return $changes;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			__( 'Reset negative margins to zero.', 'acrossai-abilities-manager' ),
			__( 'No negative margins were found in scope, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
