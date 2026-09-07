<?php
/**
 * Feature 017 — effective ability exposure resolver.
 *
 * Single source of truth for per-(server, ability) exposure. Consulted by
 * the REST controller (READ + WRITE) and the `mcp_adapter_pre_tool_call`
 * enforcement gate.
 *
 * Rule:
 *   - Row exists → `(bool) $row->is_exposed`
 *   - No row     → `! empty( $meta['mcp']['public'] )`
 *
 * Per-request static cache keyed by `"{$server_id}:{$ability_slug}"`.
 *
 * A11 pure-service exception (no singleton, no ctor).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerAbility
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless resolver for effective ability exposure.
 *
 * @since 0.1.0
 */
final class ExposureResolver {

	/**
	 * Per-request cache. Reset naturally at end-of-request; no persistence.
	 *
	 * @var array<string, bool>
	 */
	private static array $cache = array();

	/**
	 * Return the effective exposure for the given (server, ability) pair.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $server_id    MCP server id (references acrossai_mcp_servers.id).
	 * @param string $ability_slug \WP_Ability::get_name() output.
	 * @param array  $meta         The ability's `get_meta()` output — read to
	 *                             extract `meta[mcp][public]` for the fallback.
	 * @return bool True when the ability is effectively exposed on this server.
	 */
	public static function resolve( int $server_id, string $ability_slug, array $meta ): bool {
		$key = $server_id . ':' . $ability_slug;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		$rows = Query::instance()->query(
			array(
				'server_id'    => $server_id,
				'ability_slug' => $ability_slug,
				'number'       => 1,
			)
		);

		if ( ! empty( $rows ) ) {
			// B18 defense — $wpdb returns TINYINT as string; cast to bool.
			$result = (bool) $rows[0]->is_exposed;
		} else {
			$result = ! empty( $meta['mcp']['public'] );
		}

		self::$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Test-only cache reset. Not part of the public F017 API.
	 *
	 * @internal
	 * @return void
	 */
	public static function _reset_cache_for_tests(): void { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Name is a pinned test contract (F017); renaming it breaks the suites that call it.
		self::$cache = array();
	}
}
