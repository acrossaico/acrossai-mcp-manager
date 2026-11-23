<?php
/**
 * Feature 059 — List recent fatal errors ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.17
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Streams `debug.log` line-by-line, extracts PHP Fatal / Parse / Compile error
 * entries emitted within the last N days, groups by unique signature
 * (type + file + line + message), and returns the top-M groups sorted by
 * most-recent occurrence. Unlike `read-debug-log`, which returns the raw log,
 * this ability targets the fatal-error signal specifically for triage.
 */
class List_Recent_Fatal_Errors extends Ability_Definition {

	/**
	 * PHP error-line regex.
	 *
	 * Matches lines like:
	 *   [24-Jul-2026 09:15:03 UTC] PHP Fatal error:  Uncaught Error: ... in /path/to/file.php:42
	 *   [24-Jul-2026 09:15:03 UTC] PHP Parse error:  syntax error, ... in /path/to/file.php on line 42
	 */
	private const ERROR_LINE_REGEX = '/^\[(?<ts>[^\]]+)\]\s+PHP\s+(?<type>Fatal|Parse|Compile)\s+error:\s+(?<message>.*?)(?:\s+in\s+(?<file>[^\s]+?)(?::(?<line_after_colon>\d+)|(?:\s+on\s+line\s+(?<line_after_on>\d+)))?)?$/i';

	/**
	 * Maximum bytes to scan from the tail of debug.log. Guards against
	 * runaway logs (multi-GB) from consuming the request.
	 */
	private const MAX_SCAN_BYTES = 20 * 1024 * 1024;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'recovery/list-recent-fatal-errors',
			'args' => array(
				'label'               => __( 'List Recent Fatal Errors', 'acrossai-abilities-manager' ),
				'description'         => __( 'Extracts PHP Fatal / Parse / Compile error entries from debug.log within the last N days, groups them by unique signature (type + file + line + message), and returns the top-M groups sorted by most-recent occurrence. Streams the log from disk with a 20 MB tail cap to guard against runaway logs.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-recovery',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'since_days' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 90,
							'default'     => 7,
							'description' => __( 'Only include errors logged within the last N days.', 'acrossai-abilities-manager' ),
						),
						'limit'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 200,
							'default'     => 20,
							'description' => __( 'Maximum number of grouped errors to return.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'errors'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'first_seen' => array( 'type' => 'string' ),
									'last_seen'  => array( 'type' => 'string' ),
									'count'      => array( 'type' => 'integer' ),
									'type'       => array( 'type' => 'string' ),
									'message'    => array( 'type' => 'string' ),
									'file'       => array( 'type' => 'string' ),
									'line'       => array( 'type' => 'integer' ),
								),
							),
						),
						'scanned_bytes' => array( 'type' => 'integer' ),
						'truncated'     => array( 'type' => 'boolean' ),
						'message'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'recovery',
						'sub_group_label' => __( 'Recovery Mode', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$log_path = WP_CONTENT_DIR . '/debug.log';

		if ( ! is_file( $log_path ) ) {
			$logging_on = ( defined( 'WP_DEBUG_LOG' ) && \WP_DEBUG_LOG ) && ( defined( 'WP_DEBUG' ) && \WP_DEBUG );
			$reason     = $logging_on
				? __( 'No entries have been written yet.', 'acrossai-abilities-manager' )
				: __( 'WP_DEBUG and/or WP_DEBUG_LOG are not enabled in wp-config.php, so nothing is being written.', 'acrossai-abilities-manager' );
			return array(
				'success' => true,
				'errors'  => array(),
				/* translators: %s: reason the log file is missing */
				'message' => sprintf( __( 'debug.log does not exist. %s', 'acrossai-abilities-manager' ), $reason ),
			);
		}

		$since_days = isset( $input['since_days'] ) ? max( 1, min( 90, (int) $input['since_days'] ) ) : 7;
		$limit      = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 20;
		$cutoff_ts  = time() - ( $since_days * DAY_IN_SECONDS );

		$file_size = filesize( $log_path );
		if ( false === $file_size ) {
			return array(
				'success' => false,
				'errors'  => array(),
				'message' => __( 'Could not stat debug.log.', 'acrossai-abilities-manager' ),
			);
		}

		$truncated  = $file_size > self::MAX_SCAN_BYTES;
		$seek_start = $truncated ? ( $file_size - self::MAX_SCAN_BYTES ) : 0;

		$fh = fopen( $log_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return array(
				'success' => false,
				'errors'  => array(),
				'message' => __( 'Could not open debug.log.', 'acrossai-abilities-manager' ),
			);
		}

		if ( $seek_start > 0 ) {
			fseek( $fh, $seek_start );
			fgets( $fh ); // discard likely-truncated first line
		}

		$groups = array();

		while ( ! feof( $fh ) ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}

			if ( ! preg_match( self::ERROR_LINE_REGEX, rtrim( $line ), $m ) ) {
				continue;
			}

			$ts = strtotime( $m['ts'] );
			if ( false === $ts || $ts < $cutoff_ts ) {
				continue;
			}

			$type    = ucfirst( strtolower( $m['type'] ) ) . ' Error';
			$file    = isset( $m['file'] ) ? $m['file'] : '';
			$line_no = 0;
			if ( ! empty( $m['line_after_colon'] ) ) {
				$line_no = (int) $m['line_after_colon'];
			} elseif ( ! empty( $m['line_after_on'] ) ) {
				$line_no = (int) $m['line_after_on'];
			}
			$message = trim( $m['message'] );

			$sig = md5( $type . '|' . $file . '|' . $line_no . '|' . $message );
			$iso = gmdate( 'c', $ts );

			if ( ! isset( $groups[ $sig ] ) ) {
				$groups[ $sig ] = array(
					'first_seen' => $iso,
					'last_seen'  => $iso,
					'count'      => 0,
					'type'       => $type,
					'message'    => $message,
					'file'       => $file,
					'line'       => $line_no,
				);
			}
			$groups[ $sig ]['last_seen'] = $iso;
			++$groups[ $sig ]['count'];
		}

		fclose( $fh );

		usort( $groups, static function ( array $a, array $b ): int {
			return strcmp( $b['last_seen'], $a['last_seen'] );
		} );

		$groups = array_slice( $groups, 0, $limit );

		return array(
			'success'       => true,
			'errors'        => array_values( $groups ),
			'scanned_bytes' => (int) ( $file_size - $seek_start ),
			'truncated'     => $truncated,
			'message'       => $truncated
				? sprintf(
					/* translators: %d: number of bytes scanned */
					__( 'debug.log exceeds %d bytes; scanned only the tail.', 'acrossai-abilities-manager' ),
					self::MAX_SCAN_BYTES
				)
				: '',
		);
	}
}
