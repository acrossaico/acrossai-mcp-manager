<?php
/**
 * Feature 106 — XML sitemap status and cache invalidation.
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
 * Static-only repository over Yoast's sitemaps.
 */
final class Sitemap_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether XML sitemaps are switched on, and where the index lives.
	 *
	 * @since  0.0.34
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$enabled = (bool) \WPSEO_Options::get( 'enable_xml_sitemap', false );

		return array(
			'enabled'   => $enabled,
			'index_url' => $enabled ? home_url( '/sitemap_index.xml' ) : '',
		);
	}

	/**
	 * The sitemap types Yoast can be asked to invalidate.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$types[ (string) $type ] = sprintf(
				/* translators: %s: post type name */
				__( 'The %s sitemap.', 'acrossai-abilities-manager' ),
				(string) $type
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$types[ (string) $tax ] = sprintf(
				/* translators: %s: taxonomy name */
				__( 'The %s term sitemap.', 'acrossai-abilities-manager' ),
				(string) $tax
			);
		}

		return $types;
	}

	/**
	 * Invalidate one sitemap type, or every type when none is named.
	 *
	 * @since  0.0.34
	 * @param  string $type Sitemap type, or '' for all.
	 * @return array<int,string>|WP_Error The types invalidated.
	 */
	public static function invalidate( string $type = '' ) {
		if ( '' === $type ) {
			\WPSEO_Sitemaps_Cache::clear();

			return array( 'all' );
		}

		if ( ! isset( self::types()[ $type ] ) ) {
			return new WP_Error(
				'unknown_sitemap_type',
				sprintf(
					/* translators: 1: requested type, 2: comma-separated known types */
					__( '"%1$s" is not a sitemap type on this site. Known types: %2$s.', 'acrossai-abilities-manager' ),
					$type,
					implode( ', ', array_keys( self::types() ) )
				)
			);
		}

		\WPSEO_Sitemaps_Cache::invalidate( $type );

		return array( $type );
	}

	/**
	 * Invalidate the sitemap entry for one post.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function invalidate_post( int $post_id ) {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'unknown_post',
				sprintf(
					/* translators: %d: post id */
					__( 'No post with id %d.', 'acrossai-abilities-manager' ),
					$post_id
				)
			);
		}

		\WPSEO_Sitemaps_Cache::invalidate_post( $post_id );

		return true;
	}

	/**
	 * Which post types and taxonomies are excluded from the sitemap, as ROWS.
	 *
	 * Derived from the `noindex-*` settings, because Yoast excludes anything noindexed rather than
	 * keeping a separate sitemap list — a distinction worth surfacing, since a caller looking for a
	 * "sitemap exclusions" setting will not find one.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string,mixed>>
	 */
	public static function coverage(): array {
		$rows = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$rows[] = array(
				'object'   => (string) $type,
				'kind'     => 'post_type',
				'included' => ! (bool) \WPSEO_Options::get( 'noindex-' . $type, false ),
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$rows[] = array(
				'object'   => (string) $tax,
				'kind'     => 'taxonomy',
				'included' => ! (bool) \WPSEO_Options::get( 'noindex-tax-' . $tax, false ),
			);
		}

		return $rows;
	}
}
