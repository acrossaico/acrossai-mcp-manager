<?php
/**
 * Feature 104 — every LiteSpeed cache purge the suite performs.
 *
 * Two behaviours of LiteSpeed's purge API this class exists to absorb:
 *
 * 1. **The purge methods queue an admin notice.** `Purge::purge_cat()` and friends call
 *    `Admin_Display::success()` unless `LITESPEED_PURGE_SILENT` is defined, which would surface a
 *    "Purge category x" banner on whatever admin screen the operator loads next — caused by an API
 *    call they never saw. Every entry point here defines that constant first.
 * 2. **They report nothing.** `purge_cat()` returns void and bails silently on an unknown slug, so
 *    the caller cannot tell a purge from a no-op. This class validates the target itself and reports
 *    what it actually resolved.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over LiteSpeed's purge API.
 */
final class Purge_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Purge targets, as target => human-readable description.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function targets(): array {
		return array(
			'all'        => __( 'Everything LiteSpeed caches, including generated CSS/JS.', 'acrossai-abilities-manager' ),
			'lscache'    => __( 'The page cache only, leaving generated CSS/JS in place.', 'acrossai-abilities-manager' ),
			'object'     => __( 'The object cache.', 'acrossai-abilities-manager' ),
			'opcache'    => __( 'The PHP opcode cache.', 'acrossai-abilities-manager' ),
			'css-js'     => __( 'Generated CSS and JS artefacts only.', 'acrossai-abilities-manager' ),
			'ucss'       => __( 'Generated unique CSS only.', 'acrossai-abilities-manager' ),
			'front-page' => __( 'The front page only.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Suppress LiteSpeed's admin-notice side effect.
	 *
	 * An ability call is not an admin screen interaction; queueing a notice would show it to whoever
	 * loads wp-admin next, with no context for where it came from.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private static function silence(): void {
		if ( ! defined( 'LITESPEED_PURGE_SILENT' ) ) {
			define( 'LITESPEED_PURGE_SILENT', true );
		}
	}

	/**
	 * Purge by named target.
	 *
	 * @since  0.0.34
	 * @param  string $target One of targets().
	 * @return true|WP_Error
	 */
	public static function purge( string $target ) {
		if ( ! isset( self::targets()[ $target ] ) ) {
			return new WP_Error(
				'unknown_purge_target',
				sprintf(
					/* translators: 1: requested target, 2: comma-separated known targets */
					__( '"%1$s" is not a purge target. Known targets: %2$s.', 'acrossai-abilities-manager' ),
					$target,
					implode( ', ', array_keys( self::targets() ) )
				)
			);
		}

		self::silence();

		switch ( $target ) {
			case 'all':
				\LiteSpeed\Purge::purge_all( 'acrossai ability' );
				break;

			case 'lscache':
				\LiteSpeed\Purge::purge_all_lscache( 'acrossai ability' );
				break;

			case 'object':
				\LiteSpeed\Purge::purge_all_object();
				break;

			case 'opcache':
				\LiteSpeed\Core::cls( 'Purge' )->purge_all_opcache();
				break;

			case 'css-js':
				\LiteSpeed\Purge::purge_all_lscache( 'acrossai ability' );
				\LiteSpeed\Core::cls( 'Purge' )->purge_list();
				break;

			case 'ucss':
				\LiteSpeed\Purge::purge_ucss( home_url() );
				break;

			default:
				\LiteSpeed\Core::cls( 'Purge' )->purge_url( home_url( '/' ) );
				break;
		}

		return true;
	}

	/**
	 * Purge specific URLs.
	 *
	 * @since  0.0.34
	 * @param  string[] $urls URLs.
	 * @return array<int, string>|WP_Error The URLs actually purged.
	 */
	public static function purge_urls( array $urls ) {
		$valid = array();

		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );

			if ( '' !== $url ) {
				$valid[] = $url;
			}
		}

		if ( array() === $valid ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one valid URL.', 'acrossai-abilities-manager' ) );
		}

		self::silence();

		foreach ( $valid as $url ) {
			\LiteSpeed\Core::cls( 'Purge' )->purge_url( $url );
		}

		return $valid;
	}

	/**
	 * Purge by post ID.
	 *
	 * Each ID is checked before purging so an unknown one is reported rather than silently skipped.
	 *
	 * @since  0.0.34
	 * @param  int[] $post_ids Post IDs.
	 * @return array{purged: array<int,int>, missing: array<int,int>}|WP_Error
	 */
	public static function purge_posts( array $post_ids ) {
		$purged  = array();
		$missing = array();

		foreach ( $post_ids as $id ) {
			$id = (int) $id;

			if ( $id < 1 || ! get_post( $id ) ) {
				$missing[] = $id;
				continue;
			}

			$purged[] = $id;
		}

		if ( array() === $purged ) {
			return new WP_Error( 'invalid_input', __( 'None of the supplied post ids exist.', 'acrossai-abilities-manager' ) );
		}

		self::silence();

		foreach ( $purged as $id ) {
			\LiteSpeed\Purge::add( \LiteSpeed\Tag::TYPE_POST . $id );
		}

		return array(
			'purged'  => $purged,
			'missing' => $missing,
		);
	}

	/**
	 * Purge a category or tag archive.
	 *
	 * LiteSpeed's `purge_cat()` / `purge_tag()` take a SLUG, validate it against
	 * `/^[a-zA-Z0-9-]+$/`, and return void — bailing silently when the term does not exist. Numeric
	 * ids are therefore resolved to slugs here, and every term is checked, so the caller learns which
	 * ones were real.
	 *
	 * @since  0.0.34
	 * @param  string            $taxonomy `category` or `post_tag`.
	 * @param  array<int,string> $terms    Slugs or numeric ids.
	 * @return array{purged: array<int,string>, missing: array<int,string>}|WP_Error
	 */
	public static function purge_taxonomy( string $taxonomy, array $terms ) {
		if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return new WP_Error(
				'unknown_taxonomy',
				__( 'Taxonomy must be "category" or "post_tag".', 'acrossai-abilities-manager' )
			);
		}

		$purged  = array();
		$missing = array();

		foreach ( $terms as $raw ) {
			$raw  = trim( (string) $raw );
			$term = is_numeric( $raw )
				? get_term( (int) $raw, $taxonomy )
				: get_term_by( 'slug', $raw, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				$missing[] = $raw;
				continue;
			}

			$purged[] = (string) $term->slug;
		}

		if ( array() === $purged ) {
			return new WP_Error( 'invalid_input', __( 'None of the supplied terms exist.', 'acrossai-abilities-manager' ) );
		}

		self::silence();

		foreach ( $purged as $slug ) {
			if ( 'category' === $taxonomy ) {
				\LiteSpeed\Core::cls( 'Purge' )->purge_cat( $slug );
			} else {
				\LiteSpeed\Core::cls( 'Purge' )->purge_tag( $slug );
			}
		}

		return array(
			'purged'  => $purged,
			'missing' => $missing,
		);
	}

	/**
	 * Purge by raw LiteSpeed cache tag.
	 *
	 * @since  0.0.34
	 * @param  string[] $tags Cache tags.
	 * @return array<int,string>|WP_Error
	 */
	public static function purge_tags( array $tags ) {
		$valid = array();

		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( trim( (string) $tag ) );

			if ( '' !== $tag ) {
				$valid[] = $tag;
			}
		}

		if ( array() === $valid ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one cache tag.', 'acrossai-abilities-manager' ) );
		}

		self::silence();
		\LiteSpeed\Purge::add( $valid );

		return $valid;
	}
}
