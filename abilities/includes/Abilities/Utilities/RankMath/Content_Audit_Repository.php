<?php
/**
 * Feature 069 — content-level SEO audits, inbound links and FAQ block checks.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only content analysis built on WordPress queries plus Rank Math's postmeta.
 *
 * These are OUR analyses, not wrappers: Rank Math core can score a post and audit the
 * site technically, but it cannot report which specific SEO fields are missing across
 * a set of posts, nor which pages link TO a given page.
 */
final class Content_Audit_Repository {

	/**
	 * Issue keys the audit can report.
	 */
	public const ISSUES = array(
		'missing_seo_title',
		'missing_seo_description',
		'missing_focus_keyword',
		'noindex',
		'low_score',
		'missing_schema',
		'no_inbound_links',
	);

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Audit published content for missing or weak Rank Math SEO data.
	 *
	 * With only_issues=false this doubles as a bulk metadata reader, which is why no
	 * separate bulk-read ability exists.
	 *
	 * @param array<string,mixed> $args Query and threshold options.
	 * @return array<string,mixed>
	 */
	public static function audit( array $args ): array {
		$post_ids        = self::requested_post_ids( $args );
		$score_below     = max( 0, min( 100, (int) ( $args['score_below'] ?? 70 ) ) );
		$include_schema  = ! array_key_exists( 'include_schema', $args ) || (bool) $args['include_schema'];
		$include_inbound = ! empty( $args['include_inbound'] );

		// only_issues defaults true when sweeping, but false when specific ids were
		// named: "audit these five posts" answered with two rows, because the other
		// three were healthy, reads as three posts that do not exist. An explicit
		// only_issues still wins either way.
		$only_issues = array_key_exists( 'only_issues', $args )
			? (bool) $args['only_issues']
			: array() === $post_ids;

		$query_args = self::build_query_args( $args, $post_ids );
		$page       = (int) $query_args['paged'];

		$query   = new WP_Query( $query_args );
		$inbound = $include_inbound
			? self::inbound_counts( array_map( static fn( $p ): int => (int) $p->ID, $query->posts ) )
			: array();

		$counts = array_fill_keys( self::ISSUES, 0 );
		$items  = array();

		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$seo_title   = (string) get_post_meta( $post->ID, 'rank_math_title', true );
			$seo_desc    = (string) get_post_meta( $post->ID, 'rank_math_description', true );
			$keyword     = (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
			$robots      = get_post_meta( $post->ID, 'rank_math_robots', true );
			$robots      = is_array( $robots ) ? array_values( $robots ) : array();
			$score_meta  = get_post_meta( $post->ID, 'rank_math_seo_score', true );
			$score       = '' === $score_meta ? null : (int) $score_meta;
			$provenance  = Post_Meta_Repository::get_score_provenance( (int) $post->ID );
			$schema_used = $include_schema ? self::has_schema( (int) $post->ID ) : null;
			$in_count    = $include_inbound ? (int) ( $inbound[ $post->ID ] ?? 0 ) : null;

			$issues = array();
			if ( '' === $seo_title ) {
				$issues[] = 'missing_seo_title';
			}
			if ( '' === $seo_desc ) {
				$issues[] = 'missing_seo_description';
			}
			if ( '' === $keyword ) {
				$issues[] = 'missing_focus_keyword';
			}
			if ( in_array( 'noindex', $robots, true ) ) {
				$issues[] = 'noindex';
			}
			// A never-scored post is not "low scoring" — that is missing_focus_keyword
			// territory — so only an actual score below the threshold counts.
			if ( null !== $score && $score < $score_below ) {
				$issues[] = 'low_score';
			}
			if ( false === $schema_used ) {
				$issues[] = 'missing_schema';
			}
			if ( null !== $in_count && 0 === $in_count ) {
				$issues[] = 'no_inbound_links';
			}

			foreach ( $issues as $issue ) {
				++$counts[ $issue ];
			}

			if ( $only_issues && array() === $issues ) {
				continue;
			}

			$items[] = array(
				'id'               => (int) $post->ID,
				'title'            => (string) $post->post_title,
				'post_type'        => (string) $post->post_type,
				'status'           => (string) $post->post_status,
				'url'              => (string) get_permalink( $post->ID ),
				'seo_title'        => $seo_title,
				'seo_description'  => $seo_desc,
				'focus_keyword'    => $keyword,
				'robots'           => $robots,
				'seo_score'        => $score,
				// Null for a score this plugin never wrote — including every score Rank
				// Math's own analyzer set, which is the normal case on an existing site.
				'seo_score_at'     => $provenance['scored_at'],
				'seo_score_source' => $provenance['source'],
				'has_schema'       => $schema_used,
				'inbound_count'    => $in_count,
				'issues'           => $issues,
			);
		}

		return array(
			'items'  => $items,
			'total'  => (int) $query->found_posts,
			'page'   => $page,
			'pages'  => (int) $query->max_num_pages,
			'counts' => $counts,
		);
	}

