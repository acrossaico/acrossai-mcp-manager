<?php
/**
 * Feature 106 — Get Indexable SEO.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Indexable_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-indexable — Get Indexable SEO.
 */
final class Get_Indexable extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-indexable';
	}

	protected function ability_label(): string {
		return __( 'Get Indexable SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The computed SEO output for any page that is not a single post: the home page, the blog page, a post type archive, an author archive, a term archive or the search results. These are exactly the pages Yoast\'s own post-scoped abilities cannot reach, and the ones whose titles come from templates rather than from anything editable per page.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexables';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/list-indexables',
		);
	}

	protected function input_properties(): array {
		return array(
			'kind' => array(
				'type'        => 'string',
				'enum'        => array( 'home-page', 'posts-page', 'post-type-archive', 'author', 'term', 'search' ),
				'description' => __( 'Which indexable.', 'acrossai-abilities-manager' ),
			),

			'subject' => array(
				'type'        => array( 'string', 'integer' ),
				'description' => __( 'Depends on kind: the post type name for post-type-archive, the user ID for author, the term ID for term. Ignored for home-page, posts-page and search.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'kind',
		);
	}

	protected function output_properties(): array {
		return array(
			'indexable' => array( 'type' => 'object' ),
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
		$row = Indexable_Repository::meta_for(
			(string) $input['kind'],
			$input['subject'] ?? ''
		);

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return array(
			'indexable' => $row,
			'message'   => sprintf(
				/* translators: %s: indexable kind */
				__( 'SEO for the %s indexable.', 'acrossai-abilities-manager' ),
				(string) $input['kind']
			),
		);
	}
}
