<?php
/**
 * File-manager directory-listing ability (Feature 089).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Fs_Roots;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * List_Directory ability class.
 *
 * Recursive walk of a directory under ABSPATH. Bounded by max_depth and
 * max_entries so a caller cannot enumerate the entire filesystem in one call.
 */
class List_Directory extends Ability_Definition {

	private const DEFAULT_MAX_DEPTH   = 5;
	private const DEFAULT_MAX_ENTRIES = 1000;
	private const HARD_CAP_MAX_DEPTH  = 20;
	private const HARD_CAP_MAX_ENTRIES = 5000;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/list-directory',
			'args' => array(
				'label'               => __( 'List Directory', 'acrossai-abilities-manager' ),
				'description'         => __( 'Recursively list entries under a directory inside the WordPress installation. Path is relative to the WordPress directories: a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory even on hosts that place it outside ABSPATH. Results are bounded by max_depth (default 5, max 20) and max_entries (default 1000, max 5000); when either bound is reached the response sets truncated:true. Symlinks are not followed.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'path'        => array(
							'type'        => 'string',
							'description' => __( 'Directory path relative to a WordPress directory (e.g. wp-content/plugins/hello-dolly).', 'acrossai-abilities-manager' ),
						),
						'max_depth'   => array(
							'type'        => 'integer',
							'default'     => self::DEFAULT_MAX_DEPTH,
							'minimum'     => 1,
							'maximum'     => self::HARD_CAP_MAX_DEPTH,
							'description' => __( 'Maximum recursion depth. Depth 1 lists only direct children.', 'acrossai-abilities-manager' ),
						),
						'max_entries' => array(
							'type'        => 'integer',
							'default'     => self::DEFAULT_MAX_ENTRIES,
							'minimum'     => 1,
							'maximum'     => self::HARD_CAP_MAX_ENTRIES,
							'description' => __( 'Maximum number of entries returned. If reached, response has truncated:true.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'path'           => array( 'type' => 'string' ),
						'entries'        => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'path'  => array( 'type' => 'string' ),
									'type'  => array(
										'type' => 'string',
										'enum' => array( 'file', 'dir' ),
									),
									'size'  => array( 'type' => 'integer' ),
									'mtime' => array( 'type' => 'integer' ),
								),
								'required'             => array( 'path', 'type', 'size', 'mtime' ),
								'additionalProperties' => false,
							),
						),
						'truncated'      => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'message' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'files',
						'sub_group_label' => __( 'Files', 'acrossai-abilities-manager' ),
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
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$rel_path    = sanitize_text_field( $input['path'] ?? '' );
		$max_depth   = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : self::DEFAULT_MAX_DEPTH;
		$max_entries = isset( $input['max_entries'] ) ? (int) $input['max_entries'] : self::DEFAULT_MAX_ENTRIES;

		if ( $max_depth < 1 ) {
			$max_depth = 1;
		}
		if ( $max_depth > self::HARD_CAP_MAX_DEPTH ) {
			$max_depth = self::HARD_CAP_MAX_DEPTH;
		}
		if ( $max_entries < 1 ) {
			$max_entries = 1;
		}
		if ( $max_entries > self::HARD_CAP_MAX_ENTRIES ) {
			$max_entries = self::HARD_CAP_MAX_ENTRIES;
		}

		// Feature 135: resolve against every WordPress root. $base is the prefix that makes the
		// reported entry paths round-trip back through the resolver -- see Fs_Roots::display_base().
		$resolved = Fs_Roots::resolve( $rel_path, true );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$real = $resolved['path'];
		$base = Fs_Roots::display_base( $real );

		if ( ! $fs->is_dir( $real ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'not_a_directory',
				'message'        => __( 'Path exists but is not a directory.', 'acrossai-abilities-manager' ),
			);
		}

		$entries   = array();
		$truncated = false;
		$this->walk( $fs, $real, $base, 1, $max_depth, $max_entries, $entries, $truncated );

		return array(
			'success'   => true,
			'path'      => $real,
			'entries'   => $entries,
			'truncated' => $truncated,
			'message'   => sprintf(
				/* translators: 1: entry count, 2: truncated flag literal */
				_n(
					'Listed %1$d entry (truncated=%2$s).',
					'Listed %1$d entries (truncated=%2$s).',
					count( $entries ),
					'acrossai-abilities-manager'
				),
				count( $entries ),
				$truncated ? 'true' : 'false'
			),
		);
	}

	/**
	 * Recursive pre-order walk using WP_Filesystem dirlist().
	 *
	 * Replaces the SPL-iterator pattern from feature 089 with a
	 * WP_Filesystem-compatible $fs->dirlist() walk. Preserves depth cap,
	 * entry cap, and symlink-skip semantics.
	 *
	 * @param \WP_Filesystem_Base  $fs           Initialised filesystem transport.
	 * @param string               $dir_abs      Absolute directory path being walked.
	 * @param string               $base         Display base from Fs_Roots::display_base() (rtrimmed).
	 * @param int                  $current_depth Current walk depth (1-indexed).
	 * @param int                  $max_depth    Maximum walk depth.
	 * @param int                  $max_entries  Maximum entries to collect.
	 * @param array<int,array<string,mixed>> $entries Output collector.
	 * @param bool                 $truncated    Output flag, true when max_entries reached.
	 * @return void
	 */
	private function walk( \WP_Filesystem_Base $fs, string $dir_abs, string $base, int $current_depth, int $max_depth, int $max_entries, array &$entries, bool &$truncated ): void {
		if ( $current_depth > $max_depth || $truncated ) {
			return;
		}

		$dirlist = $fs->dirlist( $dir_abs );
		if ( ! is_array( $dirlist ) ) {
			return;
		}

		// Deterministic ordering: alphabetical within each directory.
		ksort( $dirlist, SORT_STRING );

		$base_len = strlen( $base ) + 1;

		foreach ( $dirlist as $name => $info ) {
			if ( $truncated ) {
				return;
			}

			// Skip symlinks — never followed. dirlist() reports 'l' for links.
			if ( isset( $info['type'] ) && 'l' === $info['type'] ) {
				continue;
			}

			$entry_abs   = rtrim( $dir_abs, '/' ) . '/' . $name;
			$is_dir      = isset( $info['type'] ) && 'd' === $info['type'];
			$size        = $is_dir ? 0 : (int) ( $info['size'] ?? 0 );
			$mtime       = (int) ( $info['lastmodunix'] ?? 0 );

			$entries[] = array(
				'path'  => substr( $entry_abs, $base_len ),
				'type'  => $is_dir ? 'dir' : 'file',
				'size'  => $size,
				'mtime' => $mtime,
			);

			if ( count( $entries ) >= $max_entries ) {
				$truncated = true;
				return;
			}

			if ( $is_dir ) {
				$this->walk( $fs, $entry_abs, $base, $current_depth + 1, $max_depth, $max_entries, $entries, $truncated );
			}
		}
	}
}
