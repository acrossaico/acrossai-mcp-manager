<?php
/**
 * Feature 106 — Get Term SEO.
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
 * taxonomies/get-term-seo — Get Term SEO.
 */
final class Get_Term_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'taxonomies/get-term-seo';
	}

	protected function ability_label(): string {
		return __( 'Get Term SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read the Yoast SEO fields on one taxonomy term: SEO title, meta description, focus keyphrase, canonical, breadcrumb title, noindex and cornerstone. Yoast keeps term SEO in a single wpseo_taxonomy_meta option keyed by taxonomy and term, not in termmeta, so reading it any other way misses it. This is the term equivalent of Yoast\'s own post-only get-post-seo-data.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-terms';
	}

	protected function input_properties(): array {
		return array(
			'term_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Term ID.', 'acrossai-abilities-manager' ),
			),

			'taxonomy' => array(
				'type'        => 'string',
				'description' => __( 'Taxonomy name. Optional when the term ID is unambiguous.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'term_id',
		);
	}

	protected function output_properties(): array {
		return array(
			'term_id' => array( 'type' => 'integer' ),

			'taxonomy' => array( 'type' => 'string' ),

			'seo' => array( 'type' => 'array' ),
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
		$term = Term_Repository::term( (int) $input['term_id'], isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '' );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		return array(
			'term_id'  => (int) $term->term_id,
			'taxonomy' => (string) $term->taxonomy,
			'seo'      => Term_Repository::describe( $term ),
			'message'  => sprintf(
				/* translators: 1: term name, 2: taxonomy */
				__( 'SEO for "%1$s" in %2$s.', 'acrossai-abilities-manager' ),
				$term->name,
				$term->taxonomy
			),
		);
	}
}
