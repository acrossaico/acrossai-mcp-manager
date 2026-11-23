<?php
/**
 * Path allowlist guard for file-manager abilities (Feature 092).
 *
 * Two independent lists — one for writes, one for reads — with slightly
 * different semantics:
 *
 * - WRITE allowlist: empty array means DENY ALL writes. Non-empty means
 *   only the listed paths (and their descendants) permit writes.
 *   Default on install: ['wp-content'].
 * - READ allowlist:  empty array means UNRESTRICTED (all reads allowed —
 *   sentinel). Non-empty means only the listed paths (and their descendants)
 *   permit reads. Default on install: [] (unrestricted).
 *
 * Prefix-match semantics: a target absolute path is inside an allowed root iff its real path
 * equals the entry's resolved real path or starts with it followed by a '/'. Entries are resolved
 * through Fs_Roots (Feature 135), so 'wp-content' means this site's content directory on every
 * layout -- not ABSPATH/wp-content, which on a split-docroot host is a different, unused
 * directory.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Path_Allowlist_Guard utility.
 */
final class Path_Allowlist_Guard {

	/**
	 * Option name for the WRITE allowlist.
	 */
	public const OPTION_WRITE = 'acrossai_file_manager_write_allowlist';

	/**
	 * Option name for the READ allowlist.
	 */
	public const OPTION_READ = 'acrossai_file_manager_read_allowlist';

	/**
	 * Default WRITE allowlist applied on activation.
	 *
	 * @var array<int,string>
	 */
	public const DEFAULT_WRITE_ALLOWLIST = array( 'wp-content' );

	/**
	 * Default READ allowlist applied on activation (empty = unrestricted).
	 *
	 * @var array<int,string>
	 */
	public const DEFAULT_READ_ALLOWLIST = array();

	/* -------------------------------------------------------------------- */
	/* Accessors                                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Get the current WRITE allowlist.
	 *
	 * @return array<int,string>
	 */
	public static function get_write_paths(): array {
		return self::sanitize_path_list( (array) get_option( self::OPTION_WRITE, self::DEFAULT_WRITE_ALLOWLIST ) );
	}

	/**
	 * Persist a new WRITE allowlist.
	 *
	 * @param array<int,string> $paths ABSPATH-relative paths.
	 * @return bool True on success.
	 */
	public static function set_write_paths( array $paths ): bool {
		return (bool) update_option( self::OPTION_WRITE, self::sanitize_path_list( $paths ) );
	}

	/**
	 * Get the current READ allowlist.
	 *
	 * @return array<int,string>
	 */
	public static function get_read_paths(): array {
		return self::sanitize_path_list( (array) get_option( self::OPTION_READ, self::DEFAULT_READ_ALLOWLIST ) );
	}

	/**
	 * Persist a new READ allowlist.
	 *
	 * @param array<int,string> $paths ABSPATH-relative paths.
	 * @return bool True on success.
	 */
	public static function set_read_paths( array $paths ): bool {
		return (bool) update_option( self::OPTION_READ, self::sanitize_path_list( $paths ) );
	}

	/* -------------------------------------------------------------------- */
	/* Guard checks                                                          */
	/* -------------------------------------------------------------------- */

	/**
	 * Check whether an absolute path is inside any WRITE-allowed root.
	 *
	 * Empty allowlist → deny all writes.
	 *
	 * @param string $absolute_path Absolute filesystem path (post realpath scoping).
	 * @return true|\WP_Error True if allowed; WP_Error(path_not_allowed_for_write) otherwise.
	 */
	public static function check_write( string $absolute_path ) {
		$allowed = self::get_write_paths();
		if ( empty( $allowed ) ) {
			return new \WP_Error(
				'path_not_allowed_for_write',
				__( 'Writes are disabled — the write allowlist is empty. Add at least one folder in Settings → AcrossAI → File Manager → Write access.', 'acrossai-abilities-manager' )
			);
		}
		if ( self::path_matches_any_root( $absolute_path, $allowed ) ) {
			return true;
		}
		return new \WP_Error(
			'path_not_allowed_for_write',
			/* translators: %s: comma-separated list of currently-allowed write roots */
			sprintf( __( 'Path is outside the write allowlist. Allowed roots: %s.', 'acrossai-abilities-manager' ), implode( ', ', $allowed ) )
		);
	}

