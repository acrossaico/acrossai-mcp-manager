<?php
/**
 * Feature 106 — Update Term SEO.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Term_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * taxonomies/update-term-seo — Update Term SEO.
 */
final class Update_Term_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'taxonomies/update-term-seo';
	}

	protected function ability_label(): string {
		return __( 'Update Term SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Write Yoast SEO fields on one taxonomy term. Accepts seo_title, meta_description, focus_keyphrase, canonical, breadcrumb_title, noindex and is_cornerstone; omitted fields keep their values. Goes through WPSEO_Taxonomy_Meta::set_values(), the only writer that keeps Yoast\'s single-option structure intact and runs its per-key sanitisation.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-terms';
	}

	protected function is_writer(): bool {
		return true;
	}

	protected function suggested_abilities(): array {
		return array(
			'taxonomies/get-term-seo',
		);
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

			'fields' => array(
				'type'        => 'object',
				'description' => __( 'Field name => value. See taxonomies/get-term-seo for the field list.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'term_id',
			'fields',
		);
	}

	protected function output_properties(): array {
		return array(
			'term_id' => array( 'type' => 'integer' ),

			'updated' => array( 'type' => 'array' ),

			'seo' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$term = Term_Repository::term( (int) $input['term_id'], isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '' );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$fields  = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		$updated = Term_Repository::update( $term, (array) Slash_Input::slash( $fields, $input ) );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'term_id' => (int) $term->term_id,
			'updated' => $updated,
			'seo'     => Term_Repository::describe( $term ),
			'message' => sprintf(
				/* translators: 1: comma-separated fields, 2: term name */
				__( 'Updated %1$s on "%2$s".', 'acrossai-abilities-manager' ),
				implode( ', ', $updated ),
				$term->name
			),
		);
	}
}
