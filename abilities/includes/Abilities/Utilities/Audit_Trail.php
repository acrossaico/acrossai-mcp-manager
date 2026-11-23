<?php
/**
 * Audit trail — pre-image backup + append-only log for every file-manager
 * mutation (feature 094).
 *
 * Reads the four Backup & Audit option keys shipped as UI scaffold in PR
 * #144 and enforced-toggle-only in PR #146. When enabled, every mutation
 * via the ten affected file-manager abilities writes:
 *
 *   - a pre-image backup into wp-content/acrossai-file-manager-backups/<YYYY-MM-DD>/
 *     (8 of the 10 abilities — mkdir/rmdir have no content to back up),
 *   - a log entry appended to wp-content/acrossai-file-manager-logs/acrossai-file-manager.log
 *     (all 10 abilities).
 *
 * Both storage locations get a `.htaccess` (`Deny from all`) on first
 * creation. Retention is amortised via a 1-in-10 wp_rand trigger inside
 * write_log() — no separate WP-Cron event.
 *
 * See specs/094-file-manager-audit-log/ for the full spec, contracts, and
 * quickstart probes. See also the reference implementation at
 * wp-content/plugins/mcp-abilities-filesystem/mcp-abilities-filesystem.php
 * lines 54–188.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Audit_Trail — backup + log + cleanup + stats for file-manager mutations.
 */
final class Audit_Trail {

	/** Directory (relative to wp-content) where date-bucket backup dirs live. */
	private const BACKUP_SUBDIR = 'acrossai-file-manager-backups';

	/** Directory (relative to wp-content) where the log file lives. */
	private const LOG_SUBDIR = 'acrossai-file-manager-logs';

	/** Log filename inside the log dir. */
	private const LOG_FILENAME = 'acrossai-file-manager.log';

	/** Hard cap on same-second collision suffixes before giving up. */
	private const COLLISION_SUFFIX_MAX = 100;

	/** Hard cap on stored context length (schema also enforces 2000-char inbound). */
	private const CONTEXT_STORE_MAX = 500;

	/** Amortised cleanup trigger: 1-in-N per log write. Reference plugin uses 10. */
	private const CLEANUP_DIE_ROLL = 10;

	/* -------------------------------------------------------------------- */
	/* Path accessors                                                        */
	/* -------------------------------------------------------------------- */

	/**
	 * Absolute path to the backup base directory (does NOT include today's date).
	 *
	 * @return string
	 */
	public static function backup_base_dir(): string {
		return WP_CONTENT_DIR . '/' . self::BACKUP_SUBDIR;
	}

	/**
	 * Absolute path to today's backup directory.
	 *
	 * @return string
	 */
	public static function backup_today_dir(): string {
		return self::backup_base_dir() . '/' . gmdate( 'Y-m-d' );
	}

	/**
	 * Absolute path to the log file.
	 *
	 * @return string
	 */
	public static function log_path(): string {
		return WP_CONTENT_DIR . '/' . self::LOG_SUBDIR . '/' . self::LOG_FILENAME;
	}

	/**
	 * Absolute path to the log directory.
	 *
	 * @return string
	 */
	public static function log_dir(): string {
		return WP_CONTENT_DIR . '/' . self::LOG_SUBDIR;
	}

	/* -------------------------------------------------------------------- */
	/* Backup writer                                                         */
	/* -------------------------------------------------------------------- */

