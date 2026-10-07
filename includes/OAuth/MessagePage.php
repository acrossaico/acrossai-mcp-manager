<?php
/**
 * MessagePage — shared styled browser-facing OAuth message/error page.
 *
 * Every browser-rendered OAuth surface that is NOT the consent screen
 * (inline 400s where the redirect_uri cannot be trusted, the pending-
 * approval notice, the /authorize rate-limit 429, POST failure 403s, and
 * the router's unknown-route 404) routes through this helper so the
 * visitor sees a consent-style card instead of raw text, raw JSON, or a
 * blank page. Status codes and semantics are the caller's — this class
 * only owns presentation and the shared response-header order.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

// F095 did not port the companion's OAuth\CacheHeaders — the Utilities copy
// is a superset. Every other file in this namespace imports it; this one was
// missed, so the unqualified name resolved to OAuth\CacheHeaders and every
// render() call fataled, taking out /authorize and all OAuth error pages.
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;

defined( 'ABSPATH' ) || exit;

final class MessagePage {

	/**
	 * Render a styled, self-contained message page and exit.
	 *
	 * Header order mirrors the previous inline-error path: status, then
	 * no-store cache defense (memory D7 — OAuth response paths MUST use
	 * CacheHeaders), then caller-supplied extras (e.g. Retry-After), then
	 * Content-Type.
	 *
	 * Callers pass only fixed, pre-translated strings — never
	 * request-derived values. The template escapes everything regardless.
	 *
	 * Shape of $extra:
	 *   icon_url  (string, optional)          Icon shown above the heading.
	 *                                         Defaults to the site icon; '' hides it.
	 *   headers   (array<int, string>, optional) Raw header lines sent verbatim.
	 *   home_link (bool, optional, default true) Render a "Return to {site}" link.
	 *
	 * @param int                  $status     HTTP status code.
	 * @param string               $heading    Pre-translated heading.
	 * @param array<int, string>   $paragraphs Pre-translated body paragraphs.
	 * @param array<string, mixed> $extra      Optional overrides (see above).
	 * @return never
	 */
	public static function render( int $status, string $heading, array $paragraphs, array $extra = array() ): void {
		status_header( $status );
		CacheHeaders::send_no_store();

		if ( ! empty( $extra['headers'] ) && is_array( $extra['headers'] ) ) {
			foreach ( $extra['headers'] as $header_line ) {
				header( (string) $header_line );
			}
		}

		header( 'Content-Type: text/html; charset=utf-8' );

		$message_heading    = $heading;
		$message_paragraphs = array_values( array_filter( array_map( 'strval', $paragraphs ) ) );
		$message_icon_url   = array_key_exists( 'icon_url', $extra )
			? (string) $extra['icon_url']
			: (string) get_site_icon_url( 64 );
		$message_home_link  = ! isset( $extra['home_link'] ) || false !== $extra['home_link'];
		$message_home_url   = home_url( '/' );
		$message_site_name  = get_bloginfo( 'name' );

		$template = plugin_dir_path( __DIR__ ) . '../templates/oauth/message.php';
		if ( ! file_exists( $template ) ) {
			// Fallback path relative to includes/OAuth/.
			$template = dirname( __DIR__, 2 ) . '/templates/oauth/message.php';
		}
		require $template;
		exit;
	}
}
