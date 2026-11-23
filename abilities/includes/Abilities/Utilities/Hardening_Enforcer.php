<?php
/**
 * Runtime enforcement of the Content Filters + sensitive-read denylist options
 * shipped as UI scaffold in PR #144 (feature 093).
 *
 * Split from {@see Hardening_Settings}: that class is the persistence layer
 * (option keys, defaults, sanitising getters/setters) — this one is the
 * runtime consumer. Every write-side file-manager ability calls
 * {@see check_write()} after the existing File_Mods_Guard + Path_Allowlist_Guard
 * checks; the single read ability that consults content (`file-manager/read-file`)
 * calls {@see check_read()} in the same position. Both return a ready-made
 * refusal envelope-or-null so the ability's execute() body reads:
 *
 *     $blocked = Hardening_Enforcer::check_write( $abs, $content );
 *     if ( null !== $blocked ) { return $blocked; }
 *
 * Both entrypoints take exactly one Hardening_Settings::get_content_filters()
 * snapshot per call and use that snapshot for every sub-check within the call —
 * no static caching between calls, so an admin flipping a toggle takes effect
 * on the very next ability invocation.
 *
 * Check ordering inside check_write() is best-cheap-first: extension blocklist →
 * double-extension → sanitize-filename → strict-filename → mime-type →
 * htaccess-directive → write-size. The enforcer only ever returns the FIRST
 * refusal — order matters for the blocked_reason admins see.
 *
 * See specs/093-file-manager-hardening/ for the full spec, contracts, and
 * quickstart probes.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Hardening_Enforcer — runtime consumer of the ten hardening options.
 */
final class Hardening_Enforcer {

	/**
	 * Directive names the .htaccess scanner refuses (case-insensitive substring).
	 *
	 * @var array<int,string>
	 */
	private const HTACCESS_DIRECTIVES = array(
		'AddType',
		'SetHandler',
		'php_value',
		'php_flag',
		'auto_prepend',
		'auto_append',
	);

	/**
	 * Webshell / suspicious-name substrings the strict filename filter refuses
	 * (case-insensitive substring match against the basename).
	 *
	 * @var array<int,string>
	 */
	private const STRICT_FILENAME_MARKERS = array(
		'c99',
		'r57',
		'wso',
		'b374k',
		'weevely',
		'shell',
		'alfa',
		'bypass',
		'backdoor',
	);

	/**
	 * Dotfile basenames the sanitize-filename check treats as always-allowed.
	 *
	 * WordPress's own sanitize_file_name() strips leading dots via
	 * `trim($filename, '.-_')`, so `.htaccess` → `htaccess`. Applied literally,
	 * the FR-005 roundtrip check would refuse every legitimate WP-adjacent
	 * dotfile — including `.htaccess`, blocking the FR-004 htaccess-directive
	 * scan from ever running. Mirrors the reference plugin's `$allowed_dotfiles`
	 * carve-out in `mcp-abilities-filesystem.php:221`.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_DOTFILES = array(
		'.htaccess',
		'.htpasswd',
		'.user.ini',
	);

	/**
	 * Extensions the MIME check treats as always-allowed even when
	 * wp_check_filetype() returns an empty type. Config-only / code / plain-text
	 * formats WordPress core historically leaves out of the default MIME
	 * allowlist but that a mu-plugin / theme deploy legitimately needs.
	 *
	 * @var array<int,string>
	 */
	private const MIME_ALWAYS_ALLOWED = array(
		'php',
		'txt',
		'log',
		'json',
		'xml',
		'css',
		'js',
		'md',
		'html',
		'htm',
		'htaccess',
	);

	/* -------------------------------------------------------------------- */
	/* Public entrypoints                                                    */
	/* -------------------------------------------------------------------- */

	/**
	 * Run every enabled write-side content-filter check.
	 *
	 * @param string              $absolute_path Absolute target path.
	 * @param string              $content       Bytes being written (empty for delete-like ops).
	 * @param array<string,mixed> $opts          Per-call context. Recognised keys:
	 *   - mode: 'create'|'edit'|'append'|'copy'|'move' (default: 'create')
	 *   - target_basename_override: string    — use this basename instead of
	 *     basename($absolute_path). Used by copy/move so checks apply to the
	 *     DESTINATION basename even when $absolute_path is the source.
	 *   - existing_size: int                  — for mode:append, the current
	 *     on-disk size in bytes; write-size cap uses existing + strlen($content).
	 *   - source_size: int                    — for mode:copy|move, the source
	 *     file size (used by the write-size cap).
	 *   - source_content_reader: callable():string — for mode:copy|move, a
	 *     lazy reader that returns the source file bytes. Called only if the
	 *     htaccess-directive scan needs to inspect content.
	 * @return array<string,mixed>|null Refusal envelope or null when allowed.
	 */
	public static function check_write( string $absolute_path, string $content = '', array $opts = array() ): ?array {
		$snapshot = Hardening_Settings::get_content_filters();

		$basename  = isset( $opts['target_basename_override'] ) && '' !== $opts['target_basename_override']
			? (string) $opts['target_basename_override']
			: basename( $absolute_path );
		$extension = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );
		$mode      = (string) ( $opts['mode'] ?? 'create' );

