<?php
/**
 * file-manager/get-changelog ability (feature 094).
 *
 * Tails the audit log at wp-content/acrossai-file-manager-logs/acrossai-file-manager.log.
 * Read via WP_Filesystem, honours the read allowlist, returns the last N
 * entries in chronological order (oldest first, newest last).
 *
 * See specs/094-file-manager-audit-log/contracts/get-changelog-ability.md
 * for the I/O contract.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Audit_Trail;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Get_Changelog ability class.
 */
class Get_Changelog extends Ability_Definition {

	private const DEFAULT_LINES = 100;
	private const MIN_LINES     = 1;
	private const MAX_LINES     = 500;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/get-changelog',
			'args' => array(
				'label'               => __( 'Get File Manager Changelog', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the last N entries from the File Manager audit log at wp-content/acrossai-file-manager-logs/acrossai-file-manager.log. Entries are returned in chronological order (oldest first). Default 100 lines, max 500. Empty log returns success with an informative message.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'lines' => array(
							'type'        => 'integer',
							'default'     => self::DEFAULT_LINES,
							'minimum'     => self::MIN_LINES,
							'maximum'     => self::MAX_LINES,
							'description' => __( 'Number of most-recent log entries to return. Default 100, max 500.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'log'            => array( 'type' => 'string' ),
						'path'           => array( 'type' => 'string' ),
						'lines_returned' => array( 'type' => 'integer' ),
						'total_lines'    => array( 'type' => 'integer' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'allowed_roots'  => array( 'type' => 'array' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'audit',
						'sub_group_label' => __( 'Audit', 'acrossai-abilities-manager' ),
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
		$lines = (int) ( $input['lines'] ?? self::DEFAULT_LINES );
		if ( $lines < self::MIN_LINES ) {
			$lines = self::MIN_LINES;
		}
		if ( $lines > self::MAX_LINES ) {
			$lines = self::MAX_LINES;
		}

		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$log_path = Audit_Trail::log_path();

		// Honour the read allowlist — a tightened allowlist that excludes
		// wp-content refuses this call too, matching Read_File semantics.
		$blocked = Path_Allowlist_Guard::blocked_read_response( $log_path );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( ! $fs->exists( $log_path ) ) {
			return array(
				'success'        => true,
				'log'            => '',
				'path'           => $log_path,
				'lines_returned' => 0,
				'total_lines'    => 0,
				'message'        => __( 'No filesystem operations have been logged yet.', 'acrossai-abilities-manager' ),
			);
		}

		$contents = $fs->get_contents( $log_path );
		if ( false === $contents || '' === $contents ) {
			return array(
				'success'        => true,
				'log'            => '',
				'path'           => $log_path,
				'lines_returned' => 0,
				'total_lines'    => 0,
				'message'        => __( 'Log file exists but contains no entries.', 'acrossai-abilities-manager' ),
			);
		}

		$blocks = preg_split( '/\n{2,}/', trim( (string) $contents ) );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}
		$blocks      = array_values( array_filter( $blocks, static fn( string $b ): bool => '' !== trim( $b ) ) );
		$total_lines = count( $blocks );
		$tail        = array_slice( $blocks, -1 * $lines );

		return array(
			'success'        => true,
			'log'            => implode( "\n\n", $tail ),
			'path'           => $log_path,
			'lines_returned' => count( $tail ),
			'total_lines'    => $total_lines,
			'message'        => sprintf(
				/* translators: 1: lines returned, 2: total lines */
				__( 'Showing last %1$d entries of %2$d total.', 'acrossai-abilities-manager' ),
				count( $tail ),
				$total_lines
			),
		);
	}
}
