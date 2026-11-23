<?php
/**
 * Feature 132 — AIOSEO sitemap state and regeneration.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything this suite knows about AIOSEO's sitemaps.
 *
 * AIOSEO ships no sitemap ability of its own, and `aioseo-audit/site-get` reports only whether the
 * sitemap is on. So "it is enabled and still 404s" — the question people actually ask — has no
 * answer anywhere until this class.
 *
 * **One trap governs this whole file.** `aioseo()->sitemap->helpers->includedPostTypes()` reads
 * `aioseo()->options->sitemap->{aioseo()->sitemap->type}->postTypes`, and `type` is set while AIOSEO
 * parses an actual sitemap request. Outside one it is empty, the dynamic property resolves to null,
 * and the helper emits "Attempt to read property on null" warnings and returns null — which an
 * ability would then report as "no post types are included". Every helper call here therefore runs
 * with `type` set, and the previous value is restored afterwards so nothing else in the request sees
 * a sitemap type it did not set.
 *
 * @since 0.0.44
 */
final class Sitemap_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Live sitemap state.
	 *
	 * @since  0.0.44
	 * @param  bool $fetch_index Whether to fetch the sitemap index over HTTP.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function health( bool $fetch_index = true ) {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$general = aioseo()->options->sitemap->general;
		$enabled = (bool) $general->enable;

		$state = self::with_type(
			'general',
			static function () {
				$helpers = aioseo()->sitemap->helpers;

				return array(
					'post_types' => array_values( (array) ( $helpers->includedPostTypes() ?: array() ) ),
					'taxonomies' => array_values( (array) ( $helpers->includedTaxonomies() ?: array() ) ),
					'urls'       => array_values( (array) ( $helpers->getSitemapUrls() ?: array() ) ),
					'index_url'  => (string) $helpers->getUrl( 'general' ),
				);
			}
		);

		$payload = array(
			'enabled'          => $enabled,
			'index_url'        => $state['index_url'],
			'sitemap_urls'     => $state['urls'],
			'post_types'       => $state['post_types'],
			'taxonomies'       => $state['taxonomies'],
			'indexes_enabled'  => (bool) $general->indexes,
			'links_per_index'  => (int) $general->linksPerIndex,
			'filename'         => (string) $general->filename,
			'author_sitemap'   => (bool) $general->author,
			'date_sitemap'     => (bool) $general->date,
			'static_file_mode' => self::static_mode(),
			'rss_enabled'      => (bool) aioseo()->options->sitemap->rss->enable,
			'html_enabled'     => (bool) aioseo()->options->sitemap->html->enable,
			'llms_enabled'     => (bool) aioseo()->options->sitemap->llms->enable,
			'index_check'      => null,
		);

		if ( ! $enabled ) {
			$payload['message'] = __( 'The AIOSEO XML sitemap is switched off, so no sitemap is served. Turn it on by writing enable=true to the sitemap-general panel.', 'acrossai-abilities-manager' );

			return $payload;
		}

		if ( $fetch_index ) {
			$payload['index_check'] = self::check_index( $payload['index_url'] );
		}

		$payload['message'] = self::summarise( $payload );

		return $payload;
	}

	/**
	 * Ask AIOSEO to rebuild its static sitemap files.
	 *
	 * Only meaningful in static-file mode. On a site serving the sitemap dynamically there are no
	 * files to rebuild, and saying so is more useful than scheduling work that does nothing.
	 *
	 * @since  0.0.44
	 * @param  bool $immediate Rebuild now rather than scheduling it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function regenerate( bool $immediate ) {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		if ( ! aioseo()->options->sitemap->general->enable ) {
			return new WP_Error(
				'aioseo_sitemap_disabled',
				__( 'The AIOSEO XML sitemap is switched off, so there is nothing to regenerate. Turn it on first by writing enable=true to the sitemap-general panel.', 'acrossai-abilities-manager' )
			);
		}

		$mode = self::static_mode();

		if ( 'static' !== $mode ) {
			return array(
				'mode'      => $mode,
				'scheduled' => false,
				'message'   => __( 'This sitemap is generated on request rather than written to files, so there is nothing to regenerate — the next request already reflects the current settings. Clear any page cache in front of the site if a stale sitemap is being served.', 'acrossai-abilities-manager' ),
			);
		}

		if ( $immediate ) {
			aioseo()->sitemap->regenerateStaticSitemap();

			return array(
				'mode'      => $mode,
				'scheduled' => false,
				'message'   => __( 'The static sitemap files were rebuilt now.', 'acrossai-abilities-manager' ),
			);
		}

		aioseo()->sitemap->scheduleRegeneration();

		return array(
			'mode'      => $mode,
			'scheduled' => true,
			'message'   => __( 'A static sitemap rebuild was scheduled, which is how AIOSEO does it after its own settings changes. Pass immediate=true to rebuild in this request instead — that can be slow on a large site.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Whether AIOSEO is serving static sitemap files or generating on request.
	 *
	 * @since  0.0.44
	 * @return string 'static', 'dynamic' or 'unknown'.
	 */
	private static function static_mode(): string {
		if ( ! is_object( aioseo()->sitemap ) || ! method_exists( aioseo()->sitemap, 'detectStatic' ) ) {
			return 'unknown';
		}

		return aioseo()->sitemap->detectStatic() ? 'static' : 'dynamic';
	}

	/**
	 * Fetch the sitemap index and report what came back.
	 *
	 * A loopback request to this site's own URL, the same shape the Rank Math suite's sitemap read
	 * uses. The point is to catch the case the settings cannot show: a sitemap that is enabled,
	 * correctly configured, and still answers 404 because a rewrite rule or a server rule is missing.
	 *
	 * @since  0.0.44
	 * @param  string $url Sitemap index URL.
	 * @return array<string, mixed>|null
	 */
	private static function check_index( string $url ): ?array {
		if ( '' === $url ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'url'          => $url,
				'reachable'    => false,
				'status'       => null,
				'content_type' => null,
				'note'         => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$type   = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body   = (string) wp_remote_retrieve_body( $response );

		return array(
			'url'          => $url,
			'reachable'    => $status >= 200 && $status < 300,
			'status'       => $status,
			'content_type' => $type,
			'looks_like_xml' => str_contains( $body, '<?xml' ) || str_contains( $body, '<sitemapindex' ) || str_contains( $body, '<urlset' ),
		);
	}

	/**
	 * A one-line reading of the payload, naming the likely cause when something is wrong.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $payload Health payload.
	 * @return string
	 */
	private static function summarise( array $payload ): string {
		$check = $payload['index_check'];

		if ( is_array( $check ) && false === $check['reachable'] ) {
			return sprintf(
				/* translators: 1: sitemap index URL, 2: HTTP status or a transport error */
				__( 'The sitemap is switched on but %1$s did not serve it (%2$s). With the settings correct, the usual causes are stale rewrite rules — re-save permalinks — or a server rule or security plugin intercepting the path.', 'acrossai-abilities-manager' ),
				(string) $check['url'],
				null === $check['status'] ? (string) ( $check['note'] ?? '' ) : (string) $check['status']
			);
		}

		if ( is_array( $check ) && empty( $check['looks_like_xml'] ) ) {
			return sprintf(
				/* translators: %s: sitemap index URL */
				__( '%s responded, but not with XML — something is serving a page in the sitemap\'s place. Check for a published page at that path or a caching layer in front of it.', 'acrossai-abilities-manager' ),
				(string) $check['url']
			);
		}

		if ( array() === $payload['post_types'] && array() === $payload['taxonomies'] ) {
			return __( 'The sitemap is switched on, but no post types or taxonomies are included in it, so it advertises nothing. Set postTypes.all=true, or list the ones you want in postTypes.included, on the sitemap-general panel.', 'acrossai-abilities-manager' );
		}

		// Never claim reachability that was not measured. With fetch_index=false there is no
		// index_check, and an earlier version still reported "reachable and serving XML" — an
		// assertion about the one thing the caller had just asked us not to test.
		if ( null === $check ) {
			return __( 'The sitemap is switched on and configured to include content. Whether it is actually served was not checked — call again with fetch_index=true to confirm the index responds.', 'acrossai-abilities-manager' );
		}

		return __( 'The sitemap is switched on, reachable and serving XML.', 'acrossai-abilities-manager' );
	}

	/**
	 * Run a callback with `aioseo()->sitemap->type` set, restoring it afterwards.
	 *
	 * @since  0.0.44
	 * @param  string   $type     Sitemap type, e.g. 'general'.
	 * @param  callable $callback Callback.
	 * @return mixed
	 */
	private static function with_type( string $type, callable $callback ) {
		$sitemap = aioseo()->sitemap;

		if ( ! is_object( $sitemap ) ) {
			return $callback();
		}

		$previous      = $sitemap->type ?? null;
		$sitemap->type = $type;

		try {
			return $callback();
		} finally {
			$sitemap->type = $previous;
		}
	}
}
