<?php
/**
 * Shared helpers for the plugin-owned replacements of vendor's
 * three built-in MCP abilities.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Kept as a trait (not a class of statics) so the three ability
 * callback classes stay symmetric — each one `use`s the trait,
 * calls `self::apply_exposure_filter()`, and reads like a plain
 * class with no cross-class coupling.
 */
trait AbilityHelpers {

	/**
	 * Return the ability's MCP type. Defaults to 'tool' when unset
	 * or when the value isn't one of the allowed enums.
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 */
	protected static function mcp_type( WP_Ability $ability ): string {
		$meta = $ability->get_meta();
		$type = $meta['mcp']['type'] ?? 'tool';
		if ( ! in_array( $type, array( 'tool', 'resource', 'prompt' ), true ) ) {
			return 'tool';
		}
		return (string) $type;
	}

	/**
	 * Return the ability's static `meta.mcp.public` flag.
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 */
	protected static function is_meta_public( WP_Ability $ability ): bool {
		$meta = $ability->get_meta();
		return (bool) ( $meta['mcp']['public'] ?? false );
	}

	/**
	 * Apply the plugin's per-server exposure filter with `server_id`.
	 * Default is the ability's `meta.mcp.public` flag so both narrowing
	 * and widening are single-callback operations for downstream.
	 *
	 * @param \WP_Ability $ability The ability being evaluated.
	 * @param string      $context One of 'discover' | 'get_info' | 'execute'.
	 * @return bool                Filter-resolved exposure decision.
	 */
	protected static function apply_exposure_filter( WP_Ability $ability, string $context ): bool {
		$server_id = CurrentServerHolder::instance()->get_server_id();

		if ( null === $server_id ) {
			// No MCP request context (WP-CLI, cron, direct wp_get_ability()->execute()).
			// Fall back to the ability's static `meta.mcp.public` flag.
			$default = self::is_meta_public( $ability );
		} else {
			/*
			 * MCP request context available — consult the F017 canonical
			 * resolver, which honors the row-in-`wp_acrossai_mcp_server_abilities`
			 * override AND falls back to `meta.mcp.public` per
			 * DEC-ABILITY-OVERRIDE-RESOLUTION. This is what makes the
			 * Abilities-tab per-server toggles authoritative for the
			 * three built-in meta tools.
			 */
			$meta    = $ability->get_meta();
			$default = ExposureResolver::resolve_effective(
				$server_id,
				$ability->get_name(),
				is_array( $meta ) ? $meta : array()
			);
		}

		/**
		 * Filters whether an ability is exposed via MCP for the
		 * plugin-owned discover / get-info / execute tools.
		 *
		 * The default value is the F017 canonical per-server resolver's
		 * output (row-in-`wp_acrossai_mcp_server_abilities` beats
		 * `meta.mcp.public` fallback). When no MCP request context is in
		 * flight — WP-CLI, cron, direct `wp_get_ability()->execute()` —
		 * the default is just `meta.mcp.public`.
		 *
		 * Return `true` to expose an ability that F017 would hide, or
		 * `false` to hide one that F017 would expose. `server_id` is the
		 * DB PK of the MCP server handling the current request, or null
		 * when invoked outside an MCP request.
		 *
		 * **Exposure is not authorization.** Returning true here does
		 * not bypass the ability's own `permission_callback`; the
		 * execute path still runs `WP_Ability::check_permissions()`
		 * before invoking the ability.
		 *
		 * @since 0.1.0
		 *
		 * @param bool        $is_exposed F017-resolved default (or `meta.mcp.public` when no server context).
		 * @param \WP_Ability $ability    The ability being checked.
		 * @param int|null    $server_id  Current MCP server DB PK, or null.
		 * @param string      $context    One of 'discover' | 'get_info' | 'execute'.
		 */
		return (bool) apply_filters(
			'acrossai_mcp_is_ability_exposed',
			$default,
			$ability,
			$server_id,
			$context
		);
	}
}