	/**
	 * Normalise the requested post_ids, de-duplicated and order-preserving.
	 *
	 * Capped at the same 200 as per_page: the cap is what one response can carry, and
	 * it should not move just because the ids arrived in a different argument.
	 *
	 * @param array<string,mixed> $args Audit options.
	 * @return int[]
	 */
	private static function requested_post_ids( array $args ): array {
		if ( ! isset( $args['post_ids'] ) || ! is_array( $args['post_ids'] ) ) {
			return array();
		}

		$ids = array_filter( array_map( 'absint', $args['post_ids'] ) );

		return array_slice( array_values( array_unique( $ids ) ), 0, 200 );
	}

	/**
	 * Build the WP_Query arguments for an audit.
	 *
	 * Extracted from audit() so the id-scoped behaviour — which silently changes four
	 * other arguments — is assertable without a database.
	 *
	 * @param array<string,mixed> $args     Audit options.
	 * @param int[]               $post_ids Already-normalised ids, or empty to sweep.
	 * @return array<string,mixed>
	 */
	public static function build_query_args( array $args, array $post_ids ): array {
		$has_types = isset( $args['post_types'] ) && is_array( $args['post_types'] ) && array() !== $args['post_types'];
		$has_stati = isset( $args['post_statuses'] ) && is_array( $args['post_statuses'] ) && array() !== $args['post_statuses'];
		$scoped    = array() !== $post_ids;

		$post_types = $has_types
			? array_values( array_filter( array_map( 'sanitize_key', $args['post_types'] ) ) )
			// Naming ids means naming the posts. Defaulting to post+page/publish there
			// would drop a named CPT or draft and report it as "not found".
			: ( $scoped ? 'any' : array( 'post', 'page' ) );
		$statuses   = $has_stati
			? array_values( array_filter( array_map( 'sanitize_key', $args['post_statuses'] ) ) )
			: ( $scoped ? 'any' : array( 'publish' ) );

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query_args = array(
			'post_type'              => $post_types,
			'post_status'            => $statuses,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		);

		if ( $scoped ) {
			// An explicit id list is already the page: paginating it would make the
			// caller ask twice for something it fully enumerated.
			$query_args['post__in']       = $post_ids;
			$query_args['posts_per_page'] = count( $post_ids );
			$query_args['paged']          = 1;
			// Answer in the order asked, which is what makes a before/after comparison
			// line up row for row.
			$query_args['orderby']        = 'post__in';
			unset( $query_args['order'] );
		}

		// Authors who cannot edit others' posts only ever see their own.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}

		return $query_args;
	}

