<?php
/**
 * Feature 067 — Elementor template query helpers.
 *
 * WP_Query wrappers around the elementor_library CPT with tax filters by
 * template type + pattern-matching keyword scoring for
 * find-template-for-pattern.
 *
 * All methods are pure static.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Template query + pattern scoring.
 */
final class Template_Query {

	/** The Elementor library CPT. */
	public const CPT = 'elementor_library';

	/** The Elementor library type taxonomy. */
	public const TYPE_TAX = 'elementor_library_type';

	/**
	 * Query saved Elementor templates.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string      $template_type Optional — one of 'page', 'section', 'popup', 'header', 'footer', 'single', 'archive', 'kit'.
	 *     @type string      $status        Post status filter (default 'publish').
	 *     @type int         $limit         Max results (default 50).
	 *     @type int         $offset        Offset (default 0).
	 * }
	 * @return WP_Post[]
	 */
	public static function query( array $args = array() ): array {
		$defaults = array(
			'template_type' => '',
			'status'        => 'publish',
			'limit'         => 50,
			'offset'        => 0,
		);
		$args = array_merge( $defaults, $args );

		$query_args = array(
			'post_type'      => self::CPT,
			'post_status'    => $args['status'],
			'posts_per_page' => (int) $args['limit'],
			'offset'         => (int) $args['offset'],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'suppress_filters' => false,
		);

		if ( '' !== $args['template_type'] ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => self::TYPE_TAX,
					'field'    => 'slug',
					'terms'    => (string) $args['template_type'],
				),
			);
		}

		$query = new WP_Query( $query_args );
		return $query->posts;
	}

	/**
	 * Normalise a WP_Post row into a template summary array.
	 *
	 * @param WP_Post $post
	 * @param bool    $include_data Include _elementor_data.
	 * @return array<string, mixed>
	 */
	public static function to_summary( WP_Post $post, bool $include_data = false ): array {
		$terms = get_the_terms( $post->ID, self::TYPE_TAX );
		$type  = ( is_array( $terms ) && ! empty( $terms ) ) ? (string) $terms[0]->slug : '';

		$summary = array(
			'id'            => (int) $post->ID,
			'title'         => (string) $post->post_title,
			'status'        => (string) $post->post_status,
			'template_type' => $type,
			'sub_type'      => (string) ( get_post_meta( $post->ID, '_elementor_template_sub_type', true ) ?: '' ),
			'created'       => (string) $post->post_date_gmt,
			'modified'      => (string) $post->post_modified_gmt,
			'conditions'    => get_post_meta( $post->ID, '_elementor_conditions', true ) ?: array(),
		);
		if ( $include_data ) {
			$raw                  = Document_Repository::get_raw_data( (int) $post->ID );
			$summary['raw_data']  = $raw;
			$summary['data']      = Document_Repository::decode_data( $raw );
		}
		return $summary;
	}

	/**
	 * Score a template's match against a set of pattern keywords.
	 *
	 * Scoring:
	 *   +5 per exact-word match in title (case-insensitive)
	 *   +2 per partial-substring match in title
	 *   +2 per widget-type match in the parsed content (indicates layout keyword like 'hero', 'cta')
	 *   +1 per keyword mention in template_type ('header', 'footer', 'popup', etc.)
	 *
	 * @param WP_Post   $post     Template post.
	 * @param string[]  $keywords Normalised (lower-case) keywords to match.
	 * @return int Score.
	 */
	public static function score_pattern_match( WP_Post $post, array $keywords ): int {
		if ( empty( $keywords ) ) {
			return 0;
		}
		$score = 0;
		$title = strtolower( (string) $post->post_title );

		foreach ( $keywords as $keyword ) {
			if ( '' === $keyword ) {
				continue;
			}
			$pattern = '/\b' . preg_quote( $keyword, '/' ) . '\b/i';
			if ( 1 === preg_match( $pattern, $title ) ) {
				$score += 5;
			} elseif ( false !== strpos( $title, $keyword ) ) {
				$score += 2;
			}
		}

		$terms = get_the_terms( $post->ID, self::TYPE_TAX );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_slug = strtolower( (string) $term->slug );
				foreach ( $keywords as $keyword ) {
					if ( '' !== $keyword && false !== strpos( $term_slug, $keyword ) ) {
						$score += 1;
					}
				}
			}
		}

		// Inspect the parsed content for widget-type mentions matching keywords.
		$raw = Document_Repository::get_raw_data( (int) $post->ID );
		if ( '' !== $raw ) {
			$data = Document_Repository::decode_data( $raw );
			$widget_types = array();
			Document_Repository::walk_tree(
				$data,
				static function ( array $element ) use ( &$widget_types ): void {
					if ( isset( $element['widgetType'] ) && '' !== $element['widgetType'] ) {
						$widget_types[ strtolower( (string) $element['widgetType'] ) ] = true;
					}
				}
			);
			foreach ( array_keys( $widget_types ) as $widget_type ) {
				foreach ( $keywords as $keyword ) {
					if ( '' !== $keyword && false !== strpos( $widget_type, $keyword ) ) {
						$score += 2;
					}
				}
			}
		}

		return $score;
	}

	/**
	 * Rank a set of templates against pattern keywords and return the top N.
	 *
	 * @param WP_Post[] $posts   Candidate templates.
	 * @param string    $pattern_keywords Space-separated keyword string.
	 * @param int       $limit   Max results (default 5).
	 * @return array<int, array<string, mixed>> Ordered array of { id, title, template_type, score }.
	 */
	public static function rank_by_pattern( array $posts, string $pattern_keywords, int $limit = 5 ): array {
		$keywords = array_values(
			array_filter(
				array_map( 'strtolower', preg_split( '/\s+/', trim( $pattern_keywords ) ) ?: array() ),
				static fn( $kw ) => '' !== $kw
			)
		);
		if ( empty( $keywords ) ) {
			return array();
		}
		$scored = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$score = self::score_pattern_match( $post, $keywords );
			if ( $score > 0 ) {
				$scored[] = array(
					'id'            => (int) $post->ID,
					'title'         => (string) $post->post_title,
					'template_type' => self::to_summary( $post )['template_type'],
					'score'         => $score,
				);
			}
		}
		usort( $scored, static fn( $a, $b ) => (int) $b['score'] <=> (int) $a['score'] );
		return array_slice( $scored, 0, $limit );
	}
}
