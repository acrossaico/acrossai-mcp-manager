<?php
/**
 * Feature 106 — Clear Term SEO.
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
 * taxonomies/clear-term-seo — Clear Term SEO.
 */
final class Clear_Term_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'taxonomies/clear-term-seo';
	}

	protected function ability_label(): string {
		return __( 'Clear Term SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Remove every Yoast SEO field from one term, returning it to the site-wide defaults. The term itself is untouched — only its SEO overrides are cleared.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-terms';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __(
			'Clearing a term\'s SEO overrides cannot be undone. Pass confirm: true to proceed.',
			'acrossai-abilities-manager'
		);
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

			'cleared' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$term = Term_Repository::term( (int) $input['term_id'], isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '' );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		Term_Repository::clear( $term );

		return array(
			'term_id' => (int) $term->term_id,
			'cleared' => true,
			'message' => sprintf(
				/* translators: %s: term name */
				__( 'Cleared the SEO overrides on "%s". It now uses the site defaults.', 'acrossai-abilities-manager' ),
				$term->name
			),
		);
	}
}