	/**
	 * Whether a post has any Rank Math schema attached.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function has_schema( int $post_id ): bool {
		if ( class_exists( '\RankMath\Schema\DB' ) ) {
			$schemas = \RankMath\Schema\DB::get_schemas( $post_id );
			if ( is_array( $schemas ) && array() !== $schemas ) {
				return true;
			}
		}
		// Fall back to the raw meta keys, so a build without the schema module still
		// reports accurately rather than claiming everything is missing.
		$meta = get_post_meta( $post_id );
		foreach ( array_keys( is_array( $meta ) ? $meta : array() ) as $key ) {
			if ( str_starts_with( (string) $key, 'rank_math_schema_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build an inbound internal-link graph.
	 *
	 * Distinct from Rank Math's link counter and from the plugin's own
	 * find-internal-links: both look at links going OUT of a post. This answers which
	 * pages link IN to a target, and also counts navigation-menu links, which no other
	 * ability considers.
	 *
	 * @param array<string,mixed> $args Options.
	 * @return array<string,mixed>
	 */
	public static function inbound_links( array $args ): array {
		$post_types = isset( $args['post_types'] ) && is_array( $args['post_types'] ) && array() !== $args['post_types']
			? array_values( array_filter( array_map( 'sanitize_key', $args['post_types'] ) ) )
			: array_values( get_post_types( array( 'public' => true ) ) );

		$limit          = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$min_count      = max( 0, (int) ( $args['min_count'] ?? 1 ) );
		$include_menus  = ! array_key_exists( 'include_menus', $args ) || (bool) $args['include_menus'];
		$include_sources = ! array_key_exists( 'include_sources', $args ) || (bool) $args['include_sources'];

		$target_id  = absint( $args['target_post_id'] ?? 0 );
		$target_url = isset( $args['target_url'] ) ? (string) $args['target_url'] : '';
		$target_ids = array();
		if ( $target_id > 0 ) {
			$target_ids[] = $target_id;
		} elseif ( '' !== $target_url ) {
			$resolved = url_to_postid( $target_url );
			if ( $resolved > 0 ) {
				$target_ids[] = $resolved;
			}
		}

		$sources = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		$graph = array();
		foreach ( (array) $sources as $source_id ) {
			$source_id = (int) $source_id;
			$content   = (string) get_post_field( 'post_content', $source_id );
			foreach ( self::extract_internal_targets( $content ) as $to_id ) {
				if ( $to_id === $source_id ) {
					continue;
				}
				$graph[ $to_id ]['content'][] = $source_id;
			}
		}

		if ( $include_menus ) {
			foreach ( wp_get_nav_menus() as $menu ) {
				foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
					if ( ! is_object( $item ) ) {
						continue;
					}
					$to_id = 0;
					if ( 'post_type' === ( $item->type ?? '' ) ) {
						$to_id = (int) ( $item->object_id ?? 0 );
					} elseif ( ! empty( $item->url ) ) {
						$to_id = (int) url_to_postid( (string) $item->url );
					}
					if ( $to_id > 0 ) {
						$graph[ $to_id ]['menu'][] = (string) $menu->name;
					}
				}
			}
		}

		$items = array();
		foreach ( $graph as $to_id => $refs ) {
			if ( array() !== $target_ids && ! in_array( (int) $to_id, $target_ids, true ) ) {
				continue;
			}
			$from_content = array_values( array_unique( $refs['content'] ?? array() ) );
			$from_menus   = array_values( array_unique( $refs['menu'] ?? array() ) );
			$total        = count( $from_content ) + count( $from_menus );
			if ( $total < $min_count ) {
				continue;
			}

			$entry = array(
				'target_id'     => (int) $to_id,
				'target_title'  => (string) get_the_title( (int) $to_id ),
				'target_url'    => (string) get_permalink( (int) $to_id ),
				'inbound_count' => $total,
				'content_count' => count( $from_content ),
				'menu_count'    => count( $from_menus ),
			);
			if ( $include_sources ) {
				$entry['sources'] = array_map(
					static fn( int $id ): array => array(
						'id'    => $id,
						'title' => (string) get_the_title( $id ),
						'url'   => (string) get_permalink( $id ),
					),
					$from_content
				);
				$entry['menus'] = $from_menus;
			}
			$items[] = $entry;
		}

		usort( $items, static fn( array $a, array $b ): int => $b['inbound_count'] <=> $a['inbound_count'] );
		$items = array_slice( $items, 0, $limit );

		// A target that was asked about but never linked to must still be reported,
		// with zero — otherwise "no result" is ambiguous between "not linked" and
		// "target does not exist".
		if ( array() !== $target_ids && array() === $items ) {
			foreach ( $target_ids as $id ) {
				$items[] = array(
					'target_id'     => $id,
					'target_title'  => (string) get_the_title( $id ),
					'target_url'    => (string) get_permalink( $id ),
					'inbound_count' => 0,
					'content_count' => 0,
					'menu_count'    => 0,
				);
			}
		}

