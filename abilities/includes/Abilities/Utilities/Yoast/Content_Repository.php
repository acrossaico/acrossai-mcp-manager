<?php
/**
 * Feature 106 — content analysis: cornerstone, scores, keyphrases and internal links.
 *
 * Yoast writes its per-post analysis into ordinary post meta — `_yoast_wpseo_linkdex` for the SEO
 * score, `_yoast_wpseo_focuskw`, `_yoast_wpseo_is_cornerstone` — so these read through `WP_Query`
 * and `get_post_meta()`, which is the public API for post meta and keeps the suite's no-raw-SQL rule
 * intact. Internal links come from Yoast's own `SEO_Links_Repository` via Indexable_Repository.
 *
 * Reading Yoast's meta directly is safe in a way that WRITING it would not be: these keys are plain
 * scalars with no companion reference rows, unlike the ACF fields that motivated Feature 105.
 * `set_cornerstone()` is the only writer here and it writes the single flag Yoast itself writes.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over Yoast's per-post analysis data.
 */
final class Content_Repository {

	/**
	 * Yoast's per-post meta keys.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	private const SCORE       = '_yoast_wpseo_linkdex';
	private const READABILITY = '_yoast_wpseo_content_score';
	private const KEYPHRASE   = '_yoast_wpseo_focuskw';
	private const METADESC    = '_yoast_wpseo_metadesc';
	private const CORNERSTONE = '_yoast_wpseo_is_cornerstone';

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Published post IDs, newest first.
	 *
	 * @since  0.0.34
	 * @param  int                  $limit Maximum posts.
	 * @param  array<string, mixed> $meta  Optional meta_query.
	 * @return int[]
	 */
	private static function published( int $limit, array $meta = array() ): array {
		$args = array(
			'post_type'              => 'any',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
		);

		if ( array() !== $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Summarise one post.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function row( int $post_id ): array {
		$score = get_post_meta( $post_id, self::SCORE, true );

		return array(
			'post_id'          => $post_id,
			'title'            => get_the_title( $post_id ),
			'permalink'        => (string) get_permalink( $post_id ),
			'seo_score'        => '' === $score ? null : (int) $score,
			'readability'      => (int) get_post_meta( $post_id, self::READABILITY, true ),
			'focus_keyphrase'  => (string) get_post_meta( $post_id, self::KEYPHRASE, true ),
			'has_metadesc'     => '' !== (string) get_post_meta( $post_id, self::METADESC, true ),
			'is_cornerstone'   => '1' === (string) get_post_meta( $post_id, self::CORNERSTONE, true ),
		);
	}

	/**
	 * Cornerstone posts, with their incoming link counts.
	 *
	 * @since  0.0.34
	 * @param  int $limit Maximum posts.
	 * @return array<int, array<string,mixed>>
	 */
	public static function cornerstone( int $limit ): array {
		$ids = self::published(
			$limit,
			array(
				array(
					'key'   => self::CORNERSTONE,
					'value' => '1',
				),
			)
		);

		$incoming = Indexable_Repository::incoming_counts( $ids );
		$rows     = array();

		foreach ( $ids as $id ) {
			$row                    = self::row( $id );
			$row['incoming_links']  = $incoming[ $id ] ?? 0;
			$rows[]                 = $row;
		}

		return $rows;
	}

	/**
	 * Mark or unmark a post as cornerstone.
	 *
	 * @since  0.0.34
	 * @param  int  $post_id Post ID.
	 * @param  bool $on      Desired state.
	 * @return void
	 */
	public static function set_cornerstone( int $post_id, bool $on ): void {
		if ( $on ) {
			update_post_meta( $post_id, self::CORNERSTONE, '1' );

			return;
		}

		delete_post_meta( $post_id, self::CORNERSTONE );
	}

	/**
	 * Internal links for one post.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array{outgoing: array<int,array<string,mixed>>, incoming: int}
	 */
	public static function links( int $post_id ): array {
		return Indexable_Repository::links_for_post( $post_id );
	}

	/**
	 * Published posts with no incoming internal links.
	 *
	 * `ready` distinguishes "nothing is orphaned" from "the link index is empty", which look
	 * identical from the counts alone and mean opposite things.
	 *
	 * @since  0.0.34
	 * @param  int $limit Maximum posts to examine.
	 * @return array{posts: array<int,array<string,mixed>>, ready: bool}
	 */
	public static function orphaned( int $limit ): array {
		$ids      = self::published( $limit );
		$incoming = Indexable_Repository::incoming_counts( $ids );
		$ready    = Yoast_Guard::has_indexables() && array() !== $incoming;
		$rows     = array();

		if ( ! $ready ) {
			return array(
				'posts' => array(),
				'ready' => false,
			);
		}

		foreach ( $ids as $id ) {
			if ( 0 === ( $incoming[ $id ] ?? 0 ) ) {
				$rows[] = self::row( $id );
			}
		}

		return array(
			'posts' => $rows,
			'ready' => true,
		);
	}

	/**
	 * Published posts by SEO score band, as ROWS.
	 *
	 * Bands match Yoast's own traffic lights: 71+ good, 41-70 needs improvement, 1-40 bad, and
	 * unanalysed reported separately rather than folded into "bad" — they are different problems.
	 *
	 * @since  0.0.34
	 * @return array{bands: array<int,array<string,mixed>>, total: int}
	 */
	public static function score_summary(): array {
		$ids   = self::published( 500 );
		$bands = array(
			'good'              => 0,
			'needs-improvement' => 0,
			'bad'               => 0,
			'not-analysed'      => 0,
		);

		foreach ( $ids as $id ) {
			$score = get_post_meta( $id, self::SCORE, true );

			if ( '' === $score ) {
				++$bands['not-analysed'];
				continue;
			}

			$score = (int) $score;

			if ( $score >= 71 ) {
				++$bands['good'];
			} elseif ( $score >= 41 ) {
				++$bands['needs-improvement'];
			} else {
				++$bands['bad'];
			}
		}

		$rows = array();

		foreach ( $bands as $band => $count ) {
			$rows[] = array(
				'band'  => $band,
				'count' => $count,
			);
		}

		return array(
			'bands' => $rows,
			'total' => count( $ids ),
		);
	}

	/**
	 * Published posts scoring below a threshold, worst first.
	 *
	 * @since  0.0.34
	 * @param  int $below Threshold.
	 * @param  int $limit Maximum rows.
	 * @return array<int, array<string,mixed>>
	 */
	public static function low_scoring( int $below, int $limit ): array {
		$rows = array();

		foreach ( self::published( 500 ) as $id ) {
			$score = get_post_meta( $id, self::SCORE, true );
			$value = '' === $score ? 0 : (int) $score;

			if ( $value < $below ) {
				$rows[] = self::row( $id );
			}
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => ( $a['seo_score'] ?? 0 ) <=> ( $b['seo_score'] ?? 0 )
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Focus keyphrases in use, as ROWS, with the posts using each.
	 *
	 * @since  0.0.34
	 * @param  bool $duplicates_only Only keyphrases used more than once.
	 * @return array<int, array<string,mixed>>
	 */
	public static function keyphrase_usage( bool $duplicates_only ): array {
		$byphrase = array();

		foreach ( self::published( 500 ) as $id ) {
			$phrase = trim( (string) get_post_meta( $id, self::KEYPHRASE, true ) );

			if ( '' === $phrase ) {
				continue;
			}

			$byphrase[ $phrase ][] = array(
				'post_id' => $id,
				'title'   => get_the_title( $id ),
			);
		}

		$rows = array();

		foreach ( $byphrase as $phrase => $posts ) {
			if ( $duplicates_only && count( $posts ) < 2 ) {
				continue;
			}

			$rows[] = array(
				'keyphrase' => (string) $phrase,
				'count'     => count( $posts ),
				'posts'     => $posts,
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );

		return $rows;
	}

	/**
	 * Content problems worth acting on, each naming the ability that addresses it.
	 *
	 * @since  0.0.34
	 * @param  int $limit Maximum posts to examine.
	 * @return array<int, array<string,mixed>>
	 */
	public static function issues( int $limit ): array {
		$ids          = self::published( $limit );
		$no_metadesc  = array();
		$no_keyphrase = array();
		$low          = array();

		foreach ( $ids as $id ) {
			$row = self::row( $id );

			if ( ! $row['has_metadesc'] ) {
				$no_metadesc[] = $id;
			}

			if ( '' === $row['focus_keyphrase'] ) {
				$no_keyphrase[] = $id;
			}

			if ( null === $row['seo_score'] || $row['seo_score'] < 41 ) {
				$low[] = $id;
			}
		}

		$duplicates = self::keyphrase_usage( true );
		$orphans    = self::orphaned( $limit );

		return array(
			array(
				'issue'   => 'missing_meta_description',
				'count'   => count( $no_metadesc ),
				'posts'   => array_slice( $no_metadesc, 0, 20 ),
				'ability' => 'yoast-seo/update-post-seo-data',
			),
			array(
				'issue'   => 'missing_focus_keyphrase',
				'count'   => count( $no_keyphrase ),
				'posts'   => array_slice( $no_keyphrase, 0, 20 ),
				'ability' => 'yoast-seo/update-post-seo-data',
			),
			array(
				'issue'   => 'duplicate_keyphrase',
				'count'   => count( $duplicates ),
				'posts'   => array(),
				'ability' => 'seo/get-keyphrase-usage',
			),
			array(
				'issue'   => 'low_seo_score',
				'count'   => count( $low ),
				'posts'   => array_slice( $low, 0, 20 ),
				'ability' => 'seo/list-low-score-content',
			),
			array(
				'issue'   => 'orphaned',
				'count'   => count( $orphans['posts'] ),
				'posts'   => array_slice( array_column( $orphans['posts'], 'post_id' ), 0, 20 ),
				'ability' => 'seo/list-orphaned-content',
			),
		);
	}
}
