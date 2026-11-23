<?php
/**
 * Feature 106 — List Orphaned Content.
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
 * seo/list-orphaned-content — List Orphaned Content.
 */
final class List_Orphaned_Content extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-orphaned-content';
	}

	protected function ability_label(): string {
		return __( 'List Orphaned Content', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Published posts with no internal links pointing at them. Orphans are reachable only through archives and sitemaps, so search engines discount them and readers rarely find them — usually the highest-value SEO fix on an established site. Requires Yoast\'s link index; if it is empty this reports that rather than claiming everything is orphaned.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-internal-links',
			'seo/get-indexing-status',
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
			'posts' => array( 'type' => 'array' ),

			'count' => array( 'type' => 'integer' ),

			'link_index_ready' => array( 'type' => 'boolean' ),
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
		$result = Content_Repository::orphaned( isset( $input['limit'] ) ? (int) $input['limit'] : 100 );

		return array(
			'posts'            => $result['posts'],
			'count'            => count( $result['posts'] ),
			'link_index_ready' => $result['ready'],
			'message'          => $result['ready']
				? sprintf( /* translators: %d: number of orphans */ __( '%d orphaned post(s).', 'acrossai-abilities-manager' ), count( $result['posts'] ) )
				: __( 'Yoast has no link index yet, so orphan status cannot be judged. See seo/get-indexing-status.', 'acrossai-abilities-manager' ),
		);
	}
}
