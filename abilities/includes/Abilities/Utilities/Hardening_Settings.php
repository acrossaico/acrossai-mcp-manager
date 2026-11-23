<?php
/**
 * Hardening & audit settings — SCAFFOLD ONLY.
 *
 * Holds option keys, defaults, and sanitising getters/setters for the twelve
 * settings that back the File Manager tab's new Content Filters and Backup &
 * Audit panels. Values persist to wp_options via the standard settings-tab
 * REST controller; enforcement is deliberately NOT wired here — see feature
 * 093 (content filters) and feature 094 (backup + audit) for the runtime
 * changes that will read from these keys.
 *
 * Layout mirrors {@see Path_Allowlist_Guard}: one public const per option
 * name + a matching DEFAULT_* const + a small getter/setter pair with the
 * sanitiser inlined in the setter.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Hardening_Settings — persistence layer for the scaffolded hardening options.
 */
final class Hardening_Settings {

	/* -------------------------------------------------------------------- */
	/* Content-filter option keys (feature 093 will consume these)           */
	/* -------------------------------------------------------------------- */

	/** Extension blocklist. Values are lowercase, no leading dot. */
	public const OPTION_DANGEROUS_EXTENSIONS = 'acrossai_file_manager_dangerous_extensions';

	/** Reject double extensions like foo.php.jpg. */
	public const OPTION_BLOCK_DOUBLE_EXTENSIONS = 'acrossai_file_manager_block_double_extensions';

	/** Scan .htaccess content for dangerous directives (AddType, php_value, …). */
	public const OPTION_HTACCESS_DIRECTIVE_SCAN = 'acrossai_file_manager_htaccess_directive_scan';

	/** Refuse when sanitize_file_name() would rename the input. */
	public const OPTION_SANITIZE_FILENAME_CHECK = 'acrossai_file_manager_sanitize_filename_check';

	/** Max bytes per write ability (create-file, edit-file, append-file). */
	public const OPTION_WRITE_MAX_BYTES = 'acrossai_file_manager_write_max_bytes';

	/**
	 * Path/name patterns that must never be readable, regardless of the
	 * read allowlist. Values are lowercase basenames or `*.ext` globs.
	 */
	public const OPTION_SENSITIVE_READ_DENYLIST = 'acrossai_file_manager_sensitive_read_denylist';

	/** Tier B: block filenames containing common webshell markers (c99, r57, …). */
	public const OPTION_STRICT_FILENAME_FILTER = 'acrossai_file_manager_strict_filename_filter';

	/** Tier B: validate write filenames through wp_check_filetype(). */
	public const OPTION_MIME_TYPE_CHECK = 'acrossai_file_manager_mime_type_check';

	/* -------------------------------------------------------------------- */
	/* Backup + audit option keys (feature 094 will consume these)           */
	/* -------------------------------------------------------------------- */

	/** Append every file-manager mutation to wp-content/acrossai-file-manager.log. */
	public const OPTION_AUDIT_LOG_ENABLED = 'acrossai_file_manager_audit_log_enabled';

	/** Trim audit log entries older than N days. Range: 1–90. */
	public const OPTION_AUDIT_LOG_RETENTION_DAYS = 'acrossai_file_manager_audit_log_retention_days';

	/** Copy the pre-image of every mutation into wp-content/acrossai-file-manager-backups/<YYYY-MM-DD>/. */
	public const OPTION_BACKUP_ENABLED = 'acrossai_file_manager_backup_enabled';

	/** Remove backup folders older than N days. Range: 1–90. */
	public const OPTION_BACKUP_RETENTION_DAYS = 'acrossai_file_manager_backup_retention_days';

	/* -------------------------------------------------------------------- */
	/* Defaults                                                              */
	/* -------------------------------------------------------------------- */

