<?php
/**
 * Wraps exported servers in something a reader can identify (Feature 105).
 *
 * Four fields do the work, and each exists because of a failure it prevents:
 *
 *  - `format`      — a valid JSON file from another plugin is refused as "not
 *                    ours" rather than half-read and reported as corrupt.
 *  - `version`     — a file from a NEWER build is refused rather than
 *                    best-effort parsed. A newer writer may give meaning to a
 *                    block this build would read as "absent, leave alone".
 *  - `generated_at`— so a file found in six months can be placed in time.
 *  - `source`      — diagnostic only, NEVER read on import. It answers "which
 *                    site did this come from" when somebody emails you a file.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Export
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Export;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as ServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row as ServerRow;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles a complete export payload.
 *
 * @since 0.4.9
 */
final class Envelope {

	/**
	 * Not instantiable.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}

	/**
	 * Build the payload for a set of servers.
	 *
	 * `$selection` maps a server id to the blocks wanted from it, because the
	 * screen lets each server carry a different set. An id present with an
	 * empty array still produces a record — the server's identity travels and
	 * none of its settings do, which is a thing an operator can legitimately
	 * ask for.
	 *
	 * @since  0.4.9
	 * Typed loosely on purpose: the selection is assembled from a submitted
	 * form, so a value is whatever was posted until the guard below narrows it.
	 *
	 * @param  array<int, mixed> $selection Server id => block keys.
	 * @return array<string, mixed>
	 */
	public static function build( array $selection ): array {
		$servers = array();

		foreach ( $selection as $server_id => $blocks ) {
			$row = ServerQuery::instance()->get_item( (int) $server_id );

			// A server deleted between rendering the form and submitting it is
			// skipped rather than fatal — the operator gets the rest of their
			// export, which is more use than an error page.
			if ( ! $row instanceof ServerRow ) {
				continue;
			}

			$servers[] = ServerExporter::export( $row, is_array( $blocks ) ? $blocks : ExportConstants::BLOCKS );
		}

		return array(
			'format'       => ExportConstants::FORMAT,
			'version'      => ExportConstants::VERSION,
			'generated_at' => gmdate( 'c' ),
			'source'       => array(
				'site_url'       => home_url(),
				'plugin_version' => defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) ? ACROSSAI_MCP_MANAGER_VERSION : '',
			),
			'servers'      => $servers,
		);
	}

	/**
	 * Every server on the site, with every block.
	 *
	 * What the automatic pre-import snapshot takes: a snapshot narrowed to the
	 * blocks being imported would not be a restore point, because the blocks
	 * it left out are exactly the ones nothing else would put back.
	 *
	 * @since  0.4.9
	 * @return array<string, mixed>
	 */
	public static function build_everything(): array {
		$selection = array();

		foreach ( (array) ServerQuery::instance()->query( array( 'number' => 0 ) ) as $row ) {
			if ( $row instanceof ServerRow ) {
				$selection[ (int) $row->id ] = ExportConstants::BLOCKS;
			}
		}

		return self::build( $selection );
	}

	/**
	 * The filename a download is offered under.
	 *
	 * Host plus date, so a folder of exports from several sites stays legible.
	 *
	 * @since  0.4.9
	 * @return string
	 */
	public static function filename(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = '' !== $host ? sanitize_file_name( $host ) : 'site';

		return sprintf( 'acrossai-mcp-%s-%s.json', $host, gmdate( 'Y-m-d' ) );
	}

	/**
	 * The payload as the JSON that actually ships.
	 *
	 * Pretty-printed and with slashes unescaped: an export is read, diffed and
	 * kept in version control by people, and `https:\/\/example.com` helps
	 * nobody. Not a security control — `wp_json_encode` still escapes what
	 * matters for a JSON document.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $payload Built payload.
	 * @return string Empty string when encoding fails.
	 */
	public static function to_json( array $payload ): string {
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '';
	}
}
