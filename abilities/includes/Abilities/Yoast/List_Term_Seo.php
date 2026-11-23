<?php
/**
 * Feature 106 — List Term SEO.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Term_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * taxonomies/list-term-seo — List Term SEO.
 */
final class List_Term_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'taxonomies/list-term-seo';
	}

	protected function ability_label(): string {
		return __( 'List Term SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'A page of terms in one taxonomy with their Yoast SEO data, so a caller can find which terms lack a description or title without querying each in turn. Use limit and offset to page.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-terms';
	}

	protected function input_properties(): array {
		return array(
			'taxonomy' => array(
				'type'        => 'string',
				'description' => __( 'Taxonomy name, e.g. category or post_tag.', 'acrossai-abilities-manager' ),
			),

			'limit' => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 200,
				'description' => __( 'Maximum terms to return.', 'acrossai-abilities-manager' ),
			),

			'offset' => array(
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => __( 'Terms to skip.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'taxonomy',
		);
	}

	protected function output_properties(): array {
		return array(
			'terms' => array( 'type' => 'array' ),

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
		$rows = Term_Repository::list_for_taxonomy(
			(string) $input['taxonomy'],
			isset( $input['limit'] ) ? (int) $input['limit'] : 50,
			isset( $input['offset'] ) ? (int) $input['offset'] : 0
		);

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return array(
			'terms'   => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: %d: number of terms */
				_n( '%d term.', '%d terms.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
