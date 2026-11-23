<?php
/**
 * Feature 067 / issue #243 — score structural distinctiveness.
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
 * Composes three structural signals into one score, and shows its working.
 *
 * The components are returned alongside the total precisely because a single design
 * number invites more trust than it deserves. This measures STRUCTURE — it has no view
 * on typography, colour, imagery or copy, which is most of what makes a page
 * distinctive, and the description says so rather than letting the number imply
 * otherwise.
 */
class Score_Distinctiveness extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'score-distinctiveness';
	}

	protected function audit_label(): string {
		return __( 'Score Elementor Page Distinctiveness', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Turn the structural repetition signals on a page into a single 0-100 distinctiveness score, with the components that produced it shown separately so the number can be argued with. Measures shape variety, ratio variety and furniture density — structure only. It cannot see colour, imagery or copy, and a high score is not a claim that a page looks good.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$rows = $model['rows'];
		if ( count( $rows ) < 2 ) {
			return array(
				'findings' => array(),
				'score'    => null,
				'message'  => __( 'Too few sections to score distinctiveness. A null score means not assessed.', 'acrossai-abilities-manager' ),
			);
		}

		$shapes        = array_map( static fn( array $r ): string => $r['ratio'] . ':' . $r['lane_count'], $rows );
		$shape_variety = count( array_unique( $shapes ) ) / count( $shapes );

		$ratios        = array_values( array_filter( array_map( static fn( array $r ): string => (string) $r['ratio'], $rows ) ) );
		$ratio_variety = array() === $ratios ? 1.0 : count( array_unique( $ratios ) ) / count( $ratios );

		$types     = (array) $model['widget_types'];
		$furniture = 0;
		foreach ( Design_Model::FURNITURE_WIDGETS as $widget ) {
			$furniture += (int) ( $types[ $widget ] ?? 0 );
		}
		$total   = max( 1, (int) $model['widget_count'] );
		$density = min( 1.0, $furniture / $total );

		$components = array(
			'shape_variety' => round( $shape_variety, 3 ),
			'ratio_variety' => round( $ratio_variety, 3 ),
			'furniture_density' => round( $density, 3 ),
		);

		$score = (int) round( ( $shape_variety * 45 ) + ( $ratio_variety * 35 ) + ( ( 1 - $density ) * 20 ) );

		return array(
			'findings'        => array(),
			'score'           => max( 0, min( 100, $score ) ),
			'recommendations' => $score >= 70 ? array() : array(
				array( 'suggestion' => __( 'The page repeats a small number of structural shapes. Varying one section\u2019s composition raises this more than any amount of restyling.', 'acrossai-abilities-manager' ) ),
			),
			'extras'          => array(
				'components' => $components,
				'basis'      => __( 'Structural signals only — this cannot see colour, typography, imagery or copy.', 'acrossai-abilities-manager' ),
			),
		);
	}
}