	/**
	 * Write a pre-image backup of the file at $absolute_path.
	 *
	 * Returns null when there's nothing to back up (target doesn't exist —
	 * fresh create). Returns false on I/O failure. Returns the absolute
	 * backup path on success.
	 *
	 * MUST NOT block the calling ability. Callers are expected to record
	 * the return value in the log entry ("SKIPPED (target did not exist)"
	 * for null, "FAILED (...)" for false, the path for success) and proceed
	 * with the primary write regardless.
	 *
	 * @param string              $absolute_path Absolute path of the pre-image target.
	 * @param array<string,mixed> $opts          Reserved for future use.
	 * @return string|false|null
	 */
	public static function write_backup( string $absolute_path, array $opts = array() ): string|false|null {
		unset( $opts ); // reserved.
		$snapshot = Hardening_Settings::get_backup_audit();
		if ( empty( $snapshot['backup_enabled'] ) ) {
			return null;
		}

		if ( ! self::wp_filesystem_ready() ) {
			return false;
		}
		global $wp_filesystem;

		if ( ! $wp_filesystem->exists( $absolute_path ) || ! $wp_filesystem->is_file( $absolute_path ) ) {
			return null;
		}

		$day_dir = self::backup_today_dir();
		if ( ! $wp_filesystem->is_dir( $day_dir ) ) {
			if ( ! wp_mkdir_p( $day_dir ) ) {
				return false;
			}
			self::ensure_htaccess_guard( self::backup_base_dir() );
		}

		$basename    = basename( $absolute_path );
		$base_target = $day_dir . '/' . $basename . '.bak.' . gmdate( 'His' );
		$target      = $base_target;
		$counter     = 1;
		while ( $wp_filesystem->exists( $target ) ) {
			if ( $counter > self::COLLISION_SUFFIX_MAX ) {
				return false;
			}
			$target = $base_target . '.' . $counter;
			++$counter;
		}

		if ( ! $wp_filesystem->copy( $absolute_path, $target, true, FS_CHMOD_FILE ) ) {
			return false;
		}

		return $target;
	}

	/* -------------------------------------------------------------------- */
	/* Log writer                                                            */
	/* -------------------------------------------------------------------- */