	/** @var array<int,string> */
	public const DEFAULT_DANGEROUS_EXTENSIONS = array( 'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'cgi', 'pl', 'py', 'phar' );

	public const DEFAULT_BLOCK_DOUBLE_EXTENSIONS = true;
	public const DEFAULT_HTACCESS_DIRECTIVE_SCAN = true;
	public const DEFAULT_SANITIZE_FILENAME_CHECK = true;

	/** 10 MiB — matches the reference plugin. */
	public const DEFAULT_WRITE_MAX_BYTES = 10485760;

	/** @var array<int,string> */
	public const DEFAULT_SENSITIVE_READ_DENYLIST = array(
		'.env',
		'.env.local',
		'.env.production',
		'.env.development',
		'id_rsa',
		'id_dsa',
		'authorized_keys',
		'*.key',
		'*.pem',
		'*.p12',
		'*.pfx',
		'*.crt',
	);

	public const DEFAULT_STRICT_FILENAME_FILTER = false;
	public const DEFAULT_MIME_TYPE_CHECK        = false;

	public const DEFAULT_AUDIT_LOG_ENABLED        = false;
	public const DEFAULT_AUDIT_LOG_RETENTION_DAYS = 7;
	public const DEFAULT_BACKUP_ENABLED           = false;
	public const DEFAULT_BACKUP_RETENTION_DAYS    = 7;

	/** Retention range enforced by the sanitisers. */
	public const MIN_RETENTION_DAYS = 1;
	public const MAX_RETENTION_DAYS = 90;

	/** Write-size ceiling: 1 KiB minimum, 100 MiB maximum. */
	public const MIN_WRITE_MAX_BYTES = 1024;
	public const MAX_WRITE_MAX_BYTES = 104857600;

	/* -------------------------------------------------------------------- */
	/* Aggregate accessors                                                   */
	/* -------------------------------------------------------------------- */

	/**
	 * Snapshot of all content-filter settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_content_filters(): array {
		return array(
			'dangerous_extensions'     => self::get_string_list( self::OPTION_DANGEROUS_EXTENSIONS, self::DEFAULT_DANGEROUS_EXTENSIONS ),
			'block_double_extensions'  => self::get_bool( self::OPTION_BLOCK_DOUBLE_EXTENSIONS, self::DEFAULT_BLOCK_DOUBLE_EXTENSIONS ),
			'htaccess_directive_scan'  => self::get_bool( self::OPTION_HTACCESS_DIRECTIVE_SCAN, self::DEFAULT_HTACCESS_DIRECTIVE_SCAN ),
			'sanitize_filename_check'  => self::get_bool( self::OPTION_SANITIZE_FILENAME_CHECK, self::DEFAULT_SANITIZE_FILENAME_CHECK ),
			'write_max_bytes'          => self::get_write_max_bytes(),
			'sensitive_read_denylist'  => self::get_string_list( self::OPTION_SENSITIVE_READ_DENYLIST, self::DEFAULT_SENSITIVE_READ_DENYLIST ),
			'strict_filename_filter'   => self::get_bool( self::OPTION_STRICT_FILENAME_FILTER, self::DEFAULT_STRICT_FILENAME_FILTER ),
			'mime_type_check'          => self::get_bool( self::OPTION_MIME_TYPE_CHECK, self::DEFAULT_MIME_TYPE_CHECK ),
		);
	}

	/**
	 * Snapshot of all backup + audit settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_backup_audit(): array {
		return array(
			'audit_log_enabled'         => self::get_bool( self::OPTION_AUDIT_LOG_ENABLED, self::DEFAULT_AUDIT_LOG_ENABLED ),
			'audit_log_retention_days'  => self::get_retention_days( self::OPTION_AUDIT_LOG_RETENTION_DAYS, self::DEFAULT_AUDIT_LOG_RETENTION_DAYS ),
			'backup_enabled'            => self::get_bool( self::OPTION_BACKUP_ENABLED, self::DEFAULT_BACKUP_ENABLED ),
			'backup_retention_days'     => self::get_retention_days( self::OPTION_BACKUP_RETENTION_DAYS, self::DEFAULT_BACKUP_RETENTION_DAYS ),
		);
	}

	/**
	 * Persist a content-filters payload from the REST controller.
	 *
	 * Unknown keys are ignored; the two list-typed keys pass through their
	 * sanitiser, which additionally reports any entries it dropped. Result
	 * shape:
	 *
	 *     [
	 *         'config'  => <same shape as get_content_filters()>,
	 *         'skipped' => [
	 *             ['field' => 'dangerous_extensions', 'value' => 'in valid', 'reason' => 'invalid_format'],
	 *             ...
	 *         ],
	 *     ]
	 *
	 * The React panel renders the `skipped` list as a WP admin notice so the
	 * admin sees exactly what got dropped and why (matches the UX the
	 * server-rendered Mime Types field surfaces via add_settings_error).
	 *
	 * @param array<string,mixed> $input Raw payload.
	 * @return array{config: array<string,mixed>, skipped: array<int,array{field:string,value:string,reason:string}>}
	 */
	public static function set_content_filters( array $input ): array {
		$skipped = array();

		if ( array_key_exists( 'dangerous_extensions', $input ) ) {
			list( $sanitized, $drops ) = self::sanitize_extension_list_verbose( (array) $input['dangerous_extensions'] );
			update_option( self::OPTION_DANGEROUS_EXTENSIONS, $sanitized );
			foreach ( $drops as $drop ) {
				$skipped[] = array(
					'field'  => 'dangerous_extensions',
					'value'  => $drop['value'],
					'reason' => $drop['reason'],
				);
			}
		}
		if ( array_key_exists( 'block_double_extensions', $input ) ) {
			update_option( self::OPTION_BLOCK_DOUBLE_EXTENSIONS, self::coerce_bool( $input['block_double_extensions'] ) );
		}
		if ( array_key_exists( 'htaccess_directive_scan', $input ) ) {
			update_option( self::OPTION_HTACCESS_DIRECTIVE_SCAN, self::coerce_bool( $input['htaccess_directive_scan'] ) );
		}
		if ( array_key_exists( 'sanitize_filename_check', $input ) ) {
			update_option( self::OPTION_SANITIZE_FILENAME_CHECK, self::coerce_bool( $input['sanitize_filename_check'] ) );
		}
		if ( array_key_exists( 'write_max_bytes', $input ) ) {
			update_option( self::OPTION_WRITE_MAX_BYTES, self::clamp_write_max_bytes( $input['write_max_bytes'] ) );
		}
		if ( array_key_exists( 'sensitive_read_denylist', $input ) ) {
			list( $sanitized, $drops ) = self::sanitize_pattern_list_verbose( (array) $input['sensitive_read_denylist'] );
			update_option( self::OPTION_SENSITIVE_READ_DENYLIST, $sanitized );
			foreach ( $drops as $drop ) {
				$skipped[] = array(
					'field'  => 'sensitive_read_denylist',
					'value'  => $drop['value'],
					'reason' => $drop['reason'],
				);
			}
		}
		if ( array_key_exists( 'strict_filename_filter', $input ) ) {
			update_option( self::OPTION_STRICT_FILENAME_FILTER, self::coerce_bool( $input['strict_filename_filter'] ) );
		}
		if ( array_key_exists( 'mime_type_check', $input ) ) {
			update_option( self::OPTION_MIME_TYPE_CHECK, self::coerce_bool( $input['mime_type_check'] ) );
		}

		return array(
			'config'  => self::get_content_filters(),
			'skipped' => $skipped,
		);
	}

	/**
	 * Persist a backup + audit payload from the REST controller.
	 *
	 * @param array<string,mixed> $input Raw payload.
	 * @return array<string,mixed> Snapshot after write.
	 */
	public static function set_backup_audit( array $input ): array {
		if ( array_key_exists( 'audit_log_enabled', $input ) ) {
			update_option( self::OPTION_AUDIT_LOG_ENABLED, self::coerce_bool( $input['audit_log_enabled'] ) );
		}
		if ( array_key_exists( 'audit_log_retention_days', $input ) ) {
			update_option( self::OPTION_AUDIT_LOG_RETENTION_DAYS, self::clamp_retention_days( $input['audit_log_retention_days'] ) );
		}
		if ( array_key_exists( 'backup_enabled', $input ) ) {
			update_option( self::OPTION_BACKUP_ENABLED, self::coerce_bool( $input['backup_enabled'] ) );
		}
		if ( array_key_exists( 'backup_retention_days', $input ) ) {
			update_option( self::OPTION_BACKUP_RETENTION_DAYS, self::clamp_retention_days( $input['backup_retention_days'] ) );
		}
		return self::get_backup_audit();
	}

	/* -------------------------------------------------------------------- */
	/* Internal helpers                                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Get a bool option with a typed default.
	 *
	 * @param string $key     Option name.
	 * @param bool   $default Default value.
	 * @return bool
	 */
	private static function get_bool( string $key, bool $default ): bool {
		$raw = get_option( $key, $default );
		return self::coerce_bool( $raw );
	}

	/**
	 * Get a string-list option, running the elements through the shared
	 * sanitiser so a hand-edited option row can't smuggle in garbage.
	 *
	 * @param string           $key     Option name.
	 * @param array<int,string> $default Default list.
	 * @return array<int,string>
	 */
	private static function get_string_list( string $key, array $default ): array {
		$raw = get_option( $key, $default );
		if ( ! is_array( $raw ) ) {
			return $default;
		}
		if ( self::OPTION_DANGEROUS_EXTENSIONS === $key ) {
			return self::sanitize_extension_list( $raw );
		}
		return self::sanitize_pattern_list( $raw );
	}

	/**
	 * Fetch the write-size cap, clamped to the min/max range.
	 *
	 * @return int
	 */
	private static function get_write_max_bytes(): int {
		return self::clamp_write_max_bytes( get_option( self::OPTION_WRITE_MAX_BYTES, self::DEFAULT_WRITE_MAX_BYTES ) );
	}

	/**
	 * Fetch a retention-days option, clamped to the min/max range.
	 *
	 * @param string $key     Option name.
	 * @param int    $default Default day count.
	 * @return int
	 */
	private static function get_retention_days( string $key, int $default ): int {
		return self::clamp_retention_days( get_option( $key, $default ) );
	}

	/**
	 * Coerce a mixed value to bool with WordPress-ish semantics (accepts
	 * "true"/"false"/"1"/"0"/0/1/true/false).
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function coerce_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$normalised = strtolower( trim( $value ) );
			if ( in_array( $normalised, array( 'false', '0', 'no', 'off', '' ), true ) ) {
				return false;
			}
			return true;
		}
		return (bool) $value;
	}

	/**
	 * Clamp a candidate day count into [MIN_RETENTION_DAYS, MAX_RETENTION_DAYS].
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function clamp_retention_days( $value ): int {
		$n = (int) $value;
		if ( $n < self::MIN_RETENTION_DAYS ) {
			return self::MIN_RETENTION_DAYS;
		}
		if ( $n > self::MAX_RETENTION_DAYS ) {
			return self::MAX_RETENTION_DAYS;
		}
		return $n;
	}

	/**
	 * Clamp the write-size cap into [MIN_WRITE_MAX_BYTES, MAX_WRITE_MAX_BYTES].
	 *
	 * @param mixed $value Raw value (int or numeric string).
	 * @return int
	 */
	private static function clamp_write_max_bytes( $value ): int {
		$n = (int) $value;
		if ( $n < self::MIN_WRITE_MAX_BYTES ) {
			return self::MIN_WRITE_MAX_BYTES;
		}
		if ( $n > self::MAX_WRITE_MAX_BYTES ) {
			return self::MAX_WRITE_MAX_BYTES;
		}
		return $n;
	}

	/**
	 * Sanitise a caller-supplied extension list. Convenience wrapper around
	 * {@see sanitize_extension_list_verbose()} that drops the skipped-entry
	 * report — use the verbose form when surfacing rejections to the caller.
	 *
	 * @param array<int|string,mixed> $raw Raw list.
	 * @return array<int,string>
	 */
	private static function sanitize_extension_list( array $raw ): array {
		list( $stored ) = self::sanitize_extension_list_verbose( $raw );
		return $stored;
	}

	/**
	 * Sanitise an extension list AND report which raw entries were dropped.
	 *
	 * Rules:
	 *   - non-string → skipped as `not_a_string`
	 *   - blank after trim → silently ignored (not reported)
	 *   - fails /^[a-z0-9]{1,16}$/ after normalisation → skipped as `invalid_format`
	 *   - duplicates of an already-stored entry → skipped as `duplicate`
	 *   - past the 100-entry cap → skipped as `list_cap_reached`
	 *
	 * @param array<int|string,mixed> $raw Raw list.
	 * @return array{0: array<int,string>, 1: array<int,array{value:string,reason:string}>}
	 */
	private static function sanitize_extension_list_verbose( array $raw ): array {
		$out     = array();
		$skipped = array();
		$seen    = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				$skipped[] = array( 'value' => is_scalar( $item ) ? (string) $item : '(non-string)', 'reason' => 'not_a_string' );
				continue;
			}
			$original = $item;
			$item     = strtolower( trim( $item, ". \t\n\r\0\x0B" ) );
			if ( '' === $item ) {
				continue;
			}
			if ( count( $out ) >= 100 ) {
				$skipped[] = array( 'value' => $original, 'reason' => 'list_cap_reached' );
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9]{1,16}$/', $item ) ) {
				$skipped[] = array( 'value' => $original, 'reason' => 'invalid_format' );
				continue;
			}
			if ( isset( $seen[ $item ] ) ) {
				$skipped[] = array( 'value' => $original, 'reason' => 'duplicate' );
				continue;
			}
			$seen[ $item ] = true;
			$out[]         = $item;
		}
		return array( $out, $skipped );
	}

	/**
	 * Sanitise a caller-supplied pattern list. Convenience wrapper around
	 * {@see sanitize_pattern_list_verbose()}.
	 *
	 * @param array<int|string,mixed> $raw Raw list.
	 * @return array<int,string>
	 */
	private static function sanitize_pattern_list( array $raw ): array {
		list( $stored ) = self::sanitize_pattern_list_verbose( $raw );
		return $stored;
	}

	/**
	 * Sanitise a pattern list AND report which raw entries were dropped.
	 *
	 * Rules:
	 *   - non-string → `not_a_string`
	 *   - blank after trim → silently ignored
	 *   - contains `/` or `\` → `path_segment_not_allowed` (basenames only)
	 *   - contains NUL / control chars → `control_char`
	 *   - duplicate → `duplicate`
	 *   - past the 200-entry cap → `list_cap_reached`
	 *
	 * @param array<int|string,mixed> $raw Raw list.
	 * @return array{0: array<int,string>, 1: array<int,array{value:string,reason:string}>}
	 */
	private static function sanitize_pattern_list_verbose( array $raw ): array {
		$out     = array();
		$skipped = array();
		$seen    = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				$skipped[] = array( 'value' => is_scalar( $item ) ? (string) $item : '(non-string)', 'reason' => 'not_a_string' );
				continue;
			}
			$original = $item;
			$item     = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( count( $out ) >= 200 ) {
				$skipped[] = array( 'value' => $original, 'reason' => 'list_cap_reached' );
				continue;
			}
			if ( false !== strpos( $item, '/' ) || false !== strpos( $item, '\\' ) ) {
				$skipped[] = array( 'value' => $original, 'reason' => 'path_segment_not_allowed' );
				continue;
			}
			if ( preg_match( '/[\x00-\x1F\x7F]/', $item ) ) {
				$skipped[] = array( 'value' => $original, 'reason' => 'control_char' );
				continue;
			}
			if ( isset( $seen[ $item ] ) ) {
				$skipped[] = array( 'value' => $original, 'reason' => 'duplicate' );
				continue;
			}
			$seen[ $item ] = true;
			$out[]         = $item;
		}
		return array( $out, $skipped );
	}
}
