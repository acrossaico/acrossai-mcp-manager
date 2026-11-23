<?php
/**
 * Feature 067 / issue #243 — remove redundant responsive overrides.
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
 * Removes tablet/mobile overrides that DUPLICATE the desktop value.
 *
 * These are invisible but not harmless: while the override exists, changing the
 * desktop value silently stops affecting that breakpoint, which is a confusing thing
 * to debug months later.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Normalize_Responsive_Values extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'normalize-responsive-values';
	}

	protected function audit_label(): string {
		return __( 'Normalize Elementor Responsive Values', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Remove tablet and mobile overrides that repeat the desktop value exactly. These are usually left behind by editing at one breakpoint and then changing your mind — they do nothing visually, but they pin the value so later desktop changes stop cascading down. Overrides that genuinely differ are kept.', 'acrossai-abilities-manager' );
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

				foreach ( array_keys( $settings ) as $key ) {
					$key = (string) $key;
					if ( ! str_ends_with( $key, '_tablet' ) && ! str_ends_with( $key, '_mobile' ) ) {
						continue;
					}

					$base = (string) preg_replace( '/_(tablet|mobile)$/', '', $key );
					if ( ! array_key_exists( $base, $settings ) ) {
						continue;
					}

					if ( $settings[ $base ] === $settings[ $key ] ) {
						$changes = array_merge( $changes, Design_Mutator::clear( $settings, $key ) );
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
			__( 'Removed responsive overrides that only repeated the desktop value.', 'acrossai-abilities-manager' ),
			__( 'Every responsive override in scope differs from its desktop value, so nothing was redundant and nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
