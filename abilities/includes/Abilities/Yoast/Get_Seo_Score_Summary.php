<?php
/**
 * Feature 106 — Get SEO Score Summary.
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
 * seo/get-seo-score-summary — Get SEO Score Summary.
 */
final class Get_Seo_Score_Summary extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-seo-score-summary';
	}

	protected function ability_label(): string {
		return __( 'Get SEO Score Summary', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'How the site\'s published posts distribute across Yoast\'s SEO score bands — good, needs improvement, bad, and not analysed. The one-call answer to "how is our SEO doing", and the way to see how much of the site has never been analysed at all.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/list-low-score-content',
		);
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'bands' => array( 'type' => 'array' ),

			'total' => array( 'type' => 'integer' ),
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
		$summary = Content_Repository::score_summary();

		return array(
			'bands'   => $summary['bands'],
			'total'   => $summary['total'],
			'message' => sprintf(
				/* translators: %d: number of posts */
				__( '%d published post(s) scored.', 'acrossai-abilities-manager' ),
				$summary['total']
			),
		);
	}
}
