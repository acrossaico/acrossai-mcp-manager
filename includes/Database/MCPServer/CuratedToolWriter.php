<?php
/**
 * Additive writer for a server's curated tool rows.
 *
 * Extracted at the second use (Constitution §VI): `ServerGuideBackfill` needed
 * "add these slugs, leave everything else alone, remember to flush the gate",
 * and `SeededToolsBackfill` needs the same three steps for a different payload.
 *
 * The pairing is the reason this exists rather than each caller writing four
 * lines. `MCPServerToolQuery` caches a server's added slugs per request through
 * `ToolExposureGate`, and invalidating that cache is the CALLER's job —
 * `replace_set()` does not do it either. A backfill that adds a row and forgets
 * the flush leaves the rest of the request believing the tool is absent, which
 * is invisible until something calls it.
 *
 * ADDITIVE on purpose, and never `replace_set()`. Every caller here is
 * repairing a gap in a set the operator may also have curated; a replace would
 * take their choices with it.
 *
 * Stateless pure service (A11 exemption) — no singleton, no ctor, no state.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.3.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Adds curated tool rows to a server without disturbing the rest.
 *
 * @since 0.3.6
 */
final class CuratedToolWriter {

	/**
	 * Add every slug the server does not already carry.
	 *
	 * Re-checks presence per slug rather than trusting the caller's SELECT: the
	 * `server_ability` UNIQUE index is the real backstop, and a duplicate INSERT
	 * that silently fails would otherwise be counted as written.
	 *
	 * @since  0.3.6
	 * @param  int      $server_id Server row id.
	 * @param  string[] $slugs     Ability slugs to ensure are present.
	 * @return int Rows actually inserted.
	 */
	public static function add_missing( int $server_id, array $slugs ): int {
		if ( $server_id <= 0 ) {
			return 0;
		}

		$query   = MCPServerToolQuery::instance();
		$present = array_flip( $query->get_added_slugs( $server_id ) );
		$written = 0;

		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;

			if ( '' === $slug || isset( $present[ $slug ] ) ) {
				continue;
			}

			$result = $query->add_item(
				array(
					'server_id'    => $server_id,
					'ability_slug' => $slug,
				)
			);

			if ( false !== $result ) {
				++$written;
			}
		}

		if ( $written > 0 ) {
			ToolExposureGate::flush_cache( $server_id );
		}

		return $written;
	}
}
