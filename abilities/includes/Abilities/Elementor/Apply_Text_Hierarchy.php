<?php
/**
 * Feature 067 / issue #243 — normalise heading levels.
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
 * Rewrites heading TAGS so levels never skip.
 *
 * Only header_size changes. Visual size on an Elementor heading is typography, not the
 * tag, so correcting the tag does not resize anything — which is exactly why this is
 * safe to apply and why the fault is so easy to introduce in the first place.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Apply_Text_Hierarchy extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'apply-text-hierarchy';
	}

	protected function audit_label(): string {
		return __( 'Apply Elementor Text Hierarchy', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Fix heading levels in a subtree so they descend without skipping: the first heading becomes the top level present and the rest follow in order. Skipped levels are the single most common accessibility fault on builder pages, because a screen reader announces structure that is not there. Only the HTML tag changes — size, colour and every other style are untouched.', 'acrossai-abilities-manager' );
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
		// Collected in document order first: a heading's correct level depends on what
		// came before it, which a per-element editor cannot see on its own.
		$headings = array();
		\AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository::walk_tree(
			(array) $model['scoped_data'],
			static function ( array $element ) use ( &$headings ): void {
				if ( 'widget' !== ( $element['elType'] ?? '' ) || 'heading' !== ( $element['widgetType'] ?? '' ) ) {
					return;
				}
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$headings[ (string) ( $element['id'] ?? '' ) ] = (string) ( $settings['header_size'] ?? 'h2' );
			}
		);

		if ( array() === $headings ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'No heading widgets in scope, so there is no hierarchy to correct.', 'acrossai-abilities-manager' )
			);
		}

		$levels = array();
		foreach ( $headings as $id => $tag ) {
			$levels[ $id ] = 1 === preg_match( '/^h([1-6])$/', $tag, $m ) ? (int) $m[1] : 2;
		}

		$target   = array();
		$previous = 0;
		foreach ( $levels as $id => $level ) {
			if ( 0 === $previous ) {
				$next = $level;
			} elseif ( $level > $previous + 1 ) {
				// A skip: pull it up to exactly one below its parent.
				$next = $previous + 1;
			} else {
				$next = $level;
			}

			$target[ $id ] = 'h' . $next;
			$previous      = $next;
		}

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $target ): array {
				$id = (string) ( $element['id'] ?? '' );
				if ( ! isset( $target[ $id ] ) ) {
					return array();
				}

				return Design_Mutator::set( $settings, 'header_size', $target[ $id ] );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			__( 'Corrected heading levels so they descend without skipping. Only the HTML tag changed; no visual style was touched.', 'acrossai-abilities-manager' ),
			__( 'Heading levels in scope already descend without skipping, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
