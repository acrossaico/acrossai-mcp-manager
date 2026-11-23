<?php
/**
 * Feature 127 — everything this plugin knows about All-in-One WP Migration.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\AllInOne
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\AllInOne;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * All-in-One keeps each backup as a single `.wpress` archive in one directory.
 *
 * There is no history option and no set structure: the directory listing IS the history. Identifiers
 * here are therefore filenames, which is what every one of its own operations takes.
 *
 * It ships a REST API of its own (`ai1wm/v1`) whose export route starts a background job and returns
 * a job id immediately, so backups CAN be started from here — the work is dispatched in-process
 * through `rest_do_request()`, which runs the plugin's own permission callbacks rather than
 * side-stepping them.
 *
 * Restore is the exception, and not by our choice: the free plugin's own `/backups/{name}/restore`
 * route answers `upgrade_required`, because restoring is part of its paid Unlimited Extension. That
 * refusal is passed through with the plugin's own wording rather than being reworded as though the
 * limitation were ours.
 *
 * @since 0.0.35
 */
final class Archive_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$files  = self::files();
		$newest = null;

		foreach ( $files as $file ) {
			$mtime = isset( $file['mtime'] ) ? (int) $file['mtime'] : 0;

			if ( $mtime > 0 && ( null === $newest || $mtime > $newest ) ) {
				$newest = $mtime;
			}
		}

		return array(
			'backup_count'       => count( $files ),
			'last_backup_time'   => $newest,
			'last_backup_gmt'    => $newest ? gmdate( 'c', $newest ) : null,
			'last_backup_age'    => $newest ? self::describe_age( $newest ) : null,
			// The archive's presence on disk is the only record kept; there is no success flag to
			// report, and inventing one would be worse than admitting the absence.
			'last_backup_result' => null,
			'last_backup_errors' => array(),
			'running'            => self::is_running(),
			'schedules'          => array(),
			'retention'          => array(),
			'remote_storage'     => array(),
			'storage_path'       => self::directory(),
			'note'               => __( 'All-in-One records no success or failure for a completed export, so whether the last one finished cleanly cannot be reported — only that an archive of that age exists. Scheduled backups are a paid extension and are not readable here.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @param  int $limit  Maximum rows.
	 * @param  int $offset Rows to skip.
	 * @return array<string, mixed>
	 */
	public static function list_backups( int $limit, int $offset ): array {
		$files  = self::files();
		$labels = self::labels();
		$total  = count( $files );
		$rows   = array();

		foreach ( array_slice( $files, $offset, $limit ) as $file ) {
			$rows[] = self::shape( $file, $labels );
		}

		return array(
			'backups'  => $rows,
			'count'    => count( $rows ),
			'total'    => $total,
		);
	}

	/**
	 * @since  0.0.35
	 * @param  string $id Archive filename.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_backup( string $id ) {
		$labels = self::labels();

		foreach ( self::files() as $file ) {
			if ( isset( $file['filename'] ) && (string) $file['filename'] === $id ) {
				$shaped            = self::shape( $file, $labels );
				$shaped['storage'] = self::directory();

				return $shaped;
			}
		}

		return new WP_Error(
			'unknown_backup',
			sprintf(
				/* translators: %s: the backup identifier that was asked for. */
				__( 'All-in-One has no archive named %s. Use all-in-one/list-backups to see what exists.', 'acrossai-abilities-manager' ),
				$id
			)
		);
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
	 * Start an export through the plugin's own REST route.
	 *
	 * Dispatched in-process rather than over HTTP: `rest_do_request()` runs the route's own
	 * permission callback, so the plugin decides whether the current user may export. The route
	 * answers 202 with a job id and leaves the work running in the background, which is what makes
	 * this safe to call from a request.
	 *
	 * @since  0.0.35
	 * @param  array<string, mixed> $options Provider-neutral options.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start_backup( array $options ) {
		if ( ! self::rest_available() ) {
			return new WP_Error(
				'start_unsupported',
				__( 'This build of All-in-One WP Migration does not expose the REST route used to start an export. Start it at Tools > All-in-One WP Migration > Export.', 'acrossai-abilities-manager' )
			);
		}

		$exclude = array();

		// The provider-neutral option is "back up the database"; All-in-One expresses the same thing
		// as an exclusion, so a caller never has to know which way round a given plugin phrases it.
		if ( isset( $options['database'] ) && empty( $options['database'] ) ) {
			$exclude['no_database'] = 1;
		}

		if ( isset( $options['files'] ) && empty( $options['files'] ) ) {
			$exclude['no_media']   = 1;
			$exclude['no_themes']  = 1;
			$exclude['no_plugins'] = 1;
		}

		$request = new \WP_REST_Request( 'POST', '/ai1wm/v1/exports' );
		$request->set_param( 'options', $exclude );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data   = $response->get_data();
		$job_id = is_array( $data ) && isset( $data['job_id'] ) ? (string) $data['job_id'] : '';

		if ( '' === $job_id ) {
			return new WP_Error(
				'start_failed',
				__( 'All-in-One did not return a job identifier, so the export cannot be tracked. Check its own screen for progress.', 'acrossai-abilities-manager' )
			);
		}

		return array(
			'job_id'   => $job_id,
			'started'  => true,
			'includes' => array(
				'files'    => ! isset( $exclude['no_media'] ),
				'database' => ! isset( $exclude['no_database'] ),
			),
			'note'     => __( 'The export runs in the background and is not finished when this returns. Poll all-in-one/get-export-progress with this job_id. The archive appears in all-in-one/list-backups only once it completes.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @param  string $job Unused.
	 * @return array<string, mixed>
	 */
	public static function job_progress( string $job ) {
		if ( self::rest_available() && '' !== $job ) {
			$request  = new \WP_REST_Request( 'GET', '/ai1wm/v1/exports/' . $job );
			$response = rest_do_request( $request );

			if ( ! $response->is_error() ) {
				$data   = $response->get_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (string) $data['status'] : '';

				return array(
							'job_id'       => $job,
					'running'      => 'running' === $status,
					'finished'     => in_array( $status, array( 'done', 'completed', 'error' ), true ),
					'backup_id'    => is_array( $data ) && isset( $data['name'] ) ? (string) $data['name'] : null,
					'last_message' => is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '',
					'note'         => 'error' === $status
						? __( 'The export reported an error. all-in-one/get-export-progress returns its last message; the full log is on the plugin\'s own screen.', 'acrossai-abilities-manager' )
						: '',
				);
			}
		}

		return array(
			'job_id'       => $job,
			'running'      => self::is_running(),
			'finished'     => ! self::is_running(),
			'backup_id'    => null,
			'last_message' => self::status_message(),
			'note'         => __( 'This job is not known to All-in-One, so what is reported is whatever export is running on this site, if any.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @param  string $id Archive filename.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_backup( string $id ) {
		$existing = self::get_backup( $id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$bytes = isset( $existing['bytes_local'] ) ? (int) $existing['bytes_local'] : 0;

		\Ai1wm_Backups::delete_file( $id );

		$still_there = self::get_backup( $id );

		if ( ! is_wp_error( $still_there ) ) {
			return new WP_Error(
				'delete_failed',
				sprintf(
					/* translators: %s: the archive filename. */
					__( 'All-in-One was asked to delete %s but the archive is still present. Check file permissions on the backups directory.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		return array(
			'backup_id'     => $id,
			'files_removed' => array( $id ),
			'bytes_freed'   => $bytes,
			'note'          => '',
		);
	}

	/**
	 * @since  0.0.35
	 * @param  string               $id      Unused.
	 * @param  array<string, mixed> $options Unused.
	 * @return WP_Error
	 */
	public static function restore_backup( string $id, array $options ) {
		unset( $options );

		$existing = self::get_backup( $id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		// Ask the plugin rather than asserting on its behalf. Its answer carries the licence wording
		// and the upgrade link, and if a paid extension IS installed the route may well succeed --
		// in which case refusing here would have been wrong.
		if ( self::rest_available() ) {
			$request  = new \WP_REST_Request( 'POST', '/ai1wm/v1/backups/' . rawurlencode( $id ) . '/restore' );
			$response = rest_do_request( $request );

			if ( ! $response->is_error() ) {
				$data = $response->get_data();

				return array(
							'backup_id' => $id,
					'entities'  => array( 'database', 'files' ),
					'job_id'    => is_array( $data ) && isset( $data['job_id'] ) ? (string) $data['job_id'] : null,
				);
			}

			$error = $response->as_error();

			return new WP_Error(
				'restore_requires_extension',
				sprintf(
					/* translators: %s: the backup plugin's own explanation. */
					__( 'All-in-One WP Migration declined to restore: %s', 'acrossai-abilities-manager' ),
					(string) $error->get_error_message()
				)
			);
		}

		return new WP_Error(
			'restore_unsupported',
			__( 'This build of All-in-One WP Migration does not expose a restore route. Import the archive at Tools > All-in-One WP Migration > Import.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Whether the plugin's own REST routes are registered.
	 *
	 * @since  0.0.35
	 * @return bool
	 */
	private static function rest_available(): bool {
		return class_exists( 'Ai1wm_Rest_Controller' );
	}

	/**
	 * @since  0.0.35
	 * @return array<int, array<string, mixed>>
	 */
	private static function files(): array {
		if ( ! class_exists( 'Ai1wm_Backups' ) ) {
			return array();
		}

		$files = \Ai1wm_Backups::get_files();

		return is_array( $files ) ? $files : array();
	}

	/**
	 * @since  0.0.35
	 * @return array<string, string>
	 */
	private static function labels(): array {
		if ( ! class_exists( 'Ai1wm_Backups' ) || ! method_exists( 'Ai1wm_Backups', 'get_labels' ) ) {
			return array();
		}

		$labels = \Ai1wm_Backups::get_labels();

		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * @since  0.0.35
	 * @param  array<string, mixed>  $file   Raw file row.
	 * @param  array<string, string> $labels Stored labels.
	 * @return array<string, mixed>
	 */
	private static function shape( array $file, array $labels ): array {
		$name  = isset( $file['filename'] ) ? (string) $file['filename'] : '';
		$mtime = isset( $file['mtime'] ) ? (int) $file['mtime'] : 0;

		return array(
			'id'           => $name,
			'created'      => $mtime > 0 ? $mtime : null,
			'created_gmt'  => $mtime > 0 ? gmdate( 'c', $mtime ) : null,
			'age'          => $mtime > 0 ? self::describe_age( $mtime ) : null,
			// A .wpress archive is always a whole-site export: database and files together. There is
			// no per-component selection to report.
			'components'   => array( 'database', 'files' ),
			'has_database' => true,
			'file_count'   => 1,
			'bytes_local'  => isset( $file['size'] ) && null !== $file['size'] ? (int) $file['size'] : 0,
			'label'        => isset( $labels[ $name ] ) ? (string) $labels[ $name ] : '',
		);
	}

	/**
	 * Attach a label to an archive.
	 *
	 * @since  0.0.35
	 * @param  string $id    Archive filename.
	 * @param  string $label Label text.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_label( string $id, string $label ) {
		$existing = self::get_backup( $id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( '' === $label ) {
			\Ai1wm_Backups::delete_label( $id );
		} else {
			\Ai1wm_Backups::set_label( $id, $label );
		}

		$after = self::get_backup( $id );

		return array(
			'backup_id' => $id,
			'label'     => is_wp_error( $after ) ? $label : (string) $after['label'],
		);
	}

	/**
	 * @since  0.0.35
	 * @return bool
	 */
	private static function is_running(): bool {
		$status = self::status_payload();

		if ( ! is_array( $status ) || ! isset( $status['type'] ) ) {
			return false;
		}

		return ! in_array( (string) $status['type'], array( 'done', 'error' ), true );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	private static function status_message(): string {
		$status = self::status_payload();

		return is_array( $status ) && isset( $status['message'] ) && is_scalar( $status['message'] )
			? (string) $status['message']
			: '';
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>|null
	 */
	private static function status_payload() {
		if ( ! function_exists( 'ai1wm_storage_path' ) || ! defined( 'AI1WM_STATUS_NAME' ) ) {
			return null;
		}

		$stored = get_option( AI1WM_STATUS_NAME, null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	private static function directory(): string {
		return defined( 'AI1WM_BACKUPS_PATH' ) ? untrailingslashit( (string) AI1WM_BACKUPS_PATH ) : '';
	}

	/**
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
