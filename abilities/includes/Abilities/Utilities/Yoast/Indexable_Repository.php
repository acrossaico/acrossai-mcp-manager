<?php
/**
 * Feature 106 — indexables: the SEO of everything that is not a single post.
 *
 * Yoast's meta surface (`YoastSEO()->meta`) resolves the computed SEO output for the home page, a
 * post-type archive, an author archive, a term or a search result — the pages that have no post to
 * hang meta on and that Yoast's own five abilities therefore cannot reach.
 *
 * **Degrades rather than disappears when the indexables table is empty.** Yoast stops building
 * indexables outside production, so on a staging site the table has no rows. That is a reason for
 * these abilities to report "nothing indexed yet" — not a reason for them to be unregistered, which
 * is what Yoast does to its own. See Yoast_Guard for the full reasoning.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over Yoast's indexables and meta surface.
 */
final class Indexable_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The indexable kinds this suite addresses, as kind => what it means.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return array(
			'home-page'         => __( 'The site front page.', 'acrossai-abilities-manager' ),
			'posts-page'        => __( 'The blog posts page, when a static front page is set.', 'acrossai-abilities-manager' ),
			'post-type-archive' => __( 'A post type archive. Pass subject as the post type name.', 'acrossai-abilities-manager' ),
			'author'            => __( 'An author archive. Pass subject as the user ID.', 'acrossai-abilities-manager' ),
			'term'              => __( 'A taxonomy term. Pass subject as the term ID.', 'acrossai-abilities-manager' ),
			'search'            => __( 'The search results page.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Resolve the computed SEO for one indexable kind.
	 *
	 * @since  0.0.34
	 * @param  string     $kind    One of kinds().
	 * @param  string|int $subject Post type, user ID or term ID, depending on the kind.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function meta_for( string $kind, $subject = '' ) {
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			return new WP_Error(
				'unknown_indexable_kind',
				sprintf(
					/* translators: 1: requested kind, 2: comma-separated known kinds */
					__( '"%1$s" is not an indexable kind. Known kinds: %2$s.', 'acrossai-abilities-manager' ),
					$kind,
					implode( ', ', array_keys( self::kinds() ) )
				)
			);
		}

		$meta = self::surface();

		if ( null === $meta ) {
			return new WP_Error( 'yoast_missing', __( 'Yoast SEO is not available.', 'acrossai-abilities-manager' ) );
		}

		switch ( $kind ) {
			case 'home-page':
				$resolved = $meta->for_home_page();
				break;

			case 'posts-page':
				$resolved = $meta->for_posts_page();
				break;

			case 'post-type-archive':
				$resolved = $meta->for_post_type_archive( (string) $subject );
				break;

			case 'author':
				$resolved = $meta->for_author( (int) $subject );
				break;

			case 'term':
				$resolved = $meta->for_term( (int) $subject );
				break;

			default:
				$resolved = $meta->for_search_result();
				break;
		}

		if ( ! $resolved ) {
			return new WP_Error(
				'indexable_not_found',
				sprintf(
					/* translators: 1: kind, 2: subject */
					__( 'Yoast has no indexable for %1$s "%2$s". On a non-production site the indexables table is often empty — check seo/get-indexing-status.', 'acrossai-abilities-manager' ),
					$kind,
					(string) $subject
				)
			);
		}

		return self::row( $kind, (string) $subject, $resolved );
	}

	/**
	 * Shape a meta result into the row every indexable ability returns.
	 *
	 * @since  0.0.34
	 * @param  string $kind     Indexable kind.
	 * @param  string $subject  Subject identifier.
	 * @param  object $resolved Yoast meta object.
	 * @return array<string,mixed>
	 */
	private static function row( string $kind, string $subject, $resolved ): array {
		$get = static function ( $object, string $property ) {
			return isset( $object->{$property} ) ? $object->{$property} : null;
		};

		return array(
			'kind'             => $kind,
			'subject'          => $subject,
			'title'            => (string) ( $get( $resolved, 'title' ) ?? '' ),
			'meta_description' => (string) ( $get( $resolved, 'description' ) ?? '' ),
			'canonical'        => (string) ( $get( $resolved, 'canonical' ) ?? '' ),
			'robots'           => $get( $resolved, 'robots' ),
			'open_graph_title' => (string) ( $get( $resolved, 'open_graph_title' ) ?? '' ),
			'twitter_title'    => (string) ( $get( $resolved, 'twitter_title' ) ?? '' ),
		);
	}

	/**
	 * How many indexables exist, by object type, as ROWS.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string,mixed>>
	 */
	public static function counts(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yoast_indexable';

		if ( ! Yoast_Guard::has_indexables() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_type, COUNT(*) AS total FROM `{$table}` GROUP BY object_type", ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'object_type' => (string) ( $row['object_type'] ?? '' ),
				'count'       => (int) ( $row['total'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Internal links out of, and into, one post.
	 *
	 * Through `SEO_Links_Repository` rather than the `yoast_seo_links` table, so the suite keeps its
	 * no-raw-SQL rule and Yoast owns its own storage shape.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array{outgoing: array<int,array<string,mixed>>, incoming: int}
	 */
	public static function links_for_post( int $post_id ): array {
		$repo = self::links_repository();

		if ( null === $repo ) {
			return array(
				'outgoing' => array(),
				'incoming' => 0,
			);
		}

		$outgoing = array();

		foreach ( (array) $repo->find_all_by_post_id( $post_id ) as $link ) {
			$outgoing[] = array(
				'target_url'      => isset( $link->url ) ? (string) $link->url : '',
				'type'            => isset( $link->type ) ? (string) $link->type : '',
				'target_post_id'  => isset( $link->target_post_id ) ? (int) $link->target_post_id : 0,
			);
		}

		$counts   = self::incoming_counts( array( $post_id ) );
		$incoming = $counts[ $post_id ] ?? 0;

		return array(
			'outgoing' => $outgoing,
			'incoming' => $incoming,
		);
	}

	/**
	 * Incoming link counts for a set of posts, as post id => count.
	 *
	 * @since  0.0.34
	 * @param  int[] $post_ids Post IDs.
	 * @return array<int,int>
	 */
	public static function incoming_counts( array $post_ids ): array {
		$repo = self::links_repository();

		if ( null === $repo || array() === $post_ids ) {
			return array();
		}

		$out = array();

		foreach ( (array) $repo->get_incoming_link_counts_for_post_ids( $post_ids ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// Yoast ALIASES the column: `->select( 'target_post_id', 'post_id' )`, so a row reads
			// { incoming, post_id } and there is no target_post_id key at all. Reading the obvious
			// name silently yields zero incoming links for every post — caught only by pointing this
			// at a site with real links, because an empty table returns nothing either way.
			$id = (int) ( $row['post_id'] ?? $row['target_post_id'] ?? 0 );

			if ( $id > 0 ) {
				$out[ $id ] = (int) ( $row['incoming'] ?? 0 );
			}
		}

		return $out;
	}

	/**
	 * Yoast's SEO links repository, or null when unavailable.
	 *
	 * @since  0.0.34
	 * @return object|null
	 */
	private static function links_repository() {
		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( '\Yoast\WP\SEO\Repositories\SEO_Links_Repository' ) ) {
			return null;
		}

		try {
			return \YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\SEO_Links_Repository::class );
		} catch ( \Throwable $e ) {
			// The container throws when Yoast's DI has not finished booting. A null here means the
			// link abilities report no data rather than fatalling mid-request.
			return null;
		}
	}

	/**
	 * Yoast's meta surface, or null when Yoast is absent.
	 *
	 * @since  0.0.34
	 * @return object|null
	 */
	private static function surface() {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return null;
		}

		$yoast = \YoastSEO();

		return isset( $yoast->meta ) ? $yoast->meta : null;
	}
}
