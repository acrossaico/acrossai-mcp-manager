<?php
/**
 * Feature 067 / issue #243 — clear container padding overrides.
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
 * Clears per-element padding so kit values apply again.
 *
 * REMOVES the override rather than setting zero. Writing zero would be a new override
 * with the same problem — a value the kit cannot change.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Zero_Container_Padding_Subtree extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'zero-container-padding-subtree';
	}

	protected function audit_label(): string {
		return __( 'Zero Elementor Container Padding In Subtree', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Remove padding overrides from containers and columns in a subtree so they fall back to the values the site kit defines. Use this when nested containers have accumulated padding that no longer matches anything. Only removes overrides — it does not set padding to zero, so the kit values take over. Every removal is reported with the value it removed.', 'acrossai-abilities-manager' );
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
				$type = (string) ( $element['elType'] ?? '' );
				if ( 'container' !== $type && 'column' !== $type ) {
					return array();
				}

				$changes = array();
				foreach ( array( 'padding', 'padding_tablet', 'padding_mobile' ) as $key ) {
					$changes = array_merge( $changes, Design_Mutator::clear( $settings, $key ) );
				}

				return $changes;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			__( 'Cleared container padding overrides so the site kit values apply again.', 'acrossai-abilities-manager' ),
			__( 'No container or column in scope carried a padding override, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
