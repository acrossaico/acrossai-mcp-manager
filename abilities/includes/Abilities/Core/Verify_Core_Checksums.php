<?php
/**
 * Feature 064 — Verify WordPress core files against the official checksums
 * manifest.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Core
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Core;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Checksum_Results;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Verify WordPress core files against the manifest returned by
 * get_core_checksums() from wp-admin/includes/update.php. Same diff
 * algorithm as Verify_Plugin_Checksums; paths are relative to ABSPATH.
 *
 * Honours:
 *   - version: defaults to the installed core version.
 *   - locale:  defaults to 'en_US'.
 *   - include_root: when false (default), root-level files like wp-config.php
 *     and .htaccess are skipped so custom deploys don't produce noise.
 *   - exclude: additional paths to skip.
 *   - strict: when true, files present on disk but absent from the manifest
 *     are flagged with status:'added'.
 */
class Verify_Core_Checksums extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'core/verify-core-checksums',
			'args' => array(
				'label'               => __( 'Verify Core Checksums', 'acrossai-abilities-manager' ),
				'description'         => __( 'Verify WordPress core files against the official checksums manifest returned by get_core_checksums(). Per-file status: ok / modified / missing / added. By default results lists only files needing attention and the summary carries the counts — a clean install has 3,766 files and listing them all exceeds the MCP response limit; pass include_ok:true to list the matching files. Skips root-level files by default (set include_root:true to include them).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-core',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'version'      => array( 'type' => 'string' ),
						'locale'       => array( 'type' => 'string' ),
						'include_ok'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Include every file that matched. Off by default and emphatically so: a clean WordPress install returns 3,766 rows at roughly 608 KB, which exceeds the MCP response limit outright — the ability returned nothing usable at all. The summary carries the counts.', 'acrossai-abilities-manager' ),
						),
						'include_root' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'exclude'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'strict'       => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'version' => array( 'type' => 'string' ),
						'locale'  => array( 'type' => 'string' ),
						'results' => array( 'type' => 'array' ),
						'summary' => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'integrity',
						'sub_group_label' => __( 'Integrity', 'acrossai-abilities-manager' ),
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
		$version_input = isset( $input['version'] ) ? sanitize_text_field( (string) $input['version'] ) : '';
		$locale_input  = isset( $input['locale'] ) ? sanitize_text_field( (string) $input['locale'] ) : '';
		$version       = '' !== $version_input ? $version_input : (string) get_bloginfo( 'version' );
		$locale        = '' !== $locale_input ? $locale_input : 'en_US';
		$include_root  = ! empty( $input['include_root'] );
		$include_ok    = ! empty( $input['include_ok'] );
		$strict        = ! empty( $input['strict'] );

		$exclude_raw = isset( $input['exclude'] ) && is_array( $input['exclude'] ) ? $input['exclude'] : array();
		$exclude     = array_map(
			static function ( $entry ): string {
				return sanitize_text_field( (string) $entry );
			},
			$exclude_raw
		);

		require_once ABSPATH . 'wp-admin/includes/update.php';

		$manifest = get_core_checksums( $version, $locale );

		$empty_summary = array(
			'total'    => 0,
			'ok'       => 0,
			'modified' => 0,
			'missing'  => 0,
			'added'    => 0,
		);

		if ( ! is_array( $manifest ) || empty( $manifest ) ) {
			return array(
				'success' => true,
				'version' => $version,
				'locale'  => $locale,
				'results' => array(),
				'summary' => $empty_summary,
				'message' => 'no_manifest',
			);
		}

		$results = array();
		$summary = $empty_summary;

		foreach ( $manifest as $file => $expected_hash ) {
			$file          = (string) $file;
			$expected_hash = (string) $expected_hash;

			if ( ! $include_root && false === strpos( $file, '/' ) ) {
				continue;
			}
			if ( in_array( $file, $exclude, true ) ) {
				continue;
			}

			$disk_path = ABSPATH . $file;
			if ( ! file_exists( $disk_path ) ) {
				$results[] = array(
					'file'     => $file,
					'expected' => $expected_hash,
					'actual'   => '',
					'status'   => 'missing',
				);
				++$summary['missing'];
				continue;
			}

			$actual_hash = (string) md5_file( $disk_path );
			$status      = hash_equals( $expected_hash, $actual_hash ) ? 'ok' : 'modified';
			$results[]   = array(
				'file'     => $file,
				'expected' => $expected_hash,
				'actual'   => $actual_hash,
				'status'   => $status,
			);
			++$summary[ $status ];
		}

		if ( $strict ) {
			$manifest_files = array_map( 'strval', array_keys( $manifest ) );
			// Only scan wp-admin/ and wp-includes/ for "added" files — walking
			// the whole ABSPATH would drown the caller in project files.
			foreach ( array( 'wp-admin', 'wp-includes' ) as $scan_dir ) {
				$abs_dir = ABSPATH . $scan_dir;
				if ( ! is_dir( $abs_dir ) ) {
					continue;
				}
				$disk_files = self::list_files_recursive( $abs_dir, $scan_dir . '/' );
				$added      = array_diff( $disk_files, $manifest_files );
				foreach ( $added as $file ) {
					if ( in_array( $file, $exclude, true ) ) {
						continue;
					}
					$results[] = array(
						'file'     => $file,
						'expected' => '',
						'actual'   => (string) md5_file( ABSPATH . $file ),
						'status'   => 'added',
					);
					++$summary['added'];
				}
			}
		}

		$summary['total'] = count( $results );

		// Measured on a clean install: 3,766 rows, 3,763 of them 'ok', 608,677 characters. That
		// exceeds the MCP response limit, so the ability returned an error instead of an answer --
		// it has never been usable through an AI client despite working correctly. The three rows
		// that mattered were a deleted hello.php and two bundled-theme files updated independently
		// of core. The summary counts every file; results carries what needs attention.
		$omitted = 0;

		if ( ! $include_ok ) {
			$omitted = Checksum_Results::matched_count( $results );
			$results = Checksum_Results::only_problems( $results );
		}

		return array(
			'success' => true,
			'version' => $version,
			'locale'  => $locale,
			'results' => $results,
			'summary' => $summary,
			'message' => $omitted > 0
				? sprintf(
					/* translators: 1: version, 2: locale, 3: total, 4: omitted count */
					__( 'Verified %3$d core file(s) at version "%1$s" (%2$s). %4$d matched and are omitted from results; pass include_ok:true to list them.', 'acrossai-abilities-manager' ),
					$version,
					$locale,
					$summary['total'],
					$omitted
				)
				: sprintf(
					/* translators: 1: version, 2: locale, 3: total */
					__( 'Verified %3$d core file(s) at version "%1$s" (%2$s).', 'acrossai-abilities-manager' ),
					$version,
					$locale,
					$summary['total']
				),
		);
	}

	/**
	 * List every file under $dir with a caller-supplied path prefix.
	 *
	 * @param string $dir    Absolute directory path.
	 * @param string $prefix String prepended to each returned path (e.g. 'wp-admin/').
	 * @return string[]
	 */
	private static function list_files_recursive( string $dir, string $prefix ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = array();
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		$prefix_len = strlen( $dir ) + 1;
		foreach ( $iter as $item ) {
			if ( $item->isFile() ) {
				$files[] = $prefix . substr( $item->getPathname(), $prefix_len );
			}
		}
		return $files;
	}
}