	/**
	 * Append one audit-log entry for a mutation.
	 *
	 * No-ops silently when audit_log_enabled is false. Errors never surface
	 * to the caller — a filesystem that can't be written swallows the entry
	 * and the mutation continues.
	 *
	 * At the end of a successful log write, fires
	 * do_action('acrossai_file_manager_log_entry', $parsed_entry) and
	 * probabilistically triggers maybe_cleanup().
	 *
	 * @param string              $operation     Uppercase operation label (CREATE, EDIT, …).
	 * @param string              $absolute_path Absolute path of the mutation target.
	 * @param array<string,mixed> $details       Optional extras:
	 *   - ability_slug: file-manager/<slug> (defaults to file-manager/unknown)
	 *   - size_before: int|null
	 *   - size_after: int|null
	 *   - destination: string|null (move/copy only)
	 *   - backup_status: 'written'|'skipped'|'failed'|'disabled' (defaults to 'disabled')
	 *   - backup_reason: string (only used when status !== 'written')
	 *   - backup_path: string|null (only used when status === 'written')
	 *   - context: string (caller-supplied; sanitised and truncated here)
	 * @return void
	 */
	public static function write_log( string $operation, string $absolute_path, array $details = array() ): void {
		$snapshot = Hardening_Settings::get_backup_audit();
		if ( empty( $snapshot['audit_log_enabled'] ) ) {
			return;
		}

		if ( ! self::wp_filesystem_ready() ) {
			return;
		}
		global $wp_filesystem;

		$dir = self::log_dir();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return;
			}
			self::ensure_htaccess_guard( $dir );
		}

		$parsed = self::normalise_entry( $operation, $absolute_path, $details );
		$text   = self::format_entry( $parsed );

		$log_file = self::log_path();
		$existing = $wp_filesystem->exists( $log_file ) ? (string) $wp_filesystem->get_contents( $log_file ) : '';
		$sep      = ( '' === $existing ) ? '' : "\n";
		if ( ! $wp_filesystem->put_contents( $log_file, $existing . $sep . $text, FS_CHMOD_FILE ) ) {
			return;
		}

		/**
		 * Fires after a file-manager audit log entry is written.
		 *
		 * Subscribers may forward the entry to Slack, Datadog, a SIEM, etc.
		 *
		 * @since 0.1.0
		 * @param array<string,mixed> $entry Parsed entry array — see the LogEntry
		 *                                   shape in data-model.md.
		 */
		do_action( 'acrossai_file_manager_log_entry', $parsed );

		self::maybe_cleanup();
	}

	/* -------------------------------------------------------------------- */
	/* Cleanup                                                               */
	/* -------------------------------------------------------------------- */

	/**
	 * Probabilistic cleanup entrypoint. 1-in-10 chance per log write.
	 *
	 * @return void
	 */
	public static function maybe_cleanup(): void {
		if ( wp_rand( 1, self::CLEANUP_DIE_ROLL ) !== 1 ) {
			return;
		}
		self::run_cleanup_now();
	}

	/**
	 * Unconditional cleanup — public so tests and admins can force it.
	 *
	 * @return void
	 */
	public static function run_cleanup_now(): void {
		$snapshot = Hardening_Settings::get_backup_audit();
		self::cleanup_backups( (int) ( $snapshot['backup_retention_days'] ?? 7 ) );
		self::trim_log( (int) ( $snapshot['audit_log_retention_days'] ?? 7 ) );
	}

	/**
	 * Delete backup date-dirs older than $retention_days.
	 *
	 * @param int $retention_days Retention window in days.
	 * @return void
	 */
	private static function cleanup_backups( int $retention_days ): void {
		if ( ! self::wp_filesystem_ready() ) {
			return;
		}
		global $wp_filesystem;

		$base = self::backup_base_dir();
		if ( ! $wp_filesystem->is_dir( $base ) ) {
			return;
		}

		$cutoff = strtotime( '-' . $retention_days . ' days UTC' );
		if ( false === $cutoff ) {
			return;
		}

		$children = $wp_filesystem->dirlist( $base );
		if ( ! is_array( $children ) ) {
			return;
		}

		foreach ( $children as $name => $meta ) {
			if ( ! is_array( $meta ) || 'd' !== ( $meta['type'] ?? '' ) ) {
				continue;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $name ) ) {
				continue;
			}
			$day_ts = strtotime( $name . ' UTC' );
			if ( false === $day_ts || $day_ts >= $cutoff ) {
				continue;
			}
			$wp_filesystem->rmdir( $base . '/' . $name, true );
		}
	}

	/**
	 * Trim log entries older than $retention_days from the log file.
	 *
	 * @param int $retention_days Retention window in days.
	 * @return void
	 */
	private static function trim_log( int $retention_days ): void {
		if ( ! self::wp_filesystem_ready() ) {
			return;
		}
		global $wp_filesystem;

		$log_file = self::log_path();
		if ( ! $wp_filesystem->exists( $log_file ) ) {
			return;
		}

		$contents = (string) $wp_filesystem->get_contents( $log_file );
		if ( '' === $contents ) {
			return;
		}

		$cutoff = strtotime( '-' . $retention_days . ' days UTC' );
		if ( false === $cutoff ) {
			return;
		}

		$blocks = preg_split( '/\n{2,}/', trim( $contents ) );
		if ( ! is_array( $blocks ) ) {
			return;
		}

		$kept = array();
		foreach ( $blocks as $block ) {
			if ( '' === trim( $block ) ) {
				continue;
			}
			$header_ts = self::parse_entry_timestamp( $block );
			if ( null === $header_ts || $header_ts >= $cutoff ) {
				$kept[] = $block;
			}
		}

		$rewrite = implode( "\n\n", $kept );
		if ( '' !== $rewrite ) {
			$rewrite .= "\n";
		}
		$wp_filesystem->put_contents( $log_file, $rewrite, FS_CHMOD_FILE );
	}

	/* -------------------------------------------------------------------- */
	/* Stats — for the /backup-audit-stats REST endpoint                     */
	/* -------------------------------------------------------------------- */

	/**
	 * Point-in-time storage stats for the admin panel's info line.
	 *
	 * @return array<string,mixed>
	 */
	public static function stats(): array {
		$out = array(
			'log_path'                  => self::log_path(),
			'log_total_lines'           => 0,
			'log_size_bytes'            => 0,
			'log_last_entry_timestamp'  => null,
			'backup_base_dir'           => self::backup_base_dir(),
			'backup_days_present'       => 0,
			'backup_total_size_bytes'   => 0,
		);

		if ( ! self::wp_filesystem_ready() ) {
			return $out;
		}
		global $wp_filesystem;

		$log_file = self::log_path();
		if ( $wp_filesystem->exists( $log_file ) ) {
			$contents = (string) $wp_filesystem->get_contents( $log_file );
			if ( '' !== $contents ) {
				$blocks = preg_split( '/\n{2,}/', trim( $contents ) );
				if ( is_array( $blocks ) ) {
					$blocks               = array_values( array_filter( $blocks, static fn( string $b ): bool => '' !== trim( $b ) ) );
					$out['log_total_lines'] = count( $blocks );
					if ( count( $blocks ) > 0 ) {
						$last                              = $blocks[ count( $blocks ) - 1 ];
						$out['log_last_entry_timestamp']   = self::parse_entry_timestamp_string( $last );
					}
				}
			}
			$out['log_size_bytes'] = (int) $wp_filesystem->size( $log_file );
		}

		$base = self::backup_base_dir();
		if ( $wp_filesystem->is_dir( $base ) ) {
			$children = $wp_filesystem->dirlist( $base );
			if ( is_array( $children ) ) {
				foreach ( $children as $name => $meta ) {
					if ( ! is_array( $meta ) || 'd' !== ( $meta['type'] ?? '' ) ) {
						continue;
					}
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $name ) ) {
						continue;
					}
					++$out['backup_days_present'];
					$day_dir       = $base . '/' . $name;
					$day_children  = $wp_filesystem->dirlist( $day_dir );
					if ( is_array( $day_children ) ) {
						foreach ( $day_children as $file_meta ) {
							if ( is_array( $file_meta ) && 'f' === ( $file_meta['type'] ?? '' ) ) {
								$out['backup_total_size_bytes'] += (int) ( $file_meta['size'] ?? 0 );
							}
						}
					}
				}
			}
		}

		return $out;
	}

	/* -------------------------------------------------------------------- */
	/* Internal helpers                                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Normalise the caller-supplied details into the canonical parsed shape
	 * used both for `do_action` and for the on-disk format.
	 *
	 * @param string              $operation     Uppercase operation label.
	 * @param string              $absolute_path Absolute target path.
	 * @param array<string,mixed> $details       Caller details.
	 * @return array<string,mixed>
	 */
	private static function normalise_entry( string $operation, string $absolute_path, array $details ): array {
		$user     = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$user_email = ( $user && isset( $user->user_email ) ) ? (string) $user->user_email : '';
		$user_id    = ( $user && isset( $user->ID ) ) ? (int) $user->ID : 0;

		$ip = 'unknown';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$backup_status = (string) ( $details['backup_status'] ?? 'disabled' );
		$backup_path   = null;
		$backup_reason = '';
		if ( 'written' === $backup_status ) {
			$backup_path = (string) ( $details['backup_path'] ?? '' );
		} elseif ( in_array( $backup_status, array( 'skipped', 'failed' ), true ) ) {
			$backup_reason = (string) ( $details['backup_reason'] ?? '' );
		}

		$context_raw = (string) ( $details['context'] ?? '' );
		$context     = substr( sanitize_text_field( $context_raw ), 0, self::CONTEXT_STORE_MAX );

		return array(
			'timestamp_utc' => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'operation'     => strtoupper( $operation ),
			'ability_slug'  => (string) ( $details['ability_slug'] ?? 'file-manager/unknown' ),
			'path'          => $absolute_path,
			'user_email'    => $user_email,
			'user_id'       => $user_id,
			'ip'            => $ip,
			'size_before'   => array_key_exists( 'size_before', $details ) ? $details['size_before'] : null,
			'size_after'    => array_key_exists( 'size_after', $details ) ? $details['size_after'] : null,
			'destination'   => array_key_exists( 'destination', $details ) ? (string) $details['destination'] : null,
			'backup_path'   => $backup_path,
			'backup_status' => $backup_status,
			'backup_reason' => $backup_reason,
			'context'       => $context,
		);
	}

	/**
	 * Format one parsed entry into the on-disk text shape.
	 *
	 * @param array<string,mixed> $e Parsed entry.
	 * @return string
	 */
	private static function format_entry( array $e ): string {
		$lines   = array();
		$lines[] = '[' . $e['timestamp_utc'] . '] ' . $e['operation'];
		$lines[] = '  Ability: ' . $e['ability_slug'];
		$lines[] = '  File: ' . $e['path'];
		$lines[] = '  User: ' . $e['user_email'] . ' (ID:' . $e['user_id'] . ') IP:' . $e['ip'];
		$lines[] = '  Size: ' . self::size_str( $e['size_before'] ) . ' -> ' . self::size_str( $e['size_after'] ) . ' bytes';
		if ( null !== $e['destination'] && '' !== $e['destination'] ) {
			$lines[] = '  Destination: ' . $e['destination'];
		}
		$lines[] = '  Backup: ' . self::backup_line( $e );
		$lines[] = '  Context: ' . $e['context'];

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Render the Size field's before/after cell — int or "n/a".
	 *
	 * @param mixed $value Raw size value.
	 * @return string
	 */
	private static function size_str( $value ): string {
		return ( null === $value ) ? 'n/a' : (string) (int) $value;
	}

	/**
	 * Render the Backup field's single line.
	 *
	 * @param array<string,mixed> $entry Parsed entry.
	 * @return string
	 */
	private static function backup_line( array $entry ): string {
		switch ( $entry['backup_status'] ) {
			case 'written':
				return (string) $entry['backup_path'];
			case 'skipped':
				return 'SKIPPED (' . ( $entry['backup_reason'] ?: 'unspecified' ) . ')';
			case 'failed':
				return 'FAILED (' . ( $entry['backup_reason'] ?: 'unspecified' ) . ')';
			case 'disabled':
			default:
				return 'DISABLED';
		}
	}

	/**
	 * Extract the timestamp from an entry's header line as a UNIX timestamp.
	 *
	 * @param string $block Full multi-line entry block.
	 * @return int|null Unix timestamp or null when unparseable.
	 */
	private static function parse_entry_timestamp( string $block ): ?int {
		$header = strtok( $block, "\n" );
		if ( ! is_string( $header ) ) {
			return null;
		}
		if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\]/', $header, $m ) ) {
			return null;
		}
		$ts = strtotime( $m[1] . ' UTC' );
		return ( false === $ts ) ? null : $ts;
	}

	/**
	 * Extract the raw timestamp string from an entry's header.
	 *
	 * @param string $block Entry block.
	 * @return string|null
	 */
	private static function parse_entry_timestamp_string( string $block ): ?string {
		$header = strtok( $block, "\n" );
		if ( ! is_string( $header ) ) {
			return null;
		}
		if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC)\]/', $header, $m ) ) {
			return null;
		}
		return $m[1];
	}

	/**
	 * Write a `Deny from all` .htaccess into $dir if not present.
	 *
	 * @param string $dir Directory to guard.
	 * @return void
	 */
	private static function ensure_htaccess_guard( string $dir ): void {
		if ( ! self::wp_filesystem_ready() ) {
			return;
		}
		global $wp_filesystem;

		$file = rtrim( $dir, '/' ) . '/.htaccess';
		if ( $wp_filesystem->exists( $file ) ) {
			return;
		}
		$wp_filesystem->put_contents( $file, "Deny from all\n", FS_CHMOD_FILE );
	}

	/**
	 * Ensure WP_Filesystem is initialised. Returns false when unavailable.
	 *
	 * @return bool
	 */
	private static function wp_filesystem_ready(): bool {
		global $wp_filesystem;
		if ( is_object( $wp_filesystem ) ) {
			return true;
		}
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return false;
		}
		return is_object( Wp_Filesystem_Init::get() );
	}
}
