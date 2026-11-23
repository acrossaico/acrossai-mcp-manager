<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helper for the Get_*_Revisions abilities — turns a revision WP_Post
 * into the canonical response shape, resolves the author display name once,
 * and flags autosaves so callers can distinguish them from full revisions.
 */
final class Revision_Formatter {

	/**
	 * @param WP_Post $rev A WP_Post whose post_type is 'revision'.
	 * @return array<string, mixed>
	 */
	public static function to_array( WP_Post $rev ): array {
		$author_id   = (int) $rev->post_author;
		$author_name = '';
		if ( $author_id > 0 ) {
			$user = get_userdata( $author_id );
			if ( $user ) {
				$author_name = (string) $user->display_name;
			}
		}

		return array(
			'id'           => (int) $rev->ID,
			'parent'       => (int) $rev->post_parent,
			'date'         => mysql_to_rfc3339( $rev->post_date ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'date_gmt'     => mysql_to_rfc3339( $rev->post_date_gmt ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'modified'     => mysql_to_rfc3339( $rev->post_modified ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'modified_gmt' => mysql_to_rfc3339( $rev->post_modified_gmt ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'author'       => $author_id,
			'author_name'  => $author_name,
			'title'        => (string) $rev->post_title,
			'content'      => (string) $rev->post_content,
			'excerpt'      => (string) $rev->post_excerpt,
			'is_autosave'  => (bool) wp_is_post_autosave( $rev ),
		);
	}

	/**
	 * Apply pagination + autosave filtering to a raw `wp_get_post_revisions()`
	 * result. Returns [ items, total ] where items is the page slice and total
	 * is the post-filter count (so pagination math stays accurate when
	 * autosaves are hidden).
	 *
	 * @param array<int, WP_Post> $all                Raw output of wp_get_post_revisions().
	 * @param bool                $include_autosaves  When false, drops autosave rows.
	 * @param int                 $page               1-based page number.
	 * @param int                 $per_page           1–100.
	 * @return array{0: array<int, WP_Post>, 1: int}
	 */
	public static function paginate( array $all, bool $include_autosaves, int $page, int $per_page ): array {
		if ( ! $include_autosaves ) {
			$all = array_filter(
				$all,
				static function ( $rev ): bool {
					return $rev instanceof WP_Post && ! wp_is_post_autosave( $rev );
				}
			);
		}
		$all   = array_values( $all );
		$total = count( $all );
		$slice = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
		return array( $slice, $total );
	}
}
