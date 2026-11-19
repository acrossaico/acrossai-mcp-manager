<?php
/**
 * Decides whether a file may be imported at all (Feature 105).
 *
 * Runs to completion before the importer writes anything. The ordering is the
 * point: a half-validated file that fails on its third server has already
 * changed the first two, and "restore the snapshot" is a worse answer than
 * "that file was refused".
 *
 * The threat here is low and not zero. The uploader holds `manage_options`, so
 * this is not a privilege boundary — it is the boundary between a well-formed
 * file and a malformed one, and between this build's format and a future one.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Export
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Export;

use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses and checks an uploaded export file.
 *
 * @since 0.4.9
 */
final class ImportValidator {

	/**
	 * Largest file accepted, in bytes.
	 *
	 * An export of this codebase's own dev site — four servers, every block —
	 * is under 6 KB. Two megabytes is roughly three hundred times that, which
	 * leaves room for a site with many servers while refusing the 50 MB array
	 * that exists only to exhaust memory inside `json_decode`.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	public const MAX_BYTES = 2097152;

	/**
	 * Deepest nesting `json_decode` will follow.
	 *
	 * The format's own deepest path is four levels. Sixteen is generous and
	 * still refuses a file whose only purpose is recursion.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	public const MAX_DEPTH = 16;

	/**
	 * Not instantiable.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}

	/**
	 * Parse and check raw file contents.
	 *
	 * @since  0.4.9
	 * @param  string $raw File contents.
	 * @return array<string, mixed>|WP_Error The payload, or why it was refused.
	 */
	public static function parse( string $raw ) {
		if ( '' === trim( $raw ) ) {
			return new WP_Error(
				'acrossai_mcp_import_empty',
				__( 'That file is empty.', 'acrossai-mcp-manager' )
			);
		}

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new WP_Error(
				'acrossai_mcp_import_too_large',
				sprintf(
					/* translators: %s: maximum size, already formatted. */
					__( 'That file is larger than %s, which is far bigger than any export this plugin produces.', 'acrossai-mcp-manager' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		$payload = json_decode( $raw, true, self::MAX_DEPTH );

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'acrossai_mcp_import_unreadable',
				__( 'That file is not valid JSON, or it nests more deeply than an export ever does.', 'acrossai-mcp-manager' )
			);
		}

		return self::check( $payload );
	}

	/**
	 * Check a decoded payload.
	 *
	 * Split from {@see self::parse()} so a snapshot — which is already an
	 * array and never went near a file — can be checked by the same rules
	 * before being restored.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $payload Decoded payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function check( array $payload ) {
		$format = isset( $payload['format'] ) && is_scalar( $payload['format'] ) ? (string) $payload['format'] : '';

		// Checked before the version, so a JSON file belonging to another
		// plugin is refused as "not ours" rather than "version 0 unsupported".
		if ( ExportConstants::FORMAT !== $format ) {
			return new WP_Error(
				'acrossai_mcp_import_foreign',
				__( 'That file is valid JSON but it is not an AcrossAI MCP export.', 'acrossai-mcp-manager' )
			);
		}

		$version = isset( $payload['version'] ) ? (int) $payload['version'] : 0;

		if ( $version < 1 ) {
			return new WP_Error(
				'acrossai_mcp_import_no_version',
				__( 'That export does not say which format version it is.', 'acrossai-mcp-manager' )
			);
		}

		// A NEWER file is refused rather than best-effort parsed. A later
		// writer may give meaning to a block this build would read as "absent,
		// so leave it alone" — and acting on that reading would quietly undo
		// settings the file was carrying.
		if ( $version > ExportConstants::VERSION ) {
			return new WP_Error(
				'acrossai_mcp_import_too_new',
				sprintf(
					/* translators: 1: file's format version, 2: version this build reads. */
					__( 'That export was written by a newer version of this plugin (format %1$d; this version reads up to %2$d). Update the plugin and try again.', 'acrossai-mcp-manager' ),
					$version,
					ExportConstants::VERSION
				)
			);
		}

		if ( ! isset( $payload['servers'] ) || ! is_array( $payload['servers'] ) ) {
			return new WP_Error(
				'acrossai_mcp_import_no_servers',
				__( 'That export contains no servers.', 'acrossai-mcp-manager' )
			);
		}

		$servers = array();

		foreach ( $payload['servers'] as $index => $server ) {
			$checked = self::check_server( $server, (int) $index );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$servers[] = $checked;
		}

		if ( array() === $servers ) {
			return new WP_Error(
				'acrossai_mcp_import_no_servers',
				__( 'That export contains no servers.', 'acrossai-mcp-manager' )
			);
		}

		$payload['servers'] = $servers;

		return $payload;
	}

	/**
	 * Check one server record and normalise what the importer relies on.
	 *
	 * @since  0.4.9
	 * @param  mixed $server Candidate record.
	 * @param  int   $index  Position in the file, for the message.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function check_server( $server, int $index ) {
		$position = $index + 1;

		if ( ! is_array( $server ) ) {
			return new WP_Error(
				'acrossai_mcp_import_bad_server',
				sprintf(
					/* translators: %d: position in the file. */
					__( 'Server %d in that file is not readable.', 'acrossai-mcp-manager' ),
					$position
				)
			);
		}

		$slug = isset( $server['slug'] ) && is_scalar( $server['slug'] ) ? sanitize_title( (string) $server['slug'] ) : '';

		// The slug is the match key AND the route, so a record without one
		// cannot be placed or created. Refused rather than skipped: a file
		// missing a slug is malformed, and importing the rest of it would be
		// a partial result reported as a success.
		if ( '' === $slug ) {
			return new WP_Error(
				'acrossai_mcp_import_no_slug',
				sprintf(
					/* translators: %d: position in the file. */
					__( 'Server %d in that file has no slug, so there is no way to tell which server it is.', 'acrossai-mcp-manager' ),
					$position
				)
			);
		}

		$server['slug'] = $slug;

		$origin = isset( $server['origin'] ) && is_scalar( $server['origin'] ) ? (string) $server['origin'] : '';

		// Anything unrecognised is treated as plugin-registered, which is the
		// cautious reading: it means "update where present, never create".
		$server['origin'] = ExportConstants::ORIGIN_DATABASE === $origin
			? ExportConstants::ORIGIN_DATABASE
			: ExportConstants::ORIGIN_PLUGIN;

		$server['blocks'] = self::blocks_for( $server );

		return $server;
	}

	/**
	 * Which blocks a record carries.
	 *
	 * A declared `blocks` list wins. When it is absent — a hand-written or
	 * hand-edited file — the fallback is "every block key present in this
	 * object", which is what a person writing JSON by hand would expect.
	 *
	 * The declared list is still intersected with the keys actually present,
	 * so a record naming a block it does not carry cannot make the importer
	 * read a missing key.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $server Server record.
	 * @return array<int, string>
	 */
	private static function blocks_for( array $server ): array {
		$present = array_values(
			array_filter(
				ExportConstants::BLOCKS,
				static function ( string $block ) use ( $server ): bool {
					return isset( $server[ $block ] ) && is_array( $server[ $block ] );
				}
			)
		);

		if ( ! isset( $server['blocks'] ) || ! is_array( $server['blocks'] ) ) {
			return $present;
		}

		$declared = array_map( 'sanitize_key', array_map( 'strval', $server['blocks'] ) );

		return array_values( array_intersect( $present, $declared ) );
	}
}
