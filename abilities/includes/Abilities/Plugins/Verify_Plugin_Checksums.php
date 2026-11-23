<?php
/**
 * Feature 064 — Verify a plugin's on-disk files against the WordPress.org
 * checksums manifest.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Plugins
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Plugins;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Checksum_Results;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Plugin_Helpers;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Verify a plugin's files against the WordPress.org checksums manifest.
 *
 * Fetches the manifest from
 * https://downloads.wordpress.org/plugin-checksums/{slug}/{version}.json — the endpoint WP-CLI's
 * `wp plugin verify-checksums` uses. For each entry, md5_file() the on-disk path and compare.
 * Emits per-file status: 'ok' | 'modified' | 'missing' | 'added' (added only when strict:true and
 * the file is on disk but absent from the manifest).
 *
 * The manifest also carries a sha256 per file. md5 is used to stay aligned with WP-CLI and because
 * hashing every file twice doubles the cost on a large plugin for no gain against a published
 * reference.
 *
 * Three distinct outcomes, which an earlier version collapsed into one:
 *
 *   - 404 from the endpoint     -> success:true,  error_code:'no_manifest'
 *                                  WP.org genuinely publishes no manifest for that slug+version
 *                                  (not hosted there, or that version was never released).
 *   - any other fetch failure   -> success:false, error_code:'manifest_fetch_failed'
 *   - 200 with unusable body    -> success:false, error_code:'manifest_unreadable'
 *
 * Reporting a network failure as 'no manifest available' tells the caller something about the
 * plugin when the truth is about the request. See the endpoint note in the git history.
 */
