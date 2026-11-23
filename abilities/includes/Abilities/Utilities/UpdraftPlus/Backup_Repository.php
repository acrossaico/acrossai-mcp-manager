<?php
/**
 * Feature 127 — everything this plugin knows about UpdraftPlus.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\UpdraftPlus
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\UpdraftPlus;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * UpdraftPlus keeps backup sets in an option keyed by timestamp.
 *
 * Identifiers in this suite are those timestamps rendered as strings, because they are what
 * UpdraftPlus itself uses as the primary key of a set and what its own restore path expects.
 *
 * This talks to UpdraftPlus directly. An earlier version put both backup plugins behind one shared
 * interface; that made the two look interchangeable when they are not — UpdraftPlus schedules and
 * restores, All-in-One labels and needs a paid extension to restore — and forced every real
 * difference to be reported as a capability flag rather than simply being absent.
 *
 * @since 0.0.35
 */
final class Backup_Repository {

	/**
	 * The scheduled events UpdraftPlus runs backups on.
	 *
	 * @since 0.0.35
	 * @var   array<string, string>
	 */
	private const SCHEDULES = array(
		'updraft_backup'          => 'files',
		'updraft_backup_database' => 'database',
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$last = self::option( 'updraft_last_backup' );

		$last_time    = null;
		$last_success = null;
		$last_errors  = array();

		if ( is_array( $last ) ) {
			$last_time = isset( $last['backup_time'] ) ? (int) $last['backup_time'] : null;

			// UpdraftPlus records success as 1/0 and a separate error list. An empty error list with
			// no explicit success flag is not the same as a success, so only a present flag speaks.
			if ( isset( $last['success'] ) ) {
				$last_success = (bool) $last['success'];
			}

			if ( ! empty( $last['errors'] ) && is_array( $last['errors'] ) ) {
				foreach ( $last['errors'] as $error ) {
					$last_errors[] = is_scalar( $error ) ? (string) $error : wp_json_encode( $error );
				}
			}
		}

		$history = self::history();

		return array(
						'backup_count'       => count( $history ),
			'last_backup_time'   => $last_time,
			'last_backup_gmt'    => $last_time ? gmdate( 'c', $last_time ) : null,
			'last_backup_age'    => $last_time ? self::describe_age( $last_time ) : null,
			'last_backup_result' => $last_success,
			'last_backup_errors' => $last_errors,
			'running'            => self::is_running(),
			'schedules'          => self::schedules(),
			'retention'          => array(
				'files'    => (int) self::option( 'updraft_retain', 2 ),
				'database' => (int) self::option( 'updraft_retain_db', 2 ),
			),
			'remote_storage'     => self::remote_storage(),
			'storage_path'       => self::directory(),
		);
	}

	/**
	 * @since  0.0.35
	 * @param  int $limit  Maximum rows.
	 * @param  int $offset Rows to skip.
	 * @return array<string, mixed>
	 */
	public static function list_backups( int $limit, int $offset ): array {
		$history = self::history();
		$total   = count( $history );
		$rows    = array();

		foreach ( array_slice( $history, $offset, $limit, true ) as $timestamp => $set ) {
			$rows[] = self::shape( (int) $timestamp, is_array( $set ) ? $set : array() );
		}

		return array(
			
			'backups'  => $rows,
			'count'    => count( $rows ),
			'total'    => $total,
		);
	}

	/**
	 * @since  0.0.35
	 * @param  string $id Backup timestamp.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_backup( string $id ) {
		$history   = self::history();
		$timestamp = (int) $id;

		if ( ! isset( $history[ $timestamp ] ) ) {
			return new WP_Error(
				'unknown_backup',
				sprintf(
					/* translators: %s: the backup identifier that was asked for. */
					__( 'UpdraftPlus has no backup set identified by %s. Use updraftplus/list-backups to see what exists.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		$set = is_array( $history[ $timestamp ] ) ? $history[ $timestamp ] : array();

		return self::shape( $timestamp, $set, true );
	}

