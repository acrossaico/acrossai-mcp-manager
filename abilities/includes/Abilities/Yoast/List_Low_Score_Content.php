<?php
/**
 * Feature 106 — List Low Scoring Content.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Content_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/list-low-score-content — List Low Scoring Content.
 */
final class List_Low_Score_Content extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-low-score-content';
	}

	protected function ability_label(): string {
		return __( 'List Low Scoring Content', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Published posts whose Yoast SEO score falls below a threshold, worst first — the work queue for improving a site. Posts that have never been analysed score zero and appear here, which is usually correct: unanalysed content is not passing.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-seo-score-summary',
		);
	}

	protected function input_properties(): array {
		return array(
			'below' => array(
				'type'        => 'integer',
				'default'     => 41,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Score threshold, 0-100. Yoast treats 41-70 as needs-improvement and 0-40 as bad.', 'acrossai-abilities-manager' ),
			),

			'limit' => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 200,
				'description' => __( 'Maximum posts.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'posts' => array( 'type' => 'array' ),

			'count' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$rows = Content_Repository::low_scoring(
			isset( $input['below'] ) ? (int) $input['below'] : 41,
			isset( $input['limit'] ) ? (int) $input['limit'] : 50
		);

		return array(
			'posts'   => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: %d: number of posts */
				__( '%d post(s) below the threshold.', 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
