<?php
/**
 * Feature 067 / issue #243 — copy one lane\u2019s settings to its siblings.
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
 * Copies SPACING only, never content.
 *
 * Restricted to padding, margin and width deliberately: those are the settings that
 * make sibling lanes disagree, and copying anything broader would carry across
 * backgrounds and borders a caller did not ask to change.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Copy_Lane_Settings extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'copy-lane-settings';
	}

	protected function audit_label(): string {
		return __( 'Copy Elementor Lane Settings', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Copy the spacing settings of one lane onto its siblings in the same row, so lanes that should match actually do. Pass source_id as the lane to copy from; without it the first lane in the row is used. Copies padding, margin and width only — content and every other setting are left alone.', 'acrossai-abilities-manager' );
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
		$keys   = array( 'padding', 'margin', 'width', '_column_size' );
		$source = '';
		$row    = null;

		foreach ( (array) $model['rows'] as $candidate ) {
			if ( (int) $candidate['lane_count'] >= 2 ) {
				$row = $candidate;
				break;
			}
		}

		if ( null === $row ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'No row in scope has two or more lanes, so there are no siblings to copy between.', 'acrossai-abilities-manager' )
			);
		}

		$lanes  = (array) $row['lanes'];
		$first  = (array) ( $lanes[0] ?? array() );
		$source = (string) ( $first['id'] ?? '' );
		$from   = (array) ( $first['settings'] ?? array() );

		$payload = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $from ) ) {
				$payload[ $key ] = $from[ $key ];
			}
		}

		if ( array() === $payload ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'The source lane sets no spacing of its own, so there is nothing to copy.', 'acrossai-abilities-manager' )
			);
		}

		$siblings = array_values(
			array_filter(
				array_map( static fn( array $l ): string => (string) $l['id'], $lanes ),
				static fn( string $id ): bool => '' !== $id && $id !== $source
			)
		);

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $siblings, $payload ): array {
				if ( ! in_array( (string) ( $element['id'] ?? '' ), $siblings, true ) ) {
					return array();
				}

				$changes = array();
				foreach ( $payload as $key => $value ) {
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
			sprintf(
				/* translators: %s: source lane id */
				__( 'Copied spacing from lane %s onto its siblings.', 'acrossai-abilities-manager' ),
				$source
			),
			__( 'Sibling lanes already matched the source, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