	/**
	 * Check whether an absolute path is inside any READ-allowed root.
	 *
	 * Empty allowlist → unrestricted (all reads allowed — sentinel).
	 *
	 * @param string $absolute_path Absolute filesystem path.
	 * @return true|\WP_Error True if allowed; WP_Error(path_not_allowed_for_read) otherwise.
	 */
	public static function check_read( string $absolute_path ) {
		$allowed = self::get_read_paths();
		if ( empty( $allowed ) ) {
			return true; // Unrestricted sentinel.
		}
		if ( self::path_matches_any_root( $absolute_path, $allowed ) ) {
			return true;
		}
		return new \WP_Error(
			'path_not_allowed_for_read',
			/* translators: %s: comma-separated list of currently-allowed read roots */
			sprintf( __( 'Path is outside the read allowlist. Allowed roots: %s.', 'acrossai-abilities-manager' ), implode( ', ', $allowed ) )
		);
	}

	/* -------------------------------------------------------------------- */
	/* Ability-envelope wrappers                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Convenience for ability classes — returns the standard refusal envelope
	 * when the write is disallowed, or null when the caller may proceed.
	 *
	 * @param string $absolute_path Absolute filesystem path.
	 * @return array{success: false, blocked_reason: string, path: string, message: string, allowed_roots: array<int,string>}|null
	 */
	public static function blocked_write_response( string $absolute_path ): ?array {
		$check = self::check_write( $absolute_path );
		if ( ! is_wp_error( $check ) ) {
			return null;
		}
		return array(
			'success'        => false,
			'blocked_reason' => 'path_not_allowed_for_write',
			'path'           => $absolute_path,
			'message'        => (string) $check->get_error_message(),
			'allowed_roots'  => self::get_write_paths(),
		);
	}

	/**
	 * Convenience for ability classes — returns the standard refusal envelope
	 * when the read is disallowed, or null when the caller may proceed.
	 *
	 * @param string $absolute_path Absolute filesystem path.
	 * @return array{success: false, blocked_reason: string, path: string, message: string, allowed_roots: array<int,string>}|null
	 */
	public static function blocked_read_response( string $absolute_path ): ?array {
		$check = self::check_read( $absolute_path );
		if ( ! is_wp_error( $check ) ) {
			return null;
		}
		return array(
			'success'        => false,
			'blocked_reason' => 'path_not_allowed_for_read',
			'path'           => $absolute_path,
			'message'        => (string) $check->get_error_message(),
			'allowed_roots'  => self::get_read_paths(),
		);
	}

	/* -------------------------------------------------------------------- */
	/* Internals                                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Sanitize a path list: strip whitespace and leading/trailing slashes,
	 * drop empty entries and any entry containing '..' (post-normalisation).
	 *
	 * @param array<int|string,mixed> $paths Raw path list.
	 * @return array<int,string> Sanitized ABSPATH-relative paths (POSIX separators, no leading/trailing slash).
	 */
	public static function sanitize_path_list( array $paths ): array {
		$out = array();
		foreach ( $paths as $raw ) {
			if ( ! is_string( $raw ) ) {
				continue;
			}
			$rel = trim( $raw );
			if ( '' === $rel ) {
				continue;
			}
			$rel = str_replace( '\\', '/', $rel );
			$rel = trim( $rel, "/ \t\n\r\0\x0B" );
			if ( '' === $rel ) {
				continue;
			}
			// Reject any traversal attempts.
			$segments = explode( '/', $rel );
			foreach ( $segments as $segment ) {
				if ( '..' === $segment || '.' === $segment ) {
					continue 2;
				}
			}
			$out[] = $rel;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Determine whether $absolute_path sits inside any of the allowlist roots.
	 *
	 * Feature 135: each entry is resolved through {@see Fs_Roots::resolve()} rather than joined onto
	 * ABSPATH. The old join made the shipped default actively wrong on hosts that place
	 * `WP_CONTENT_DIR` outside `ABSPATH`: `DEFAULT_WRITE_ALLOWLIST = array( 'wp-content' )` resolved
	 * to the unused copy under the core directory, so the out-of-the-box posture denied writes to
	 * the site's real content directory and permitted them inside the host's core tree -- the exact
	 * inverse of what the setting reads as.
	 *
	 * Entries that do not exist on disk are skipped, as before.
	 *
	 * @param string            $absolute_path Absolute target path.
	 * @param array<int,string> $allowlist     Roots, relative to a WordPress directory.
	 * @return bool
	 */
	private static function path_matches_any_root( string $absolute_path, array $allowlist ): bool {
		if ( '' === $absolute_path ) {
			return false;
		}

		$target = rtrim( str_replace( '\\', '/', $absolute_path ), '/' );

		foreach ( $allowlist as $rel ) {
			$resolved = Fs_Roots::resolve( (string) $rel, true );

			if ( is_wp_error( $resolved ) ) {
				continue;
			}

			if ( Fs_Roots::inside( $target, $resolved['path'] ) ) {
				return true;
			}
		}

		return false;
	}
}
