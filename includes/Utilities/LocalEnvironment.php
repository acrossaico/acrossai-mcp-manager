<?php
/**
 * Local-dev environment detection for the TLS-bypass injection.
 *
 * When a WordPress site is served over HTTPS from a local dev tool
 * (Local by Flywheel, MAMP, DDEV, wp-env) with a self-signed certificate,
 * the `@automattic/mcp-wordpress-remote` Node proxy rejects the cert and
 * the connected MCP client returns zero tools with no error surface. Setting
 * NODE_TLS_REJECT_UNAUTHORIZED=0 in the proxy's env block fixes it — for
 * throwaway local testing only, never against a live site.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 * @since      0.4.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the current site looks like a local dev environment — the
 * condition under which the plugin injects `NODE_TLS_REJECT_UNAUTHORIZED=0`
 * into generated MCP client JSON snippets and renders the "local dev
 * detected" warning notice above them.
 *
 * Rule (see Feature 075 spec FR-001, FR-002 — Clarification 2026-08-24 Q4
 * dropped the earlier HTTPS-scheme gate):
 *   The site is "local" when ANY of:
 *   - `wp_get_environment_type()` returns `local` or `development` — NOT
 *     `staging` (Clarification Q1: staging is close to production);
 *   - the host is `localhost`, `127.0.0.1`, or `::1`;
 *   - the host ends with one of the suffixes returned by the
 *     `acrossai_mcp_local_hostname_suffixes` filter (default:
 *     `['.local', '.test', '.localhost']`).
 *
 * The scheme (http vs https) does NOT gate detection. On HTTPS-with-self-
 * signed-cert the flag is the real fix; on HTTP the flag is a harmless
 * no-op but the warning + doc link still guides operators toward
 * Automattic's troubleshooting page (which covers non-TLS local-dev
 * issues too). Clarification Q4 (2026-08-24) explicitly authorises the
 * cosmetic no-op on HTTP in exchange for the simpler mental model
 * "site looks local → show the affordance".
 *
 * Static-only, no state, no hooks registered here — mirrors the shape of
 * `Utilities\SiteSlug`. Called inline from render paths that already run
 * inside admin/wizard contexts; per-call cost is O(1).
 */
final class LocalEnvironment {

	/**
	 * Automattic troubleshooting doc URL — single source of truth for both
	 * the admin notice and the wizard's `wp_localize_script` payload.
	 */
	public const TROUBLESHOOTING_DOC_URL = 'https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md';

	/**
	 * Default host suffixes considered "local". Extended by third parties via
	 * the `acrossai_mcp_local_hostname_suffixes` filter (FR-010).
	 */
	public const DEFAULT_LOCAL_HOSTNAME_SUFFIXES = array( '.local', '.test', '.localhost' );

	/**
	 * Return the doc URL — kept as a getter (not just the constant) so
	 * callers reference a single symbol whether they need the URL from PHP
	 * or forward it to JS via `wp_localize_script`.
	 */
	public static function troubleshooting_doc_url(): string {
		return self::TROUBLESHOOTING_DOC_URL;
	}

	/**
	 * True iff the current site meets the FR-001 conditions to auto-inject
	 * NODE_TLS_REJECT_UNAUTHORIZED=0 into generated MCP client JSON.
	 * Fires on any local-looking site regardless of scheme (Clarification
	 * Q4 2026-08-24 — see class docblock for rationale).
	 */
	public static function needs_tls_bypass(): bool {
		if ( self::is_local_env_type() ) {
			return true;
		}

		if ( ! function_exists( 'home_url' ) ) {
			return false;
		}

		$home = (string) home_url();
		if ( '' === $home ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		return self::is_local_host( $host );
	}

	/**
	 * True when `wp_get_environment_type()` returns `local` or `development`.
	 * `staging` and `production` return false (Clarification Q1).
	 */
	private static function is_local_env_type(): bool {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false;
		}
		$env = wp_get_environment_type();
		return 'local' === $env || 'development' === $env;
	}

	/**
	 * True when the host matches a well-known loopback address or ends with
	 * one of the local-suffix strings.
	 *
	 * @param string $host Lowercased host component of `home_url()`.
	 */
	private static function is_local_host( string $host ): bool {
		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return true;
		}

		foreach ( self::get_local_hostname_suffixes() as $suffix ) {
			$suffix = (string) $suffix;
			if ( '' === $suffix ) {
				continue;
			}
			$len = strlen( $suffix );
			if ( strlen( $host ) >= $len && 0 === substr_compare( $host, $suffix, -$len ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filterable list of host suffixes considered "local". Callbacks returning
	 * a non-array value are ignored — the defaults are used instead (SC-005).
	 *
	 * @return string[]
	 */
	private static function get_local_hostname_suffixes(): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::DEFAULT_LOCAL_HOSTNAME_SUFFIXES;
		}

		/**
		 * Filter: acrossai_mcp_local_hostname_suffixes
		 *
		 * Ops teams that self-host on custom local suffixes (`.docker`,
		 * `.internal`, `.dev`, etc.) can append to the default list here.
		 * Suffixes should include the leading dot. Callbacks returning a
		 * non-array value are defensively ignored — detection falls back
		 * to the default suffix list. The HTTPS scheme gate (FR-009) is
		 * enforced independently of this filter, so extending the list
		 * cannot enable the flag on a plain-HTTP site.
		 *
		 * @since 0.4.0
		 *
		 * @param string[] $suffixes Default local hostname suffixes.
		 */
		$filtered = apply_filters(
			'acrossai_mcp_local_hostname_suffixes',
			self::DEFAULT_LOCAL_HOSTNAME_SUFFIXES
		);

		return is_array( $filtered ) ? $filtered : self::DEFAULT_LOCAL_HOSTNAME_SUFFIXES;
	}
}