	/**
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	public static function storage_paths(): array {
		$dir = self::directory();

		return '' === $dir ? array() : array( $dir );
	}

	/**
	 * Start a backup on cron rather than in this request.
	 *
	 * UpdraftPlus's own `request_backupnow()` is deliberately NOT used. It ends by calling
	 * `close_browser_connection()`, which echoes its own JSON and detaches the client, then runs the
	 * whole backup synchronously in the remainder of the process. Driven from an ability that does
	 * two unacceptable things: the raw UpdraftPlus payload replaces this suite's response entirely
	 * (measured -- the ability returned `{"nonce":...,"m":...}` and nothing else), and the backup
	 * blocks the request until it finishes or the server gives up.
	 *
	 * So the nonce is allocated the same way UpdraftPlus allocates it, and the same action it would
	 * fire is scheduled on cron instead. The job is UpdraftPlus's own from that point on; only who
	 * starts the clock differs.
	 *
	 * @since  0.0.35
	 * @param  array<string, mixed> $options Provider-neutral options.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start_backup( array $options ) {
		global $updraftplus;

		if ( ! is_object( $updraftplus ) || ! method_exists( $updraftplus, 'backup_time_nonce' ) ) {
			return new WP_Error(
				'start_unsupported',
				__( 'UpdraftPlus is active but its main object is not available, so a backup cannot be started from here.', 'acrossai-abilities-manager' )
			);
		}

		$files    = ! isset( $options['files'] ) || ! empty( $options['files'] );
		$database = ! isset( $options['database'] ) || ! empty( $options['database'] );

		if ( ! $files && ! $database ) {
			return new WP_Error(
				'nothing_to_back_up',
				__( 'A backup of neither files nor database would produce nothing. Set files: true, database: true, or both.', 'acrossai-abilities-manager' )
			);
		}

		$nonce = (string) $updraftplus->backup_time_nonce();

		if ( '' === $nonce ) {
			return new WP_Error(
				'start_failed',
				__( 'UpdraftPlus did not allocate a job identifier, so the backup cannot be tracked.', 'acrossai-abilities-manager' )
			);
		}

		if ( $files && $database ) {
			$event = 'updraft_backupnow_backup_all';
		} elseif ( $files ) {
			$event = 'updraft_backupnow_backup';
		} else {
			$event = 'updraft_backupnow_backup_database';
		}

		$job_options = array(
			'nocloud'     => empty( $options['send_to_remote'] ) ? 1 : 0,
			'use_nonce'   => $nonce,
			'always_keep' => ! empty( $options['always_keep'] ),
		);

		if ( isset( $options['label'] ) && '' !== (string) $options['label'] ) {
			$job_options['label'] = (string) $options['label'];
		}

		$scheduled = wp_schedule_single_event( time(), $event, array( $job_options ) );

		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			return new WP_Error(
				'start_failed',
				__( 'The backup could not be scheduled. Another identical job may already be queued; check updraftplus/get-status.', 'acrossai-abilities-manager' )
			);
		}

		// Nudge cron so the job starts now rather than on the next visitor. If this cannot run, the
		// job still fires on the next request -- the note says so rather than implying immediacy.
		spawn_cron();

		return array(
			'job_id'   => $nonce,
			'started'  => true,
			'includes' => array(
				'files'    => $files,
				'database' => $database,
			),
			'note'     => __( 'The backup is queued on WordPress cron and is NOT finished when this returns. Poll updraftplus/get-backup-progress with this job_id. On a site with no traffic, cron may need a visitor before it advances.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @param  string $job Job nonce.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function job_progress( string $job ) {
		$activity = self::option( 'updraft_activejobs', array() );
		$running  = is_array( $activity ) && isset( $activity[ $job ] );

		$log = self::option( 'updraft_lastmessage', '' );

		// A finished job leaves the active list and lands in the history under its own timestamp.
		$finished = null;

		foreach ( self::history() as $timestamp => $set ) {
			if ( is_array( $set ) && isset( $set['nonce'] ) && (string) $set['nonce'] === $job ) {
				$finished = (int) $timestamp;
				break;
			}
		}

		return array(
			'job_id'       => $job,
			'running'      => $running,
			'finished'     => null !== $finished,
			'backup_id'    => null !== $finished ? (string) $finished : null,
			'last_message' => is_scalar( $log ) ? (string) $log : '',
			'note'         => $running || null !== $finished
				? ''
				: __( 'This job is neither running nor in the backup history. It may not have started, or it may have failed before writing anything — the UpdraftPlus log on its own screen is the record.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @param  string $id Backup timestamp.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_backup( string $id ) {
		$existing = self::get_backup( $id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$timestamp = (int) $id;
		$history   = self::history();
		$set       = is_array( $history[ $timestamp ] ) ? $history[ $timestamp ] : array();
		$directory = self::directory();
		$removed   = array();
		$freed     = 0;

		foreach ( self::files_in( $set ) as $file ) {
			$path = trailingslashit( $directory ) . $file;

			if ( '' === $directory || ! is_file( $path ) ) {
				continue;
			}

			$size = (int) filesize( $path );

			if ( wp_delete_file_from_directory( $path, $directory ) ) {
				$removed[] = $file;
				$freed    += $size;
			}
		}

		unset( $history[ $timestamp ] );

		\UpdraftPlus_Backup_History::save_history( $history );

		return array(
			'backup_id'     => $id,
			'files_removed' => $removed,
			'bytes_freed'   => $freed,
			'note'          => empty( $removed )
				? __( 'The set was removed from the backup history, but no archive files were found on disk to delete — they may already have been removed, or they live only on remote storage, which is not touched from here.', 'acrossai-abilities-manager' )
				: __( 'Local archive files were deleted. Copies held on remote storage are NOT removed by this — delete those in the storage provider itself.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Restore the site from a set. There is no undo.
	 *
	 * UpdraftPlus's restore is built for its own admin screen and shows it: it echoes HTML as it
	 * goes, and `ensure_wp_filesystem_set_up_for_restore()` calls `exit` outright when WordPress
	 * cannot write to the filesystem directly and needs FTP credentials. Both are fatal to an
	 * ability -- the first corrupts a JSON response, the second kills the request mid-restore, which
	 * on the most destructive operation in this plugin is the worst possible failure.
	 *
	 * So the filesystem method is checked FIRST and the restore refused when it is not `direct`,
	 * rather than discovering it at the point of no return. The restore itself then runs with output
	 * captured, so UpdraftPlus's markup never reaches the caller.
	 *
	 * @since  0.0.35
	 * @param  string               $id      Backup timestamp.
	 * @param  array<string, mixed> $options Restore options.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_backup( string $id, array $options ) {
		global $updraftplus;

		$existing = self::get_backup( $id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		/*
		 * get_filesystem_method(), request_filesystem_credentials() and WP_Filesystem() all live in
		 * wp-admin/includes/file.php, which WordPress does not load for a REST request -- and every
		 * call into this suite is a REST request. Without this the restore died with "Call to
		 * undefined function get_filesystem_method()" before it checked anything, which is how it
		 * behaved for every input, not just a bad one. Measured.
		 */
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Checked before anything is touched: this is the condition that would otherwise exit()
		// halfway through replacing the site.
		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error(
				'filesystem_not_direct',
				__( 'WordPress cannot write to this site\'s files directly and would ask for FTP credentials, which stops a restore part-way through. Restoring from here is refused for that reason. Use the UpdraftPlus screen, which can prompt for them, or give the web server write access to the WordPress directory.', 'acrossai-abilities-manager' )
			);
		}

		if ( ! is_object( $updraftplus ) || ! method_exists( $updraftplus, 'jobdata_set' ) ) {
			return new WP_Error(
				'restore_unsupported',
				__( 'The UpdraftPlus main object is not available, so a restore cannot be run from here.', 'acrossai-abilities-manager' )
			);
		}

		foreach ( array( 'restorer.php', 'admin.php' ) as $file ) {
			if ( defined( 'UPDRAFTPLUS_DIR' ) && is_readable( UPDRAFTPLUS_DIR . '/' . $file ) ) {
				require_once UPDRAFTPLUS_DIR . '/' . $file;
			}
		}

		if ( ! class_exists( 'Updraft_Restorer' ) ) {
			return new WP_Error(
				'restore_unsupported',
				__( 'The UpdraftPlus restore class could not be loaded, so a restore cannot be run from here. Use the UpdraftPlus screen.', 'acrossai-abilities-manager' )
			);
		}

		$entities = self::restore_entities( $existing, $options );

		if ( empty( $entities ) ) {
			return new WP_Error(
				'nothing_to_restore',
				sprintf(
					/* translators: %s: comma-separated list of the components this set holds. */
					__( 'None of the requested components is in this backup set. It holds: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', isset( $existing['components'] ) ? $existing['components'] : array() )
				)
			);
		}

		$timestamp  = (int) $id;
		$backup_set = \UpdraftPlus_Backup_History::get_history( $timestamp );

		if ( empty( $backup_set ) ) {
			return new WP_Error( 'unknown_backup', __( 'The backup set disappeared from the history before the restore could start.', 'acrossai-abilities-manager' ) );
		}

		$backup_set['timestamp'] = $timestamp;

		// A restore is a job in its own right, with its own nonce and jobdata -- the same shape the
		// admin screen builds before handing over to the restorer.
		$updraftplus->backup_time_nonce();
		$updraftplus->jobdata_set( 'job_type', 'restore' );
		$updraftplus->jobdata_set( 'backup_timestamp', $timestamp );
		$updraftplus->jobdata_set( 'updraft_restore', array_keys( $entities ) );

		$restore_options = array(
			'updraft_encryptionphrase' => '',
			'updraft_restorer_wpcore_includewpconfig' => false,
			'updraft_restorer_replacesiteurl'         => false,
			'updraft_incremental_restore_point'       => -1,
		);

		$updraftplus->jobdata_set( 'restore_options', $restore_options );

		$credentials = request_filesystem_credentials( '', '', false, false );

		if ( false === $credentials || ! WP_Filesystem( $credentials ) ) {
			return new WP_Error(
				'filesystem_not_direct',
				__( 'WordPress could not take direct control of the filesystem, so the restore was not started. Nothing has been changed.', 'acrossai-abilities-manager' )
			);
		}

		// Everything UpdraftPlus prints from here on is admin markup. Capturing it keeps the
		// response valid; the outcome is read from the return value, not from what it printed.
		ob_start();

		try {
			$restorer = new \Updraft_Restorer( new \Updraft_Restorer_Skin(), $backup_set, false, $restore_options, null );
			$outcome  = $restorer->perform_restore( $entities, $restore_options );

			if ( method_exists( $restorer, 'post_restore_clean_up' ) ) {
				$restorer->post_restore_clean_up( $outcome );
			}
		} catch ( \Throwable $e ) {
			ob_end_clean();

			return new WP_Error(
				'restore_failed',
				sprintf(
					/* translators: %s: the error reported by the backup plugin. */
					__( 'The restore stopped part-way through: %s. The site may be in a mixed state - some of the backup applied and some not. Check the UpdraftPlus screen before making any further changes.', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		ob_end_clean();

		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		if ( true !== $outcome ) {
			return new WP_Error(
				'restore_incomplete',
				__( 'UpdraftPlus did not report the restore as successful. The site may be in a mixed state - some of the backup applied and some not. Check the UpdraftPlus screen before making any further changes.', 'acrossai-abilities-manager' )
			);
		}

		return array(
			'backup_id' => $id,
			'entities'  => array_keys( $entities ),
			'job_id'    => isset( $updraftplus->nonce ) ? (string) $updraftplus->nonce : null,
		);
	}

	/**
	 * Which components of a set the caller asked to restore.
	 *
	 * @since  0.0.35
	 * @param  array<string, mixed> $backup  Shaped backup.
	 * @param  array<string, mixed> $options Restore options.
	 * @return array<string, bool>
	 */
	public static function restore_entities( array $backup, array $options ): array {
		$available = isset( $backup['components'] ) && is_array( $backup['components'] ) ? $backup['components'] : array();
		$requested = isset( $options['components'] ) && is_array( $options['components'] ) ? $options['components'] : array();
		$entities  = array();

		foreach ( $available as $component ) {
			if ( empty( $requested ) || in_array( $component, $requested, true ) ) {
				$entities[ $component ] = true;
			}
		}

		return $entities;
	}

	/**
	 * Whether a backup is in progress right now.
	 *
	 * @since  0.0.35
	 * @return bool
	 */
	private static function is_running(): bool {
		$active = self::option( 'updraft_activejobs', array() );

		return is_array( $active ) && ! empty( $active );
	}

	/**
	 * @since  0.0.35
	 * @return array<int, array<string, mixed>>
	 */
	private static function schedules(): array {
		$out = array();

		foreach ( self::SCHEDULES as $hook => $what ) {
			$next = wp_next_scheduled( $hook );
			$key  = 'files' === $what ? 'updraft_interval' : 'updraft_interval_database';

			$out[] = array(
				'covers'   => $what,
				'interval' => (string) self::option( $key, 'manual' ),
				'next_run' => $next ? (int) $next : null,
				'next_gmt' => $next ? gmdate( 'c', (int) $next ) : null,
			);
		}

		return $out;
	}

	/**
	 * Remote storage destinations, by name only.
	 *
	 * Deliberately names and nothing else. Each destination's settings hold its access tokens and
	 * secrets in the same structure as its name, so anything returning the settings would hand those
	 * out — the position already taken on payment gateways in the store suite.
	 *
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	private static function remote_storage(): array {
		$service = self::option( 'updraft_service', array() );

		if ( is_string( $service ) ) {
			$service = '' === $service ? array() : array( $service );
		}

		if ( ! is_array( $service ) ) {
			return array();
		}

		$names = array();

		foreach ( $service as $entry ) {
			if ( is_string( $entry ) && '' !== $entry && 'none' !== $entry ) {
				$names[] = $entry;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * @since  0.0.35
	 * @return array<int|string, mixed>
	 */
	private static function history(): array {
		if ( ! class_exists( 'UpdraftPlus_Backup_History' ) ) {
			return array();
		}

		$history = \UpdraftPlus_Backup_History::get_history();

		if ( ! is_array( $history ) ) {
			return array();
		}

		krsort( $history );

		return $history;
	}

	/**
	 * The keys of a set that are actual backup components.
	 *
	 * A set mixes components with metadata under the same roof -- `db` sits beside `checksums`,
	 * `nonce`, `service` and `created_by_version`. Treating "anything that is not a scalar" as a
	 * component was wrong in both directions: it reported `checksums` and `service` as components of
	 * the backup, and missed `db` entirely because its value is a plain filename string. Measured on
	 * a real database backup, which reported has_database false with the archive sitting on disk.
	 *
	 * UpdraftPlus's own entity list is the authority, so it is asked rather than guessed at.
	 *
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	private static function entity_keys(): array {
		global $updraftplus;

		$entities = array( 'db' );

		if ( is_object( $updraftplus ) && method_exists( $updraftplus, 'get_backupable_file_entities' ) ) {
			$file_entities = $updraftplus->get_backupable_file_entities( true );

			if ( is_array( $file_entities ) ) {
				$entities = array_merge( $entities, array_keys( $file_entities ) );
			}
		}

		// wpcore appears in sets imported from other backup plugins and is not in the entity list.
		$entities[] = 'wpcore';

		return array_values( array_unique( $entities ) );
	}

	/**
	 * Turn one history entry into this suite's shape.
	 *
	 * @since  0.0.35
	 * @param  int                  $timestamp Set timestamp.
	 * @param  array<string, mixed> $set       Raw set.
	 * @param  bool                 $detailed  Whether to include the file list.
	 * @return array<string, mixed>
	 */
	private static function shape( int $timestamp, array $set, bool $detailed = false ): array {
		$components = array();
		$files      = array();
		$bytes      = 0;
		$dir        = self::directory();

		foreach ( self::entity_keys() as $entity ) {
			if ( ! isset( $set[ $entity ] ) ) {
				continue;
			}

			$value = $set[ $entity ];
			$named = array();

			if ( is_string( $value ) && '' !== $value ) {
				$named[] = $value;
			} elseif ( is_array( $value ) ) {
				// A large entity is split across several archives, keyed by part number.
				foreach ( $value as $part ) {
					if ( is_string( $part ) && '' !== $part ) {
						$named[] = $part;
					}
				}
			}

			if ( empty( $named ) ) {
				continue;
			}

			$components[] = $entity;
			$files        = array_merge( $files, $named );

			// The recorded size is authoritative and survives the archive being moved to remote
			// storage; filesize() only works while the file is still here.
			if ( isset( $set[ $entity . '-size' ] ) && is_numeric( $set[ $entity . '-size' ] ) ) {
				$bytes += (int) $set[ $entity . '-size' ];
				continue;
			}

			foreach ( $named as $file ) {
				$path = trailingslashit( $dir ) . $file;

				if ( '' !== $dir && is_file( $path ) ) {
					$bytes += (int) filesize( $path );
				}
			}
		}

		$files = array_values( array_unique( $files ) );

		$present = 0;

		foreach ( $files as $file ) {
			if ( '' !== $dir && is_file( trailingslashit( $dir ) . $file ) ) {
				++$present;
			}
		}

		$shaped = array(
			'id'           => (string) $timestamp,
			'created'      => $timestamp,
			'created_gmt'  => gmdate( 'c', $timestamp ),
			'age'          => self::describe_age( $timestamp ),
			'components'   => $components,
			'has_database' => in_array( 'db', $components, true ),
			'file_count'   => count( $files ),
			'files_present_locally' => $present,
			'bytes_local'  => $bytes,
			'label'        => isset( $set['label'] ) && is_scalar( $set['label'] ) ? (string) $set['label'] : '',
		);

		if ( $present < count( $files ) ) {
			$shaped['note'] = __( 'Some archives of this set are not on this server. They may have been moved to remote storage, or deleted. A restore needs every part.', 'acrossai-abilities-manager' );
		}

		if ( $detailed ) {
			$shaped['files']   = $files;
			$shaped['storage'] = $dir;
		}

		return $shaped;
	}

	/**
	 * Every archive filename in a set.
	 *
	 * @since  0.0.35
	 * @param  array<string, mixed> $set Raw set.
	 * @return array<int, string>
	 */
	private static function files_in( array $set ): array {
		$files = array();

		foreach ( self::entity_keys() as $entity ) {
			if ( ! isset( $set[ $entity ] ) ) {
				continue;
			}

			$value = $set[ $entity ];

			if ( is_string( $value ) && '' !== $value ) {
				$files[] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				foreach ( $value as $part ) {
					if ( is_string( $part ) && '' !== $part ) {
						$files[] = $part;
					}
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	private static function directory(): string {
		global $updraftplus;

		if ( is_object( $updraftplus ) && method_exists( $updraftplus, 'backups_dir_location' ) ) {
			$dir = $updraftplus->backups_dir_location();

			if ( is_string( $dir ) && '' !== $dir ) {
				return untrailingslashit( $dir );
			}
		}

		$configured = self::option( 'updraft_dir', '' );

		return is_string( $configured ) && '' !== $configured
			? untrailingslashit( $configured )
			: untrailingslashit( WP_CONTENT_DIR . '/updraft' );
	}

	/**
	 * @since  0.0.35
	 * @return object|null
	 */
	private static function admin() {
		global $updraftplus_admin;

		if ( is_object( $updraftplus_admin ) ) {
			return $updraftplus_admin;
		}

		if ( defined( 'UPDRAFTPLUS_DIR' ) ) {
			$file = UPDRAFTPLUS_DIR . '/admin.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		if ( class_exists( 'UpdraftPlus_Admin' ) && ! is_object( $updraftplus_admin ) ) {
			$updraftplus_admin = new \UpdraftPlus_Admin();
		}

		return is_object( $updraftplus_admin ) ? $updraftplus_admin : null;
	}

	/**
	 * Read an UpdraftPlus option through its own accessor when available.
	 *
	 * UpdraftPlus stores options differently on multisite, and its accessor is the only thing that
	 * knows which. Reading get_option() directly would be right on single sites and wrong on
	 * networks.
	 *
	 * @since  0.0.35
	 * @param  string $name    Option name.
	 * @param  mixed  $default_value Fallback.
	 * @return mixed
	 */
	private static function option( string $name, $default_value = null ) {
		if ( class_exists( 'UpdraftPlus_Options' ) && method_exists( 'UpdraftPlus_Options', 'get_updraft_option' ) ) {
			return \UpdraftPlus_Options::get_updraft_option( $name, $default_value );
		}

		return get_option( $name, $default_value );
	}

	/**
	 * How long ago, in plain words.
	 *
	 * @since  0.0.35
	 * @param  int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function describe_age( int $timestamp ): string {
		$now = time();

		if ( $timestamp > $now ) {
			return __( 'in the future', 'acrossai-abilities-manager' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 days". */
			__( '%s ago', 'acrossai-abilities-manager' ),
			human_time_diff( $timestamp, $now )
		);
	}
}
