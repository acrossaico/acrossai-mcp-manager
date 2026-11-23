<?php
/**
 * Feature 106 — List Cornerstone Content.
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
 * seo/list-cornerstone-content — List Cornerstone Content.
 */
final class List_Cornerstone_Content extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-cornerstone-content';
	}

	protected function ability_label(): string {
		return __( 'List Cornerstone Content', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every post marked as cornerstone content — the pages you consider most important, which Yoast holds to a stricter analysis and expects to be well linked internally. Returns each with its SEO score and incoming link count, so an under-linked cornerstone is visible immediately.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/set-cornerstone',
		);
	}

	protected function input_properties(): array {
		return array(
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
		$rows = Content_Repository::cornerstone( isset( $input['limit'] ) ? (int) $input['limit'] : 50 );

		return array(
			'posts'   => $rows,
			'count'   => count( $rows ),
			'message' => array() === $rows
				? __( 'No cornerstone content is marked.', 'acrossai-abilities-manager' )
				: sprintf( /* translators: %d: number of posts */ __( '%d cornerstone post(s).', 'acrossai-abilities-manager' ), count( $rows ) ),
		);
	}
}
