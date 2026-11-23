<?php
/**
 * Feature 106 — term and taxonomy SEO, the gap Yoast's post-only abilities leave.
 *
 * Yoast stores every term's SEO data inside one option, `wpseo_taxonomy_meta`, keyed by taxonomy then
 * term id — not in `termmeta`. `WPSEO_Taxonomy_Meta::set_values()` is the only writer that keeps that
 * structure intact and runs Yoast's per-key sanitisation; writing the option directly would be the
 * term-shaped version of the post-meta corruption that motivates this whole family of suites.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast;

use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over Yoast's taxonomy meta.
 */
final class Term_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The SEO fields a term carries, as friendly name => Yoast meta key.
	 *
	 * Yoast prefixes every key `wpseo_`; the friendly names match what the post abilities use, so a
	 * caller who has learned `seo_title` on a post does not have to relearn `wpseo_title` on a term.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array(
			'seo_title'        => 'wpseo_title',
			'meta_description' => 'wpseo_desc',
			'focus_keyphrase'  => 'wpseo_focuskw',
			'canonical'        => 'wpseo_canonical',
			'breadcrumb_title' => 'wpseo_bctitle',
			'noindex'          => 'wpseo_noindex',
			'is_cornerstone'   => 'wpseo_is_cornerstone',
		);
	}

	/**
	 * Resolve and validate a term.
	 *
	 * @since  0.0.34
	 * @param  int    $term_id  Term ID.
	 * @param  string $taxonomy Taxonomy name. Resolved from the term when empty.
	 * @return WP_Term|WP_Error
	 */
	public static function term( int $term_id, string $taxonomy = '' ) {
		$term = '' !== $taxonomy ? get_term( $term_id, $taxonomy ) : get_term( $term_id );

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error(
				'unknown_term',
				sprintf(
					/* translators: %d: term id */
					__( 'No term with id %d.', 'acrossai-abilities-manager' ),
					$term_id
				)
			);
		}

		return $term;
	}

	/**
	 * Describe one term's SEO data as ROWS.
	 *
	 * @since  0.0.34
	 * @param  WP_Term $term Term.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe( WP_Term $term ): array {
		$meta = \WPSEO_Taxonomy_Meta::get_term_meta( $term->term_id, $term->taxonomy );
		$rows = array();

		foreach ( self::fields() as $name => $key ) {
			$rows[] = array(
				'field' => $name,
				'key'   => $key,
				'value' => is_array( $meta ) && isset( $meta[ $key ] ) ? $meta[ $key ] : '',
			);
		}

		return $rows;
	}

	/**
	 * Write SEO fields on a term.
	 *
	 * @since  0.0.34
	 * @param  WP_Term             $term  Term.
	 * @param  array<string,mixed> $patch Friendly field name => value.
	 * @return array<int,string>|WP_Error The fields actually changed.
	 */
	public static function update( WP_Term $term, array $patch ) {
		$fields = self::fields();

		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one field to change.', 'acrossai-abilities-manager' ) );
		}

		$values  = array();
		$changed = array();

		foreach ( $patch as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( $fields[ $name ] ) ) {
				return new WP_Error(
					'unknown_field',
					sprintf(
						/* translators: 1: field name, 2: comma-separated known fields */
						__( '"%1$s" is not a term SEO field. Known fields: %2$s.', 'acrossai-abilities-manager' ),
						$name,
						implode( ', ', array_keys( $fields ) )
					)
				);
			}

			$values[ $fields[ $name ] ] = is_scalar( $value ) ? (string) $value : '';
			$changed[]                  = $name;
		}

		\WPSEO_Taxonomy_Meta::set_values( $term->term_id, $term->taxonomy, $values );

		return $changed;
	}

	/**
	 * Clear every SEO field on a term.
	 *
	 * @since  0.0.34
	 * @param  WP_Term $term Term.
	 * @return void
	 */
	public static function clear( WP_Term $term ): void {
		$values = array();

		foreach ( self::fields() as $key ) {
			$values[ $key ] = '';
		}

		\WPSEO_Taxonomy_Meta::set_values( $term->term_id, $term->taxonomy, $values );
	}

	/**
	 * A page of terms in a taxonomy with their SEO data, as ROWS.
	 *
	 * @since  0.0.34
	 * @param  string $taxonomy Taxonomy name.
	 * @param  int    $limit    Maximum rows.
	 * @param  int    $offset   Rows to skip.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function list_for_taxonomy( string $taxonomy, int $limit = 50, int $offset = 0 ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'unknown_taxonomy',
				sprintf(
					/* translators: %s: taxonomy name */
					__( 'No taxonomy named "%s".', 'acrossai-abilities-manager' ),
					$taxonomy
				)
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $limit,
				'offset'     => $offset,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$rows = array();

		foreach ( (array) $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$rows[] = array(
				'term_id'  => (int) $term->term_id,
				'name'     => (string) $term->name,
				'slug'     => (string) $term->slug,
				'taxonomy' => (string) $term->taxonomy,
				'seo'      => self::describe( $term ),
			);
		}

		return $rows;
	}

	/**
	 * The primary term for a post in a taxonomy.
	 *
	 * @since  0.0.34
	 * @param  int    $post_id  Post ID.
	 * @param  string $taxonomy Taxonomy name.
	 * @return int 0 when none is set.
	 */
	public static function primary_term( int $post_id, string $taxonomy ): int {
		$primary = new \WPSEO_Primary_Term( $taxonomy, $post_id );

		return (int) $primary->get_primary_term();
	}

	/**
	 * Set the primary term for a post in a taxonomy.
	 *
	 * @since  0.0.34
	 * @param  int    $post_id  Post ID.
	 * @param  string $taxonomy Taxonomy name.
	 * @param  int    $term_id  Term ID.
	 * @return void
	 */
	public static function set_primary_term( int $post_id, string $taxonomy, int $term_id ): void {
		$primary = new \WPSEO_Primary_Term( $taxonomy, $post_id );
		$primary->set_primary_term( $term_id );
	}
}
