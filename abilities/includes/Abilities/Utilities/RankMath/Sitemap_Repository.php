<?php
/**
 * Feature 069 — Rank Math sitemap state, cache and URL enumeration.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only helper for Rank Math's XML sitemap.
 */
final class Sitemap_Repository {

	/**
	 * The rewrite pattern Rank Math registers for the sitemap index.
	 */
	public const INDEX_PATTERN = '^sitemap_index\.xml$';

	/**
	 * Module slug owning the sitemap.
	 */
	public const MODULE = 'sitemap';

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Rank Math's sitemap file cache is enabled.
	 *
	 * When false, invalidation is a no-op and the caller must be told so rather
	 * than shown a misleading success.
	 *
	 * @return bool
	 */
	public static function is_cache_enabled(): bool {
		if ( ! class_exists( '\RankMath\Sitemap\Sitemap' ) || ! method_exists( '\RankMath\Sitemap\Sitemap', 'is_cache_enabled' ) ) {
			return false;
		}
		return (bool) \RankMath\Sitemap\Sitemap::is_cache_enabled();
	}

	/**
	 * Module state, enabled object types, rewrite status and a live index check.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$settings = array();
		if ( class_exists( '\RankMath\Helper' ) ) {
			$stored   = \RankMath\Helper::get_settings( 'sitemap' );
			$settings = is_array( $stored ) ? $stored : array();
		}

		$active = class_exists( '\RankMath\Helper' ) && \RankMath\Helper::is_module_active( self::MODULE );

		return array(
			'module'        => self::MODULE,
			'module_active' => $active,
			'index_url'     => home_url( '/sitemap_index.xml' ),
			'rewrite'       => Routes_Repository::rewrite_status( self::INDEX_PATTERN ),
			'cache_enabled' => self::is_cache_enabled(),
			'post_types'    => self::enabled_objects( $settings, 'pt_' ),
			'taxonomies'    => self::enabled_objects( $settings, 'tax_' ),
			'general'       => array(
				'items_per_page'         => isset( $settings['items_per_page'] ) ? (int) $settings['items_per_page'] : null,
				'include_images'         => $settings['include_images'] ?? null,
				'include_featured_image' => $settings['include_featured_image'] ?? null,
				'exclude_posts'          => isset( $settings['exclude_posts'] ) ? (string) $settings['exclude_posts'] : '',
				'exclude_terms'          => isset( $settings['exclude_terms'] ) ? (string) $settings['exclude_terms'] : '',
			),
			'index_check'   => $active ? Routes_Repository::preview( '/sitemap_index.xml', 8 ) : null,
		);
	}

	/**
	 * Object types whose sitemap toggle is on.
	 *
	 * Rank Math keys these as pt_{type}_sitemap and tax_{taxonomy}_sitemap.
	 *
	 * @param array<string,mixed> $settings Sitemap settings blob.
	 * @param string              $prefix   'pt_' or 'tax_'.
	 * @return array<int,array<string,mixed>>
	 */
	private static function enabled_objects( array $settings, string $prefix ): array {
		$out = array();
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( ! str_starts_with( $key, $prefix ) || ! str_ends_with( $key, '_sitemap' ) ) {
				continue;
			}
			$object = substr( $key, strlen( $prefix ), -strlen( '_sitemap' ) );
			$out[]  = array(
				'object'       => $object,
				'in_sitemap'   => 'off' !== $value && false !== $value && '' !== $value,
				'html_sitemap' => $settings[ $prefix . $object . '_html_sitemap' ] ?? null,
			);
		}
		return $out;
	}

	/**
	 * Invalidate sitemap cache.
	 *
	 * scope=post uses Cache_Watcher, which only QUEUES invalidation for the
	 * shutdown hook (class-cache-watcher.php:284), so clear_queued() is called
	 * explicitly to make it happen now — an ability that returns success must have
	 * actually done the work.
	 *
	 * @param string $scope   'all' | 'type' | 'post'.
	 * @param string $type    Sitemap type, for scope=type.
	 * @param int    $post_id Post id, for scope=post.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function invalidate( string $scope, string $type = '', int $post_id = 0 ) {
		if ( ! class_exists( '\RankMath\Sitemap\Cache' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math sitemap module is not available.', 'acrossai-abilities-manager' ) );
		}

		$cache_enabled = self::is_cache_enabled();

		switch ( $scope ) {
			case 'all':
				\RankMath\Sitemap\Cache::invalidate_storage( null );
				$target = 'all';
				break;

			case 'type':
				if ( '' === $type ) {
					return new WP_Error( 'invalid_input', __( 'scope=type requires a type.', 'acrossai-abilities-manager' ) );
				}
				\RankMath\Sitemap\Cache::invalidate_storage( $type );
				$target = $type;
				break;

			case 'post':
				if ( $post_id < 1 ) {
					return new WP_Error( 'invalid_input', __( 'scope=post requires a post_id.', 'acrossai-abilities-manager' ) );
				}
				if ( null === get_post( $post_id ) ) {
					return new WP_Error(
						'not_found',
						sprintf(
							/* translators: %d: post id */
							__( 'Post %d does not exist.', 'acrossai-abilities-manager' ),
							$post_id
						)
					);
				}
				if ( ! class_exists( '\RankMath\Sitemap\Cache_Watcher' ) ) {
					return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math sitemap cache watcher is not available.', 'acrossai-abilities-manager' ) );
				}
				\RankMath\Sitemap\Cache_Watcher::invalidate_post( $post_id );
				// Force the queued work now instead of at shutdown.
				\RankMath\Sitemap\Cache_Watcher::clear_queued();
				$target = (string) $post_id;
				break;

			default:
				return new WP_Error( 'invalid_input', __( 'scope must be all, type or post.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'scope'         => $scope,
			'target'        => $target,
			'invalidated'   => true,
			'cache_enabled' => $cache_enabled,
		);
	}

	/**
	 * Fetch the sitemap index and optionally its child sitemaps.
	 *
	 * @param bool $include_children Whether to follow child sitemaps.
	 * @param int  $limit            Maximum URLs to return.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_urls( bool $include_children, int $limit ) {
		$index    = home_url( '/sitemap_index.xml' );
		$response = wp_remote_get( $index, array( 'timeout' => 20, 'redirection' => 3, 'sslverify' => false ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: 1: sitemap index URL, 2: transport error message */
					__( 'Could not fetch %1$s: %2$s', 'acrossai-abilities-manager' ),
					$index,
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: 1: sitemap index URL, 2: HTTP status code */
					__( '%1$s returned HTTP %2$d. Check that the sitemap module is active and rewrite rules are current.', 'acrossai-abilities-manager' ),
					$index,
					$code
				)
			);
		}

		$sitemaps = self::parse_locations( (string) wp_remote_retrieve_body( $response ) );
		$urls     = array();
		$fetched  = 0;

		if ( ! $include_children ) {
			$urls = array_slice( $sitemaps, 0, $limit );
		} else {
			foreach ( $sitemaps as $child ) {
				if ( count( $urls ) >= $limit ) {
					break;
				}
				$child_response = wp_remote_get( $child, array( 'timeout' => 20, 'redirection' => 3, 'sslverify' => false ) );
				if ( is_wp_error( $child_response ) ) {
					continue;
				}
				++$fetched;
				foreach ( self::parse_locations( (string) wp_remote_retrieve_body( $child_response ) ) as $url ) {
					if ( count( $urls ) >= $limit ) {
						break 2;
					}
					$urls[] = $url;
				}
			}
		}

		return array(
			'index_url'         => $index,
			'sitemaps'          => $sitemaps,
			'urls'              => array_values( $urls ),
			'count'             => count( $urls ),
			'children_fetched'  => $fetched,
			'limit_reached'     => count( $urls ) >= $limit,
		);
	}

	/**
	 * Extract <loc> values from a sitemap document.
	 *
	 * Regex rather than a DOM parse: sitemaps can be large and we only need the
	 * locations, and a malformed document should yield what it can rather than
	 * throw.
	 *
	 * @param string $xml Sitemap XML.
	 * @return string[]
	 */
	private static function parse_locations( string $xml ): array {
		if ( ! preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $matches ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches[1] as $loc ) {
			$url = esc_url_raw( html_entity_decode( trim( (string) $loc ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $url ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
