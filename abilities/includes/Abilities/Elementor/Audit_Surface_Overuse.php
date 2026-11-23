<?php
/**
 * Feature 067 / issue #243 — audit repeated surface treatments.
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
 * Reports the PROPORTION of sections carrying a background.
 *
 * Explicitly does not treat a plain page as a problem — the score falls only as the
 * backgrounded share rises, so a restrained page scores 100 rather than being told it
 * lacks visual interest.
 */
class Audit_Surface_Overuse extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'audit-surface-overuse';
	}

	protected function audit_label(): string {
		return __( 'Audit Elementor Surface Overuse', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Report repeated surface treatments — how many sections carry a background colour or image. A page where nearly every section is a distinct panel loses the contrast that made panels useful. Simplicity is never treated as a fault: a page with few backgrounds scores well.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$rows  = $model['rows'];
		$total = count( $rows );
		if ( $total < 3 ) {
			return array( 'findings' => array(), 'score' => 100 );
		}

		$surfaced = array_values( array_filter( $rows, static fn( array $r ): bool => ! empty( $r['has_background'] ) ) );
		$share    = count( $surfaced ) / $total;

		$findings = array();
		if ( $share >= 0.75 ) {
			$findings[] = array(
				'type'     => 'surface_overuse',
				'rows'     => array_map( static fn( array $r ): string => (string) $r['id'], $surfaced ),
				'share'    => round( $share, 2 ),
				'severity' => 'low',
				'message'  => sprintf(
					/* translators: 1: backgrounded sections, 2: total sections */
					__( '%1$d of %2$d sections carry a background, so the treatment no longer separates anything.', 'acrossai-abilities-manager' ),
					count( $surfaced ),
					$total
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Return some sections to the page background. A panel only reads as a panel when something next to it is not one.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => (int) round( 100 - max( 0, $share - 0.6 ) * 100 ),
			'extras'          => array( 'backgrounded' => count( $surfaced ), 'sections' => $total ),
		);
	}
}
