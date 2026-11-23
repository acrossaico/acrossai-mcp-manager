<?php
/**
 * Backups storage helper for the Zip_* abilities (Feature 041).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Single point of truth for the two managed directories used by the Zip_*
 * abilities:
 *
 *   - wp-content/uploads/acrossai-backups/  → finalized zip archives
 *   - wp-content/uploads/acrossai-staging/  → in-progress chunked uploads
 *
 * The directories are hardened on first use with `.htaccess` (deny access) and
 * an empty `index.php` to prevent directory listing / PHP execution. Every
 * caller-supplied path is validated to resolve INSIDE one of these dirs;
 * anything else is rejected as a WP_Error so callers never touch the wider
 * filesystem through this helper.
 */
final class Backups_Storage {

	public const BACKUPS_DIR = 'acrossai-backups';
	public const STAGING_DIR = 'acrossai-staging';

	/**
	 * Absolute path to the finalized-backups directory. Creates + hardens on
	 * first call. Returns false when the uploads dir is unavailable.
	 *
	 * @return string|false
	 */
	public static function backups_path() {
		return self::resolve_dir( self::BACKUPS_DIR );
	}

	/**
	 * URL to the finalized-backups directory (without trailing slash).
	 *
	 * @return string|false
	 */
	public static function backups_url() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return false;
		}
		return trailingslashit( $uploads['baseurl'] ) . self::BACKUPS_DIR;
	}

	/**
	 * Absolute path to the chunked-upload staging directory.
	 *
	 * @return string|false
	 */
	public static function staging_path() {
		return self::resolve_dir( self::STAGING_DIR );
	}

	/**
	 * Generate a filename for a new backup: `{slug}-{unix-timestamp}-{ms}.zip`.
	 *
	 * The slug segment is the sanitized target descriptor (`hello-dolly` for a
	 * plugin, sanitized filename hint for uploads, etc.). When the caller
	 * doesn't supply one, we fall back to the sanitized $target_type so we
	 * always have a base word (e.g. `uploads-...zip`, `mu-plugins-...zip`).
	 * The timestamp + millisecond suffix guarantees two back-to-back calls
	 * for the same target don't overwrite each other.
	 *
	 * The `.` separator between path characters is replaced with `-` so
	 * callers can never smuggle in a path segment through the slug.
	 */
	public static function random_backup_filename( string $target_type, string $target ): string {
		$slug = self::filename_slug_segment( $target_type, $target );

		list( $unix, $ms ) = self::filename_time_segments();

		return $slug . '-' . $unix . '-' . $ms . '.zip';
	}

	/**
	 * Resolve the slug segment for `random_backup_filename()`. Prefer the
	 * sanitized $target; fall back to the sanitized $target_type; ultimate
	 * fallback is `backup` so the returned filename is never just `-{ts}.zip`.
	 */
	private static function filename_slug_segment( string $target_type, string $target ): string {
		$slug = sanitize_key( str_replace( array( '/', '\\', '.' ), '-', $target ) );
		if ( '' !== $slug ) {
			return $slug;
		}
		$type = sanitize_key( $target_type );
		if ( '' !== $type ) {
			return $type;
		}
		return 'backup';
	}

	/**
	 * Return `[$unix, $ms_padded_to_3_digits]` — used by random_backup_filename()
	 * so two calls within the same second still produce distinct filenames.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function filename_time_segments(): array {
		$now  = microtime( true );
		$unix = (int) floor( $now );
		$ms   = (int) floor( ( $now - $unix ) * 1000 );
		return array( (string) $unix, str_pad( (string) $ms, 3, '0', STR_PAD_LEFT ) );
	}

	/**
	 * Given an ABSPATH-relative or bare filename input, return the absolute path
	 * IF it resolves inside `acrossai-backups/` or `acrossai-staging/`; return a
	 * WP_Error otherwise. Callers pass this to file operations so they cannot
	 * be tricked into touching arbitrary paths.
	 *
	 * Accepts:
	 *   - bare filename ("backup-plugin-xyz.zip") → resolved against backups dir
	 *   - relative path starting with "wp-content/uploads/..." → validated
	 *   - relative path starting with the plugin's managed sub-dir names
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve_managed_path( string $rel_or_name ) {
		$rel_or_name = trim( $rel_or_name );
		if ( '' === $rel_or_name ) {
			return new \WP_Error(
				'invalid_path',
				__( 'A file path is required.', 'acrossai-abilities-manager' )
			);
		}

		// Reject obvious traversal / absolute paths up front.
		if ( false !== strpos( $rel_or_name, "\0" ) ) {
			return new \WP_Error(
				'invalid_path',
				__( 'Path contains disallowed characters.', 'acrossai-abilities-manager' )
			);
		}

		$backups = self::backups_path();
		$staging = self::staging_path();
		if ( false === $backups && false === $staging ) {
			return new \WP_Error(
				'uploads_unavailable',
				__( 'Uploads directory is unavailable on this site.', 'acrossai-abilities-manager' )
			);
		}

		// Bare filename → default to backups dir.
		if ( false === strpos( $rel_or_name, '/' ) && false === strpos( $rel_or_name, '\\' ) ) {
			if ( false === $backups ) {
				return new \WP_Error(
					'uploads_unavailable',
					__( 'Backups directory is unavailable on this site.', 'acrossai-abilities-manager' )
				);
			}
			$candidate = trailingslashit( $backups ) . $rel_or_name;
		} else {
			// Interpret relative to ABSPATH.
			$base      = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );
			$candidate = $base . '/' . ltrim( $rel_or_name, '/' );
		}

		$parent = realpath( dirname( $candidate ) );
		if ( false === $parent ) {
			return new \WP_Error(
				'invalid_path',
				__( 'Path does not resolve.', 'acrossai-abilities-manager' )
			);
		}

		$parent_slash = $parent . '/';
		$in_backups   = false !== $backups && ( $parent === rtrim( $backups, '/' ) || 0 === strpos( $parent_slash, trailingslashit( $backups ) ) );
		$in_staging   = false !== $staging && ( $parent === rtrim( $staging, '/' ) || 0 === strpos( $parent_slash, trailingslashit( $staging ) ) );

		if ( ! $in_backups && ! $in_staging ) {
			return new \WP_Error(
				'path_out_of_bounds',
				__( 'Path must resolve inside acrossai-backups/ or acrossai-staging/.', 'acrossai-abilities-manager' )
			);
		}

		return $candidate;
	}

	/**
	 * List zip entries in one of the two managed directories, with metadata.
	 *
	 * @param string $which  BACKUPS_DIR or STAGING_DIR.
	 * @param int    $limit  Max entries to return (clamped to 1..200).
	 * @param int    $offset Offset (clamped to >= 0).
	 * @return array{items: array<int, array{file_path: string, file_url: string, size: int, sha256: string, created_at: string}>, total: int}|\WP_Error
	 */
	public static function list_entries( string $which, int $limit = 50, int $offset = 0 ) {
		$which = self::BACKUPS_DIR === $which ? self::BACKUPS_DIR : self::STAGING_DIR;
		$dir   = self::resolve_dir( $which );
		if ( false === $dir ) {
			return new \WP_Error(
				'uploads_unavailable',
				__( 'Directory is unavailable on this site.', 'acrossai-abilities-manager' )
			);
		}

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$files = glob( trailingslashit( $dir ) . '*.zip' );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		usort(
			$files,
			static function ( string $a, string $b ): int {
				$ma = (int) @filemtime( $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$mb = (int) @filemtime( $b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $mb <=> $ma;
			}
		);

		$total = count( $files );
		$slice = array_slice( $files, $offset, $limit );

		$uploads_base    = wp_upload_dir( null, false );
		$uploads_basedir = ! empty( $uploads_base['basedir'] ) ? rtrim( (string) $uploads_base['basedir'], '/' ) : '';
		$uploads_baseurl = ! empty( $uploads_base['baseurl'] ) ? rtrim( (string) $uploads_base['baseurl'], '/' ) : '';
		$abspath         = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );

		$items = array();
		foreach ( $slice as $abs ) {
			$rel = $abs;
			if ( '' !== $abspath && 0 === strpos( $abs, $abspath . '/' ) ) {
				$rel = substr( $abs, strlen( $abspath ) + 1 );
			}

			$url = '';
			if ( '' !== $uploads_basedir && 0 === strpos( $abs, $uploads_basedir . '/' ) ) {
				$url = $uploads_baseurl . '/' . ltrim( substr( $abs, strlen( $uploads_basedir ) ), '/' );
			}

			$items[] = array(
				'file_path'  => $rel,
				'file_url'   => $url,
				'size'       => (int) @filesize( $abs ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'sha256'     => self::sha256_of( $abs ),
				'created_at' => gmdate( 'c', (int) @filemtime( $abs ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Compute the SHA-256 of a file, or '' when it cannot be read. Skips large
	 * files gracefully.
	 */
	public static function sha256_of( string $abs_path ): string {
		if ( ! is_file( $abs_path ) || ! is_readable( $abs_path ) ) {
			return '';
		}
		$hash = @hash_file( 'sha256', $abs_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Convert an absolute path inside uploads/ into ABSPATH-relative form for
	 * transport to callers. Returns the input unchanged when it is already
	 * outside ABSPATH.
	 */
	public static function to_abspath_relative( string $abs_path ): string {
		$abspath = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );
		if ( '' !== $abspath && 0 === strpos( $abs_path, $abspath . '/' ) ) {
			return substr( $abs_path, strlen( $abspath ) + 1 );
		}
		return $abs_path;
	}

	/**
	 * Return the wp-uploads URL for an absolute path inside uploads/, or ''
	 * when it is not under uploads/.
	 */
	public static function url_for( string $abs_path ): string {
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}
		$basedir = rtrim( (string) $uploads['basedir'], '/' );
		$baseurl = rtrim( (string) $uploads['baseurl'], '/' );
		if ( 0 === strpos( $abs_path, $basedir . '/' ) ) {
			return $baseurl . '/' . ltrim( substr( $abs_path, strlen( $basedir ) ), '/' );
		}
		return '';
	}

	/**
	 * Ensure the given managed sub-dir exists inside uploads/ with hardening
	 * files. Returns the absolute path or false on failure.
	 *
	 * @return string|false
	 */
	private static function resolve_dir( string $subdir ) {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}
		$dir = trailingslashit( (string) $uploads['basedir'] ) . $subdir;
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Block PHP execution and disable directory listing, but allow
			// direct downloads of the finalized .zip files so the URLs returned
			// by Create_Zip_Backup / Download_Zip_Backup remain fetchable. Random filenames
			// provide the enumeration defense.
			$rules = "Options -Indexes\n"
				. "<FilesMatch \"\\.(php|phtml|phar|pl|py|jsp|asp|htm|html|shtml)$\">\n"
				. "\tDeny from all\n"
				. "\t<IfModule mod_authz_core.c>\n"
				. "\t\tRequire all denied\n"
				. "\t</IfModule>\n"
				. "</FilesMatch>\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$index_php = $dir . '/index.php';
		if ( ! file_exists( $index_php ) ) {
			@file_put_contents( $index_php, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $dir;
	}
}