		return array(
			'items'           => $items,
			'count'           => count( $items ),
			'scanned_sources' => count( (array) $sources ),
			'menus_included'  => $include_menus,
		);
	}

	/**
	 * Inbound counts for a specific set of post ids.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array<int,int>
	 */
	private static function inbound_counts( array $post_ids ): array {
		if ( array() === $post_ids ) {
			return array();
		}
		$graph  = self::inbound_links( array( 'limit' => 500, 'min_count' => 0, 'include_sources' => false ) );
		$counts = array_fill_keys( $post_ids, 0 );
		foreach ( $graph['items'] as $item ) {
			if ( array_key_exists( (int) $item['target_id'], $counts ) ) {
				$counts[ (int) $item['target_id'] ] = (int) $item['inbound_count'];
			}
		}
		return $counts;
	}

	/**
	 * Resolve internal <a href> targets in content to post ids.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	private static function extract_internal_targets( string $content ): array {
		if ( '' === $content || ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', $content, $matches ) ) {
			return array();
		}

		$home = untrailingslashit( (string) home_url() );
		$ids  = array();

		foreach ( $matches[1] as $href ) {
			$href = trim( (string) $href );
			if ( '' === $href || str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
				continue;
			}
			// Only same-site links count as internal.
			if ( ! str_starts_with( $href, '/' ) && ! str_starts_with( $href, $home ) ) {
				continue;
			}
			$id = (int) url_to_postid( str_starts_with( $href, '/' ) ? $home . $href : $href );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Audit Rank Math FAQ blocks for content that breaks the FAQ schema contract.
	 *
	 * FAQ answers must be plain text: a link inside an answer is stripped from the
	 * JSON-LD output while remaining visible on the page, so the rendered FAQ and the
	 * structured data silently disagree.
	 *
	 * @param array<string,mixed> $args Query options.
	 * @return array<string,mixed>
	 */
	public static function audit_faq_blocks( array $args ): array {
		$post_types = isset( $args['post_types'] ) && is_array( $args['post_types'] ) && array() !== $args['post_types']
			? array_values( array_filter( array_map( 'sanitize_key', $args['post_types'] ) ) )
			: array( 'post', 'page' );
		$per_page   = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				's'                      => 'rank-math/faq-block',
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$blocks = parse_blocks( (string) $post->post_content );
			$found  = self::inspect_faq_blocks( $blocks );
			if ( array() === $found['issues'] ) {
				continue;
			}
			$items[] = array(
				'id'          => (int) $post->ID,
				'title'       => (string) $post->post_title,
				'url'         => (string) get_permalink( $post->ID ),
				'block_count' => $found['blocks'],
				'item_count'  => $found['items'],
				'issues'      => $found['issues'],
			);
		}

		return array(
			'items' => $items,
			'count' => count( $items ),
			'total' => (int) $query->found_posts,
			'page'  => $page,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Walk a block tree looking for FAQ problems.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array{blocks:int,items:int,issues:array<int,array<string,mixed>>}
	 */
	private static function inspect_faq_blocks( array $blocks ): array {
		$found = array( 'blocks' => 0, 'items' => 0, 'issues' => array() );

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( 'rank-math/faq-block' === ( $block['blockName'] ?? '' ) ) {
				++$found['blocks'];
				$questions = $block['attrs']['questions'] ?? null;

				if ( ! is_array( $questions ) ) {
					$found['issues'][] = array(
						'type'    => 'unparseable_questions',
						'message' => __( 'The block has no readable questions attribute, so it renders nothing and emits no FAQ schema.', 'acrossai-abilities-manager' ),
					);
					continue;
				}
				if ( array() === $questions ) {
					$found['issues'][] = array(
						'type'    => 'empty_block',
						'message' => __( 'The FAQ block contains no items.', 'acrossai-abilities-manager' ),
					);
					continue;
				}

				foreach ( $questions as $index => $item ) {
					++$found['items'];
					$title   = is_array( $item ) ? (string) ( $item['title'] ?? '' ) : '';
					$content = is_array( $item ) ? (string) ( $item['content'] ?? '' ) : '';

					if ( '' === trim( wp_strip_all_tags( $title ) ) || '' === trim( wp_strip_all_tags( $content ) ) ) {
						$found['issues'][] = array(
							'type'    => 'empty_item',
							'index'   => (int) $index,
							'message' => __( 'The question or answer is empty, so the item is dropped from the FAQ schema.', 'acrossai-abilities-manager' ),
						);
					}
					if ( preg_match( '#<a\s#i', $content ) ) {
						$found['issues'][] = array(
							'type'    => 'link_in_answer',
							'index'   => (int) $index,
							'message' => __( 'The answer contains a link. Links are stripped from FAQ structured data while staying visible on the page, so the rendered FAQ and the schema will disagree.', 'acrossai-abilities-manager' ),
						);
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner            = self::inspect_faq_blocks( $block['innerBlocks'] );
				$found['blocks'] += $inner['blocks'];
				$found['items']  += $inner['items'];
				$found['issues']  = array_merge( $found['issues'], $inner['issues'] );
			}
		}

		return $found;
	}
}
