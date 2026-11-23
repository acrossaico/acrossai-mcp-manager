<?php
/**
 * Feature 069 — audit Rank Math FAQ blocks.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Content_Audit_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #50 — rank-math/audit-faq-links.
 *
 * Catches a specific silent failure: FAQ answers must be plain text, so a link inside
 * an answer is stripped from the emitted JSON-LD while remaining visible on the page.
 * The rendered FAQ and the structured data then disagree, and nothing surfaces that —
 * Google may flag the mismatch, or simply ignore the FAQ.
 *
 * Read-only, idempotent.
 */
class Audit_Faq_Links extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'audit-faq-links';
	}

	protected function ability_label(): string {
		return __( 'Audit Rank Math FAQ Blocks', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Find Rank Math FAQ blocks whose content will not survive into structured data: links inside answers (stripped from the JSON-LD while staying visible on the page, so the two disagree), empty questions or answers (dropped from the schema), and blocks whose question attribute cannot be parsed (rendering nothing at all).', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content';
	}

	protected function rank_math_cap(): string {
		return 'onpage_general';
	}

	protected function input_properties(): array {
		return array(
			'post_types' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Post types to scan. Defaults to post and page.', 'acrossai-abilities-manager' ),
			),
			'per_page'   => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
			'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
		);
	}

	protected function output_properties(): array {
		return array(
			'items' => array( 'type' => 'array' ),
			'count' => array( 'type' => 'integer' ),
			'total' => array( 'type' => 'integer' ),
			'page'  => array( 'type' => 'integer' ),
			'pages' => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Content_Audit_Repository::audit_faq_blocks( $input );

		$result['message'] = 0 === $result['count']
			? __( 'No FAQ block problems were found.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: %d: number of posts with FAQ problems */
				_n( '%d post has FAQ block problems.', '%d posts have FAQ block problems.', $result['count'], 'acrossai-abilities-manager' ),
				$result['count']
			);

		return $result;
	}
}