class Verify_Plugin_Checksums extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/verify-plugin-checksums',
			'args' => array(
				'label'               => __( 'Verify Plugin Checksums', 'acrossai-abilities-manager' ),
				'description'         => __( 'Verify an installed plugin\'s on-disk files against the official WordPress.org checksums manifest. Per-file status: ok / modified / missing / added (added only when strict:true), plus a summary. By default results lists only files needing attention — the summary carries the counts — and include_ok:true lists the matching files too. A plugin WordPress.org publishes no manifest for returns success:true with error_code "no_manifest"; a request that failed returns success:false with "manifest_fetch_failed", so a network problem is never reported as a fact about the plugin.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'strict' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'include_ok' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Include every file that matched. Off by default: the summary already carries the counts, and a clean 166-file plugin would otherwise return 166 identical rows.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'plugin'  => array( 'type' => 'string' ),
						'version' => array( 'type' => 'string' ),
						'results' => array( 'type' => 'array' ),
						'summary' => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
						// additionalProperties is false here, so a returned key that is not listed
						// is stripped by output validation and the caller silently loses it.
						'error_code' => array( 'type' => 'string' ),
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
		$identifier = sanitize_text_field( (string) ( $input['plugin'] ?? '' ) );
		if ( '' === $identifier ) {
			return array(
				'success' => false,
				'message' => __( 'plugin is required.', 'acrossai-abilities-manager' ),
			);
		}
		$strict     = ! empty( $input['strict'] );
		$include_ok = ! empty( $input['include_ok'] );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$resolved = Plugin_Helpers::resolve_plugin( $identifier );
		if ( null === $resolved['plugin_file'] || $resolved['certainty'] < 8.0 ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: input identifier */
					__( 'No plugin found matching "%s".', 'acrossai-abilities-manager' ),
					$identifier
				),
			);
		}

		$plugin_file = (string) $resolved['plugin_file'];
		$slug        = dirname( $plugin_file );
		if ( '.' === $slug || '' === $slug ) {
			// Single-file plugin at the root of WP_PLUGIN_DIR — no manifest exists.
			return array(
				'success' => true,
				'plugin'  => $plugin_file,
				'version' => '',
				'results' => array(),
				'summary' => array(
					'total'    => 0,
					'ok'       => 0,
					'modified' => 0,
					'missing'  => 0,
					'added'    => 0,
				),
				'error_code' => 'no_manifest',
				'message'    => __( 'A single-file plugin at the plugins-directory root has no WordPress.org manifest.', 'acrossai-abilities-manager' ),
			);
		}

		$plugin_data = Plugin_Helpers::get_plugin_by_slug( $plugin_file );
		$version     = null !== $plugin_data && isset( $plugin_data['version'] ) ? (string) $plugin_data['version'] : '';

		// The api.wordpress.org/plugins/checksums/1.0/ path this used to call returns 404 for every
		// plugin and every version; it is not a WordPress.org endpoint. Measured against akismet
		// 5.7.2 and query-monitor 4.1.1, both of which return a full manifest from the URL below.
		$manifest_url = self::manifest_url( $slug, $version );
		$response     = wp_remote_get( $manifest_url, array( 'timeout' => 15 ) );

		$empty_summary = array(
			'total'    => 0,
			'ok'       => 0,
			'modified' => 0,
			'missing'  => 0,
			'added'    => 0,
		);

		$failure = self::manifest_failure( $response, $slug, $version );

		if ( null !== $failure ) {
			return array_merge(
				array(
					'plugin'  => $slug,
					'version' => $version,
					'results' => array(),
					'summary' => $empty_summary,
				),
				$failure
			);
		}

		$body     = (string) wp_remote_retrieve_body( $response );
		$manifest = json_decode( $body, true );
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
			return array(
				'success'    => false,
				'plugin'     => $slug,
				'version'    => $version,
				'results'    => array(),
				'summary'    => $empty_summary,
				'error_code' => 'manifest_unreadable',
				'message'    => __( 'The checksums manifest responded but carried no readable file list.', 'acrossai-abilities-manager' ),
			);
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
		$results    = array();
		$summary    = $empty_summary;

		foreach ( $manifest['files'] as $file => $expected_data ) {
			$file          = (string) $file;
			$expected_hash = self::extract_expected_hash( $expected_data );
			$disk_path     = $plugin_dir . '/' . $file;

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

			$results[] = array(
				'file'     => $file,
				'expected' => $expected_hash,
				'actual'   => $actual_hash,
				'status'   => $status,
			);
			++$summary[ $status ];
		}

		if ( $strict && is_dir( $plugin_dir ) ) {
			$manifest_files = array_map( 'strval', array_keys( $manifest['files'] ) );
			$disk_files     = self::list_files_recursive( $plugin_dir );
			$added_files    = array_diff( $disk_files, $manifest_files );
			foreach ( $added_files as $file ) {
				$results[] = array(
					'file'     => $file,
					'expected' => '',
					'actual'   => (string) md5_file( $plugin_dir . '/' . $file ),
					'status'   => 'added',
				);
				++$summary['added'];
			}
		}

		$summary['total'] = count( $results );

		// The summary is counted across every file; `results` is what gets read. Returning 166
		// identical 'ok' rows costs the caller its context window and tells it nothing the summary
		// has not already said, so by default only the files that need attention are listed.
		$omitted = 0;

		if ( ! $include_ok ) {
			$omitted = Checksum_Results::matched_count( $results );
			$results = Checksum_Results::only_problems( $results );
		}

		return array(
			'success' => true,
			'plugin'  => $slug,
			'version' => $version,
			'results' => $results,
			'summary' => $summary,
			'message' => $omitted > 0
				? sprintf(
					/* translators: 1: slug, 2: total files, 3: omitted count */
					__( 'Verified %2$d file(s) for plugin "%1$s". %3$d matched and are omitted from results; pass include_ok:true to list them.', 'acrossai-abilities-manager' ),
					$slug,
					$summary['total'],
					$omitted
				)
				: sprintf(
					/* translators: 1: slug, 2: total */
					__( 'Verified %2$d file(s) for plugin "%1$s".', 'acrossai-abilities-manager' ),
					$slug,
					$summary['total']
				),
		);
	}

	/**
	 * The WordPress.org checksums manifest URL for one plugin version.
	 *
	 * Extracted and public so the URL can be asserted directly. The bug this replaces was a single
	 * wrong URL buried behind a `wp_remote_get()` inside a method that also `require_once`s
	 * `wp-admin/includes/plugin.php` — unreachable from the stub test harness, so nothing could
	 * catch it. Every call 404'd and the ability reported "no manifest available" for every plugin
	 * on every site, with `success: true`.
	 *
	 * The old path was `api.wordpress.org/plugins/checksums/1.0/?plugin=X&version=Y`, which is not a
	 * WordPress.org endpoint. This is the one `wp plugin verify-checksums` uses.
	 *
	 * @since  0.0.45
	 * @param  string $slug    Plugin directory slug.
	 * @param  string $version Installed version.
	 * @return string
	 */
	public static function manifest_url( string $slug, string $version ): string {
		return sprintf(
			'https://downloads.wordpress.org/plugin-checksums/%s/%s.json',
			rawurlencode( $slug ),
			rawurlencode( $version )
		);
	}

	/**
	 * Classify a manifest response: which outcome, and whether it is the plugin's fault or ours.
	 *
	 * Returns null when the response is usable and verification should proceed.
	 *
	 * The distinction is the point. A 404 is a fact about the plugin — WordPress.org publishes no
	 * manifest for it. Anything else is a fact about the request, and reporting a timeout or a 503
	 * as "this plugin has no manifest" tells the caller something untrue about their site. The
	 * earlier version collapsed both into `success: true, message: 'no_manifest'`.
	 *
	 * @since  0.0.45
	 * @param  mixed  $response Result of wp_remote_get().
	 * @param  string $slug     Plugin slug, for the message.
	 * @param  string $version  Version, for the message.
	 * @return array{success:bool,error_code:string,message:string}|null
	 */
	public static function manifest_failure( $response, string $slug = '', string $version = '' ): ?array {
		if ( is_wp_error( $response ) ) {
			return array(
				'success'    => false,
				'error_code' => 'manifest_fetch_failed',
				'message'    => sprintf(
					/* translators: %s: transport error message */
					__( 'Could not reach the checksums manifest: %s', 'acrossai-abilities-manager' ),
					$response->get_error_message()
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $status ) {
			return array(
				'success'    => true,
				'error_code' => 'no_manifest',
				'message'    => '' !== $slug
					? sprintf(
						/* translators: 1: plugin slug, 2: version */
						__( 'WordPress.org publishes no checksums manifest for "%1$s" %2$s, so its files cannot be verified against a published release.', 'acrossai-abilities-manager' ),
						$slug,
						$version
					)
					: __( 'WordPress.org publishes no checksums manifest for this plugin version, so its files cannot be verified against a published release.', 'acrossai-abilities-manager' ),
			);
		}

		if ( 200 !== $status ) {
			return array(
				'success'    => false,
				'error_code' => 'manifest_fetch_failed',
				'message'    => sprintf(
					/* translators: %d: HTTP status code */
					__( 'The checksums manifest request returned HTTP %d.', 'acrossai-abilities-manager' ),
					$status
				),
			);
		}

		return null;
	}

	/**
	 * Extract the expected MD5 hash from a manifest entry.
	 *
	 * The WP.org manifest ships each entry as either a scalar hash string or
	 * an array of the form { md5: '<hex>' } (older format).
	 *
	 * @param mixed $entry Manifest entry.
	 * @return string
	 */
	private static function extract_expected_hash( $entry ): string {
		if ( is_string( $entry ) ) {
			return $entry;
		}
		if ( is_array( $entry ) && isset( $entry['md5'] ) ) {
			return (string) $entry['md5'];
		}
		return '';
	}

	/**
	 * List every file under $dir as paths relative to $dir.
	 *
	 * @param string $dir Absolute directory path.
	 * @return string[]
	 */
	private static function list_files_recursive( string $dir ): array {
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
				$files[] = substr( $item->getPathname(), $prefix_len );
			}
		}
		return $files;
	}
}