		// 1) Extension blocklist.
		if ( ! empty( $snapshot['dangerous_extensions'] ) && '' !== $extension ) {
			if ( in_array( $extension, (array) $snapshot['dangerous_extensions'], true ) ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'extension_blocked',
					'path'           => $absolute_path,
					'message'        => sprintf(
						/* translators: %s: file extension */
						__( 'File extension ".%s" is blocked by the Content Filters extension list.', 'acrossai-abilities-manager' ),
						$extension
					),
					'extension'      => $extension,
				);
			}
		}

		// 2) Double-extension refusal — PHP-like extension followed by another.
		if ( ! empty( $snapshot['block_double_extensions'] ) ) {
			if ( preg_match( '/\.(php|phtml|phar)\.[^.]+$/i', $basename ) ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'double_extension_blocked',
					'path'           => $absolute_path,
					'message'        => sprintf(
						/* translators: %s: file basename */
						__( 'File basename "%s" uses a blocked double extension.', 'acrossai-abilities-manager' ),
						$basename
					),
					'basename'       => $basename,
				);
			}
		}

		// 3) sanitize_file_name roundtrip.
		//    Legitimate WP-adjacent dotfiles (.htaccess, .htpasswd, .user.ini)
		//    are exempted — real WP's sanitize_file_name() strips the leading
		//    dot, so the check would otherwise refuse every valid dotfile and
		//    starve the .htaccess-directive scanner (FR-004) of any target.
		if ( ! empty( $snapshot['sanitize_filename_check'] ) && ! in_array( $basename, self::ALLOWED_DOTFILES, true ) ) {
			$sanitized = sanitize_file_name( $basename );
			if ( $sanitized !== $basename ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'filename_sanitize_failed',
					'path'           => $absolute_path,
					'message'        => sprintf(
						/* translators: 1: original input, 2: WordPress-sanitised variant */
						__( 'Filename fails the sanitize-filename check. WordPress would rename "%1$s" to "%2$s".', 'acrossai-abilities-manager' ),
						$basename,
						$sanitized
					),
					'input'          => $basename,
					'sanitized'      => $sanitized,
				);
			}
		}

		// 4) Strict-filename filter (opt-in — off by default).
		if ( ! empty( $snapshot['strict_filename_filter'] ) ) {
			$basename_lower = strtolower( $basename );
			foreach ( self::STRICT_FILENAME_MARKERS as $marker ) {
				if ( false !== strpos( $basename_lower, $marker ) ) {
					return array(
						'success'        => false,
						'blocked_reason' => 'filename_strict_blocked',
						'path'           => $absolute_path,
						'message'        => sprintf(
							/* translators: %s: matched marker */
							__( 'Filename contains the blocked marker "%s". Disable Strict filename filter in Settings → File Manager to allow.', 'acrossai-abilities-manager' ),
							$marker
						),
						'marker'         => $marker,
					);
				}
			}
		}

		// 5) MIME type check — skipped on append-file (extension didn't change).
		if ( ! empty( $snapshot['mime_type_check'] ) && 'append' !== $mode && '' !== $extension ) {
			if ( ! in_array( $extension, self::MIME_ALWAYS_ALLOWED, true ) ) {
				$filetype = wp_check_filetype( $basename );
				if ( empty( $filetype['type'] ) ) {
					return array(
						'success'        => false,
						'blocked_reason' => 'mime_type_blocked',
						'path'           => $absolute_path,
						'message'        => sprintf(
							/* translators: %s: file extension */
							__( 'Extension ".%s" is not in WordPress\'s allowed MIME types.', 'acrossai-abilities-manager' ),
							$extension
						),
						'extension'      => $extension,
					);
				}
			}
		}

		// 6) .htaccess directive scan — only when target basename is .htaccess.
		if ( ! empty( $snapshot['htaccess_directive_scan'] ) && '.htaccess' === $basename ) {
			$scan_content = self::resolve_scan_content( $content, $mode, $opts );
			if ( '' !== $scan_content ) {
				foreach ( self::HTACCESS_DIRECTIVES as $directive ) {
					if ( false !== stripos( $scan_content, $directive ) ) {
						return array(
							'success'        => false,
							'blocked_reason' => 'htaccess_directive_blocked',
							'path'           => $absolute_path,
							'message'        => sprintf(
								/* translators: %s: matched Apache directive name */
								__( 'Refused .htaccess write: content contains the blocked directive "%s".', 'acrossai-abilities-manager' ),
								$directive
							),
							'directive'      => $directive,
						);
					}
				}
			}
		}

		// 7) Write-size cap.
		$max_bytes = (int) ( $snapshot['write_max_bytes'] ?? 0 );
		if ( $max_bytes > 0 ) {
			$size = self::resolve_size( $content, $mode, $opts );
			if ( $size > $max_bytes ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'write_size_exceeded',
					'path'           => $absolute_path,
					'message'        => sprintf(
						/* translators: 1: observed size, 2: configured cap */
						__( 'Write size %1$d bytes exceeds the configured cap of %2$d bytes.', 'acrossai-abilities-manager' ),
						$size,
						$max_bytes
					),
					'size'           => $size,
					'max_bytes'      => $max_bytes,
				);
			}
		}

		return null;
	}

	/**
	 * Run the sensitive-read denylist check for `file-manager/read-file`.
	 *
	 * Per spec FR-011 this MUST run AFTER Path_Allowlist_Guard::blocked_read_response(),
	 * so allowlist refusals (path_not_allowed_for_read) take precedence.
	 *
	 * @param string $absolute_path Absolute target path.
	 * @return array<string,mixed>|null Refusal envelope or null.
	 */
	public static function check_read( string $absolute_path ): ?array {
		$snapshot = Hardening_Settings::get_content_filters();
		$denylist = (array) ( $snapshot['sensitive_read_denylist'] ?? array() );
		if ( empty( $denylist ) ) {
			return null;
		}

		$basename          = basename( $absolute_path );
		$basename_lower    = strtolower( $basename );
		$extension_lower   = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );

		foreach ( $denylist as $entry ) {
			if ( ! is_string( $entry ) || '' === $entry ) {
				continue;
			}

			// *.EXT globs — case-insensitive extension match.
			if ( 0 === strpos( $entry, '*.' ) ) {
				$glob_ext = strtolower( substr( $entry, 2 ) );
				if ( '' !== $glob_ext && $glob_ext === $extension_lower ) {
					return self::sensitive_read_envelope( $absolute_path, $basename, $entry );
				}
				continue;
			}

			// Literal basename — case-sensitive match (spec User Story 2 AS4).
			if ( $entry === $basename ) {
				return self::sensitive_read_envelope( $absolute_path, $basename, $entry );
			}
			// Also match on lowercase-lowercase when the entry itself is lowercase-only —
			// no-op here because the equality above already handles it; kept explicit
			// for readers who wonder about case handling.
			unset( $basename_lower ); // silence unused-var warnings from static analysers.
		}

		return null;
	}

	/* -------------------------------------------------------------------- */
	/* Internal helpers                                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Build the sensitive-read refusal envelope.
	 *
	 * @param string $absolute_path   Absolute target path.
	 * @param string $basename        Target basename.
	 * @param string $matched_pattern Entry from the denylist that matched.
	 * @return array<string,mixed>
	 */
	private static function sensitive_read_envelope( string $absolute_path, string $basename, string $matched_pattern ): array {
		$message = 0 === strpos( $matched_pattern, '*.' )
			? sprintf(
				/* translators: 1: basename, 2: matched glob pattern */
				__( 'Reads of "%1$s" are refused by the Content Filters sensitive-read denylist (matched pattern %2$s).', 'acrossai-abilities-manager' ),
				$basename,
				$matched_pattern
			)
			: sprintf(
				/* translators: %s: basename */
				__( 'Reads of "%s" are refused by the Content Filters sensitive-read denylist.', 'acrossai-abilities-manager' ),
				$basename
			);

		return array(
			'success'         => false,
			'blocked_reason'  => 'sensitive_read_blocked',
			'path'            => $absolute_path,
			'message'         => $message,
			'basename'        => $basename,
			'matched_pattern' => $matched_pattern,
		);
	}

	/**
	 * Resolve which content to feed the .htaccess directive scanner for the
	 * current call mode.
	 *
	 * - create / edit: $content (the raw bytes being written).
	 * - append:        $content (only the appended bytes — spec FR-004).
	 * - copy / move:   the source file content via $opts['source_content_reader'].
	 *
	 * @param string              $content Bytes being written.
	 * @param string              $mode    Call mode.
	 * @param array<string,mixed> $opts    Per-call options.
	 * @return string
	 */
	private static function resolve_scan_content( string $content, string $mode, array $opts ): string {
		if ( 'copy' === $mode || 'move' === $mode ) {
			$reader = $opts['source_content_reader'] ?? null;
			if ( is_callable( $reader ) ) {
				$result = $reader();
				return is_string( $result ) ? $result : '';
			}
			return '';
		}
		return $content;
	}

	/**
	 * Resolve the byte count the write-size cap checks against.
	 *
	 * - create / edit: strlen($content).
	 * - append:        existing_size + strlen($content) (new_size).
	 * - copy / move:   source_size.
	 *
	 * @param string              $content Bytes being written.
	 * @param string              $mode    Call mode.
	 * @param array<string,mixed> $opts    Per-call options.
	 * @return int
	 */
	private static function resolve_size( string $content, string $mode, array $opts ): int {
		if ( 'append' === $mode ) {
			return (int) ( $opts['existing_size'] ?? 0 ) + strlen( $content );
		}
		if ( 'copy' === $mode || 'move' === $mode ) {
			return (int) ( $opts['source_size'] ?? 0 );
		}
		return strlen( $content );
	}
}
