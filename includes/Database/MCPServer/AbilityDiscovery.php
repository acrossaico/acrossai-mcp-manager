<?php
/**
 * AbilityDiscovery — type-aware F017-effective ability discovery for MCP server registration.
 *
 * Given a server ID and an MCP ability type ('tool', 'resource', or 'prompt'), returns
 * the list of ability slugs that should be advertised for that server. Composition:
 *
 *   For each ability returned by wp_get_abilities():
 *     - Skip if the ability's mcp.type (defaulting to 'tool' when unset) doesn't match $type.
 *     - Include if ExposureResolver::resolve_effective( $server_id, $slug, $meta ) === true.
 *       (Three-tier priority: row-in-wp_acrossai_mcp_server_abilities → server-level
 *       policy → meta.mcp.public — see DEC-ABILITY-OVERRIDE-RESOLUTION + F082
 *       docs/planings-tasks/082-per-server-ability-policy-defaults.md.)
 *
 * Fail-open: returns empty array when wp_get_abilities() is unavailable.
 *
 * Type semantic mirrors the vendor DefaultServerFactory::discover_abilities_by_type()
 * at vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:141
 * (private helper) — that helper is public-only, this composer adds the F017
 * per-server override layer on top.
 *
 * Stateless per A11 pure-service exemption — no singleton, no constructor state.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helper for discovering F017-effective abilities filtered by MCP type.
 *
 * @since 0.1.0
 */
final class AbilityDiscovery {

	public const TYPE_TOOL     = 'tool';
	public const TYPE_RESOURCE = 'resource';
	public const TYPE_PROMPT   = 'prompt';

	/**
	 * Return the F017-effective ability slugs for a server, filtered by MCP type.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $server_id The MCP server row ID.
	 * @param string $type      One of self::TYPE_TOOL, self::TYPE_RESOURCE, self::TYPE_PROMPT.
	 *                          Matched against the ability's mcp.type meta key (defaulting
	 *                          to 'tool' when unset — vendor semantic).
	 * @return string[] Deduped, string-normalized ability slugs. Empty when the Abilities API
	 *                  is unavailable, when no ability matches, or when $type is invalid.
	 */
	public static function for_server( int $server_id, string $type ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$result = array();
		foreach ( \wp_get_abilities() as $ability ) {
			$slug = (string) $ability->get_name();
			if ( '' === $slug ) {
				continue;
			}

			$meta       = $ability->get_meta();
			$meta_array = is_array( $meta ) ? $meta : array();

			$ability_type = isset( $meta_array['mcp']['type'] ) && is_string( $meta_array['mcp']['type'] )
				? $meta_array['mcp']['type']
				: self::TYPE_TOOL;

			if ( $ability_type !== $type ) {
				continue;
			}

			if ( ExposureResolver::resolve_effective( $server_id, $slug, $meta_array ) ) {
				$result[] = $slug;
			}
		}

		return array_values( array_unique( array_map( 'strval', $result ) ) );
	}
}
