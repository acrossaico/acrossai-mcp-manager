<?php
/**
 * Feature 106 — Get Content SEO Issues.
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
 * seo/get-content-seo-issues — Get Content SEO Issues.
 */
final class Get_Content_Seo_Issues extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-content-seo-issues';
	}

	protected function ability_label(): string {
		return __( 'Get Content SEO Issues', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'A single pass over published content collecting the problems worth acting on: missing meta descriptions, missing focus keyphrases, duplicated keyphrases, low scores and orphaned pages. Each issue names the ability that addresses it, so the report is a work queue rather than a verdict.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/list-low-score-content',
			'seo/list-orphaned-content',
		);
	}

	protected function input_properties(): array {
		return array(
			'limit' => array(
				'type'        => 'integer',
				'default'     => 100,
				'minimum'     => 1,
				'maximum'     => 500,
				'description' => __( 'Maximum posts to examine.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'issues' => array( 'type' => 'array' ),

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
		$issues = Content_Repository::issues( isset( $input['limit'] ) ? (int) $input['limit'] : 100 );
		$total  = 0;

		foreach ( $issues as $issue ) {
			$total += (int) $issue['count'];
		}

		return array(
			'issues'  => $issues,
			'total'   => $total,
			'message' => 0 === $total
				? __( 'No content issues found.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of issues */
					__( '%d issue(s) across the content examined. Each row names the ability that fixes it.', 'acrossai-abilities-manager' ),
					$total
				),
		);
	}
}
